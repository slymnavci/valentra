<?php
declare(strict_types=1);

/**
 * Tüm köşe yazıları ("Valentra Diyor ki…"), günlere göre.
 *
 * Sayfalama gun uzerinden: bir gunun yazilari iki sayfaya bolunmez.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kose.php';
require_once __DIR__ . '/includes/seo.php';

const KOSE_SAYFA_GUN = 10;

$sayfa    = max(1, (int) ($_GET['sayfa'] ?? 1));
$gunSayi  = kose_gun_sayisi();
$sonSayfa = max(1, (int) ceil($gunSayi / KOSE_SAYFA_GUN));

if ($sayfa > $sonSayfa) {
    $sayfa = $sonSayfa;
}

$gunler = kose_gunlere_gore($sayfa, KOSE_SAYFA_GUN);

$gunAdlari = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

$aktifKategori = 'kose';
$sayfaBasligi  = 'Valentra Diyor ki… — Tüm yazılar' . ($sayfa > 1 ? ' — Sayfa ' . $sayfa : '');
$sayfaAciklama = 'Valentra\'nın her gün gündemin en önemli konularını değerlendirdiği '
               . 'köşe yazıları: ekonomi, piyasalar, vergi ve mevzuat.';
$seoAdres      = site_adresi() . kose_liste_yolu($sayfa);

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Valentra Diyor ki…</h1>
    <p>
        Her gün gündemin en önemli 3-4 konusu, ayrı ayrı yazılarla:
        ekonomi, piyasalar, vergi ve mevzuat.
    </p>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php if ($gunler === []): ?>
            <div class="bos-durum">
                <strong>Henüz yayımlanmış yazı yok.</strong>
                Günün yazıları editör onayından geçtikten sonra burada görünecek.
            </div>
        <?php endif; ?>

        <?php foreach ($gunler as $gun => $yazilar): ?>
            <?php $zaman = strtotime($gun); ?>
            <div class="bolum-basligi">
                <h2><?= e(tarih_bicimle($gun, false) . ($zaman !== false ? ' ' . $gunAdlari[(int) date('w', $zaman)] : '')) ?></h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($yazilar as $yazi): ?>
                    <li>
                        <a href="<?= e(kose_yolu((string) $yazi['slug'])) ?>">
                            <?php if ((string) $yazi['gundem'] !== ''): ?>
                                <span class="kose-gundem"><?= e((string) $yazi['gundem']) ?></span>
                            <?php endif; ?>
                            <span class="kose-baslik"><?= e((string) $yazi['baslik']) ?></span>
                            <?php if ((string) $yazi['ozet'] !== ''): ?>
                                <span class="kose-ozet"><?= e((string) $yazi['ozet']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>

        <?php if ($sonSayfa > 1): ?>
            <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;" aria-label="Sayfalar">
                <?php if ($sayfa > 1): ?>
                    <a class="rg-dis" href="<?= e(kose_liste_yolu($sayfa - 1)) ?>">&larr; Daha yeni</a>
                <?php endif; ?>
                <span><?= $sayfa ?> / <?= $sonSayfa ?></span>
                <?php if ($sayfa < $sonSayfa): ?>
                    <a class="rg-dis" href="<?= e(kose_liste_yolu($sayfa + 1)) ?>">Daha eski &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
