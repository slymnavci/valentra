<?php
declare(strict_types=1);

/**
 * Maç kadroları ve olayları (ESPN maç özeti, "summary").
 *
 * Site sahibinin istegi (08.11.2026): "canli maclarda mac kadrolarini da
 * gosterelim. yedekler, kim girdi kim cikti, kim gol atti, kim sari kart
 * gordu".
 *
 * Gunun maclari (scoreboard) yalnizca gol ve kart veriyor; kadro, yedek
 * ve oyuncu degisikligi mac ozetinde:
 *   /site/v2/sports/soccer/{lig}/summary?event={mac}
 *     rosters[]   homeAway, team, formation, roster[] (starter, jersey,
 *                 athlete, position, subbedIn, subbedOut)
 *     keyEvents[] type.text (Goal, Yellow Card, Red Card, Substitution...),
 *                 clock.displayValue, team.id, participants[].athlete, text
 *
 * Onbellek: canli mac 60 sn (saatlik ESPN istek sinirina sigsin), baslamamis 10 dk (kadro mactan ~1 saat
 * once aciklaniyor), biten mac 1 gun.
 */

require_once __DIR__ . '/futbol.php';

/**
 * Maçın özeti (kadrolar ve olaylar).
 *
 * @return array{veri:?array,zaman:int}
 */
function futbol_mac_ozeti(int $mac, string $lig, string $durum): array
{
    if ($mac <= 0 || !futbol_lig_kodu_gecerli($lig)) {
        return ['veri' => null, 'zaman' => 0];
    }

    $sure = match (true) {
        in_array($durum, FUTBOL_CANLI, true) => 60,
        in_array($durum, FUTBOL_BITTI, true) => 86400,
        default                              => 600,
    };

    return futbol_onbellekli('/site/v2/sports/soccer/' . $lig . '/summary', ['event' => $mac], $sure, 'futbol_mac_ozeti_coz');
}

/**
 * ESPN maç özeti -> kadrolar ve olaylar.
 *
 * @param array<string,mixed> $c
 * @return array{kadro:array<int,array>,olaylar:list<array>}|null
 */
function futbol_mac_ozeti_coz(array $c): ?array
{
    if (!isset($c['rosters']) && !isset($c['keyEvents'])) {
        return null;
    }

    $olaylar = futbol_ozet_olaylari((array) ($c['keyEvents'] ?? []));
    $kadro   = [];

    foreach ((array) ($c['rosters'] ?? []) as $r) {
        $takim = (int) ($r['team']['id'] ?? 0);

        if ($takim === 0 || !is_array($r['roster'] ?? null) || $r['roster'] === []) {
            continue;
        }

        $ilk = $yedek = [];

        foreach ($r['roster'] as $o) {
            $ad = (string) ($o['athlete']['displayName'] ?? ($o['athlete']['fullName'] ?? ''));

            if ($ad === '') {
                continue;
            }

            $oyuncu = [
                'id'     => (int) ($o['athlete']['id'] ?? 0),
                'no'     => (string) ($o['jersey'] ?? ''),
                'ad'     => $ad,
                'mevki'  => (string) ($o['position']['abbreviation'] ?? ''),
                'sira'   => (int) ($o['formationPlace'] ?? 0),
                'girdi'  => null,
                'cikti'  => null,
                'gol'    => 0,
                'sari'   => 0,
                'kirmizi'=> false,
            ];

            if (!empty($o['starter'])) {
                $ilk[] = $oyuncu;
            } else {
                $yedek[] = $oyuncu;
            }
        }

        // Ilk 11 dizilis sirasiyla (kaleci basta).
        usort($ilk, static fn (array $a, array $b): int => ($a['sira'] ?: 99) <=> ($b['sira'] ?: 99));

        $kadro[$takim] = [
            'ev'      => ($r['homeAway'] ?? '') === 'home',
            'dizilis' => (string) ($r['formation'] ?? ''),
            'ilk11'   => $ilk,
            'yedek'   => $yedek,
        ];
    }

    // Olaylari oyunculara isle: gol, kart, giris ve cikis dakikasi.
    foreach ($olaylar as $ol) {
        if (!isset($kadro[$ol['takim']])) {
            continue;
        }

        foreach (['ilk11', 'yedek'] as $grup) {
            foreach ($kadro[$ol['takim']][$grup] as &$p) {
                $dk = $ol['dk'] . ($ol['ek'] > 0 ? '+' . $ol['ek'] : '') . "'";

                if (futbol_ozet_ayni_oyuncu($p, $ol['oyuncu_id'], $ol['oyuncu'])) {
                    match ($ol['tip']) {
                        'gol'        => $ol['detay'] === 'kk' ? null : $p['gol']++,
                        'sari'       => $p['sari']++,
                        'kirmizi'    => $p['kirmizi'] = true,
                        'degisiklik' => $p['girdi'] = $dk,
                        default      => null,
                    };
                }

                if ($ol['tip'] === 'degisiklik' && futbol_ozet_ayni_oyuncu($p, $ol['cikan_id'], $ol['cikan'])) {
                    $p['cikti'] = $dk;
                }
            }
            unset($p);
        }
    }

    if ($kadro === [] && $olaylar === []) {
        return null;
    }

    return ['kadro' => $kadro, 'olaylar' => $olaylar];
}

/**
 * keyEvents -> gol, kart ve oyuncu değişikliği olayları (dakika sırasıyla).
 *
 * @param list<array<string,mixed>> $liste
 * @return list<array{dk:int,ek:int,takim:int,tip:string,detay:string,oyuncu:string,oyuncu_id:int,cikan:string,cikan_id:int}>
 */
function futbol_ozet_olaylari(array $liste): array
{
    $olaylar = [];

    foreach ($liste as $k) {
        if (!is_array($k)) {
            continue;
        }

        $tur  = mb_strtolower((string) ($k['type']['text'] ?? ''), 'UTF-8');
        $kod  = mb_strtolower((string) ($k['type']['type'] ?? ''), 'UTF-8');
        $hepsi = $tur . ' ' . $kod;

        $tip = match (true) {
            str_contains($hepsi, 'substitution')                              => 'degisiklik',
            str_contains($hepsi, 'red card') || str_contains($hepsi, 'red-card') => 'kirmizi',
            str_contains($hepsi, 'yellow')                                    => str_contains($hepsi, 'second') ? 'kirmizi' : 'sari',
            !empty($k['scoringPlay']) || str_contains($hepsi, 'goal')         => 'gol',
            default                                                           => '',
        };

        // "Penalty - Missed", "Goal Disallowed" gibi gol OLMAYAN olaylar.
        if ($tip === 'gol' && (str_contains($hepsi, 'missed') || str_contains($hepsi, 'saved') || str_contains($hepsi, 'disallowed')
            || (isset($k['scoringPlay']) && empty($k['scoringPlay'])))) {
            $tip = '';
        }

        if ($tip === '' || !empty($k['shootout'])) {
            continue;
        }

        [$dk, $ek] = futbol_dakika((string) ($k['clock']['displayValue'] ?? ''));
        $katilan   = array_values(array_filter((array) ($k['participants'] ?? []), 'is_array'));
        $ad        = static fn (?array $p): string => (string) ($p['athlete']['displayName'] ?? '');
        $id        = static fn (?array $p): int => (int) ($p['athlete']['id'] ?? 0);

        $olay = [
            'dk'        => $dk,
            'ek'        => $ek,
            'takim'     => (int) ($k['team']['id'] ?? 0),
            'tip'       => $tip,
            'detay'     => '',
            'oyuncu'    => $ad($katilan[0] ?? null),
            'oyuncu_id' => $id($katilan[0] ?? null),
            'cikan'     => '',
            'cikan_id'  => 0,
        ];

        if ($tip === 'gol') {
            $olay['detay'] = match (true) {
                str_contains($hepsi, 'own goal') || str_contains($hepsi, 'own-goal') || !empty($k['ownGoal']) => 'kk',
                str_contains($hepsi, 'penalty') || !empty($k['penaltyKick'])                                => 'pen',
                default                                                                                     => '',
            };

            // Asist: ikinci katilan (ESPN golde "assist" olarak veriyor).
            $olay['cikan'] = $ad($katilan[1] ?? null);
        }

        if ($tip === 'degisiklik') {
            /*
             * Metin "Substitution, Galatasaray. X replaces Y." — giren X,
             * cikan Y. Metin yoksa katilanlarin sirasi: once giren.
             */
            $metin = (string) ($k['text'] ?? '');

            if (preg_match('/\.\s*(.+?)\s+replaces\s+(.+?)(?:\s+because\b.*)?\.?\s*$/u', $metin, $m)) {
                $giren = trim($m[1]);
                $cikan = trim(rtrim($m[2], '.'));
                $olay['oyuncu']    = $giren;
                $olay['cikan']     = $cikan;
                $olay['oyuncu_id'] = 0;
                $olay['cikan_id']  = 0;

                foreach ($katilan as $p) {
                    if ($ad($p) === $giren) {
                        $olay['oyuncu_id'] = $id($p);
                    } elseif ($ad($p) === $cikan) {
                        $olay['cikan_id'] = $id($p);
                    }
                }
            } else {
                $olay['cikan']    = $ad($katilan[1] ?? null);
                $olay['cikan_id'] = $id($katilan[1] ?? null);
            }
        }

        $olaylar[] = $olay;
    }

    usort($olaylar, static fn (array $a, array $b): int => [$a['dk'], $a['ek']] <=> [$b['dk'], $b['ek']]);

    return $olaylar;
}

/** Olaydaki oyuncu kadrodaki bu oyuncu mu (önce ESPN numarası, yoksa ad). */
function futbol_ozet_ayni_oyuncu(array $p, int $id, string $ad): bool
{
    if ($id > 0 && $p['id'] > 0) {
        return $id === $p['id'];
    }

    return $ad !== '' && mb_strtolower($ad, 'UTF-8') === mb_strtolower($p['ad'], 'UTF-8');
}

/* ------------------------------------------------------------------ HTML */

/** Mevki kısaltması: G/D/M/F -> Türkçe. */
function futbol_mevki_kisa(string $m): string
{
    return match (strtoupper(substr($m, 0, 1))) {
        'G'     => 'K',
        'D'     => 'D',
        'M'     => 'OS',
        'F', 'A' => 'F',
        default => '',
    };
}

/** Oyuncunun yanındaki simgeler: gol, kart, giriş/çıkış. */
function futbol_oyuncu_simgeleri(array $p): string
{
    $s = str_repeat('<span class="kadro-simge" title="Gol">⚽</span>', $p['gol']);

    if ($p['sari'] > 0) {
        $s .= '<span class="kadro-simge" title="Sarı kart">🟨</span>';
    }

    if ($p['kirmizi']) {
        $s .= '<span class="kadro-simge" title="Kırmızı kart">🟥</span>';
    }

    if ($p['girdi'] !== null) {
        $s .= '<span class="kadro-giris" title="Oyuna girdi">▲ ' . e((string) $p['girdi']) . '</span>';
    }

    if ($p['cikti'] !== null) {
        $s .= '<span class="kadro-cikis" title="Oyundan çıktı">▼ ' . e((string) $p['cikti']) . '</span>';
    }

    return $s;
}

/**
 * Maç sayfasındaki olaylar ve kadrolar.
 *
 * @param array<string,mixed> $m   futbol_mac_kirp çıktısı (ev_id, dep_id, ev, dep)
 * @param array<string,mixed> $oz  futbol_mac_ozeti_coz çıktısı
 */
function futbol_kadro_html(array $m, array $oz): string
{
    $html = '';

    if ($oz['olaylar'] !== []) {
        $html .= '<div class="spor-kutu"><h3>Maçın olayları</h3><ul class="olay-listesi">';

        foreach ($oz['olaylar'] as $o) {
            $simge = match ($o['tip']) {
                'gol'        => '⚽',
                'sari'       => '🟨',
                'kirmizi'    => '🟥',
                'degisiklik' => '🔁',
                default      => '•',
            };
            $kim = match ($o['tip']) {
                'degisiklik' => '<span class="kadro-giris">▲ ' . e($o['oyuncu']) . '</span> <span class="kadro-cikis">▼ ' . e($o['cikan']) . '</span>',
                'gol'        => e($o['oyuncu'])
                    . ($o['detay'] === 'pen' ? ' <small>(penaltı)</small>' : ($o['detay'] === 'kk' ? ' <small>(kendi kalesine)</small>' : ''))
                    . ($o['cikan'] !== '' && $o['detay'] !== 'kk' ? ' <small>asist: ' . e($o['cikan']) . '</small>' : ''),
                default      => e($o['oyuncu']),
            };

            $html .= '<li class="' . ($o['takim'] === (int) $m['ev_id'] ? 'ev' : 'dep') . ' olay-' . e($o['tip']) . '">'
                . '<span class="dk">' . $o['dk'] . ($o['ek'] > 0 ? '+' . $o['ek'] : '') . "'</span>"
                . '<span class="simge">' . $simge . '</span>'
                . '<span class="kim">' . $kim . '</span></li>';
        }

        $html .= '</ul></div>';
    }

    $ev  = $oz['kadro'][(int) $m['ev_id']] ?? null;
    $dep = $oz['kadro'][(int) $m['dep_id']] ?? null;

    if ($ev === null && $dep === null) {
        return $html;
    }

    $takim = static function (?array $k, string $ad): string {
        if ($k === null) {
            return '<div class="kadro-takim"><h4>' . e($ad) . '</h4><p class="canli-not">Kadro açıklanmadı.</p></div>';
        }

        $satir = static function (array $p): string {
            return '<li' . ($p['cikti'] !== null ? ' class="cikti"' : ($p['girdi'] !== null ? ' class="girdi"' : '')) . '>'
                . '<span class="kadro-no">' . e($p['no']) . '</span>'
                . '<span class="kadro-ad">' . e($p['ad']) . '</span>'
                . '<span class="kadro-mevki">' . e(futbol_mevki_kisa($p['mevki'])) . '</span>'
                . futbol_oyuncu_simgeleri($p) . '</li>';
        };

        return '<div class="kadro-takim"><h4>' . e($ad) . ($k['dizilis'] !== '' ? ' <small>' . e($k['dizilis']) . '</small>' : '') . '</h4>'
            . '<h5>İlk 11</h5><ol class="kadro-liste">' . implode('', array_map($satir, $k['ilk11'])) . '</ol>'
            . ($k['yedek'] !== [] ? '<h5>Yedekler</h5><ol class="kadro-liste yedek">' . implode('', array_map($satir, $k['yedek'])) . '</ol>' : '')
            . '</div>';
    };

    return $html . '<div class="spor-kutu"><h3>Kadrolar</h3><div class="kadro-izgara">'
        . $takim($ev, (string) $m['ev']) . $takim($dep, (string) $m['dep'])
        . '</div><p class="canli-not">▲ oyuna girdi · ▼ oyundan çıktı · K kaleci, D defans, OS orta saha, F forvet</p></div>';
}
