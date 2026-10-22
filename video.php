<?php
declare(strict_types=1);

/**
 * Video haberler (22.10.2026, site sahibinin istegi).
 *
 * Videolu haberler konu sayfalarinda ve ana sayfada eskisi gibi; bu
 * sayfa yalnizca onlari bir araya getiriyor. Ilk sayfada en yeni video
 * hemen oynatilabilir; digerleri kapak gorseliyle kart olarak, video
 * haber sayfasinda metnin ustunde oynuyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$sayfa    = max(1, (int) ($_GET['sayfa'] ?? 1));
$adet     = 12;
$toplam   = haber_videolu_sayisi();
$sonSayfa = max(1, (int) ceil($toplam / $adet));
$haberler = haber_videolular($adet, ($sayfa - 1) * $adet);

// Oynatici yalnizca ilk sayfada: sonraki sayfalar duz liste.
$oneCikan = $sayfa === 1 && $haberler !== [] ? array_shift($haberler) : null;

$aktifKategori = 'video';
$sayfaBasligi  = 'Video Haberler — Valentra';
$sayfaAciklama = 'Güncel haberlerin videoları: Türkiye ve dünyadan gelişmeleri izleyin.';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Video Haberler</h1>
    <p>Videosu olan haberler bir arada. Her haber kendi konusunda da yer alıyor.</p>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php if ($oneCikan === null && $haberler === []): ?>
            <div class="bos-durum">
                <strong>Henüz videolu haber yok.</strong>
                Videosu olan haberler yayımlandıkça burada listelenecek.
            </div>
        <?php else: ?>
            <?php if ($oneCikan !== null): ?>
                <article class="video-one-cikan">
                    <div class="gomulu-icerik video">
                        <iframe
                            src="<?= e((string) $oneCikan['iframe_url']) ?>"
                            title="<?= e($oneCikan['baslik']) ?> — video"
                            loading="lazy"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allow="fullscreen; encrypted-media; picture-in-picture"
                            allowfullscreen>
                        </iframe>
                    </div>

                    <?php if (!empty($oneCikan['kategori_adi'])): ?>
                        <span class="etiket"><?= e($oneCikan['kategori_adi']) ?></span>
                    <?php endif; ?>
                    <h2><a href="<?= e(haber_yolu((string) $oneCikan['slug'])) ?>"><?= e($oneCikan['baslik']) ?></a></h2>
                    <p class="ozet"><?= e($oneCikan['ozet']) ?></p>
                    <div class="kart-alt">
                        <span><?= e(tarih_bicimle($oneCikan['yayin_tarihi'])) ?></span>
                        <?php if ($oneCikan['kaynak_adi'] !== ''): ?>
                            <span>&middot; <?= e(kaynak_gorunen_ad((string) $oneCikan['kaynak_adi'])) ?></span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endif; ?>

            <?php if ($haberler !== []): ?>
                <div class="bolum-basligi">
                    <h2><?= $sayfa > 1 ? 'Video Haberler' : 'Diğer Videolar' ?></h2>
                    <span class="cizgi"></span>
                </div>

                <?php $izgaraHaberleri = $haberler; ?>
                <?php require __DIR__ . '/includes/kart_izgara.php'; ?>
            <?php endif; ?>

            <?php if ($sonSayfa > 1): ?>
                <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;">
                    <?php if ($sayfa > 1): ?>
                        <a href="<?= e(video_yolu($sayfa - 1)) ?>">&larr; Önceki</a>
                    <?php endif; ?>
                    <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
                    <?php if ($sayfa < $sonSayfa): ?>
                        <a href="<?= e(video_yolu($sayfa + 1)) ?>">Sonraki &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
