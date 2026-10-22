<?php
declare(strict_types=1);

/**
 * Spor sayfasının üstündeki Süper Lig bölümü: puan durumu, haftanın
 * maçları, geçen haftanın sonuçları.
 *
 * Sayfa (kategori.php) ve 3 dakikada bir yoklanan uc (api/spor.php) ayni
 * HTML'i bu islevden aliyor; canli yenileme ayri bir cizim yolu
 * gerektirmiyor.
 */

require_once __DIR__ . '/spor.php';

function spor_paneli_html(): string
{
    $kayit = spor_super_lig();
    $veri  = $kayit['veri'];

    if ($veri === null) {
        // Hic veri yoksa (ilk acilis ve TFF yanit vermedi) bolum basilmiyor;
        // bos kutu sayfayi bozuk gosterir. Veri yanittan sonra cekiliyor.
        return '';
    }

    $hafta      = $veri['hafta'];
    $maclar     = $veri['haftanin_maclari'];
    $sonOynanan = spor_son_oynanan_hafta($veri['fikstur']);

    // Gecen haftanin sonuclari: haftanin maclarindan onceki hafta. Hafta
    // numarasi okunamadiysa skoru olan son hafta.
    $gecenNo    = $hafta !== null ? $hafta - 1 : $sonOynanan;
    $gecen      = $gecenNo !== null ? ($veri['fikstur'][$gecenNo] ?? []) : [];

    ob_start();
    ?>
    <section class="spor-panel" data-canli-bolum="/api/spor.php" aria-label="Trendyol Süper Lig">
        <div class="canli-panel-ust">
            <h2>Trendyol Süper Lig</h2>
            <span class="canli-not">
                Kaynak: <a href="<?= e(TFF_SUPER_LIG_URL) ?>" target="_blank" rel="noopener">TFF</a>
                &middot; güncelleme <?= e(canli_zaman_yazi($kayit['zaman'])) ?>
            </span>
        </div>

        <div class="spor-izgara">
            <div class="spor-kutu spor-puan">
                <h3>Puan Durumu</h3>
                <div class="tablo-kaydir">
                    <table class="puan-tablosu">
                        <thead>
                            <tr>
                                <th scope="col" class="sira">#</th>
                                <th scope="col" class="takim">Takım</th>
                                <th scope="col" title="Oynanan">O</th>
                                <th scope="col" title="Galibiyet">G</th>
                                <th scope="col" title="Beraberlik">B</th>
                                <th scope="col" title="Mağlubiyet">M</th>
                                <th scope="col" title="Atılan" class="genis">A</th>
                                <th scope="col" title="Yenilen" class="genis">Y</th>
                                <th scope="col" title="Averaj">AV</th>
                                <th scope="col" title="Puan" class="puan">P</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($veri['puan'] as $s): ?>
                                <tr>
                                    <td class="sira"><?= (int) $s['sira'] ?></td>
                                    <td class="takim"><?= e((string) $s['takim']) ?></td>
                                    <td><?= (int) $s['o'] ?></td>
                                    <td><?= (int) $s['g'] ?></td>
                                    <td><?= (int) $s['b'] ?></td>
                                    <td><?= (int) $s['m'] ?></td>
                                    <td class="genis"><?= (int) $s['a'] ?></td>
                                    <td class="genis"><?= (int) $s['y'] ?></td>
                                    <td><?= (int) $s['av'] > 0 ? '+' : '' ?><?= (int) $s['av'] ?></td>
                                    <td class="puan"><?= (int) $s['p'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="spor-kutu spor-maclar">
                <?php if ($maclar !== []): ?>
                    <h3><?= $hafta !== null ? $hafta . '. Hafta' : 'Haftanın Maçları' ?></h3>
                    <ul class="mac-listesi">
                        <?php foreach ($maclar as $mac): ?>
                            <li>
                                <span class="mac-zaman"><?= e(spor_tarih($mac['tarih'])) ?> <?= e($mac['saat']) ?></span>
                                <span class="mac-ev"><?= e($mac['ev']) ?></span>
                                <span class="mac-skor<?= $mac['skor'] === '' ? ' oynanmadi' : '' ?>"><?= $mac['skor'] !== '' ? e($mac['skor']) : '-' ?></span>
                                <span class="mac-dep"><?= e($mac['dep']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($gecen !== []): ?>
                    <h3><?= (int) $gecenNo ?>. Hafta Sonuçları</h3>
                    <ul class="mac-listesi sonuclar">
                        <?php foreach ($gecen as $mac): ?>
                            <li>
                                <span class="mac-ev"><?= e($mac['ev']) ?></span>
                                <span class="mac-skor<?= $mac['skor'] === '' ? ' oynanmadi' : '' ?>"><?= $mac['skor'] !== '' ? e($mac['skor']) : '-' ?></span>
                                <span class="mac-dep"><?= e($mac['dep']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="canli-not">
                    <a href="<?= e(TFF_SUPER_LIG_URL) ?>" target="_blank" rel="noopener">Tüm fikstür TFF'de &rarr;</a>
                </p>
            </div>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}
