<?php
declare(strict_types=1);

/**
 * Ana sayfanin alt pencereleri: konu konu haber bloklari.
 *
 * Her blokta ilk haber gorselli, kalanlar duz baslik listesi olarak
 * duruyor; boylece konu basligi bir gazete sayfasi gibi taraniyor.
 */

$altBloklar = kategori_bloklari(4, 6);

if ($altBloklar === []) {
    return;
}
?>
<div class="bolum-basligi">
    <h2>Konu Başlıkları</h2>
    <span class="cizgi"></span>
</div>

<section class="alt-pencereler">
    <?php foreach ($altBloklar as $blok): ?>
        <?php
        $haberler  = $blok['haberler'];
        $ilk       = array_shift($haberler);
        $ilkGorsel = guvenli_url($ilk['gorsel_url'] ?? '');
        ?>
        <section class="pencere blok">
            <h3 class="blok-baslik">
                <a href="/kategori.php?k=<?= e($blok['slug']) ?>">
                    <?= e($blok['ad']) ?>
                    <span class="devam">Tümü &rarr;</span>
                </a>
            </h3>

            <a class="blok-one-cikan" href="/haber.php?h=<?= e($ilk['slug']) ?>">
                <?php if ($ilkGorsel !== ''): ?>
                    <img src="<?= e($ilkGorsel) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <h4><?= e($ilk['baslik']) ?></h4>
                <p><?= e(kisalt((string) $ilk['ozet'], 110)) ?></p>
                <span class="ust-bilgi"><?= e(tarih_bicimle($ilk['yayin_tarihi'], false)) ?></span>
            </a>

            <?php if ($haberler !== []): ?>
                <ul class="blok-liste">
                    <?php foreach ($haberler as $haber): ?>
                        <li>
                            <a href="/haber.php?h=<?= e($haber['slug']) ?>">
                                <?= e($haber['baslik']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</section>
