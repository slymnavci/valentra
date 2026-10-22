<?php
declare(strict_types=1);

/**
 * Ana sayfanin ve haber sayfasinin yan penceresi.
 *
 * Ana kolonda haberler onem sirasiyla akiyor; buradaki pencereler
 * okuyucuya farkli bir giris kapisi veriyor: gunun kose yazilari ve
 * etiketler.
 *
 * 22.10.2026: "Konular" penceresi kaldirildi, yerine "Valentra Diyor
 * ki…" (kose yazilari) geldi (site sahibinin istegi). Konu basliklari
 * zaten ust menude. Altinda "En Cok Okunanlar": son iki gunde en cok
 * okuyucunun actigi yayindaki haberler (sitenin kendi ziyaret sayaci;
 * uc haberden azsa pencere basilmiyor).
 *
 * Ekim 2026: site genel haber ozetlerine dondu. Vergiye ozgu iki
 * pencere kaldirildi: "Onemli Duzenlemeler" (mevzuat kategorileri) ve
 * "Yaklasan Tarihler" (vergi takvimi). Takvim sayfasi acik; yalnizca
 * burada gosterilmiyor.
 */

require_once __DIR__ . '/kose.php';
require_once __DIR__ . '/ziyaret.php';

$etiketler = haber_etiket_bulutu(12);
$enCokOkunanlar = haber_en_cok_okunanlar(6);

$koseYazilari = [];

try {
    $koseYazilari = kose_son_gun();
} catch (PDOException $e) {
    error_log('[valentra] kose yazilari okunamadi: ' . $e->getMessage());
}
?>
<aside class="yan-pencere">

    <?php require __DIR__ . '/kose_kutu.php'; ?>

    <?php if (count($enCokOkunanlar) >= 3): ?>
        <section class="pencere" aria-labelledby="cok-okunan-baslik">
            <h2 class="pencere-baslik" id="cok-okunan-baslik">En Çok Okunanlar</h2>

            <ol class="sirali-liste">
                <?php foreach ($enCokOkunanlar as $sira => $haber): ?>
                    <li>
                        <span class="sira"><?= $sira + 1 ?></span>
                        <a href="<?= e(haber_yolu($haber['slug'])) ?>">
                            <span class="ad"><?= e($haber['baslik']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
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
