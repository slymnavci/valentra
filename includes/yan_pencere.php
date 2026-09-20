<?php
declare(strict_types=1);

/**
 * Ana sayfanin yan penceresi.
 *
 * Ana kolonda haberler tarih sirasiyla akiyor; buradaki pencereler
 * okuyucuya farkli bir giris kapisi veriyor: en son eklenenler, konu
 * basliklari ve etiketler.
 *
 * Kaydirakla tekrar olmasin diye "Son Eklenen" listesi one_cikan
 * siralamasini kullanmiyor, yalnizca yayin tarihine bakiyor.
 */

// Yan pencere genisledigi icin daha fazla haber sigiyor; kolon
// yuksekligi ana kolona yaklasinca sagda bosluk kalmiyor.
$sonEklenenler = haber_son_eklenenler(10);
$yanMenu       = kategori_menusu();
$etiketler     = haber_etiket_bulutu(12);

// Konu listesinde bos gruplari gostermek anlamsiz.
$yanMenu = array_values(array_filter(
    $yanMenu,
    static fn (array $grup): bool => $grup['adet'] > 0
));
?>
<aside class="yan-pencere">

    <?php if ($sonEklenenler !== []): ?>
        <section class="pencere">
            <h2 class="pencere-baslik">Son Eklenen</h2>

            <ol class="sirali-liste">
                <?php foreach ($sonEklenenler as $sira => $haber): ?>
                    <li>
                        <span class="sira"><?= $sira + 1 ?></span>
                        <a href="<?= e(haber_yolu((string) $haber['slug'])) ?>">
                            <span class="ad"><?= e($haber['baslik']) ?></span>
                            <span class="ust-bilgi">
                                <?php if (!empty($haber['kategori_adi'])): ?>
                                    <?= e($haber['kategori_adi']) ?> &middot;
                                <?php endif; ?>
                                <?= e(tarih_bicimle($haber['yayin_tarihi'], false)) ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if ($yanMenu !== []): ?>
        <section class="pencere">
            <h2 class="pencere-baslik">Konular</h2>

            <ul class="konu-listesi">
                <?php foreach ($yanMenu as $grup): ?>
                    <li>
                        <a href="<?= e(kategori_yolu((string) $grup['slug'])) ?>">
                            <?= e($grup['ad']) ?>
                            <span class="adet"><?= $grup['adet'] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($etiketler !== []): ?>
        <section class="pencere">
            <h2 class="pencere-baslik">Etiketler</h2>

            <div class="etiket-bulutu">
                <?php foreach ($etiketler as $etiket): ?>
                    <span class="bulut-etiket">#<?= e($etiket['etiket']) ?></span>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="pencere pencere-vurgu">
        <h2 class="pencere-baslik">Valentra</h2>
        <p class="pencere-metin">
            Vergi ve mali mevzuattaki gelişmeler ile ekonomi gündemi,
            yeminli mali müşavirlik bakışıyla derlenir.
        </p>
    </section>

</aside>
