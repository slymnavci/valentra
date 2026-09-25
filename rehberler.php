<?php
declare(strict_types=1);

/**
 * Uygulama rehberleri listesi: /rehberler
 *
 * Konuya gore gruplu. Yayinda rehber yoksa bos durum mesaji; menu
 * baglantisi da o durumda gizleniyor (bkz. site_menusu).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/rehberler.php';
require_once __DIR__ . '/includes/seo.php';

$gruplar = [];

foreach (rehber_yayindakiler() as $r) {
    $gruplar[(string) $r['konu'] !== '' ? (string) $r['konu'] : 'Genel'][] = $r;
}

$aktifKategori = 'rehberler';
$sayfaBasligi  = 'Uygulama Rehberleri — Valentra';
$sayfaAciklama = 'Vergi, muhasebe ve finansal yönetimde adım adım uygulama rehberleri: '
               . 'formüller, sayısal örnekler ve muhasebe kayıtları.';
$seoAdres      = site_adresi() . rehberler_yolu();

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Uygulama Rehberleri</h1>
    <p>
        Formülü, adım adım örneği ve muhasebe kaydıyla; işte uygulayabileceğiniz
        kalıcı rehberler. Hesaplamaları <a href="<?= e(araclar_yolu()) ?>" style="color:var(--vurgu);text-decoration:underline;">Araçlar</a>
        sayfasında kendi rakamlarınızla deneyebilirsiniz.
    </p>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php if ($gruplar === []): ?>
            <div class="bos-durum">
                <strong>Rehberler hazırlanıyor.</strong>
                İlk rehberler editör kontrolünden geçtikten sonra burada yayımlanacak.
            </div>
        <?php endif; ?>

        <?php foreach ($gruplar as $konu => $rehberler): ?>
            <div class="bolum-basligi">
                <h2><?= e($konu) ?></h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($rehberler as $r): ?>
                    <li>
                        <a href="<?= e(rehber_yolu((string) $r['slug'])) ?>">
                            <span class="kose-baslik"><?= e((string) $r['baslik']) ?></span>
                            <?php if ((string) $r['ozet'] !== ''): ?>
                                <span class="kose-ozet"><?= e((string) $r['ozet']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
