<?php
declare(strict_types=1);

/**
 * Google tam denetimini siteye calistirtir ve sonucu kayda yazar
 * (.github/workflows/google-denetim.yml).
 *
 * Search Console anahtari yalnizca sitede; denetimi site yapiyor
 * (api/google-denetim.php), bu betik sonucu okunur bicimde basiyor.
 * Hiz olcumu (Lighthouse + PageSpeed Insights) onceki adimda siteye
 * yaziliyor (ajan/hiz_olcum.php) ve denetimin bir bolumu olarak geliyor.
 *
 * Cikis kodu: hata varsa 1 (calisma kirmizi biter).
 */

$site    = rtrim((string) getenv('VALENTRA_SITE_URL'), '/');
$anahtar = (string) getenv('VALENTRA_AGENT_KEY');

if ($site === '' || $anahtar === '') {
    fwrite(STDERR, "VALENTRA_SITE_URL / VALENTRA_AGENT_KEY eksik.\n");
    exit(1);
}

function istek(string $url, string $yontem = 'GET', array $basliklar = [], int $zamanAsimi = 170): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $yontem,
        CURLOPT_HTTPHEADER     => $basliklar,
        CURLOPT_TIMEOUT        => $zamanAsimi,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata  = curl_error($ch);
    curl_close($ch);

    return [$kod, is_string($govde) ? $govde : '', $hata];
}

[$kod, $govde, $hata] = istek($site . '/api/google-denetim.php', 'POST', [
    'X-Valentra-Key: ' . $anahtar,
    'Authorization: Bearer ' . $anahtar,
    'Content-Length: 0',
]);

$sonuc = json_decode($govde, true);

if ($kod !== 200 || !is_array($sonuc) || !isset($sonuc['maddeler'])) {
    echo "::error::Denetim ucu yanit vermedi: HTTP {$kod} {$hata}\n" . mb_substr($govde, 0, 2000) . "\n";
    exit(1);
}

$isaret = ['tamam' => '[ OK ]', 'uyari' => '[UYARI]', 'hata' => '[HATA]', 'bilgi' => '[bilgi]'];
$grup   = '';

echo "Google tam denetimi — " . $sonuc['zaman'] . "\n";

// Ayni bolumun maddeleri bir arada (ilk gorulme sirasiyla).
$gruplar = [];

foreach ($sonuc['maddeler'] as $m) {
    $gruplar[$m['grup']][] = $m;
}

foreach (array_merge(...array_values($gruplar)) as $m) {
    if ($m['grup'] !== $grup) {
        $grup = $m['grup'];
        echo "\n== {$grup} ==\n";
    }

    echo str_pad($isaret[$m['durum']] ?? $m['durum'], 8) . ' ' . $m['ad'] . ': ' . $m['ayrinti'] . "\n";

    if ($m['oneri'] !== '') {
        echo "         -> " . $m['oneri'] . "\n";
    }

    if ($m['durum'] === 'hata') {
        echo '::error::' . $m['grup'] . ' / ' . $m['ad'] . ': ' . $m['ayrinti'] . "\n";
    } elseif ($m['durum'] === 'uyari') {
        echo '::warning::' . $m['grup'] . ' / ' . $m['ad'] . ': ' . $m['ayrinti'] . "\n";
    }
}

$o = $sonuc['ozet'];
echo "\nOzet: {$o['tamam']} tamam, {$o['uyari']} uyari, {$o['hata']} hata, {$o['bilgi']} bilgi\n";

/*
 * PageSpeed Insights ve Lighthouse olcumu ayri adimda (ajan/hiz_olcum.php):
 * sonuc siteye yaziliyor ve yukaridaki "Hiz (Core Web Vitals)" bolumunde.
 */

exit($o['hata'] > 0 ? 1 : 0);
