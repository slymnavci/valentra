<?php
declare(strict_types=1);

/**
 * Site içi arama: /ara?q=...
 *
 * Arama motorlari bu sayfayi dizine almamali (sonsuz sayida bos ya da
 * tekrar eden sayfa uretir); robots etiketi asagida.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/arama.php';
require_once __DIR__ . '/includes/rehberler.php';

$sorgu     = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, ARAMA_EN_UZUN);
$kelimeler = arama_kelimeleri($sorgu);

$haberler = $koseler = $pratikler = $rgMaddeleri = $rehberler = [];
$hata     = false;

if ($kelimeler !== []) {
    try {
        $haberler    = arama_haberler($kelimeler);
        $koseler     = arama_kose_yazilari($kelimeler);
        $pratikler   = arama_pratik($kelimeler);
        $rgMaddeleri = arama_resmi_gazete($kelimeler);
        $rehberler   = arama_rehberler($kelimeler);
    } catch (PDOException $e) {
        error_log('[valentra] arama: ' . $e->getMessage());
        $hata = true;
    }
}

$toplam = count($haberler) + count($koseler) + count($pratikler) + count($rgMaddeleri) + count($rehberler);

$aktifKategori = 'ara';
$sayfaBasligi  = ($sorgu !== '' ? '"' . $sorgu . '" araması' : 'Arama') . ' — Valentra';
$seoRobots     = 'noindex, follow';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Arama</h1>

    <form class="arama-buyuk" action="<?= e(arama_yolu()) ?>" method="get" role="search">
        <label class="gizli-etiket" for="arama-sayfa-q">Aranacak kelime</label>
        <input id="arama-sayfa-q" type="search" name="q" value="<?= e($sorgu) ?>"
               placeholder="Örn. KDV tevkifatı, asgari ücret, e-defter"
               maxlength="<?= ARAMA_EN_UZUN ?>" autocomplete="off" <?= $sorgu === '' ? 'autofocus' : '' ?>>
        <button type="submit">Ara</button>
    </form>

    <?php if ($sorgu !== ''): ?>
        <p>
            <?php if ($hata): ?>
                Arama şu anda yapılamıyor. Biraz sonra yeniden deneyin.
            <?php elseif ($kelimeler === []): ?>
                En az <?= ARAMA_EN_KISA ?> harfli bir kelime yazın.
            <?php else: ?>
                “<?= e($sorgu) ?>” için <?= $toplam ?> sonuç<?= $toplam >= 30 ? ' (en yeniler)' : '' ?>.
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<div class="arama-sonuclari">

    <?php if ($rehberler !== []): ?>
        <section>
            <div class="bolum-basligi">
                <h2>Rehberler</h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($rehberler as $g): ?>
                    <li>
                        <a href="<?= e(rehber_yolu((string) $g['slug'])) ?>">
                            <?php if ((string) $g['konu'] !== ''): ?>
                                <span class="kose-gundem"><?= e((string) $g['konu']) ?></span>
                            <?php endif; ?>
                            <span class="kose-baslik"><?= e((string) $g['baslik']) ?></span>
                            <?php if ((string) $g['ozet'] !== ''): ?>
                                <span class="kose-ozet"><?= e((string) $g['ozet']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($pratikler !== []): ?>
        <section>
            <div class="bolum-basligi">
                <h2>Pratik Bilgiler</h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($pratikler as $p): ?>
                    <li>
                        <a href="<?= e(pratik_bilgi_yolu((string) $p['anahtar'])) ?>">
                            <?php if ((string) $p['donem'] !== ''): ?>
                                <span class="kose-gundem"><?= e((string) $p['donem']) ?></span>
                            <?php endif; ?>
                            <span class="kose-baslik"><?= e((string) $p['baslik']) ?></span>
                            <span class="kose-ozet"><?= e(mb_strimwidth(strip_tags((string) $p['deger']), 0, 180, '…')) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($haberler !== []): ?>
        <section>
            <div class="bolum-basligi">
                <h2>Haberler</h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($haberler as $h): ?>
                    <li>
                        <a href="<?= e(haber_yolu((string) $h['slug'])) ?>">
                            <span class="kose-gundem">
                                <?= e(trim(($h['kategori_adi'] ?? '') . ' · ' . tarih_bicimle((string) $h['yayin_tarihi'], false), ' ·')) ?>
                            </span>
                            <span class="kose-baslik"><?= e((string) $h['baslik']) ?></span>
                            <?php if ((string) $h['ozet'] !== ''): ?>
                                <span class="kose-ozet"><?= e(mb_strimwidth((string) $h['ozet'], 0, 220, '…')) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($koseler !== []): ?>
        <section>
            <div class="bolum-basligi">
                <h2>Valentra Diyor ki…</h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($koseler as $k): ?>
                    <li>
                        <a href="<?= e(kose_yolu((string) $k['slug'])) ?>">
                            <span class="kose-gundem">
                                <?= e(trim((string) $k['gundem'] . ' · ' . tarih_bicimle((string) $k['gun'], false), ' ·')) ?>
                            </span>
                            <span class="kose-baslik"><?= e((string) $k['baslik']) ?></span>
                            <?php if ((string) $k['ozet'] !== ''): ?>
                                <span class="kose-ozet"><?= e((string) $k['ozet']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($rgMaddeleri !== []): ?>
        <section>
            <div class="bolum-basligi">
                <h2>Resmî Gazete</h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($rgMaddeleri as $r): ?>
                    <li>
                        <a href="<?= e((string) $r['url']) ?>" target="_blank" rel="noopener">
                            <span class="kose-gundem">
                                <?= e(trim(tarih_bicimle((string) $r['tarih'], false) . ' · ' . (string) $r['bolum'], ' ·')) ?>
                            </span>
                            <span class="kose-baslik"><?= e((string) $r['baslik']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($kelimeler !== [] && !$hata && $toplam === 0): ?>
        <div class="bos-durum">
            <strong>Sonuç bulunamadı.</strong>
            Daha kısa ya da farklı bir kelimeyle deneyin; örneğin “tevkifat” yerine “KDV”.
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
