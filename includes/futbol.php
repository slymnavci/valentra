<?php
declare(strict_types=1);

/**
 * Futbol verisi: ESPN'in herkese acik JSON uclari (site.api.espn.com).
 *
 * Site sahibinin istegi (23.10.2026): "dunyanin herhangi bir liginin
 * puan durumu, fiksturu ve mac sonuclari anlik; kulube basinca kulubun
 * maclari, sonuclari, golleri gelsin, detayli analiz yapacagiz".
 *
 * Neden ESPN: API-Football'un ucretsiz plani (gunde 100 istek) yetmedi,
 * ucretli plan istenmedi; site sahibi ESPN'i secti. Uclar anahtarsiz ve
 * belgesiz; resmi bir sozlesme yok, ESPN degistirirse ya da kapatirsa
 * bozulabilir. Bu yuzden:
 *   - her cevap kirpilarak saklaniyor, ESPN dusse de son veri gorunuyor;
 *   - panelden (Site -> Spor verisi) kapatilabiliyor, kapaliyken spor
 *     sayfalari TFF'den Super Lig'i gosteriyor (includes/spor.php);
 *   - saatlik istek siniri var: sayfalar herkese acik ve lig/mac kodunu
 *     adresten aliyor, biri tek tek denese ESPN'e yuk bindirip engel
 *     yememeliyiz.
 *
 * Yapi kaynak testiyle (ajan/kaynak_dene.php --ham) goruldu (22.10.2026):
 *   standings  -> children[].standings.entries[] {team, note, stats[]}
 *   scoreboard -> leagues[0].calendar (mac gunleri), events[] ve her
 *                 macin competitions[0].details[] (goller, kartlar)
 *   teams/{id}/schedule (?fixture=true) -> sezonun oynanan (oynanacak) maclari
 *   statistics -> stats[] {name: goalsLeaders, leaders[]}
 *
 * Saklanan veri ayarlar.deger (TEXT, 64 KB) sinirina sigacak kadar kirpik.
 */

require_once __DIR__ . '/canli_onbellek.php';

// Testte sahte sunucuya yonlendirilebilsin diye define (const kosullu olamaz).
if (!defined('FUTBOL_API_TABAN')) {
    define('FUTBOL_API_TABAN', 'https://site.api.espn.com/apis');
}

/** Panelden kapatma ayarı: '1' ise ESPN kullanılmaz. */
const FUTBOL_KAPALI = 'futbol_espn_kapali';

/** Varsayılan lig: Trendyol Süper Lig. */
const FUTBOL_VARSAYILAN_LIG = 'tur.1';

/**
 * Seçicideki ligler (ESPN kodu => ad), ülkeye göre. ESPN'in kapsadığı
 * liglerin hepsi değil; sitenin okuruna yakın olanlar. Bir lig eklemek
 * için ESPN kodunu buraya yazmak yeterli (espn.com/soccer/league/_/name/KOD).
 */
const FUTBOL_LIGLER = [
    /*
     * 1. Lig (tur.2) kaldirildi (23.10.2026, site sahibinin karari):
     * ESPN bu lig icin veri vermiyor, sayfa bos aciliyordu.
     */
    'Türkiye'         => ['tur.1' => 'Süper Lig'],
    'Avrupa Kupaları' => ['uefa.champions' => 'Şampiyonlar Ligi', 'uefa.europa' => 'Avrupa Ligi', 'uefa.europa.conf' => 'Konferans Ligi', 'uefa.nations' => 'Uluslar Ligi'],
    'İngiltere'       => ['eng.1' => 'Premier League', 'eng.2' => 'Championship', 'eng.fa' => 'FA Cup', 'eng.league_cup' => 'Lig Kupası'],
    'İspanya'         => ['esp.1' => 'La Liga', 'esp.2' => 'La Liga 2', 'esp.copa_del_rey' => 'Kral Kupası'],
    'İtalya'          => ['ita.1' => 'Serie A', 'ita.2' => 'Serie B', 'ita.coppa_italia' => 'İtalya Kupası'],
    'Almanya'         => ['ger.1' => 'Bundesliga', 'ger.2' => '2. Bundesliga', 'ger.dfb_pokal' => 'Almanya Kupası'],
    'Fransa'          => ['fra.1' => 'Ligue 1', 'fra.2' => 'Ligue 2', 'fra.coupe_de_france' => 'Fransa Kupası'],
    'Hollanda'        => ['ned.1' => 'Eredivisie'],
    'Portekiz'        => ['por.1' => 'Primeira Liga'],
    'Belçika'         => ['bel.1' => 'Pro League'],
    'İskoçya'         => ['sco.1' => 'Premiership'],
    'Yunanistan'      => ['gre.1' => 'Süper Lig'],
    'Avusturya'       => ['aut.1' => 'Bundesliga'],
    'İsviçre'         => ['sui.1' => 'Super League'],
    'Danimarka'       => ['den.1' => 'Superliga'],
    'Norveç'          => ['nor.1' => 'Eliteserien'],
    'İsveç'           => ['swe.1' => 'Allsvenskan'],
    'Rusya'           => ['rus.1' => 'Premier Liga'],
    'Suudi Arabistan' => ['ksa.1' => 'Pro League'],
    'ABD'             => ['usa.1' => 'MLS'],
    'Meksika'         => ['mex.1' => 'Liga MX'],
    'Brezilya'        => ['bra.1' => 'Série A'],
    'Arjantin'        => ['arg.1' => 'Liga Profesional'],
    'Japonya'         => ['jpn.1' => 'J1 League'],
    'Avustralya'      => ['aus.1' => 'A-League'],
    'Dünya'           => ['fifa.world' => 'Dünya Kupası', 'fifa.worldq.uefa' => 'Dünya Kupası Elemeleri (Avrupa)'],
];

/** Seçicinin üstündeki hızlı seçimler. */
const FUTBOL_ONE_CIKAN_LIGLER = [
    'tur.1'          => 'Süper Lig',
    'eng.1'          => 'Premier League',
    'esp.1'          => 'La Liga',
    'ita.1'          => 'Serie A',
    'ger.1'          => 'Bundesliga',
    'fra.1'          => 'Ligue 1',
    'uefa.champions' => 'Şampiyonlar Ligi',
    'uefa.europa'    => 'Avrupa Ligi',
];

/**
 * Saatte en fazla bu kadar ESPN isteği. Kota yok ama sayfalar herkese
 * açık; kodları tek tek deneyen biri ESPN'e yük bindirip sitenin
 * engellenmesine yol açmasın. Sınır dolunca önbellekteki veri gösteriliyor.
 */
const FUTBOL_SAATLIK_SINIR = 600;

/** Biten maçın durum kodları. */
const FUTBOL_BITTI = ['FT', 'AET', 'PEN'];

/** Oynanmakta olan maçın durum kodları. */
const FUTBOL_CANLI = ['LIVE', 'HT'];

function futbol_api_acik(): bool
{
    return ayar_oku(FUTBOL_KAPALI) !== '1';
}

/** Lig kodu biçim olarak geçerli mi ("tur.1", "uefa.europa.conf"). */
function futbol_lig_kodu_gecerli(string $kod): bool
{
    return (bool) preg_match('/^[a-z]{2,6}(\.[a-z0-9_]{1,20}){1,2}$/', $kod);
}

/** Katalogdaki adı; yoksa boş. */
function futbol_lig_katalog_adi(string $kod): string
{
    foreach (FUTBOL_LIGLER as $ligler) {
        if (isset($ligler[$kod])) {
            return $ligler[$kod];
        }
    }

    return '';
}

/** Ligin ülkesi (katalogdan); yoksa boş. */
function futbol_lig_ulkesi(string $kod): string
{
    foreach (FUTBOL_LIGLER as $ulke => $ligler) {
        if (isset($ligler[$kod])) {
            return $ulke;
        }
    }

    return '';
}

/**
 * ESPN'e bir istek. Başarısızsa null; neden futbol_son_hata ayarına.
 *
 * @param array<string,scalar> $parametre
 */
function futbol_istek(string $yol, array $parametre = []): ?array
{
    if (!futbol_api_acik()) {
        return null;
    }

    if (!futbol_saatlik_hak()) {
        futbol_hata_yaz($yol . ': saatlik istek sınırı (' . FUTBOL_SAATLIK_SINIR . ') doldu; önbellekteki veri gösteriliyor.');

        return null;
    }

    require_once __DIR__ . '/http_ortak.php';

    $adres = FUTBOL_API_TABAN . $yol . ($parametre !== [] ? '?' . http_build_query($parametre) : '');
    $sonuc = http_getir($adres, 15);

    if (!$sonuc['tamam']) {
        futbol_hata_yaz($yol . ': ' . $sonuc['neden']);

        return null;
    }

    $veri = json_decode((string) $sonuc['govde'], true);

    if (!is_array($veri)) {
        futbol_hata_yaz($yol . ': cevap çözümlenemedi.');

        return null;
    }

    return $veri;
}

/** Bu saat için istek hakkı kaldıysa sayar ve true döner. */
function futbol_saatlik_hak(): bool
{
    try {
        $saat = date('YmdH');
        [$kayitSaat, $sayi] = array_pad(explode(':', ayar_oku('futbol_saatlik_sayac')), 2, '0');
        $sayi = $kayitSaat === $saat ? (int) $sayi : 0;

        if ($sayi >= FUTBOL_SAATLIK_SINIR) {
            return false;
        }

        ayar_yaz('futbol_saatlik_sayac', $saat . ':' . ($sayi + 1));
    } catch (Throwable $e) {
        // Sayac yazilamazsa istek engellenmesin.
    }

    return true;
}

function futbol_hata_yaz(string $mesaj): void
{
    error_log('[valentra] futbol ' . $mesaj);

    try {
        ayar_yaz('futbol_son_hata', date('Y-m-d H:i') . ' — ' . mb_substr($mesaj, 0, 400));
    } catch (Throwable $e) {
        // panel bilgisi; yazilamazsa sayfa yine calisir
    }
}

/**
 * Önbellekli istek; $kirp ham cevabı saklanacak biçime çevirir.
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
    $anahtar = 'es_' . substr(md5($yol . '?' . http_build_query($parametre)), 0, 24);

    return canli_onbellek($anahtar, $sure, static function () use ($yol, $parametre, $kirp): ?array {
        $cevap = futbol_istek($yol, $parametre);

        return $cevap === null ? null : $kirp($cevap);
    });
}

// --- Lig bilgisi --------------------------------------------------------------

/**
 * Ligin adı, logosu, sezonu ve maç günleri (scoreboard'dan).
 *
 * @return array{id:string,ad:string,logo:string,sezon:string,ulke:string,takvim:list<string>}|null
 */
function futbol_lig(string $lig): ?array
{
    if (!futbol_lig_kodu_gecerli($lig)) {
        return null;
    }

    $kayit = futbol_onbellekli('/site/v2/sports/soccer/' . $lig . '/scoreboard', [], 21600, static function (array $c) use ($lig): ?array {
        $l = $c['leagues'][0] ?? null;

        if (!is_array($l)) {
            return null;
        }

        // Mac gunleri, Istanbul tarihine gore (ESPN gunu 07:00Z yaziyor).
        $gunler = [];

        foreach ($l['calendar'] ?? [] as $g) {
            $t = is_string($g) ? strtotime($g) : false;

            if ($t !== false) {
                $gunler[date('Ymd', $t)] = true;
            }
        }

        $gunler = array_map('strval', array_keys($gunler));
        sort($gunler);

        return [
            'id'     => $lig,
            'ad'     => (string) ($l['name'] ?? ''),
            'logo'   => (string) ($l['logos'][0]['href'] ?? ''),
            'sezon'  => (string) ($l['season']['displayName'] ?? ''),
            'takvim' => $gunler,
        ];
    });

    $veri = $kayit['veri'];

    if ($veri === null) {
        return null;
    }

    // Katalogdaki Turkce ad, ESPN'in Ingilizce adindan once.
    $katalog = futbol_lig_katalog_adi($lig);
    $veri['ad']   = $katalog !== '' ? $katalog : $veri['ad'];
    $veri['ulke'] = futbol_lig_ulkesi($lig);

    return $veri;
}

// --- Puan durumu ve gol krallığı -------------------------------------------

/** @return array{veri:?array,zaman:int} veri: list<list<satir>> */
function futbol_puan_durumu(string $lig): array
{
    return futbol_onbellekli('/v2/sports/soccer/' . $lig . '/standings', [], 600, static function (array $c): ?array {
        $gruplar = [];

        foreach ($c['children'] ?? [] as $grup) {
            $satirlar = [];

            foreach ($grup['standings']['entries'] ?? [] as $s) {
                $ist = [];

                foreach ($s['stats'] ?? [] as $d) {
                    $ist[(string) ($d['name'] ?? '')] = $d['value'] ?? null;
                }

                $satirlar[] = [
                    'sira'  => (int) ($ist['rank'] ?? 0),
                    'grup'  => (string) ($grup['name'] ?? ''),
                    'id'    => (int) ($s['team']['id'] ?? 0),
                    'takim' => (string) ($s['team']['displayName'] ?? ''),
                    'logo'  => (string) ($s['team']['logos'][0]['href'] ?? ''),
                    'o'     => (int) ($ist['gamesPlayed'] ?? 0),
                    'g'     => (int) ($ist['wins'] ?? 0),
                    'b'     => (int) ($ist['ties'] ?? 0),
                    'm'     => (int) ($ist['losses'] ?? 0),
                    'a'     => (int) ($ist['pointsFor'] ?? 0),
                    'y'     => (int) ($ist['pointsAgainst'] ?? 0),
                    'av'    => (int) ($ist['pointDifferential'] ?? 0),
                    'p'     => (int) ($ist['points'] ?? 0),
                    'form'  => '',
                    'not'   => (string) ($s['note']['description'] ?? ''),
                ];
            }

            usort($satirlar, static fn (array $a, array $b): int => $a['sira'] <=> $b['sira']);

            if ($satirlar !== []) {
                $gruplar[] = $satirlar;
            }
        }

        return $gruplar !== [] ? $gruplar : null;
    });
}

/** @return array{veri:?array,zaman:int} */
function futbol_gol_kralligi(string $lig): array
{
    $kayit = futbol_krallik_verisi($lig);

    return ['veri' => $kayit['veri']['gol'] ?? null, 'zaman' => $kayit['zaman']];
}

/**
 * Asist kralligi (30.10.2026, site sahibinin istegi: "gol ve asist
 * kralligina menuden gidelim"). Gol kralligiyla ayni ESPN cevabindan.
 *
 * @return array{veri:?array,zaman:int}
 */
function futbol_asist_kralligi(string $lig): array
{
    $kayit = futbol_krallik_verisi($lig);

    return ['veri' => $kayit['veri']['asist'] ?? null, 'zaman' => $kayit['zaman']];
}

/**
 * Ligin gol ve asist liderleri, tek istekle (ESPN statistics: goalsLeaders,
 * assistsLeaders). Onbellek anahtari istek adresinden; iki liste ayni
 * cevaptan cikiyor.
 *
 * @return array{veri:?array{gol:?array,asist:?array},zaman:int}
 */
function futbol_krallik_verisi(string $lig): array
{
    $kayit = futbol_onbellekli('/site/v2/sports/soccer/' . $lig . '/statistics', [], 3600, static function (array $c): ?array {
        $listeler = ['goalsLeaders' => [], 'assistsLeaders' => []];

        foreach ($c['stats'] ?? [] as $s) {
            $ad = (string) ($s['name'] ?? '');

            if (isset($listeler[$ad])) {
                $listeler[$ad] = $s['leaders'] ?? [];
            }
        }

        $gol   = futbol_lider_satirlari($listeler['goalsLeaders'], 'gol');
        $asist = futbol_lider_satirlari($listeler['assistsLeaders'], 'asist');

        return $gol === [] && $asist === [] ? null : ['gol' => $gol ?: null, 'asist' => $asist ?: null];
    });

    // Yayindan once onbellege giren eski bicim: yalnizca gol listesi.
    if (is_array($kayit['veri']) && array_is_list($kayit['veri'])) {
        $kayit['veri'] = ['gol' => $kayit['veri'], 'asist' => null];
    }

    return $kayit;
}

/**
 * @param list<array<string,mixed>> $liderler
 * @param 'gol'|'asist' $tur Liderin 'value' alani hangisi
 * @return list<array<string,mixed>>
 */
function futbol_lider_satirlari(array $liderler, string $tur): array
{
    $liste = [];

    foreach (array_slice($liderler, 0, 30) as $o) {
        $ist = [];

        foreach ($o['athlete']['statistics'] ?? [] as $d) {
            $ist[(string) ($d['name'] ?? '')] = (int) ($d['value'] ?? 0);
        }

        // "M: 8, G: 8: A: 3": istatistik adlari eksikse buradan.
        preg_match('/M:\s*(\d+).*?G:\s*(\d+)(?:.*?A:\s*(\d+))?/', (string) ($o['shortDisplayValue'] ?? ''), $e);

        $liste[] = [
            'oyuncu'   => (string) ($o['athlete']['displayName'] ?? ''),
            'uyruk'    => '',
            'takim_id' => (int) ($o['athlete']['team']['id'] ?? 0),
            'takim'    => (string) ($o['athlete']['team']['displayName'] ?? ''),
            'logo'     => (string) ($o['athlete']['team']['logos'][0]['href'] ?? ''),
            'mac'      => $ist['appearances'] ?? (int) ($e[1] ?? 0),
            'gol'      => $tur === 'gol' ? (int) ($o['value'] ?? ($e[2] ?? 0)) : ($ist['totalGoals'] ?? (int) ($e[2] ?? 0)),
            'asist'    => $tur === 'asist' ? (int) ($o['value'] ?? 0) : ($ist['goalAssists'] ?? (int) ($e[3] ?? 0)),
        ];
    }

    return $liste;
}

// --- Fikstür: maç günleri turlara ayrılıyor --------------------------------

/**
 * Maç günlerini turlara ayırır: aralarında iki günden fazla boşluk olan
 * günler ayrı tur. ESPN'de "hafta" bilgisi yok; ligler bir haftanın
 * maçlarını art arda günlere yayıyor, bu gruplama haftayı veriyor.
 *
 * @param list<string> $takvim Ymd
 * @return list<string> "Ymd-Ymd"
 */
function futbol_turlar_takvimden(array $takvim): array
{
    $turlar = [];
    $bas = $son = null;

    foreach ($takvim as $gun) {
        if ($son !== null && strtotime($gun) - strtotime($son) > 2 * 86400) {
            $turlar[] = $bas . '-' . $son;
            $bas = null;
        }

        $bas ??= $gun;
        $son = $gun;
    }

    if ($bas !== null) {
        $turlar[] = $bas . '-' . $son;
    }

    return $turlar;
}

/** Bugünü ya da sıradaki maç günlerini içeren tur; sezon bittiyse sonuncusu. */
function futbol_guncel_tur(array $turlar): string
{
    $bugun = date('Ymd');

    foreach ($turlar as $tur) {
        if (explode('-', $tur)[1] >= $bugun) {
            return $tur;
        }
    }

    return (string) (end($turlar) ?: '');
}

/** "20261016-20261019" -> "16–19 Eki"; ay farklıysa iki ay. */
function futbol_tur_adi(string $tur): string
{
    if (!preg_match('/^(\d{8})-(\d{8})$/', $tur, $e)) {
        return $tur;
    }

    $aylar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    $bas = (int) strtotime($e[1]);
    $son = (int) strtotime($e[2]);
    $ay  = static fn (int $t): string => $aylar[(int) date('n', $t) - 1];

    if ($e[1] === $e[2]) {
        return (int) date('j', $bas) . ' ' . $ay($bas);
    }

    return date('n', $bas) === date('n', $son)
        ? (int) date('j', $bas) . '–' . (int) date('j', $son) . ' ' . $ay($son)
        : (int) date('j', $bas) . ' ' . $ay($bas) . ' – ' . (int) date('j', $son) . ' ' . $ay($son);
}

/**
 * Bir tarih aralığının maçları (golleri, kartları, istatistikleriyle).
 *
 * ESPN ARALIK SORGUSUNU KABUL ETMIYOR: scoreboard?dates=20261016-20261020
 * HTTP 400 donuyor, tek gun (dates=20261018) 200. Kaynak testiyle
 * goruldu (22.10.2026); fikstur bu yuzden "Bu gunlerin maclari
 * alinamadi" diyordu. Aralik gun gun cekiliyor ve birlestiriliyor; her
 * gun ayri saklaniyor (bkz. futbol_gun_maclari).
 *
 * Bir gun alinamazsa digerleri yine gosteriliyor. zaman: en eski
 * verinin cekildigi an.
 *
 * @return array{veri:?array,zaman:int} veri: list<mac>
 */
function futbol_tarih_maclari(string $lig, string $aralik): array
{
    if (!preg_match('/^(\d{8})(?:-(\d{8}))?$/', $aralik, $e)) {
        return ['veri' => null, 'zaman' => 0];
    }

    $bas = DateTimeImmutable::createFromFormat('!Ymd', $e[1]);
    $son = DateTimeImmutable::createFromFormat('!Ymd', $e[2] ?? $e[1]);

    if ($bas === false || $son === false || $son < $bas) {
        return ['veri' => null, 'zaman' => 0];
    }

    $maclar = [];
    $zaman  = 0;
    $alinan = 0;

    // Bir tur birkac gun; adresten gelen asiri uzun araliga karsi sinir.
    for ($gun = $bas, $i = 0; $gun <= $son && $i < 14; $gun = $gun->modify('+1 day'), $i++) {
        $kayit = futbol_gun_maclari($lig, $gun->format('Ymd'));

        if ($kayit['veri'] === null) {
            continue;
        }

        $alinan++;
        $zaman = $zaman === 0 ? $kayit['zaman'] : min($zaman, $kayit['zaman']);

        foreach ($kayit['veri']['maclar'] as $m) {
            $maclar[$m['id']] = $m;
        }
    }

    if ($alinan === 0) {
        return ['veri' => null, 'zaman' => 0];
    }

    $maclar = array_values($maclar);
    usort($maclar, static fn (array $a, array $b): int => $a['zaman'] <=> $b['zaman']);

    return ['veri' => $maclar, 'zaman' => $zaman];
}

/**
 * Tek bir ESPN gününün maçları.
 *
 * Gecmis gun bir daha degismiyor: bir hafta saklaniyor. Dunu, bugunu ya
 * da gelecegi 2 dakikada bir tazeleniyor. Maci olmayan gun de BOS LISTE
 * olarak saklaniyor; yoksa her ziyaret ESPN'e yeniden giderdi.
 *
 * @return array{veri:?array{maclar:list<array>},zaman:int}
 */
function futbol_gun_maclari(string $lig, string $gun): array
{
    $sure = $gun < date('Ymd', time() - 86400) ? 7 * 86400 : 120;

    return futbol_onbellekli('/site/v2/sports/soccer/' . $lig . '/scoreboard', ['dates' => $gun, 'limit' => 200], $sure, static function (array $c) use ($lig): ?array {
        if (!isset($c['events']) || !is_array($c['events'])) {
            return null;
        }

        $ligAdi = (string) ($c['leagues'][0]['name'] ?? '');
        $maclar = [];

        foreach ($c['events'] as $olay) {
            $maclar[] = futbol_mac_kirp($olay, $lig, $ligAdi, true);
        }

        return ['maclar' => $maclar];
    });
}

/**
 * ESPN olayını (event) sitenin maç biçimine çevirir.
 *
 * @param array<string,mixed> $o
 */
function futbol_mac_kirp(array $o, string $lig, string $ligAdi, bool $ayrintili): array
{
    $c  = $o['competitions'][0] ?? [];
    $ev = $dep = [];

    foreach ($c['competitors'] ?? [] as $t) {
        if (($t['homeAway'] ?? '') === 'home') {
            $ev = $t;
        } else {
            $dep = $t;
        }
    }

    // Skor scoreboard'da metin, takim programinda nesne.
    $skor = static function (array $t): ?int {
        $s = $t['score'] ?? null;

        if (is_array($s)) {
            $s = $s['displayValue'] ?? $s['value'] ?? null;
        }

        return $s === null || $s === '' ? null : (int) $s;
    };

    $durum   = $o['status'] ?? ($c['status'] ?? []);
    $kod     = futbol_durum_kodu($durum);
    $ligKodu = (string) ($o['league']['slug'] ?? $lig);

    $mac = [
        'id'       => (int) ($o['id'] ?? 0),
        'zaman'    => (int) strtotime((string) ($o['date'] ?? '')),
        'durum'    => $kod,
        'dakika'   => (int) ($durum['displayClock'] ?? 0),
        'stat'     => (string) ($c['venue']['fullName'] ?? ''),
        'lig_id'   => $ligKodu,
        'lig'      => futbol_lig_katalog_adi($ligKodu) ?: (string) ($o['league']['name'] ?? $ligAdi),
        'tur'      => '',
        'ev_id'    => (int) ($ev['team']['id'] ?? 0),
        'ev'       => (string) ($ev['team']['displayName'] ?? ''),
        'ev_logo'  => (string) ($ev['team']['logo'] ?? ($ev['team']['logos'][0]['href'] ?? '')),
        'dep_id'   => (int) ($dep['team']['id'] ?? 0),
        'dep'      => (string) ($dep['team']['displayName'] ?? ''),
        'dep_logo' => (string) ($dep['team']['logo'] ?? ($dep['team']['logos'][0]['href'] ?? '')),
        'ev_gol'   => $kod === 'NS' ? null : $skor($ev),
        'dep_gol'  => $kod === 'NS' ? null : $skor($dep),
        'iy_ev'    => null,
        'iy_dep'   => null,
    ];

    if (!$ayrintili) {
        return $mac;
    }

    $olaylar = [];

    foreach ($c['details'] ?? [] as $d) {
        [$dk, $ek] = futbol_dakika((string) ($d['clock']['displayValue'] ?? ''));
        $gol  = !empty($d['scoringPlay']);
        $kart = !empty($d['yellowCard']) || !empty($d['redCard']);

        $olaylar[] = [
            'dk'     => $dk,
            'ek'     => $ek,
            'takim'  => (int) ($d['team']['id'] ?? 0),
            'oyuncu' => (string) ($d['athletesInvolved'][0]['displayName'] ?? ''),
            'asist'  => '',
            'tip'    => $gol ? 'Goal' : ($kart ? 'Card' : (string) ($d['type']['text'] ?? '')),
            'detay'  => match (true) {
                $gol && !empty($d['ownGoal'])     => 'Own Goal',
                $gol && !empty($d['penaltyKick']) => 'Penalty',
                $gol                              => 'Normal Goal',
                !empty($d['redCard'])             => 'Red Card',
                !empty($d['yellowCard'])          => 'Yellow Card',
                default                           => (string) ($d['type']['text'] ?? ''),
            },
        ];
    }

    usort($olaylar, static fn (array $a, array $b): int => [$a['dk'], $a['ek']] <=> [$b['dk'], $b['ek']]);

    $istatistik = [];

    foreach ([$ev, $dep] as $t) {
        foreach ($t['statistics'] ?? [] as $s) {
            $istatistik[(string) ($s['name'] ?? '')][(int) ($t['team']['id'] ?? 0)] = (string) ($s['displayValue'] ?? '');
        }
    }

    return $mac + [
        'olaylar'    => $olaylar,
        'istatistik' => $istatistik,
        'kadrolar'   => [],
        'hakem'      => '',
    ];
}

/** ESPN durumu -> FT, AET, PEN, LIVE, HT, NS, PST, CANC, ABD. */
function futbol_durum_kodu(array $durum): string
{
    $ad    = (string) ($durum['type']['name'] ?? '');
    $evre  = (string) ($durum['type']['state'] ?? '');

    return match (true) {
        $ad === 'STATUS_HALFTIME'                                   => 'HT',
        $ad === 'STATUS_FINAL_AET'                                  => 'AET',
        $ad === 'STATUS_FINAL_PEN'                                  => 'PEN',
        in_array($ad, ['STATUS_POSTPONED', 'STATUS_DELAYED'], true) => 'PST',
        $ad === 'STATUS_CANCELED'                                   => 'CANC',
        $ad === 'STATUS_ABANDONED'                                  => 'ABD',
        $evre === 'in'                                              => 'LIVE',
        $evre === 'post'                                            => !empty($durum['type']['completed']) ? 'FT' : 'PST',
        default                                                     => 'NS',
    };
}

/** "90'+4'" -> [90, 4]; "26'" -> [26, 0]. */
function futbol_dakika(string $yazi): array
{
    return preg_match("/(\\d+)'?\\s*(?:\\+\\s*(\\d+))?/", $yazi, $e)
        ? [(int) $e[1], (int) ($e[2] ?? 0)]
        : [0, 0];
}

// --- Kulüp: maçlar, golleri, analiz -------------------------------------------

/**
 * Kulübün bu ligdeki sezon programı: oynananlar ve sıradakiler.
 *
 * @return array{son:list<array>,sirada:list<array>,ad:string,logo:string,ozet:string,zaman:int}
 */
function futbol_kulup_maclari(int $takim, string $lig): array
{
    $yol  = '/site/v2/sports/soccer/' . $lig . '/teams/' . $takim . '/schedule';
    $kirp = static function (array $c) use ($lig): ?array {
        $maclar = [];

        foreach ($c['events'] ?? [] as $o) {
            $maclar[] = futbol_mac_kirp($o, $lig, '', false);
        }

        if ($maclar === [] && !isset($c['team']['id'])) {
            return null;
        }

        return [
            'maclar' => $maclar,
            'ad'     => (string) ($c['team']['displayName'] ?? ''),
            'logo'   => (string) ($c['team']['logo'] ?? ''),
            'ozet'   => (string) ($c['team']['standingSummary'] ?? ''),
        ];
    };

    $oynanan = futbol_onbellekli($yol, [], 900, $kirp);
    $sirada  = futbol_onbellekli($yol, ['fixture' => 'true'], 3600, $kirp);

    $son = array_values(array_filter(
        $oynanan['veri']['maclar'] ?? [],
        static fn (array $m): bool => in_array($m['durum'], FUTBOL_BITTI, true) || in_array($m['durum'], FUTBOL_CANLI, true)
    ));
    usort($son, static fn (array $a, array $b): int => $b['zaman'] <=> $a['zaman']);

    $siradakiler = array_values(array_filter($sirada['veri']['maclar'] ?? [], static fn (array $m): bool => $m['durum'] === 'NS'));
    usort($siradakiler, static fn (array $a, array $b): int => $a['zaman'] <=> $b['zaman']);

    return [
        'son'    => $son,
        'sirada' => array_slice($siradakiler, 0, 5),
        'ad'     => (string) ($oynanan['veri']['ad'] ?? $sirada['veri']['ad'] ?? ''),
        'logo'   => (string) ($oynanan['veri']['logo'] ?? $sirada['veri']['logo'] ?? ''),
        'ozet'   => (string) ($oynanan['veri']['ozet'] ?? ''),
        'zaman'  => $oynanan['zaman'],
    ];
}

/**
 * Maçların ayrıntısı (goller, kartlar, istatistik): maç günlerinin
 * scoreboard'undan. Ayni gunun bulteni butun kulupler icin ortak ve
 * gecmis gunler bir hafta saklaniyor; kulup sayfasi ESPN'e mac basina
 * ayri istek atmiyor.
 *
 * @param list<array> $maclar futbol_mac_kirp ciktisi (lig_id ve zaman lazim)
 * @return array<int,array> mac id => ayrinti
 */
function futbol_mac_ayrintilari(array $maclar): array
{
    $gunler = [];

    foreach ($maclar as $m) {
        if ($m['zaman'] > 0 && futbol_lig_kodu_gecerli((string) $m['lig_id'])) {
            $gunler[$m['lig_id']][futbol_espn_gunu($m['zaman'])] = true;
        }
    }

    $sonuc = [];

    foreach ($gunler as $lig => $liste) {
        foreach (array_keys($liste) as $gun) {
            foreach (futbol_tarih_maclari((string) $lig, $gun)['veri'] ?? [] as $a) {
                $sonuc[$a['id']] = $a;
            }
        }
    }

    return $sonuc;
}

/**
 * ESPN'in maçı hangi takvim gününe yazdığı. ESPN gunleri ABD Dogu saatine
 * gore ayiriyor (takvimdeki 07:00Z = gece yarisi EDT); Istanbul gecesindeki
 * bir mac ESPN'de bir onceki gune duser.
 */
function futbol_espn_gunu(int $zaman): string
{
    return (new DateTimeImmutable('@' . $zaman))->setTimezone(new DateTimeZone('America/New_York'))->format('Ymd');
}

/** Tek maçın ayrıntısı; ESPN'de maç numarası yetmiyor, lig ve gün lazım. */
function futbol_mac_ayrintisi(int $mac, string $lig, string $gun): ?array
{
    if (!preg_match('/^\d{8}$/', $gun) || !futbol_lig_kodu_gecerli($lig)) {
        return null;
    }

    foreach (futbol_tarih_maclari($lig, $gun)['veri'] ?? [] as $a) {
        if ($a['id'] === $mac) {
            return $a;
        }
    }

    return null;
}

/**
 * Kulübün sezon analizi, oynanan maçlarından hesaplanıyor (ESPN hazır
 * kulüp istatistiği vermiyor).
 *
 * @param list<array> $son        biten ve oynanan maclar, yeniden eskiye
 * @param array<int,array> $ayrinti mac id => ayrinti (gol dakikalari icin)
 */
function futbol_kulup_analizi(int $takim, array $son, array $ayrinti, string $ad, string $logo, string $lig): ?array
{
    $biten = array_values(array_filter($son, static fn (array $m): bool => in_array($m['durum'], FUTBOL_BITTI, true)));

    if ($biten === []) {
        return null;
    }

    $sifir = ['ic' => 0, 'dis' => 0, 'top' => 0];
    $s = [
        'oynanan' => $sifir, 'galibiyet' => $sifir, 'beraberlik' => $sifir, 'maglubiyet' => $sifir,
        'atilan' => $sifir, 'yenilen' => $sifir, 'gol_yemeden' => $sifir, 'gol_atamadan' => $sifir,
    ];

    $enFarkliG = ['ic' => null, 'dis' => null];
    $enFarkliM = ['ic' => null, 'dis' => null];
    $farkG = ['ic' => 0, 'dis' => 0];
    $farkM = ['ic' => 0, 'dis' => 0];
    $sonuclar = [];

    // Eskiden yeniye: seriler sirayla sayilsin.
    foreach (array_reverse($biten) as $m) {
        $ic    = $m['ev_id'] === $takim;
        $yer   = $ic ? 'ic' : 'dis';
        $biz   = (int) ($ic ? $m['ev_gol'] : $m['dep_gol']);
        $onlar = (int) ($ic ? $m['dep_gol'] : $m['ev_gol']);
        $harf  = $biz > $onlar ? 'G' : ($biz === $onlar ? 'B' : 'M');
        $sonuclar[] = $harf;

        foreach ([$yer, 'top'] as $k) {
            $s['oynanan'][$k]++;
            $s[['G' => 'galibiyet', 'B' => 'beraberlik', 'M' => 'maglubiyet'][$harf]][$k]++;
            $s['atilan'][$k] += $biz;
            $s['yenilen'][$k] += $onlar;
            $s['gol_yemeden'][$k] += $onlar === 0 ? 1 : 0;
            $s['gol_atamadan'][$k] += $biz === 0 ? 1 : 0;
        }

        // Skor ev sahibi once yaziliyor.
        $skor = $ic ? $biz . '-' . $onlar : $onlar . '-' . $biz;

        if ($harf === 'G' && $biz - $onlar > $farkG[$yer]) {
            $farkG[$yer] = $biz - $onlar;
            $enFarkliG[$yer] = $skor;
        }

        if ($harf === 'M' && $onlar - $biz > $farkM[$yer]) {
            $farkM[$yer] = $onlar - $biz;
            $enFarkliM[$yer] = $skor;
        }
    }

    $seri = static function (array $sonuclar, string $harf): int {
        $en = $simdi = 0;

        foreach ($sonuclar as $h) {
            $simdi = $h === $harf ? $simdi + 1 : 0;
            $en    = max($en, $simdi);
        }

        return $en;
    };

    $ort = static function (array $gol, array $mac): array {
        $sonuc = [];

        foreach (['ic', 'dis', 'top'] as $k) {
            $sonuc[$k] = $mac[$k] > 0 ? number_format($gol[$k] / $mac[$k], 1) : null;
        }

        return $sonuc;
    };

    // Gol dakikalari ve penaltilar mac olaylarindan.
    $araliklar = ['0-15' => 15, '16-30' => 30, '31-45' => 45, '46-60' => 60, '61-75' => 75, '76-90' => PHP_INT_MAX];
    $dakika    = ['atilan' => array_fill_keys(array_keys($araliklar), 0), 'yenilen' => array_fill_keys(array_keys($araliklar), 0)];
    $penalti   = ['atilan' => 0, 'kacan' => 0];
    $olayVar   = false;

    foreach ($biten as $m) {
        foreach ($ayrinti[$m['id']]['olaylar'] ?? [] as $o) {
            if ($o['tip'] !== 'Goal') {
                continue;
            }

            $olayVar = true;

            // Kendi kalesine gol: ESPN golu atan oyuncunun takimina yaziyor.
            $bizim = $o['detay'] === 'Own Goal' ? $o['takim'] !== $takim : $o['takim'] === $takim;
            $hane  = $bizim ? 'atilan' : 'yenilen';

            foreach ($araliklar as $aralik => $ust) {
                if ($o['dk'] <= $ust) {
                    $dakika[$hane][$aralik]++;
                    break;
                }
            }

            if ($bizim && $o['detay'] === 'Penalty') {
                $penalti['atilan']++;
            }
        }
    }

    $harfler = ['G' => 'W', 'B' => 'D', 'M' => 'L'];

    return $s + [
        'takim'       => $ad,
        'logo'        => $logo,
        'lig'         => futbol_lig_katalog_adi($lig),
        'form'        => implode('', array_map(static fn (string $h): string => $harfler[$h], array_slice($sonuclar, -5))),
        'atilan_ort'  => $ort($s['atilan'], $s['oynanan']),
        'yenilen_ort' => $ort($s['yenilen'], $s['oynanan']),
        'seri'        => [
            'galibiyet'  => $seri($sonuclar, 'G'),
            'beraberlik' => $seri($sonuclar, 'B'),
            'maglubiyet' => $seri($sonuclar, 'M'),
        ],
        'en_farkli_galibiyet'  => $enFarkliG,
        'en_farkli_maglubiyet' => $enFarkliM,
        'penalti'     => $penalti,
        'dizilisler'  => [],
        'dakika'      => $olayVar ? $dakika : [],
    ];
}

// --- Biçim yardımcıları ------------------------------------------------------

/** Maçın skoru ya da saati: "2 - 1", "20:00", "Ertelendi". */
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

/** Takımın bu maçtaki sonucu: G / B / M; oynanmadıysa boş. */
function futbol_sonuc_harfi(array $m, int $takim): string
{
    if (!in_array($m['durum'], FUTBOL_BITTI, true)) {
        return '';
    }

    $biz   = $m['ev_id'] === $takim ? (int) $m['ev_gol'] : (int) $m['dep_gol'];
    $onlar = $m['ev_id'] === $takim ? (int) $m['dep_gol'] : (int) $m['ev_gol'];

    return $biz > $onlar ? 'G' : ($biz === $onlar ? 'B' : 'M');
}
