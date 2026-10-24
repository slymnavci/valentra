<?php
declare(strict_types=1);

/**
 * Büyük şehirler hava şeridi (ana sayfa, piyasa şeridiyle aynı sırada).
 *
 * Site sahibinin istegi (24.10.2026): "hava durumunda seride paralel
 * buyuk sehir havasi koyalim". Sehir sehir anlik sicaklik ve gunun en
 * yuksek/en dusuk sicakligi; sigmazsa piyasa seridi gibi kayiyor
 * (uzerine gelince duruyor). Sehre tiklayinca hava kartindaki ayrinti o
 * sehre geciyor (includes/hava_kutu.php). Veri yoksa serit basilmiyor.
 */

require_once __DIR__ . '/hava.php';

try {
    $seritSehirleri = hava_buyuk_sehirler();
} catch (Throwable $e) {
    $seritSehirleri = [];
}

if ($seritSehirleri === []) {
    return;
}
?>
<div class="sehir-serit" aria-label="Büyük şehirlerde hava">
    <div class="sehir-akis">
        <?php foreach ([false, true] as $kopya): ?>
            <ul class="sehir-liste" <?= $kopya ? 'aria-hidden="true"' : '' ?>>
                <?php foreach ($seritSehirleri as $sehir): ?>
                    <li>
                        <button type="button" data-hava-il="<?= e($sehir['il']) ?>" title="<?= e($sehir['durum']) ?>"
                                <?= $kopya ? 'tabindex="-1"' : '' ?>>
                            <?= hava_simge_svg($sehir['simge']) ?>
                            <span class="ad"><?= e($sehir['ad']) ?></span>
                            <span class="simdi"><?= (int) $sehir['sicaklik'] ?>°</span>
                            <?php if ($sehir['en_yuksek'] !== null && $sehir['en_dusuk'] !== null): ?>
                                <span class="aralik"><?= (int) $sehir['en_yuksek'] ?>°/<?= (int) $sehir['en_dusuk'] ?>°</span>
                            <?php endif; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>
</div>
