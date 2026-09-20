<?php
declare(strict_types=1);

/**
 * Eksik ara sertifikayı bulup zinciri tamamlar.
 *
 * SORUN. mevzuat.gov.tr TLS el sikismasinda yalnizca kendi
 * sertifikasini gonderiyor, onu imzalayan ARA sertifikayi
 * gondermiyor. Tanida sebep tam olarak bu goruldu:
 *
 *   zincir: *.tccb.gov.tr <- GeoTrust TLS RSA CA G1
 *   curl 60 - ara sertifikanin koku bulunamadi
 *
 * Ara sertifikayi imzalayan kok (DigiCert Global Root G2) listemizde
 * ZATEN var; eksik olan zincirin ortasi. Tarayicilar bu bosugu
 * sertifikanin icindeki AIA adresinden indirip kendileri kapatir,
 * curl kapatmaz. Kok listesini guncellemek bu sorunu cozmez — eksik
 * olan kok degil.
 *
 * COZUM. Ayni isi biz yapiyoruz: sunucunun sertifikasindaki AIA
 * adresinden ara sertifika indiriliyor ve listeye ekleniyor.
 *
 * GUVENLIK. Indirilen sertifika koru korune eklenmiyor. Once
 * imzasinin, GUVENDIGIMIZ listedeki bir kok tarafindan atildigi
 * dogrulaniyor (openssl_x509_verify). Dogrulama gecmezse dosya
 * yazilmiyor. Boylece araya giren biri kendi sertifikasini listeye
 * sokamaz: onun sertifikasi bizim kok listemizdeki hicbir kok
 * tarafindan imzalanmis olmaz.
 *
 * Dogrulamayi KAPATMAK bu isin kolay yoluydu ve yapilmadi:
 * CURLOPT_SSL_VERIFYPEER => false siteyi araya giren birinin sahte
 * sertifikasina acik birakirdi.
 */

require_once __DIR__ . '/http_ortak.php';

/**
 * Onarılmış listenin yazılacağı dosya.
 *
 * Tercih edilen yer includes/sertifika; ama paylasimli hostingde o
 * klasor salt okunur olabiliyor ve onarim sessizce basarisiz olurdu.
 * Yazilamiyorsa gecici klasore duseriz — kalici degil, ama calisir ve
 * gerektiginde onarim yeniden calistirilir.
 */
function sertifika_onarim_dosyasi(): string
{
    $tercih = __DIR__ . '/sertifika/ca-bundle-guncel.crt';

    if (is_writable(dirname($tercih)) || is_writable($tercih)) {
        return $tercih;
    }

    return sys_get_temp_dir() . '/valentra-ca-bundle.crt';
}

/** Temel (depoyla gelen) kök listesi. */
function sertifika_temel_liste(): string
{
    return __DIR__ . '/sertifika/ca-bundle.crt';
}

/**
 * Sunucunun sunduğu sertifikayı PEM olarak alır.
 *
 * Dogrulama kapali: dogrulanamadigi icin bakiyoruz zaten. Burada
 * okunan sertifikaya GUVENILMIYOR — yalnizca icindeki AIA adresi
 * okunuyor ve indirilen sey ayrica imza dogrulamasindan geciyor.
 */
function sertifika_sunucudan_al(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_NOBODY         => true,
        CURLOPT_CERTINFO       => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    curl_exec($ch);
    $zincir = curl_getinfo($ch, CURLINFO_CERTINFO);
    curl_close($ch);

    if (!is_array($zincir) || !isset($zincir[0]['Cert'])) {
        return null;
    }

    return (string) $zincir[0]['Cert'];
}

/**
 * Sertifikanın içindeki "bu sertifikayı imzalayan buradan indirilir"
 * adresi (AIA - CA Issuers).
 */
function sertifika_aia_adresi(string $pem): string
{
    $bilgi = @openssl_x509_parse($pem);

    if (!is_array($bilgi)) {
        return '';
    }

    $aia = (string) ($bilgi['extensions']['authorityInfoAccess'] ?? '');

    if ($aia === '') {
        return '';
    }

    if (preg_match('#CA Issuers\s*-\s*URI:\s*(https?://\S+)#i', $aia, $es) !== 1) {
        return '';
    }

    return trim($es[1]);
}

/**
 * İndirilen sertifikayı PEM'e çevirir.
 *
 * AIA adresleri cogu zaman DER (ikili) bicimde .crt dosyasi verir;
 * bazilari zaten PEM gonderir. Ikisi de kabul ediliyor.
 */
function sertifika_pem_yap(string $ham): string
{
    if (str_contains($ham, '-----BEGIN CERTIFICATE-----')) {
        return $ham;
    }

    return "-----BEGIN CERTIFICATE-----\n"
         . chunk_split(base64_encode($ham), 64, "\n")
         . "-----END CERTIFICATE-----\n";
}

/**
 * Ara sertifikayı güvendiğimiz köklere karşı doğrular.
 *
 * Yontem: sertifikanin BELIRTTIGI vereni (issuer) kok listemizde
 * arayip, imzayi o kokun acik anahtariyla dogrulamak. Issuer alani
 * sahte olabilir — ama sahte bir issuer yazan sertifikanin imzasi o
 * kokun anahtariyla dogrulanmaz, yani kontrol yine tutar.
 *
 * @return array{tamam:bool,mesaj:string}
 */
function sertifika_koke_dogrula(string $araPem): array
{
    $ara = @openssl_x509_parse($araPem);

    if (!is_array($ara)) {
        return ['tamam' => false, 'mesaj' => 'İndirilen dosya sertifika değil.'];
    }

    $simdi = time();

    if ($simdi < (int) ($ara['validFrom_time_t'] ?? 0)
        || $simdi > (int) ($ara['validTo_time_t'] ?? 0)) {
        return ['tamam' => false, 'mesaj' => 'Ara sertifikanın süresi geçerli değil.'];
    }

    $liste = @file_get_contents(sertifika_temel_liste());

    if (!is_string($liste) || $liste === '') {
        return ['tamam' => false, 'mesaj' => 'Kök sertifika listesi okunamadı.'];
    }

    $arananVeren = sertifika_ad_metni((array) ($ara['issuer'] ?? []));

    foreach (sertifika_listeyi_ayir($liste) as $kokPem) {
        $kok = @openssl_x509_parse($kokPem);

        if (!is_array($kok)) {
            continue;
        }

        if (sertifika_ad_metni((array) ($kok['subject'] ?? [])) !== $arananVeren) {
            continue;
        }

        $anahtar = @openssl_pkey_get_public($kokPem);

        if ($anahtar === false) {
            continue;
        }

        if (openssl_x509_verify($araPem, $anahtar) === 1) {
            return [
                'tamam' => true,
                'mesaj' => 'Ara sertifika doğrulandı: '
                         . (string) ($ara['subject']['CN'] ?? '?')
                         . ' ← ' . (string) ($kok['subject']['CN'] ?? '?'),
            ];
        }
    }

    return ['tamam' => false, 'mesaj' => 'Ara sertifikayı imzalayan kök '
                                       . 'listemizde bulunamadı (' . $arananVeren . ').'];
}

/**
 * Ayırt edici ad (DN) alanlarını karşılaştırılabilir tek metne çevirir.
 *
 * @param array<string,mixed> $ad
 */
function sertifika_ad_metni(array $ad): string
{
    $parcalar = [];

    foreach ($ad as $alan => $deger) {
        $parcalar[] = $alan . '=' . (is_array($deger) ? implode('+', $deger) : (string) $deger);
    }

    return implode(',', $parcalar);
}

/**
 * Bir listedeki sertifikaları tek tek verir.
 *
 * @return list<string>
 */
function sertifika_listeyi_ayir(string $liste): array
{
    if (preg_match_all(
        '#-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----#s',
        $liste,
        $es
    ) === false) {
        return [];
    }

    return $es[0] ?? [];
}

/**
 * Bir adres için eksik ara sertifikayı bulup listeye ekler.
 *
 * @return array{tamam:bool,mesaj:string}
 */
function sertifika_zinciri_onar(string $url): array
{
    $leaf = sertifika_sunucudan_al($url);

    if ($leaf === null) {
        return ['tamam' => false, 'mesaj' => 'Sunucunun sertifikası alınamadı; '
                                           . 'adrese hiç bağlanılamıyor olabilir.'];
    }

    $aia = sertifika_aia_adresi($leaf);

    if ($aia === '') {
        return ['tamam' => false, 'mesaj' => 'Sertifikada ara sertifika adresi (AIA) yok; '
                                           . 'eksik halka bu yoldan bulunamıyor.'];
    }

    $indirme = http_getir($aia, 20);

    if (!$indirme['tamam']) {
        return ['tamam' => false, 'mesaj' => 'Ara sertifika indirilemedi (' . $aia . '): '
                                           . $indirme['neden']];
    }

    $araPem  = sertifika_pem_yap($indirme['govde']);
    $kontrol = sertifika_koke_dogrula($araPem);

    if (!$kontrol['tamam']) {
        return $kontrol;
    }

    $temel = (string) @file_get_contents(sertifika_temel_liste());
    $mevcut = @file_get_contents(sertifika_onarim_dosyasi());
    $eldeki = is_string($mevcut) && $mevcut !== '' ? $mevcut : $temel;

    // Ayni sertifikayi iki kez eklemeyelim.
    if (str_contains($eldeki, trim($araPem))) {
        return ['tamam' => true, 'mesaj' => $kontrol['mesaj'] . ' (listede zaten vardı)'];
    }

    if (@file_put_contents(sertifika_onarim_dosyasi(), $eldeki . "\n" . trim($araPem) . "\n") === false) {
        return ['tamam' => false, 'mesaj' => 'Dosya yazılamadı; includes/sertifika '
                                           . 'klasörü yazılabilir olmalı.'];
    }

    return ['tamam' => true, 'mesaj' => $kontrol['mesaj'] . ' ve listeye eklendi.'];
}
