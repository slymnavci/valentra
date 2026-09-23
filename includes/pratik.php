<?php
declare(strict_types=1);

/**
 * Pratik bilgiler: veri katmanı.
 *
 * Akis haberlerdekiyle ayni mantikta ama daha siki: ajan yalnizca
 * ADAY deger yaziyor, yayindaki deger onay verilene kadar degismiyor.
 * Boylece dogrulanmamis bir rakam hicbir an sitede gorunmuyor.
 */

/**
 * Yayımlanmış bilgiler, gruplara ayrılmış.
 *
 * @return array<string,list<array<string,mixed>>>
 */
function pratik_yayindakiler(): array
{
    $satirlar = db()->query(
        'SELECT * FROM pratik_bilgiler
          WHERE aktif = 1 AND deger IS NOT NULL AND deger <> ""
          ORDER BY grup, sira, baslik'
    )->fetchAll();

    $gruplar = [];

    foreach ($satirlar as $satir) {
        $gruplar[(string) $satir['grup']][] = $satir;
    }

    return $gruplar;
}

/** Ajanın toplayacağı bilgilerin listesi (ajan bunu API'den çeker). */
function pratik_toplanacaklar(): array
{
    return db()->query(
        // aciklama da gonderiliyor: ajan fihrist sayfalarinda dogru
        // alt baglantiyi ararken baslik tek basina yetmiyor
        // ("Enflasyon (TÜFE)" ile "Tüketici Fiyat Endeksi" baglantisinin
        // ortak kelimesi yok).
        'SELECT id, anahtar, baslik, aciklama, kaynak_url, kaynak_adi,
                arama_ipucu, deger, donem
           FROM pratik_bilgiler
          WHERE aktif = 1 AND kaynak_url IS NOT NULL AND kaynak_url <> ""
          ORDER BY grup, sira'
    )->fetchAll();
}

/** Onay bekleyen aday sayısı. */
function pratik_bekleyen_sayisi(): int
{
    return (int) db()->query(
        'SELECT COUNT(*) FROM pratik_bilgiler
          WHERE aday_deger IS NOT NULL AND aday_deger <> ""'
    )->fetchColumn();
}

/** Panelde gösterilecek tüm satırlar. */
function pratik_listele(): array
{
    return db()->query(
        'SELECT * FROM pratik_bilgiler ORDER BY grup, sira, baslik'
    )->fetchAll();
}

/**
 * Ajanın getirdiği değeri aday olarak kaydeder.
 *
 * Yayindaki degere DOKUNMAZ. Ajan yanlis okusa bile sitede gorunen
 * rakam degismez; degisim yalnizca onayla olur.
 *
 * @param array<string,mixed> $veri
 * @return array{durum:string}
 */
function pratik_aday_yaz(array $veri): array
{
    $anahtar = trim((string) ($veri['anahtar'] ?? ''));
    $deger   = trim((string) ($veri['deger'] ?? ''));

    if ($anahtar === '' || $deger === '') {
        throw new InvalidArgumentException('anahtar ve deger zorunlu.');
    }

    $ifade = db()->prepare(
        'SELECT id, deger, seri FROM pratik_bilgiler WHERE anahtar = :a LIMIT 1'
    );
    $ifade->execute(['a' => $anahtar]);
    $mevcut = $ifade->fetch();

    if ($mevcut === false) {
        throw new InvalidArgumentException('Tanımsız bilgi anahtarı: ' . $anahtar);
    }

    // Bozuk ya da eksik seri DEGERI dusurmuyor; yalnizca grafik gelmez.
    $seri = pratik_seri_temizle($veri['seri'] ?? null);

    if (trim((string) $mevcut['deger']) === $deger) {
        /*
         * Deger ayni. Uc durum var:
         *
         * 1. Seri yok -> gercekten degisen bir sey yok.
         *
         * 2. Seri, yayindakinin DUZ UZANTISI -> yayindaki seri
         *    onaysiz tazeleniyor. Politika faizi aylarca sabit kaliyor;
         *    her hafta "degismedi" diye grafigin sagi eskimesin, ama her
         *    hafta da ayni rakami onaylamak zorunda kalinmasin. Duz
         *    uzanti yeni BILGI tasimiyor: eklenen her nokta zaten
         *    onayli son degere esit.
         *
         * 3. Seri var ama yayinda seri yok ya da gecmis degismis ->
         *    ONAYA dusuyor. Bu olmasaydi yayindaki %37 ile API'den
         *    gelen %37 ayni oldugu icin grafik hic onaya gelmez, yani
         *    hic gorunmezdi.
         */
        if ($seri === null) {
            return ['durum' => 'degismedi'];
        }

        if (pratik_seri_duz_uzanti((string) ($mevcut['seri'] ?? ''), $seri)) {
            db()->prepare('UPDATE pratik_bilgiler SET seri = :seri WHERE id = :id')
                ->execute(['seri' => $seri, 'id' => (int) $mevcut['id']]);

            return ['durum' => 'degismedi'];
        }

        $veri['not'] = trim('Değer aynı; grafik serisi onay bekliyor. '
                          . (string) ($veri['not'] ?? ''));
    }

    db()->prepare(
        'UPDATE pratik_bilgiler
            SET aday_deger = :deger, aday_donem = :donem, aday_notu = :not,
                aday_guven = :guven, aday_tarihi = NOW(), aday_seri = :seri
          WHERE id = :id'
    )->execute([
        'deger' => $deger,
        'donem' => trim((string) ($veri['donem'] ?? '')) ?: null,
        /*
         * Not 500 karakterle sinirli (sutun boyle tanimli). Uzun bir
         * not, siki kipteki MySQL'de butun kaydi dusururdu; yani
         * saglam bir rakam yalnizca aciklamasi uzun diye kaybolurdu.
         * Kirpilan not zarar vermiyor, kaybolan deger veriyor.
         */
        'not'   => mb_substr(trim((string) ($veri['not'] ?? '')), 0, 500, 'UTF-8') ?: null,
        'guven' => isset($veri['guven']) ? max(0, min(100, (int) $veri['guven'])) : null,
        'seri'  => $seri,
        'id'    => (int) $mevcut['id'],
    ]);

    return ['durum' => 'aday'];
}

/** Adayı yayına alır. */
function pratik_onayla(int $id): void
{
    db()->prepare(
        'UPDATE pratik_bilgiler
            SET deger = aday_deger,
                donem = aday_donem,
                onay_tarihi = NOW(),
                -- Aday seri yoksa yayindaki KORUNUYOR: sayfa okunarak
                -- gelen degerlerin serisi olmaz, onlari onaylamak mevcut
                -- grafigi silmemeli.
                seri = COALESCE(aday_seri, seri),
                aday_deger = NULL, aday_donem = NULL,
                aday_notu = NULL, aday_guven = NULL, aday_tarihi = NULL,
                aday_seri = NULL
          WHERE id = :id AND aday_deger IS NOT NULL'
    )->execute(['id' => $id]);
}

/** Adayı reddeder; yayındaki değer yerinde kalır. */
function pratik_reddet(int $id): void
{
    db()->prepare(
        'UPDATE pratik_bilgiler
            SET aday_deger = NULL, aday_donem = NULL,
                aday_notu = NULL, aday_guven = NULL, aday_tarihi = NULL,
                aday_seri = NULL
          WHERE id = :id'
    )->execute(['id' => $id]);
}

/** Değeri elle düzenler (onay akışını atlar). */
function pratik_elle_yaz(int $id, string $deger, string $donem): void
{
    db()->prepare(
        'UPDATE pratik_bilgiler
            SET deger = :deger, donem = :donem, onay_tarihi = NOW(),
                -- Elle yazilan deger kaynaktan gelmiyor; kaynaktan cizilen
                -- grafik artik onunla celisebilir (grafik %37 derken sayfa
                -- %40 der). Grafik kaldiriliyor, bir sonraki cekimle doner.
                seri = NULL,
                aday_deger = NULL, aday_donem = NULL,
                aday_notu = NULL, aday_guven = NULL, aday_tarihi = NULL,
                aday_seri = NULL
          WHERE id = :id'
    )->execute([
        'deger' => trim($deger) !== '' ? trim($deger) : null,
        'donem' => trim($donem) !== '' ? trim($donem) : null,
        'id'    => $id,
    ]);
}

/**
 * Kaynak adresini günceller.
 *
 * Bazi kaynak adresleri yila bagli (Alomaliye'nin "2026-pratik-bilgiler"
 * sayfasi gibi). Yil donunce adres 404 verir ve deger toplanamaz;
 * yoneticinin bunu kod degisikligi beklemeden duzeltebilmesi gerekir.
 */
function pratik_kaynak_yaz(int $id, string $url, string $ad): void
{
    db()->prepare(
        'UPDATE pratik_bilgiler SET kaynak_url = :url, kaynak_adi = :ad WHERE id = :id'
    )->execute([
        'url' => guvenli_url($url) ?: null,
        'ad'  => trim($ad) !== '' ? trim($ad) : null,
        'id'  => $id,
    ]);
}

/** Grup adlarının okunur karşılığı. */
function pratik_grup_adi(string $grup): string
{
    return match ($grup) {
        'ucret-sgk'      => 'Ücret ve SGK',
        'vergi-oranlari' => 'Vergi Oranları',
        'hadler'         => 'Hadler ve Tutarlar',
        'ekonomi'        => 'Ekonomik Göstergeler',
        default          => 'Diğer',
    };
}

/**
 * Yayındaki tek bir bilgiyi anahtarıyla getirir.
 *
 * Yalnizca onaylanmis ve degeri olan kayit doner; onay beklemekte olan
 * bir deger kendi adresinden de gorunmemeli.
 *
 * @return array<string,mixed>|null
 */
function pratik_bul(string $anahtar): ?array
{
    $anahtar = trim($anahtar);

    if ($anahtar === '') {
        return null;
    }

    $ifade = db()->prepare(
        'SELECT * FROM pratik_bilgiler
          WHERE anahtar = :a AND aktif = 1 AND deger IS NOT NULL AND deger <> ""
          LIMIT 1'
    );
    $ifade->execute(['a' => $anahtar]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Aynı gruptaki diğer bilgiler.
 *
 * Detay sayfasinin altinda duruyor: okuyucu bir hadde bakmaya geldiyse
 * komsu hadler de isine yarar ve sayfa cikmaz sokak olmaz.
 *
 * @return list<array<string,mixed>>
 */
function pratik_grup_komsulari(string $grup, string $haricAnahtar): array
{
    $ifade = db()->prepare(
        'SELECT anahtar, baslik, deger, donem
           FROM pratik_bilgiler
          WHERE grup = :g AND anahtar <> :a
            AND aktif = 1 AND deger IS NOT NULL AND deger <> ""
          ORDER BY sira, baslik'
    );
    $ifade->execute(['g' => $grup, 'a' => $haricAnahtar]);

    return $ifade->fetchAll();
}

/**
 * Listede gösterilecek tek satırlık özet.
 *
 * Bazi degerler tek bir rakam ("12.000 TL"), bazilari onlarca satirlik
 * tarife (harcirah, gelir vergisi dilimleri). Liste sayfasinda ikisi de
 * tek satira sigmali; uzun olan kirpilip detaya birakiliyor. Onceki
 * halinde butun tarife kartin icine dokuluyor ve sayfa okunmaz
 * oluyordu.
 */
function pratik_ozet(string $deger): string
{
    $satirlar = preg_split('/\R/u', trim($deger)) ?: [];
    $ilk      = '';

    foreach ($satirlar as $satir) {
        $satir = trim($satir);

        // Basligimsi satirlar ("01/01 - 30/06 Dönemi:") tek basina
        // hicbir sey anlatmaz; ilk gercek degeri ariyoruz.
        if ($satir !== '' && !str_ends_with($satir, ':')) {
            $ilk = $satir;
            break;
        }
    }

    if ($ilk === '') {
        $ilk = trim((string) ($satirlar[0] ?? ''));
    }

    $cokSatir = count(array_filter($satirlar, static fn ($s) => trim((string) $s) !== '')) > 1;

    return kisalt($ilk, 70) . ($cokSatir ? ' …' : '');
}

/**
 * Çok satırlı bir değeri bölümlere ayırır.
 *
 * Harcirah ve vergi tarifeleri "01/01 - 30/06 Dönemi:" gibi baslik
 * satirlariyla geliyor. Bunlari baslik olarak isaretlemek, degerin
 * tamamini tek blok halinde basmaktan cok daha okunabilir.
 *
 * @return list<array{tur:string,metin:string}>
 */
function pratik_deger_bolumleri(string $deger): array
{
    $bolumler = [];

    foreach (preg_split('/\R/u', trim($deger)) ?: [] as $satir) {
        $satir = trim($satir);

        if ($satir === '') {
            continue;
        }

        $bolumler[] = [
            'tur'   => str_ends_with($satir, ':') ? 'baslik' : 'satir',
            'metin' => $satir,
        ];
    }

    return $bolumler;
}

/**
 * Gelen grafik serisini denetler; saklanacak JSON'u ya da null döndürür.
 *
 * Seri ajandan geliyor. Ajan kimligini kanitlamis olsa da veri
 * yapisina guvenilmiyor: grafik bu diziyi dogrudan cizecek ve bozuk
 * bir nokta (gecersiz tarih, sonsuz sayi) sayfayi bozar.
 *
 * Kurallar: 2-1000 nokta, her nokta ["Y-m-d", sonlu sayi], tarihler
 * artan ve tekrarsiz. Uymayan seri BUTUNUYLE reddediliyor — parca parca
 * ayiklamak, eksik noktali ama dogru gorunen bir grafik uretirdi.
 */
function pratik_seri_temizle(mixed $seri): ?string
{
    if (!is_array($seri) || !array_is_list($seri)) {
        return null;
    }

    $adet = count($seri);

    if ($adet < 2 || $adet > 1000) {
        return null;
    }

    $temiz  = [];
    $onceki = '';

    foreach ($seri as $nokta) {
        if (!is_array($nokta) || count($nokta) !== 2
            || !is_string($nokta[0] ?? null) || !is_numeric($nokta[1] ?? null)) {
            return null;
        }

        $tarih = $nokta[0];
        $deger = (float) $nokta[1];

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tarih, $e)
            || !checkdate((int) $e[2], (int) $e[3], (int) $e[1])
            || !is_finite($deger)
            || $tarih <= $onceki) {
            return null;
        }

        $temiz[] = [$tarih, round($deger, 4)];
        $onceki  = $tarih;
    }

    $json = json_encode($temiz);

    return is_string($json) ? $json : null;
}

/**
 * Saklı seriyi diziye çevirir; bozuksa boş dizi.
 *
 * @return list<array{0:string,1:float}>
 */
function pratik_seri_oku(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $seri = json_decode($json, true);

    return is_array($seri) && pratik_seri_temizle($seri) !== null ? $seri : [];
}

/**
 * Yeni seri, yayındakinin düz uzantısı mı?
 *
 * "Duz uzanti": yeni serinin yayindakiyle cakisan kismi AYNI, yayindaki
 * son tarihten sonraki her noktasi da yayindaki SON DEGERE esit. Yani
 * yeni seri hicbir yeni rakam tasimiyor, yalnizca sabit kalan degeri
 * bugune kadar uzatiyor.
 *
 * Karsilastirma BASAMAK degeri uzerinden: yeni serinin her noktasi,
 * yayindaki serinin o tarihte gecerli olan degeriyle (o tarihten onceki
 * son nokta) karsilastiriliyor. Nokta nokta esitlik aranamaz, cunku
 * pencere her cekimde bir hafta kayiyor ve ilk nokta her seferinde
 * baska bir gun oluyor.
 */
function pratik_seri_duz_uzanti(string $yayindakiJson, string $yeniJson): bool
{
    $eski = pratik_seri_oku($yayindakiJson);
    $yeni = pratik_seri_oku($yeniJson);

    if ($eski === [] || $yeni === []) {
        return false;
    }

    $eskiIlk  = $eski[0][0];
    $eskiSon  = $eski[count($eski) - 1];
    $esit     = static fn (float $a, float $b): bool => abs($a - $b) < 1e-6;

    foreach ($yeni as [$tarih, $deger]) {
        if ($tarih < $eskiIlk) {
            // Yayindaki pencereden once: karsilastirilacak bir sey yok.
            continue;
        }

        if ($tarih > $eskiSon[0]) {
            if (!$esit((float) $deger, (float) $eskiSon[1])) {
                return false;
            }

            continue;
        }

        $gecerli = null;

        foreach ($eski as [$eTarih, $eDeger]) {
            if ($eTarih > $tarih) {
                break;
            }

            $gecerli = (float) $eDeger;
        }

        if ($gecerli === null || !$esit((float) $deger, $gecerli)) {
            return false;
        }
    }

    return true;
}
