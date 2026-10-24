<?php
declare(strict_types=1);

/**
 * Hava durumu ucu: yan penceredeki kartta il değiştirilince sayfa buradan alıyor.
 *
 *   GET /api/hava.php?il=ankara
 *
 * Acik uc: veri zaten herkese acik. Il listesi sabit (HAVA_ILLERI);
 * bilinmeyen il Istanbul'a duser, istenen her koordinat icin kaynaga
 * gidilmez. Veri sunucuda 30 dakika tutuluyor (bkz. includes/hava.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hava.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');

$hava = hava_durumu((string) ($_GET['il'] ?? HAVA_VARSAYILAN_IL));

if ($hava === null) {
    http_response_code(503);
    echo json_encode(['hata' => 'Hava durumu şu an alınamıyor.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hava['simge_svg'] = hava_simge_svg($hava['simge']);

foreach ($hava['gunler'] as $i => $gun) {
    $hava['gunler'][$i]['simge_svg'] = hava_simge_svg($gun['simge']);
}

echo json_encode($hava, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
