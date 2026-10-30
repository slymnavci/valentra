<?php
declare(strict_types=1);

/**
 * Burç yorumu ajanının ucu (bkz. includes/burclar.php, ajan/burc.php).
 *
 *   GET  -> {"bugun": "Y-m-d", "var": true|false}  bugunun yorumlari var mi
 *   POST -> {"gun": "Y-m-d", "yorumlar": [{"burc","baslik","genel","ask","para","saglik","sans_sayisi","sans_rengi"}]}
 *
 * Yorumlar dogrudan yayinda (site sahibinin karari, 28.10.2026: onay yok).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/burclar.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $bugun = burc_bugun();

    ajan_json(200, ['bugun' => $bugun, 'var' => count(burc_gun_yorumlari($bugun)) >= count(BURCLAR)]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !is_array($veri['yorumlar'] ?? null) || count($veri['yorumlar']) > 12) {
    ajan_json(400, ['hata' => 'Gövde {"gun": "Y-m-d", "yorumlar": [...]} biçiminde ve en fazla 12 kayıt olmalı.']);
}

try {
    ajan_json(200, burc_kaydet((string) ($veri['gun'] ?? ''), $veri['yorumlar']));
} catch (InvalidArgumentException $e) {
    ajan_json(400, ['hata' => $e->getMessage()]);
}
