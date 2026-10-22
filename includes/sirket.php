<?php
declare(strict_types=1);

/**
 * Şirket sayfası: bir Borsa İstanbul hissesinin geçmişi ve analiz verileri.
 *
 * Site sahibinin istegi (22.10.2026): "bir sirket secelim, son 10 yillik
 * gecmisini okuyucu izleyebilsin; analistlerin ihtiyac duyabilecegi
 * bilgileri gorelim".
 *
 * Fiyat ve hacim Yahoo Finance grafik ucundan (piyasa sayfasiyla ayni
 * kaynak; anahtarsiz). Analiz verileri bu seriden HESAPLANIYOR, bir
 * kaynaktan kopyalanmiyor:
 *   - donem getirileri (1 ay - 10 yil) ve yillik bilesik getiri,
 *   - takvim yili getirileri,
 *   - yillik oynaklik (gunluk logaritmik getirilerin standart sapmasi),
 *   - BIST 100'e gore beta ve korelasyon (son 1 yil, gunluk),
 *   - en buyuk dusus (zirveden dibe),
 *   - 52 haftalik en yuksek / en dusuk ve zirveye uzaklik,
 *   - ortalama gunluk islem hacmi (son 3 ay).
 * Hesap yontemi sayfada yaziyor: analist sayinin nereden geldigini bilmeli.
 */

require_once __DIR__ . '/piyasalar.php';

/**
 * Dönemler: Yahoo aralığı, adımı ve önbellek süresi.
 *
 * @return array<string,array{ad:string,aralik:string,adim:string,sure:int}>
 */
function sirket_donemleri(): array
{
    return [
        '1a'  => ['ad' => '1 Ay',   'aralik' => '1mo', 'adim' => '1d',  'sure' => 600],
        '6a'  => ['ad' => '6 Ay',   'aralik' => '6mo', 'adim' => '1d',  'sure' => 1800],
        '1y'  => ['ad' => '1 Yıl',  'aralik' => '1y',  'adim' => '1d',  'sure' => 3600],
        '5y'  => ['ad' => '5 Yıl',  'aralik' => '5y',  'adim' => '1wk', 'sure' => 6 * 3600],
        '10y' => ['ad' => '10 Yıl', 'aralik' => '10y', 'adim' => '1mo', 'sure' => 12 * 3600],
    ];
}

/** "thyao" -> "THYAO"; geçersizse null. Borsa kodu 3-6 harf/rakam. */
function sirket_kod_temiz(string $kod): ?string
{
    $kod = strtoupper(trim($kod));

    return preg_match('/^[A-Z0-9]{3,6}$/', $kod) ? $kod : null;
}

/** Şirketin adı: seçkideki ad, yoksa kaynağın verdiği ad, yoksa kod. */
function sirket_adi(string $kod, string $kaynaktaki = ''): string
{
    return piyasa_sirketleri()[$kod] ?? ($kaynaktaki !== '' ? $kaynaktaki : $kod);
}

/**
 * Bir dönemin serisi (önbellekten). '1d': gün içi, anlık fiyat için.
 *
 * @return array{veri:?array,zaman:int}
 */
function sirket_seri(string $kod, string $donem): array
{
    if ($donem === '1d') {
        $tanim = ['aralik' => '1d', 'adim' => '5m', 'sure' => 120];
    } else {
        $tanim = sirket_donemleri()[$donem] ?? sirket_donemleri()['1y'];
    }

    return canli_onbellek(
        'sirket_' . $kod . '_' . $tanim['aralik'],
        $tanim['sure'],
        static fn (): ?array => yahoo_grafik($kod . '.IS', $tanim['aralik'], $tanim['adim'])
    );
}

/** İki değer arasındaki yüzde değişim. */
function sirket_yuzde(?float $once, ?float $sonra): ?float
{
    return ($once !== null && $sonra !== null && $once > 0) ? ($sonra / $once - 1) * 100 : null;
}

/**
 * Analiz verileri.
 *
 * @param list<array{0:int,1:float}> $gunluk  son 1 yil, gunluk kapanis
 * @param list<int>                  $hacim   ayni noktalarin hacmi
 * @param list<array{0:int,1:float}> $aylik   son 10 yil, aylik kapanis
 * @param list<array{0:int,1:float}> $endeks  BIST 100, son 1 yil, gunluk
 * @return array<string,mixed>
 */
function sirket_analiz(array $gunluk, array $hacim, array $aylik, array $endeks,
                       ?float $yuksek52 = null, ?float $dusuk52 = null): array
{
    $sonuc = ['getiriler' => [], 'yillik' => []];
    $son   = $gunluk !== [] ? $gunluk[count($gunluk) - 1][1] : ($aylik !== [] ? $aylik[count($aylik) - 1][1] : null);

    // --- donem getirileri ----------------------------------------------------
    $gunGeri = static function (int $islemGunu) use ($gunluk): ?float {
        $i = count($gunluk) - 1 - $islemGunu;

        return $i >= 0 ? $gunluk[$i][1] : null;
    };

    $ayGeri = static function (int $ay) use ($aylik): ?float {
        $i = count($aylik) - 1 - $ay;

        return $i >= 0 ? $aylik[$i][1] : null;
    };

    // Yilbasindan beri: gecen yilin son kapanisindan (seride yoksa bu
    // yilin ilk kapanisindan).
    $yilBasi = null;

    foreach ($gunluk as [$t, $d]) {
        if ((int) date('Y', $t) < (int) date('Y')) {
            $yilBasi = $d;
        } else {
            $yilBasi ??= $d;
            break;
        }
    }

    foreach ([
        '1 Ay'  => $gunGeri(21),
        '3 Ay'  => $gunGeri(63),
        '6 Ay'  => $gunGeri(126),
        'Yılbaşından beri' => $yilBasi,
        '1 Yıl' => $gunluk !== [] ? $gunluk[0][1] : $ayGeri(12),
    ] as $ad => $once) {
        $sonuc['getiriler'][$ad] = ['getiri' => sirket_yuzde($once, $son), 'yillik' => null];
    }

    foreach ([3, 5, 10] as $yil) {
        $once = $ayGeri(12 * $yil);
        $top  = sirket_yuzde($once, $son);

        $sonuc['getiriler'][$yil . ' Yıl'] = [
            'getiri' => $top,
            // Yillik bilesik getiri: (son/ilk)^(1/yil) - 1
            'yillik' => ($once !== null && $son !== null && $once > 0)
                ? (pow($son / $once, 1 / $yil) - 1) * 100
                : null,
        ];
    }

    // --- takvim yili getirileri --------------------------------------------
    $yilSonlari = [];

    foreach ($aylik as [$t, $d]) {
        $yilSonlari[(int) date('Y', $t)] = $d;
    }

    ksort($yilSonlari);
    $onceki = null;

    foreach ($yilSonlari as $yil => $d) {
        if ($onceki !== null) {
            // Bu yil henuz bitmediyse son kapanis gunluk seriden (daha taze).
            $deger = ($yil === (int) date('Y') && $son !== null) ? $son : $d;
            $sonuc['yillik'][$yil] = sirket_yuzde($onceki, $deger);
        }

        $onceki = $d;
    }

    // Bu yilin satiri "Yilbasindan beri" ile ayni sayi olsun: gunluk seri
    // gecen yilin son kapanisini daha kesin veriyor (aylik seride ay sonu).
    $buYil = (int) date('Y');

    if (array_key_exists($buYil, $sonuc['yillik']) && $sonuc['getiriler']['Yılbaşından beri']['getiri'] !== null) {
        $sonuc['yillik'][$buYil] = $sonuc['getiriler']['Yılbaşından beri']['getiri'];
    }

    krsort($sonuc['yillik']);

    // --- oynaklik, beta, korelasyon -----------------------------------------
    $getiriler = [];

    for ($i = 1; $i < count($gunluk); $i++) {
        $getiriler[date('Y-m-d', $gunluk[$i][0])] = log($gunluk[$i][1] / $gunluk[$i - 1][1]);
    }

    $sonuc['oynaklik'] = count($getiriler) > 20 ? sirket_std(array_values($getiriler)) * sqrt(252) * 100 : null;

    $endeksGetiri = [];

    for ($i = 1; $i < count($endeks); $i++) {
        $endeksGetiri[date('Y-m-d', $endeks[$i][0])] = log($endeks[$i][1] / $endeks[$i - 1][1]);
    }

    $ortak = array_keys(array_intersect_key($getiriler, $endeksGetiri));
    $sonuc['beta'] = $sonuc['korelasyon'] = null;

    if (count($ortak) > 60) {
        $x = array_map(static fn ($g) => $endeksGetiri[$g], $ortak);
        $y = array_map(static fn ($g) => $getiriler[$g], $ortak);
        $mx = array_sum($x) / count($x);
        $my = array_sum($y) / count($y);
        $kov = $vx = $vy = 0.0;

        foreach ($x as $i => $xi) {
            $kov += ($xi - $mx) * ($y[$i] - $my);
            $vx  += ($xi - $mx) ** 2;
            $vy  += ($y[$i] - $my) ** 2;
        }

        $sonuc['beta']       = $vx > 0 ? $kov / $vx : null;
        $sonuc['korelasyon'] = ($vx > 0 && $vy > 0) ? $kov / sqrt($vx * $vy) : null;
    }

    // --- en buyuk dusus ------------------------------------------------------
    $sonuc['dusus1y']  = sirket_en_buyuk_dusus($gunluk);
    $sonuc['dusus10y'] = sirket_en_buyuk_dusus($aylik);

    // --- 52 hafta ve hacim ---------------------------------------------------
    $degerler = array_column($gunluk, 1);
    $sonuc['yuksek52'] = $yuksek52 ?? ($degerler !== [] ? max($degerler) : null);
    $sonuc['dusuk52']  = $dusuk52 ?? ($degerler !== [] ? min($degerler) : null);
    $sonuc['zirveye']  = sirket_yuzde($sonuc['yuksek52'], $son);

    $sonHacim = array_slice($hacim, -63);
    $sonuc['hacim3a'] = $sonHacim !== [] ? array_sum($sonHacim) / count($sonHacim) : null;

    return $sonuc;
}

/** Örneklem standart sapması. */
function sirket_std(array $dizi): float
{
    $n = count($dizi);

    if ($n < 2) {
        return 0.0;
    }

    $ort = array_sum($dizi) / $n;
    $top = 0.0;

    foreach ($dizi as $d) {
        $top += ($d - $ort) ** 2;
    }

    return sqrt($top / ($n - 1));
}

/**
 * Zirveden dibe en büyük düşüş (yüzde, eksi) ve tarihleri.
 *
 * @param list<array{0:int,1:float}> $seri
 * @return array{yuzde:float,zirve:int,dip:int}|null
 */
function sirket_en_buyuk_dusus(array $seri): ?array
{
    if (count($seri) < 2) {
        return null;
    }

    $zirve = $seri[0];
    $enKotu = ['yuzde' => 0.0, 'zirve' => $seri[0][0], 'dip' => $seri[0][0]];

    foreach ($seri as $n) {
        if ($n[1] > $zirve[1]) {
            $zirve = $n;
        }

        $dusus = ($n[1] / $zirve[1] - 1) * 100;

        if ($dusus < $enKotu['yuzde']) {
            $enKotu = ['yuzde' => $dusus, 'zirve' => $zirve[0], 'dip' => $n[0]];
        }
    }

    return $enKotu;
}

/** Yüzdeyi işaretli ve renk sınıfıyla yazar. */
function sirket_yuzde_html(?float $deger, int $hane = 2): string
{
    if ($deger === null) {
        return '<span class="pdeg bos">–</span>';
    }

    $sinif = $deger > 0 ? 'arti' : ($deger < 0 ? 'eksi' : 'duz');

    return '<span class="pdeg ' . $sinif . '">' . ($deger > 0 ? '+' : '') . '%'
        . number_format($deger, $hane, ',', '.') . '</span>';
}

/** Büyük sayıyı kısaltır: 12.345.678 -> "12,3 milyon". */
function sirket_buyuk_sayi(?float $deger): string
{
    if ($deger === null) {
        return '–';
    }

    foreach ([1e9 => 'milyar', 1e6 => 'milyon', 1e3 => 'bin'] as $bolen => $ad) {
        if (abs($deger) >= $bolen) {
            return number_format($deger / $bolen, 1, ',', '.') . ' ' . $ad;
        }
    }

    return number_format($deger, 0, ',', '.');
}

/** "2024-03" biçiminde ay; tarih aralıkları için. */
function sirket_ay(int $zaman): string
{
    $aylar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

    return $aylar[(int) date('n', $zaman) - 1] . ' ' . date('Y', $zaman);
}
