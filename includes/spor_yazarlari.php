<?php
declare(strict_types=1);

/**
 * Spor sayfasının "Spor Yazarları" bölümü: futbol, basketbol ve dört büyük
 * kulübün yorumcuları, en son yazılarıyla.
 *
 * Site sahibinin istegi (25.10.2026): "spor sayfasinda da yorumcular
 * yapalim; bunlari kose yazari olarak sunalim". Yazarlar
 * includes/yazarlar.php'de ('spor' => true). Yayinda yazisi olmayan
 * yazar gorunmuyor; hicbirinin yazisi yoksa bolum basilmiyor.
 */

require_once __DIR__ . '/kose.php';

try {
    $sporYazarlari = array_values(array_filter(
        kose_yazarlarin_son_yazilari(),
        static fn (array $s): bool => !empty($s['yazar']['spor'])
    ));
} catch (Throwable $e) {
    $sporYazarlari = [];
}

if ($sporYazarlari === []) {
    return;
}
?>
<section class="spor-yazarlari" aria-labelledby="spor-yazarlari-baslik">
    <div class="bolum-basligi">
        <h2 id="spor-yazarlari-baslik">Spor Yazarları</h2>
        <span class="cizgi"></span>
    </div>

    <ul class="spor-yazar-izgara">
        <?php foreach ($sporYazarlari as $satir): ?>
            <?php $yazar = $satir['yazar']; $yazi = $satir['yazi']; ?>
            <li>
                <a class="yazar-portre-bag" href="<?= e(yazar_yolu($yazar['anahtar'])) ?>" aria-hidden="true" tabindex="-1">
                    <?= yazar_portre_svg($yazar['anahtar'], 56) ?>
                </a>
                <div class="yazar-metin">
                    <a class="yazar-ad" href="<?= e(yazar_yolu($yazar['anahtar'])) ?>"><?= e($yazar['ad']) ?></a>
                    <span class="yazar-alan"><?= e((string) $yazar['alan']) ?></span>
                    <a class="yazar-yazi" href="<?= e(kose_yolu((string) $yazi['slug'])) ?>"><?= e((string) $yazi['baslik']) ?></a>
                    <span class="yazar-tarih"><?= e(tarih_bicimle((string) $yazi['gun'], false)) ?></span>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
