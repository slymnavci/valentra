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
$sayfaAciklama = 'Valentra\'nın her gün gündemin ve dünyanın en önemli konularını '
               . 'değerlendirdiği köşe yazıları.';
$seoAdres      = site_adresi() . kose_liste_yolu($sayfa);

// Liste sayfalarinin 2., 3. ... sayfalari Google'a sunulmuyor (26.10.2026):
// icerikleri haberlerin kendisinde; 'follow' ile baglantilar yine taraniyor.
if ($sayfa > 1) {
    $seoRobots = 'noindex, follow';
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Valentra Diyor ki…</h1>
    <p>
        Yazarlarımız her gün kendi alanlarında gündemin en önemli
        konularını değerlendiriyor.
    </p>
</div>

<?php // Yazarlar seridi: her yazarin sayfasina (gecmis yazilari). ?>
<nav class="yazar-seridi" aria-label="Yazarlar">
    <?php foreach (array_keys(KOSE_YAZARLARI) as $anahtar): ?>
        <?php $y = yazar_bul($anahtar); ?>
        <a href="<?= e(yazar_yolu($anahtar)) ?>">
            <?= yazar_portre_svg($anahtar, 64) ?>
            <span class="yazar-ad"><?= e($y['ad']) ?></span>
            <span class="yazar-alan"><?= e($y['alan']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php if ($gunler === []): ?>
            <div class="bos-durum">
                <strong>Henüz yayımlanmış yazı yok.</strong>
                Günün yazıları editör onayından geçtikten sonra burada görünecek.
            </div>
        <?php endif; ?>

        <?php $koseGorselleri = kose_gorselleri(array_merge([], ...array_values($gunler))); ?>
        <?php foreach ($gunler as $gun => $yazilar): ?>
            <?php $zaman = strtotime($gun); ?>
            <div class="bolum-basligi">
                <h2><?= e(tarih_bicimle($gun, false) . ($zaman !== false ? ' ' . $gunAdlari[(int) date('w', $zaman)] : '')) ?></h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($yazilar as $yazi): ?>
                    <li>
                        <a href="<?= e(kose_yolu((string) $yazi['slug'])) ?>"
                           class="<?= isset($koseGorselleri[(int) $yazi['id']]) ? 'gorselli' : '' ?>">
                            <?php if (isset($koseGorselleri[(int) $yazi['id']])): ?>
                                <img class="kose-kucuk" src="<?= e($koseGorselleri[(int) $yazi['id']]['src']) ?>" alt=""
                                     loading="lazy" referrerpolicy="no-referrer"
                                     onerror="this.parentElement.classList.remove('gorselli');this.remove()">
                            <?php endif; ?>
                            <span class="kose-gundem"><?= e(yazar_yazinin($yazi)['ad']) ?><?=
                                (string) $yazi['gundem'] !== '' ? ' · ' . e((string) $yazi['gundem']) : '' ?></span>
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
