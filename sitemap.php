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
require_once __DIR__ . '/includes/pratik.php';

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
        <loc><?= e($taban . kanunlar_yolu()) ?></loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>

    <url>
        <loc><?= e($taban . pratik_yolu()) ?></loc>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>

    <url>
        <loc><?= e($taban . takvim_yolu()) ?></loc>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>

    <url>
        <loc><?= e($taban . araclar_yolu()) ?></loc>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>

    <?php /* TMS/TFRS egitim platformu: giris sayfasi herkese acik, icerik uyelere. */ ?>
    <url>
        <loc><?= e($taban) ?>/egitim/</loc>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>

    <url>
        <loc><?= e($taban . rg_yolu()) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>

    <?php
    /*
     * Her pratik bilginin kendi sayfasi haritaya giriyor.
     *
     * "Kidem tazminati tavani" gibi aramalarin dogrudan cevabi bu
     * sayfalar; tek tek indekslenmeleri ana sayfadan cok daha degerli.
     */
    foreach (pratik_yayindakiler() as $satirlar): ?>
        <?php foreach ($satirlar as $bilgi): ?>
            <url>
                <loc><?= e($taban . pratik_bilgi_yolu((string) $bilgi['anahtar'])) ?></loc>
                <?php if (!empty($bilgi['onay_tarihi'])): ?>
                    <lastmod><?= e($zaman((string) $bilgi['onay_tarihi'])) ?></lastmod>
                <?php endif; ?>
                <changefreq>weekly</changefreq>
                <priority>0.7</priority>
            </url>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <url>
        <loc><?= e($taban . kose_liste_yolu()) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>

    <?php
    /*
     * Kose yazilari: yalnizca yayindakiler. Tablo henuz yoksa (sema
     * yukseltmesi dustuyse) harita yine uretilsin.
     */
    try {
        $koseler = db()->query(
            "SELECT slug, COALESCE(guncellendi, yayin_tarihi) AS son FROM kose_yazilari
              WHERE durum = 'yayinda' ORDER BY gun DESC LIMIT 1000"
        )->fetchAll();
    } catch (PDOException $e) {
        $koseler = [];
    }

    foreach ($koseler as $kose): ?>
        <url>
            <loc><?= e($taban . kose_yolu((string) $kose['slug'])) ?></loc>
            <lastmod><?= e($zaman((string) $kose['son'])) ?></lastmod>
            <changefreq>monthly</changefreq>
            <priority>0.7</priority>
        </url>
    <?php endforeach; ?>

    <?php
    /* Uygulama rehberleri: kalici icerik, guncellendikce lastmod ilerliyor. */
    require_once __DIR__ . '/includes/rehberler.php';
    $rehberler = rehber_yayindakiler();
    ?>
    <?php if ($rehberler !== []): ?>
        <url>
            <loc><?= e($taban . rehberler_yolu()) ?></loc>
            <changefreq>weekly</changefreq>
        </url>
    <?php endif; ?>
    <?php foreach ($rehberler as $rehber): ?>
        <url>
            <loc><?= e($taban . rehber_yolu((string) $rehber['slug'])) ?></loc>
            <lastmod><?= e($zaman((string) $rehber['guncellendi'])) ?></lastmod>
            <priority>0.8</priority>
        </url>
    <?php endforeach; ?>

    <?php foreach ($kategoriler as $kategori): ?>
        <url>
            <loc><?= e($taban . kategori_yolu((string) $kategori['slug'])) ?></loc>
            <lastmod><?= e($zaman($kategori['son'])) ?></lastmod>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    <?php endforeach; ?>

    <?php foreach ($haberler as $haber): ?>
        <url>
            <loc><?= e($taban . haber_yolu((string) $haber['slug'])) ?></loc>
            <lastmod><?= e($zaman($haber['guncellendi'] ?: $haber['yayin_tarihi'])) ?></lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
        </url>
    <?php endforeach; ?>
</urlset>
