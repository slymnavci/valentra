<?php
declare(strict_types=1);

/**
 * Panelden tanımlanan grafikler: veri katmanı.
 *
 * Yonetici bir EVDS serisi seciyor, nasil gosterilecegini belirliyor
 * (deger / yillik degisim / aylik degisim), onizlemede goruyor ve yayina
 * aliyor. Veri ayni seriden kendiliginden tazeleniyor.
 *
 * Veriyi SITE cekiyor, ajan degil: EVDS anahtari sitede kayitli ve site
 * Turkiye'de barindigi icin TCMB'ye ulasiyor. Ajan yalnizca "tazele"
 * diye durtuyor (api/grafik-tazele.php).
 */

require_once __DIR__ . '/ekonomi.php';
require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/pratik.php';

/*
 * Cizgi grafikte en fazla nokta. Bes yillik gunluk kur ~1250 gozlem;
 * hepsini cizmek sayfayi agirlastirir ve 700 piksellik genislikte zaten
 * ayirt edilemez. Asilirsa HAFTA SONU degerine indiriliyor.
 */
const GRAFIK_EN_FAZLA_NOKTA = 400;

/**
 * Hazır seri kataloğu — YALNIZCA DOĞRULANMIŞ KODLAR.
 *
 * Her kod en az iki bagimsiz kaynakta ayni anlamla gectigi icin burada.
 * Politika faizi BILEREK YOK: ilk surumde TP.APIFON4 politika faizi
 * sanilmisti, oysa agirlikli ortalama fonlama maliyetiymis. Dogru kod
 * dogrulanana kadar yonetici onu "baska EVDS serisi" alanina kendisi
 * girebilir; onizleme son degeri gosterdigi icin yanlis seri hemen
 * fark edilir.
 *
 * @return array<string,array{ad:string,birim:string,donusum:string,tur:string,donem:int,kaynak:string}>
 */
function grafik_katalogu(): array
{
    return [
        'TP.FG.J0' => [
            'ad'      => 'Enflasyon (TÜFE)',
            'birim'   => 'yuzde',
            'donusum' => 'yillik',
            'tur'     => 'cizgi',
            'donem'   => 24,
            'kaynak'  => 'TÜİK — TCMB EVDS üzerinden',
        ],
        'TP.DK.USD.A.YTL' => [
            'ad'      => 'Dolar kuru (TCMB döviz alış)',
            'birim'   => 'tl',
            'donusum' => 'duzey',
            'tur'     => 'cizgi',
            'donem'   => 12,
            'kaynak'  => 'TCMB — EVDS',
        ],
        'TP.DK.EUR.A.YTL' => [
            'ad'      => 'Euro kuru (TCMB döviz alış)',
            'birim'   => 'tl',
            'donusum' => 'duzey',
            'tur'     => 'cizgi',
            'donem'   => 12,
            'kaynak'  => 'TCMB — EVDS',
        ],
        'TP.APIFON4' => [
            'ad'      => 'TCMB ağırlıklı ortalama fonlama maliyeti',
            'birim'   => 'yuzde',
            'donusum' => 'duzey',
            'tur'     => 'basamak',
            'donem'   => 24,
            'kaynak'  => 'TCMB — EVDS',
        ],
    ];
}

/** @return array<string,string> */
function grafik_donusumleri(): array
{
    return [
        'duzey'  => 'Değerin kendisi',
        'yillik' => 'Yıllık % değişim (bir yıl öncesine göre)',
        'aylik'  => 'Aylık % değişim (bir ay öncesine göre)',
    ];
}

/** @return array<int,string> */
function grafik_donemleri(): array
{
    return [12 => 'Son 1 yıl', 24 => 'Son 2 yıl', 36 => 'Son 3 yıl', 60 => 'Son 5 yıl'];
}

/** @return array<string,string> */
function grafik_birimleri(): array
{
    return ['yuzde' => 'Yüzde (%)', 'tl' => 'Türk lirası (TL)', '' => 'Birimsiz'];
}

/** @return array<string,string> */
function grafik_turleri(): array
{
    return [
        'cizgi'   => 'Çizgi',
        'basamak' => 'Basamak — karar ile değişen oranlar (faiz gibi)',
    ];
}

/** Tüm grafikler, sıraya göre. */
function grafik_listele(): array
{
    return db()->query('SELECT * FROM grafikler ORDER BY sira, id')->fetchAll();
}

function grafik_bul(int $id): ?array
{
    $ifade = db()->prepare('SELECT * FROM grafikler WHERE id = :id');
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Ana sayfada gösterilecek yayındaki grafikler.
 *
 * ASLA HATA FIRLATMAZ. Ana sayfa bu bolum yuzunden dusmemeli: sema
 * yukseltmesi bir sebeple yapilmadiysa tablo yoktur; o durumda bolum
 * sessizce bos kalir ve sebebi gunluge yazilir.
 *
 * @return list<array<string,mixed>>
 */
function grafik_ana_sayfa(): array
{
    try {
        $satirlar = db()->query(
            'SELECT * FROM grafikler
              WHERE yayinda = 1 AND ana_sayfa = 1 AND seri IS NOT NULL
              ORDER BY sira, id'
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] ana sayfa grafikleri okunamadi: ' . $e->getMessage());

        return [];
    }

    // Bozuk seri tasiyan satir cizilmez; bolumun geri kalani etkilenmez.
    return array_values(array_filter(
        $satirlar,
        static fn (array $s): bool => pratik_seri_oku((string) $s['seri']) !== []
    ));
}

/**
 * Formdan gelen tanımı denetler ve kaydeder.
 *
 * Kayit YAYINA ALMAZ: yeni grafik taslak baslar, yayin ayri bir adim.
 * Tanim degistirilince eski veri siliniyor — baska bir serinin verisi
 * yeni basligin altinda durmasin.
 *
 * @param array<string,mixed> $girdi
 * @return array{tamam:bool,id:int,hatalar:list<string>}
 */
function grafik_kaydet(array $girdi, int $id = 0): array
{
    $hatalar = [];

    $baslik = trim((string) ($girdi['baslik'] ?? ''));
    $kod    = strtoupper(trim((string) ($girdi['seri_kodu'] ?? '')));

    if (mb_strlen($baslik, 'UTF-8') < 3 || mb_strlen($baslik, 'UTF-8') > 160) {
        $hatalar[] = 'Başlık 3–160 karakter olmalı.';
    }

    // EVDS kodlari buyuk harf, rakam, nokta ve alt cizgiden olusuyor.
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,119}$/', $kod)) {
        $hatalar[] = 'Seri kodu geçersiz (örnek: TP.DK.USD.A.YTL).';
    }

    $donusum = (string) ($girdi['donusum'] ?? 'duzey');
    $birim   = (string) ($girdi['birim'] ?? '');
    $tur     = (string) ($girdi['tur'] ?? 'cizgi');
    $donem   = (int) ($girdi['donem_ay'] ?? 24);

    foreach ([[$donusum, grafik_donusumleri(), 'Gösterim'],
              [$birim, grafik_birimleri(), 'Birim'],
              [$tur, grafik_turleri(), 'Çizim türü']] as [$deger, $izinli, $ad]) {
        if (!array_key_exists($deger, $izinli)) {
            $hatalar[] = $ad . ' seçimi geçersiz.';
        }
    }

    if (!array_key_exists($donem, grafik_donemleri())) {
        $hatalar[] = 'Dönem seçimi geçersiz.';
    }

    // Degisim bir oran: birimi her zaman yuzde, cizimi her zaman cizgi.
    if ($donusum !== 'duzey') {
        $birim = 'yuzde';
        $tur   = 'cizgi';
    }

    if ($hatalar !== []) {
        return ['tamam' => false, 'id' => $id, 'hatalar' => $hatalar];
    }

    $alanlar = [
        'baslik'     => $baslik,
        'seri_kodu'  => $kod,
        'donusum'    => $donusum,
        'birim'      => $birim,
        'tur'        => $tur,
        'donem_ay'   => $donem,
        'kaynak_adi'  => trim((string) ($girdi['kaynak_adi'] ?? '')) ?: 'TCMB — EVDS',
        'ana_sayfa'  => !empty($girdi['ana_sayfa']) ? 1 : 0,
        'sira'       => (int) ($girdi['sira'] ?? 100),
    ];

    if ($id > 0) {
        $eski = grafik_bul($id);

        if ($eski === null) {
            return ['tamam' => false, 'id' => 0, 'hatalar' => ['Grafik bulunamadı.']];
        }

        // Veriyi etkileyen alanlardan biri degistiyse eski veri gecersiz.
        $veriDegisti = $eski['seri_kodu'] !== $kod || $eski['donusum'] !== $donusum
                    || (int) $eski['donem_ay'] !== $donem || $eski['tur'] !== $tur;

        $sql = 'UPDATE grafikler SET baslik = :baslik, seri_kodu = :seri_kodu,
                       donusum = :donusum, birim = :birim, tur = :tur,
                       donem_ay = :donem_ay, kaynak_adi = :kaynak_adi,
                       ana_sayfa = :ana_sayfa, sira = :sira'
             . ($veriDegisti ? ', seri = NULL, seri_tarihi = NULL, yayinda = 0' : '')
             . ' WHERE id = :id';

        db()->prepare($sql)->execute($alanlar + ['id' => $id]);

        return ['tamam' => true, 'id' => $id, 'hatalar' => []];
    }

    db()->prepare(
        'INSERT INTO grafikler (baslik, seri_kodu, donusum, birim, tur, donem_ay,
                                kaynak_adi, ana_sayfa, sira, yayinda)
         VALUES (:baslik, :seri_kodu, :donusum, :birim, :tur, :donem_ay,
                 :kaynak_adi, :ana_sayfa, :sira, 0)'
    )->execute($alanlar);

    return ['tamam' => true, 'id' => (int) db()->lastInsertId(), 'hatalar' => []];
}

/**
 * Bir grafik satırının çizim ayarları — panel ve ana sayfa AYNI ayarı kullanır.
 *
 * Eksen yalnizca oran ve degisim serilerinde sifirdan basliyor. Kur ve
 * endeks gibi duzey serilerinde sifirdan baslamak hareketi yok ediyor
 * (0-42 TL ekseninde kur duz cizgi); o durumda alan dolgusu da cizilmiyor.
 *
 * @param array<string,mixed> $g
 * @return array<string,mixed>
 */
function grafik_ciz_ayari(array $g, string $baslik = ''): array
{
    return [
        'tur'      => (string) $g['tur'],
        'birim'    => (string) $g['birim'],
        'baslik'   => $baslik,
        'kaynak'   => (string) ($g['kaynak_adi'] ?? ''),
        'ad'       => (string) $g['baslik'],
        'sifirdan' => $g['donusum'] !== 'duzey' || $g['birim'] === 'yuzde',
    ];
}

function grafik_sil(int $id): void
{
    db()->prepare('DELETE FROM grafikler WHERE id = :id')->execute(['id' => $id]);
}

/**
 * Yayına alır ya da kaldırır.
 *
 * Verisi olmayan grafik yayina ALINAMAZ: ana sayfada bos bir kutu
 * gormek, hic gormemekten kotu.
 *
 * @return array{tamam:bool,mesaj:string}
 */
function grafik_yayin(int $id, bool $yayinda): array
{
    $grafik = grafik_bul($id);

    if ($grafik === null) {
        return ['tamam' => false, 'mesaj' => 'Grafik bulunamadı.'];
    }

    if ($yayinda && pratik_seri_oku($grafik['seri'] ?? null) === []) {
        return ['tamam' => false, 'mesaj' => 'Önce veriyi çekin; verisi olmayan grafik yayına alınamaz.'];
    }

    db()->prepare('UPDATE grafikler SET yayinda = :y WHERE id = :id')
        ->execute(['y' => $yayinda ? 1 : 0, 'id' => $id]);

    return ['tamam' => true, 'mesaj' => $yayinda ? 'Grafik yayında.' : 'Grafik yayından kaldırıldı.'];
}

/**
 * Grafiğin verisini EVDS'den çeker ve dönüştürür. VERİTABANINA YAZMAZ.
 *
 * @param array<string,mixed> $grafik
 * @return array{tamam:bool,seri:list<array{0:string,1:float}>,hata:string,uyari:string,
 *               son_deger:?float,son_tarih:string,adres:string,kod:int,ham:string}
 */
function grafik_veri_cek(array $grafik, string $evdsAnahtari): array
{
    $sonuc = ['tamam' => false, 'seri' => [], 'hata' => '', 'uyari' => '', 'son_deger' => null,
              'son_tarih' => '', 'adres' => '', 'kod' => 0, 'ham' => ''];

    if ($evdsAnahtari === '') {
        return ['hata' => 'EVDS anahtarı girilmemiş (Panel → Pratik bilgiler).'] + $sonuc;
    }

    $kod     = (string) $grafik['seri_kodu'];
    $donusum = (string) $grafik['donusum'];
    $donem   = max(1, (int) $grafik['donem_ay']);

    // Degisim icin pencerenin basinda karsilastirma ayi da gerekiyor.
    $ekAy    = $donusum === 'yillik' ? 13 : ($donusum === 'aylik' ? 2 : 0);
    $tanim   = ['saglayici' => 'evds', 'seri' => $kod, 'gerigit' => ($donem + $ekAy) * 31 + 7];

    $adres = ekonomi_adres($tanim);
    $sonuc['adres'] = $adres;

    // 15 sn: EVDS yaniti kucuk; toplu tazelemede tek yavas istek butun
    // sure butcesini yemesin.
    $yanit = http_getir($adres, 15, '', ekonomi_basliklar($tanim, $evdsAnahtari));
    $sonuc['kod'] = (int) ($yanit['kod'] ?? 0);
    $sonuc['ham'] = ekonomi_kirp((string) ($yanit['govde'] ?? ''), 300);

    if (!$yanit['tamam']) {
        return ['hata' => (string) $yanit['neden']] + $sonuc;
    }

    $veri = json_decode((string) $yanit['govde'], true);

    if (!is_array($veri)) {
        // Cozumleyicinin HTML/JSON ayrimini ve mesajini yeniden kullan.
        return ['hata' => ekonomi_cozumle($tanim + ['birim' => ''], (string) $yanit['govde'])['hata']] + $sonuc;
    }

    $okuma = ekonomi_evds_gozlemler($veri, $kod);

    if (!$okuma['tamam']) {
        return ['hata' => $okuma['hata']] + $sonuc;
    }

    // Baz yili degisen seride (TUFE) yeni bazli devam da ekleniyor;
    // alinamazsa eski seri aynen kullaniliyor, sebep uyarida.
    $devam = ekonomi_evds_devam_ekle($okuma['gozlemler'], $tanim, $evdsAnahtari);
    $sonuc['uyari'] = $devam['uyari'];

    $seri = grafik_donustur($devam['gozlemler'], $donusum, (string) $grafik['tur'], $donem);

    if (count($seri) < 2) {
        return ['hata' => 'Seçilen dönemde çizilecek kadar gözlem yok ('
                        . count($seri) . ' nokta). Dönemi uzatın ya da gösterimi değiştirin.'] + $sonuc;
    }

    $son = $seri[count($seri) - 1];

    return ['tamam' => true, 'seri' => $seri, 'son_deger' => (float) $son[1],
            'son_tarih' => (string) $son[0]] + $sonuc;
}

/**
 * Ham gözlemleri grafiğin istediği seriye çevirir. Saf fonksiyon.
 *
 * @param list<array{iso:string,deger:float}> $gozlemler tarihe gore sirali
 * @return list<array{0:string,1:float}>
 */
function grafik_donustur(array $gozlemler, string $donusum, string $tur, int $donemAy): array
{
    if ($donusum === 'yillik' || $donusum === 'aylik') {
        return ekonomi_degisim_serisi(
            ekonomi_ay_sonlari($gozlemler),
            $donusum === 'yillik' ? 12 : 1,
            $donemAy
        );
    }

    $baslangic = date('Y-m-d', (int) strtotime('-' . $donemAy . ' months'));
    $pencere   = array_values(array_filter(
        $gozlemler,
        static fn (array $g): bool => $g['iso'] >= $baslangic
    ));

    if ($tur === 'basamak') {
        return ekonomi_basamaklar($pencere);
    }

    $seri = array_map(static fn (array $g): array => [$g['iso'], round($g['deger'], 4)], $pencere);

    return count($seri) > GRAFIK_EN_FAZLA_NOKTA ? grafik_seyrelt($seri) : $seri;
}

/**
 * Uzun seriyi hafta sonu değerlerine indirger; son gözlem her zaman kalır.
 *
 * Ortalama degil SON deger: ortalama kaynagin yayimladigi hicbir rakama
 * karsilik gelmez. Son gozlem korunuyor ki grafigin sag ucu ve etiketi
 * guncel degeri gostersin.
 *
 * @param list<array{0:string,1:float}> $seri
 * @return list<array{0:string,1:float}>
 */
function grafik_seyrelt(array $seri): array
{
    $haftaGore = [];

    foreach ($seri as $nokta) {
        $haftaGore[date('o-W', (int) strtotime($nokta[0]))] = $nokta;
    }

    $sonuc = array_values($haftaGore);
    $son   = $seri[count($seri) - 1];

    if ($sonuc[count($sonuc) - 1][0] !== $son[0]) {
        $sonuc[] = $son;
    }

    return $sonuc;
}

/**
 * Veriyi çeker ve saklar. Başarısızsa SON İYİ VERİ korunur.
 *
 * @return array{tamam:bool,mesaj:string,uyari?:string}
 */
function grafik_tazele(int $id): array
{
    $grafik = grafik_bul($id);

    if ($grafik === null) {
        return ['tamam' => false, 'mesaj' => 'Grafik bulunamadı.'];
    }

    $cekim = grafik_veri_cek($grafik, ayar_oku('evds_anahtari'));

    if (!$cekim['tamam']) {
        db()->prepare('UPDATE grafikler SET son_deneme = NOW(), son_hata = :h WHERE id = :id')
            ->execute(['h' => mb_substr($cekim['hata'], 0, 500, 'UTF-8'), 'id' => $id]);

        return ['tamam' => false, 'mesaj' => $cekim['hata']];
    }

    $json = pratik_seri_temizle($cekim['seri']);

    if ($json === null) {
        db()->prepare('UPDATE grafikler SET son_deneme = NOW(), son_hata = :h WHERE id = :id')
            ->execute(['h' => 'Gelen seri denetimden geçmedi.', 'id' => $id]);

        return ['tamam' => false, 'mesaj' => 'Gelen seri denetimden geçmedi.'];
    }

    db()->prepare(
        'UPDATE grafikler
            SET seri = :s, seri_tarihi = NOW(), son_deneme = NOW(), son_hata = NULL
          WHERE id = :id'
    )->execute(['s' => $json, 'id' => $id]);

    /*
     * Son gozlem mesajda: panelde "Veriyi simdi cek" denince serinin
     * nerede bittigi hemen goruluyor. Seri durmussa (baz yili degisimi
     * gibi) cekim "basarili" olsa da tarih bunu ele veriyor.
     */
    $mesaj = count($cekim['seri']) . ' nokta alındı, son gözlem ' . $cekim['son_tarih'] . '.';

    if ($cekim['uyari'] !== '') {
        $mesaj .= ' Uyarı: ' . $cekim['uyari'];
    }

    return ['tamam' => true, 'mesaj' => $mesaj, 'uyari' => $cekim['uyari']];
}

/**
 * Eskimiş grafikleri tazeler (ajanın dürtmesiyle).
 *
 * Sinir var: her cekim bir dis istek ve bu uc bir web istegi icinde
 * calisiyor. Paylasimli hostingin zaman asimini asmamak icin tur basina
 * en fazla $enFazla grafik ve $sureButcesi saniye; kalanlar bir sonraki
 * turda (en eskiler once) aliniyor.
 *
 * @return array{tazelenen:int,basarisiz:int,atlanan:int,hatalar:list<string>,uyarilar:list<string>}
 */
function grafik_bayatlari_tazele(int $saat = 3, int $enFazla = 8, int $sureButcesi = 40): array
{
    $baslangic = time();

    $ifade = db()->prepare(
        'SELECT id, baslik FROM grafikler
          WHERE seri_tarihi IS NULL OR seri_tarihi < :sinir
          ORDER BY seri_tarihi IS NOT NULL, seri_tarihi, id'
    );
    $ifade->execute(['sinir' => date('Y-m-d H:i:s', time() - $saat * 3600)]);
    $bayatlar = $ifade->fetchAll();

    $ozet = ['tazelenen' => 0, 'basarisiz' => 0, 'atlanan' => max(0, count($bayatlar) - $enFazla),
             'hatalar' => [], 'uyarilar' => []];

    foreach (array_slice($bayatlar, 0, $enFazla) as $sira => $grafik) {
        if (time() - $baslangic >= $sureButcesi) {
            $ozet['atlanan'] += min($enFazla, count($bayatlar)) - $sira;
            break;
        }

        $sonuc = grafik_tazele((int) $grafik['id']);

        if ($sonuc['tamam']) {
            $ozet['tazelenen']++;

            if (($sonuc['uyari'] ?? '') !== '') {
                $ozet['uyarilar'][] = $grafik['baslik'] . ': ' . $sonuc['uyari'];
            }
        } else {
            $ozet['basarisiz']++;
            $ozet['hatalar'][] = $grafik['baslik'] . ': ' . $sonuc['mesaj'];
        }
    }

    return $ozet;
}
