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
 * ?bolum=bist|doviz|altin|petrol|gostergeler: o bolumun AYRI sayfasi
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
    : 'Piyasalar: BIST 100, Döviz, Altın — Valentra';
$sayfaAciklama = $b !== null
    ? $b['aciklama']
    : 'BIST 100, dolar, euro, gram ve ons altın, Brent petrol: anlık değerler, '
      . 'grafikler, Borsa İstanbul şirketleri ve ekonomik göstergeler.';
$seoAdres      = site_adresi() . ($bolum !== '' ? piyasa_bolum_yolu($bolum) : piyasalar_yolu());

if ($b === null) {
    $kartlar     = piyasa_kartlari_html();
    $grafikler   = piyasa_grafikleri_html($donem);
    $sirketler   = piyasa_sirketler_html();
    $gostergeler = piyasa_gostergeleri_html();
} else {
    $kartlar     = $b['araclar'] !== [] ? piyasa_kartlari_html($b['araclar']) : '';
    $grafikler   = $b['araclar'] !== [] ? piyasa_grafikleri_html($donem, $b['araclar'], $bolum, true) : '';
    $sirketler   = !empty($b['sirketler']) ? piyasa_sirketler_html() : '';
    $gostergeler = !empty($b['gostergeler']) ? piyasa_gostergeleri_html() : '';
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1><?= e($b !== null ? $b['baslik'] : 'Piyasalar') ?></h1>
    <p><?= $b !== null ? e($b['aciklama']) : 'BIST 100, döviz, altın ve petrol; borsa şirketleri ve ekonomik göstergeler.' ?>
        Günün ekonomi haberleri <a href="<?= e(kategori_yolu('ekonomi')) ?>">Ekonomi</a> sayfasında.</p>
</div>

<?php /* Bolumler arasi gecis; spor sekmeleriyle ayni gorunum. */ ?>
<nav class="spor-sekmeler" aria-label="Piyasa bölümleri">
    <a href="<?= e(piyasalar_yolu()) ?>"<?= $bolum === '' ? ' class="aktif" aria-current="page"' : '' ?>>Genel Bakış</a>
    <?php foreach ($bolumler as $anahtar => $bb): ?>
        <a href="<?= e(piyasa_bolum_yolu($anahtar)) ?>"<?= $anahtar === $bolum ? ' class="aktif" aria-current="page"' : '' ?>><?= e($bb['ad']) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(sirket_yolu()) ?>">Şirket Analizi</a>
</nav>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php if ($kartlar === '' && $grafikler === '' && $sirketler === '' && $gostergeler === ''): ?>
            <div class="bos-durum">
                <strong>Piyasa verisi şu an alınamadı.</strong>
                Birkaç dakika içinde yeniden deneyin.
            </div>
        <?php else: ?>
            <section class="piyasa-panel" aria-label="Piyasalar">
                <?= $kartlar ?>
                <?php if ($grafikler !== ''): ?>
                    <div class="bolum-basligi"><h2><?= $b !== null ? 'Grafikler ve Dönem Analizi' : 'Piyasa Grafikleri' ?></h2><span class="cizgi"></span></div>
                    <?= $grafikler ?>
                <?php endif; ?>
                <?= $sirketler ?>
            </section>

            <?= $gostergeler ?>

            <?= canli_betik() ?>
        <?php endif; ?>

        <p class="piyasa-bag">
            <a href="<?= e(kategori_yolu('ekonomi')) ?>">Günlük ekonomi haberleri &rarr;</a>
        </p>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
