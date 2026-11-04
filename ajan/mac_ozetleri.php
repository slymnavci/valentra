<?php
declare(strict_types=1);

/**
 * Mac ozeti videolarini YouTube kanallarindan toplar (04.11.2026; bkz.
 * includes/mac_ozeti.php).
 *
 * Kanal listesi siteden (panelde duzenleniyor). Her kanalin beslemesinden
 * son iki gunun videolari okunuyor; basliginda "ozet / mac ozeti / genis
 * ozet / highlights" gecenler siteye gidiyor. Model kullanilmiyor: video
 * YouTube'dan oynuyor, baslik yayincinin basligi.
 *
 *   php ajan/mac_ozetleri.php [--kuru] [--saat=48]
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
require_once __DIR__ . '/src/Kodlama.php';
require_once __DIR__ . '/src/Besleme.php';
require_once __DIR__ . '/src/Site.php';

use Valentra\Ajan\{Besleme, Http, Site};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
}

$siteUrl = trim((string) getenv('VALENTRA_SITE_URL'));
$anahtar = trim((string) getenv('VALENTRA_AGENT_KEY'));

if ($siteUrl === '' || $anahtar === '') {
    fwrite(STDERR, "HATA: VALENTRA_SITE_URL ve VALENTRA_AGENT_KEY gerekli.\n");
    exit(1);
}

$secenekler = getopt('', ['kuru', 'saat::']);
$kuru = isset($secenekler['kuru']);
$saat = max(6, min(168, (int) ($secenekler['saat'] ?? 48)));

$site = new Site($siteUrl, $anahtar);

try {
    $durum = $site->macOzetiDurum();
} catch (Throwable $e) {
    gunluk('Site ayarları alınamadı: ' . $e->getMessage());
    exit(0);
}

$bilinen  = array_flip($durum['bilinen']);
$besleme  = new Besleme(new Http(15));
$ozetler  = [];
$okunamayan = [];

foreach ($durum['kanallar'] as $kanal) {
    $girdiler = $besleme->oku((string) $kanal, $saat);

    if ($girdiler === []) {
        $okunamayan[] = $kanal;
        continue;
    }

    $bu = 0;

    foreach ($girdiler as $g) {
        if (!preg_match('~(?:v=|youtu\.be/|/shorts/|/embed/)([\w-]{11})~', (string) $g['baglanti'], $e) || isset($bilinen[$e[1]])) {
            continue;
        }

        // Ilk eleme burada (site de yeniden eliyor): basliginda ozet gecmeyen video gitmesin.
        if (!preg_match('/(?:özet|ozet|highlights|resumen)/iu', (string) $g['baslik']) || preg_match('/#shorts/iu', (string) $g['baslik'])) {
            continue;
        }

        $ozetler[] = [
            'video_id' => $e[1],
            'baslik'   => (string) $g['baslik'],
            'aciklama' => mb_substr((string) $g['ozet'], 0, 1000),
            'kanal'    => (string) ($g['yazar'] ?? ''),
            'yayin'    => $g['tarih'] !== null ? (int) strtotime((string) $g['tarih']) : 0,
        ];
        $bilinen[$e[1]] = true;
        $bu++;
        gunluk('  ▶ ' . $g['baslik'] . ' (' . ($g['yazar'] ?? '') . ')');
    }

    gunluk($kanal . ': ' . count($girdiler) . ' video, ' . $bu . ' özet');
}

$eklenen = 0;

if (!$kuru && $ozetler !== []) {
    try {
        $eklenen = $site->macOzetiGonder($ozetler);
    } catch (Throwable $e) {
        gunluk('Özetler gönderilemedi: ' . $e->getMessage());
    }
}

$ozet = 'Maç özetleri: ' . count($ozetler) . ' yeni özet bulundu'
    . ($kuru ? ' (kuru çalışma)' : ', ' . $eklenen . ' tanesi siteye eklendi')
    . ($okunamayan !== [] ? '; okunamayan kanal: ' . implode(', ', $okunamayan) : '') . '.';
gunluk($ozet);

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Maç özetleri::' . $ozet . PHP_EOL;
}
