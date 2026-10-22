<?php
declare(strict_types=1);

/**
 * Ekonomi sayfası: piyasa grafikleri ve şirket fiyatları (Yahoo Finance).
 *
 * Site sahibinin istegi (22.10.2026): "ekonomide borsa, altin vs.
 * grafiklerini gorebilelim, canli gostersin; sirketlerle ilgili
 * bilgileri cekelim".
 *
 * Kaynak, piyasa seridinin de kullandigi Yahoo Finance grafik ucu
 * (anahtarsiz). Yapisi kaynak testiyle (kaynak_dene --ham) goruldu:
 *   - /v8/finance/chart/SEMBOL?range=..&interval=..: meta.regularMarketPrice,
 *     meta.regularMarketChangePercent, timestamp[], indicators.quote[0].close[]
 *   - /v8/finance/spark?symbols=A,B,..: sembol basina fulldayPrice,
 *     fulldayChangePercent (sirket tablosu, tek istekte on sirket).
 * Veri gecikmeli olabilir (borsa icin 15 dakikaya kadar); sayfada yaziyor.
 *
 * Gram altin Yahoo'da yok: ons (GC=F) x dolar (USDTRY=X) / 31,1035.
 */

require_once __DIR__ . '/canli_onbellek.php';

/** Ons -> gram. */
const PIYASA_ONS_GRAM = 31.1035;

/**
 * Kartlar ve grafikler, sırasıyla.
 *
 * @return array<string,array{ad:string,sembol:string,birim:string,hane:int}>
 */
function piyasa_araclari(): array
{
    return [
        'bist'  => ['ad' => 'BIST 100',     'sembol' => 'XU100.IS', 'birim' => '',  'hane' => 2],
        'usd'   => ['ad' => 'Dolar/TL',     'sembol' => 'USDTRY=X', 'birim' => '₺', 'hane' => 4],
        'eur'   => ['ad' => 'Euro/TL',      'sembol' => 'EURTRY=X', 'birim' => '₺', 'hane' => 4],
        'altin' => ['ad' => 'Gram Altın',   'sembol' => '',         'birim' => '₺', 'hane' => 2],
        'ons'   => ['ad' => 'Ons Altın',    'sembol' => 'GC=F',     'birim' => '$', 'hane' => 2],
        'brent' => ['ad' => 'Brent Petrol', 'sembol' => 'BZ=F',     'birim' => '$', 'hane' => 2],
    ];
}

/**
 * Grafik dönemleri: Yahoo aralığı ve önbellek süresi.
 *
 * @return array<string,array{ad:string,aralik:string,sure:int}>
 */
function piyasa_donemleri(): array
{
    return [
        '3a' => ['ad' => '3 Ay',  'aralik' => '3mo', 'sure' => 3600],
        '1y' => ['ad' => '1 Yıl', 'aralik' => '1y',  'sure' => 3 * 3600],
        '5y' => ['ad' => '5 Yıl', 'aralik' => '5y',  'sure' => 12 * 3600],
    ];
}

/**
 * Şirket tablosu: Borsa İstanbul'un en büyük şirketlerinden bir seçki.
 *
 * BIST 30 bileseni donem donem degisiyor; liste "BIST 30" diye
 * sunulmuyor, "buyuk sirketler" diye sunuluyor.
 *
 * @return array<string,string> kod => ad
 */
function piyasa_sirketleri(): array
{
    return [
        'AKBNK' => 'Akbank',              'ALARK' => 'Alarko Holding',
        'ASELS' => 'Aselsan',             'ASTOR' => 'Astor Enerji',
        'BIMAS' => 'BİM Mağazalar',       'EKGYO' => 'Emlak Konut GYO',
        'ENKAI' => 'Enka İnşaat',         'EREGL' => 'Ereğli Demir Çelik',
        'FROTO' => 'Ford Otosan',         'GARAN' => 'Garanti BBVA',
        'GUBRF' => 'Gübre Fabrikaları',   'ISCTR' => 'İş Bankası (C)',
        'KCHOL' => 'Koç Holding',         'KRDMD' => 'Kardemir (D)',
        'MGROS' => 'Migros',              'PETKM' => 'Petkim',
        'PGSUS' => 'Pegasus',             'SAHOL' => 'Sabancı Holding',
        'SASA'  => 'SASA Polyester',      'SISE'  => 'Şişecam',
        'TAVHL' => 'TAV Havalimanları',   'TCELL' => 'Turkcell',
        'THYAO' => 'Türk Hava Yolları',   'TOASO' => 'Tofaş',
        'TTKOM' => 'Türk Telekom',        'TUPRS' => 'Tüpraş',
        'ULKER' => 'Ülker',               'VAKBN' => 'Vakıfbank',
        'YKBNK' => 'Yapı Kredi',          'HALKB' => 'Halkbank',
    ];
}

/**
 * Yahoo grafik ucunu okur.
 *
 * @return array{fiyat:?float,degisim:?float,noktalar:list<array{0:int,1:float}>}|null
 */
function yahoo_grafik(string $sembol, string $aralik, string $adim): ?array
{
    $ham = canli_indir(
        'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($sembol)
        . '?range=' . rawurlencode($aralik) . '&interval=' . rawurlencode($adim)
    );

    return $ham !== null ? yahoo_grafik_coz($ham) : null;
}

/** @return array{fiyat:?float,degisim:?float,noktalar:list<array{0:int,1:float}>}|null */
function yahoo_grafik_coz(string $ham): ?array
{
    $sonuc = json_decode($ham, true)['chart']['result'][0] ?? null;

    if (!is_array($sonuc)) {
        return null;
    }

    $meta     = is_array($sonuc['meta'] ?? null) ? $sonuc['meta'] : [];
    $zamanlar = is_array($sonuc['timestamp'] ?? null) ? $sonuc['timestamp'] : [];
    $kapanis  = $sonuc['indicators']['quote'][0]['close'] ?? [];
    $hacim    = $sonuc['indicators']['quote'][0]['volume'] ?? [];
    $noktalar = [];
    $hacimler = [];

    foreach ($zamanlar as $i => $t) {
        $d = $kapanis[$i] ?? null;

        if (is_numeric($d) && (float) $d > 0) {
            $noktalar[] = [(int) $t, round((float) $d, 4)];
            // Hacim sirket sayfasi icin (ortalama islem hacmi); kapanisla
            // ayni noktalarda tutuluyor.
            $hacimler[] = is_numeric($hacim[$i] ?? null) ? (int) $hacim[$i] : 0;
        }
    }

    $fiyat = isset($meta['regularMarketPrice']) ? (float) $meta['regularMarketPrice'] : null;

    // Gunluk degisim: kaynagin kendi yuzdesi; yoksa (yalnizca gun ici
    // aralikta anlamli) onceki kapanistan.
    $degisim = isset($meta['regularMarketChangePercent'])
        ? round((float) $meta['regularMarketChangePercent'], 2)
        : null;

    if ($degisim === null && $fiyat !== null && ($meta['range'] ?? '') === '1d') {
        $onceki  = (float) ($meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0);
        $degisim = $onceki > 0 ? round(($fiyat - $onceki) / $onceki * 100, 2) : null;
    }

    if ($fiyat === null && $noktalar === []) {
        return null;
    }

    return [
        'fiyat'    => $fiyat,
        'degisim'  => $degisim,
        'noktalar' => $noktalar,
        'hacimler' => $hacimler,
        'ad'       => (string) ($meta['longName'] ?? $meta['shortName'] ?? ''),
        'para'     => (string) ($meta['currency'] ?? ''),
        'yuksek52' => isset($meta['fiftyTwoWeekHigh']) ? (float) $meta['fiftyTwoWeekHigh'] : null,
        'dusuk52'  => isset($meta['fiftyTwoWeekLow']) ? (float) $meta['fiftyTwoWeekLow'] : null,
    ];
}

/**
 * Gram altın serisi: her ons noktası, o ana kadarki son dolar kuruyla.
 *
 * Gunluk seride iki kaynagin "gun" zaman damgasi farkli saatlerde
 * (ons New York, kur Londra gece yarisi); ayni gun sayilsin diye 12 saat
 * pay birakiliyor. Gun icinde pay yok: gelecekteki bir kur kullanilmasin.
 *
 * @param list<array{0:int,1:float}> $ons
 * @param list<array{0:int,1:float}> $usd
 * @return list<array{0:int,1:float}>
 */
function piyasa_gram_serisi(array $ons, array $usd, int $tolerans): array
{
    $gram = [];
    $j    = 0;
    $kur  = null;

    foreach ($ons as [$t, $o]) {
        while ($j < count($usd) && $usd[$j][0] <= $t + $tolerans) {
            $kur = $usd[$j][1];
            $j++;
        }

        if ($kur !== null) {
            $gram[] = [$t, round($o * $kur / PIYASA_ONS_GRAM, 2)];
        }
    }

    return $gram;
}

/**
 * Bir aracın seri verisi (önbellekten).
 *
 * $aralik '1d' ise gun ici (5 dakikalik), degilse gunluk.
 *
 * @return array{veri:?array{fiyat:?float,degisim:?float,noktalar:list<array{0:int,1:float}>},zaman:int}
 */
function piyasa_seri(string $arac, string $aralik, int $sure): array
{
    $tanim = piyasa_araclari()[$arac] ?? null;
    $adim  = $aralik === '1d' ? '5m' : ($aralik === '5y' ? '1wk' : '1d');

    if ($tanim === null) {
        return ['veri' => null, 'zaman' => 0];
    }

    if ($arac === 'altin') {
        $ons = piyasa_seri('ons', $aralik, $sure);
        $usd = piyasa_seri('usd', $aralik, $sure);

        if ($ons['veri'] === null || $usd['veri'] === null) {
            return ['veri' => null, 'zaman' => 0];
        }

        $seri = piyasa_gram_serisi($ons['veri']['noktalar'], $usd['veri']['noktalar'], $aralik === '1d' ? 0 : 43200);
        $fiyat = ($ons['veri']['fiyat'] !== null && $usd['veri']['fiyat'] !== null)
            ? round($ons['veri']['fiyat'] * $usd['veri']['fiyat'] / PIYASA_ONS_GRAM, 2)
            : null;

        // Degisim: iki yuzdenin bileskesi, (1+a)(1+b)-1.
        $a = $ons['veri']['degisim'];
        $b = $usd['veri']['degisim'];

        return [
            'veri' => [
                'fiyat'    => $fiyat,
                'degisim'  => ($a !== null && $b !== null) ? round(((1 + $a / 100) * (1 + $b / 100) - 1) * 100, 2) : null,
                'noktalar' => $seri,
            ],
            'zaman' => min($ons['zaman'], $usd['zaman']),
        ];
    }

    return canli_onbellek(
        'piyasa_' . $tanim['sembol'] . '_' . $aralik,
        $sure,
        static fn (): ?array => yahoo_grafik($tanim['sembol'], $aralik, $adim)
    );
}

/**
 * Şirket fiyatları (önbellekten, 2 dakika).
 *
 * @return array{veri:?array<string,array{fiyat:float,degisim:?float}>,zaman:int}
 */
function piyasa_sirket_fiyatlari(): array
{
    return canli_onbellek('piyasa_sirketler', 120, static function (): ?array {
        $sonuc = [];

        foreach (array_chunk(array_keys(piyasa_sirketleri()), 10) as $parca) {
            $semboller = implode(',', array_map(static fn (string $k): string => $k . '.IS', $parca));
            $ham       = canli_indir(
                'https://query1.finance.yahoo.com/v8/finance/spark?symbols=' . rawurlencode($semboller)
                . '&range=1d&interval=1d'
            );

            if ($ham !== null) {
                $sonuc += yahoo_spark_coz($ham);
            }
        }

        return $sonuc !== [] ? $sonuc : null;
    });
}

/** @return array<string,array{fiyat:float,degisim:?float}> kod (".IS"siz) => fiyat */
function yahoo_spark_coz(string $ham): array
{
    $veri  = json_decode($ham, true);
    $sonuc = [];

    if (!is_array($veri)) {
        return [];
    }

    foreach ($veri as $sembol => $s) {
        if (!is_array($s)) {
            continue;
        }

        $kapanis = is_array($s['close'] ?? null) ? array_values(array_filter($s['close'], 'is_numeric')) : [];
        $fiyat   = $s['fulldayPrice'] ?? ($kapanis !== [] ? end($kapanis) : null);

        if (!is_numeric($fiyat) || (float) $fiyat <= 0) {
            continue;
        }

        $degisim = $s['fulldayChangePercent'] ?? null;

        if (!is_numeric($degisim)) {
            $onceki  = (float) ($s['chartPreviousClose'] ?? $s['previousClose'] ?? 0);
            $degisim = $onceki > 0 ? ((float) $fiyat - $onceki) / $onceki * 100 : null;
        }

        $sonuc[preg_replace('/\.IS$/', '', (string) $sembol) ?? (string) $sembol] = [
            'fiyat'   => round((float) $fiyat, 2),
            'degisim' => $degisim !== null ? round((float) $degisim, 2) : null,
        ];
    }

    return $sonuc;
}

/** Türkçe sayı biçimi: 11.220,37 */
function piyasa_sayi(?float $deger, int $hane = 2): string
{
    return $deger === null ? '–' : number_format($deger, $hane, ',', '.');
}

/** "▲ %1,25" — değişim yoksa boş. */
function piyasa_degisim_html(?float $degisim, string $veri = ''): string
{
    $nitelik = $veri !== '' ? ' data-canli-degisim="' . e($veri) . '"' : '';

    if ($degisim === null) {
        return '<span class="pdeg bos"' . $nitelik . '></span>';
    }

    $sinif = $degisim > 0 ? 'arti' : ($degisim < 0 ? 'eksi' : 'duz');
    $ok    = $degisim > 0 ? '▲' : ($degisim < 0 ? '▼' : '■');

    return '<span class="pdeg ' . $sinif . '"' . $nitelik . '>' . $ok . ' %'
        . number_format(abs($degisim), 2, ',', '.') . '</span>';
}

/**
 * Küçük gün içi çizgisi (kart için). Etiket yok; yalnızca biçim.
 *
 * @param list<array{0:int,1:float}> $noktalar
 */
function piyasa_kivrim(array $noktalar, ?float $degisim): string
{
    if (count($noktalar) < 2) {
        return '';
    }

    $t0 = $noktalar[0][0];
    $t1 = $noktalar[count($noktalar) - 1][0];
    $d  = array_column($noktalar, 1);
    $lo = min($d);
    $hi = max($d);

    if ($t1 <= $t0) {
        return '';
    }

    $aralik = $hi - $lo ?: 1.0;
    $yol    = '';

    foreach ($noktalar as $i => [$t, $v]) {
        $x = ($t - $t0) / ($t1 - $t0) * 200;
        $y = 46 - ($v - $lo) / $aralik * 42;
        $yol .= ($i === 0 ? 'M' : ' L') . round($x, 1) . ' ' . round($y, 1);
    }

    $sinif = $degisim === null ? '' : ($degisim > 0 ? ' arti' : ($degisim < 0 ? ' eksi' : ''));

    return '<svg class="kivrim' . $sinif . '" viewBox="0 0 200 50" preserveAspectRatio="none" aria-hidden="true">'
        . '<path d="' . $yol . '" vector-effect="non-scaling-stroke"/></svg>';
}
