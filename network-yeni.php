<?php
declare(strict_types=1);

/**
 * V Network akisinda "yeni paylasim" sayisi (31.10.2026, site sahibi: "X'te
 * oldugu gibi; geri dondugumde en son okudugum haber en ustte kalsin, en
 * uste tiklaninca yeni haberler basa gelsin").
 *
 *   GET /network-yeni.php?once=<unix>&sekme=takip
 *   {"sayi": 3}
 *
 * Akistaki en yeni kartin zamanindan sonra gelen haber ve paylasim sayisi;
 * akis betigi (assets/network-akis.js) birkac dakikada bir soruyor ve
 * "↑ 3 yeni paylasim" dugmesini gosteriyor. Iki kucuk COUNT sorgusu.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/uyelik.php';
require_once __DIR__ . '/includes/topluluk.php';

// Acik akis sekmeleri birkac dakikada bir soruyor: YZ begenileri de buradan ilerliyor.
topluluk_begeni_zamanla();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$once = (int) ($_GET['once'] ?? 0);

if ($once <= 0 || $once > time() + 3600) {
    echo '{"sayi":0}';
    exit;
}

$uye   = aktif_uye();
$takip = $uye !== null && (string) ($_GET['sekme'] ?? '') === 'takip';
$sayi  = 0;

try {
    $h = db()->prepare(
        "SELECT COUNT(*) FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d AND h.yayin_tarihi > FROM_UNIXTIME(:o) AND h.yayin_tarihi <= NOW()
            AND (k.slug IS NULL OR k.slug <> 'yemek-tarifleri')"
    );
    $h->execute(['d' => HABER_YAYINDA, 'o' => $once]);
    $sayi += (int) $h->fetchColumn();

    $kosul = $takip ? ' AND (g.uye_id = :u1 OR g.uye_id IN (SELECT takip_edilen FROM takipler WHERE takip_eden = :u2))' : '';
    $g = db()->prepare("SELECT COUNT(*) FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id
                         WHERE g.durum = 'yayinda' AND u.durum = 'aktif' AND g.olusturuldu > FROM_UNIXTIME(:o)" . $kosul);
    $g->execute(['o' => $once] + ($takip ? ['u1' => (int) $uye['id'], 'u2' => (int) $uye['id']] : []));
    $sayi += (int) $g->fetchColumn();
} catch (PDOException $e) {
    $sayi = 0;
}

echo json_encode(['sayi' => min($sayi, 99)]);
