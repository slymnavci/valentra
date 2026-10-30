<?php
declare(strict_types=1);

/**
 * Piyasalar: BIST 100, döviz, altın, Brent; dönem grafikleri; Borsa
 * İstanbul şirketleri; ekonomik göstergeler.
 *
 * Site sahibinin istegi (22.10.2026): Ekonomi menusunde piyasa (trendler,
 * BIST, grafikler) ile gunluk ekonomi haberleri AYRI. Haberler Ekonomi
 * konu sayfasinda; bu sayfa yalnizca piyasa verisi. Kartlar ve sirket
 * tablosu 2 dakikada bir yenileniyor (includes/canli_betik.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/piyasa_panel.php';
require_once __DIR__ . '/includes/canli_betik.php';

$donem = (string) ($_GET['donem'] ?? '3a');

/*
 * ?bolum=bist|doviz|altin|petrol|kripto|dunya|gostergeler: o bolumun AYRI sayfasi
 * (23.10.2026, site sahibinin istegi; bkz. piyasa_bolumleri). Kartlar ve
 * grafikler yalnizca o bolumun araclari, her grafigin altinda donem
 * analizi. Bolumsuz adres hepsini birden gosteren genel bakis.
 */
$bolumler = piyasa_bolumleri();
$bolum    = (string) ($_GET['bolum'] ?? '');
$bolum    = isset($bolumler[$bolum]) ? $bolum : '';
$b        = $bolum !== '' ? $bolumler[$bolum] : null;

$aktifKategori = $bolum !== '' ? 'ekonomi-' . $bolum : 'piyasalar';
$sayfaBasligi  = $b !== null
    ? $b['baslik'] . ' — Valentra'
    : 'Piyasalar: Borsa, Döviz, Altın, Emtia, Kripto — Valentra';
$sayfaAciklama = $b !== null
    ? $b['aciklama']
    : 'Döviz kurları, altın fiyatları, BIST endeksleri, yükselen ve düşen hisseler, emtia, '
      . 'kripto paralar, dünya borsaları; grafikler, döviz çevirici ve ekonomik göstergeler.';
$seoAdres      = site_adresi() . ($bolum !== '' ? piyasa_bolum_yolu($bolum) : piyasalar_yolu());

if ($b === null) {
    $kartlar     = piyasa_kartlari_html();
    $grafikler   = piyasa_grafikleri_html($donem);
    $sirketler   = piyasa_sirketler_html();
    $gostergeler = piyasa_gostergeleri_html();
    // BigPara'daki gibi cok kalemli tablolar (24.10.2026; bkz. piyasa_tablolari.php).
    $tablolar    = piyasa_tablolar_html(['doviz', 'altin', 'endeks', 'yukselen', 'emtia', 'kripto', 'dunya', 'parite', 'cevirici']);
} else {
    $kartlar     = $b['araclar'] !== [] ? piyasa_kartlari_html($b['araclar']) : '';
    $grafikler   = $b['araclar'] !== [] ? piyasa_grafikleri_html($donem, $b['araclar'], $bolum, true) : '';
    $sirketler   = !empty($b['sirketler']) ? piyasa_sirketler_html() : '';
    $gostergeler = !empty($b['gostergeler']) ? piyasa_gostergeleri_html() : '';
    $tablolar    = piyasa_tablolar_html($b['tablolar'] ?? []);
}

require __DIR__ . '/includes/sayfa_ust.php';
require_once __DIR__ . '/includes/gazeteler.php';

// ValPapel'in kunyesi ve basliklari (29.10.2026): piyasa sayfalari ValPapel'in.
$gazeteBolumu = ['bist' => 'borsa', 'dunya' => 'dunya-borsalari', 'petrol' => 'emtia'][$bolum] ?? ($bolum !== '' ? $bolum : 'piyasalar');
?>

<?= gazete_ust_html('valpapel', $gazeteBolumu) ?>

<div class="kategori-basligi">
    <h1><?= e($b !== null ? $b['baslik'] : 'Piyasalar') ?></h1>
    <p><?= $b !== null ? e($b['aciklama']) : 'Döviz, altın, borsa, emtia, kripto ve dünya borsaları; grafikler ve ekonomik göstergeler.' ?>
        Günün ekonomi haberleri <a href="<?= e(gazete_yolu('valpapel')) ?>">ValPapel</a>'de.</p>
</div>

<?php /* Bolumler arasi gecis ValPapel'in sekmelerinde (gazete_ust_html); ayri piyasa menusu 30.10.2026'da kaldirildi. */ ?>

<div class="ana-duzen tam">
    <div class="ana-kolon">
        <?php if ($kartlar === '' && $tablolar === '' && $grafikler === '' && $sirketler === '' && $gostergeler === ''): ?>
            <div class="bos-durum">
                <strong>Piyasa verisi şu an alınamadı.</strong>
                Birkaç dakika içinde yeniden deneyin.
            </div>
        <?php else: ?>
            <section class="piyasa-panel" aria-label="Piyasalar">
                <?= $kartlar ?>
                <?= $tablolar ?>
                <?php if ($grafikler !== ''): ?>
                    <div class="bolum-basligi"><h2><?= $b !== null ? 'Grafikler ve Dönem Analizi' : 'Piyasa Grafikleri' ?></h2><span class="cizgi"></span></div>
                    <?= $grafikler ?>
                <?php endif; ?>
                <?= $sirketler ?>
            </section>

            <?= $gostergeler ?>

            <?= canli_betik() ?>
        <?php endif; ?>

        <?= ekonomi_haberleri_html() ?>
    </div>

    <?php /* Yan pencere yok: grafik ve tablolar tam genislikte (23.10.2026). */ ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
