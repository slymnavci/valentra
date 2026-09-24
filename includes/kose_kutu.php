<?php
declare(strict_types=1);

/**
 * Ana sayfa: "Valentra Diyor ki…" kutusu.
 *
 * Mansetin sagina, Onemli Duzenlemeler'in soluna oturuyor (dar ekranda
 * mansetin altina). Yayindaki en son gunun yazilari; en altta butun
 * yazilarin listesine bag.
 *
 * Yayinda yazi yoksa HICBIR SEY basilmiyor ve manset eski genisligiyle
 * kaliyor: bos bir kutu siteyi eksik gosterir. Tablo yoksa (sema
 * yukseltmesi dustuyse) da ana sayfa dusmesin diye hata yutuluyor.
 *
 * $koseYazilari'ni cagiran doldurur (index.php); duzen sinifi o
 * degiskene gore seciliyor.
 */

if (($koseYazilari ?? []) === []) {
    return;
}
?>
<?php /* Dort yazili gunde ozetler gizleniyor: kutu mansetin boyunda,
          dorduncu yazi aksi halde kirpilirdi. Basliklar her zaman gorunur. */ ?>
<aside class="kose-kutu<?= count($koseYazilari) >= 4 ? ' kose-kutu-sik' : '' ?>" aria-labelledby="kose-kutu-baslik">
    <div class="kose-kutu-ust">
        <h2 id="kose-kutu-baslik">Valentra Diyor ki…</h2>
        <span><?= e(tarih_bicimle((string) $koseYazilari[0]['gun'], false)) ?></span>
    </div>

    <ol class="kose-kutu-liste">
        <?php foreach ($koseYazilari as $yazi): ?>
            <li>
                <a href="<?= e(kose_yolu((string) $yazi['slug'])) ?>">
                    <?php if ((string) $yazi['gundem'] !== ''): ?>
                        <span class="kose-gundem"><?= e((string) $yazi['gundem']) ?></span>
                    <?php endif; ?>
                    <span class="kose-baslik"><?= e((string) $yazi['baslik']) ?></span>
                    <?php if ((string) $yazi['ozet'] !== ''): ?>
                        <span class="kose-ozet"><?= e((string) $yazi['ozet']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>

    <a class="kose-kutu-tumu" href="<?= e(kose_liste_yolu()) ?>">Tüm yazılar &rarr;</a>
</aside>
