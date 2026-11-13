<?php
declare(strict_types=1);

/**
 * Kim ne yazdı? (13.11.2026; bkz. includes/kim_ne_yazdi.php)
 *
 *   /kim-ne-yazdi        günün olayları: kaç yayın yazdı
 *   /kim-ne-yazdi/N      bir olay: her yayının kendi başlığı, kısa özeti,
 *                        saati ve kendi sitesine bağlantısı
 *
 * Yayınlardan yalnızca başlık, kısa özet ve bağlantı gösterilir; haberin
 * metni ve fotoğrafı yayının kendi sitesindedir. Yayınlara eğilim etiketi
 * konmaz; sıra sabittir.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

sayfa_onbellek(['o']);

require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/kim_ne_yazdi.php';

$kimlik = (int) ($_GET['o'] ?? 0);
$olay   = $kimlik > 0 ? kny_olay($kimlik) : null;

if ($kimlik > 0 && $olay === null) {
    // Olay silinmis (4 gunden eski) ya da hic olmamis: listeye don.
    header('Location: ' . kny_yolu(), true, 302);
    exit;
}

$aktifKategori = 'kim-ne-yazdi';
// Baslik ve ozetler baska yayinlarin; arama motoruna sunulmuyor.
$seoRobots     = 'noindex, follow';

if ($olay !== null) {
    $sayfaBasligi  = $olay['baslik'] . ' — Kim ne yazdı? | Valentra';
    $sayfaAciklama = count($olay['yayinlar']) . ' yayın bu haberi nasıl verdi: her yayının kendi başlığı ve bağlantısı yan yana.';
    $seoAdres      = site_adresi() . kny_yolu($kimlik);
} else {
    $olaylar       = kny_olaylar();
    $sayfaBasligi  = 'Kim ne yazdı? Günün haberlerini hangi yayın nasıl verdi | Valentra';
    $sayfaAciklama = 'Günün öne çıkan haberleri ve her birini hangi yayının hangi başlıkla verdiği: Türkiye basınından yan yana başlıklar.';
    $seoAdres      = site_adresi() . kny_yolu();
}

require __DIR__ . '/includes/sayfa_ust.php';

$genelToplam = count(kny_etkin_genel_yayinlar());
?>

<?php if ($olay === null): ?>

    <div class="kategori-basligi">
        <h1>Kim ne yazdı?</h1>
        <p>Son <?= (int) KNY_PENCERE_SAAT ?> saatte en az <?= (int) KNY_EN_AZ_YAYIN ?> yayının verdiği haberler. Bir başlığa dokunun;
            o haberi hangi yayın hangi başlıkla vermiş, yan yana görün.</p>
    </div>

    <div class="ana-duzen tam">
        <div class="ana-kolon">
            <?php if ($olaylar === []): ?>
                <div class="bos-durum"><strong>Şu an karşılaştırılacak haber yok.</strong>
                    Yayınların başlıkları gün içinde toplandıkça burada görünecek.</div>
            <?php else: ?>
                <ol class="kny-liste">
                    <?php foreach ($olaylar as $o): ?>
                        <li class="kny-olay">
                            <a class="kny-olay-bag" href="<?= e(kny_yolu($o['kimlik'])) ?>">
                                <span class="kny-olay-sayi"><strong><?= count($o['yayinlar']) ?></strong> yayın</span>
                                <span class="kny-olay-govde">
                                    <span class="kny-olay-baslik"><?= e($o['baslik']) ?></span>
                                    <span class="kny-olay-kim"><?= e(implode(' · ', array_map(static fn (string $y): string => KNY_YAYINLARI[$y][0], $o['yayinlar']))) ?></span>
                                </span>
                                <span class="kny-olay-zaman"><?= e(kny_once($o['son'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <p class="kny-not">Başlıklar yayınların kendi beslemelerinden alınır ve değiştirilmeden gösterilir. Aynı olayı anlatan
                başlıklar otomatik eşleştirilir; eşleştirme hata yapabilir. Yayınlar herhangi bir görüşe göre sınıflandırılmaz.</p>
        </div>
    </div>

<?php else: ?>

    <div class="kategori-basligi kny-ust">
        <p class="kny-geri"><a href="<?= e(kny_yolu()) ?>">← Kim ne yazdı?</a></p>
        <h1><?= e($olay['baslik']) ?></h1>
        <p><strong><?= count($olay['yayinlar']) ?> yayın</strong> bu haberi verdi · ilk başlık <?= e(kny_once($olay['ilk'])) ?>,
            son başlık <?= e(kny_once($olay['son'])) ?></p>
    </div>

    <div class="ana-duzen tam">
        <div class="ana-kolon">

            <?php if ($olay['haber'] !== null): ?>
                <a class="kny-valentra" href="<?= e(haber_yolu($olay['haber']['slug'])) ?>">
                    <span class="kny-valentra-etiket">Valentra özeti</span>
                    <span class="kny-valentra-baslik"><?= e($olay['haber']['baslik']) ?></span>
                    <span class="kny-valentra-ok">Haberi oku →</span>
                </a>
            <?php endif; ?>

            <h2 class="kny-bolum">Hangi yayın hangi başlıkla verdi</h2>
            <div class="kny-izgara">
                <?php foreach ($olay['yayinlar'] as $yayin => $basliklar): ?>
                    <?php $ilk = $basliklar[0]; ?>
                    <article class="kny-kart">
                        <header class="kny-kart-ust">
                            <span class="kny-yayin"><?= e(KNY_YAYINLARI[$yayin][0]) ?></span>
                            <time datetime="<?= e(date('c', (int) $ilk['zaman'])) ?>"><?= e(kny_once((int) $ilk['zaman'])) ?></time>
                        </header>
                        <h3 class="kny-kart-baslik">
                            <a href="<?= e((string) $ilk['adres']) ?>" target="_blank" rel="noopener nofollow"><?= e((string) $ilk['baslik']) ?></a>
                        </h3>
                        <?php if ((string) $ilk['ozet'] !== ''): ?>
                            <p class="kny-kart-ozet"><?= e((string) $ilk['ozet']) ?></p>
                        <?php endif; ?>
                        <?php if (count($basliklar) > 1): ?>
                            <ul class="kny-kart-diger">
                                <?php foreach (array_slice($basliklar, 1, 3) as $b): ?>
                                    <li><a href="<?= e((string) $b['adres']) ?>" target="_blank" rel="noopener nofollow"><?= e((string) $b['baslik']) ?></a>
                                        <span><?= e(kny_once((int) $b['zaman'])) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <a class="kny-kart-git" href="<?= e((string) $ilk['adres']) ?>" target="_blank" rel="noopener nofollow"><?= e(KNY_YAYINLARI[$yayin][0]) ?> sitesinde oku ↗</a>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($olay['yazmayanlar'] !== []): ?>
                <h2 class="kny-bolum">Beslemesinde bu habere rastlanmayanlar</h2>
                <p class="kny-yok">
                    <?php foreach ($olay['yazmayanlar'] as $y): ?><span><?= e(KNY_YAYINLARI[$y][0]) ?></span><?php endforeach; ?>
                </p>
                <p class="kny-not">Bu yayınların son <?= (int) KNY_PENCERE_SAAT ?> saatte okunan gündem, politika, ekonomi ve dünya beslemelerinde eşleşen
                    başlık bulunamadı. Haberi başka bir bölümde ya da farklı bir başlıkla vermiş olabilirler; bu liste "yazmadı" anlamına gelmez.</p>
            <?php endif; ?>

            <p class="kny-not">Başlıklar ve özetler yayınların kendi beslemelerinden alınmıştır; değiştirilmeden gösterilir ve haberin tamamı
                yayının kendi sitesindedir. Aynı olayı anlatan başlıklar otomatik eşleştirilir; yanlış eşleşme görürseniz
                <a href="/iletisim">bize bildirin</a>. Yayınlar herhangi bir görüşe göre sınıflandırılmaz<?= $genelToplam > 0 ? '; şu an ' . $genelToplam . ' genel yayının beslemesi izleniyor' : '' ?>.</p>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
