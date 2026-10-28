<?php
declare(strict_types=1);

/**
 * Google tam denetimi ucu (bkz. includes/google_denetim.php).
 *
 *   POST -> denetimi calistirir, sonucu JSON dondurur
 *   GET  -> son denetimin sonucunu dondurur (calistirmaz)
 *
 * GitHub'daki "google-denetim" calismasi bunu cagirip sonucu kayda
 * yaziyor; boylece anahtar sitede kalirken sonuc disaridan okunabiliyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/google_denetim.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $son = json_decode(ayar_oku('google_denetim_son'), true);
    ajan_json(200, is_array($son) ? $son : ['durum' => 'hic_calismadi']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

@set_time_limit(150);

try {
    $sonuc = google_denetimi(100);
} catch (Throwable $e) {
    ajan_json(500, ['hata' => 'Denetim çalışamadı: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
}

ajan_json(200, $sonuc);
