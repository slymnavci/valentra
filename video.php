<?php
declare(strict_types=1);

/**
 * Video haberler: videosu olan haberler bir arada.
 *
 * Site sahibinin istegi (22.10.2026; 30.10.2026'da menu ve ana sayfa
 * degistigi icin yeniden yazildi): videolu haberler kendi konularinda da
 * kaliyor, okuyucu videolari burada bir arada izleyebiliyor. Ilk sayfada
 * en yeni video hemen oynatilabilir; digerleri kapak gorseliyle kart.
 * Ustte konuya gore sekmeler (?k=spor), yalnizca videosu olan konular.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

// Ziyaretciye bir dakikalik kopya (bkz. includes/sayfa_onbellek.php).
sayfa_onbellek(['k', 'sayfa']);
require_once __DIR__ . '/includes/seo.php';

$sayilar  = haber_videolu_sayilari();
$kategori = (string) ($_GET['k'] ?? '');
$kategori = $kategori !== '' && isset($sayilar[$kategori]) ? $kategori : '';
$sayfa    = max(1, (int) ($_GET['sayfa'] ?? 1));
$adet     = 12;
$toplam   = $sayilar[$kategori] ?? 0;
$sonSayfa = max(1, (int) ceil($toplam / $adet));
$haberler = haber_videolular($adet, ($sayfa - 1) * $adet, $kategori);

// Oynatici yalnizca ilk sayfada: sonraki sayfalar duz liste.
$oneCikan = $sayfa === 1 && $haberler !== [] ? array_shift($haberler) : null;

// Sekmeler: haber akisi sirasiyla, videosu olan basliklar.
$sekmeler = [];

foreach (haber_akis_kategorileri() as $k) {
    if (($sayilar[$k['slug']] ?? 0) > 0) {
        $sekmeler[$k['slug']] = $k['ad'];
    }
}

$kategoriAdi   = $sekmeler[$kategori] ?? '';
$aktifKategori = 'video';
$sayfaBasligi  = ($kategoriAdi !== '' ? $kategoriAdi . ' Videoları' : 'Video Haberler') . ' — Valentra';
$sayfaAciklama = 'Güncel haberlerin videoları: Türkiye ve dünyadan gelişmeleri izleyin.';
$seoAdres      = site_adresi() . video_yolu($kategori, $sayfa);

if ($sayfa > 1) {
    $seoRobots = 'noindex, follow';
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1><?= e($kategoriAdi !== '' ? $kategoriAdi . ' Videoları' : 'Video Haberler') ?></h1>
    <p>Videosu olan haberler bir arada. Her haber kendi konusunda da yer alıyor.</p>
</div>

<?php if (count($sekmeler) > 1): ?>
    <nav class="gazete-sekmeler video-sekmeler" aria-label="Video konuları">
        <a href="<?= e(video_yolu()) ?>"<?= $kategori === '' ? ' class="aktif" aria-current="page"' : '' ?>>Tümü</a>
        <?php foreach ($sekmeler as $slug => $ad): ?>
            <a href="<?= e(video_yolu($slug)) ?>"<?= $slug === $kategori ? ' class="aktif" aria-current="page"' : '' ?>><?= e($ad) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

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
                            src="<?= e(haber_video_adresi((string) $oneCikan['iframe_url'])) ?>"
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
                        <span><?= e(tarih_bicimle($oneCikan['yayin_tarihi'] ?? $oneCikan['olusturuldu'])) ?></span>
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
                        <a href="<?= e(video_yolu($kategori, $sayfa - 1)) ?>">&larr; Önceki</a>
                    <?php endif; ?>
                    <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
                    <?php if ($sayfa < $sonSayfa): ?>
                        <a href="<?= e(video_yolu($kategori, $sayfa + 1)) ?>">Sonraki &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
