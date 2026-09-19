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
    $yol = __DIR__ . '/sertifika/ca-bundle.crt';

    return is_readable($yol) ? $yol : null;
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
 * @param resource|CurlHandle $ch
 * @return array{kod:int,tur:string,sure:float,son_url:string}
 */
function http_ayrinti($ch): array
{
    return [
        'kod'     => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'tur'     => strtok((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), ';') ?: '',
        'sure'    => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1),
        'son_url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
}

/**
 * Hata kaynağa değil bağlantıya mı ait?
 *
 * Alan adi cozulemiyor ya da sunucuya hic ulasilamiyorsa AYNI sunucudaki
 * baska adresleri denemenin anlami yok; her biri ayni sureyi harcayip
 * ayni sekilde dusecek. Bu ayrimi yapmadigimizda tek bir sayfa acilisi
 * bes ayri zaman asimini arka arkaya bekliyordu.
 */
function http_baglanti_hatasi_mi(int $hataNo): bool
{
    // 5/6 ad cozumleme, 7 baglanti, 28 sure, 35/51/60/77 sertifika,
    // 52/55/56 aktarim ortasinda kopma — hepsi yolun kendisine degil
    // sunucuya ulasamamaya isaret eder.
    return in_array($hataNo, [5, 6, 7, 28, 35, 51, 52, 55, 56, 60, 77], true);
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

    $temel = $ayrinti + ['hata_no' => $hataNo, 'boyut' => strlen($tampon)];

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
