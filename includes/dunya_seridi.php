<?php
declare(strict_types=1);

/**
 * Ana sayfa: "Dünyadan Son Dakika" bandı (29.10.2026; bkz. haber_dunya_son_dakika).
 * Son 24 saatin dunya haberleri, saatiyle; en az 3 haber yoksa gorunmuyor.
 */

$dunyaHaberleri = haber_dunya_son_dakika(8);

if (count($dunyaHaberleri) < 3) {
    return;
}
?>
<section class="dunya-seridi" aria-labelledby="dunya-seridi-baslik">
    <div class="dunya-seridi-ust">
        <h2 id="dunya-seridi-baslik">Dünyadan Son Dakika</h2>
        <a href="<?= e(kategori_yolu('dunya')) ?>">Tümü &rarr;</a>
    </div>
    <ul>
        <?php foreach ($dunyaHaberleri as $dh): ?>
            <li>
                <time><?= e((string) $dh['zaman']) ?></time>
                <a href="<?= e(haber_yolu((string) $dh['slug'])) ?>"><?= e((string) $dh['baslik']) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
