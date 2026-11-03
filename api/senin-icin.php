<?php
declare(strict_types=1);

/**
 * "Senin icin" bolumu (03.11.2026; bkz. includes/senin_icin.php).
 *
 *   GET /api/senin-icin.php?s=izmir&k=spor,ekonomi
 *
 * Ana sayfa onbellekten geldigi icin kisisel bolum ayrica isteniyor.
 * Acik uc: yanit yalnizca herkese acik haberler; ayni secim iki dakika
 * tarayicida ve vekil sunucularda saklanabiliyor. Bilinmeyen sehir ve
 * konu yok sayiliyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/senin_icin.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/gorsel_onbellek.php';
require_once __DIR__ . '/../includes/etiket.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Robots-Tag: noindex');

$sehir   = strtolower(trim((string) ($_GET['s'] ?? '')));
$konular = array_slice(array_filter(array_map('trim', explode(',', strtolower((string) ($_GET['k'] ?? ''))))), 0, 9);

try {
    $sonuc = senin_icin_haberler($konular, $sehir);
} catch (PDOException $e) {
    http_response_code(503);
    exit;
}

$izgaraHaberleri = array_merge($sonuc['sehir'], $sonuc['konu']);

if ($izgaraHaberleri === []) {
    echo '<p class="senin-bos">Seçtiğiniz şehir ve konularda son günlerde yeni haber yok. Seçimi genişletmeyi deneyin.</p>';
    exit;
}

require __DIR__ . '/../includes/kart_izgara.php';
