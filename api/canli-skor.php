<?php
declare(strict_types=1);

/**
 * Büyük maç canlı skoru (JSON): Galatasaray, Fenerbahçe, Beşiktaş.
 *
 * Her sayfanin ustundeki skor bandi (includes/sayfa_ust.php) bunu
 * soruyor: mac oynanirken 20 saniyede bir, mac yoksa hic. Oynanan macin
 * skoru BUYUK_MAC_TAZE saniyeden eskiyse ESPN'den hemen yeniden
 * aliniyor; ayni anda gelen ziyaretciler ESPN'e tek istek atiyor (bkz.
 * includes/buyuk_mac.php).
 *
 * Acik uc: yetki istemez, dondurdugu bilgi zaten herkese acik.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/buyuk_mac.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

try {
    $maclar = buyuk_maclar(true);
} catch (Throwable $e) {
    error_log('[valentra] canli skor: ' . $e->getMessage());
    $maclar = [];
}

$suruyor = array_filter($maclar, static fn (array $m): bool => !in_array($m['durum'], FUTBOL_BITTI, true));

// Tarayici ve ara bellekler kisa sure saklasin: ayni anda acilan yuzlerce
// sayfa sunucuya ayri ayri gelmesin.
header('Cache-Control: public, max-age=' . ($suruyor !== [] ? 10 : 120));

echo json_encode([
    'maclar' => array_map(static function (array $m): array {
        unset($m['veri_zamani']);

        return $m;
    }, $maclar),
    // Betik bu kadar saniye sonra yeniden sorar (0: sormaz).
    'html'   => buyuk_mac_bandi_html($maclar),
    'yenile' => $suruyor !== [] ? BUYUK_MAC_TAZE : ($maclar !== [] ? 300 : 0),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
