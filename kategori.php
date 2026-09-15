<?php
declare(strict_types=1);

/** Konu grubu sayfasi. */

require_once __DIR__ . '/includes/bootstrap.php';

$slug     = trim((string) ($_GET['k'] ?? ''));
$kategori = $slug !== '' ? kategori_slug_bul($slug) : null;

if ($kategori === null) {
    http_response_code(404);
    $sayfaBasligi = 'Konu bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Konu grubu bulunamadı.</strong>
        <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$sayfa    = max(1, (int) ($_GET['sayfa'] ?? 1));
$adet     = 12;
$toplam   = haber_kategoride_sayi((int) $kategori['id']);
$sonSayfa = max(1, (int) ceil($toplam / $adet));
$haberler = haber_kategoride((int) $kategori['id'], $adet, ($sayfa - 1) * $adet);

$aktifKategori = $kategori['slug'];
$sayfaBasligi  = $kategori['ad'] . ' — Valentra';
$sayfaAciklama = $kategori['aciklama'] !== ''
    ? $kategori['aciklama']
    : $kategori['ad'] . ' konusundaki güncel vergi haberleri.';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1><?= e($kategori['ad']) ?></h1>
    <?php if ($kategori['aciklama'] !== ''): ?>
        <p><?= e($kategori['aciklama']) ?></p>
    <?php endif; ?>
</div>

<div class="bolum-basligi">
    <h2><?= $toplam ?> Haber</h2>
    <span class="cizgi"></span>
</div>

<?php if ($haberler === []): ?>
    <div class="bos-durum">
        <strong>Bu grupta henüz haber yok.</strong>
        Bu konuda gelişme olduğunda haberler burada listelenecek.
    </div>
<?php else: ?>
    <?php $izgaraHaberleri = $haberler; ?>
    <?php require __DIR__ . '/includes/kart_izgara.php'; ?>

    <?php if ($sonSayfa > 1): ?>
        <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;">
            <?php if ($sayfa > 1): ?>
                <a href="/kategori.php?k=<?= e($kategori['slug']) ?>&sayfa=<?= $sayfa - 1 ?>">&larr; Önceki</a>
            <?php endif; ?>
            <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
            <?php if ($sayfa < $sonSayfa): ?>
                <a href="/kategori.php?k=<?= e($kategori['slug']) ?>&sayfa=<?= $sayfa + 1 ?>">Sonraki &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
