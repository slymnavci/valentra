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
// "Diger gazeteler"de toplanan yayinlarin simgesi de verilir (14.11.2026; bkz. nw_gazete_alani).
$alan = preg_match('/^[a-z0-9-]{1,60}$/', $slug) === 1 && $slug !== NW_GAZETE_DIGER ? nw_gazete_alani($slug) : '';
$g    = $alan !== '' ? ['alan' => $alan] : null;

$yok = static function (): never {
    http_response_code(404);
    header('Cache-Control: public, max-age=3600');
    exit;
};

if ($g === null || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $alan) !== 1) {
    $yok();
}

$klasor = nw_gazete_simge_klasoru();
// Dosya adinda alan adi var (14.11.2026, bkz. nw_gazete_simge_dosyasi): adres duzelince eski simge kullanilmaz.
$ad     = nw_gazete_simge_dosyasi($slug, $alan);
$dosya  = $klasor . '/' . $ad . '.png';
$isaret = $klasor . '/' . $ad . '.yok';

/** Adresten gorsel indirir; gecerli (PNG / ICO / JPEG / WEBP, en az $enAz piksel) degilse null. */
$indir = static function (string $adres, int $enAz = 24): ?string {
    $ch = curl_init($adres);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; simge)',
    ]);
    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($kod !== 200 || !is_string($govde) || $govde === '' || strlen($govde) > 300000) {
        return null;
    }

    $bilgi = @getimagesizefromstring($govde);

    return $bilgi !== false && in_array($bilgi['mime'] ?? '', ['image/png', 'image/vnd.microsoft.icon', 'image/x-icon', 'image/jpeg', 'image/webp'], true)
        && $bilgi[0] >= $enAz ? $govde : null;
};

if (!is_file($dosya)) {
    if (is_file($isaret) && filemtime($isaret) > time() - 86400) {
        $yok();
    }

    if (!is_dir($klasor)) {
        @mkdir($klasor, 0775, true);
    }

    /*
     * Sirayla (14.11.2026; canli taramada Sozcu, Taka, TechCrunch, CoinDesk
     * ilk servisten gelmiyordu):
     *   1. Google'in simge servisi (bilinmeyen sitede 16 piksellik genel
     *      simge verir: en az 24 piksel kosulu onu eler),
     *   2. sitenin kendi buyuk simgesi (apple-touch-icon),
     *   3. DuckDuckGo'nun simge servisi,
     *   4. sitenin favicon.ico dosyasi.
     */
    $govde = $indir('https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($alan))
        ?? $indir('https://www.' . $alan . '/apple-touch-icon.png', 48)
        ?? $indir('https://' . $alan . '/apple-touch-icon.png', 48)
        ?? $indir('https://icons.duckduckgo.com/ip3/' . rawurlencode($alan) . '.ico', 24)
        ?? $indir('https://www.' . $alan . '/favicon.ico', 16);

    if ($govde === null) {
        @file_put_contents($isaret, '1');
        $yok();
    }

    @file_put_contents($dosya, $govde, LOCK_EX);
    @unlink($isaret);
}

$tur = (string) ((@getimagesize($dosya))['mime'] ?? 'image/png');

header('Content-Type: ' . $tur);
header('Cache-Control: public, max-age=2592000');
readfile($dosya);
