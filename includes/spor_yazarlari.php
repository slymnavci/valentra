<?php
declare(strict_types=1);

/**
 * Spor sayfasının "Spor Analizi" bölümü: spor masasının son yazıları.
 *
 * 25.10.2026'dan 11.11.2026'ya kadar "Spor Yazarlari" idi (alti yorumcu
 * karakteri, portreleriyle). Site sahibinin karari (11.11.2026): yazarlar
 * kalkti; spor icin gunde bir analiz (bkz. includes/yazarlar.php). Eski
 * spor yazarlarinin yazilari da bu listede. Yazi yoksa bolum basilmiyor.
 */

require_once __DIR__ . '/kose.php';

try {
    $sporAnalizleri = kose_yazarin_yazilari('spor', 1, 4)['yazilar'];
} catch (Throwable $e) {
    $sporAnalizleri = [];
}

if ($sporAnalizleri === []) {
    return;
}
?>
<section class="spor-yazarlari" aria-labelledby="spor-yazarlari-baslik">
    <div class="bolum-basligi">
        <h2 id="spor-yazarlari-baslik"><a href="<?= e(yazar_yolu('spor')) ?>">Spor Analizi</a></h2>
        <span class="cizgi"></span>
    </div>

    <ul class="spor-yazar-izgara">
        <?php foreach ($sporAnalizleri as $yazi): ?>
            <li>
                <div class="yazar-metin">
                    <span class="yazar-tarih"><?= e(tarih_bicimle((string) $yazi['gun'], false)) ?></span>
                    <a class="yazar-yazi" href="<?= e(kose_yolu((string) $yazi['slug'])) ?>"><?= e((string) $yazi['baslik']) ?></a>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
