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
        'SELECT id, deger FROM pratik_bilgiler WHERE anahtar = :a LIMIT 1'
    );
    $ifade->execute(['a' => $anahtar]);
    $mevcut = $ifade->fetch();

    if ($mevcut === false) {
        throw new InvalidArgumentException('Tanımsız bilgi anahtarı: ' . $anahtar);
    }

    // Yayindaki degerin aynisi geldiyse onaya dusurmeye gerek yok;
    // kullaniciyi bos onay isteriyle mesgul etmeyelim.
    if (trim((string) $mevcut['deger']) === $deger) {
        return ['durum' => 'degismedi'];
    }

    db()->prepare(
        'UPDATE pratik_bilgiler
            SET aday_deger = :deger, aday_donem = :donem, aday_notu = :not,
                aday_guven = :guven, aday_tarihi = NOW()
          WHERE id = :id'
    )->execute([
        'deger' => $deger,
        'donem' => trim((string) ($veri['donem'] ?? '')) ?: null,
        'not'   => trim((string) ($veri['not'] ?? '')) ?: null,
        'guven' => isset($veri['guven']) ? max(0, min(100, (int) $veri['guven'])) : null,
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
                aday_deger = NULL, aday_donem = NULL,
                aday_notu = NULL, aday_guven = NULL, aday_tarihi = NULL
          WHERE id = :id AND aday_deger IS NOT NULL'
    )->execute(['id' => $id]);
}

/** Adayı reddeder; yayındaki değer yerinde kalır. */
function pratik_reddet(int $id): void
{
    db()->prepare(
        'UPDATE pratik_bilgiler
            SET aday_deger = NULL, aday_donem = NULL,
                aday_notu = NULL, aday_guven = NULL, aday_tarihi = NULL
          WHERE id = :id'
    )->execute(['id' => $id]);
}

/** Değeri elle düzenler (onay akışını atlar). */
function pratik_elle_yaz(int $id, string $deger, string $donem): void
{
    db()->prepare(
        'UPDATE pratik_bilgiler
            SET deger = :deger, donem = :donem, onay_tarihi = NOW(),
                aday_deger = NULL, aday_donem = NULL,
                aday_notu = NULL, aday_guven = NULL, aday_tarihi = NULL
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
