<?php
declare(strict_types=1);

/**
 * Yayin tarihi bilinmeyen videolu haberlerin gercek tarihini YouTube'dan
 * okuyup siteye yazar (api/video-tarih.php).
 *
 * Site sahibinin istegi (23.10.2026): "sali videosunu arsive tasi, ana
 * sayfadan kaldir". Tarih videonun izleme sayfasindan: datePublished /
 * uploadDate bildirimi ya da "publishDate". Okunamayan video tarihsiz
 * kalir (son dakika olamaz, onaysiz yayina girmez).
 *
 *   php ajan/video_tarih.php
 *
 * Ortam: VALENTRA_SITE_URL, VALENTRA_AGENT_KEY.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Indirici.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Site.php';

use Valentra\Ajan\{Http, Site};

date_default_timezone_set('Europe/Istanbul');

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
}

/** Izleme sayfasindan yayin zamani (Unix); bulunamazsa null. */
function video_yayin_zamani(string $html): ?int
{
    foreach ([
        '#<meta[^>]+itemprop=["\'](?:datePublished|uploadDate)["\'][^>]+content=["\']([^"\']+)["\']#i',
        '#"(?:publishDate|uploadDate)"\s*:\s*"([^"]+)"#',
    ] as $kalip) {
        if (preg_match($kalip, $html, $e) && ($z = strtotime($e[1])) !== false && $z > 0) {
            return $z;
        }
    }

    return null;
}

$siteUrl = trim((string) getenv('VALENTRA_SITE_URL'));
$anahtar = trim((string) getenv('VALENTRA_AGENT_KEY'));

if ($siteUrl === '' || $anahtar === '') {
    fwrite(STDERR, "HATA: VALENTRA_SITE_URL ve VALENTRA_AGENT_KEY gerekli.\n");
    exit(1);
}

$site = new Site($siteUrl, $anahtar);
$http = new Http();

try {
    $eksikler = $site->videoTarihEksikleri();
} catch (Throwable $e) {
    gunluk('Video listesi alınamadı: ' . $e->getMessage());
    exit(0);
}

if ($eksikler === []) {
    gunluk('Tarihi bilinmeyen videolu haber yok.');
    exit(0);
}

$tarihler = [];
$okunamayan = 0;

foreach ($eksikler as $v) {
    $html  = $http->indir((string) $v['url']) ?? '';
    $zaman = $html !== '' ? video_yayin_zamani($html) : null;

    if ($zaman === null) {
        $okunamayan++;
        gunluk('  okunamadı — ' . $v['url']);
        continue;
    }

    $tarihler[] = ['id' => (int) $v['id'], 'zaman' => $zaman];
    gunluk('  #' . $v['id'] . ' yayın: ' . date('d.m.Y H:i', $zaman) . ' — ' . $v['url']);
}

$guncellenen = 0;

if ($tarihler !== []) {
    try {
        $guncellenen = $site->videoTarihGonder($tarihler);
    } catch (Throwable $e) {
        gunluk('Tarihler gönderilemedi: ' . $e->getMessage());
    }
}

$ozet = 'Videolu haber tarihleri: ' . count($eksikler) . ' tarihsiz haberden ' . $guncellenen
    . ' tanesinin gerçek yayın tarihi yazıldı' . ($okunamayan > 0 ? ', ' . $okunamayan . ' okunamadı' : '') . '.';
gunluk($ozet);

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Video tarihleri::' . $ozet . PHP_EOL;
}
