<?php
declare(strict_types=1);

/**
 * Ana sayfa vitrini: piyasa seridinin altinda ince, kayan hava seridi.
 *
 * Site sahibinin istegi (08.11.2026): "hava durumunu alta tasiyalim;
 * altin, dolar seridinin altinda kayar sekilde koyabiliriz; kucuk bir
 * serit olsun". Vitrindeki genis hava kutusunun yerinde artik "Bugun
 * bilmeniz gereken 5 gelisme" (includes/bes_gelisme.php).
 *
 * 30 buyuksehir tek istekle (hava_sehirler, 30 dakika onbellek). Liste
 * iki kez basiliyor ki kayma kesintisiz donsun (ikinci kopya ekran
 * okuyucudan gizli); uzerine gelince duruyor, hareket azaltma tercihinde
 * kaymiyor (son dakika seridiyle ayni duzen, bkz. .sd-serit).
 */

require_once __DIR__ . '/hava.php';

try {
    $havaSehirleri = hava_sehirler(HAVA_BUYUKSEHIRLER, 'hava_buyuksehirler');
} catch (Throwable $e) {
    $havaSehirleri = [];
}

if ($havaSehirleri === []) {
    return;
}
?>
<div class="hava-serit" aria-label="Büyükşehirlerde hava durumu">
    <span class="hava-serit-etiket">Hava</span>
    <div class="hava-serit-pencere">
        <div class="hava-serit-akis" style="--sure: <?= max(40, count($havaSehirleri) * 4) ?>s">
            <?php foreach ([false, true] as $kopya): ?>
                <ul class="hava-serit-liste" <?= $kopya ? 'aria-hidden="true"' : '' ?>>
                    <?php foreach ($havaSehirleri as $s): ?>
                        <li title="<?= e((string) $s['durum']) ?>">
                            <span class="hava-serit-ikon"><?= hava_simge_svg((string) $s['simge']) ?></span>
                            <span class="hava-serit-ad"><?= e((string) $s['ad']) ?></span>
                            <span class="hava-serit-derece"><?= (int) $s['sicaklik'] ?>°</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
    </div>
</div>
