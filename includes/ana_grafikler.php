<?php
declare(strict_types=1);

/**
 * Ana sayfa: panelden yayına alınan grafikler.
 *
 * Piyasa seridinin hemen altinda, yalnizca ilk sayfada. Yayinda grafik
 * yoksa bolum hic basilmiyor; bos bir baslik siteyi eksik gosterir.
 *
 * Bu dosya ana sayfanin ortasinda calisiyor: grafik_ana_sayfa() hata
 * firlatmiyor (tablo yoksa bos donuyor), yani bu bolum yuzunden sayfa
 * dusmez.
 */

require_once __DIR__ . '/grafikler.php';
require_once __DIR__ . '/grafik.php';

$anaGrafikler = ($sayfa ?? 1) === 1 ? grafik_ana_sayfa() : [];

if ($anaGrafikler === []) {
    return;
}
?>
<section class="ana-grafikler" aria-labelledby="ana-grafikler-baslik">
    <div class="bolum-basligi">
        <h2 id="ana-grafikler-baslik">Ekonomik Göstergeler</h2>
        <span class="cizgi"></span>
    </div>

    <div class="ana-grafikler-izgara">
        <?php foreach ($anaGrafikler as $anaGrafik): ?>
            <article class="ana-grafik-kart">
                <h3><?= e((string) $anaGrafik['baslik']) ?></h3>
                <?php
                /*
                 * Kart basligi grafigin adini zaten soyluyor; figur
                 * basligi bos, altinda yalnizca kaynak ve son gozlem.
                 */
                echo grafik_ciz(pratik_seri_oku((string) $anaGrafik['seri']),
                                grafik_ciz_ayari($anaGrafik));
                ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
