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
require_once __DIR__ . '/includes/kurumsal.php';
require_once __DIR__ . '/includes/pratik.php';
require_once __DIR__ . '/includes/piyasalar.php';
require_once __DIR__ . '/includes/deprem.php';
require_once __DIR__ . '/includes/konu.php';

header('Content-Type: application/xml; charset=utf-8');

$taban = site_adresi();

// Google'a sunulan haberler (bkz. haber_dizinlenir_kosulu).
// %70 ozgun kotasi taze olsun (bkz. haber_ozgun_kotasini_dagit).
try {
    haber_ozgun_kotasini_dagit();
} catch (Throwable $e) {
    error_log('[valentra] ozgun kota: ' . $e->getMessage());
}

$haberler = db()->query(
    'SELECT id, slug, baslik, gorsel_url, yayin_tarihi, guncellendi
       FROM haberler
      WHERE durum = ' . db()->quote(HABER_YAYINDA) . '
        AND ' . haber_dizinlenir_kosulu() . '
      ORDER BY yayin_tarihi DESC
      LIMIT 10000'
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
<?php /* Gorsel uzantisi (12.11.2026): haberin gorseli adresin yaninda
         bildiriliyor; Google Gorseller gorseli sayfayi taramayi beklemeden
         ogreniyor. */ ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    <url>
        <loc><?= e($taban) ?>/</loc>
        <changefreq>hourly</changefreq>
    </url>
    <?php /* Haber sitesinin on sayfasi (14.11.2026: kok adres artik V Network). */ ?>
    <url>
        <loc><?= e($taban . haber_ana_yolu()) ?></loc>
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

    <?php
    // Canli anlatimlar (03.11.2026; bkz. includes/canli.php).
    require_once __DIR__ . '/includes/canli.php';
    try { $canliHarita = canli_liste(100); } catch (PDOException $e) { $canliHarita = []; }
    ?>
    <?php foreach ($canliHarita as $canliSatir): ?>
        <url>
            <loc><?= e($taban . canli_yolu((string) $canliSatir['slug'])) ?></loc>
            <lastmod><?= e(date('c', strtotime((string) $canliSatir['guncellendi']) ?: time())) ?></lastmod>
            <changefreq><?= $canliSatir['durum'] === 'canli' ? 'always' : 'monthly' ?></changefreq>
        </url>
    <?php endforeach; ?>

    <?php
    // Mac ozetleri (04.11.2026; bkz. includes/mac_ozeti.php): liste ve son 500 ozet.
    require_once __DIR__ . '/includes/mac_ozeti.php';
    try { $ozetHarita = mac_ozeti_liste(100, 0); for ($i = 1; $i < 5 && count($ozetHarita) === 100 * $i; $i++) { $ozetHarita = array_merge($ozetHarita, mac_ozeti_liste(100, 100 * $i)); } } catch (PDOException $e) { $ozetHarita = []; }
    ?>
    <?php if ($ozetHarita !== []): ?>
        <url>
            <loc><?= e($taban . mac_ozetleri_yolu()) ?></loc>
            <changefreq>hourly</changefreq>
            <priority>0.7</priority>
        </url>
    <?php endif; ?>
    <?php foreach ($ozetHarita as $ozetSatir): ?>
        <url>
            <loc><?= e($taban . mac_ozeti_yolu((string) $ozetSatir['slug'])) ?></loc>
            <lastmod><?= e(date('c', strtotime((string) $ozetSatir['yayin']) ?: time())) ?></lastmod>
        </url>
    <?php endforeach; ?>

    <?php foreach (array_keys(kurumsal_sayfalar()) as $kurumsalAnahtar): ?>
        <url>
            <loc><?= e($taban . kurumsal_yolu($kurumsalAnahtar)) ?></loc>
            <changefreq>monthly</changefreq>
            <priority>0.3</priority>
        </url>
    <?php endforeach; ?>

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

    <?php // Gunluk burc yorumlari (29.10.2026): her gun yenileniyor. ?>
    <?php require_once __DIR__ . '/includes/burclar.php'; ?>
    <url>
        <loc><?= e($taban . burclar_yolu()) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
    <?php foreach (array_keys(BURCLAR) as $burcAnahtari): ?>
        <url>
            <loc><?= e($taban . burc_yolu($burcAnahtari)) ?></loc>
            <changefreq>daily</changefreq>
            <priority>0.6</priority>
        </url>
    <?php endforeach; ?>

    <?php /* Gundem dosyalari: yeterli haberi olanlar (bkz. KONU_DIZIN_ASGARI). */ ?>
    <url>
        <loc><?= e($taban . konu_yolu()) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.6</priority>
    </url>
    <?php foreach (konu_ozetleri() as $konuSlug => $konuOzet): ?>
        <?php if ($konuOzet['sayi'] >= KONU_DIZIN_ASGARI): ?>
        <url>
            <loc><?= e($taban . konu_yolu($konuSlug)) ?></loc>
            <lastmod><?= e(date('c', strtotime($konuOzet['son']))) ?></lastmod>
            <changefreq>daily</changefreq>
            <priority>0.8</priority>
        </url>
        <?php endif; ?>
    <?php endforeach; ?>

    <url>
        <loc><?= e($taban . deprem_yolu()) ?></loc>
        <changefreq>always</changefreq>
        <priority>0.8</priority>
    </url>

    <?php require_once __DIR__ . '/includes/kelime_oyunu.php'; ?>
    <url>
        <loc><?= e($taban . kelime_oyunu_yolu()) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.5</priority>
    </url>

    <url>
        <loc><?= e($taban . piyasalar_yolu()) ?></loc>
        <changefreq>hourly</changefreq>
        <priority>0.7</priority>
    </url>

    <?php foreach (array_keys(piyasa_sirketleri()) as $sirketKodu): ?>
        <url>
            <loc><?= e($taban . sirket_yolu($sirketKodu)) ?></loc>
            <changefreq>daily</changefreq>
            <priority>0.5</priority>
        </url>
    <?php endforeach; ?>

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

    <?php // Video haberler (30.10.2026). ?>
    <url>
        <loc><?= e($taban . video_yolu()) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.6</priority>
    </url>

    <?php // ValPapel, ValSpor, ValMagazin ve haber basliklari (29.10.2026). ?>
    <?php require_once __DIR__ . '/includes/gazeteler.php'; ?>
    <?php foreach (gazeteler() as $gazeteAnahtari => $gazete): ?>
        <url>
            <loc><?= e($taban . gazete_yolu($gazeteAnahtari)) ?></loc>
            <changefreq>hourly</changefreq>
            <priority>0.8</priority>
        </url>
        <?php foreach ($gazete['bolumler'] as $bolumAnahtari => $gazeteBolumu): ?>
            <?php if (!isset($gazeteBolumu['kosullar'])) { continue; } ?>
            <url>
                <loc><?= e($taban . gazete_yolu($gazeteAnahtari, $bolumAnahtari)) ?></loc>
                <changefreq>daily</changefreq>
                <priority>0.6</priority>
            </url>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php foreach ($kategoriler as $kategori): ?>
        <?php // Gazetesi olan kategorinin adresi gazeteye yonleniyor; yukarida. ?>
        <?php if (isset(KATEGORI_GAZETESI[(string) $kategori['slug']])) { continue; } ?>
        <url>
            <loc><?= e($taban . kategori_yolu((string) $kategori['slug'])) ?></loc>
            <lastmod><?= e($zaman($kategori['son'])) ?></lastmod>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    <?php endforeach; ?>

    <?php // Spor'un puan durumu ve fikstur sayfalari ayri adresler (23.10.2026). ?>
    <?php if (in_array('spor', array_column($kategoriler, 'slug'), true)): ?>
        <?php require_once __DIR__ . '/includes/spor_panel.php'; ?>
        <?php foreach (array_keys(SPOR_BOLUMLERI) as $bolum): ?>
            <?php if (in_array($bolum, ['gol-kralligi', 'asist-kralligi'], true) && !futbol_api_acik()) { continue; } ?>
            <url>
                <loc><?= e($taban . spor_bolum_yolu($bolum)) ?></loc>
                <changefreq>daily</changefreq>
                <priority>0.6</priority>
            </url>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php foreach ($haberler as $haber): ?>
        <url>
            <loc><?= e($taban . haber_yolu((string) $haber['slug'])) ?></loc>
            <lastmod><?= e($zaman($haber['guncellendi'] ?: $haber['yayin_tarihi'])) ?></lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
            <?php $haritaGorsel = seo_haber_gorsel_adresi($haber, true); ?>
            <?php if ($haritaGorsel !== ''): ?>
            <image:image>
                <image:loc><?= e($haritaGorsel) ?></image:loc>
            </image:image>
            <?php endif; ?>
        </url>
    <?php endforeach; ?>
</urlset>
