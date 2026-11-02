<?php
declare(strict_types=1);

/**
 * Google Analytics (GA4) verisi panelde (02.11.2026, site sahibi: "bu google
 * analytic verilerini kendi sayfamda gorebilir miyim?"; bkz. admin/analytics.php).
 *
 * Search Console icin girilmis servis hesabi kullaniliyor (ayni JSON
 * anahtari, salt okunur analytics kapsami). Gerekenler:
 *   - Google Cloud projesinde "Google Analytics Data API" etkin,
 *   - servis hesabinin e-postasi GA mulkunde "Goruntuleyici",
 *   - mulk kimligi (Yonetici > Mulk bilgileri, 9-10 haneli sayi) panelde.
 *
 * Google'a her sayfa acilisinda gidilmiyor: raporlar bir saat, "su an
 * sitede" bir dakika ayarlar tablosunda saklaniyor.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/search_console.php';

const GA_KAPSAM      = 'https://www.googleapis.com/auth/analytics.readonly';
const GA_API         = 'https://analyticsdata.googleapis.com/v1beta/properties/';
const GA_RAPOR_SURE  = 3600; // saniye
const GA_ANLIK_SURE  = 60;

/** Kayitli GA4 mulk kimligi (yalnizca rakam); yoksa bos. */
function ga_mulk(): string
{
    $k = trim(ayar_oku('ga4_mulk'));

    return preg_match('/^\d{6,12}$/', $k) === 1 ? $k : '';
}

/** "properties/123", "123" ya da adresten mulk kimligini ayiklar; gecersizse bos. */
function ga_mulk_temizle(string $girdi): string
{
    $girdi = trim($girdi);

    if (preg_match('/^(?:properties\/)?(\d{6,12})$/', $girdi, $e) === 1) {
        return $e[1];
    }

    // analytics.google.com/.../p123456789/... adresi yapistirildiysa
    if (preg_match('/[\/#]p(\d{6,12})(?:\/|$)/', $girdi, $e) === 1) {
        return $e[1];
    }

    return '';
}

/** Google'in sik verdigi hatalarin Turkce karsiligi. */
function ga_hata_ipucu(int $kod, string $mesaj): string
{
    $m = strtolower($mesaj);

    return match (true) {
        str_contains($m, 'has not been used') || str_contains($m, 'is disabled') || str_contains($m, 'service_disabled')
            => 'Google Cloud projesinde "Google Analytics Data API" etkin değil. Aşağıdaki 1. adımdaki bağlantıdan etkinleştirin; açıldıktan sonra birkaç dakika sürebilir.',
        $kod === 403 || str_contains($m, 'permission')
            => 'Servis hesabı bu Analytics mülkünde yetkili değil. Analytics → Yönetici → Mülk erişim yönetimi bölümünde servis hesabının e-postasını "Görüntüleyici" olarak ekleyin.',
        $kod === 400 && str_contains($m, 'property')
            => 'Mülk kimliği hatalı görünüyor. Analytics → Yönetici → Mülk bilgileri sayfasındaki "Mülk kimliği" sayısını girin (G- ile başlayan ölçüm kimliği değil).',
        $kod === 404
            => 'Bu kimlikte bir Analytics mülkü bulunamadı. Mülk kimliğini kontrol edin.',
        $kod === 429
            => 'Google kotası doldu; bir süre sonra kendiliğinden yeniden denenecek.',
        default => '',
    };
}

/**
 * GA Data API istegi.
 *
 * @param array<string,mixed> $govde
 * @return array{tamam:bool,veri:array<mixed>,hata:string}
 */
function ga_istek(string $islem, array $govde): array
{
    $mulk = ga_mulk();

    if ($mulk === '') {
        return ['tamam' => false, 'veri' => [], 'hata' => 'Mülk kimliği girilmemiş.'];
    }

    $jeton = gsc_jeton(GA_KAPSAM);

    if (!$jeton['tamam']) {
        return ['tamam' => false, 'veri' => [], 'hata' => $jeton['hata']];
    }

    $yanit = gsc_istek('POST', GA_API . $mulk . ':' . $islem, $govde, $jeton['jeton']);

    if (!$yanit['tamam']) {
        $mesaj = (string) ($yanit['veri']['error']['message'] ?? '');
        $ipucu = ga_hata_ipucu($yanit['kod'], $mesaj);
        $hata  = $yanit['kod'] > 0
            ? 'Google HTTP ' . $yanit['kod'] . ($mesaj !== '' ? ': ' . $mesaj : '')
            : $yanit['hata'];

        return ['tamam' => false, 'veri' => $yanit['veri'], 'hata' => $hata . ($ipucu !== '' ? ' — ' . $ipucu : '')];
    }

    return ['tamam' => true, 'veri' => $yanit['veri'], 'hata' => ''];
}

/**
 * Rapor yanitini sadelestirir.
 *
 * @param array<mixed> $rapor
 * @return list<array{b:list<string>,m:list<float>}>
 */
function ga_satirlar(array $rapor): array
{
    $satirlar = [];

    foreach ((array) ($rapor['rows'] ?? []) as $r) {
        $satirlar[] = [
            'b' => array_map(static fn ($d): string => (string) ($d['value'] ?? ''), (array) ($r['dimensionValues'] ?? [])),
            'm' => array_map(static fn ($d): float => (float) ($d['value'] ?? 0), (array) ($r['metricValues'] ?? [])),
        ];
    }

    return $satirlar;
}

/**
 * Raporlar (bir saat saklaniyor).
 *
 * @return array{zaman:int,hata:string,ozet:array<string,array<string,float>>,gunluk:list<array{tarih:string,kullanici:int,goruntuleme:int}>,sayfalar:list<array{yol:string,baslik:string,goruntuleme:int,kullanici:int}>,kanallar:list<array{ad:string,oturum:int,kullanici:int}>,kaynaklar:list<array{ad:string,oturum:int}>,cihazlar:list<array{ad:string,kullanici:int}>,sehirler:list<array{ad:string,kullanici:int}>}
 */
function ga_raporlar(bool $zorla = false): array
{
    $bos = ['zaman' => 0, 'hata' => '', 'ozet' => [], 'gunluk' => [], 'sayfalar' => [], 'kanallar' => [], 'kaynaklar' => [], 'cihazlar' => [], 'sehirler' => []];
    $onbellek = json_decode(ayar_oku('ga_rapor_onbellek'), true);

    if (!$zorla && is_array($onbellek) && (int) ($onbellek['zaman'] ?? 0) > time() - GA_RAPOR_SURE && ($onbellek['mulk'] ?? '') === ga_mulk()) {
        return $onbellek + $bos;
    }

    $son28 = ['startDate' => '27daysAgo', 'endDate' => 'today'];
    $ozetMetrik = [['name' => 'activeUsers'], ['name' => 'newUsers'], ['name' => 'screenPageViews'], ['name' => 'sessions'], ['name' => 'averageSessionDuration']];

    $birinci = ga_istek('batchRunReports', ['requests' => [
        [
            'dateRanges' => [
                ['startDate' => '6daysAgo', 'endDate' => 'today', 'name' => 'g7'],
                ['startDate' => '13daysAgo', 'endDate' => '7daysAgo', 'name' => 'o7'],
                ['startDate' => '27daysAgo', 'endDate' => 'today', 'name' => 'g28'],
                ['startDate' => '55daysAgo', 'endDate' => '28daysAgo', 'name' => 'o28'],
            ],
            'metrics' => $ozetMetrik,
        ],
        [
            'dateRanges' => [['startDate' => '89daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'date']],
            'metrics'    => [['name' => 'activeUsers'], ['name' => 'screenPageViews']],
            'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
            'limit'      => 100,
        ],
        [
            'dateRanges' => [$son28],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics'    => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit'      => 25,
        ],
        [
            'dateRanges' => [$son28],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => 12,
        ],
        [
            'dateRanges' => [$son28],
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics'    => [['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
        ],
    ]]);

    if (!$birinci['tamam']) {
        $sonuc = ['zaman' => time(), 'hata' => $birinci['hata'], 'mulk' => ga_mulk()] + $bos;
        // Hata da kisa sure saklaniyor: her sayfa acilisi Google'i yeniden yormasin.
        ayar_yaz('ga_rapor_onbellek', (string) json_encode(['zaman' => time() - GA_RAPOR_SURE + 120] + $sonuc, JSON_UNESCAPED_UNICODE));

        return $sonuc;
    }

    $ikinci = ga_istek('batchRunReports', ['requests' => [
        [
            'dateRanges' => [$son28],
            'dimensions' => [['name' => 'sessionSource']],
            'metrics'    => [['name' => 'sessions']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => 12,
        ],
        [
            'dateRanges' => [$son28],
            'dimensions' => [['name' => 'city']],
            'metrics'    => [['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
            'limit'      => 12,
        ],
    ]]);

    $r = (array) ($birinci['veri']['reports'] ?? []);
    $r2 = $ikinci['tamam'] ? (array) ($ikinci['veri']['reports'] ?? []) : [];

    $ozet = [];
    $anahtarlar = ['kullanici', 'yeni', 'goruntuleme', 'oturum', 'sure'];

    foreach (ga_satirlar((array) ($r[0] ?? [])) as $s) {
        $ozet[$s['b'][0] ?? ''] = array_combine($anahtarlar, array_pad($s['m'], 5, 0.0));
    }

    foreach (['g7', 'o7', 'g28', 'o28'] as $ad) {
        $ozet[$ad] ??= array_fill_keys($anahtarlar, 0.0);
    }

    $gunluk = [];

    foreach (ga_satirlar((array) ($r[1] ?? [])) as $s) {
        $t = $s['b'][0] ?? '';
        $gunluk[] = [
            'tarih'       => strlen($t) === 8 ? substr($t, 0, 4) . '-' . substr($t, 4, 2) . '-' . substr($t, 6, 2) : $t,
            'kullanici'   => (int) ($s['m'][0] ?? 0),
            'goruntuleme' => (int) ($s['m'][1] ?? 0),
        ];
    }

    $sonuc = [
        'zaman'    => time(),
        'hata'     => $ikinci['tamam'] ? '' : $ikinci['hata'],
        'mulk'     => ga_mulk(),
        'ozet'     => $ozet,
        'gunluk'   => $gunluk,
        'sayfalar' => array_map(static fn (array $s): array => ['yol' => $s['b'][0] ?? '', 'baslik' => $s['b'][1] ?? '', 'goruntuleme' => (int) $s['m'][0], 'kullanici' => (int) ($s['m'][1] ?? 0)], ga_satirlar((array) ($r[2] ?? []))),
        'kanallar' => array_map(static fn (array $s): array => ['ad' => $s['b'][0] ?? '', 'oturum' => (int) $s['m'][0], 'kullanici' => (int) ($s['m'][1] ?? 0)], ga_satirlar((array) ($r[3] ?? []))),
        'cihazlar' => array_map(static fn (array $s): array => ['ad' => $s['b'][0] ?? '', 'kullanici' => (int) $s['m'][0]], ga_satirlar((array) ($r[4] ?? []))),
        'kaynaklar' => array_map(static fn (array $s): array => ['ad' => $s['b'][0] ?? '', 'oturum' => (int) $s['m'][0]], ga_satirlar((array) ($r2[0] ?? []))),
        'sehirler' => array_map(static fn (array $s): array => ['ad' => $s['b'][0] ?? '', 'kullanici' => (int) $s['m'][0]], ga_satirlar((array) ($r2[1] ?? []))),
    ];

    ayar_yaz('ga_rapor_onbellek', (string) json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $sonuc;
}

/**
 * Su an sitede (son 30 dakika; bir dakika saklaniyor).
 *
 * @return array{zaman:int,hata:string,kullanici:int,sayfalar:list<array{ad:string,kullanici:int}>,cihazlar:list<array{ad:string,kullanici:int}>}
 */
function ga_anlik(bool $zorla = false): array
{
    $onbellek = json_decode(ayar_oku('ga_anlik_onbellek'), true);

    if (!$zorla && is_array($onbellek) && (int) ($onbellek['zaman'] ?? 0) > time() - GA_ANLIK_SURE && ($onbellek['mulk'] ?? '') === ga_mulk()) {
        return $onbellek;
    }

    $toplam = ga_istek('runRealtimeReport', ['metrics' => [['name' => 'activeUsers']]]);
    $sonuc  = ['zaman' => time(), 'mulk' => ga_mulk(), 'hata' => $toplam['hata'], 'kullanici' => 0, 'sayfalar' => [], 'cihazlar' => []];

    if ($toplam['tamam']) {
        $sonuc['kullanici'] = (int) (ga_satirlar($toplam['veri'])[0]['m'][0] ?? 0);

        $sayfalar = ga_istek('runRealtimeReport', [
            'dimensions' => [['name' => 'unifiedScreenName']],
            'metrics'    => [['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
            'limit'      => 10,
        ]);
        $cihazlar = ga_istek('runRealtimeReport', [
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics'    => [['name' => 'activeUsers']],
        ]);

        $sonuc['sayfalar'] = array_map(static fn (array $s): array => ['ad' => $s['b'][0] ?? '', 'kullanici' => (int) $s['m'][0]], ga_satirlar($sayfalar['veri']));
        $sonuc['cihazlar'] = array_map(static fn (array $s): array => ['ad' => $s['b'][0] ?? '', 'kullanici' => (int) $s['m'][0]], ga_satirlar($cihazlar['veri']));
    }

    ayar_yaz('ga_anlik_onbellek', (string) json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $sonuc;
}

/** GA'nin Ingilizce kanal ve cihaz adlarinin Turkcesi. */
function ga_turkce(string $ad): string
{
    return [
        'Organic Search'  => 'Google ve arama motorları',
        'Direct'          => 'Doğrudan (adres yazarak, yer imi)',
        'Referral'        => 'Başka siteler',
        'Organic Social'  => 'Sosyal medya',
        'Paid Search'     => 'Ücretli arama',
        'Paid Social'     => 'Ücretli sosyal medya',
        'Email'           => 'E-posta',
        'Display'         => 'Görüntülü reklam',
        'Organic Video'   => 'Video siteleri',
        'Unassigned'      => 'Belirlenemedi',
        'Cross-network'   => 'Çok kanallı reklam',
        'Organic Shopping' => 'Alışveriş',
        'mobile'          => 'Mobil',
        'desktop'         => 'Masaüstü',
        'tablet'          => 'Tablet',
        'smart tv'        => 'Akıllı TV',
        '(not set)'       => 'Belirlenemedi',
        '(direct)'        => 'Doğrudan',
    ][$ad] ?? $ad;
}
