<?php
declare(strict_types=1);

/**
 * Haber gorseli: ilk istekte kaynaktan indirip onbellege alir.
 *
 * Sablonlar onbellekte olmayan gorsel icin buraya baglanir (bkz.
 * includes/gorsel_onbellek.php). Adres disaridan alinmiyor; yalnizca
 * haberin KIMLIGI geliyor ve indirilen adres veritabanindaki kayit.
 * Boylece bu uc baska adresleri indirmek icin kullanilamaz.
 *
 * Basarida yerel dosyaya, basarisizlikta kaynagin kendi adresine
 * yonlendirir; tarayici gorseli yine dener (referrer gondermeden).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/gorsel_onbellek.php';

header('X-Robots-Tag: noindex');

$id    = (int) ($_GET['id'] ?? 0);
$haber = $id > 0 ? haber_bul($id) : null;
$url   = guvenli_url((string) ($haber['gorsel_url'] ?? ''));

if ($url === '') {
    http_response_code(404);
    exit;
}

$yerel = haber_gorsel_indir($url);

if ($yerel !== '') {
    header('Cache-Control: public, max-age=86400');
    header('Location: ' . $yerel, true, 302);
    exit;
}

// Gecici bir hata olabilir: bu yonlendirme onbellege alinmasin.
header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
