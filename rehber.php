<?php
declare(strict_types=1);

/**
 * Tek rehber: /rehber/{slug}
 *
 * Yalnizca yayindakiler acilir. Panelde oturumu acik yonetici
 * ?onizleme=1 ile taslagi da gorebilir (onaydan once sayfadaki son
 * haliyle okumak icin); onizleme arama motorlarina kapali.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/rehberler.php';
require_once __DIR__ . '/includes/seo.php';

$slug   = trim((string) ($_GET['r'] ?? ''));
$rehber = $slug !== '' ? rehber_slug_bul($slug) : null;
$onizleme = false;

if ($rehber !== null && $rehber['durum'] !== REHBER_YAYINDA) {
    $rehber = null;

    if (isset($_GET['onizleme'])) {
        require_once __DIR__ . '/includes/auth.php';
        oturum_baslat();

        if (aktif_yonetici_id() > 0) {
            $rehber   = rehber_slug_bul($slug);
            $onizleme = true;
        }
    }
}

if ($rehber === null) {
    http_response_code(404);
    $sayfaBasligi = 'Rehber bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Rehber bulunamadı.</strong>
        Aradığınız rehber yayından kaldırılmış veya adres hatalı olabilir.
        <p><a href="<?= e(rehberler_yolu()) ?>" style="color:var(--vurgu);text-decoration:underline;">Tüm rehberler</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$icindekiler = rehber_icindekiler((string) $rehber['icerik']);
$digerleri   = array_values(array_filter(
    rehber_yayindakiler(),
    static fn (array $r): bool => (int) $r['id'] !== (int) $rehber['id']
));

$yayinTarihi = (string) ($rehber['yayin_tarihi'] ?: $rehber['olusturuldu']);
$guncellendi = (string) $rehber['guncellendi'];
$revizyon    = strtotime($guncellendi) - strtotime($yayinTarihi) > 86400;

$aktifKategori = 'rehberler';
$sayfaBasligi  = $rehber['baslik'] . ' | Valentra Rehber';
$sayfaAciklama = (string) $rehber['ozet'];
$seoAdres      = site_adresi() . rehber_yolu((string) $rehber['slug']);
$seoTur        = 'article';
$seoSema       = seo_rehber_semasi($rehber);
$seoRobots     = $onizleme ? 'noindex, nofollow' : '';
$govdeSinifi   = 'okuma';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="ana-duzen">
<div class="ana-kolon">

<?php if ($onizleme): ?>
    <div class="uyari-serit">
        Önizleme: bu rehber henüz <strong>taslak</strong>, ziyaretçiler göremez.
        Panelde okuyup onaylayınca yayına çıkar.
    </div>
<?php endif; ?>

<article class="detay rehber-detay">
    <nav class="rehber-iz" aria-label="Konum">
        <a href="<?= e(rehberler_yolu()) ?>">Rehberler</a>
        <?php if ((string) $rehber['konu'] !== ''): ?>
            <span>&rsaquo;</span> <span><?= e((string) $rehber['konu']) ?></span>
        <?php endif; ?>
    </nav>

    <h1><?= e((string) $rehber['baslik']) ?></h1>

    <div class="kunye rehber-kunye">
        <span>Hazırlayan: <strong><?= e((string) $rehber['hazirlayan']) ?></strong></span>
        <?php if ((string) ($rehber['kontrol_eden'] ?? '') !== ''): ?>
            <span>&middot; Kontrol eden: <strong><?= e((string) $rehber['kontrol_eden']) ?></strong></span>
        <?php endif; ?>
        <span>&middot; <?= $revizyon ? 'Son güncelleme: ' . e(tarih_bicimle($guncellendi, false))
                                     : e(tarih_bicimle($yayinTarihi, false)) ?></span>
        <span>&middot; <?= rehber_okuma_suresi((string) $rehber['icerik']) ?> dk okuma</span>
    </div>

    <?php if ((string) $rehber['ozet'] !== ''): ?>
        <p class="spot"><?= e((string) $rehber['ozet']) ?></p>
    <?php endif; ?>

    <?php if (count($icindekiler) >= 3): ?>
        <nav class="rehber-icindekiler" aria-label="İçindekiler">
            <strong>İçindekiler</strong>
            <ol>
                <?php foreach ($icindekiler as $b): ?>
                    <li><a href="#<?= e($b['id']) ?>"><?= e($b['baslik']) ?></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <div class="icerik rehber-icerik">
        <?= rehber_icerik_html((string) $rehber['icerik']) ?>
    </div>

    <?php /* Kaldirilmamali: rehber genel bilgidir; kisiye ozel danismanlik
             yerine gecmez ve mevzuat degisebilir. */ ?>
    <p class="kose-not">
        Bu rehber genel bilgilendirme amaçlıdır; örneklerdeki tutarlar
        varsayımsaldır. Mevzuat ve oranlar değişebilir; işleminize
        uygulamadan önce güncel düzenlemeyi ve mali müşavirinizi esas alın.
    </p>
</article>

<?php if ($digerleri !== []): ?>
    <div class="bolum-basligi">
        <h2>Diğer rehberler</h2>
        <span class="cizgi"></span>
    </div>

    <ul class="kose-liste">
        <?php foreach ($digerleri as $d): ?>
            <li>
                <a href="<?= e(rehber_yolu((string) $d['slug'])) ?>">
                    <?php if ((string) $d['konu'] !== ''): ?>
                        <span class="kose-gundem"><?= e((string) $d['konu']) ?></span>
                    <?php endif; ?>
                    <span class="kose-baslik"><?= e((string) $d['baslik']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
