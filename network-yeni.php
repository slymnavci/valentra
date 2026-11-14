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
// Konu secili akis (06.11.2026, bkz. nw_konular): yalnizca o konunun haberleri ve yorumlari.
$konu  = (string) ($_GET['konu'] ?? '');
$konu  = isset(nw_konular()[$konu]) ? $konu : '';
// Gazete secili akis (13.11.2026, bkz. nw_gazeteler): yalnizca o yayindan
// ozetlenen haberler; paylasim ve yorum sayilmaz (akista da yoklar).
$gazete = (string) ($_GET['gazete'] ?? '');
$gazeteKaynaklari = $gazete !== '' ? (nw_gazeteler()[$gazete]['kaynaklar'] ?? []) : [];
$gazeteKosulu = $gazete === '' ? '' : ($gazeteKaynaklari !== []
    ? ' AND h.kaynak_adi IN (' . implode(', ', array_map(static fn (string $k): string => db()->quote($k), $gazeteKaynaklari)) . ')'
    : ' AND 1 = 0');
$konu  = $gazete !== '' ? '' : $konu;
$sayi  = 0;

try {
    $h = db()->prepare(
        "SELECT COUNT(*) FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d AND h.yayin_tarihi > FROM_UNIXTIME(:o) AND h.yayin_tarihi <= NOW()
            AND " . ($konu !== '' ? 'k.slug = ' . db()->quote($konu) : "(k.slug IS NULL OR k.slug <> 'yemek-tarifleri')") . $gazeteKosulu
            // Spor dali secili akis (14.11.2026): yalnizca o dalin yeni haberleri.
            . ($konu === 'spor' ? nw_spor_dal_kosulu((string) ($_GET['dal'] ?? '')) : '')
    );
    $h->execute(['d' => HABER_YAYINDA, 'o' => $once]);
    $sayi += (int) $h->fetchColumn();

    // Akista yalnizca haber var (13.11.2026): paylasim ve yorum sayilmaz.
    echo json_encode(['sayi' => min($sayi, 99)]);
    exit;

    $kosul = $takip ? ' AND (g.uye_id = :u1 OR g.uye_id IN (SELECT takip_edilen FROM takipler WHERE takip_eden = :u2))' : '';

    if ($konu === '') {
        $g = db()->prepare("SELECT COUNT(*) FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id
                             WHERE g.durum = 'yayinda' AND u.durum = 'aktif' AND g.olusturuldu > FROM_UNIXTIME(:o)" . $kosul);
        $g->execute(['o' => $once] + ($takip ? ['u1' => (int) $uye['id'], 'u2' => (int) $uye['id']] : []));
        $sayi += (int) $g->fetchColumn();
    }

    // Haber yorumlari da akista kart (01.11.2026, bkz. nw_akis).
    $y = db()->prepare("SELECT COUNT(*) FROM yorumlar y JOIN uyeler u ON u.id = y.uye_id
                          JOIN haberler h ON h.id = y.haber_id LEFT JOIN kategoriler k ON k.id = h.kategori_id
                         WHERE y.haber_id IS NOT NULL AND y.durum = 'yayinda' AND u.durum = 'aktif' AND u.tur <> 'yz'
                           AND y.olusturuldu > FROM_UNIXTIME(:o)" . str_replace('g.uye_id', 'y.uye_id', $kosul)
                        . ($konu !== '' ? ' AND k.slug = ' . db()->quote($konu) : ''));
    $y->execute(['o' => $once] + ($takip ? ['u1' => (int) $uye['id'], 'u2' => (int) $uye['id']] : []));
    $sayi += (int) $y->fetchColumn();
} catch (PDOException $e) {
    $sayi = 0;
}

echo json_encode(['sayi' => min($sayi, 99)]);
