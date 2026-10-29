<?php
declare(strict_types=1);

/** Etiket sayfası: bir etiketteki bütün haberler (28.10.2026; bkz. includes/etiket.php). */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/etiket.php';

$sayfa  = max(1, (int) ($_GET['sayfa'] ?? 1));
$adet   = 24;
$sonuc  = etiket_haberleri((string) ($_GET['e'] ?? ''), $adet, ($sayfa - 1) * $adet);

if ($sonuc['toplam'] === 0) {
    http_response_code(404);
    $sayfaBasligi = 'Etiket bulunamadı — Valentra';
    $seoRobots    = 'noindex, follow';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Bu etikette yayında haber yok.</strong>
        <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$etiketAdi = $sonuc['ad'];
$sonSayfa  = max(1, (int) ceil($sonuc['toplam'] / $adet));

require_once __DIR__ . '/includes/seo.php';
$seoAdres = site_adresi() . etiket_yolu($etiketAdi, $sayfa);

// Az haberli etiket ince sayfa; 2. ve sonraki sayfalar da (kategoriyle ayni)
// Google'a sunulmuyor. "follow": haberlere giden baglantilar yine taraniyor.
if ($sonuc['toplam'] < ETIKET_DIZIN_ASGARI || $sayfa > 1) {
    $seoRobots = 'noindex, follow';
}

$sayfaBasligi  = $etiketAdi . ' haberleri — Valentra';
$sayfaAciklama = $etiketAdi . ' ile ilgili son haberler ve gelişmeler.';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>#<?= e($etiketAdi) ?></h1>
    <p><?= e($etiketAdi) ?> ile ilgili <?= (int) $sonuc['toplam'] ?> haber</p>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php $izgaraHaberleri = $sonuc['haberler']; ?>
        <?php require __DIR__ . '/includes/kart_izgara.php'; ?>

        <?php if ($sonSayfa > 1): ?>
            <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;">
                <?php if ($sayfa > 1): ?>
                    <a href="<?= e(etiket_yolu($etiketAdi, $sayfa - 1)) ?>">&larr; Önceki</a>
                <?php endif; ?>
                <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
                <?php if ($sayfa < $sonSayfa): ?>
                    <a href="<?= e(etiket_yolu($etiketAdi, $sayfa + 1)) ?>">Sonraki &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
