<?php
declare(strict_types=1);

/**
 * Gazete simgesi (13.11.2026; bkz. includes/network.php, nw_gazete_simge_html).
 *
 *   /gazete-simge.php?g=hurriyet
 *
 * Yayinin site simgesini (favicon) bir kez indirir, gorseller/gazete/ altina
 * yazar ve verir; sonraki gosterimler dogrudan o dosyadan. Yalnizca gazete
 * listesindeki yayinlar icin calisir (adres disaridan verilemez). Indirilemezse
 * 404 doner ve bir gun yeniden denenmez; sayfada adin bas harfi gorunur.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/topluluk.php';

$slug = (string) ($_GET['g'] ?? '');
$g    = preg_match('/^[a-z0-9-]{1,60}$/', $slug) === 1 ? (nw_gazeteler()[$slug] ?? null) : null;
$alan = $g !== null ? (string) $g['alan'] : '';

$yok = static function (): never {
    http_response_code(404);
    header('Cache-Control: public, max-age=3600');
    exit;
};

if ($g === null || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $alan) !== 1) {
    $yok();
}

$klasor = nw_gazete_simge_klasoru();
$dosya  = $klasor . '/' . $slug . '.png';
$isaret = $klasor . '/' . $slug . '.yok';

if (!is_file($dosya)) {
    if (is_file($isaret) && filemtime($isaret) > time() - 86400) {
        $yok();
    }

    if (!is_dir($klasor)) {
        @mkdir($klasor, 0775, true);
    }

    // Site simgesi Google'in simge servisinden PNG olarak (her site icin tek, duzgun bicim).
    $ch = curl_init('https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($alan));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $bilgi = is_string($govde) && $govde !== '' && strlen($govde) < 200000 ? @getimagesizefromstring($govde) : false;

    // Gercekten PNG mi ve en az 16 piksel mi (servis bilinmeyen sitede 16 piksellik genel simge verir: o da kabul degil).
    if ($kod !== 200 || $bilgi === false || ($bilgi['mime'] ?? '') !== 'image/png' || $bilgi[0] < 24) {
        @file_put_contents($isaret, (string) $kod);
        $yok();
    }

    @file_put_contents($dosya, $govde, LOCK_EX);
    @unlink($isaret);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=2592000');
readfile($dosya);
