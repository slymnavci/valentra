<?php
declare(strict_types=1);

/**
 * Ana sayfanin video seridi: en yeni dort videolu haber (30.10.2026, site
 * sahibinin istegi). Kartlarda oynat isareti var (kart_izgara.php); tamami
 * /video sayfasinda. Iki videodan azsa serit basilmiyor.
 */

$videoSeridi = haber_videolular(4);

if (count($videoSeridi) < 2) {
    return;
}
?>
<div class="bolum-basligi video-seridi-baslik">
    <h2><a href="<?= e(video_yolu()) ?>">Video Haberler</a></h2>
    <span class="cizgi"></span>
    <a class="gazete-tumu" href="<?= e(video_yolu()) ?>">Tüm videolar &rarr;</a>
</div>
<?php $izgaraHaberleri = $videoSeridi; ?>
<div class="video-seridi"><?php require __DIR__ . '/kart_izgara.php'; ?></div>
