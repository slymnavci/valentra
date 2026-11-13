<?php
declare(strict_types=1);

/**
 * Buyuk yayinlarin gundemindeki basliklar (13.11.2026; bkz.
 * includes/manset_sinyal.php).
 *
 *   POST /api/manset-sinyal.php
 *   {"sinyaller": [{"baslik": "...", "yayin": "hurriyet.com.tr", "manset": false, "zaman": 1794590000}]}
 *
 * Site her haberin gundem puanini bu basliklardan sayar (yeni gelenlere
 * teslimde, yayindakilere burada).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/manset_sinyal.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

if (ajan_anahtar_dogrula($neden) === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);
$liste = is_array($veri) ? ($veri['sinyaller'] ?? null) : null;

if (!is_array($liste)) {
    ajan_json(400, ['hata' => 'Gövde {"sinyaller": [{"baslik": "...", "yayin": "ntv.com.tr"}]} biçiminde olmalı.']);
}

try {
    $sonuc = manset_sinyal_yaz($liste);
} catch (Throwable $e) {
    error_log('[valentra] manset sinyali: ' . $e->getMessage());
    ajan_json(500, ['hata' => 'Sinyaller kaydedilemedi.']);
}

ajan_json(200, ['durum' => 'tamam'] + $sonuc);
