<?php
declare(strict_types=1);

/**
 * Ana sayfa: "Valentra Diyor ki…" kutusu.
 *
 * Mansetin sagina, Onemli Duzenlemeler'in soluna oturuyor (dar ekranda
 * mansetin altina) ve mansetle ayni boyda. Gorunum yan penceredeki
 * "Onemli Duzenlemeler" ile AYNI (numarali liste, baslik, altinda konu):
 * iki kutu yan yana duruyor ve ayni ailenin parcasi gibi okunmali. Tarih
 * baslikta: bir gunun yazilari, her satirda ayni tarihi tekrarlamak yer
 * kaybi. En altta butun yazilarin listesine bag.
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

<?php
/*
 * Kutu mansetle AYNI boyda (CSS: contain: size). Ekran kisaysa sigmayan
 * yazilar yarim kesik gorunmesin diye tamamen gizleniyor; hepsi "Tum
 * yazilar" sayfasinda. Kutu mansetin altina indiginde (dar ekran) hepsi
 * gorunur, olcum de o durumda hicbir seyi gizlemez.
 */
?>
<script>
(function () {
    var liste = document.querySelector('.kose-kutu .sirali-liste');
    if (!liste) { return; }

    function sigdir() {
        var ogeler = liste.children;
        for (var i = 0; i < ogeler.length; i++) { ogeler[i].hidden = false; }
        var sinir = liste.getBoundingClientRect().bottom + 1;
        for (var j = 1; j < ogeler.length; j++) {
            if (ogeler[j].getBoundingClientRect().bottom > sinir) {
                for (var k = j; k < ogeler.length; k++) { ogeler[k].hidden = true; }
                break;
            }
        }
    }

    sigdir();
    window.addEventListener('load', sigdir);
    window.addEventListener('resize', sigdir);
})();
</script>
