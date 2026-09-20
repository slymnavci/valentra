<?php
declare(strict_types=1);

/**
 * Site tarafındaki dış isteklerin ortak ayarları.
 *
 * Tek bir yerde toplandi cunku ayni ayrintilar her cagri yerinde
 * tekrarlaniyordu ve biri unutuldugunda sorun sessizce geri geliyordu.
 */

/**
 * Güncel kök sertifika listesinin yolu.
 *
 * Paylasimli hostinglerin kok sertifika listesi cogu zaman eski oluyor
 * ve gecerli sertifikalar dogrulanamiyor. Panelden yapilan kaynak
 * testinde bunu birkac kaynakta gorduk ("Guvenlik sertifikasi
 * dogrulanamadi"), kanun metni de ayni nedenle gelmemis olabilir.
 *
 * Cozum listeyi curl'e acikca gostermek. Dogrulamayi KAPATMAK degil:
 * CURLOPT_SSL_VERIFYPEER => false siteyi araya giren birinin sahte
 * sertifikasina acik birakirdi.
 *
 * Dosya yoksa null doner ve sistemin kendi listesi kullanilir.
 */
function ca_paketi(): ?string
{
    foreach (ca_paketi_yollari() as $yol) {
        if (is_readable($yol)) {
            return $yol;
        }
    }

    return null;
}

/**
 * Kök sertifika listesinin aranacağı yerler, öncelik sırasıyla.
 *
 * Sunucuda guncellenen liste depodakinden ONCE geliyor. Sebebi su:
 * depodaki liste deploy ile sabitleniyor ve bir CA eksik kaldiginda
 * duzeltmek yeni bir deploy gerektiriyor; oysa sunucu disariya
 * cikabiliyor ve listeyi kendisi tazeleyebiliyor. Guncel dosya depoda
 * yok, deploy onu silmiyor (mirror uzaktaki fazla dosyalari
 * kaldirmiyor), yani bir kez indirildiginde kaliyor.
 *
 * @return list<string>
 */
function ca_paketi_yollari(): array
{
    return [
        __DIR__ . '/sertifika/ca-bundle-guncel.crt',
        // Paylasimli hostingde includes/sertifika salt okunur olabilir;
        // o durumda tamamlanmis liste gecici klasore yaziliyor. Gecici
        // klasor temizlenirse liste kaybolur ve onarim yeniden
        // calistirilir — kalicilik garantisi yok ama calisiyor.
        sys_get_temp_dir() . '/valentra-ca-bundle.crt',
        __DIR__ . '/sertifika/ca-bundle.crt',
    ];
}

/**
 * Kök sertifika listesini kaynağından indirip sunucuya yazar.
 *
 * Depodaki liste 2024 surumu ve icinde TEK BIR Turk kok sertifikasi
 * yok; mevzuat.gov.tr gibi .gov.tr siteleri devlet CA'lari kullandigi
 * icin bu liste onlari dogrulayamiyor. Listeyi tazelemek bu sinifin
 * hatalarinin bir kismini dogrudan cozuyor.
 *
 * Indirme mevcut listeyle DOGRULANARAK yapiliyor: curl.se yaygin bir
 * CA kullaniyor ve o zaten listede. Dogrulamayi kapatip kok sertifika
 * listesi indirmek, guvenmek icin indirdigimiz seyi guvensiz yoldan
 * almak olurdu.
 *
 * Gelen icerik bicim olarak da sinaniyor: sertifika sayisi ve boyut
 * beklenen araligin disindaysa yazilmiyor. Boylece araya giren bir
 * hata sayfasi listenin yerine gecemiyor.
 *
 * @return array{tamam:bool,mesaj:string}
 */
function ca_paketi_guncelle(): array
{
    $kaynak = 'https://curl.se/ca/cacert.pem';
    $yanit  = http_getir($kaynak, 40);

    if (!$yanit['tamam']) {
        return ['tamam' => false, 'mesaj' => 'İndirilemedi: ' . $yanit['neden']];
    }

    $govde = $yanit['govde'];
    $adet  = substr_count($govde, 'BEGIN CERTIFICATE');

    if ($adet < 100 || strlen($govde) < 100000) {
        return ['tamam' => false, 'mesaj' => 'Gelen dosya kök sertifika listesine '
                                           . 'benzemiyor (' . $adet . ' sertifika, '
                                           . strlen($govde) . ' bayt).'];
    }

    $hedef = __DIR__ . '/sertifika/ca-bundle-guncel.crt';

    if (@file_put_contents($hedef, $govde) === false) {
        return ['tamam' => false, 'mesaj' => 'Dosya yazılamadı; '
                                           . 'includes/sertifika klasörü yazılabilir olmalı.'];
    }

    return ['tamam' => true, 'mesaj' => 'Liste güncellendi: ' . $adet . ' sertifika.'];
}

/**
 * Dış istekler için ortak curl seçenekleri.
 *
 * @param string $referer Bos degilse Referer basligi eklenir. Bazi kamu
 *                        sunuculari dogrudan cagrilan duragan dosyalara
 *                        (PDF, DOC) Referer olmadan 403 donuyor.
 * @return array<int,mixed>
 */
function http_ortak_secenekler(int $zamanAsimi = 20, int $baglantiAsimi = 8, string $referer = ''): array
{
    $secenekler = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $zamanAsimi,
        CURLOPT_CONNECTTIMEOUT => $baglantiAsimi,
        CURLOPT_ENCODING       => '',

        /*
         * Tarayici gibi tanit.
         *
         * Kamu ve kurum siteleri bilinmeyen istemcileri siklikla
         * reddediyor. "ValentraBot" ile gelen istekler bos donuyordu.
         */
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                . 'Chrome/128.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/pdf,'
                . 'application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            'Upgrade-Insecure-Requests: 1',
        ],

        // Bazi paylasimli sunucularda IPv6 yolu calismiyor ve istek
        // zaman asimina kadar bekliyor.
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,

        /*
         * HTTP/1.1'de kal.
         *
         * Eski libcurl + yeni sunucu birlesiminde HTTP/2 akisi yarida
         * kesilip bos govde donebiliyor; kamu sunucularinin onundeki
         * guvenlik duvarlari da HTTP/2'yi her zaman dogru gecirmiyor.
         * Burada indirilen dosyalar kucuk, HTTP/2'nin kazandiracagi
         * bir sey yok.
         */
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,

        /*
         * Sunucunun gonderdigi sertifika zinciri kayda alinsin.
         *
         * "Guvenlik sertifikasi dogrulanamadi" tek basina hicbir sey
         * soylemiyor: kok listemiz sunucuya hic gitmemis de olabilir,
         * kaynak ara sertifikayi eksik gonderiyor da olabilir, kok
         * gercekten taninmiyor da olabilir. Ucu de bambaska islere
         * bakar. Zinciri kaydetmenin maliyeti yok.
         */
        CURLOPT_CERTINFO       => true,
    ];

    if ($referer !== '') {
        $secenekler[CURLOPT_REFERER] = $referer;
    }

    $ca = ca_paketi();

    if ($ca !== null) {
        $secenekler[CURLOPT_CAINFO] = $ca;
    }

    return $secenekler;
}

/**
 * Bir isteğin ölçülebilir ayrıntıları.
 *
 * Tani ekraninda "neden olmadi" sorusunu cevaplayan sey bu alanlar:
 * gelen icerik turu HTML ise kaynak hata sayfasi dondurmus, sure
 * zaman asimina yakinsa yavaslik var, son adres farkliysa yonlendirme
 * baska yere gitmis demektir.
 *
 * "baglandi" ayri tutuluyor: zaman asimi, baglanti HIC kurulamadigi icin
 * mi yoksa kurulduktan sonra aktarim uzadigi icin mi olustu — ikisi
 * bambaska seyler ve asagidaki karar buna dayaniyor.
 *
 * @param resource|CurlHandle $ch
 * @return array{kod:int,tur:string,sure:float,son_url:string,baglandi:bool,
 *               dogrulama:int,ca:string}
 */
function http_ayrinti($ch): array
{
    return [
        'kod'       => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'tur'       => strtok((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), ';') ?: '',
        'sure'      => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1),
        'son_url'   => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'baglandi'  => ((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME)) > 0.0,
        'dogrulama' => (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT),
        'ca'        => (string) (ca_paketi() ?? ''),
    ];
}

/**
 * Kök sertifika listesinin durumu, tek cümlede.
 *
 * Ilk sorulacak soru bu: liste sunucuda var mi? Yoksa curl sistemin
 * kendi listesini kullanir ve paylasimli hostinglerde o liste cogu
 * zaman eskidir — yani dosya deploy sirasinda gitmediyse sertifika
 * hatalari, biz listeyi repoya koymus olsak bile aynen devam eder.
 */
function ca_paketi_durumu(): string
{
    $yol = ca_paketi();

    if ($yol === null) {
        return 'Kök sertifika listesi sunucuda YOK; sistemin kendi listesi '
             . 'kullanılıyor ve paylaşımlı hostinglerde o liste çoğu zaman eski.';
    }

    $govde = (string) @file_get_contents($yol);
    $adet  = substr_count($govde, 'BEGIN CERTIFICATE');

    /*
     * Onarilmis dosya kullaniliyorsa bunu soylemek onemli: eksik ara
     * sertifikalar oraya eklenir ve depodaki temel liste degismez.
     */
    $onarik = basename($yol) === 'ca-bundle-guncel.crt';

    return 'Kök sertifika listesi: ' . basename($yol) . ', ' . $adet
         . ' sertifika, ' . number_format(strlen($govde) / 1024, 0) . ' KB'
         . ($onarik ? ' (sunucuda tamamlanmış liste).' : ' (depoyla gelen liste).');
}

/**
 * TLS kurulumunun panelde gösterilecek tam durumu.
 *
 * curl'un resmi rehberi (https://curl.se/docs/sslcerts.html) sertifika
 * dogrulamasinin uc seye bagli oldugunu soyluyor: kok listesinin YERI
 * (CURLOPT_CAINFO / CURLOPT_CAPATH), o dosyanin OKUNABILIR olmasi ve
 * zincirin listedeki bir koke kadar TAMAMLANABILMESI. "Sertifika
 * dogrulanamadi" hatasi bu ucunden hangisinde takildigini soylemiyor,
 * o yuzden ucu de ayri ayri raporlaniyor.
 *
 * Klasorun yazilabilirligi de burada: eksik ara sertifika oraya
 * yaziliyor ve paylasimli hostingde klasor salt okunur olabiliyor —
 * o durumda onarim sessizce basarisiz olurdu.
 *
 * @return array<string,string>
 */
function http_ssl_durumu(): array
{
    $surum  = curl_version();
    $klasor = __DIR__ . '/sertifika';
    $yol    = ca_paketi();

    $durum = [
        'curl'       => (string) ($surum['version'] ?? '?'),
        'ssl'        => (string) ($surum['ssl_version'] ?? '?'),
        'ca_yolu'    => $yol ?? '(yok — sistemin kendi listesi kullanılıyor)',
        'klasor'     => $klasor,
        'yazilabilir' => is_writable($klasor) ? 'evet' : 'HAYIR',
    ];

    if ($yol === null) {
        $durum['dosya'] = 'Liste bulunamadı.';

        return $durum;
    }

    $govde = (string) @file_get_contents($yol);

    $durum['dosya'] = sprintf(
        'okunabilir: %s · %d sertifika · %s KB',
        is_readable($yol) ? 'evet' : 'HAYIR',
        substr_count($govde, 'BEGIN CERTIFICATE'),
        number_format(strlen($govde) / 1024, 0)
    );

    return $durum;
}

/**
 * OpenSSL doğrulama sonucunu açıklar.
 *
 * Sayilar OpenSSL'in X509 dogrulama kodlari. Hangi isin yapilmasi
 * gerektigini ayiran sey bu kod: 20/21 kaynagin ara sertifikayi eksik
 * gonderdigine, 2/24 kokun listemizde olmadigina, 10 ise sertifikanin
 * suresinin dolduguna isaret eder.
 */
function http_dogrulama_acikla(int $kod): string
{
    return match ($kod) {
        0  => '',
        2  => 'zincirdeki üst sertifika bulunamadı',
        10 => 'sertifikanın süresi dolmuş',
        18 => 'sertifika kendinden imzalı',
        19 => 'zincirde kendinden imzalı sertifika var',
        20 => 'ara sertifikanın kökü bulunamadı (kök listede yok)',
        21 => 'ilk sertifika doğrulanamadı (kaynak ara sertifikayı göndermiyor)',
        24 => 'kök sertifika geçersiz',
        default => 'OpenSSL doğrulama kodu ' . $kod,
    };
}

/**
 * Hata tek bir adrese değil, sunucunun tamamına mı ait?
 *
 * Sunucuya hic ulasilamiyorsa AYNI sunucudaki baska adresleri denemenin
 * anlami yok; her biri ayni sureyi harcayip ayni sekilde dusecek. Bu
 * ayrimi yapmadigimizda tek bir sayfa acilisi bes ayri zaman asimini
 * arka arkaya bekliyordu.
 *
 * Olcut BAGLANTININ KURULUP KURULMADIGI. Baglanti kurulduktan sonra
 * olusan hatalar (aktarim ortasinda kopma, uzayan bir PDF uretimi)
 * istenen adrese ozel olabilir; onlar yuzunden sunucunun tamamini
 * elemek, tam da bu degisiklikle eklenen yedek adresleri bosa
 * cikarirdi.
 *
 * Kapali bir guvenlik duvari paketi cogu zaman sessizce dusurur, yani
 * "baglanti kurulamadi" degil "sure doldu" goruluruz; bu yuzden zaman
 * asimi yalnizca hic baglanilamadiysa sunucu capinda sayiliyor.
 *
 *   5  vekil sunucu cozumlenemedi
 *   6  alan adi cozumlenemedi
 *   7  baglanti kurulamadi
 *   28 sure doldu (yalnizca baglanti hic kurulmadiysa)
 *   35/51/60/77 TLS el sikismasi ya da sertifika dogrulamasi
 */
function http_baglanti_hatasi_mi(int $hataNo, bool $baglandi = true): bool
{
    if ($hataNo === 28) {
        return !$baglandi;
    }

    return in_array($hataNo, [5, 6, 7, 35, 51, 60, 77], true);
}

/**
 * Dış bir adresi indirir ve neden başarısız olduğunu da söyler.
 *
 * @return array{tamam:bool,govde:string,kod:int,hata:string,neden:string,
 *               hata_no:int,tur:string,sure:float,son_url:string,boyut:int}
 */
function http_getir(string $url, int $zamanAsimi = 20, string $referer = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, http_ortak_secenekler($zamanAsimi, 8, $referer));

    $govde   = curl_exec($ch);
    $hata    = curl_error($ch);
    $hataNo  = curl_errno($ch);
    $ayrinti = http_ayrinti($ch);
    curl_close($ch);

    $temel = $ayrinti + ['hata_no' => $hataNo, 'hata' => $hata];

    if (!is_string($govde)) {
        return ['tamam' => false, 'govde' => '', 'boyut' => 0,
                'neden' => http_hata_acikla($hataNo, $hata)] + $temel;
    }

    $temel['boyut'] = strlen($govde);

    if ($ayrinti['kod'] < 200 || $ayrinti['kod'] >= 300) {
        return ['tamam' => false, 'govde' => '',
                'neden' => 'Sunucu HTTP ' . $ayrinti['kod'] . ' döndü.'] + $temel;
    }

    return ['tamam' => true, 'govde' => $govde, 'neden' => ''] + $temel;
}

/**
 * curl hata numarasını anlaşılır bir cümleye çevirir.
 *
 * Numaralar SAYI olarak yaziliyor, CURLE_* sabitleriyle degil.
 * Sabitlerin hepsi her PHP yapisinda tanimli degil; ornegin
 * CURLE_PEER_FAILED_VERIFICATION bu ortamda YOK. Tanimsiz bir sabite
 * atifta bulunmak bu fonksiyonu her cagrildiginda olumcul hataya
 * dusururdu — yani tam da bir baglanti hatasini acikladigi anda.
 *
 * Numaralar libcurl'de sabittir ve degismez:
 *   6  alan adi cozumlenemedi
 *   7  baglanti kurulamadi
 *   28 zaman asimi
 *   35 SSL el sikismasi basarisiz
 *   47 cok fazla yonlendirme
 *   51 sunucu sertifikasi/parmak izi dogrulanamadi
 *   60 sertifika zinciri dogrulanamadi (kok liste eski)
 *   77 kok sertifika dosyasi okunamadi
 */
function http_hata_acikla(int $hataNo, string $hata): string
{
    return match ($hataNo) {
        28 => 'Süre doldu; kaynak yanıt vermedi.',
        6  => 'Alan adı çözümlenemedi.',
        7  => 'Bağlantı kurulamadı; kaynak sunucumuzu engelliyor olabilir.',
        35, 51, 60, 77 => 'Güvenlik sertifikası doğrulanamadı.',
        47 => 'Çok fazla yönlendirme.',
        default => $hata !== '' ? $hata : 'Bilinmeyen bağlantı hatası (curl ' . $hataNo . ').',
    };
}

/**
 * Adresin ilk birkaç baytını indirir.
 *
 * Buyuk bir dosyanin (ornegin bir kanun PDF'inin) orada olup olmadigini
 * anlamak icin tamamini indirmek gereksiz: bastaki dosya imzasina
 * bakmak yetiyor.
 *
 * Aktarim bu yuzden yeterli bayt gelir gelmez KESILIYOR. Onceki surum
 * bunu Range ("0-1023") basligiyla istiyordu; iki sorunu vardi:
 * sunucunun onundeki guvenlik duvarlari parcali istegi reddedebiliyor,
 * ve sunucu Range'i yok sayip dosyanin tamamini gondermeye basladiginda
 * megabaytlik PDF bastan sona indirilip 15 saniyelik sureye takiliyor —
 * yani "dosya var mi" sorusu "sure doldu" diye cevapliyordu. Aktarimi
 * biz kestigimizde sunucunun ne destekledigi onemli olmaktan cikiyor.
 *
 * HEAD istegi de kullanilmadi: bazi sunucular HEAD'e 405 donuyor ve
 * govde olmadigi icin dosya imzasini dogrulama sansi kalmiyor.
 *
 * @return array{tamam:bool,govde:string,kod:int,neden:string,
 *               hata_no:int,tur:string,sure:float,son_url:string,boyut:int}
 */
function http_bas_getir(string $url, int $bayt = 1024, int $zamanAsimi = 15, string $referer = ''): array
{
    $bayt   = max(1, $bayt);
    $tampon = '';

    $ch = curl_init($url);
    curl_setopt_array($ch, http_ortak_secenekler($zamanAsimi, 8, $referer));

    // Sikistirilmis aktarim bastaki dosya imzasini gizler.
    curl_setopt($ch, CURLOPT_ENCODING, 'identity');
    curl_setopt(
        $ch,
        CURLOPT_WRITEFUNCTION,
        static function ($islem, string $parca) use (&$tampon, $bayt): int {
            $tampon .= $parca;

            // Parca uzunlugundan farkli bir deger dondurmek curl'e
            // "yeter, kes" demektir; geriye 23 numarali yazma hatasi
            // kalir ve bunu asagida basari sayiyoruz.
            return strlen($tampon) >= $bayt ? 0 : strlen($parca);
        }
    );

    curl_exec($ch);
    $hata    = curl_error($ch);
    $hataNo  = curl_errno($ch);
    $ayrinti = http_ayrinti($ch);
    curl_close($ch);

    // Ham curl mesaji da tasiniyor: cevrilmis cumle "ne oldu"yu
    // soyluyor, ham mesaj "tam olarak neresi"ni soyluyor ve tani
    // ekraninda ikisi birden gerekiyor.
    $temel = $ayrinti + ['hata_no' => $hataNo, 'hata' => $hata,
                         'boyut' => strlen($tampon)];

    // 23: aktarimi biz kestik. Elimizde veri varsa bu bir hata degil.
    $kesildi = $hataNo === 23 && $tampon !== '';

    if ($tampon === '' || ($hataNo !== 0 && !$kesildi)) {
        return ['tamam' => false, 'govde' => '',
                'neden' => $hataNo !== 0
                    ? http_hata_acikla($hataNo, $hata)
                    : 'Sunucu HTTP ' . $ayrinti['kod'] . ' döndü.'] + $temel;
    }

    /*
     * 206 parcali yanit, 200 ise sunucu dosyanin tamamini gondermeye
     * baslamis demektir; ikisi de olur. Aktarimi kestigimiz durumda
     * kod bazen 0 kalir, o zaman elimizdeki veri belirleyici.
     */
    if ($ayrinti['kod'] !== 0 && $ayrinti['kod'] !== 200 && $ayrinti['kod'] !== 206) {
        return ['tamam' => false, 'govde' => '', 'kod' => $ayrinti['kod'],
                'neden' => 'Sunucu HTTP ' . $ayrinti['kod'] . ' döndü.'] + $temel;
    }

    return ['tamam' => true, 'govde' => $tampon, 'neden' => ''] + $temel;
}

/**
 * Sunucunun sunduğu sertifika zincirini YALNIZCA tanı için okur.
 *
 * Bu fonksiyon dogrulamayi kapatir. Bunun tek sebebi sorunun sebebini
 * gorebilmek: dogrulama basarisiz oldugunda curl zinciri vermez, yani
 * "hangi kurum imzalamis, ara sertifika gelmis mi" sorusunu ancak
 * dogrulamadan baglanarak cevaplayabiliyoruz.
 *
 * Donen govde KULLANILMAZ ve kullanilmamalidir: burada okunan hicbir
 * sey siteye yazilmaz, ziyaretciye gosterilmez, onbellege girmez.
 * Yalnizca sertifikanin kim tarafindan verildigi bilgisi doner. Icerik
 * cekmek icin her zaman http_getir / http_bas_getir kullanilir; onlar
 * dogrulamayi acik tutar.
 *
 * @return list<string> Zincirdeki her sertifika icin "konu ← veren".
 */
function http_sertifika_zinciri(string $url, int $zamanAsimi = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $zamanAsimi,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CERTINFO       => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    curl_exec($ch);
    $zincir = curl_getinfo($ch, CURLINFO_CERTINFO);
    curl_close($ch);

    if (!is_array($zincir) || $zincir === []) {
        return [];
    }

    $satirlar = [];

    foreach ($zincir as $sertifika) {
        $konu  = (string) ($sertifika['Subject'] ?? '');
        $veren = (string) ($sertifika['Issuer'] ?? '');

        $satirlar[] = http_ad_kisalt($konu) . ' ← ' . http_ad_kisalt($veren);
    }

    return $satirlar;
}

/**
 * X.509 ad dizisinden yalnızca okunabilir kısmı alır.
 *
 * Ham ad "C=TR, O=..., CN=..." seklinde uzun; tanida isimize yarayan
 * CN (ya da yoksa O) alani.
 */
function http_ad_kisalt(string $ad): string
{
    foreach (['CN', 'O'] as $alan) {
        if (preg_match('/(?:^|,)\s*' . $alan . '\s*=\s*([^,]+)/', $ad, $es) === 1) {
            return trim($es[1]);
        }
    }

    return $ad === '' ? '?' : $ad;
}
