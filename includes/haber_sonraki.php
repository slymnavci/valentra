<?php
declare(strict_types=1);

/**
 * Kaydırma akışında sıradaki haber (28.10.2026; bkz. haber.php HABER AKISI).
 *
 * haber.php?sonraki=1&h=<son okunan>&haric=<akistaki numaralar>
 *
 * Son okunan haberin ilgili haberlerinden (ortak etiket, ayni baslik)
 * akista olmayan ilki; yoksa en yeni haberlerden biri. Yalnizca
 * <article> donuyor; yoksa 204. Ayri bir sayfa goruntulemesi olarak
 * kaydediliyor (adres siradaki haberin adresi); "insan" isareti betik
 * haber ekranda gorundugunde api/gorunum.php ile koyuyor.
 *
 * Bekledigi: $haber (son okunan haber ya da null).
 */

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if ($haber === null) {
    http_response_code(204);
    exit;
}

$haric = [(int) $haber['id'] => true];

foreach (array_slice(explode(',', (string) ($_GET['haric'] ?? '')), 0, 50) as $id) {
    if ((int) $id > 0) {
        $haric[(int) $id] = true;
    }
}

$sonraki = null;

foreach (array_merge(haber_ilgililer($haber, 12), haber_yayindakiler(20)) as $aday) {
    if (!isset($haric[(int) $aday['id']])) {
        $sonraki = haber_yayinda_bul((string) $aday['slug']);

        if ($sonraki !== null) {
            break;
        }
    }
}

if ($sonraki === null) {
    http_response_code(204);
    exit;
}

$haber  = $sonraki;
$baslik = $haber['baslik'] . ' — Valentra';

// Sayim: satirin adresi siradaki haberin adresi olsun (panelde "hangi
// sayfa okundu"), istegin kendi adresi degil.
require_once __DIR__ . '/ziyaret.php';
$_SERVER['REQUEST_URI'] = haber_yolu((string) $haber['slug']);
ziyaret_kaydet((int) $haber['id'], $baslik);
$zid = (int) ($GLOBALS['ziyaret_son_id'] ?? 0);

$govdeVeri = [
    'id'     => (int) $haber['id'],
    'slug'   => (string) $haber['slug'],
    'adres'  => haber_yolu((string) $haber['slug']),
    'baslik' => $baslik,
    'zid'    => $zid > 0 ? $zid : '',
    'zt'     => $zid > 0 ? ziyaret_isaret_imzasi($zid) : '',
];

header('Content-Type: text/html; charset=utf-8');
?>
<div class="sonraki-ayrac" role="separator"><span>Sıradaki haber</span></div>
<?php $sonrakiIstek = true; // reklam alanini akis betigi dolduruyor (bkz. haber.php) ?>
<?php require __DIR__ . '/haber_govde.php'; ?>
