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

$aktifKategori = 'ekonomi';
$sayfaBasligi  = 'Piyasalar: BIST 100, Döviz, Altın — Valentra';
$sayfaAciklama = 'BIST 100, dolar, euro, gram ve ons altın, Brent petrol: anlık değerler, '
               . 'grafikler, Borsa İstanbul şirketleri ve ekonomik göstergeler.';
$seoAdres      = site_adresi() . piyasalar_yolu();

$kartlar    = piyasa_kartlari_html();
$grafikler  = piyasa_grafikleri_html($donem);
$sirketler  = piyasa_sirketler_html();
$gostergeler = piyasa_gostergeleri_html();

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Piyasalar</h1>
    <p>BIST 100, döviz, altın ve petrol; borsa şirketleri ve ekonomik göstergeler.
        Günün ekonomi haberleri <a href="<?= e(kategori_yolu('ekonomi')) ?>">Ekonomi</a> sayfasında.</p>
</div>

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
                    <div class="bolum-basligi"><h2>Piyasa Grafikleri</h2><span class="cizgi"></span></div>
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
