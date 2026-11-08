<?php
declare(strict_types=1);

/**
 * Hız ölçümü ucu (08.11.2026; bkz. ajan/hiz_olcum.php).
 *
 * Site sahibinin istegi: "mobil hizi ve kaymalari PageSpeed Insights ve
 * gercek kullanici verileriyle olcmek". Olcumu GitHub makinesi yapiyor
 * (Lighthouse + PageSpeed Insights / CrUX); sonuc buraya yaziliyor ve
 * Google tam denetiminde (includes/google_denetim.php) gorunuyor.
 *
 *   POST {mobil:{...}, masaustu:{...}, alan:{...}}  -> kaydeder
 *   GET                                             -> son olcum
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/google_denetim.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ajan_json(200, hiz_olcum_oku() ?? ['durum' => 'hic_olculmedi']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($veri)) {
    ajan_json(400, ['hata' => 'Gövde JSON değil.']);
}

$temiz = hiz_olcum_temizle($veri);

if ($temiz === null) {
    ajan_json(400, ['hata' => 'Ölçümde mobil sonuç yok.']);
}

ayar_yaz('hiz_olcum_son', (string) json_encode($temiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
ajan_json(200, ['durum' => 'kaydedildi', 'olcum' => $temiz]);
