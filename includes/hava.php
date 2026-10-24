<?php
declare(strict_types=1);

/**
 * Hava durumu: yan penceredeki kart (includes/hava_kutu.php).
 *
 * Site sahibinin istegi (24.10.2026): "bir yere de hava durumunu
 * koyalim". Kaynak Open-Meteo: ucretsiz, anahtarsiz, Turkiye icin
 * ulusal modelleri de kullaniyor. Veri sunucuda 30 dakika tutuluyor
 * (canli_onbellek): bayatsa eskisi gosterilip yanittan sonra
 * tazeleniyor, ziyaretci kaynagi beklemiyor. Kaynak dusmusse kutu
 * gizleniyor; sayfa bozulmuyor.
 *
 * Varsayilan il Istanbul; ziyaretci baska il secerse secim tarayicida
 * kaliyor ve veri api/hava.php'den geliyor.
 *
 * 24.10.2026: ust cubuktan yan pencereye tasindi (site sahibi: "sagdaki
 * bos kalan yere hava durumunu yerlestirelim"); sonraki uc gunun tahmini
 * eklendi.
 */

require_once __DIR__ . '/canli_onbellek.php';

/** Seçilebilir iller: ad, enlem, boylam. */
const HAVA_ILLERI = [
    'istanbul'  => ['İstanbul',  41.01, 28.98],
    'ankara'    => ['Ankara',    39.93, 32.86],
    'izmir'     => ['İzmir',     38.42, 27.14],
    'bursa'     => ['Bursa',     40.19, 29.06],
    'antalya'   => ['Antalya',   36.89, 30.71],
    'adana'     => ['Adana',     37.00, 35.32],
    'konya'     => ['Konya',     37.87, 32.48],
    'gaziantep' => ['Gaziantep', 37.07, 37.38],
    'diyarbakir'=> ['Diyarbakır',37.91, 40.24],
    'trabzon'   => ['Trabzon',   41.00, 39.72],
    'samsun'    => ['Samsun',    41.29, 36.33],
    'erzurum'   => ['Erzurum',   39.90, 41.27],
    'eskisehir' => ['Eskişehir', 39.78, 30.52],
    'kayseri'   => ['Kayseri',   38.73, 35.48],
    'van'       => ['Van',       38.49, 43.38],
];

const HAVA_VARSAYILAN_IL = 'istanbul';
const HAVA_ONBELLEK_SURE = 1800;

/**
 * İlin anlık hava durumu; alınamazsa null.
 *
 * @return array{il:string,ad:string,sicaklik:int,en_yuksek:?int,en_dusuk:?int,durum:string,simge:string,gunler:list<array{gun:string,durum:string,simge:string,en_yuksek:int,en_dusuk:int}>,zaman:int}|null
 */
function hava_durumu(string $il = HAVA_VARSAYILAN_IL): ?array
{
    if (!isset(HAVA_ILLERI[$il])) {
        $il = HAVA_VARSAYILAN_IL;
    }

    [$ad, $enlem, $boylam] = HAVA_ILLERI[$il];

    $kayit = canli_onbellek('hava_' . $il, HAVA_ONBELLEK_SURE, static function () use ($enlem, $boylam): ?array {
        $ham = canli_indir(
            'https://api.open-meteo.com/v1/forecast?latitude=' . $enlem . '&longitude=' . $boylam
            . '&current=temperature_2m,weather_code,is_day'
            . '&daily=weather_code,temperature_2m_max,temperature_2m_min&forecast_days=4&timezone=Europe%2FIstanbul',
            6
        );

        return $ham !== null ? hava_coz($ham) : null;
    });

    $veri = $kayit['veri'];

    if (!is_array($veri)) {
        return null;
    }

    [$durum, $simge] = hava_kodu((int) $veri['kod'], (bool) $veri['gunduz']);

    // Sonraki gunler (bugun haric); eski onbellek kaydinda yok.
    $gunAdlari = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
    $gunler    = [];

    foreach (array_slice((array) ($veri['gunler'] ?? []), 1, 3) as $g) {
        [$gDurum, $gSimge] = hava_kodu((int) $g['kod']);
        $gunler[] = [
            'gun'       => $gunAdlari[(int) date('w', (int) strtotime((string) $g['tarih']))],
            'durum'     => $gDurum,
            'simge'     => $gSimge,
            'en_yuksek' => (int) round((float) $g['en_yuksek']),
            'en_dusuk'  => (int) round((float) $g['en_dusuk']),
        ];
    }

    return [
        'il'        => $il,
        'ad'        => $ad,
        'sicaklik'  => (int) round((float) $veri['sicaklik']),
        'en_yuksek' => $veri['en_yuksek'] !== null ? (int) round((float) $veri['en_yuksek']) : null,
        'en_dusuk'  => $veri['en_dusuk'] !== null ? (int) round((float) $veri['en_dusuk']) : null,
        'durum'     => $durum,
        'simge'     => $simge,
        'gunler'    => $gunler,
        'zaman'     => (int) $kayit['zaman'],
    ];
}

/** Kartın şehir listesinde gösterilen büyük şehirler (HAVA_ILLERI anahtarları). */
const HAVA_BUYUK_SEHIRLER = ['istanbul', 'ankara', 'izmir', 'bursa', 'antalya', 'adana', 'trabzon', 'diyarbakir'];

/**
 * Büyük şehirlerin anlık ve günlük hava durumu, tek istekle.
 *
 * Site sahibinin istegi (24.10.2026): "hava durumunda buyuk sehirlerin
 * gunluk hatta anlik hava tahmin bilgileri gosterilsin". Open-Meteo
 * virgulle ayrilmis koordinatlarla tek cagrida sehir basina bir sonuc
 * donduruyor; 30 dakika onbellekte.
 *
 * @return list<array{il:string,ad:string,sicaklik:int,en_yuksek:?int,en_dusuk:?int,durum:string,simge:string}>
 */
function hava_buyuk_sehirler(): array
{
    $enlemler = [];
    $boylamlar = [];

    foreach (HAVA_BUYUK_SEHIRLER as $il) {
        $enlemler[]  = HAVA_ILLERI[$il][1];
        $boylamlar[] = HAVA_ILLERI[$il][2];
    }

    $kayit = canli_onbellek('hava_sehirler', HAVA_ONBELLEK_SURE, static function () use ($enlemler, $boylamlar): ?array {
        $ham = canli_indir(
            'https://api.open-meteo.com/v1/forecast?latitude=' . implode(',', $enlemler)
            . '&longitude=' . implode(',', $boylamlar)
            . '&current=temperature_2m,weather_code,is_day'
            . '&daily=weather_code,temperature_2m_max,temperature_2m_min&forecast_days=1&timezone=Europe%2FIstanbul',
            8
        );

        if ($ham === null) {
            return null;
        }

        $liste = json_decode($ham, true);

        if (!is_array($liste) || !array_is_list($liste) || count($liste) !== count(HAVA_BUYUK_SEHIRLER)) {
            return null;
        }

        $sonuc = [];

        foreach ($liste as $tek) {
            $cozulen = hava_coz((string) json_encode($tek));

            if ($cozulen === null) {
                return null;
            }

            unset($cozulen['gunler']);
            $sonuc[] = $cozulen;
        }

        return $sonuc;
    });

    $sehirler = [];

    foreach ((array) ($kayit['veri'] ?? []) as $i => $veri) {
        $il = HAVA_BUYUK_SEHIRLER[$i] ?? null;

        if ($il === null || !is_array($veri)) {
            continue;
        }

        [$durum, $simge] = hava_kodu((int) $veri['kod'], (bool) $veri['gunduz']);
        $sehirler[] = [
            'il'        => $il,
            'ad'        => HAVA_ILLERI[$il][0],
            'sicaklik'  => (int) round((float) $veri['sicaklik']),
            'en_yuksek' => $veri['en_yuksek'] !== null ? (int) round((float) $veri['en_yuksek']) : null,
            'en_dusuk'  => $veri['en_dusuk'] !== null ? (int) round((float) $veri['en_dusuk']) : null,
            'durum'     => $durum,
            'simge'     => $simge,
        ];
    }

    return $sehirler;
}

/**
 * Open-Meteo yanıtını sadeleştirir; beklenen alanlar yoksa null.
 *
 * @return array{sicaklik:float,kod:int,gunduz:bool,en_yuksek:?float,en_dusuk:?float,gunler:list<array{tarih:string,kod:int,en_yuksek:float,en_dusuk:float}>}|null
 */
function hava_coz(string $ham): ?array
{
    $veri  = json_decode($ham, true);
    $simdi = is_array($veri) ? ($veri['current'] ?? null) : null;

    if (!is_array($simdi) || !isset($simdi['temperature_2m'], $simdi['weather_code'])) {
        return null;
    }

    $sicaklik = (float) $simdi['temperature_2m'];

    // Turkiye icin makul aralik disi: bozuk yanit.
    if ($sicaklik < -45 || $sicaklik > 55) {
        return null;
    }

    $gun    = $veri['daily'] ?? [];
    $gunler = [];

    foreach ((array) ($gun['time'] ?? []) as $i => $tarih) {
        if (!isset($gun['weather_code'][$i], $gun['temperature_2m_max'][$i], $gun['temperature_2m_min'][$i])) {
            continue;
        }

        $gunler[] = [
            'tarih'     => (string) $tarih,
            'kod'       => (int) $gun['weather_code'][$i],
            'en_yuksek' => (float) $gun['temperature_2m_max'][$i],
            'en_dusuk'  => (float) $gun['temperature_2m_min'][$i],
        ];
    }

    return [
        'sicaklik'  => $sicaklik,
        'kod'       => (int) $simdi['weather_code'],
        'gunduz'    => (int) ($simdi['is_day'] ?? 1) === 1,
        'en_yuksek' => isset($gun['temperature_2m_max'][0]) ? (float) $gun['temperature_2m_max'][0] : null,
        'en_dusuk'  => isset($gun['temperature_2m_min'][0]) ? (float) $gun['temperature_2m_min'][0] : null,
        'gunler'    => $gunler,
    ];
}

/**
 * WMO hava kodu -> Türkçe durum ve simge adı.
 *
 * Simgeler: gunes, ay, parcali, bulut, sis, yagmur, kar, firtina.
 *
 * @return array{0:string,1:string}
 */
function hava_kodu(int $kod, bool $gunduz = true): array
{
    return match (true) {
        $kod === 0              => ['Açık', $gunduz ? 'gunes' : 'ay'],
        $kod === 1              => ['Az bulutlu', $gunduz ? 'gunes' : 'ay'],
        $kod === 2              => ['Parçalı bulutlu', 'parcali'],
        $kod === 3              => ['Kapalı', 'bulut'],
        $kod === 45, $kod === 48 => ['Sisli', 'sis'],
        $kod >= 51 && $kod <= 57 => ['Çisenti', 'yagmur'],
        $kod >= 61 && $kod <= 67 => ['Yağmurlu', 'yagmur'],
        $kod >= 71 && $kod <= 77 => ['Karlı', 'kar'],
        $kod >= 80 && $kod <= 82 => ['Sağanak', 'yagmur'],
        $kod === 85, $kod === 86 => ['Kar sağanağı', 'kar'],
        $kod >= 95              => ['Gök gürültülü', 'firtina'],
        default                 => ['', 'bulut'],
    };
}

/** Simgenin SVG'si (currentColor; 20x20). */
function hava_simge_svg(string $simge): string
{
    $govde = match ($simge) {
        'gunes'    => '<circle cx="10" cy="10" r="3.6"/><path d="M10 2.2v2M10 15.8v2M2.2 10h2M15.8 10h2M4.5 4.5l1.4 1.4M14.1 14.1l1.4 1.4M4.5 15.5l1.4-1.4M14.1 5.9l1.4-1.4"/>',
        'ay'       => '<path d="M16 12.4A6.4 6.4 0 0 1 7.6 4a6.4 6.4 0 1 0 8.4 8.4z"/>',
        'parcali'  => '<circle cx="7.5" cy="7.5" r="2.8"/><path d="M7.5 1.8v1.4M1.8 7.5h1.4M3.5 3.5l1 1M11.5 3.5l-1 1"/><path d="M7.2 16.5h7.3a3 3 0 0 0 .3-6 4 4 0 0 0-7.7 1.1 2.5 2.5 0 0 0 .1 4.9z"/>',
        'sis'      => '<path d="M3 8h14M2 11.5h16M4 15h12"/>',
        'yagmur'   => '<path d="M6 12.5h8.5a3 3 0 0 0 .3-6 4.5 4.5 0 0 0-8.7 1.3A2.4 2.4 0 0 0 6 12.5z"/><path d="M7 15l-.8 2M10.5 15l-.8 2M14 15l-.8 2"/>',
        'kar'      => '<path d="M6 12h8.5a3 3 0 0 0 .3-6 4.5 4.5 0 0 0-8.7 1.3A2.4 2.4 0 0 0 6 12z"/><path d="M7 15.2v.1M10.5 16.5v.1M14 15.2v.1"/>',
        'firtina'  => '<path d="M6 11.5h8.5a3 3 0 0 0 .3-6 4.5 4.5 0 0 0-8.7 1.3A2.4 2.4 0 0 0 6 11.5z"/><path d="M10.5 12.5l-2 3h3l-2 3"/>',
        default    => '<path d="M5.5 15h9.5a3.4 3.4 0 0 0 .3-6.8 5 5 0 0 0-9.7 1.5 2.7 2.7 0 0 0-.1 5.3z"/>',
    };

    return '<svg class="hava-simge" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor"'
        . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $govde . '</svg>';
}
