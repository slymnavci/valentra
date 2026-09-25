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

        /*
         * POLITIKA FAIZI BURADA YOK — bilincli.
         *
         * Ilk surumde TP.APIFON4 serisinden okunuyordu. O seri politika
         * faizi (bir hafta vadeli repo) DEGIL, "TCMB agirlikli ortalama
         * fonlama maliyeti". Ikisi cogu donem ayni ama ayrisabiliyor
         * (TCMB fonlamayi gecelik borc verme faizinden yaptiginda
         * maliyet politika faizinin ustune cikiyor). Bir mali musavirlik
         * sitesinde "politika faizi" etiketiyle baska bir oran
         * yayimlanamaz.
         *
         * Politika faizinin EVDS kodu dogrulanana kadar deger eskisi gibi
         * TCMB'nin kendi sayfasi okunarak toplaniyor. Fonlama maliyeti
         * ise kendi adiyla grafik olusturucuda secilebilir.
         */

        'enflasyon-orani' => [
            'saglayici'  => 'evds',
            'seri'       => 'TP.FG.J0',
            'hesap'      => 'tufe',         // endeksten degisim hesapla
            'birim'      => 'yuzde',
            // 24 aylik yillik degisim icin 36 aylik endeks gerekiyor:
            // her ayin karsiligi bir yil oncesi.
            'gerigit'    => 1130,
            'grafik'     => ['tur' => 'cizgi', 'baslik' => 'Yıllık enflasyon, son 24 ay'],
            'kaynak_adi' => 'TÜİK — TCMB EVDS üzerinden',
            'kaynak_url' => 'https://evds3.tcmb.gov.tr/',
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
function ekonomi_adres(array $seri): string
{
    $kod = (string) $seri['seri'];

    return match ((string) $seri['saglayici']) {
        /*
         * EVDS3 veri ucu.
         *
         * Eski adres (evds2.tcmb.gov.tr/service/evds/) artik veri
         * degil EVDS'nin yeni web arayuzunu donduruyor; canlida ilk
         * denemede "Yanit JSON degil: <!DOCTYPE html>" ile goruldu.
         * Yeni uc bakimi suren evds kutuphanesinin kaynak kodundan
         * teyit edildi: taban adres, parametreler yolun sonuna "?"
         * olmadan ekleniyor, anahtar YALNIZCA "key" basligiyla.
         *
         * Anahtar URL'de GONDERILMIYOR: Nisan 2024'ten beri kabul
         * edilmiyor, ustelik adresteki anahtar sunucu ve vekil
         * gunluklerine yaziliyor.
         */
        'evds' => 'https://evds3.tcmb.gov.tr/igmevdsms-dis/'
                . 'series=' . rawurlencode($kod)
                . '&startDate=' . date('d-m-Y', strtotime('-' . (int) ($seri['gerigit'] ?? 400) . ' days'))
                . '&endDate=' . date('d-m-Y')
                . '&type=json'
                // formulas=0: HAM DUZEY isteniyor. Degisim oranini
                // EVDS'ye hesaplatmak yerine kendimiz hesapliyoruz;
                // boylece hangi iki donemin karsilastirildigi belli
                // oluyor ve onaylayan kisi rakami denetleyebiliyor.
                . '&formulas=0',

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
         * EVDS anahtari YALNIZCA bu baslikla kabul ediyor. Site
         * uzerinden yapilan yedek istekte baslik ajandan gelmiyor;
         * api/getir.php EVDS adresini gorunce sitede kayitli anahtari
         * kendisi ekliyor.
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
        /*
         * HTML geldiyse bunu ACIKCA soyle. "JSON degil" dogru ama
         * yetersizdi: EVDS adresini degistirdiginde tam olarak bu
         * mesaj goruldu ve sebebin adres degisikligi oldugu ancak
         * yanitin icine bakinca anlasildi.
         */
        $html = (bool) preg_match('/^\s*(<!doctype html|<html)/i', $govde);

        return $bos + ['hata' => ($html
            ? 'Kaynak veri yerine bir web sayfası döndürdü; adres değişmiş ya da '
            . 'istek engellenmiş olabilir. Yanıtın başı: '
            : 'Yanıt JSON değil: ') . ekonomi_kirp($govde)];
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

    $okuma = ekonomi_evds_gozlemler($veri, (string) $seri['seri']);

    if (!$okuma['tamam']) {
        return $bos + ['hata' => $okuma['hata'], 'tekrar' => $okuma['tekrar']];
    }

    $dizi  = $okuma['gozlemler'];
    $devam = ['not' => '', 'uyari' => ''];

    /*
     * Baz yili degisen seride yeni bazli devam da aliniyor. Anahtar
     * yalnizca ekonomi_oku() uzerinden geliyor; ajanin site yedegi
     * (ham govdeyle) eskisi gibi tek seriyi cozer.
     */
    if (($seri['evds_anahtari'] ?? '') !== '') {
        $devam = ekonomi_evds_devam_ekle($dizi, $seri, (string) $seri['evds_anahtari']);
        $dizi  = $devam['gozlemler'];
    }

    if ((string) ($seri['hesap'] ?? '') === 'tufe') {
        $sonuc = ekonomi_tufe_hesapla($dizi);

        if (!empty($sonuc['tamam']) && ($devam['not'] !== '' || $devam['uyari'] !== '')) {
            $sonuc['not'] = trim($sonuc['not'] . ' ' . $devam['not'] . ' ' . $devam['uyari']);
        }

        return $sonuc;
    }

    $son = $dizi[count($dizi) - 1];

    return [
        'tamam' => true,
        'deger' => ekonomi_bicimle($son['deger'], (string) $seri['birim']),
        'donem' => ekonomi_evds_donem($son['tarih']),
        'not'   => 'TCMB EVDS ' . $seri['seri'] . ' serisinin son gözlemi.',
        'hata'  => '',
        'seri'  => ekonomi_basamaklar($dizi),
    ];
}

/**
 * EVDS yanıtındaki bir serinin TÜM gözlemleri, tarihe göre sıralı.
 *
 * Ayri fonksiyon cunku iki kullanici var ve farkli seyler istiyorlar:
 * pratik bilgi son degeri istiyor, grafik olusturucu butun gozlemleri.
 * Grafik icin basamaklara indirgenmis seri kullanilamaz: cizgi grafik
 * iki degisim noktasini dogrudan birlestirir ve aradaki duz donemi
 * egik gosterirdi.
 *
 * @param array<mixed> $veri json_decode edilmis EVDS yaniti
 * @return array{tamam:bool,gozlemler:list<array{tarih:string,iso:string,deger:float}>,
 *               hata:string,tekrar:bool}
 */
function ekonomi_evds_gozlemler(array $veri, string $kod): array
{
    $bos = ['tamam' => false, 'gozlemler' => []];

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

    $sutun = str_replace('.', '_', $kod);
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
        $iso  = ekonomi_evds_tarih((string) ($satir['Tarih'] ?? ''));

        // Tarihi okunamayan gozlem zaman ekseninde yerlestirilemez ve
        // tarihe dayali karsilastirmada yanlis aya eslenebilir.
        if ($sayi === null || $iso === null) {
            continue;
        }

        $dizi[] = ['tarih' => (string) $satir['Tarih'], 'iso' => $iso, 'deger' => $sayi];
    }

    if ($dizi === []) {
        /*
         * Tekrar DENENMIYOR: yanit ulasti, seri bos. Cogu zaman seri
         * kodu yanlis yazilmistir; EVDS tanimadigi kod icin hata degil
         * bos liste donuyor.
         */
        return $bos + ['hata' => 'EVDS seride dolu gözlem döndürmedi (' . $kod
                               . '). Seri kodunu kontrol edin.',
                       'tekrar' => false];
    }

    /*
     * Siralama TARIHE gore yapiliyor, gelis sirasina guvenilmiyor.
     * "Son gozlem" dizinin sonu kabul ediliyor; EVDS bir gun farkli
     * sirayla donerse eski bir deger guncelmis gibi yayimlanirdi.
     */
    usort($dizi, static fn (array $a, array $b): int => strcmp($a['iso'], $b['iso']));

    return ['tamam' => true, 'gozlemler' => $dizi, 'hata' => '', 'tekrar' => false];
}

/**
 * Baz yılı değişen EVDS serilerinin devamı: eski kod => yeni kod.
 *
 * TUIK Ocak 2026 verisiyle TUFE'nin baz yilini 2003=100'den 2025=100'e
 * tasidi. Eski seri (TP.FG.J0) orada durdu ve grafik Ocak'ta takili
 * kaldi. Eski kodu kullanan her yer (pratik bilgi, panelde tanimli
 * grafikler) yeni seriyi KENDILIGINDEN ekliyor; panelde hicbir tanimi
 * degistirmek gerekmiyor.
 *
 * YENI KOD CANLIDA DOGRULANMADI (gelistirme ortami EVDS'e ulasamiyor).
 * Yanlissa zarar yok: devam serisi alinamazsa eski seri eskisi gibi
 * cizilir ve sebebi panelde/gunlukte yazar. Kod yanlis cikarsa yalnizca
 * buradaki degeri duzeltmek yetiyor.
 *
 * @return array<string,string>
 */
function ekonomi_evds_devamlari(): array
{
    return [
        'TP.FG.J0' => 'TP.TUKFIY2025.GENEL',
    ];
}

/** Serinin devam kodu; yoksa null. */
function ekonomi_evds_devami(string $kod): ?string
{
    return ekonomi_evds_devamlari()[strtoupper(trim($kod))] ?? null;
}

/**
 * Eski bazlı seriyi yeni bazlı seriye bağlar.
 *
 * Yeni seri, verisi olan HER AYDA esas; eski seri yalnizca yeni serinin
 * baslangicindan onceki aylari tamamliyor. Eski degerler yeni baza
 * olceklenerek aliniyor (katsayi = yeni / eski, ILK ortak ayda): boylece
 * son degerler TUIK bulteniyle bire bir ayni kaliyor ve yillik degisim
 * iki bazi birbirine oranlamiyor.
 *
 * Ortak ay yoksa BAGLANMIYOR: katsayi bilinmeden iki endeksi yan yana
 * koymak yillik degisimi uydurmak olur. O durumda yeni seri tek basina
 * yillik karsilastirmaya yetiyorsa (13+ ay) o, yetmiyorsa eski seri
 * donuyor.
 *
 * @param list<array{tarih:string,iso:string,deger:float}> $eski tarihe gore sirali
 * @param list<array{tarih:string,iso:string,deger:float}> $yeni tarihe gore sirali
 * @return array{gozlemler:list<array{tarih:string,iso:string,deger:float}>,baglandi:bool,not:string}
 */
function ekonomi_evds_zincirle(array $eski, array $yeni): array
{
    if ($yeni === []) {
        return ['gozlemler' => $eski, 'baglandi' => false, 'not' => ''];
    }

    if ($eski === []) {
        return ['gozlemler' => $yeni, 'baglandi' => false, 'not' => ''];
    }

    $eskiAy = [];

    foreach ($eski as $g) {
        $eskiAy[substr($g['iso'], 0, 7)] = $g;
    }

    $katsayi = null;
    $ortakAy = '';

    foreach ($yeni as $g) {
        $ay = substr($g['iso'], 0, 7);

        if (isset($eskiAy[$ay]) && $eskiAy[$ay]['deger'] > 0.0 && $g['deger'] > 0.0) {
            $katsayi = $g['deger'] / $eskiAy[$ay]['deger'];
            $ortakAy = $ay;
            break;
        }
    }

    if ($katsayi === null) {
        $yeterli = count($yeni) >= 13;

        return ['gozlemler' => $yeterli ? $yeni : $eski, 'baglandi' => false,
                'not' => 'Eski ve yeni seride ortak ay yok; '
                       . ($yeterli ? 'yalnızca yeni seri kullanıldı.' : 'yalnızca eski seri kullanıldı.')];
    }

    $ilkYeni = $yeni[0]['iso'];
    $sonuc   = [];

    foreach ($eski as $g) {
        if ($g['iso'] >= $ilkYeni) {
            break;
        }

        $sonuc[] = ['tarih' => $g['tarih'], 'iso' => $g['iso'], 'deger' => $g['deger'] * $katsayi];
    }

    return ['gozlemler' => array_merge($sonuc, $yeni), 'baglandi' => true,
            'not' => 'Eski bazlı seri ' . $ortakAy . ' ayında yeni baza bağlandı.'];
}

/**
 * Devamı olan seriye yeni bazlı gözlemleri ekler (ayrı bir EVDS isteğiyle).
 *
 * ASLA BOZMAZ: devam serisi alinamazsa gelen gozlemler aynen doner,
 * sebep 'uyari'da. Eski serinin calisan verisi yeni kodun hatasi
 * yuzunden kaybolmasin diye iki seri AYRI istekle aliniyor, EVDS'in
 * coklu seri sorgusuyla degil.
 *
 * @param list<array{tarih:string,iso:string,deger:float}> $gozlemler
 * @param array<string,mixed> $tanim ekonomi_adres() tanimi (saglayici, seri, gerigit)
 * @return array{gozlemler:list<array{tarih:string,iso:string,deger:float}>,uyari:string,not:string}
 */
function ekonomi_evds_devam_ekle(array $gozlemler, array $tanim, string $evdsAnahtari,
                                 int $zamanAsimi = 15): array
{
    $devam = ekonomi_evds_devami((string) $tanim['seri']);

    if ($devam === null || (string) $tanim['saglayici'] !== 'evds' || $evdsAnahtari === '') {
        return ['gozlemler' => $gozlemler, 'uyari' => '', 'not' => ''];
    }

    $devamTanim = ['seri' => $devam] + $tanim;
    $yanit      = http_getir(ekonomi_adres($devamTanim), $zamanAsimi, '',
                             ekonomi_basliklar($devamTanim, $evdsAnahtari));
    $okuma      = ['tamam' => false, 'hata' => (string) ($yanit['neden'] ?? 'istek başarısız')];

    if ($yanit['tamam']) {
        $veri  = json_decode((string) $yanit['govde'], true);
        $okuma = is_array($veri)
            ? ekonomi_evds_gozlemler($veri, $devam)
            : ['tamam' => false, 'hata' => 'Yanıt JSON değil: ' . ekonomi_kirp((string) $yanit['govde'])];
    }

    if (!$okuma['tamam']) {
        $uyari = 'Yeni bazlı devam serisi (' . $devam . ') alınamadı, yalnızca '
               . $tanim['seri'] . ' kullanıldı: ' . $okuma['hata'];
        error_log('[valentra] ' . $uyari);

        return ['gozlemler' => $gozlemler, 'uyari' => $uyari, 'not' => ''];
    }

    $zincir = ekonomi_evds_zincirle($gozlemler, $okuma['gozlemler']);

    return ['gozlemler' => $zincir['gozlemler'],
            'uyari'     => $zincir['baglandi'] ? '' : $zincir['not'],
            'not'       => $zincir['not'] . ' (' . $tanim['seri'] . ' → ' . $devam . ')'];
}

/**
 * EVDS tarihini Y-m-d biçimine çevirir; okunamazsa null.
 *
 * Gunluk seriler "23-09-2026", aylik seriler "2026-9" biciminde
 * geliyor. Aylik gozlem ayin ilk gunune yerlestiriliyor.
 */
function ekonomi_evds_tarih(string $tarih): ?string
{
    $tarih = trim($tarih);

    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $tarih, $e)) {
        [$gun, $ay, $yil] = [(int) $e[1], (int) $e[2], (int) $e[3]];
    } elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $tarih, $e)) {
        [$gun, $ay, $yil] = [1, (int) $e[2], (int) $e[1]];
    } else {
        return null;
    }

    return checkdate($ay, $gun, $yil) ? sprintf('%04d-%02d-%02d', $yil, $ay, $gun) : null;
}

/**
 * Seriyi değişim noktalarına indirger (basamak grafiği için).
 *
 * Politika faizi yuzlerce gunluk gozlemden olusuyor ama yilda bir iki
 * kez degisiyor. Her gunu saklamak ayni sayiyi yuzlerce kez yazmak
 * olurdu. Tutulanlar: ilk gozlem, degerin degistigi her gun ve son
 * gozlem — son gozlem degismemis olsa da tutuluyor, cunku grafigin
 * nerede bittigini o belirliyor.
 *
 * @param list<array{iso:string,deger:float}> $dizi tarihe gore sirali
 * @return list<array{0:string,1:float}>
 */
function ekonomi_basamaklar(array $dizi): array
{
    $sonuc = [];
    $adet  = count($dizi);

    foreach ($dizi as $i => $gozlem) {
        $ilk     = $i === 0;
        $sonMu   = $i === $adet - 1;
        $degisti = !$ilk && abs($gozlem['deger'] - $dizi[$i - 1]['deger']) > 1e-9;

        if ($ilk || $sonMu || $degisti) {
            $sonuc[] = [$gozlem['iso'], round($gozlem['deger'], 4)];
        }
    }

    return $sonuc;
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
 * KARSILASTIRMA AYI TARIHLE BULUNUYOR, DIZIDEKI YERIYLE DEGIL. Ilk
 * surum "gecen yilin ayni ayi"ni sondan 13. eleman sayiyordu. Bos
 * gozlemler ayiklandigi icin arada tek bir ay eksik gelse o eleman 13
 * ay oncesi olurdu ve yillik enflasyon sessizce yanlis hesaplanirdi.
 * Simdi ay anahtariyla araniyor; ay yoksa hesap YAPILMIYOR.
 *
 * @param list<array{tarih:string,iso:string,deger:float}> $dizi tarihe gore sirali
 * @return array{tamam:bool,deger:string,donem:string,not:string,hata:string}
 */
function ekonomi_tufe_hesapla(array $dizi): array
{
    $bos = ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => ''];

    $ayGore = [];

    foreach ($dizi as $gozlem) {
        $ayGore[substr($gozlem['iso'], 0, 7)] = $gozlem;
    }

    ksort($ayGore);

    $sonAy      = (string) array_key_last($ayGore);
    $oncekiAy   = ekonomi_ay_kaydir($sonAy, -1);
    $gecenYilAy = ekonomi_ay_kaydir($sonAy, -12);

    foreach ([$oncekiAy => 'bir önceki ay', $gecenYilAy => 'geçen yılın aynı ayı'] as $ay => $ad) {
        if (!isset($ayGore[$ay])) {
            return $bos + ['hata' => 'Karşılaştırma için ' . $ad . ' (' . $ay
                                   . ') endeksi gelmedi; hesap yapılmadı.',
                           'tekrar' => false];
        }
    }

    $son      = $ayGore[$sonAy];
    $onceki   = $ayGore[$oncekiAy];
    $gecenYil = $ayGore[$gecenYilAy];

    if ($onceki['deger'] <= 0.0 || $gecenYil['deger'] <= 0.0) {
        return $bos + ['hata' => 'Karşılaştırma endeksi sıfır ya da eksi; hesaplanamaz.'];
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
        'seri'  => ekonomi_yillik_degisim_serisi($ayGore, 24),
    ];
}

/**
 * Her ay için bir yıl önceye göre değişim serisi (grafik için).
 *
 * @param array<string,array{iso:string,deger:float}> $ayGore 'Y-m' => gozlem, sirali
 * @return list<array{0:string,1:float}>
 */
function ekonomi_yillik_degisim_serisi(array $ayGore, int $enFazla): array
{
    return ekonomi_degisim_serisi($ayGore, 12, $enFazla);
}

/**
 * Her ay için N ay önceye göre yüzde değişim serisi.
 *
 * Karsiligi olmayan ay ATLANIYOR, tahmin edilmiyor. Grafikte o ay bos
 * kalir; uydurulmus bir nokta cizmekten iyidir. Karsilik TARIHLE
 * aranir, dizideki yeriyle degil (bkz. ekonomi_tufe_hesapla).
 *
 * @param array<string,array{iso:string,deger:float}> $ayGore 'Y-m' => gozlem, sirali
 * @return list<array{0:string,1:float}>
 */
function ekonomi_degisim_serisi(array $ayGore, int $gecikme, int $enFazla): array
{
    $seri = [];

    foreach ($ayGore as $ay => $gozlem) {
        $karsilik = $ayGore[ekonomi_ay_kaydir((string) $ay, -$gecikme)] ?? null;

        if ($karsilik === null || $karsilik['deger'] <= 0.0) {
            continue;
        }

        $seri[] = [$ay . '-01', round(($gozlem['deger'] / $karsilik['deger'] - 1) * 100, 4)];
    }

    return array_slice($seri, -$enFazla);
}

/**
 * Gözlemleri aya indirger: her ayın SON gözlemi.
 *
 * Gunluk bir seriden (kur gibi) yillik degisim hesaplamak icin her ayin
 * tek bir degeri gerekiyor. "Ay sonu" secildi, ortalama degil: ortalama
 * kaynagin yayimladigi hicbir rakama karsilik gelmez ve onaylayan kisi
 * onu hicbir yerde dogrulayamaz. Aylik serilerde (TUFE) zaten ayda tek
 * gozlem var, deger degismez.
 *
 * @param list<array{iso:string,deger:float}> $gozlemler tarihe gore sirali
 * @return array<string,array{iso:string,deger:float}> 'Y-m' => gozlem
 */
function ekonomi_ay_sonlari(array $gozlemler): array
{
    $ayGore = [];

    foreach ($gozlemler as $gozlem) {
        // Sirali oldugu icin ayni aya ait son yazan kazanir = ay sonu.
        $ayGore[substr($gozlem['iso'], 0, 7)] = $gozlem;
    }

    ksort($ayGore);

    return $ayGore;
}

/** 'Y-m' biçimindeki ayı verilen kadar kaydırır. */
function ekonomi_ay_kaydir(string $ay, int $kac): string
{
    // Ayin ilk gunu uzerinden: 31 Mart'tan bir ay geri gitmek PHP'de
    // 3 Mart verir, 1 Mart'tan gitmek 1 Subat.
    return date('Y-m', (int) strtotime($ay . '-01 ' . ($kac >= 0 ? '+' : '') . $kac . ' months'));
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
    $adres = ekonomi_adres($seri);
    $kunye = ['adres' => $adres, 'kod' => 0, 'boyut' => 0, 'ham' => '',
              'son_url' => '', 'tur' => ''];

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
    /*
     * Yonlendirilen SON adres ve icerik turu da tutuluyor. EVDS eski
     * adresten web arayuzune yonlendirdiginde panel yalnizca istenen
     * adresi gosteriyordu; son adres gorunseydi sorun ilk bakista
     * anlasilirdi.
     */
    $kunye['son_url'] = (string) ($yanit['son_url'] ?? '');
    $kunye['tur']     = (string) ($yanit['tur'] ?? '');
    $kunye['ham']   = ekonomi_kirp((string) ($yanit['govde'] ?? ''), 400);

    if (!$yanit['tamam']) {
        return ['tamam' => false, 'deger' => '', 'donem' => '', 'not' => '',
                'hata'  => (string) $yanit['neden']] + $kunye;
    }

    return ekonomi_cozumle($seri + ['evds_anahtari' => $evdsAnahtari], (string) $yanit['govde']) + $kunye;
}
