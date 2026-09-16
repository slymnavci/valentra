<?php
declare(strict_types=1);

/**
 * Site tarafındaki dış isteklerin ortak ayarları.
 *
 * Tek bir yerde toplandi cunku ayni iki ayrinti her cagri yerinde
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
 * @return array<int,mixed>
 */
function http_ortak_secenekler(int $zamanAsimi = 20, int $baglantiAsimi = 8): array
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
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            'Upgrade-Insecure-Requests: 1',
        ],

        // Bazi paylasimli sunucularda IPv6 yolu calismiyor ve istek
        // zaman asimina kadar bekliyor.
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ];

    $ca = ca_paketi();

    if ($ca !== null) {
        $secenekler[CURLOPT_CAINFO] = $ca;
    }

    return $secenekler;
}

/**
 * Dış bir adresi indirir ve neden başarısız olduğunu da söyler.
 *
 * @return array{tamam:bool,govde:string,kod:int,hata:string,neden:string}
 */
function http_getir(string $url, int $zamanAsimi = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, http_ortak_secenekler($zamanAsimi));

    $govde  = curl_exec($ch);
    $kod    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata   = curl_error($ch);
    $hataNo = curl_errno($ch);
    curl_close($ch);

    if (!is_string($govde)) {
        return [
            'tamam' => false,
            'govde' => '',
            'kod'   => 0,
            'hata'  => $hata,
            'neden' => http_hata_acikla($hataNo, $hata),
        ];
    }

    if ($kod < 200 || $kod >= 300) {
        return [
            'tamam' => false,
            'govde' => '',
            'kod'   => $kod,
            'hata'  => '',
            'neden' => 'Sunucu HTTP ' . $kod . ' döndü.',
        ];
    }

    return ['tamam' => true, 'govde' => $govde, 'kod' => $kod, 'hata' => '', 'neden' => ''];
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
