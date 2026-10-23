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
/*
 * Veritabani saati Turkiye saati (23.10.2026 denetimi).
 *
 * Kayit zamanlari SQL'de NOW() ile yaziliyor, "bugun" sinirlarini
 * CURDATE() ciziyor; ikisi de baglantinin saat dilimini kullaniyor ve bu
 * hic ayarlanmamisti. Sunucu UTC ise ziyaretler uc saat kayik
 * kaydediliyor, "bugun" gece 03:00'te basliyordu. PHP tarafi zaten
 * Europe/Istanbul; Turkiye 2016'dan beri yaz saati uygulamiyor, yani
 * sabit +03:00 dogru. Eski kayitlarin duzeltmesi includes/sema.php'de.
 */
try {
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    error_log('[valentra] veritabani saat dilimi ayarlanamadi: ' . $e->getMessage());
}

function db(): PDO
{
    global $pdo;

    return $pdo;
}

require_once __DIR__ . '/haberler.php';
require_once __DIR__ . '/adres.php';
require_once __DIR__ . '/gorsel_onbellek.php';

/*
 * Sema dosyasi degistiyse veritabanini kendiliginden yukselt.
 *
 * Onceden bu adim elle yapiliyordu: kod deploy ile gidiyor ama
 * veritabani eski kaliyordu ve fark ancak menu ya da kaynak listesi
 * degismeyince anlasiliyordu. Maliyeti imza tutuyorsa tek kucuk
 * SELECT.
 */
require_once __DIR__ . '/sema_otomatik.php';

sema_otomatik_yukselt();
