<?php
declare(strict_types=1);

/**
 * Haber detay sayfasi. Sadece yayinda olan haberler acilir;
 * taslak veya reddedilmis kayitlar 404 doner.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim((string) ($_GET['h'] ?? ''));
$haber = $slug !== '' ? haber_yayinda_bul($slug) : null;

if ($haber === null) {
    http_response_code(404);
    $sayfaBasligi = 'Haber bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Haber bulunamadı.</strong>
        Aradığınız haber yayından kaldırılmış veya adres hatalı olabilir.
        <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$sayfaBasligi  = $haber['baslik'] . ' — Valentra';
$sayfaAciklama = $haber['ozet'];
$aktifKategori = (string) ($haber['kategori_slug'] ?? '');

// Panelde "hangi haberler okundu" listesini besler; sayfa_ust.php
// bu degiskeni gorurse ziyareti habere bagliyor.
$ziyaretHaberId = (int) $haber['id'];

// Arama motoruna bunun bir haber oldugunu acikca soyle; yayim tarihi
// ve gorsel de semaya giriyor.
require_once __DIR__ . '/includes/seo.php';

$seoTur  = 'article';
$seoSema = seo_haber_semasi($haber);

$haberGorseli = guvenli_url((string) ($haber['gorsel_url'] ?? ''));

if ($haberGorseli !== '') {
    $seoGorsel = $haberGorseli;
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<article class="detay">
    <?php if (!empty($haber['kategori_slug'])): ?>
        <a class="etiket" href="/kategori.php?k=<?= e($haber['kategori_slug']) ?>">
            <?= e($haber['kategori_adi']) ?>
        </a>
    <?php else: ?>
        <?php $etiketler = etiketleri_coz($haber['etiketler']); ?>
        <?php if ($etiketler !== []): ?>
            <span class="etiket">#<?= e($etiketler[0]) ?></span>
        <?php endif; ?>
    <?php endif; ?>

    <h1><?= e($haber['baslik']) ?></h1>

    <div class="kunye">
        <span><?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></span>
        <?php if ($haber['kaynak_adi'] !== ''): ?>
            <span>&middot; Kaynak: <?= e($haber['kaynak_adi']) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($haber['gorsel_url'])): ?>
        <img src="<?= e(guvenli_url($haber['gorsel_url'])) ?>" alt="" style="border-radius:8px;margin-bottom:22px;">
    <?php endif; ?>

    <?php if ($haber['ozet'] !== ''): ?>
        <p class="spot"><?= e($haber['ozet']) ?></p>
    <?php endif; ?>

    <div class="icerik">
        <?php foreach (preg_split('/\n\s*\n/u', trim($haber['icerik'])) ?: [] as $paragraf): ?>
            <p><?= nl2br(e(trim($paragraf))) ?></p>
        <?php endforeach; ?>
    </div>

    <?php $iframeUrl = guvenli_url((string) ($haber['iframe_url'] ?? '')); ?>
    <?php if ($iframeUrl !== ''): ?>
        <div class="gomulu-icerik">
            <iframe
                src="<?= e($iframeUrl) ?>"
                title="<?= e($haber['baslik']) ?>"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="fullscreen; autoplay; encrypted-media; picture-in-picture"
                allowfullscreen>
            </iframe>
        </div>
    <?php endif; ?>

    <?php if ($haber['kaynak_url'] !== ''): ?>
        <div class="kaynak-kutusu">
            Bu haber,
            <?= $haber['kaynak_adi'] !== ''
                    ? '<strong>' . e($haber['kaynak_adi']) . '</strong> kaynağında'
                    : 'kaynağında' ?>
            yayımlanan içerikten derlenmiştir.
            <a href="<?= e(guvenli_url($haber['kaynak_url'])) ?>" target="_blank" rel="noopener nofollow ugc">Orijinal habere git</a>
        </div>
    <?php endif; ?>

    <?php if ($etiketler !== []): ?>
        <div class="kart-alt" style="margin-top:20px;">
            <?php foreach ($etiketler as $etiket): ?>
                <span class="etiket">#<?= e($etiket) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
