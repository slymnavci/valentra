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

use Valentra\Ajan\Site;

date_default_timezone_set('Europe/Istanbul');

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
}

/** Izleme sayfasindan yayin zamani (Unix); bulunamazsa null. */
function video_yayin_zamani(string $html): ?int
{
    foreach ([
        '#<meta[^>]+itemprop=["\'](?:datePublished|uploadDate|startDate)["\'][^>]+content=["\']([^"\']+)["\']#i',
        '#"(?:publishDate|uploadDate|startTimestamp)"\s*:\s*"([^"]+)"#',
    ] as $kalip) {
        if (preg_match($kalip, $html, $e) && ($z = strtotime($e[1])) !== false && $z > 0) {
            return $z;
        }
    }

    // Yeni sayfa bicimi: yalnizca gorunen metin ("20 Eki 2026", "Canlı
    // yayın tarihi: 20 Eki 2026"). Saat yok; gunun ogleni sayiliyor.
    $aylar = ['oca' => 1, 'şub' => 2, 'mar' => 3, 'nis' => 4, 'may' => 5, 'haz' => 6,
              'tem' => 7, 'ağu' => 8, 'eyl' => 9, 'eki' => 10, 'kas' => 11, 'ara' => 12];

    if (preg_match('#"dateText"\s*:\s*\{\s*"simpleText"\s*:\s*"[^"]*?(\d{1,2})\s+(\p{L}{3})\p{L}*\.?\s+(\d{4})#u', $html, $e)) {
        $ay = $aylar[mb_strtolower($e[2], 'UTF-8')] ?? 0;

        if ($ay > 0) {
            return mktime(12, 0, 0, $ay, (int) $e[1], (int) $e[3]) ?: null;
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

/**
 * Izleme sayfasini indirir. Ilk denemede 14 videonun 9'u okunamadi:
 * sayfa 2 MB'yi asabiliyor (genel indiricinin siniri) ve Avrupa'dan
 * gelen istege cerez onay sayfasi donebiliyor. Shorts adresi de izleme
 * adresine cevriliyor. Basarisizlikta sebep $sebep'e.
 */
function video_sayfasi(string $url, ?string &$sebep): string
{
    $sebep = '';

    if (preg_match('#(?:v=|/shorts/|youtu\.be/|/embed/)([A-Za-z0-9_-]{11})#', $url, $e)) {
        $url = 'https://www.youtube.com/watch?v=' . $e[1] . '&hl=tr&persist_hl=1';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: tr-TR,tr;q=0.9,en;q=0.8'],
        // Cerez onay sayfasi yerine videonun kendisi.
        CURLOPT_COOKIE         => 'CONSENT=YES+cb; SOCS=CAI',
    ]);
    $html = curl_exec($ch);
    $kod  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata = curl_error($ch);
    curl_close($ch);

    if (!is_string($html) || $kod < 200 || $kod >= 300) {
        $sebep = $hata !== '' ? $hata : 'HTTP ' . $kod;

        return '';
    }

    if (str_contains($html, 'consent.youtube.com') && !str_contains($html, 'datePublished')) {
        $sebep = 'çerez onay sayfası';

        return '';
    }

    return $html;
}

/** Sayfa indi ama tarih yok: video neden tarihsiz (canli, kaldirilmis...). */
function video_tarihsiz_sebebi(string $html): string
{
    if (preg_match('#"playabilityStatus"\s*:\s*\{\s*"status"\s*:\s*"([A-Z_]+)"#', $html, $e) && $e[1] !== 'OK') {
        return 'video oynatılamıyor: ' . $e[1];
    }

    if (str_contains($html, '"isLiveNow":true') || str_contains($html, '"isLive":true')) {
        return 'sürekli canlı yayın';
    }

    $ipucu = [];

    foreach (['publishDate', 'uploadDate', 'dateText', 'datePublished', 'liveBroadcastDetails', 'isLiveContent'] as $alan) {
        if (str_contains($html, '"' . $alan . '"') || str_contains($html, $alan . '"')) {
            $ipucu[] = $alan;
        }
    }

    return 'sayfada tarih yok' . ($ipucu !== [] ? ' (alanlar: ' . implode(',', $ipucu) . ')' : '');
}

$site = new Site($siteUrl, $anahtar);

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
$sebepler = [];

foreach ($eksikler as $v) {
    $html  = video_sayfasi((string) $v['url'], $sebep);
    $zaman = $html !== '' ? video_yayin_zamani($html) : null;

    if ($zaman === null) {
        $okunamayan++;
        $sebep = $sebep !== '' ? $sebep : video_tarihsiz_sebebi($html);
        $sebepler[$sebep] = ($sebepler[$sebep] ?? 0) + 1;
        gunluk('  okunamadı — ' . $v['url'] . ' (' . $sebep . ')');
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
    . ' tanesinin gerçek yayın tarihi yazıldı' . ($okunamayan > 0 ? ', ' . $okunamayan . ' okunamadı (' . implode('; ', array_map(
        static fn (string $s, int $n): string => $s . ': ' . $n,
        array_keys($sebepler),
        $sebepler
    )) . ')' : '') . '.';
gunluk($ozet);

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Video tarihleri::' . $ozet . PHP_EOL;
}
