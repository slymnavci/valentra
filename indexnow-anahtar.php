<?php
declare(strict_types=1);

/**
 * IndexNow anahtar dosyasi.
 *
 * Bing/Yandex bildirimi alinca bu adresi okuyup gonderilen anahtarla
 * karsilastiriyor (bkz. includes/arama_bildirim.php). Anahtar gizli
 * degil; yalnizca bildirimin bu siteden geldigini kanitliyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/arama_bildirim.php';

$anahtar = indexnow_anahtari();

// /<anahtar>.txt adresinden geliniyor (.htaccess); baska bir .txt adi
// bu dosyaya dusmesin.
if (isset($_GET['k']) && !hash_equals($anahtar, (string) $_GET['k'])) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
echo $anahtar;
