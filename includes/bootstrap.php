<?php
declare(strict_types=1);

/**
 * Her istegin basinda cagrilir: veritabani baglantisi, oturum ve yardimcilar.
 *
 * includes/database.php dosyasi repoda YOKTUR; deploy sirasinda GitHub
 * Secrets'tan uretilir ve $pdo degiskenini tanimlar. Yerel gelistirmede
 * ayni icerige sahip includes/database.local.php kullanilir.
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Istanbul');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hata.php';

$ayarDosyasi = __DIR__ . '/database.php';
if (!is_file($ayarDosyasi)) {
    $ayarDosyasi = __DIR__ . '/database.local.php';
}

if (!is_file($ayarDosyasi)) {
    http_response_code(503);
    exit('Veritabani ayar dosyasi bulunamadi.');
}

require_once $ayarDosyasi;

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(503);
    exit('Veritabani baglantisi kurulamadi.');
}

/**
 * Paylasilan PDO baglantisi.
 */
function db(): PDO
{
    global $pdo;

    return $pdo;
}

require_once __DIR__ . '/haberler.php';
