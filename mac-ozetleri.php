<?php
declare(strict_types=1);

/**
 * Mac ozetleri (04.11.2026; bkz. includes/mac_ozeti.php).
 *
 *   /mac-ozetleri              son ozetler (ValSpor > Mac Ozetleri), ?ara=galatasaray
 *   /mac-ozeti/{slug}          tek ozet: YouTube oynaticisi, VideoObject
 *
 * Ana sayfada ve haber akisinda yok; ValSpor sekmesi, ValSpor on sayfasi
 * seridi, site haritasi ve Google'in video sonuclari.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

sayfa_onbellek(['o', 'sayfa', 'ara']);

require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/gazeteler.php';
require_once __DIR__ . '/includes/mac_ozeti.php';

$slug = trim((string) ($_GET['o'] ?? ''));
$aktifKategori = gazete_bolum_anahtari('valspor', 'mac-ozetleri');

if ($slug !== '') {
    $ozet = mac_ozeti_bul($slug);

    if ($ozet === null) {
        http_response_code(404);
        $sayfaBasligi = 'Maç özeti bulunamadı — Valentra';
        require __DIR__ . '/includes/sayfa_ust.php';
        echo gazete_ust_html('valspor', 'mac-ozetleri');
        echo '<div class="bos-durum"><strong>Maç özeti bulunamadı.</strong> <p><a href="' . e(mac_ozetleri_yolu()) . '" style="color:var(--vurgu);text-decoration:underline;">Bütün maç özetleri</a></p></div>';
        require __DIR__ . '/includes/sayfa_alt.php';
        exit;
    }

    $digerler = array_values(array_filter(mac_ozeti_liste(9), static fn (array $o): bool => (int) $o['id'] !== (int) $ozet['id']));
    $digerler = array_slice($digerler, 0, 8);

    // Skor yoksa (Premier League "Arsenal v Chelsea" bicimi) takimlar arasinda tire.
    $mac = (string) $ozet['ev'] !== ''
        ? $ozet['ev'] . ((string) $ozet['skor'] !== '' ? ' ' . $ozet['skor'] . ' ' : ' - ') . $ozet['deplasman']
        : (string) $ozet['baslik'];
    $sayfaBasligi  = mb_strlen($mac . ' maç özeti izle') <= 52 ? $mac . ' maç özeti izle — Valentra' : $mac . ' maç özeti';
    $sayfaAciklama = kisalt(($ozet['ev'] !== '' ? $mac . ' maçının özetini ve gollerini izleyin. ' : '') . ($ozet['aciklama'] !== '' ? $ozet['aciklama'] : $ozet['baslik']), 155);
    $seoAdres  = site_adresi() . mac_ozeti_yolu((string) $ozet['slug']);
    $seoTur    = 'video.other';
    $seoGorsel = mac_ozeti_gorsel($ozet);
    $seoSema   = mac_ozeti_semasi($ozet);
    $govdeSinifi = 'okuma';

    require __DIR__ . '/includes/sayfa_ust.php';
    require_once __DIR__ . '/includes/paylas.php';
    ?>
    <?= gazete_ust_html('valspor', 'mac-ozetleri') ?>
    <article class="mozet-sayfa">
        <span class="etiket">Maç Özeti</span>
        <h1><?= e((string) $ozet['baslik']) ?></h1>
        <div class="kunye">
            <?php if ((string) $ozet['kanal'] !== ''): ?><span>Yayıncı: <?= e((string) $ozet['kanal']) ?></span> <span>&middot;</span><?php endif; ?>
            <span><?= e(tarih_bicimle((string) $ozet['yayin'])) ?></span>
        </div>
        <div class="mozet-oynatici">
            <iframe src="https://www.youtube-nocookie.com/embed/<?= e((string) $ozet['video_id']) ?>?rel=0&amp;modestbranding=1"
                    title="<?= e((string) $ozet['baslik']) ?>" loading="lazy"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
        </div>
        <p class="mozet-kaynak">
            Video <?= (string) $ozet['kanal'] !== '' ? e((string) $ozet['kanal']) . ' tarafından ' : '' ?>YouTube'da yayımlanmıştır.
            <a href="https://www.youtube.com/watch?v=<?= e((string) $ozet['video_id']) ?>" target="_blank" rel="noopener nofollow">YouTube'da izle ↗</a>
        </p>
        <?= paylas_cubugu_html((string) $ozet['baslik'], mac_ozeti_yolu((string) $ozet['slug']), 'alt') ?>

        <?php if ($digerler !== []): ?>
            <div class="bolum-basligi" style="margin-top:28px;"><h2>Diğer maç özetleri</h2><span class="cizgi"></span></div>
            <div class="mozet-izgara"><?= implode('', array_map('mac_ozeti_kart_html', $digerler)) ?></div>
        <?php endif; ?>
    </article>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$sayfa = max(1, (int) ($_GET['sayfa'] ?? 1));
$ara   = mb_substr(trim((string) ($_GET['ara'] ?? '')), 0, 40);
$toplam = mac_ozeti_sayisi($ara);
$sonSayfa = max(1, (int) ceil($toplam / MAC_OZETI_SAYFA));
$liste = mac_ozeti_liste(MAC_OZETI_SAYFA, ($sayfa - 1) * MAC_OZETI_SAYFA, $ara);

$sayfaBasligi  = 'Maç Özetleri izle: Süper Lig ve Avrupa maçlarının özetleri — Valentra';
$sayfaAciklama = 'Süper Lig, Şampiyonlar Ligi ve Avrupa maçlarının özet videoları ve golleri. Maç biter bitmez yayıncı kanallarından, tek sayfada.';
$seoAdres = site_adresi() . mac_ozetleri_yolu($sayfa);

if ($sayfa > 1 || $ara !== '') {
    $seoRobots = $ara !== '' ? 'noindex, follow' : 'index, follow, max-image-preview:large';
}

require __DIR__ . '/includes/sayfa_ust.php';
?>
<?= gazete_ust_html('valspor', 'mac-ozetleri') ?>
<section class="mozet-liste-sayfa">
    <div class="kategori-basligi gazete-bolum-basligi">
        <h1>Maç Özetleri</h1>
        <p>Maç biter bitmez yayıncı kanallarının özet videoları ve goller. İzlemek için videoya dokunun.</p>
    </div>
    <form method="get" action="<?= e(mac_ozetleri_yolu()) ?>" class="mozet-ara">
        <label class="gizli-etiket" for="mozet-ara">Takım ara</label>
        <input type="search" id="mozet-ara" name="ara" value="<?= e($ara) ?>" placeholder="Takım ara: Galatasaray, Fenerbahçe…">
        <button type="submit">Ara</button>
    </form>
    <?php if ($liste === []): ?>
        <div class="bos-durum"><?= $ara !== '' ? 'Bu takımın özeti henüz yok.' : 'Henüz maç özeti yok. Maçlar bittikçe özetler burada olacak.' ?></div>
    <?php else: ?>
        <div class="mozet-izgara"><?= implode('', array_map('mac_ozeti_kart_html', $liste)) ?></div>
    <?php endif; ?>
    <?php if ($sonSayfa > 1): ?>
        <nav class="kart-alt" style="justify-content:center;margin:28px 0;gap:14px;">
            <?php if ($sayfa > 1): ?><a href="<?= e(mac_ozetleri_yolu($sayfa - 1) . ($ara !== '' ? (str_contains(mac_ozetleri_yolu($sayfa - 1), '?') ? '&' : '?') . 'ara=' . rawurlencode($ara) : '')) ?>">&larr; Önceki</a><?php endif; ?>
            <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
            <?php if ($sayfa < $sonSayfa): ?><a href="<?= e(mac_ozetleri_yolu($sayfa + 1) . ($ara !== '' ? '&ara=' . rawurlencode($ara) : '')) ?>">Sonraki &rarr;</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
