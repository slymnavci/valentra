<?php
declare(strict_types=1);

/**
 * Hız ve kayma ölçümünü siteye yazar (.github/workflows/google-denetim.yml).
 *
 * Site sahibinin istegi (08.11.2026): "mobil hizi ve kaymalari PageSpeed
 * Insights ve gercek kullanici verileriyle olcmek".
 *
 *   - Laboratuvar: is akisinin onceki adiminda makinenin Chrome'uyla
 *     calisan Lighthouse (lh-mobil.json, lh-masaustu.json). Yoksa PSI'nin
 *     kendi Lighthouse sonucu.
 *   - Gercek kullanici (CrUX): PageSpeed Insights API'si (son 28 gun,
 *     75. yuzdelik). Once ana sayfanin, yoksa butun sitenin verisi.
 *     PSI'nin anahtarsiz ortak kotasi sik doluyor; PAGESPEED_API_KEY
 *     (istege bagli GitHub secret) varsa onunla.
 *
 * Sonuc api/hiz-olcum.php'ye gidiyor; Google tam denetiminde gorunuyor.
 */

$site    = rtrim((string) getenv('VALENTRA_SITE_URL'), '/');
$anahtar = (string) getenv('VALENTRA_AGENT_KEY');
$psiKey  = trim((string) getenv('PAGESPEED_API_KEY'));

if ($site === '' || $anahtar === '') {
    fwrite(STDERR, "VALENTRA_SITE_URL / VALENTRA_AGENT_KEY eksik.\n");
    exit(1);
}

function hiz_istek(string $url, string $yontem = 'GET', array $basliklar = [], ?string $govde = null, int $zamanAsimi = 120): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $yontem,
        CURLOPT_HTTPHEADER     => $basliklar,
        CURLOPT_TIMEOUT        => $zamanAsimi,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($govde !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
    }

    $yanit = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$kod, is_string($yanit) ? $yanit : ''];
}

/** Lighthouse sonucundan laboratuvar ölçütleri. */
function hiz_lab(?array $lh): ?array
{
    if (!is_array($lh) || !isset($lh['audits'])) {
        return null;
    }

    $deger = static fn (string $id): ?float => isset($lh['audits'][$id]['numericValue'])
        ? (float) $lh['audits'][$id]['numericValue'] : null;
    $puan  = $lh['categories']['performance']['score'] ?? null;

    return [
        'puan' => $puan !== null ? round((float) $puan * 100) : null,
        'lcp'  => $deger('largest-contentful-paint'),
        'cls'  => $deger('cumulative-layout-shift'),
        'tbt'  => $deger('total-blocking-time'),
        'fcp'  => $deger('first-contentful-paint'),
        'si'   => $deger('speed-index'),
    ];
}

/** PSI loadingExperience -> ölçütler (CLS yüzle çarpılmış geliyor). */
function hiz_alan(array $deneyim): array
{
    $m = (array) ($deneyim['metrics'] ?? []);
    $p = static fn (string $k): ?float => isset($m[$k]['percentile']) ? (float) $m[$k]['percentile'] : null;
    $cls = $p('CUMULATIVE_LAYOUT_SHIFT_SCORE');

    return [
        'lcp'  => $p('LARGEST_CONTENTFUL_PAINT_MS'),
        'cls'  => $cls !== null ? $cls / 100 : null,
        'inp'  => $p('INTERACTION_TO_NEXT_PAINT'),
        'fcp'  => $p('FIRST_CONTENTFUL_PAINT_MS'),
        'ttfb' => $p('EXPERIMENTAL_TIME_TO_FIRST_BYTE'),
    ];
}

$dosya = static function (string $ad): ?array {
    $yol = getcwd() . '/' . $ad;

    return is_file($yol) ? (json_decode((string) file_get_contents($yol), true) ?: null) : null;
};

$olcum = [
    'adres'    => $site . '/',
    'mobil'    => hiz_lab($dosya('lh-mobil.json')),
    'masaustu' => hiz_lab($dosya('lh-masaustu.json')),
    'alan'     => null,
    'alan_not' => '',
];

// PageSpeed Insights (mobil): gercek kullanici verisi, gerekirse laboratuvar.
$psi = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . rawurlencode($site . '/')
     . '&strategy=mobile&category=performance' . ($psiKey !== '' ? '&key=' . rawurlencode($psiKey) : '');
$v   = null;

for ($deneme = 1; $deneme <= 2; $deneme++) {
    [$k, $g] = hiz_istek($psi, 'GET', [], null, 90);
    $v = json_decode($g, true);

    if ($k === 200 && is_array($v)) {
        break;
    }

    echo "PSI deneme {$deneme}: HTTP {$k} " . mb_substr((string) ($v['error']['message'] ?? ''), 0, 160) . "\n";
    $v = null;
    sleep(20 * $deneme);
}

if ($v === null) {
    $olcum['alan_not'] = 'PageSpeed Insights yanıt vermedi (anahtarsız ortak kota dolu olabilir; PAGESPEED_API_KEY ekleyin).';
} else {
    $sayfa = (array) ($v['loadingExperience'] ?? []);
    $kok   = (array) ($v['originLoadingExperience'] ?? []);

    if (!empty($sayfa['metrics']) && empty($sayfa['origin_fallback'])) {
        $olcum['alan'] = ['kapsam' => 'sayfa'] + hiz_alan($sayfa);
    } elseif (!empty($kok['metrics'])) {
        $olcum['alan'] = ['kapsam' => 'site'] + hiz_alan($kok);
    } else {
        $olcum['alan_not'] = 'Chrome kullanıcı raporunda (CrUX) bu site için henüz yeterli trafik verisi yok.';
    }

    if ($olcum['mobil'] === null) {
        $olcum['mobil'] = hiz_lab($v['lighthouseResult'] ?? null);
    }
}

if ($olcum['mobil'] === null) {
    echo "::warning::Mobil ölçüm yapılamadı (Lighthouse ve PSI yok).\n";
    exit(0);
}

echo 'Ölçüm: ' . json_encode($olcum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

[$k, $g] = hiz_istek($site . '/api/hiz-olcum.php', 'POST', [
    'X-Valentra-Key: ' . $anahtar,
    'Authorization: Bearer ' . $anahtar,
    'Content-Type: application/json',
], (string) json_encode($olcum), 30);

echo "Siteye yazıldı: HTTP {$k} " . mb_substr($g, 0, 200) . "\n";
exit($k === 200 ? 0 : 1);
