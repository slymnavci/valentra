<?php
declare(strict_types=1);

/**
 * Search Console verisini tazeleme ucu.
 *
 *   POST -> son tazeleme 20 saatten eskiyse Search Console'dan ceker
 *   POST ?zorla=1 -> sure beklemeden ceker
 *
 * Ajan her calismasinda durtuyor; gunde tek cekim yeterli (veri 2-3 gun
 * geriden geliyor). Anahtar panelde girilmemisse 200 + "kurulmamis".
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/search_console.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

if (gsc_hesap() === null) {
    ajan_json(200, ['durum' => 'kurulmamis', 'mesaj' => 'Panelde servis hesabı anahtarı yok.']);
}

if (!isset($_GET['zorla']) && !gsc_tazelenmeli()) {
    ajan_json(200, ['durum' => 'guncel', 'son' => ayar_oku('gsc_son_tazeleme')]);
}

@set_time_limit(100);

try {
    $ozet = gsc_tazele(70);
} catch (Throwable $e) {
    ajan_json(500, ['hata' => 'Search Console tazelenemedi: ' . $e->getMessage()]);
}

ajan_json($ozet['tamam'] ? 200 : 502, ['durum' => $ozet['tamam'] ? 'tamam' : 'alinamadi'] + $ozet);
