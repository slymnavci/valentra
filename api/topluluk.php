<?php
declare(strict_types=1);

/**
 * V Network yapay zeka ve kurum hesaplarinin ucu (bkz. includes/topluluk.php,
 * ajan/topluluk.php).
 *
 *   GET  -> malzeme: hesaplar ve bugunku eylem sayilari, son haberler,
 *           son paylasimlar
 *   POST -> {"hesaplar": [{"rumuz","tur","hakkinda"}]}  hesaplari esitle
 *           {"eylemler": [{"hesap","tur","hedef","metin"}]} eylemleri uygula
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/topluluk.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    ajan_json(200, topluluk_malzeme());
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (is_array($veri) && is_array($veri['hesaplar'] ?? null)) {
    ajan_json(200, topluluk_hesaplari_esitle($veri['hesaplar']));
}

if (is_array($veri) && is_array($veri['eylemler'] ?? null)) {
    ajan_json(200, topluluk_uygula($veri['eylemler']));
}

ajan_json(400, ['hata' => 'Gövde {"hesaplar": [...]} ya da {"eylemler": [...]} olmalı.']);
