<?php
declare(strict_types=1);

/**
 * Ekonomik istatistikler: resmî API'lerden doğrudan okuma.
 *
 * NEDEN AYRI BIR YOL: pratik bilgilerin geri kalani sayfa KAZIYARAK
 * toplaniyor — sayfa indiriliyor, modele okutuluyor, cikan rakam onaya
 * sunuluyor. Sayilar icin bu yol gereksiz kirilgan:
 *
 *   - Sayfa duzeni degisince desen tutmuyor.
 *   - Model rakami yanlis okuyabiliyor; "1.234,56" ile "1.234.56"
 *     arasindaki fark bir vergi hesabinda gercek zarar demek.
 *   - Kamu siteleri veri merkezi IP'lerini engelliyor, ajan sayfaya
 *     hic ulasamiyor.
 *
 * Bu kaynaklarin hepsinin MAKINE OKUNUR ucu var. Oradan gelen sayi
 * zaten sayi; okunmasi gerekmiyor, cevrilmesi yetiyor. Model hic
 * devreye girmiyor.
 *
 * ONAY YINE SART. Deger buradan da ADAY olarak gidiyor. API'den gelmesi
 * dogru donemde, dogru seriden geldigini kanitlamaz — seri kodu yanlis
 * secilmis olabilir, kaynak veriyi revize etmis olabilir. Rakamin
 * sitede gorunmesi yine mali musavirin onayina bagli.
 *
 * Bu dosya VERITABANI KULLANMAZ: hem site paneli hem ajan ayni
 * cozumleyicileri kullansin diye saf fonksiyonlardan olusuyor. Tasima
 * (HTTP) her iki tarafta kendi istemcisiyle yapiliyor.
 */

require_once __DIR__ . '/http_ortak.php';

/**
 * Hangi pratik bilgi hangi seriden okunuyor.
 *
 * Anahtar = pratik_bilgiler.anahtar. Burada tanimli olmayan bilgiler
 * eskisi gibi sayfa okunarak toplanmaya devam eder; bu katman onlarin
 * yerine gecmiyor, yalnizca sayisal olanlari devraliyor.
 *
 * @return array<string,array<string,mixed>>
 */
function ekonomi_seriler(): array
{
    return [
        /* ---- TCMB EVDS (anahtar ister) --------------------------------- */

        'politika-faizi' => [
            'saglayici'  => 'evds',
            'seri'       => 'TP.APIFON4',
            'birim'      => 'yuzde',
            'gerigit'    => 400,            // gun
            'kaynak_adi' => 'TCMB — EVDS',
            'kaynak_url' => 'https://evds2.tcmb.gov.tr/',
            'aciklama'   => 'Bir hafta vadeli repo ihale faiz oranı',
            // API duserse satirin kendi sayfasi okunsun: bu rakam
            // Turkce derleme sayfalarinda da yaziyor.
            'yedek_kazima' => true,
        ],

        'enflasyon-orani' => [
            'saglayici'  => 'evds',
            'seri'       => 'TP.FG.J0',
            'hesap'      => 'tufe',         // endeksten degisim hesapla
            'birim'      => 'yuzde',
            'gerigit'    => 800,
            'kaynak_adi' => 'TÜİK — TCMB EVDS üzerinden',
            'kaynak_url' => 'https://evds2.tcmb.gov.tr/',
            'aciklama'   => 'Tüketici fiyat endeksi aylık ve yıllık değişimi',
            'yedek_kazima' => true,
        ],

        /* ---- Dunya Bankasi (anahtar istemez) --------------------------- */

        'gsyh' => [
            'saglayici'  => 'dunya_bankasi',
            'seri'       => 'NY.GDP.MKTP.CD',
            'birim'      => 'usd_buyuk',
            'kaynak_adi' => 'Dünya Bankası',
            'kaynak_url' => 'https://api.worldbank.org/',
            'aciklama'   => 'Cari fiyatlarla gayrisafi yurt içi hasıla',
            // Kazima YEDEGI YOK. Bu gostergelerin Turkce derleme
            // sayfasi yok; API duserse modele sayfa okutmak bos
            // istek harcamaktan baska ise yaramaz.
            'yedek_kazima' => false,
        ],

        'kisi-basi-gelir' => [
            'saglayici'  => 'dunya_bankasi',
            'seri'       => 'NY.GDP.PCAP.CD',
            'birim'      => 'usd',
            'kaynak_adi' => 'Dünya Bankası',
            'kaynak_url' => 'https://api.worldbank.org/',
            'aciklama'   => 'Kişi başına düşen gayrisafi yurt içi hasıla',
            // Kazima YEDEGI YOK. Bu gostergelerin Turkce derleme
            // sayfasi yok; API duserse modele sayfa okutmak bos
            // istek harcamaktan baska ise yaramaz.
            'yedek_kazima' => false,
        ],

        'buyume-orani' => [
            'saglayici'  => 'dunya_bankasi',
            'seri'       => 'NY.GDP.MKTP.KD.ZG',
            'birim'      => 'yuzde',
            'kaynak_adi' => 'Dünya Bankası',
            'kaynak_url' => 'https://api.worldbank.org/',
            'aciklama'   => 'Sabit fiyatlarla yıllık büyüme oranı',
            // Kazima YEDEGI YOK. Bu gostergelerin Turkce derleme
            // sayfasi yok; API duserse modele sayfa okutmak bos
            // istek harcamaktan baska ise yaramaz.
            'yedek_kazima' => false,
        ],

        /* ---- IMF DataMapper (anahtar istemez) -------------------------- */

        'issizlik-orani' => [
            'saglayici'  => 'imf',
            'seri'       => 'LUR',
            'birim'      => 'yuzde',
            'kaynak_adi' => 'IMF — World Economic Outlook',
            'kaynak_url' => 'https://www.imf.org/external/datamapper/',
            'aciklama'   => 'Yıllık ortalama işsizlik oranı',
            // Kazima YEDEGI YOK. Bu gostergelerin Turkce derleme
            // sayfasi yok; API duserse modele sayfa okutmak bos
            // istek harcamaktan baska ise yaramaz.
            'yedek_kazima' => false,
        ],

        'kamu-borcu-gsyh' => [
            'saglayici'  => 'imf',
            'seri'       => 'GGXWDG_NGDP',
            'birim'      => 'yuzde',
            'kaynak_adi' => 'IMF — World Economic Outlook',
            'kaynak_url' => 'https://www.imf.org/external/datamapper/',
            'aciklama'   => 'Genel yönetim brüt borç stokunun GSYH’ye oranı',
            // Kazima YEDEGI YOK. Bu gostergelerin Turkce derleme
            // sayfasi yok; API duserse modele sayfa okutmak bos
            // istek harcamaktan baska ise yaramaz.
            'yedek_kazima' => false,
        ],
    ];
}

/** Bu anahtar API'den okunabiliyor mu? */
function ekonomi_serisi(string $anahtar): ?array
{
    return ekonomi_seriler()[$anahtar] ?? null;
}

/**
 * Sağlayıcının anahtar (API key) isteyip istemediği.
 *
 * Anahtarsiz saglayicilar bir kurulum adimi beklemeden calisir;
 * anahtar isteyenler anahtar girilene kadar sessizce atlanir.
 */
function ekonomi_anahtar_ister(string $saglayici): bool
{
    return $saglayici === 'evds';
}

/**
 * Serinin sorgu adresini kurar.
 *
 * @param array<string,mixed> $seri
 */
function ekonomi_adres(array $seri, string $evdsAnahtari = ''): string
{
    $kod = (string) $seri['seri'];

    return match ((string) $seri['saglayici']) {
        'evds' => 'https://evds2.tcmb.gov.tr/service/evds/'
                . 'series=' . rawurlencode($kod)
                . '&startDate=' . date('d-m-Y', strtotime('-' . (int) ($seri['gerigit'] ?? 400) . ' days'))
                . '&endDate=' . date('d-m-Y')
                . '&type=json'
                // formulas=0: HAM DUZEY isteniyor. Degisim oranini
                // EVDS'ye hesaplatmak yerine kendimiz hesapliyoruz;
                // boylece hangi iki donemin karsilastirildigi belli
                // oluyor ve onaylayan kisi rakami denetleyebiliyor.
                . '&formulas=0'
                . ($evdsAnahtari !== '' ? '&key=' . rawurlencode($evdsAnahtari) : ''),

        'dunya_bankasi' => 'https://api.worldbank.org/v2/country/TUR/indicator/'
                         . rawurlencode($kod)
                         // mrnev=1: en son BOS OLMAYAN deger. Son yili
                         // istemek cogu gostergede bos doner, cunku
                         // veri bir iki yil gecikmeli yayimlaniyor.
                         . '?format=json&mrnev=1',

        'imf' => 'https://www.imf.org/external/datamapper/api/v1/'
               . rawurlencode($kod) . '/TUR',

        default => '',
    };
}

/**
 * Sağlayıcının istediği ek HTTP başlıkları.
 *
 * @return list<string>
 */
function ekonomi_basliklar(array $seri, string $evdsAnahtari = ''): array
{
    if ((string) $seri['saglayici'] === 'evds' && $evdsAnahtari !== '') {
        /*
         * Anahtar hem "key" basligiyla hem sorgu dizesinde
         * gonderiliyor (bkz. ekonomi_adres). Ikisi birden, cunku
         * dogrudan istekte baslik kesin calisiyor; site uzerinden
         * yapilan YEDEK istekte ise baslik tasinmiyor ve geriye
         * yalnizca sorgu dizesi kaliyor. Fazladan baslik zarar
         * vermiyor.
         *
         * EVDS sorgu dizesindeki anahtari kabul etmezse yedek yol
         * calismaz; o durumda panel dugmesi (site->EVDS, baslikli)
         * gecerli yoldur.
         */
        return ['key: ' . $evdsAnahtari];
    }

    return [];
}

/**
 * Ham yanıtı değere çevirir.
 *
 * @param array<string,mixed> $seri
 * @return array{tamam:bool,deger:string,donem:string,not:string,hata:string}
 */
function ekonomi_cozumle(array $seri, string $govde): array
{
    $bos = ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => ''];

    $veri = json_decode($govde, true);

    if (!is_array($veri)) {
        return $bos + ['hata' => 'Yanıt JSON değil: '
                               . ekonomi_kirp($govde)];
    }

    return match ((string) $seri['saglayici']) {
        'evds'          => ekonomi_evds_cozumle($seri, $veri),
        'dunya_bankasi' => ekonomi_dunya_bankasi_cozumle($seri, $veri),
        'imf'           => ekonomi_imf_cozumle($seri, $veri),
        default         => $bos + ['hata' => 'Bilinmeyen sağlayıcı.'],
    };
}

/**
 * EVDS yanıtı: {"items":[{"Tarih":"01-01-2026","TP_FG_J0":"123,45"}]}
 *
 * @param array<string,mixed> $seri
 * @param array<mixed>        $veri
 * @return array{tamam:bool,deger:string,donem:string,not:string,hata:string}
 */
function ekonomi_evds_cozumle(array $seri, array $veri): array
{
    $bos = ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => ''];

    if (!isset($veri['items']) || !is_array($veri['items'])) {
        // EVDS hatayi da JSON olarak doner; mesaji aynen tasiyoruz,
        // "anahtar gecersiz" ile "seri yok" bambaska islere bakar.
        $mesaj = (string) ($veri['message'] ?? $veri['detail'] ?? $veri['error'] ?? '');

        /*
         * EVDS'nin kendi hata mesaji geldiyse yanit ULASMIS demektir;
         * baska bir IP'den sormak ayni cevabi getirir. Gecersiz
         * anahtari on kez denemenin anlami yok.
         */
        return $bos + ['hata' => $mesaj !== ''
            ? 'EVDS: ' . $mesaj
            : 'EVDS yanıtında "items" yok.',
            'tekrar' => $mesaj === ''];
    }

    $sutun = str_replace('.', '_', (string) $seri['seri']);
    $dizi  = [];

    foreach ($veri['items'] as $satir) {
        if (!is_array($satir)) {
            continue;
        }

        $ham = $satir[$sutun] ?? null;

        if ($ham === null || $ham === '' || $ham === '-') {
            continue;
        }

        $sayi = ekonomi_sayiya((string) $ham);

        if ($sayi === null) {
            continue;
        }

        $dizi[] = ['tarih' => (string) ($satir['Tarih'] ?? ''), 'deger' => $sayi];
    }

    if ($dizi === []) {
        return $bos + ['hata' => 'EVDS seride dolu gözlem döndürmedi ('
                               . $seri['seri'] . ').'];
    }

    if ((string) ($seri['hesap'] ?? '') === 'tufe') {
        return ekonomi_tufe_hesapla($dizi);
    }

    $son = $dizi[count($dizi) - 1];

    return [
        'tamam' => true,
        'deger' => ekonomi_bicimle($son['deger'], (string) $seri['birim']),
        'donem' => ekonomi_evds_donem($son['tarih']),
        'not'   => 'TCMB EVDS ' . $seri['seri'] . ' serisinin son gözlemi.',
        'hata'  => '',
    ];
}

/**
 * TÜFE endeksinden aylık ve yıllık değişimi hesaplar.
 *
 * Neden endeksten: EVDS'nin hazir degisim serisi yerine duzey endeksi
 * isteniyor ve oran burada cikariliyor. Boylece hangi iki ayin
 * karsilastirildigi ve hangi endeks degerlerinin kullanildigi notta
 * yaziyor — onaylayan kisi rakami TUIK bulteniyle bire bir
 * karsilastirabiliyor.
 *
 * @param list<array{tarih:string,deger:float}> $dizi
 * @return array{tamam:bool,deger:string,donem:string,not:string,hata:string}
 */
function ekonomi_tufe_hesapla(array $dizi): array
{
    $adet = count($dizi);

    if ($adet < 13) {
        return ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => '',
                'hata'  => 'Yıllık değişim için 13 aylık endeks gerekiyor, '
                         . $adet . ' geldi.', 'tekrar' => false];
    }

    $son     = $dizi[$adet - 1];
    $onceki  = $dizi[$adet - 2];
    $gecenYil = $dizi[$adet - 13];

    if ($onceki['deger'] <= 0.0 || $gecenYil['deger'] <= 0.0) {
        return ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => '',
                'hata'  => 'Karşılaştırma endeksi sıfır ya da eksi; hesaplanamaz.'];
    }

    $aylik  = ($son['deger'] / $onceki['deger'] - 1) * 100;
    $yillik = ($son['deger'] / $gecenYil['deger'] - 1) * 100;

    return [
        'tamam' => true,
        'deger' => 'Aylık: ' . ekonomi_bicimle($aylik, 'yuzde') . "\n"
                 . 'Yıllık: ' . ekonomi_bicimle($yillik, 'yuzde'),
        'donem' => ekonomi_evds_donem($son['tarih']),
        'not'   => 'TÜFE endeksinden hesaplandı: '
                 . ekonomi_evds_donem($son['tarih']) . ' = '
                 . number_format($son['deger'], 2, ',', '.') . ', bir önceki ay = '
                 . number_format($onceki['deger'], 2, ',', '.') . ', geçen yıl aynı ay = '
                 . number_format($gecenYil['deger'], 2, ',', '.')
                 . '. TÜİK bülteniyle karşılaştırın.',
        'hata'  => '',
    ];
}

/**
 * Dünya Bankası yanıtı: [ {sayfa bilgisi}, [ {"date":"2024","value":1.2e12} ] ]
 *
 * @param array<string,mixed> $seri
 * @param array<mixed>        $veri
 * @return array{tamam:bool,deger:string,donem:string,not:string,hata:string}
 */
function ekonomi_dunya_bankasi_cozumle(array $seri, array $veri): array
{
    $bos = ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => ''];

    // Hata durumunda ilk oge bir mesaj dizisi oluyor, gozlem listesi hic gelmiyor.
    if (isset($veri[0]['message'])) {
        $mesaj = $veri[0]['message'][0]['value'] ?? '';

        return $bos + ['hata' => 'Dünya Bankası: ' . (string) $mesaj];
    }

    $kayitlar = ekonomi_gozlem_listesi($veri);

    if ($kayitlar === []) {
        return $bos + ['hata' => 'Dünya Bankası yanıtında dolu gözlem yok ('
                               . $seri['seri'] . ').'];
    }

    // mrnev=1 zaten tek gozlem donduruyor; yine de en yeni yil seciliyor,
    // parametre yok sayilirsa da dogru sonuc versin.
    usort($kayitlar, static fn (array $a, array $b): int => $a['yil'] <=> $b['yil']);
    $son = $kayitlar[count($kayitlar) - 1];

    return [
        'tamam' => true,
        'deger' => ekonomi_bicimle($son['deger'], (string) $seri['birim']),
        'donem' => $son['yil'] . ' yılı',
        'not'   => 'Dünya Bankası ' . $seri['seri'] . ' göstergesi, '
                 . $son['yil'] . ' yılı değeri.',
        'hata'  => '',
    ];
}

/**
 * Dünya Bankası gövdesinden {yil, deger} listesi çıkarır.
 *
 * Gozlem listesi yanitin ikinci ogesinde duruyor ama bu konuma
 * bagimli kalmamak icin dizi taraniyor: tarih ve deger alani tasiyan
 * ilk liste kullaniliyor. Bicim degisirse veri yine bulunur.
 *
 * @param array<mixed> $veri
 * @return list<array{yil:int,deger:float}>
 */
function ekonomi_gozlem_listesi(array $veri): array
{
    foreach ($veri as $oge) {
        if (!is_array($oge)) {
            continue;
        }

        $kayitlar = [];

        foreach ($oge as $satir) {
            if (!is_array($satir) || !array_key_exists('date', $satir)
                || !array_key_exists('value', $satir)) {
                continue;
            }

            if ($satir['value'] === null || $satir['value'] === '') {
                continue;
            }

            $kayitlar[] = [
                'yil'   => (int) $satir['date'],
                'deger' => (float) $satir['value'],
            ];
        }

        if ($kayitlar !== []) {
            return $kayitlar;
        }
    }

    return [];
}

/**
 * IMF DataMapper yanıtı: {"values":{"LUR":{"TUR":{"2024":8.7,"2025":9.1}}}}
 *
 * DIKKAT — TAHMIN AYIKLAMA: DataMapper gelecek yillarin TAHMINLERINI de
 * ayni dizide veriyor. Listenin sonunu almak, gerceklesmis veri yerine
 * IMF ongorusunu yayimlamak olurdu. Bu yuzden icinde bulunulan yildan
 * sonraki yillar atiliyor; icinde bulunulan yil da cogu gostergede
 * henuz tahmindir, o yuzden notta acikca yaziliyor.
 *
 * @param array<string,mixed> $seri
 * @param array<mixed>        $veri
 * @return array{tamam:bool,deger:string,donem:string,not:string,hata:string}
 */
function ekonomi_imf_cozumle(array $seri, array $veri): array
{
    $bos = ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => ''];
    $kod = (string) $seri['seri'];

    $yillar = $veri['values'][$kod]['TUR'] ?? null;

    if (!is_array($yillar)) {
        return $bos + ['hata' => 'IMF yanıtında values.' . $kod
                               . '.TUR bulunamadı.'];
    }

    $buYil    = (int) date('Y');
    $kayitlar = [];

    foreach ($yillar as $yil => $deger) {
        $yil = (int) $yil;

        if ($yil <= 0 || $yil > $buYil || $deger === null || $deger === '') {
            continue;
        }

        $kayitlar[$yil] = (float) $deger;
    }

    if ($kayitlar === []) {
        return $bos + ['hata' => 'IMF serisinde geçmiş yıla ait değer yok ('
                               . $kod . ').'];
    }

    ksort($kayitlar);
    $yil   = array_key_last($kayitlar);
    $deger = $kayitlar[$yil];

    return [
        'tamam' => true,
        'deger' => ekonomi_bicimle($deger, (string) $seri['birim']),
        'donem' => $yil . ' yılı',
        'not'   => 'IMF World Economic Outlook ' . $kod . ' göstergesi.'
                 . ($yil === $buYil
                     ? ' DİKKAT: içinde bulunulan yıla ait değer genellikle'
                     . ' gerçekleşme değil IMF tahminidir; onaylamadan önce'
                     . ' doğrulayın.'
                     : ' Gerçekleşmiş yıl verisi.'),
        'hata'  => '',
    ];
}

/**
 * Sayıyı Türkçe biçimde, birimiyle yazar.
 *
 * Buyuk tutarlar milyar/trilyon olarak yaziliyor: 1.320.000.000.000
 * okunabilir bir sayi degil, "1,32 trilyon" okunabilir.
 */
function ekonomi_bicimle(float $deger, string $birim): string
{
    return match ($birim) {
        'yuzde' => '%' . number_format($deger, 2, ',', '.'),

        'usd' => number_format($deger, 0, ',', '.') . ' ABD doları',

        'usd_buyuk' => match (true) {
            abs($deger) >= 1e12 => number_format($deger / 1e12, 2, ',', '.')
                                 . ' trilyon ABD doları',
            abs($deger) >= 1e9  => number_format($deger / 1e9, 2, ',', '.')
                                 . ' milyar ABD doları',
            default             => number_format($deger, 0, ',', '.') . ' ABD doları',
        },

        default => number_format($deger, 2, ',', '.'),
    };
}

/**
 * Metindeki sayıyı float'a çevirir.
 *
 * EVDS sayiyi bazen "123.45" bazen "123,45" olarak veriyor ve binlik
 * ayraci kullanabiliyor. Ikisini ayirt etmenin guvenli yolu SON
 * ayraca bakmak: ondalik ayrac hep sonda durur.
 */
function ekonomi_sayiya(string $ham): ?float
{
    $ham = trim($ham);

    if ($ham === '' || !preg_match('/\d/', $ham)) {
        return null;
    }

    $ham = preg_replace('/[^\d,.\-]/', '', $ham) ?? '';

    if ($ham === '' || $ham === '-') {
        return null;
    }

    $sonNokta = strrpos($ham, '.');
    $sonVirgul = strrpos($ham, ',');

    if ($sonNokta === false && $sonVirgul === false) {
        return (float) $ham;
    }

    $ondalikYeri = max(
        $sonNokta === false ? -1 : $sonNokta,
        $sonVirgul === false ? -1 : $sonVirgul
    );

    $tam    = preg_replace('/[^\d\-]/', '', substr($ham, 0, $ondalikYeri)) ?? '';
    $kusurat = preg_replace('/\D/', '', substr($ham, $ondalikYeri + 1)) ?? '';

    /*
     * Ayractan sonra uc hane varsa bu ondalik degil BINLIK ayraci
     * ("1.234"). Ondalik kismi uc haneli olan bir istatistik yok;
     * binlik ayracli sayi ise her yerde.
     */
    if (strlen($kusurat) === 3) {
        return (float) (preg_replace('/[^\d\-]/', '', $ham) ?? '0');
    }

    return (float) ($tam . '.' . ($kusurat === '' ? '0' : $kusurat));
}

/** EVDS'nin "01-09-2026" biçimindeki tarihini okunur döneme çevirir. */
function ekonomi_evds_donem(string $tarih): string
{
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
              'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $tarih, $e)) {
        return ($aylar[(int) $e[2]] ?? $e[2]) . ' ' . $e[3];
    }

    // Aylik seriler bazen "2026-9" biciminde geliyor.
    if (preg_match('/^(\d{4})-(\d{1,2})$/', $tarih, $e)) {
        return ($aylar[(int) $e[2]] ?? $e[2]) . ' ' . $e[1];
    }

    return $tarih;
}

/**
 * Başarısız bir okuma başka bir yoldan yeniden denenmeli mi?
 *
 * Bazi basarisizliklar AGLA ilgili: istek hic ulasmadi, engellendi ya
 * da engel sayfasi dondu. Bunlar site uzerinden tekrar denendiginde
 * cogu zaman geciyor.
 *
 * Bazilari ise ulasti ve kaynagin kendisi "olmaz" dedi: gecersiz
 * anahtar, tanimsiz seri, eksik gozlem. Bunlari tekrar denemek ayni
 * cevabi bir kez daha almaktan ibaret.
 *
 * @param array<string,mixed> $sonuc
 */
function ekonomi_tekrar_denenir(array $sonuc): bool
{
    return empty($sonuc['tamam']) && ($sonuc['tekrar'] ?? true) === true;
}

/**
 * Ekranda gösterilecek adres — API anahtarı maskelenir.
 *
 * EVDS adresi anahtari sorgu dizesinde tasiyor. Tani ekraninda tam
 * adresi yazmak, anahtari ekran goruntusuyle paylasilabilir hale
 * getirirdi; panel parola arkasinda olsa bile ekran goruntusu
 * disariya cikiyor.
 */
function ekonomi_adres_gizle(string $adres): string
{
    return preg_replace('/([?&]key=)[^&]*/i', '$1***', $adres) ?? $adres;
}

/** Hata mesajında gösterilecek kısa gövde özeti. */
function ekonomi_kirp(string $govde, int $sinir = 160): string
{
    $govde = trim(preg_replace('/\s+/', ' ', $govde) ?? '');

    return mb_strlen($govde, 'UTF-8') > $sinir
        ? mb_substr($govde, 0, $sinir, 'UTF-8') . '…'
        : $govde;
}

/**
 * Seriyi çeker ve çözümler (site tarafı).
 *
 * @param array<string,mixed> $seri
 * @return array{tamam:bool,deger:string,donem:string,not:string,hata:string,
 *               adres:string,kod:int,boyut:int,ham:string}
 */
function ekonomi_oku(array $seri, string $evdsAnahtari = ''): array
{
    $adres = ekonomi_adres($seri, $evdsAnahtari);
    $kunye = ['adres' => $adres, 'kod' => 0, 'boyut' => 0, 'ham' => ''];

    if ($adres === '') {
        return ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => '',
                'hata'  => 'Sağlayıcı tanınmıyor.', 'tekrar' => false] + $kunye;
    }

    if (ekonomi_anahtar_ister((string) $seri['saglayici']) && $evdsAnahtari === '') {
        return ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => '',
                'hata'  => 'Bu kaynak API anahtarı istiyor; panele henüz '
                         . 'girilmemiş.', 'tekrar' => false] + $kunye;
    }

    $yanit = http_getir($adres, 25, '', ekonomi_basliklar($seri, $evdsAnahtari));

    $kunye['kod']   = (int) ($yanit['kod'] ?? 0);
    $kunye['boyut'] = (int) ($yanit['boyut'] ?? 0);
    $kunye['ham']   = ekonomi_kirp((string) ($yanit['govde'] ?? ''), 400);

    if (!$yanit['tamam']) {
        return ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => '',
                'hata'  => (string) $yanit['neden']] + $kunye;
    }

    return ekonomi_cozumle($seri, (string) $yanit['govde']) + $kunye;
}
