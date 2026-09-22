<?php
declare(strict_types=1);

/**
 * Ana sayfanin yan penceresi.
 *
 * Ana kolonda haberler tarih sirasiyla akiyor; buradaki pencereler
 * okuyucuya farkli bir giris kapisi veriyor: en son eklenenler, konu
 * basliklari ve etiketler.
 *
 * "SON EKLENEN" KALDIRILDI.
 *
 * On haberlik o liste, hemen solundaki izgarayla buyuk olcude ayni
 * haberleri gosteriyordu: ikisi de tarihe gore siralaniyordu. Yani
 * sag sutun ekranin dortte birini kaplayip okuyucuya yeni bir sey
 * sunmuyordu.
 *
 * Yerine iki pencere geldi:
 *   Onemli Duzenlemeler — yalnizca mevzuat kategorileri, bes madde.
 *                          "En yenisi" degil "kacirmamam gereken".
 *   Yaklasan Tarihler   — beyan ve odeme sureleri. Okuyucunun geri
 *                          donme sebebi: haber bir kez okunur, takvim
 *                          her ay lazim olur.
 */

require_once __DIR__ . '/takvim.php';

$onemliler = haber_onemli_duzenlemeler(5);
$yaklasan  = takvim_yaklasanlar(45, 5);
$yanMenu   = kategori_menusu();
$etiketler     = haber_etiket_bulutu(12);

// Konu listesinde bos gruplari gostermek anlamsiz.
$yanMenu = array_values(array_filter(
    $yanMenu,
    static fn (array $grup): bool => $grup['adet'] > 0
));
?>
<aside class="yan-pencere">

    <?php if ($onemliler !== []): ?>
        <section class="pencere">
            <h2 class="pencere-baslik">Önemli Düzenlemeler</h2>

            <ol class="sirali-liste">
                <?php foreach ($onemliler as $sira => $haber): ?>
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

    <?php if ($yaklasan !== []): ?>
        <section class="pencere">
            <h2 class="pencere-baslik">Yaklaşan Tarihler</h2>

            <ul class="takvim-listesi">
                <?php foreach ($yaklasan as $olay): ?>
                    <li>
                        <span class="takvim-gun">
                            <strong><?= e(date('j', strtotime($olay['tarih']))) ?></strong>
                            <span><?= e(ay_kisa((int) date('n', strtotime($olay['tarih'])))) ?></span>
                        </span>
                        <span class="takvim-yazi">
                            <span class="ad"><?= e($olay['baslik']) ?></span>
                            <?php if ($olay['aciklama'] !== ''): ?>
                                <span class="ust-bilgi"><?= e($olay['aciklama']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="takvim-kalan">
                            <?= $olay['kalan'] === 0 ? 'bugün' : $olay['kalan'] . ' gün' ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php /*
                Uyari kaldirilmamali. Sure sonu hafta sonuna ya da resmi
                tatile denk geldiginde ilk is gunune kayar ve GIB sik sik
                sure uzatimi yayimlar. Bir YMM sitesinde yanlis tarih,
                okuyucuya ceza yazdirir.
            */ ?>
            <p class="pencere-not">
                Tarihler bilgi amaçlıdır; süre uzatımı ve tatil kaymaları için
                <a href="https://www.gib.gov.tr/vergi-takvimi" target="_blank"
                   rel="noopener">GİB vergi takvimini</a> esas alın.
            </p>
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
