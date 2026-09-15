<?php
declare(strict_types=1);

/**
 * Site haritası.
 *
 * Arama motoru sayfalari baglantilari izleyerek bulur; harita ise
 * "sitede su adresler var, sonuncusu su tarihte degisti" diye dogrudan
 * soyler. Yeni haberin indekslenmesi gunler yerine saatler aliyor.
 *
 * Statik bir .xml yerine PHP: haberler surekli eklendigi icin dosyayi
 * elle guncel tutmak imkansiz.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';

header('Content-Type: application/xml; charset=utf-8');

$taban = site_adresi();

$haberler = db()->query(
    'SELECT slug, yayin_tarihi, guncellendi
       FROM haberler
      WHERE durum = ' . db()->quote(HABER_YAYINDA) . '
      ORDER BY yayin_tarihi DESC
      LIMIT 2000'
)->fetchAll();

$kategoriler = db()->query(
    'SELECT k.slug, MAX(h.yayin_tarihi) AS son
       FROM kategoriler k
       JOIN haberler h ON h.kategori_id = k.id
            AND h.durum = ' . db()->quote(HABER_YAYINDA) . '
      WHERE k.aktif = 1
      GROUP BY k.slug'
)->fetchAll();

/** Tarihi W3C biçimine çevirir. */
$zaman = static fn (?string $t): string => date('c', $t !== null ? (int) strtotime($t) : time());

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?= e($taban) ?>/</loc>
        <changefreq>hourly</changefreq>
        <priority>1.0</priority>
    </url>

    <url>
        <loc><?= e($taban) ?>/kanunlar.php</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>

    <?php foreach ($kategoriler as $kategori): ?>
        <url>
            <loc><?= e($taban) ?>/kategori.php?k=<?= e(rawurlencode((string) $kategori['slug'])) ?></loc>
            <lastmod><?= e($zaman($kategori['son'])) ?></lastmod>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    <?php endforeach; ?>

    <?php foreach ($haberler as $haber): ?>
        <url>
            <loc><?= e($taban) ?>/haber.php?h=<?= e(rawurlencode((string) $haber['slug'])) ?></loc>
            <lastmod><?= e($zaman($haber['guncellendi'] ?: $haber['yayin_tarihi'])) ?></lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
        </url>
    <?php endforeach; ?>
</urlset>
