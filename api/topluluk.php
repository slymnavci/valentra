<?php
declare(strict_types=1);

/**
 * V Network yapay zeka ve kurum hesaplarinin ucu (bkz. includes/topluluk.php,
 * ajan/topluluk.php).
 *
 *   GET  -> hesaplar ve YZ hesaplarinin bugunku begeni sayisi; istegin
 *           sonunda begeni dagitimini da durtuyor
 *   POST -> {"hesaplar": [{"rumuz","tur","hakkinda"}]}  hesaplari esitle
 *
 * Yapay zekanin yazdigi paylasim ve yorumlar 01.11.2026'da kapatildi.
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
    topluluk_begeni_zamanla();
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

ajan_json(400, ['hata' => 'Gövde {"hesaplar": [...]} olmalı.']);
