<?php
declare(strict_types=1);

/**
 * Köşe yazısı sayfası ("Valentra Diyor ki…").
 *
 * Yalnizca yayindaki yazilar acilir; taslak ve reddedilenler 404.
 * Yazinin altinda dayandigi haberler listeleniyor: okuyucu yorumun
 * hangi olgulara dayandigini gorebilmeli.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kose.php';
require_once __DIR__ . '/includes/seo.php';

$slug = trim((string) ($_GET['y'] ?? ''));
$yazi = $slug !== '' ? kose_yayinda_bul($slug) : null;

if ($yazi === null) {
    http_response_code(404);
    $sayfaBasligi = 'Yazı bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Yazı bulunamadı.</strong>
        Aradığınız yazı yayından kaldırılmış veya adres hatalı olabilir.
        <p><a href="<?= e(kose_liste_yolu()) ?>" style="color:var(--vurgu);text-decoration:underline;">Tüm yazılar</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$dayanaklar = kose_dayanaklar((string) $yazi['haber_idleri']);
$gorsel     = kose_gorselleri([$yazi])[(int) $yazi['id']] ?? null;
$yazar      = yazar_yazinin($yazi);

// Yazarin diger yazilari: okuyucu yazarin arsivine buradan da ulassin.
$yazarinDigerleri = array_values(array_filter(
    kose_yazarin_yazilari($yazar['anahtar'], 1, 6)['yazilar'],
    static fn (array $y): bool => (int) $y['id'] !== (int) $yazi['id']
));
$yazarinDigerleri = array_slice($yazarinDigerleri, 0, 5);

// Ayni gunun diger yazilari: okuyucu gunun gundeminde gezinebilsin.
$ayniGun = array_values(array_filter(
    kose_gun_yazilari((string) $yazi['gun']),
    static fn (array $y): bool => (int) $y['id'] !== (int) $yazi['id']
));

$aktifKategori = 'kose';
$sayfaBasligi  = $yazi['baslik'] . ' — ' . $yazar['ad'] . ' | Valentra';
$sayfaAciklama = (string) $yazi['ozet'];
$seoAdres      = site_adresi() . kose_yolu((string) $yazi['slug']);
$seoTur        = 'article';
$govdeSinifi   = 'okuma';

if ($gorsel !== null) {
    $seoGorsel = str_starts_with($gorsel['src'], '/') ? site_adresi() . $gorsel['src'] : $gorsel['src'];
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="ana-duzen">
<div class="ana-kolon">

<article class="detay kose-detay">
    <a class="kose-ust-etiket" href="<?= e(kose_liste_yolu()) ?>">Valentra Diyor ki…</a>

    <h1><?= e((string) $yazi['baslik']) ?></h1>

    <div class="kunye kose-kunye">
        <a class="kose-yazar" href="<?= e(yazar_yolu($yazar['anahtar'])) ?>">
            <?= yazar_portre_svg($yazar['anahtar'], 44) ?>
            <span>
                <span class="hazirlayan"><?= e($yazar['ad']) ?></span>
                <span class="yazar-alan"><?= e($yazar['alan']) ?></span>
            </span>
        </a>
        <span>&middot; <?= e(tarih_bicimle((string) $yazi['gun'], false)) ?></span>
        <?php if ((string) $yazi['gundem'] !== ''): ?>
            <span>&middot; <?= e((string) $yazi['gundem']) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($gorsel !== null): ?>
        <figure class="kose-gorsel">
            <img src="<?= e($gorsel['orta']) ?>" alt="" referrerpolicy="no-referrer"
                 onerror="this.closest('figure').remove()">
            <?php if ($gorsel['kaynak'] !== ''): ?>
                <figcaption>Görsel: <?= e(kaynak_gorunen_ad($gorsel['kaynak'])) ?></figcaption>
            <?php endif; ?>
        </figure>
    <?php endif; ?>

    <?php if ((string) $yazi['ozet'] !== ''): ?>
        <p class="spot"><?= e((string) $yazi['ozet']) ?></p>
    <?php endif; ?>

    <div class="icerik">
        <?= kose_icerik_html((string) $yazi['icerik']) ?>
    </div>

    <?php if ($dayanaklar !== []): ?>
        <section class="kose-dayanak">
            <h2>Yazının dayandığı haberler</h2>
            <ul>
                <?php foreach ($dayanaklar as $d): ?>
                    <li>
                        <a href="<?= e($d['adres']) ?>"
                           <?= $d['ic'] ? '' : 'target="_blank" rel="noopener nofollow"' ?>>
                            <?= e($d['baslik']) ?>
                        </a>
                        <?php if ($d['kaynak'] !== ''): ?>
                            <span>&middot; <?= e($d['kaynak']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php /* Kaldirilmamali: okuyucu yorumun nasil hazirlandigini bilmeli ve
             genel degerlendirmeyi kisiye ozel danismanlik sanmamali. */ ?>
    <?php /* "Editor onayindan gecmistir" yalnizca gercekten onaylanan yazida:
             otomatik yayinlanan yazida (onaylayan bos) bu cumle dogru olmaz. */ ?>
    <p class="kose-not">
        Bu yazı, günün haberlerinden yapay zekâ desteğiyle hazırlanmış<?=
            !empty($yazi['onaylayan_id']) ? ' ve Valentra editörlerinin onayından geçmiştir' : 'tır' ?>.
        Değerlendirmeler genel bilgilendirme amaçlıdır; yatırım tavsiyesi ya da
        kişiye özel danışmanlık yerine geçmez.
    </p>
</article>

<?php if ($yazarinDigerleri !== []): ?>
    <div class="bolum-basligi">
        <h2><?= e($yazar['ad']) ?> — diğer yazıları</h2>
        <span class="cizgi"></span>
    </div>

    <ul class="kose-liste">
        <?php foreach ($yazarinDigerleri as $diger): ?>
            <li>
                <a href="<?= e(kose_yolu((string) $diger['slug'])) ?>">
                    <span class="kose-gundem"><?= e(tarih_bicimle((string) $diger['gun'], false)) ?></span>
                    <span class="kose-baslik"><?= e((string) $diger['baslik']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="piyasa-bag"><a href="<?= e(yazar_yolu($yazar['anahtar'])) ?>">Tüm yazıları &rarr;</a></p>
<?php endif; ?>

<?php if ($ayniGun !== []): ?>
    <div class="bolum-basligi">
        <h2>Günün diğer yazıları</h2>
        <span class="cizgi"></span>
    </div>

    <ul class="kose-liste">
        <?php foreach ($ayniGun as $diger): ?>
            <li>
                <a href="<?= e(kose_yolu((string) $diger['slug'])) ?>">
                    <span class="kose-gundem"><?= e(yazar_yazinin($diger)['ad']) ?></span>
                    <span class="kose-baslik"><?= e((string) $diger['baslik']) ?></span>
                    <?php if ((string) $diger['ozet'] !== ''): ?>
                        <span class="kose-ozet"><?= e((string) $diger['ozet']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p style="margin:22px 0 34px;">
    <a class="rg-dis" href="<?= e(kose_liste_yolu()) ?>">Tüm yazılar</a>
</p>

</div>

<?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
