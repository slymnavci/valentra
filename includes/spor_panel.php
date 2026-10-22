<?php
declare(strict_types=1);

/**
 * Spor sayfalari: Super Lig puan durumu ve fikstur, spor haberlerinden
 * AYRI sayfalar (23.10.2026, site sahibinin istegi: "puan durumu,
 * fikstur, spor haberleri ayri ayri olsun").
 *
 *   /kategori/spor                     -> yalnizca spor haberleri
 *   /kategori/spor?bolum=puan-durumu   -> puan durumu
 *   /kategori/spor?bolum=fikstur       -> fikstur
 *
 * Sayfa (kategori.php) ve 3 dakikada bir yoklanan uc (api/spor.php) ayni
 * HTML'i bu islevlerden aliyor; canli yenileme ayri bir cizim yolu
 * gerektirmiyor.
 */

require_once __DIR__ . '/spor.php';

/** Spor sayfasının bölümleri: anahtar => başlık. */
const SPOR_BOLUMLERI = [
    'puan-durumu' => 'Puan Durumu',
    'fikstur'     => 'Fikstür',
];

/** Spor bölümünün adresi; bölüm boşsa spor haberleri. */
function spor_bolum_yolu(string $bolum = ''): string
{
    $yol = kategori_yolu('spor');

    if ($bolum === '') {
        return $yol;
    }

    return $yol . (str_contains($yol, '?') ? '&' : '?') . 'bolum=' . rawurlencode($bolum);
}

/** Üç spor sayfası arasında geçiş sekmeleri. */
function spor_sekmeleri_html(string $aktif): string
{
    $sekmeler = SPOR_BOLUMLERI + ['' => 'Spor Haberleri'];

    ob_start();
    ?>
    <nav class="spor-sekmeler" aria-label="Spor bölümleri">
        <?php foreach ($sekmeler as $bolum => $ad): ?>
            <a href="<?= e(spor_bolum_yolu((string) $bolum)) ?>"<?= (string) $bolum === $aktif ? ' class="aktif" aria-current="page"' : '' ?>><?= e($ad) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php

    return (string) ob_get_clean();
}

/**
 * Bir spor bölümünün canlı HTML'i. Veri yoksa (ilk açılış ve TFF yanıt
 * vermedi) boş; boş kutu sayfayı bozuk gösterir. Veri yanıttan sonra
 * çekiliyor.
 */
function spor_paneli_html(string $bolum): string
{
    if (!isset(SPOR_BOLUMLERI[$bolum])) {
        return '';
    }

    $kayit = spor_super_lig();
    $veri  = $kayit['veri'];

    if ($veri === null) {
        return '';
    }

    $govde = $bolum === 'puan-durumu' ? spor_puan_html($veri) : spor_fikstur_html($veri);

    ob_start();
    ?>
    <section class="spor-panel" data-canli-bolum="/api/spor.php?bolum=<?= e($bolum) ?>" aria-label="Trendyol Süper Lig <?= e(SPOR_BOLUMLERI[$bolum]) ?>">
        <div class="canli-panel-ust">
            <h2>Trendyol Süper Lig &middot; <?= e(SPOR_BOLUMLERI[$bolum]) ?></h2>
            <span class="canli-not">
                Kaynak: <a href="<?= e(TFF_SUPER_LIG_URL) ?>" target="_blank" rel="noopener">TFF</a>
                &middot; güncelleme <?= e(canli_zaman_yazi($kayit['zaman'])) ?>
            </span>
        </div>

        <?= $govde ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

/** @param array<string,mixed> $veri */
function spor_puan_html(array $veri): string
{
    ob_start();
    ?>
    <div class="spor-kutu spor-puan">
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
        <p class="canli-not">İlk dört sıra Avrupa kupaları, son üç sıra küme düşme hattı.</p>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Fikstür: bu haftanın maçları (tarih ve saatle), geçen haftanın
 * sonuçları, gelecek hafta ve sezonun bütün haftaları.
 *
 * @param array<string,mixed> $veri
 */
function spor_fikstur_html(array $veri): string
{
    $hafta      = $veri['hafta'];
    $maclar     = $veri['haftanin_maclari'];
    $fikstur    = $veri['fikstur'];
    $sonOynanan = spor_son_oynanan_hafta($fikstur);

    // Gecen hafta: haftanin maclarindan onceki hafta. Hafta numarasi
    // okunamadiysa skoru olan son hafta.
    $gecenNo   = $hafta !== null ? $hafta - 1 : $sonOynanan;
    $gecen     = $gecenNo !== null ? ($fikstur[$gecenNo] ?? []) : [];
    $gelecekNo = $hafta !== null ? $hafta + 1 : null;
    $gelecek   = $gelecekNo !== null ? ($fikstur[$gelecekNo] ?? []) : [];

    // Kaynakta fiksturde tarih yok, yalnizca eslesmeler ve skor.
    $liste = static function (array $maclar): string {
        ob_start();
        ?>
        <ul class="mac-listesi sonuclar">
            <?php foreach ($maclar as $mac): ?>
                <li>
                    <span class="mac-ev"><?= e($mac['ev']) ?></span>
                    <span class="mac-skor<?= $mac['skor'] === '' ? ' oynanmadi' : '' ?>"><?= $mac['skor'] !== '' ? e($mac['skor']) : '-' ?></span>
                    <span class="mac-dep"><?= e($mac['dep']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php

        return (string) ob_get_clean();
    };

    ob_start();
    ?>
    <div class="spor-izgara">
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

            <?php if ($gelecek !== []): ?>
                <h3><?= (int) $gelecekNo ?>. Hafta</h3>
                <?= $liste($gelecek) ?>
            <?php endif; ?>
        </div>

        <div class="spor-kutu spor-maclar">
            <?php if ($gecen !== []): ?>
                <h3><?= (int) $gecenNo ?>. Hafta Sonuçları</h3>
                <?= $liste($gecen) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($fikstur !== []): ?>
        <div class="spor-kutu spor-tum-fikstur">
            <h3>Sezonun Bütün Haftaları</h3>
            <?php foreach ($fikstur as $no => $haftaMaclari): ?>
                <details<?= $no === $hafta ? ' open' : '' ?>>
                    <summary><?= (int) $no ?>. Hafta<?= $no === $hafta ? ' (bu hafta)' : '' ?></summary>
                    <?= $liste($haftaMaclari) ?>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php

    return (string) ob_get_clean();
}
