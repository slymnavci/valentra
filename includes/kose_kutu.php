<?php
declare(strict_types=1);

/**
 * Yan pencerenin "Valentra Diyor ki…" kutusu (en ustte).
 *
 * 22.10.2026'ya kadar ana sayfada mansetle ayni siradaydi ve mansetin
 * boyuna sigdiriliyordu; artik yan pencerede, kendi boyunda. Gorunum
 * oteki pencerelerle ayni aileden (numarali liste, baslik, altinda
 * konu). Tarih baslikta: bir gunun yazilari, her satirda ayni tarihi
 * tekrarlamak yer kaybi. En altta butun yazilarin listesine bag.
 *
 * Yayinda yazi yoksa HICBIR SEY basilmiyor: bos bir kutu siteyi eksik
 * gosterir.
 *
 * $koseYazilari'ni cagiran doldurur (includes/yan_pencere.php).
 */

if (($koseYazilari ?? []) === []) {
    return;
}
?>
<section class="pencere kose-kutu" aria-labelledby="kose-kutu-baslik">
    <h2 class="pencere-baslik kose-kutu-baslik" id="kose-kutu-baslik">
        Valentra Diyor ki…
        <span><?= e(tarih_bicimle((string) $koseYazilari[0]['gun'], false)) ?></span>
    </h2>

    <ol class="sirali-liste">
        <?php foreach ($koseYazilari as $sira => $yazi): ?>
            <li>
                <span class="sira"><?= $sira + 1 ?></span>
                <a href="<?= e(kose_yolu((string) $yazi['slug'])) ?>">
                    <span class="ad"><?= e((string) $yazi['baslik']) ?></span>
                    <?php if ((string) $yazi['gundem'] !== ''): ?>
                        <span class="ust-bilgi"><?= e((string) $yazi['gundem']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>

    <p class="pencere-not kose-kutu-tumu">
        <a href="<?= e(kose_liste_yolu()) ?>">Tüm yazılar &rarr;</a>
        &middot; Valentra Yayın Kurulu
    </p>
</section>

