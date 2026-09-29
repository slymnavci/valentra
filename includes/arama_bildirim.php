<?php
declare(strict_types=1);

/**
 * Yeni yayımlanan sayfayı arama motorlarına bildirme.
 *
 *  - IndexNow (Bing, Yandex, Seznam...): yeni adres aninda bildiriliyor;
 *    bu motorlar genelde dakikalar icinde tariyor. Anahtar ayarlarda
 *    uretiliyor ve /indexnow-anahtar.php'den sunuluyor.
 *  - Google: IndexNow'u desteklemiyor ve "dizine eklenmesini iste"nin
 *    API'si yok (Indexing API yalnizca is ilani ve canli yayin icin).
 *    Yapilabilecek tek resmi sey site haritasini Search Console'a
 *    yeniden gondermek; saatte en fazla bir kez yapiliyor.
 *
 * Bildirim sayfa yaniti GONDERILDIKTEN SONRA calisiyor: yonetici
 * "Yayimla"ya bastiginda beklemiyor, dis servis yavas ya da kapaliysa
 * yayin etkilenmiyor. Sonuclar ayarlara yaziliyor ve panelde gorunuyor.
 */

require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/http_ortak.php';
require_once __DIR__ . '/ayarlar.php';

const ARAMA_HARITA_ARALIGI = 3600;   // Google site haritasi en sik saatte bir

/** @var list<string> */
$GLOBALS['arama_bildirim_kuyrugu'] = [];

/**
 * Bir adresi bildirim kuyruguna ekler; ilk cagrida sayfa sonu isi kurulur.
 */
function arama_bildir_kuyruk(string $yol): void
{
    if ($yol === '') {
        return;
    }

    $url = str_starts_with($yol, 'http') ? $yol : site_adresi() . $yol;

    if ($GLOBALS['arama_bildirim_kuyrugu'] === []) {
        register_shutdown_function('arama_bildir_sayfa_sonu');
    }

    $GLOBALS['arama_bildirim_kuyrugu'][] = $url;
}

/** Sayfa gittikten sonra kuyruktakileri bildirir. */
function arama_bildir_sayfa_sonu(): void
{
    $urller = array_values(array_unique($GLOBALS['arama_bildirim_kuyrugu']));

    if ($urller === []) {
        return;
    }

    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        @set_time_limit(60);
        arama_bildir($urller);
    } catch (Throwable $e) {
        error_log('[valentra] arama bildirimi: ' . $e->getMessage());
    }
}

/**
 * @param list<string> $urller
 */
function arama_bildir(array $urller): void
{
    require_once __DIR__ . '/search_console.php';

    ayar_yaz('indexnow_son', (string) json_encode(indexnow_gonder($urller), JSON_UNESCAPED_UNICODE));

    if (gsc_hesap() !== null && time() - (int) ayar_oku('gsc_harita_son_deneme', '0') >= ARAMA_HARITA_ARALIGI) {
        ayar_yaz('gsc_harita_son_deneme', (string) time());
        ayar_yaz('gsc_harita_son', (string) json_encode(gsc_site_haritasi_gonder(), JSON_UNESCAPED_UNICODE));
    }
}

/** IndexNow anahtari (ilk kullanimda uretilir). */
function indexnow_anahtari(): string
{
    $anahtar = ayar_oku('indexnow_anahtari');

    if ($anahtar === '') {
        $anahtar = bin2hex(random_bytes(16));
        ayar_yaz('indexnow_anahtari', $anahtar);
    }

    return $anahtar;
}

/**
 * @param list<string> $urller
 * @return array{zaman:string,tamam:bool,kod:int,adet:int,hata:string}
 */
function indexnow_gonder(array $urller): array
{
    $host = (string) parse_url(site_adresi(), PHP_URL_HOST);
    $sonuc = ['zaman' => date('Y-m-d H:i:s'), 'tamam' => false, 'kod' => 0, 'adet' => count($urller), 'hata' => ''];

    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, http_ortak_secenekler(10));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'host'        => $host,
        'key'         => indexnow_anahtari(),
        'keyLocation' => site_adresi() . '/indexnow-anahtar.php',
        'urlList'     => $urller,
    ], JSON_UNESCAPED_SLASHES));

    $yanit = curl_exec($ch);
    $sonuc['kod'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($yanit === false) {
        $sonuc['hata'] = http_hata_acikla(curl_errno($ch), curl_error($ch));
    }

    curl_close($ch);

    // 200: alindi; 202: alindi, anahtar dogrulanacak.
    $sonuc['tamam'] = in_array($sonuc['kod'], [200, 202], true);

    if (!$sonuc['tamam'] && $sonuc['hata'] === '') {
        $sonuc['hata'] = match ($sonuc['kod']) {
            400 => 'Geçersiz istek.',
            403 => 'Anahtar doğrulanamadı (indexnow-anahtar.php erişilebilir olmalı).',
            422 => 'Adresler bu alan adına ait değil.',
            429 => 'Çok sık istek; biraz sonra yeniden denenecek.',
            default => 'HTTP ' . $sonuc['kod'],
        };
    }

    return $sonuc;
}

/**
 * Yayina alinan kaydin adresini kuyruga ekler (haber, kose, rehber).
 * Hata yayini etkilemesin: bildirim ikincil bir is.
 */
function arama_bildir_kayit(string $tur, int $id): void
{
    try {
        [$tablo, $yol] = match ($tur) {
            'haber'  => ['haberler', 'haber_yolu'],
            'kose'   => ['kose_yazilari', 'kose_yolu'],
            'rehber' => ['rehberler', 'rehber_yolu'],
        };

        $ifade = db()->prepare('SELECT slug FROM ' . $tablo . ' WHERE id = ?');
        $ifade->execute([$id]);
        $slug = $ifade->fetchColumn();

        if (is_string($slug) && $slug !== '' && function_exists($yol)) {
            arama_bildir_kuyruk($yol($slug));
        }
    } catch (Throwable $e) {
        error_log('[valentra] arama bildirimi kuyruga eklenemedi: ' . $e->getMessage());
    }
}

/**
 * Sitenin herkese acik adresleri (IndexNow'a toplu gonderim icin).
 * IndexNow istek basina 10.000 adres kabul ediyor.
 *
 * @return list<string>
 */
function arama_tum_adresler(int $sinir = 5000): array
{
    $yollar = ['/'];

    foreach (['pratik_yolu', 'takvim_yolu', 'araclar_yolu', 'rehberler_yolu', 'kanunlar_yolu', 'kose_liste_yolu', 'rg_yolu'] as $f) {
        if (function_exists($f)) {
            $yollar[] = $f();
        }
    }

    $yollar[] = '/egitim/';

    $sorgular = [
        "SELECT slug FROM rehberler WHERE durum = 'yayinda'" => 'rehber_yolu',
        "SELECT slug FROM kose_yazilari WHERE durum = 'yayinda' ORDER BY gun DESC" => 'kose_yolu',
        "SELECT slug FROM haberler WHERE durum = 'yayinda' ORDER BY yayin_tarihi DESC" => 'haber_yolu',
    ];

    foreach ($sorgular as $sql => $yol) {
        try {
            if (function_exists($yol)) {
                foreach (db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                    $yollar[] = $yol((string) $slug);
                }
            }
        } catch (PDOException $e) {
            // tablo yoksa atla
        }
    }

    $taban = site_adresi();

    return array_slice(array_values(array_unique(array_map(static fn (string $y): string => $taban . $y, $yollar))), 0, $sinir);
}
