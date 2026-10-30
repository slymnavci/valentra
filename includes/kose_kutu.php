<?php
declare(strict_types=1);

/**
 * Yan pencerenin "Yazarlarımız" kutusu (en ustte, sayfanin en saginda).
 *
 * Site sahibinin istegi (23.10.2026): "yazarlarin yazilari her gun
 * gazetenin en saginda dursun, gecmis yazilarina da okuyucular
 * ulasabilsin". Her yazar: portresi, adi, alani ve en son yazisinin
 * basligi. Portre ve ad yazar sayfasina (butun yazilari), baslik yaziya
 * gidiyor. En altta butun yazilarin listesine bag.
 *
 * Yayinda yazi yoksa HICBIR SEY basilmiyor: bos bir kutu siteyi eksik
 * gosterir.
 *
 * $yazarYazilari'ni cagiran doldurur (includes/yan_pencere.php).
 */

if (($yazarYazilari ?? []) === []) {
    return;
}
?>
<section class="pencere kose-kutu" aria-labelledby="kose-kutu-baslik">
    <h2 class="pencere-baslik kose-kutu-baslik" id="kose-kutu-baslik">Yazarlarımız</h2>

    <ul class="yazar-listesi">
        <?php foreach ($yazarYazilari as $satir): ?>
            <?php $yazar = $satir['yazar']; $yazi = $satir['yazi']; ?>
            <li>
                <a class="yazar-portre-bag" href="<?= e(yazar_yolu($yazar['anahtar'])) ?>" aria-hidden="true" tabindex="-1">
                    <?= yazar_portre_svg($yazar['anahtar'], 52) ?>
                </a>
                <div class="yazar-metin">
                    <a class="yazar-ad" href="<?= e(yazar_yolu($yazar['anahtar'])) ?>"><?= e($yazar['ad']) ?></a>
                    <a class="yazar-yazi" href="<?= e(kose_yolu((string) $yazi['slug'])) ?>"><?= e((string) $yazi['baslik']) ?></a>
                    <span class="yazar-tarih"><?= e(tarih_bicimle((string) $yazi['gun'], false)) ?></span>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="pencere-not kose-kutu-tumu">
        <a href="<?= e(kose_liste_yolu()) ?>">Tüm yazılar &rarr;</a>
    </p>
</section>

<script>
(function () {
    /*
     * Ana sayfa vitrininde kutu yuksekligi sabit (kaydirakla esit): sigmayan
     * yazar yarim kesik gorunmesin, tamamen gizlensin; hepsi "Tum yazilar"da.
     * Yeniden boyutlanmada tekrar hesaplaniyor.
     */
    var liste = document.currentScript.previousElementSibling.querySelector('.yazar-listesi');
    if (!liste || !liste.closest('.vitrin')) { return; }

    function sigdir() {
        var ogeler = liste.querySelectorAll('li');
        ogeler.forEach(function (li) { li.hidden = false; });
        var alt = liste.getBoundingClientRect().bottom;
        ogeler.forEach(function (li) {
            if (li.getBoundingClientRect().bottom > alt + 1) { li.hidden = true; }
        });
    }

    sigdir();
    window.addEventListener('resize', sigdir);
})();
</script>
