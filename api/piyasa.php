<?php
declare(strict_types=1);

/**
 * Piyasa verisi ucu.
 *
 * Sayfa bunu 2 dakikada bir yokluyor. Veri sunucuda onbellekte durdugu
 * icin kac ziyaretci olursa olsun kaynak siteye 2 dakikada bir tek
 * istek gidiyor.
 *
 * Acik uc: yetki istemez, cunku dondurdugu veri zaten herkese acik
 * piyasa bilgisi. Yazma yapmaz.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/piyasa.php';

header('Content-Type: application/json; charset=utf-8');

// Tarayici da kisa sure tutsun; sekme arkada acik kalirsa gereksiz
// istek atmasin.
header('Cache-Control: public, max-age=60');

$veri = piyasa_verisi();

echo json_encode([
    'usd'          => $veri['usd'],
    'eur'          => $veri['eur'],
    'bist'         => $veri['bist'],
    'bist_degisim' => $veri['bist_degisim'],
    'zaman'        => $veri['zaman'],
    // Hangi saglayicinin verdigi gorunsun: kazima kirildiginda
    // "TCMB" yazmasi sorunu tek bakista anlatiyor.
    'kaynak'       => $veri['kaynak'] ?? '',
], JSON_UNESCAPED_UNICODE);
