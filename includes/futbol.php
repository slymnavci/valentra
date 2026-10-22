<?php
declare(strict_types=1);

/**
 * Futbol verisi: API-Football (api-sports.io, v3).
 *
 * Site sahibinin istegi (23.10.2026): "dunyanin herhangi bir liginin
 * puan durumu, fiksturu ve mac sonuclari anlik; kulube basinca kulubun
 * maclari, sonuclari, golleri gelsin, detayli analiz yapacagiz". TFF
 * yalnizca Turkiye liglerini veriyor; bu yuzden ucretli bir veri servisi
 * secildi (site sahibinin karari).
 *
 * Anahtar panelden girilir (Spor verisi sayfasi), ayar olarak saklanir.
 * Anahtar yoksa futbol_api_acik() false doner ve spor sayfalari eskisi
 * gibi TFF'den Super Lig'i gosterir (includes/spor.php).
 *
 * KOTA: plan gunluk istek sayisiyla sinirli. Her veri canli_onbellek
 * uzerinden gidiyor: ziyaretci sayisi istek sayisini artirmiyor, bir
 * anahtar icin en fazla "sure"de bir istek atiliyor ve yalnizca o sayfa
 * acildiginda. Butun cevaplar KIRPILARAK saklaniyor: ayarlar.deger TEXT
 * (64 KB) ve ham cevaplar bunu asar.
 *
 * Saatler Europe/Istanbul: istekler timezone parametresiyle gidiyor.
 */

require_once __DIR__ . '/canli_onbellek.php';

// Testte sahte sunucuya yonlendirilebilsin diye define (const kosullu olamaz).
if (!defined('FUTBOL_API_TABAN')) {
    define('FUTBOL_API_TABAN', 'https://v3.football.api-sports.io');
}

const FUTBOL_API_ANAHTAR  = 'api_football_anahtari';
const FUTBOL_SAAT_DILIMI  = 'Europe/Istanbul';

/** Varsayılan lig: Trendyol Süper Lig. */
const FUTBOL_VARSAYILAN_LIG = 203;

/**
 * Seçicinin üstündeki hızlı seçimler (API-Football lig numaraları).
 * Diğer bütün ligler ülke ve lig seçicisinden.
 */
const FUTBOL_ONE_CIKAN_LIGLER = [
    203 => 'Süper Lig',
    204 => '1. Lig',
    39  => 'Premier League',
    140 => 'La Liga',
    135 => 'Serie A',
    78  => 'Bundesliga',
    61  => 'Ligue 1',
    2   => 'Şampiyonlar Ligi',
    3   => 'Avrupa Ligi',
];

/** Biten maçın durum kodları. */
const FUTBOL_BITTI = ['FT', 'AET', 'PEN', 'AWD', 'WO'];

/**
 * Saatte en fazla bu kadar API istegi.
 *
 * Spor sayfalari ve canli uc herkese acik ve lig/mac numarasini adresten
 * aliyor: biri numaralari tek tek denerse her biri ayri onbellek anahtari
 * olur ve gunluk kota saatler icinde biter. Sinir dolunca yeni istek
 * atilmiyor, onbellekteki veri gosterilmeye devam ediyor. Pro plan
 * (gunde 7.500) icin saatte ~300 kotanin altinda kalir.
 */
const FUTBOL_SAATLIK_SINIR = 300;

/** Oynanmakta olan maçın durum kodları. */
const FUTBOL_CANLI = ['1H', 'HT', '2H', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE'];

function futbol_api_acik(): bool
{
    return trim(ayar_oku(FUTBOL_API_ANAHTAR)) !== '';
}

/**
 * API'ye bir istek. Başarısızsa null; neden futbol_son_hata ayarına
 * yazılır (panel gösteriyor).
 *
 * @param array<string,scalar> $parametre
 * @return array<mixed>|null  cevabin "response" alani
 */
function futbol_istek(string $yol, array $parametre = []): ?array
{
    $anahtar = trim(ayar_oku(FUTBOL_API_ANAHTAR));

    if ($anahtar === '') {
        return null;
    }

    if (!futbol_saatlik_hak()) {
        futbol_hata_yaz($yol . ': saatlik istek sınırı (' . FUTBOL_SAATLIK_SINIR . ') doldu; önbellekteki veri gösteriliyor.');

        return null;
    }

    require_once __DIR__ . '/http_ortak.php';

    $adres = FUTBOL_API_TABAN . $yol . ($parametre !== [] ? '?' . http_build_query($parametre) : '');
    $sonuc = http_getir($adres, 15, '', [
        'x-apisports-key: ' . $anahtar,
        'Accept: application/json',
    ]);

    if (!$sonuc['tamam']) {
        futbol_hata_yaz($yol . ': ' . $sonuc['neden']);

        return null;
    }

    $veri = json_decode((string) $sonuc['govde'], true);

    if (!is_array($veri)) {
        futbol_hata_yaz($yol . ': cevap çözümlenemedi.');

        return null;
    }

    // Hatalar 200 ile, "errors" alaninda geliyor (kota, anahtar, parametre).
    if (!empty($veri['errors'])) {
        futbol_hata_yaz($yol . ': ' . implode(' ', array_map('strval', (array) $veri['errors'])));

        return null;
    }

    return is_array($veri['response'] ?? null) ? $veri['response'] : null;
}

/** Bu saat için istek hakkı kaldıysa sayar ve true döner. */
function futbol_saatlik_hak(): bool
{
    try {
        $saat  = date('YmdH');
        [$kayitSaat, $sayi] = array_pad(explode(':', ayar_oku('futbol_saatlik_sayac')), 2, '0');
        $sayi  = $kayitSaat === $saat ? (int) $sayi : 0;

        if ($sayi >= FUTBOL_SAATLIK_SINIR) {
            return false;
        }

        ayar_yaz('futbol_saatlik_sayac', $saat . ':' . ($sayi + 1));
    } catch (Throwable $e) {
        // Sayac yazilamazsa istek engellenmesin; kota API tarafinda da korunuyor.
    }

    return true;
}

function futbol_hata_yaz(string $mesaj): void
{
    error_log('[valentra] futbol api ' . $mesaj);

    try {
        ayar_yaz('futbol_son_hata', date('Y-m-d H:i') . ' — ' . mb_substr($mesaj, 0, 400));
    } catch (Throwable $e) {
        // panel bilgisi; yazilamazsa sayfa yine calisir
    }
}

/**
 * Önbellekli istek. Anahtar parametrelerden üretilir; $kirp ham cevabı
 * saklanacak biçime çevirir.
 *
 * @param array<string,scalar> $parametre
 * @param callable(array):(?array) $kirp
 * @return array{veri:?array,zaman:int}
 */
function futbol_onbellekli(string $yol, array $parametre, int $sure, callable $kirp): array
{
    if (!futbol_api_acik()) {
        return ['veri' => null, 'zaman' => 0];
    }

    ksort($parametre);
    $anahtar = 'af_' . substr(md5($yol . '?' . http_build_query($parametre)), 0, 24);

    return canli_onbellek($anahtar, $sure, static function () use ($yol, $parametre, $kirp): ?array {
        $cevap = futbol_istek($yol, $parametre);

        return $cevap === null ? null : $kirp($cevap);
    });
}

/** Hesap durumu (panel): plan, bugünkü istek sayısı. Önbelleksiz. */
function futbol_hesap_durumu(): ?array
{
    $cevap = futbol_istek('/status');

    if ($cevap === null) {
        return null;
    }

    return [
        'plan'    => (string) ($cevap['subscription']['plan'] ?? ''),
        'bitis'   => (string) ($cevap['subscription']['end'] ?? ''),
        'aktif'   => (bool) ($cevap['subscription']['active'] ?? false),
        'istek'   => (int) ($cevap['requests']['current'] ?? 0),
        'sinir'   => (int) ($cevap['requests']['limit_day'] ?? 0),
    ];
}

// --- Seçici: ülkeler ve ligler ---------------------------------------------

/** @return list<array{ad:string,bayrak:string}> */
function futbol_ulkeler(): array
{
    $kayit = futbol_onbellekli('/countries', [], 7 * 86400, static function (array $cevap): array {
        $liste = [];

        foreach ($cevap as $u) {
            if (($u['name'] ?? '') !== '') {
                $liste[] = ['ad' => (string) $u['name'], 'bayrak' => (string) ($u['flag'] ?? '')];
            }
        }

        usort($liste, static fn (array $a, array $b): int => strcmp($a['ad'], $b['ad']));

        return $liste;
    });

    return $kayit['veri'] ?? [];
}

/** @return list<array{id:int,ad:string,tur:string,logo:string,sezon:int}> */
function futbol_ulke_ligleri(string $ulke): array
{
    if ($ulke === '') {
        return [];
    }

    $kayit = futbol_onbellekli('/leagues', ['country' => $ulke, 'current' => 'true'], 86400, static function (array $cevap): array {
        $liste = [];

        foreach ($cevap as $l) {
            $liste[] = futbol_lig_kirp($l);
        }

        // Lig onde, kupa arkada; ayni turde ada gore.
        usort($liste, static fn (array $a, array $b): int => [$a['tur'] !== 'League', $a['ad']] <=> [$b['tur'] !== 'League', $b['ad']]);

        return $liste;
    });

    return $kayit['veri'] ?? [];
}

/**
 * Bir ligin bilgisi ve güncel sezonu.
 *
 * @return array{id:int,ad:string,tur:string,logo:string,sezon:int,ulke:string,bayrak:string}|null
 */
function futbol_lig(int $lig): ?array
{
    $kayit = futbol_onbellekli('/leagues', ['id' => $lig, 'current' => 'true'], 86400, static function (array $cevap): ?array {
        return isset($cevap[0]) ? futbol_lig_kirp($cevap[0]) : null;
    });

    return $kayit['veri'];
}

/** @param array<string,mixed> $l */
function futbol_lig_kirp(array $l): array
{
    $sezon = 0;

    foreach ($l['seasons'] ?? [] as $s) {
        if (!empty($s['current'])) {
            $sezon = (int) $s['year'];
        }
    }

    return [
        'id'     => (int) ($l['league']['id'] ?? 0),
        'ad'     => (string) ($l['league']['name'] ?? ''),
        'tur'    => (string) ($l['league']['type'] ?? ''),
        'logo'   => (string) ($l['league']['logo'] ?? ''),
        'sezon'  => $sezon,
        'ulke'   => (string) ($l['country']['name'] ?? ''),
        'bayrak' => (string) ($l['country']['flag'] ?? ''),
    ];
}

// --- Puan durumu ve gol krallığı -------------------------------------------

/**
 * Puan durumu. Kupalarda birden fazla grup olabilir.
 *
 * @return array{veri:?array,zaman:int} veri: list<list<satir>>
 */
function futbol_puan_durumu(int $lig, int $sezon): array
{
    return futbol_onbellekli('/standings', ['league' => $lig, 'season' => $sezon], 600, static function (array $cevap): ?array {
        $gruplar = [];

        foreach ($cevap[0]['league']['standings'] ?? [] as $grup) {
            $satirlar = [];

            foreach ($grup as $s) {
                $satirlar[] = [
                    'sira'  => (int) $s['rank'],
                    'grup'  => (string) ($s['group'] ?? ''),
                    'id'    => (int) $s['team']['id'],
                    'takim' => (string) $s['team']['name'],
                    'logo'  => (string) ($s['team']['logo'] ?? ''),
                    'o'     => (int) ($s['all']['played'] ?? 0),
                    'g'     => (int) ($s['all']['win'] ?? 0),
                    'b'     => (int) ($s['all']['draw'] ?? 0),
                    'm'     => (int) ($s['all']['lose'] ?? 0),
                    'a'     => (int) ($s['all']['goals']['for'] ?? 0),
                    'y'     => (int) ($s['all']['goals']['against'] ?? 0),
                    'av'    => (int) ($s['goalsDiff'] ?? 0),
                    'p'     => (int) ($s['points'] ?? 0),
                    'form'  => (string) ($s['form'] ?? ''),
                    'not'   => (string) ($s['description'] ?? ''),
                ];
            }

            if ($satirlar !== []) {
                $gruplar[] = $satirlar;
            }
        }

        return $gruplar !== [] ? $gruplar : null;
    });
}

/** @return array{veri:?array,zaman:int} */
function futbol_gol_kralligi(int $lig, int $sezon): array
{
    return futbol_onbellekli('/players/topscorers', ['league' => $lig, 'season' => $sezon], 3600, static function (array $cevap): ?array {
        $liste = [];

        foreach ($cevap as $o) {
            $ist = $o['statistics'][0] ?? [];
            $liste[] = [
                'oyuncu'  => (string) ($o['player']['name'] ?? ''),
                'uyruk'   => (string) ($o['player']['nationality'] ?? ''),
                'takim_id' => (int) ($ist['team']['id'] ?? 0),
                'takim'   => (string) ($ist['team']['name'] ?? ''),
                'logo'    => (string) ($ist['team']['logo'] ?? ''),
                'mac'     => (int) ($ist['games']['appearences'] ?? 0),
                'gol'     => (int) ($ist['goals']['total'] ?? 0),
                'asist'   => (int) ($ist['goals']['assists'] ?? 0),
                'penalti' => (int) ($ist['penalty']['scored'] ?? 0),
            ];
        }

        return $liste !== [] ? $liste : null;
    });
}

// --- Fikstür ---------------------------------------------------------------

/** @return array{veri:?array,zaman:int} veri: list<string> turlar */
function futbol_turlar(int $lig, int $sezon): array
{
    return futbol_onbellekli('/fixtures/rounds', ['league' => $lig, 'season' => $sezon], 21600, static function (array $cevap): ?array {
        return $cevap !== [] ? array_values(array_map('strval', $cevap)) : null;
    });
}

function futbol_guncel_tur(int $lig, int $sezon): string
{
    $kayit = futbol_onbellekli('/fixtures/rounds', ['league' => $lig, 'season' => $sezon, 'current' => 'true'], 3600, static function (array $cevap): ?array {
        return $cevap !== [] ? array_values(array_map('strval', $cevap)) : null;
    });

    return (string) ($kayit['veri'][0] ?? '');
}

/**
 * Bir turun maçları. 2 dakikalık önbellek: maç günü skorlar güncel kalsın.
 *
 * @return array{veri:?array,zaman:int}
 */
function futbol_tur_maclari(int $lig, int $sezon, string $tur): array
{
    return futbol_onbellekli('/fixtures', [
        'league' => $lig, 'season' => $sezon, 'round' => $tur, 'timezone' => FUTBOL_SAAT_DILIMI,
    ], 120, static function (array $cevap): ?array {
        $maclar = array_map('futbol_mac_kirp', $cevap);
        usort($maclar, static fn (array $a, array $b): int => $a['zaman'] <=> $b['zaman']);

        return $maclar !== [] ? $maclar : null;
    });
}

/**
 * Bir kulübün son ve sıradaki maçları (bütün turnuvalar).
 *
 * @return array{son:list<array>,sirada:list<array>,zaman:int}
 */
function futbol_kulup_maclari(int $takim): array
{
    $parametre = ['team' => $takim, 'timezone' => FUTBOL_SAAT_DILIMI];
    $kirp      = static fn (array $cevap): ?array => $cevap !== [] ? array_map('futbol_mac_kirp', $cevap) : null;

    $son    = futbol_onbellekli('/fixtures', $parametre + ['last' => 15], 900, $kirp);
    $sirada = futbol_onbellekli('/fixtures', $parametre + ['next' => 5], 3600, $kirp);

    return [
        'son'    => $son['veri'] ?? [],
        'sirada' => $sirada['veri'] ?? [],
        'zaman'  => $son['zaman'],
    ];
}

/** @param array<string,mixed> $m */
function futbol_mac_kirp(array $m): array
{
    return [
        'id'        => (int) $m['fixture']['id'],
        'zaman'     => (int) ($m['fixture']['timestamp'] ?? 0),
        'durum'     => (string) ($m['fixture']['status']['short'] ?? ''),
        'dakika'    => (int) ($m['fixture']['status']['elapsed'] ?? 0),
        'stat'      => (string) ($m['fixture']['venue']['name'] ?? ''),
        'lig_id'    => (int) ($m['league']['id'] ?? 0),
        'lig'       => (string) ($m['league']['name'] ?? ''),
        'tur'       => (string) ($m['league']['round'] ?? ''),
        'ev_id'     => (int) $m['teams']['home']['id'],
        'ev'        => (string) $m['teams']['home']['name'],
        'ev_logo'   => (string) ($m['teams']['home']['logo'] ?? ''),
        'dep_id'    => (int) $m['teams']['away']['id'],
        'dep'       => (string) $m['teams']['away']['name'],
        'dep_logo'  => (string) ($m['teams']['away']['logo'] ?? ''),
        'ev_gol'    => $m['goals']['home'] ?? null,
        'dep_gol'   => $m['goals']['away'] ?? null,
        'iy_ev'     => $m['score']['halftime']['home'] ?? null,
        'iy_dep'    => $m['score']['halftime']['away'] ?? null,
    ];
}

// --- Maç ayrıntısı: goller, kartlar, istatistik, kadrolar -----------------

/**
 * Birden fazla maçın ayrıntısı TEK istekte (API en fazla 20 numara
 * kabul ediyor). Kulüp sayfası son maçların gollerini böyle alıyor;
 * maç başına ayrı istek kotayı yirmi kat hızlı tüketirdi.
 *
 * @param list<int> $idler
 * @return array<int,array> mac id => ayrinti
 */
function futbol_mac_ayrintilari(array $idler): array
{
    $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));

    if ($idler === []) {
        return [];
    }

    sort($idler);
    $sonuc = [];

    foreach (array_chunk($idler, 20) as $parca) {
        $kayit = futbol_onbellekli('/fixtures', ['ids' => implode('-', $parca), 'timezone' => FUTBOL_SAAT_DILIMI], 300, static function (array $cevap): ?array {
            $liste = [];

            foreach ($cevap as $m) {
                $liste[(string) $m['fixture']['id']] = futbol_ayrinti_kirp($m);
            }

            return $liste !== [] ? $liste : null;
        });

        foreach ($kayit['veri'] ?? [] as $id => $ayrinti) {
            $sonuc[(int) $id] = $ayrinti;
        }
    }

    return $sonuc;
}

function futbol_mac_ayrintisi(int $mac): ?array
{
    return futbol_mac_ayrintilari([$mac])[$mac] ?? null;
}

/** @param array<string,mixed> $m */
function futbol_ayrinti_kirp(array $m): array
{
    $olaylar = [];

    foreach ($m['events'] ?? [] as $o) {
        $tip = (string) ($o['type'] ?? '');

        // Oyuncu degisiklikleri ayrintida gereksiz kalabalik.
        if ($tip === 'subst') {
            continue;
        }

        $olaylar[] = [
            'dk'     => (int) ($o['time']['elapsed'] ?? 0),
            'ek'     => (int) ($o['time']['extra'] ?? 0),
            'takim'  => (int) ($o['team']['id'] ?? 0),
            'oyuncu' => (string) ($o['player']['name'] ?? ''),
            'asist'  => (string) ($o['assist']['name'] ?? ''),
            'tip'    => $tip,
            'detay'  => (string) ($o['detail'] ?? ''),
        ];
    }

    $istatistik = [];

    foreach ($m['statistics'] ?? [] as $t) {
        foreach ($t['statistics'] ?? [] as $s) {
            $istatistik[(string) $s['type']][(int) $t['team']['id']] = $s['value'];
        }
    }

    $kadrolar = [];

    foreach ($m['lineups'] ?? [] as $k) {
        $kadrolar[(int) $k['team']['id']] = [
            'dizilis' => (string) ($k['formation'] ?? ''),
            'hoca'    => (string) ($k['coach']['name'] ?? ''),
            'ilk11'   => array_map(static fn (array $o): string => (string) ($o['player']['name'] ?? ''), $k['startXI'] ?? []),
        ];
    }

    return futbol_mac_kirp($m) + [
        'olaylar'    => $olaylar,
        'istatistik' => $istatistik,
        'kadrolar'   => $kadrolar,
        'hakem'      => (string) ($m['fixture']['referee'] ?? ''),
    ];
}

// --- Kulüp istatistiği (analiz) ---------------------------------------------

/** @return array{veri:?array,zaman:int} */
function futbol_kulup_istatistigi(int $lig, int $sezon, int $takim): array
{
    return futbol_onbellekli('/teams/statistics', ['league' => $lig, 'season' => $sezon, 'team' => $takim], 21600, static function (array $c): ?array {
        if (!isset($c['team']['id'])) {
            return null;
        }

        $uc = static fn (array $d): array => [
            'ic'  => $d['home'] ?? null,
            'dis' => $d['away'] ?? null,
            'top' => $d['total'] ?? null,
        ];

        $dakika = [];

        foreach (['for' => 'atilan', 'against' => 'yenilen'] as $api => $ad) {
            foreach ($c['goals'][$api]['minute'] ?? [] as $aralik => $d) {
                $dakika[$ad][(string) $aralik] = (int) ($d['total'] ?? 0);
            }
        }

        return [
            'takim'     => (string) $c['team']['name'],
            'logo'      => (string) ($c['team']['logo'] ?? ''),
            'lig'       => (string) ($c['league']['name'] ?? ''),
            'form'      => (string) ($c['form'] ?? ''),
            'oynanan'   => $uc($c['fixtures']['played'] ?? []),
            'galibiyet' => $uc($c['fixtures']['wins'] ?? []),
            'beraberlik' => $uc($c['fixtures']['draws'] ?? []),
            'maglubiyet' => $uc($c['fixtures']['loses'] ?? []),
            'atilan'    => $uc($c['goals']['for']['total'] ?? []),
            'yenilen'   => $uc($c['goals']['against']['total'] ?? []),
            'atilan_ort'  => $uc($c['goals']['for']['average'] ?? []),
            'yenilen_ort' => $uc($c['goals']['against']['average'] ?? []),
            'gol_yemeden' => $uc($c['clean_sheet'] ?? []),
            'gol_atamadan' => $uc($c['failed_to_score'] ?? []),
            'seri'      => [
                'galibiyet'  => (int) ($c['biggest']['streak']['wins'] ?? 0),
                'beraberlik' => (int) ($c['biggest']['streak']['draws'] ?? 0),
                'maglubiyet' => (int) ($c['biggest']['streak']['loses'] ?? 0),
            ],
            'en_farkli_galibiyet' => ['ic' => $c['biggest']['wins']['home'] ?? null, 'dis' => $c['biggest']['wins']['away'] ?? null],
            'en_farkli_maglubiyet' => ['ic' => $c['biggest']['loses']['home'] ?? null, 'dis' => $c['biggest']['loses']['away'] ?? null],
            'penalti'   => [
                'atilan'  => (int) ($c['penalty']['scored']['total'] ?? 0),
                'kacan'   => (int) ($c['penalty']['missed']['total'] ?? 0),
            ],
            'dizilisler' => array_map(static fn (array $d): array => ['dizilis' => (string) $d['formation'], 'mac' => (int) $d['played']], $c['lineups'] ?? []),
            'dakika'    => $dakika,
        ];
    });
}

// --- Biçim yardımcıları ------------------------------------------------------

/** Maçın skoru ya da saati: "2 - 1", "67'", "20:00". */
function futbol_skor_yazi(array $m): string
{
    if (in_array($m['durum'], FUTBOL_BITTI, true) || in_array($m['durum'], FUTBOL_CANLI, true)) {
        return (int) $m['ev_gol'] . ' - ' . (int) $m['dep_gol'];
    }

    return match ($m['durum']) {
        'PST'   => 'Ertelendi',
        'CANC'  => 'İptal',
        'ABD'   => 'Yarıda kaldı',
        default => $m['zaman'] > 0 ? date('H:i', $m['zaman']) : '-',
    };
}

/** Maçın tarih yazısı: "24 Eki Cum". */
function futbol_tarih(int $zaman): string
{
    if ($zaman <= 0) {
        return '';
    }

    $aylar  = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    $gunler = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];

    return (int) date('j', $zaman) . ' ' . $aylar[(int) date('n', $zaman) - 1] . ' ' . $gunler[(int) date('w', $zaman)];
}

/** "Regular Season - 10" -> "10. Hafta"; diğer tur adları olduğu gibi. */
function futbol_tur_adi(string $tur): string
{
    return preg_match('/^Regular Season\s*-\s*(\d+)$/i', $tur, $e) ? $e[1] . '. Hafta' : $tur;
}

/** Takımın bu maçtaki sonucu: G / B / M; oynanmadıysa boş. */
function futbol_sonuc_harfi(array $m, int $takim): string
{
    if (!in_array($m['durum'], FUTBOL_BITTI, true)) {
        return '';
    }

    $biz  = $m['ev_id'] === $takim ? (int) $m['ev_gol'] : (int) $m['dep_gol'];
    $onlar = $m['ev_id'] === $takim ? (int) $m['dep_gol'] : (int) $m['ev_gol'];

    return $biz > $onlar ? 'G' : ($biz === $onlar ? 'B' : 'M');
}
