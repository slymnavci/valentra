<?php
declare(strict_types=1);

/**
 * Google Haberler site haritası: son 2 günün haberleri.
 *
 * Site sahibinin sorusu (24.10.2026): "gunluk haberleri Google'a gonderip
 * dizine eklenmesini saglayabilir misin?" Google tek tek adres icin
 * "dizine ekle" API'si sunmuyor (Indexing API yalnizca is ilani ve canli
 * yayin icin; haberde kullanmak kurallara aykiri). Haber siteleri icin
 * resmi yol bu harita: Google onu genel haritadan cok daha sik okuyor ve
 * <news:publication_date> sayesinde yeni haberi hemen ayirt ediyor.
 *
 * Kurallar (Google): yalnizca son 2 gunun haberleri, en fazla 1000 adres.
 * Haber yayimlandiginda harita Search Console'a yeniden gonderiliyor
 * (saatte en fazla bir; bkz. includes/arama_bildirim.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300');

$taban = site_adresi();

$haberler = db()->query(
    'SELECT slug, baslik, yayin_tarihi
       FROM haberler
      WHERE durum = ' . db()->quote(HABER_YAYINDA) . '
        AND yayin_tarihi >= NOW() - INTERVAL 2 DAY
        AND ' . haber_dizinlenir_kosulu() . '
      ORDER BY yayin_tarihi DESC
      LIMIT 1000'
)->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php foreach ($haberler as $haber): ?>
    <url>
        <loc><?= e($taban . haber_yolu((string) $haber['slug'])) ?></loc>
        <news:news>
            <news:publication>
                <news:name>Valentra</news:name>
                <news:language>tr</news:language>
            </news:publication>
            <news:publication_date><?= e(date('c', (int) strtotime((string) $haber['yayin_tarihi']))) ?></news:publication_date>
            <news:title><?= e((string) $haber['baslik']) ?></news:title>
        </news:news>
    </url>
<?php endforeach; ?>
</urlset>
