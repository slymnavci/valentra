<?php
declare(strict_types=1);

/**
 * SITE HARITASI ONBELLEGI (20.11.2026).
 *
 * Google denetimi: haber ajani calisirken sunucu yavasliyor, sitemap.php
 * zaman asimina ugrayip bos yanit veriyordu ("Gecerli XML degil"); Google
 * tam o anda okursa haritayi bos gorur. Site haritasi artik dosyadan:
 *
 *   - HARITA_TAZE saniyeden yeni kopya dogrudan sunulur (veritabanina gidilmez);
 *   - daha eski ama HARITA_EN_ESKI'den yeni kopya HEMEN sunulur, yenisi yanit
 *     gonderildikten sonra uretilir (ayni anda tek uretim: kilit);
 *   - kopya yoksa ya da cok eskiyse istek beklenerek uretilir ve kaydedilir.
 *
 * Kopya yalnizca tamamlanmis XML ise yazilir (</urlset> ile biter): yarida
 * kalan uretim iyi kopyanin ustune yazilmaz. Sitenin sayfa onbellegi
 * (includes/sayfa_onbellek.php) HTML icin; sayfa sonuna betik ekledigi icin
 * XML'de kullanilmiyor.
 */

const HARITA_TAZE    = 900;
const HARITA_EN_ESKI = 86400;

function harita_onbellek_dosyasi(string $ad): string
{
    return dirname(__DIR__) . '/onbellek/harita/' . preg_replace('/[^a-z0-9-]/', '', $ad) . '.xml';
}

/**
 * Basliklar gonderildikten SONRA, uretimden once cagrilir. Taze kopya varsa
 * onu sunup cikar; bayat kopyada onu sunar ve uretime arka planda devam eder.
 */
function harita_onbellek_basla(string $ad): void
{
    if (PHP_SAPI === 'cli' || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        return;
    }

    $dosya = harita_onbellek_dosyasi($ad);
    $zaman = @filemtime($dosya);
    $yas   = $zaman !== false ? time() - $zaman : PHP_INT_MAX;

    if ($yas < HARITA_TAZE) {
        header('X-Harita: taze');
        readfile($dosya);
        exit;
    }

    $sessiz = false;

    if ($yas < HARITA_EN_ESKI) {
        // Ayni anda tek uretim; kilit alinamazsa baskasi uretiyor: bayat kopyayi sunup cik.
        $kilit = @fopen($dosya . '.kilit', 'c');
        $uret  = $kilit !== false && flock($kilit, LOCK_EX | LOCK_NB);

        header('X-Harita: bayat');
        readfile($dosya);

        if (!$uret) {
            exit;
        }

        $GLOBALS['harita_kilit'] = $kilit;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            flush();
        }

        ignore_user_abort(true);
        @set_time_limit(120);
        $sessiz = true;
    } else {
        header('X-Harita: uretildi');
    }

    ob_start();

    register_shutdown_function(static function () use ($dosya, $sessiz): void {
        $xml = (string) ob_get_contents();

        // Yalnizca tamamlanmis harita kaydedilir.
        if (str_starts_with(ltrim($xml), '<?xml') && str_ends_with(rtrim($xml), '</urlset>')) {
            if (!is_dir(dirname($dosya))) {
                @mkdir(dirname($dosya), 0775, true);
            }

            if (@file_put_contents($dosya . '.tmp', $xml, LOCK_EX) !== false) {
                @rename($dosya . '.tmp', $dosya);
            }
        }

        if ($sessiz) {
            ob_end_clean();
        } else {
            ob_end_flush();
        }

        if (isset($GLOBALS['harita_kilit']) && is_resource($GLOBALS['harita_kilit'])) {
            flock($GLOBALS['harita_kilit'], LOCK_UN);
            fclose($GLOBALS['harita_kilit']);
        }
    });
}
