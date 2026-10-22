<?php
declare(strict_types=1);

/**
 * Ana sayfanin ve haber sayfasinin yan penceresi.
 *
 * Ana kolonda haberler tarih sirasiyla akiyor; buradaki pencereler
 * okuyucuya farkli bir giris kapisi veriyor: konu basliklari ve
 * etiketler.
 *
 * Ekim 2026: site genel haber ozetlerine dondu. Vergiye ozgu iki
 * pencere kaldirildi: "Onemli Duzenlemeler" (mevzuat kategorileri) ve
 * "Yaklasan Tarihler" (vergi takvimi). Takvim sayfasi acik; yalnizca
 * burada gosterilmiyor.
 */

$yanMenu   = haber_akis_kategorileri();
$etiketler = haber_etiket_bulutu(12);

// Konu listesinde bos gruplari gostermek anlamsiz.
$yanMenu = array_values(array_filter(
    $yanMenu,
    static fn (array $grup): bool => $grup['adet'] > 0
));
?>
<aside class="yan-pencere">

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
            Türkiye ve dünyadan haberler, güvenilir kaynaklardan tarafsız
            özetler halinde derlenir. Her haberin altında kaynağı ve
            haberin tamamına giden bağlantı yer alır.
        </p>
    </section>

</aside>
