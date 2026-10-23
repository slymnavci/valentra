<?php
declare(strict_types=1);

/**
 * Spor sayfalari (kategori.php ?bolum=...).
 *
 *   (bos)          spor haberleri (kategori.php kendisi ciziyor)
 *   puan-durumu    secilen ligin puan durumu
 *   fikstur        secilen ligin maç gunleri, canli skorlar
 *   gol-kralligi   secilen ligin gol kralligi
 *   kulup          kulubun analizi, son maclari ve golleri
 *   mac            macin golleri, kartlari ve istatistigi
 *
 * Gecmis: 22.10.2026 TFF'den Super Lig (tek sayfa); 23.10.2026 bolumler
 * ayri sayfa; ayni gun site sahibinin istegiyle dunyanin liglerini, kulup
 * ve mac sayfalari (ESPN, includes/futbol.php).
 *
 * ESPN panelden kapatilmissa puan durumu ve fikstur TFF'den Super Lig;
 * diger bolumler kapali oldugunu soyler.
 *
 * Sayfa (kategori.php) ve canli yenileme ucu (api/spor.php) ayni HTML'i
 * spor_paneli_html()'den aliyor. Lig secici bunun DISINDA: yenileme
 * secicinin uzerine yazmasin.
 */

require_once __DIR__ . '/spor.php';
require_once __DIR__ . '/futbol.php';

/** Menüde ve sekmelerde görünen bölümler: anahtar => başlık. */
const SPOR_BOLUMLERI = [
    'puan-durumu'  => 'Puan Durumu',
    'fikstur'      => 'Fikstür',
    'gol-kralligi' => 'Gol Krallığı',
];

/** Sekmesi olmayan, bağlantıyla açılan bölümler. */
const SPOR_ALT_BOLUMLER = [
    'kulup' => 'Kulüp',
    'mac'   => 'Maç',
];

/**
 * İstatistik adlarının Türkçesi (ESPN adıyla). Listede olmayan istatistik
 * gösterilmiyor: ESPN bazen ayni seyi iki adla veriyor.
 */
const SPOR_ISTATISTIK_ADLARI = [
    'possessionPct'  => 'Topla oynama (%)',
    'totalShots'     => 'Toplam şut',
    'shotsOnTarget'  => 'İsabetli şut',
    'wonCorners'     => 'Korner',
    'foulsCommitted' => 'Faul',
    'offsides'       => 'Ofsayt',
    'saves'          => 'Kaleci kurtarışı',
    'yellowCards'    => 'Sarı kart',
    'redCards'       => 'Kırmızı kart',
    'totalPasses'    => 'Pas',
    'accuratePasses' => 'İsabetli pas',
    'passPct'        => 'Pas isabeti',
];

/**
 * Spor bölümünün adresi; bölüm boşsa spor haberleri.
 *
 * @param array<string,scalar> $ek  lig, tur, takim, mac, gun, ulke
 */
function spor_bolum_yolu(string $bolum = '', array $ek = []): string
{
    $yol = kategori_yolu('spor');
    $parametre = array_filter(['bolum' => $bolum] + $ek, static fn ($d): bool => $d !== '' && $d !== 0 && $d !== null);

    if ($parametre === []) {
        return $yol;
    }

    return $yol . (str_contains($yol, '?') ? '&' : '?') . http_build_query($parametre);
}

/**
 * İstekteki spor parametreleri, doğrulanmış.
 *
 * @return array{lig:string,tur:string,takim:int,mac:int,gun:string,ulke:string}
 */
function spor_parametreleri(array $kaynak): array
{
    $lig = strtolower(trim((string) ($kaynak['lig'] ?? '')));
    $tur = trim((string) ($kaynak['tur'] ?? ''));
    $gun = trim((string) ($kaynak['gun'] ?? ''));
    $ulke = trim((string) ($kaynak['ulke'] ?? ''));

    return [
        'lig'   => futbol_lig_kodu_gecerli($lig) ? $lig : FUTBOL_VARSAYILAN_LIG,
        'tur'   => preg_match('/^\d{8}-\d{8}$/', $tur) ? $tur : '',
        'takim' => max(0, (int) ($kaynak['takim'] ?? 0)),
        'mac'   => max(0, (int) ($kaynak['mac'] ?? 0)),
        'gun'   => preg_match('/^\d{8}$/', $gun) ? $gun : '',
        'ulke'  => isset(FUTBOL_LIGLER[$ulke]) ? $ulke : '',
    ];
}

/** Bölümler arasında geçiş sekmeleri. */
function spor_sekmeleri_html(string $aktif, string $lig): string
{
    // Once guncel haberler (site sahibinin istegi, 23.10.2026).
    $sekmeler = ['' => 'Güncel Spor Haberleri'] + SPOR_BOLUMLERI;

    if (!futbol_api_acik()) {
        unset($sekmeler['gol-kralligi']);
    }

    ob_start();
    ?>
    <nav class="spor-sekmeler" aria-label="Spor bölümleri">
        <?php foreach ($sekmeler as $bolum => $ad): ?>
            <?php $ek = $bolum !== '' && $lig !== FUTBOL_VARSAYILAN_LIG ? ['lig' => $lig] : []; ?>
            <a href="<?= e(spor_bolum_yolu((string) $bolum, $ek)) ?>"<?= (string) $bolum === $aktif ? ' class="aktif" aria-current="page"' : '' ?>><?= e($ad) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php

    return (string) ob_get_clean();
}

/**
 * Lig seçici: öne çıkan ligler, ülke ve o ülkenin ligleri.
 *
 * Form GET; adres temiz adres kapaliyken kategori.php?k=spor oldugu icin
 * sorgu parametreleri gizli alan olarak tasiniyor (GET formu action'daki
 * sorguyu siler).
 *
 * @param array{lig:string,tur:string,takim:int,mac:int,gun:string,ulke:string} $p
 */
function spor_secici_html(string $bolum, array $p): string
{
    if (!futbol_api_acik()) {
        return '';
    }

    $ulke   = $p['ulke'] !== '' ? $p['ulke'] : (futbol_lig_ulkesi($p['lig']) ?: 'Türkiye');
    $ligler = FUTBOL_LIGLER[$ulke] ?? [];

    $hedef = kategori_yolu('spor');
    [$yol, $sorgu] = array_pad(explode('?', $hedef, 2), 2, '');
    parse_str($sorgu, $gizli);

    ob_start();
    ?>
    <div class="lig-secici">
        <div class="lig-hizli">
            <?php foreach (FUTBOL_ONE_CIKAN_LIGLER as $kod => $ad): ?>
                <a href="<?= e(spor_bolum_yolu($bolum, ['lig' => $kod])) ?>"<?= $kod === $p['lig'] ? ' class="aktif"' : '' ?>><?= e($ad) ?></a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= e($yol) ?>" class="lig-form">
            <?php foreach ($gizli as $ad => $deger): ?>
                <input type="hidden" name="<?= e((string) $ad) ?>" value="<?= e((string) $deger) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="bolum" value="<?= e($bolum) ?>">

            <label>
                <span>Ülke</span>
                <select name="ulke" onchange="this.form.lig.value='';this.form.submit()">
                    <?php foreach (array_keys(FUTBOL_LIGLER) as $u): ?>
                        <option value="<?= e($u) ?>"<?= $u === $ulke ? ' selected' : '' ?>><?= e($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Lig</span>
                <select name="lig" onchange="this.form.submit()">
                    <?php foreach ($ligler as $kod => $ad): ?>
                        <option value="<?= e($kod) ?>"<?= $kod === $p['lig'] ? ' selected' : '' ?>><?= e($ad) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <noscript><button type="submit" class="dugme">Göster</button></noscript>
        </form>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Ülke değişti ama lig o ülkenin değilse ülkenin ilk ligi.
 *
 * @param array{lig:string,tur:string,takim:int,mac:int,gun:string,ulke:string} $p
 * @return array{lig:string,tur:string,takim:int,mac:int,gun:string,ulke:string}
 */
function spor_ulke_ligine_uyarla(array $p): array
{
    $ligler = FUTBOL_LIGLER[$p['ulke']] ?? [];

    if ($ligler !== [] && !isset($ligler[$p['lig']])) {
        $p['lig'] = (string) array_key_first($ligler);
        $p['tur'] = '';
    }

    return $p;
}

/**
 * Bir bölümün HTML'i. Veri yoksa boş (sayfa "alınamıyor" der).
 *
 * @param array{lig:string,tur:string,takim:int,mac:int,gun:string,ulke:string} $p
 */
function spor_paneli_html(string $bolum, array $p): string
{
    if (!isset(SPOR_BOLUMLERI[$bolum]) && !isset(SPOR_ALT_BOLUMLER[$bolum])) {
        return '';
    }

    if (!futbol_api_acik()) {
        return spor_tff_paneli_html($bolum);
    }

    if ($bolum === 'mac') {
        return spor_mac_html($p['mac'], $p['lig'], $p['gun']);
    }

    if ($bolum === 'kulup') {
        return spor_kulup_html($p['takim'], $p['lig']);
    }

    $lig = futbol_lig($p['lig']);

    if ($lig === null) {
        // ESPN Super Lig'i veremiyorsa TFF'ye dus.
        return $p['lig'] === FUTBOL_VARSAYILAN_LIG ? spor_tff_paneli_html($bolum) : '';
    }

    [$govde, $zaman, $yenile] = match ($bolum) {
        'puan-durumu'  => spor_api_puan_html($lig),
        'fikstur'      => spor_api_fikstur_html($lig, $p['tur']),
        default        => spor_api_gol_html($lig),
    };

    if ($govde === '') {
        return $p['lig'] === FUTBOL_VARSAYILAN_LIG ? spor_tff_paneli_html($bolum) : '';
    }

    $canli = '/api/spor.php?' . http_build_query(['bolum' => $bolum, 'lig' => $lig['id'], 'tur' => $p['tur']]);

    ob_start();
    ?>
    <section class="spor-panel"<?= $yenile ? ' data-canli-bolum="' . e($canli) . '"' : '' ?> aria-label="<?= e($lig['ad'] . ' ' . SPOR_BOLUMLERI[$bolum]) ?>">
        <div class="canli-panel-ust">
            <h2>
                <?php if ($lig['logo'] !== ''): ?><img src="<?= e($lig['logo']) ?>" alt="" class="lig-logo" loading="lazy" width="28" height="28"><?php endif; ?>
                <?= e($lig['ad']) ?> &middot; <?= e(SPOR_BOLUMLERI[$bolum]) ?>
            </h2>
            <span class="canli-not"><?= e($lig['sezon']) ?> &middot; kaynak ESPN &middot; güncelleme <?= e(canli_zaman_yazi($zaman)) ?></span>
        </div>

        <?= $govde ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

// --- Puan durumu, fikstür, gol krallığı ------------------------------------

/** @return array{0:string,1:int,2:bool} html, zaman, canli yenilensin mi */
function spor_api_puan_html(array $lig): array
{
    $kayit = futbol_puan_durumu($lig['id']);

    if ($kayit['veri'] === null) {
        return ['', 0, false];
    }

    $notlar = [];

    ob_start();
    foreach ($kayit['veri'] as $grup):
        ?>
        <div class="spor-kutu spor-puan">
            <?php if (count($kayit['veri']) > 1 && $grup[0]['grup'] !== ''): ?>
                <h3><?= e($grup[0]['grup']) ?></h3>
            <?php endif; ?>
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
                        <?php foreach ($grup as $s): ?>
                            <?php if ($s['not'] !== '') { $notlar[$s['not']] = true; } ?>
                            <tr<?= $s['not'] !== '' ? ' title="' . e($s['not']) . '"' : '' ?>>
                                <td class="sira"><?= $s['sira'] ?></td>
                                <td class="takim">
                                    <a href="<?= e(spor_bolum_yolu('kulup', ['takim' => $s['id'], 'lig' => $lig['id']])) ?>">
                                        <?php if ($s['logo'] !== ''): ?><img src="<?= e($s['logo']) ?>" alt="" loading="lazy" width="18" height="18"><?php endif; ?>
                                        <?= e($s['takim']) ?>
                                    </a>
                                </td>
                                <td><?= $s['o'] ?></td>
                                <td><?= $s['g'] ?></td>
                                <td><?= $s['b'] ?></td>
                                <td><?= $s['m'] ?></td>
                                <td class="genis"><?= $s['a'] ?></td>
                                <td class="genis"><?= $s['y'] ?></td>
                                <td><?= $s['av'] > 0 ? '+' : '' ?><?= $s['av'] ?></td>
                                <td class="puan"><?= $s['p'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    endforeach;

    if ($notlar !== []):
        ?>
        <p class="canli-not">Sıra açıklamaları (satırın üstüne gelince görünür): <?= e(implode(' · ', array_keys($notlar))) ?></p>
        <?php
    endif;

    return [(string) ob_get_clean(), $kayit['zaman'], true];
}

/** "WWDLW" -> renkli G/B/M rozetleri. */
function spor_form_html(string $form): string
{
    $harf = ['W' => 'G', 'D' => 'B', 'L' => 'M'];
    $html = '';

    foreach (str_split(substr($form, -5)) as $h) {
        if (isset($harf[$h])) {
            $html .= '<span class="form-' . strtolower($harf[$h]) . '">' . $harf[$h] . '</span>';
        }
    }

    return $html !== '' ? '<span class="form-dizi">' . $html . '</span>' : '';
}

/** @return array{0:string,1:int,2:bool} */
function spor_api_fikstur_html(array $lig, string $tur): array
{
    $turlar = futbol_turlar_takvimden($lig['takvim']);
    $guncel = futbol_guncel_tur($turlar);

    if ($tur === '' || !in_array($tur, $turlar, true)) {
        $tur = $guncel;
    }

    if ($tur === '') {
        return ['', 0, false];
    }

    $kayit   = futbol_tarih_maclari($lig['id'], $tur);
    $maclar  = $kayit['veri'] ?? [];
    $sira    = array_search($tur, $turlar, true);
    $onceki  = $sira !== false && $sira > 0 ? $turlar[$sira - 1] : '';
    $sonraki = $sira !== false && isset($turlar[$sira + 1]) ? $turlar[$sira + 1] : '';

    ob_start();
    ?>
    <div class="spor-kutu">
        <div class="tur-gezgin">
            <?php if ($onceki !== ''): ?>
                <a href="<?= e(spor_bolum_yolu('fikstur', ['lig' => $lig['id'], 'tur' => $onceki])) ?>">&larr; <?= e(futbol_tur_adi($onceki)) ?></a>
            <?php else: ?><span></span><?php endif; ?>

            <strong><?= e(futbol_tur_adi($tur)) ?><?= $tur === $guncel ? ' (bu hafta)' : '' ?></strong>

            <?php if ($sonraki !== ''): ?>
                <a href="<?= e(spor_bolum_yolu('fikstur', ['lig' => $lig['id'], 'tur' => $sonraki])) ?>"><?= e(futbol_tur_adi($sonraki)) ?> &rarr;</a>
            <?php else: ?><span></span><?php endif; ?>
        </div>

        <?php if ($maclar === []): ?>
            <p class="canli-not">Bu günlerin maçları alınamadı.</p>
        <?php endif; ?>

        <?php $gun = ''; ?>
        <?php foreach ($maclar as $m): ?>
            <?php if (futbol_tarih($m['zaman']) !== $gun): ?>
                <?php if ($gun !== ''): ?></ul><?php endif; ?>
                <?php $gun = futbol_tarih($m['zaman']); ?>
                <h3><?= e($gun) ?></h3>
                <ul class="mac-listesi sonuclar">
            <?php endif; ?>
            <?= spor_mac_satiri_html($m, $lig['id']) ?>
        <?php endforeach; ?>
        <?php if ($gun !== ''): ?></ul><?php endif; ?>

        <?php if (count($turlar) > 1): ?>
            <details class="tur-listesi">
                <summary>Sezonun bütün maç günleri</summary>
                <p>
                    <?php foreach ($turlar as $t): ?>
                        <a href="<?= e(spor_bolum_yolu('fikstur', ['lig' => $lig['id'], 'tur' => $t])) ?>"<?= $t === $tur ? ' class="aktif"' : '' ?>><?= e(futbol_tur_adi($t)) ?></a>
                    <?php endforeach; ?>
                </p>
            </details>
        <?php endif; ?>
    </div>
    <?php

    // Yalnizca canli ya da bugunku maci olan tur yenileniyor.
    $yenile = false;

    foreach ($maclar as $m) {
        if (in_array($m['durum'], FUTBOL_CANLI, true) || date('Y-m-d', $m['zaman']) === date('Y-m-d')) {
            $yenile = true;
        }
    }

    return [(string) ob_get_clean(), $kayit['zaman'], $yenile];
}

/** Fikstür ve kulüp sayfasındaki maç satırı. */
function spor_mac_satiri_html(array $m, string $lig, string $ust = ''): string
{
    $canli   = in_array($m['durum'], FUTBOL_CANLI, true);
    $oynandi = $canli || in_array($m['durum'], FUTBOL_BITTI, true);
    $macLig  = futbol_lig_kodu_gecerli((string) $m['lig_id']) ? (string) $m['lig_id'] : $lig;
    $kulup   = static fn (int $id): string => spor_bolum_yolu('kulup', ['takim' => $id, 'lig' => $macLig]);

    ob_start();
    ?>
    <li<?= $canli ? ' class="canli"' : '' ?>>
        <?php if ($ust !== '' || $canli): ?>
            <span class="mac-zaman"><?= e($ust) ?><?= $canli ? ' <b class="canli-rozet">' . ($m['durum'] === 'HT' ? 'Devre arası' : (int) $m['dakika'] . "'") . '</b>' : '' ?></span>
        <?php endif; ?>
        <a class="mac-ev" href="<?= e($kulup($m['ev_id'])) ?>"><?= e($m['ev']) ?></a>
        <a class="mac-skor<?= $oynandi ? '' : ' oynanmadi' ?>" href="<?= e(spor_bolum_yolu('mac', ['mac' => $m['id'], 'lig' => $macLig, 'gun' => futbol_espn_gunu($m['zaman'])])) ?>"><?= e(futbol_skor_yazi($m)) ?></a>
        <a class="mac-dep" href="<?= e($kulup($m['dep_id'])) ?>"><?= e($m['dep']) ?></a>
    </li>
    <?php

    return (string) ob_get_clean();
}

/** @return array{0:string,1:int,2:bool} */
function spor_api_gol_html(array $lig): array
{
    $kayit = futbol_gol_kralligi($lig['id']);

    if ($kayit['veri'] === null) {
        return ['', 0, false];
    }

    ob_start();
    ?>
    <div class="spor-kutu">
        <div class="tablo-kaydir">
            <table class="puan-tablosu">
                <thead>
                    <tr>
                        <th scope="col" class="sira">#</th>
                        <th scope="col" class="takim">Oyuncu</th>
                        <th scope="col" class="takim genis">Takım</th>
                        <th scope="col" title="Maç">M</th>
                        <th scope="col" title="Gol" class="puan">Gol</th>
                        <th scope="col" title="Asist">Asist</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kayit['veri'] as $i => $o): ?>
                        <tr>
                            <td class="sira"><?= $i + 1 ?></td>
                            <td class="takim"><?= e($o['oyuncu']) ?></td>
                            <td class="takim genis">
                                <a href="<?= e(spor_bolum_yolu('kulup', ['takim' => $o['takim_id'], 'lig' => $lig['id']])) ?>">
                                    <?php if ($o['logo'] !== ''): ?><img src="<?= e($o['logo']) ?>" alt="" loading="lazy" width="18" height="18"><?php endif; ?>
                                    <?= e($o['takim']) ?>
                                </a>
                            </td>
                            <td><?= $o['mac'] ?></td>
                            <td class="puan"><?= $o['gol'] ?></td>
                            <td><?= $o['asist'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php

    return [(string) ob_get_clean(), $kayit['zaman'], false];
}

// --- Kulüp: analiz, son maçlar ve golleri ----------------------------------

function spor_kulup_html(int $takim, string $lig): string
{
    if ($takim <= 0) {
        return '';
    }

    $maclar  = futbol_kulup_maclari($takim, $lig);
    $ayrinti = futbol_mac_ayrintilari(array_slice($maclar['son'], 0, 15));
    $ist     = futbol_kulup_analizi($takim, $maclar['son'], $ayrinti, $maclar['ad'], $maclar['logo'], $lig);

    if ($maclar['ad'] === '' && $maclar['son'] === [] && $maclar['sirada'] === []) {
        return '';
    }

    // Son maclarin gollerinden golcu listesi (kendi kalesine atilan haric).
    $golculer = [];

    foreach ($ayrinti as $a) {
        foreach ($a['olaylar'] as $o) {
            if ($o['tip'] === 'Goal' && $o['takim'] === $takim && $o['detay'] !== 'Own Goal' && $o['oyuncu'] !== '') {
                $golculer[$o['oyuncu']] = ($golculer[$o['oyuncu']] ?? 0) + 1;
            }
        }
    }

    arsort($golculer);
    $son = array_slice($maclar['son'], 0, 15);

    ob_start();
    ?>
    <section class="spor-panel kulup-panel" aria-label="<?= e($maclar['ad']) ?>">
        <div class="kulup-baslik">
            <?php if ($maclar['logo'] !== ''): ?><img src="<?= e($maclar['logo']) ?>" alt="" width="56" height="56"><?php endif; ?>
            <div>
                <h2><?= e($maclar['ad']) ?></h2>
                <span class="canli-not">
                    <?= e(futbol_lig_katalog_adi($lig) ?: $lig) ?>
                    <?= $maclar['ozet'] !== '' ? ' &middot; ' . e($maclar['ozet']) : '' ?>
                    &middot; kaynak ESPN &middot; güncelleme <?= e(canli_zaman_yazi($maclar['zaman'])) ?>
                </span>
            </div>
        </div>

        <?php if ($ist !== null): ?>
            <?= spor_kulup_analiz_html($ist) ?>
        <?php endif; ?>

        <?php if ($golculer !== []): ?>
            <div class="spor-kutu">
                <h3>Son <?= count($son) ?> maçın golcüleri</h3>
                <ul class="golcu-listesi">
                    <?php foreach (array_slice($golculer, 0, 10, true) as $oyuncu => $gol): ?>
                        <li><span><?= e((string) $oyuncu) ?></span><b><?= (int) $gol ?></b></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($son !== []): ?>
            <div class="spor-kutu">
                <h3>Son maçlar</h3>
                <ul class="mac-listesi sonuclar kulup-maclari">
                    <?php foreach ($son as $m): ?>
                        <?php $harf = futbol_sonuc_harfi($m, $takim); ?>
                        <?= spor_mac_satiri_html($m, $lig, futbol_tarih($m['zaman']) . ' · ' . $m['lig']) ?>
                        <?php if ($harf !== ''): ?>
                            <li class="mac-ek"><span class="form-dizi"><span class="form-<?= strtolower($harf) ?>"><?= $harf ?></span></span>
                                <?= spor_gol_ozeti_html($ayrinti[$m['id']] ?? null, $m) ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($maclar['sirada'] !== []): ?>
            <div class="spor-kutu">
                <h3>Sıradaki maçlar</h3>
                <ul class="mac-listesi sonuclar">
                    <?php foreach ($maclar['sirada'] as $m): ?>
                        <?= spor_mac_satiri_html($m, $lig, futbol_tarih($m['zaman']) . ' · ' . $m['lig']) ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

/** Maçın golleri tek satırda: "Icardi 23', Mertens 67' (P) — Dzeko 80'". */
function spor_gol_ozeti_html(?array $ayrinti, array $m): string
{
    if ($ayrinti === null) {
        return '';
    }

    $taraf = [$m['ev_id'] => [], $m['dep_id'] => []];

    foreach ($ayrinti['olaylar'] as $o) {
        if ($o['tip'] !== 'Goal') {
            continue;
        }

        // Kendi kalesine gol, golu yiyen takimin oyuncusu adina geliyor:
        // skor tablosunda karsi takimin hanesine yazilir.
        $hane = $o['detay'] === 'Own Goal'
            ? ($o['takim'] === $m['ev_id'] ? $m['dep_id'] : $m['ev_id'])
            : $o['takim'];

        if (!isset($taraf[$hane])) {
            continue;
        }

        $ek = match ($o['detay']) {
            'Penalty'  => ' (P)',
            'Own Goal' => ' (K.K.)',
            default    => '',
        };

        $taraf[$hane][] = $o['oyuncu'] . ' ' . $o['dk'] . ($o['ek'] > 0 ? '+' . $o['ek'] : '') . "'" . $ek;
    }

    $ev  = implode(', ', $taraf[$m['ev_id']]);
    $dep = implode(', ', $taraf[$m['dep_id']]);

    if ($ev === '' && $dep === '') {
        return '<span class="soluk">Gol yok</span>';
    }

    return '<span class="gol-ozeti">' . e($ev !== '' ? $ev : '-') . ' &mdash; ' . e($dep !== '' ? $dep : '-') . '</span>';
}

/** Kulübün sezon analizi (maç sonuçlarından hesaplanan). */
function spor_kulup_analiz_html(array $s): string
{
    $sayi = static fn ($d): string => $d === null || $d === '' ? '-' : e((string) $d);
    $satir = static fn (string $ad, array $d): string => '<tr><th scope="row">' . e($ad) . '</th><td>'
        . $sayi($d['ic']) . '</td><td>' . $sayi($d['dis']) . '</td><td class="puan">' . $sayi($d['top']) . '</td></tr>';

    $oynanan = (int) ($s['oynanan']['top'] ?? 0);
    $galibiyetOrani = $oynanan > 0 ? round(100 * (int) $s['galibiyet']['top'] / $oynanan) : null;

    ob_start();
    ?>
    <div class="kulup-ozet">
        <div class="spor-kutu ozet-kart"><span>Son 5 maç</span><?= spor_form_html($s['form']) ?: '-' ?></div>
        <div class="spor-kutu ozet-kart"><span>Galibiyet oranı</span><b><?= $galibiyetOrani !== null ? '%' . $galibiyetOrani : '-' ?></b></div>
        <div class="spor-kutu ozet-kart"><span>Maç başı gol</span><b><?= $sayi($s['atilan_ort']['top']) ?> / <?= $sayi($s['yenilen_ort']['top']) ?></b><small>atılan / yenilen</small></div>
        <div class="spor-kutu ozet-kart"><span>Gol yemeden</span><b><?= $sayi($s['gol_yemeden']['top']) ?></b><small>maç</small></div>
    </div>

    <div class="spor-izgara">
        <div class="spor-kutu">
            <h3>Sezon istatistiği</h3>
            <div class="tablo-kaydir">
                <table class="puan-tablosu analiz-tablosu">
                    <thead><tr><th></th><th scope="col">İç saha</th><th scope="col">Deplasman</th><th scope="col" class="puan">Toplam</th></tr></thead>
                    <tbody>
                        <?= $satir('Oynanan', $s['oynanan']) ?>
                        <?= $satir('Galibiyet', $s['galibiyet']) ?>
                        <?= $satir('Beraberlik', $s['beraberlik']) ?>
                        <?= $satir('Mağlubiyet', $s['maglubiyet']) ?>
                        <?= $satir('Atılan gol', $s['atilan']) ?>
                        <?= $satir('Yenilen gol', $s['yenilen']) ?>
                        <?= $satir('Maç başı atılan', $s['atilan_ort']) ?>
                        <?= $satir('Maç başı yenilen', $s['yenilen_ort']) ?>
                        <?= $satir('Gol yemeden', $s['gol_yemeden']) ?>
                        <?= $satir('Gol atamadan', $s['gol_atamadan']) ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="spor-kutu">
            <h3>Seriler ve rekorlar</h3>
            <ul class="analiz-listesi">
                <li><span>En uzun galibiyet serisi</span><b><?= (int) $s['seri']['galibiyet'] ?></b></li>
                <li><span>En uzun beraberlik serisi</span><b><?= (int) $s['seri']['beraberlik'] ?></b></li>
                <li><span>En uzun mağlubiyet serisi</span><b><?= (int) $s['seri']['maglubiyet'] ?></b></li>
                <li><span>En farklı galibiyet (iç / dış)</span><b><?= $sayi($s['en_farkli_galibiyet']['ic']) ?> / <?= $sayi($s['en_farkli_galibiyet']['dis']) ?></b></li>
                <li><span>En farklı mağlubiyet (iç / dış)</span><b><?= $sayi($s['en_farkli_maglubiyet']['ic']) ?> / <?= $sayi($s['en_farkli_maglubiyet']['dis']) ?></b></li>
                <?php if (($s['dakika'] ?? []) !== []): ?>
                    <li><span>Penaltı golü</span><b><?= (int) $s['penalti']['atilan'] ?></b></li>
                <?php endif; ?>
            </ul>

            <?php if (($s['dakika']['atilan'] ?? []) !== []): ?>
                <h3>Gollerin dakikaları</h3>
                <?php $enCok = max(1, ...array_values($s['dakika']['atilan']), ...array_values($s['dakika']['yenilen'])); ?>
                <ul class="dakika-dagilimi">
                    <?php foreach ($s['dakika']['atilan'] as $aralik => $atilan): ?>
                        <?php $yenilen = (int) ($s['dakika']['yenilen'][$aralik] ?? 0); ?>
                        <li>
                            <span class="aralik"><?= e((string) $aralik) ?></span>
                            <span class="cubuk atilan" style="width:<?= round(100 * $atilan / $enCok) ?>%" title="Atılan <?= (int) $atilan ?>"><?= (int) $atilan ?: '' ?></span>
                            <span class="cubuk yenilen" style="width:<?= round(100 * $yenilen / $enCok) ?>%" title="Yenilen <?= $yenilen ?>"><?= $yenilen ?: '' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="canli-not"><span class="lejant atilan"></span> atılan &nbsp; <span class="lejant yenilen"></span> yenilen</p>
            <?php endif; ?>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

// --- Maç ayrıntısı ------------------------------------------------------------

function spor_mac_html(int $macId, string $lig, string $gun): string
{
    $m = $macId > 0 ? futbol_mac_ayrintisi($macId, $lig, $gun) : null;

    if ($m === null) {
        return '';
    }

    $canli = in_array($m['durum'], FUTBOL_CANLI, true);
    $kulup = static fn (int $id): string => spor_bolum_yolu('kulup', ['takim' => $id, 'lig' => $m['lig_id']]);

    ob_start();
    ?>
    <section class="spor-panel mac-panel"<?= $canli ? ' data-canli-bolum="' . e('/api/spor.php?' . http_build_query(['bolum' => 'mac', 'mac' => $macId, 'lig' => $lig, 'gun' => $gun])) . '" data-canli-aralik="60"' : '' ?>>
        <div class="spor-kutu mac-ust">
            <p class="canli-not">
                <a href="<?= e(spor_bolum_yolu('fikstur', ['lig' => $m['lig_id']])) ?>"><?= e($m['lig']) ?></a>
                &middot; <?= e(futbol_tarih($m['zaman'])) ?> <?= e(date('H:i', $m['zaman'])) ?>
                <?= $m['stat'] !== '' ? ' &middot; ' . e($m['stat']) : '' ?>
            </p>
            <div class="mac-skorbord">
                <a href="<?= e($kulup($m['ev_id'])) ?>" class="takim">
                    <?php if ($m['ev_logo'] !== ''): ?><img src="<?= e($m['ev_logo']) ?>" alt="" width="48" height="48"><?php endif; ?>
                    <span><?= e($m['ev']) ?></span>
                </a>
                <div class="skor">
                    <b><?= e(futbol_skor_yazi($m)) ?></b>
                    <?php if ($canli): ?>
                        <span class="canli-rozet"><?= $m['durum'] === 'HT' ? 'Devre arası' : (int) $m['dakika'] . "'" ?></span>
                    <?php endif; ?>
                </div>
                <a href="<?= e($kulup($m['dep_id'])) ?>" class="takim">
                    <?php if ($m['dep_logo'] !== ''): ?><img src="<?= e($m['dep_logo']) ?>" alt="" width="48" height="48"><?php endif; ?>
                    <span><?= e($m['dep']) ?></span>
                </a>
            </div>
        </div>

        <?php if ($m['olaylar'] !== []): ?>
            <div class="spor-kutu">
                <h3>Goller ve kartlar</h3>
                <ul class="olay-listesi">
                    <?php foreach ($m['olaylar'] as $o): ?>
                        <?php
                        $simge = match (true) {
                            $o['tip'] === 'Goal'          => '⚽',
                            $o['detay'] === 'Yellow Card' => '🟨',
                            $o['detay'] === 'Red Card'    => '🟥',
                            default                       => '•',
                        };
                        $detay = match ($o['detay']) {
                            'Penalty'  => 'penaltı',
                            'Own Goal' => 'kendi kalesine',
                            default    => '',
                        };
                        ?>
                        <li class="<?= $o['takim'] === $m['ev_id'] ? 'ev' : 'dep' ?>">
                            <span class="dk"><?= $o['dk'] ?><?= $o['ek'] > 0 ? '+' . $o['ek'] : '' ?>'</span>
                            <span class="simge"><?= e($simge) ?></span>
                            <span class="kim"><?= e($o['oyuncu']) ?><?= $detay !== '' ? ' <small>(' . e($detay) . ')</small>' : '' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php $istatistikVar = array_intersect_key(SPOR_ISTATISTIK_ADLARI, $m['istatistik']) !== []; ?>
        <?php if ($istatistikVar): ?>
            <div class="spor-kutu">
                <h3>İstatistikler</h3>
                <table class="puan-tablosu mac-istatistik">
                    <thead><tr><th scope="col"><?= e($m['ev']) ?></th><th></th><th scope="col"><?= e($m['dep']) ?></th></tr></thead>
                    <tbody>
                        <?php foreach (SPOR_ISTATISTIK_ADLARI as $kod => $ad): ?>
                            <?php if (!isset($m['istatistik'][$kod])) { continue; } ?>
                            <tr>
                                <td><?= e((string) ($m['istatistik'][$kod][$m['ev_id']] ?? '-')) ?></td>
                                <th scope="row"><?= e($ad) ?></th>
                                <td><?= e((string) ($m['istatistik'][$kod][$m['dep_id']] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="canli-not">Kaynak ESPN.</p>
    </section>
    <?php

    return (string) ob_get_clean();
}

// --- TFF (ESPN kapalıyken ya da Süper Lig veremediğinde) -------------------

function spor_tff_paneli_html(string $bolum): string
{
    if ($bolum !== 'puan-durumu' && $bolum !== 'fikstur') {
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
 * TFF fikstürü: bu haftanın maçları (tarih ve saatle), geçen haftanın
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
