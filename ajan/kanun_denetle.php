<?php
declare(strict_types=1);

/**
 * Kanun listesindeki tertip degerlerini denetler.
 *
 * Yanlis tertip adresi kirmiyor — BASKA BIR KANUNU aciyor. Gelir
 * Vergisi Kanunu'nda oldugu gibi: adres gecerli, dosya gecerli, PDF
 * imzasi gecerli, gosterilen kanun yanlis. Hicbir teknik kontrol bunu
 * yakalayamaz; yakalayabilecek tek sey, tertibin kanunun yayim
 * tarihiyle tutarli olup olmadigidir.
 *
 * Ag erisimi gerektirmez; her yerde kosar.
 *
 *   php ajan/kanun_denetle.php
 */

require_once __DIR__ . '/../includes/kanunlar.php';

$hata = 0;
$adet = 0;

foreach (kanun_listesi() as $kanun) {
    $adet++;
    $ad     = (string) $kanun['kisa'];
    $tertip = (int) $kanun['tertip'];
    $rg     = (string) ($kanun['rg'] ?? '');

    if ($rg === '') {
        echo "  X   {$ad}: yayım tarihi girilmemiş, tertip denetlenemiyor\n";
        $hata++;
        continue;
    }

    $beklenen = kanun_tertip_beklenen($rg);

    if ($beklenen !== $tertip) {
        printf(
            "  X   %s: tertip %d yazılmış ama %s tarihli kanun %d. tertipte olmalı\n",
            $ad,
            $tertip,
            $rg,
            $beklenen
        );
        $hata++;
        continue;
    }

    printf("  ok  %-12s tertip %d  (%s)\n", $ad, $tertip, $rg);
}

echo "\n{$adet} kanun denetlendi, {$hata} tutarsızlık.\n";

exit($hata === 0 ? 0 : 1);
