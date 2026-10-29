<?php
declare(strict_types=1);

/**
 * Basketbol verileri: puan durumu (ValSpor'un Basketbol sayfasi).
 *
 * Site sahibinin istegi (30.10.2026): "futbola bastigimizda puan durumu,
 * gol kralligi gibi istatistikler; baskette de basketbolun verileri".
 *
 * Kaynak futbolla ayni: ESPN'in acik ucu (includes/futbol.php; istek
 * siniri, onbellek ve panelden kapatma ortak). ESPN Avrupa basketbolunu
 * vermiyor; EuroLeague kendi acik ucundan (api-live.euroleague.net, XML).
 * Sinananlar (30.10.2026, GitHub Actions): TBF (Basketbol Super Ligi) 403
 * veriyor.
 */

require_once __DIR__ . '/futbol.php';
require_once __DIR__ . '/canli_onbellek.php';
require_once __DIR__ . '/http_ortak.php';

/** EuroLeague sezon kodu: 2026-27 sezonu "E2026" (sezon agustosta basliyor). */
function euroleague_sezonu(): string
{
    $simdi = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
    $yil   = (int) $simdi->format('Y');

    return 'E' . ((int) $simdi->format('n') >= 8 ? $yil : $yil - 1);
}

/**
 * EuroLeague normal sezon puan durumu.
 *
 * @return array{veri:?array,zaman:int}
 */
function euroleague_puan_durumu(): array
{
    $sezon = euroleague_sezonu();

    return canli_onbellek('euroleague_' . $sezon, 1800, static function () use ($sezon): ?array {
        $sonuc = http_getir('https://api-live.euroleague.net/v1/standings?seasonCode=' . $sezon, 15);

        if (!$sonuc['tamam']) {
            error_log('[valentra] euroleague: ' . $sonuc['neden']);

            return null;
        }

        $satirlar = euroleague_xml_coz((string) $sonuc['govde']);

        return $satirlar !== [] ? $satirlar : null;
    });
}

/**
 * EuroLeague puan durumu XML'i: <standings><group><team><name/><ranking/>
 * <totalgames/><wins/><losses/>... (ilk grup normal sezon).
 *
 * @return list<array{sira:int,takim:string,o:int,g:int,m:int,fark:?int}>
 */
function euroleague_xml_coz(string $govde): array
{
    $xml = @simplexml_load_string($govde, SimpleXMLElement::class, LIBXML_NONET);

    if ($xml === false) {
        return [];
    }

    $satirlar = [];

    // Ilk grup normal sezon; playoff gruplari varsa ayri.
    foreach ($xml->group[0]->team ?? [] as $t) {
        $atilan  = isset($t->ptsfavour) ? (int) $t->ptsfavour : null;
        $yenilen = isset($t->ptsagainst) ? (int) $t->ptsagainst : null;

        $satirlar[] = [
            'sira'  => (int) $t->ranking,
            'takim' => (string) $t->name,
            'o'     => (int) $t->totalgames,
            'g'     => (int) $t->wins,
            'm'     => (int) $t->losses,
            'fark'  => isset($t->difference) ? (int) $t->difference
                : ($atilan !== null && $yenilen !== null ? $atilan - $yenilen : null),
        ];
    }

    usort($satirlar, static fn (array $a, array $b): int => $a['sira'] <=> $b['sira']);

    return $satirlar;
}

function euroleague_puan_html(): string
{
    $kayit = euroleague_puan_durumu();

    if ($kayit['veri'] === null) {
        return '';
    }

    $farkVar = array_filter(array_column($kayit['veri'], 'fark'), static fn ($f): bool => $f !== null) !== [];

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
                        <th scope="col" title="Galibiyet" class="puan">G</th>
                        <th scope="col" title="Mağlubiyet">M</th>
                        <?php if ($farkVar): ?><th scope="col" title="Sayı averajı">AV</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kayit['veri'] as $s): ?>
                        <tr>
                            <td class="sira"><?= $s['sira'] ?></td>
                            <td class="takim"><?= e($s['takim']) ?></td>
                            <td><?= $s['o'] ?></td>
                            <td class="puan"><?= $s['g'] ?></td>
                            <td><?= $s['m'] ?></td>
                            <?php if ($farkVar): ?><td><?= $s['fark'] !== null && $s['fark'] > 0 ? '+' : '' ?><?= $s['fark'] ?? '' ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="canli-not">Kaynak: EuroLeague<?= $kayit['zaman'] > 0 ? ' · güncelleme ' . e(date('H:i', $kayit['zaman'])) : '' ?></p>
    <?php

    return (string) ob_get_clean();
}

/** ESPN lig kodu => ad. */
const BASKETBOL_LIGLERI = [
    'nba' => 'NBA',
];

/**
 * Konferans konferans puan durumu, sirali.
 *
 * @return array{veri:?array,zaman:int}
 */
function basketbol_puan_durumu(string $lig): array
{
    return futbol_onbellekli('/v2/sports/basketball/' . $lig . '/standings', [], 1800, static function (array $c): ?array {
        $gruplar = [];

        foreach ($c['children'] ?? [] as $grup) {
            $satirlar = [];

            foreach ($grup['standings']['entries'] ?? [] as $s) {
                $ist = [];
                $yazi = [];

                foreach ($s['stats'] ?? [] as $d) {
                    $ad        = (string) ($d['name'] ?? '');
                    $ist[$ad]  = $d['value'] ?? null;
                    $yazi[$ad] = (string) ($d['displayValue'] ?? '');
                }

                $satirlar[] = [
                    'sira'   => (int) ($ist['playoffSeed'] ?? 0),
                    'takim'  => (string) ($s['team']['displayName'] ?? ''),
                    'logo'   => (string) ($s['team']['logos'][0]['href'] ?? ''),
                    'g'      => (int) ($ist['wins'] ?? 0),
                    'm'      => (int) ($ist['losses'] ?? 0),
                    'yuzde'  => (float) ($ist['winPercent'] ?? 0),
                    'fark'   => $yazi['gamesBehind'] ?? '',
                    'seri'   => $yazi['streak'] ?? '',
                    'atilan' => $yazi['avgPointsFor'] ?? '',
                    'yenilen'=> $yazi['avgPointsAgainst'] ?? '',
                ];
            }

            // ESPN sirasi her zaman gelmiyor (sezon basi): galibiyet yuzdesi.
            usort($satirlar, static fn (array $a, array $b): int => $a['sira'] > 0 && $b['sira'] > 0
                ? $a['sira'] <=> $b['sira']
                : [$b['yuzde'], $b['g']] <=> [$a['yuzde'], $a['g']]);

            if ($satirlar !== []) {
                $gruplar[] = ['ad' => basketbol_grup_adi((string) ($grup['name'] ?? '')), 'satirlar' => $satirlar];
            }
        }

        return $gruplar !== [] ? $gruplar : null;
    });
}

function basketbol_grup_adi(string $ad): string
{
    return ['Eastern Conference' => 'Doğu Konferansı', 'Western Conference' => 'Batı Konferansı'][$ad] ?? $ad;
}

/** Puan durumu tablolari; veri yoksa bos. */
function basketbol_puan_html(string $lig): string
{
    $kayit = basketbol_puan_durumu($lig);

    if ($kayit['veri'] === null) {
        return '';
    }

    ob_start();
    ?>
    <div class="basketbol-puan">
        <?php foreach ($kayit['veri'] as $grup): ?>
            <div class="spor-kutu spor-puan">
                <h3><?= e($grup['ad']) ?></h3>
                <div class="tablo-kaydir">
                    <table class="puan-tablosu">
                        <thead>
                            <tr>
                                <th scope="col" class="sira">#</th>
                                <th scope="col" class="takim">Takım</th>
                                <th scope="col" title="Galibiyet">G</th>
                                <th scope="col" title="Mağlubiyet">M</th>
                                <th scope="col" title="Galibiyet yüzdesi" class="puan">%</th>
                                <th scope="col" title="Liderle galibiyet farkı" class="genis">Fark</th>
                                <th scope="col" title="Seri (G: galibiyet, M: mağlubiyet)">Seri</th>
                                <th scope="col" title="Maç başına atılan sayı" class="genis">Atılan</th>
                                <th scope="col" title="Maç başına yenilen sayı" class="genis">Yenilen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grup['satirlar'] as $no => $s): ?>
                                <tr>
                                    <td class="sira"><?= $no + 1 ?></td>
                                    <td class="takim">
                                        <?php if ($s['logo'] !== ''): ?><img src="<?= e($s['logo']) ?>" alt="" loading="lazy" width="18" height="18"><?php endif; ?>
                                        <?= e($s['takim']) ?>
                                    </td>
                                    <td><?= $s['g'] ?></td>
                                    <td><?= $s['m'] ?></td>
                                    <td class="puan"><?= e(ltrim(number_format($s['yuzde'], 3, '.', ''), '0')) ?></td>
                                    <td class="genis"><?= e($s['fark']) ?></td>
                                    <td><?= e(strtr($s['seri'], ['W' => 'G', 'L' => 'M'])) ?></td>
                                    <td class="genis"><?= e($s['atilan']) ?></td>
                                    <td class="genis"><?= e($s['yenilen']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        <p class="canli-not">Kaynak: ESPN<?= $kayit['zaman'] > 0 ? ' · güncelleme ' . e(date('H:i', $kayit['zaman'])) : '' ?></p>
    </div>
    <?php

    return (string) ob_get_clean();
}
