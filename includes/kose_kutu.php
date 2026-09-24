<?php
declare(strict_types=1);

/**
 * Ana sayfa: "Valentra Diyor ki…" kutusu.
 *
 * Mansetin sagina, Onemli Duzenlemeler'in soluna oturuyor (dar ekranda
 * mansetin altina). Gorunum yan penceredeki "Onemli Duzenlemeler" ile
 * AYNI (numarali liste, baslik, altinda konu ve tarih): iki kutu yan yana
 * duruyor ve ayni ailenin parcasi gibi okunmali. Yayindaki en son gunun
 * yazilari; en altta butun yazilarin listesine bag.
 *
 * Yayinda yazi yoksa HICBIR SEY basilmiyor ve manset eski genisligiyle
 * kaliyor: bos bir kutu siteyi eksik gosterir.
 *
 * $koseYazilari'ni cagiran doldurur (index.php).
 */

if (($koseYazilari ?? []) === []) {
    return;
}
?>
<section class="pencere kose-kutu" aria-labelledby="kose-kutu-baslik">
    <h2 class="pencere-baslik" id="kose-kutu-baslik">Valentra Diyor ki…</h2>

    <ol class="sirali-liste">
        <?php foreach ($koseYazilari as $sira => $yazi): ?>
            <li>
                <span class="sira"><?= $sira + 1 ?></span>
                <a href="<?= e(kose_yolu((string) $yazi['slug'])) ?>">
                    <span class="ad"><?= e((string) $yazi['baslik']) ?></span>
                    <span class="ust-bilgi">
                        <?php if ((string) $yazi['gundem'] !== ''): ?>
                            <?= e((string) $yazi['gundem']) ?> &middot;
                        <?php endif; ?>
                        <?= e(tarih_bicimle((string) $yazi['gun'], false)) ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>

    <p class="pencere-not kose-kutu-tumu">
        <a href="<?= e(kose_liste_yolu()) ?>">Tüm yazılar &rarr;</a>
        &middot; Valentra Yayın Kurulu
    </p>
</section>
