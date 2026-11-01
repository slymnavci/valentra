<?php
declare(strict_types=1);

/**
 * V Network yapay zeka ve kurum hesaplari (31.10.2026; 01.11.2026'dan beri
 * model yok).
 *
 * Hesaplar ajan/veri/topluluk_hesaplari.json'da (100 YZ hesabi, 5 Valentra
 * kurum hesabi; bkz. includes/topluluk.php). Yapay zekanin yazdigi
 * paylasim ve yorumlar kapatildi (site sahibi: "yapay zeka yorumlarini
 * kapatalim, sadece begeni artirsin yeter"). Bu betik yalnizca:
 *
 *   - sitede eksik hesap varsa acar,
 *   - siteyi durter: YZ begenilerini site kendisi dagitiyor
 *     (topluluk_begeni_dagit); az ziyaretli saatlerde de ilerlesin diye
 *     her ajan calismasinda bir istek.
 *
 * Calistirma:
 *   php ajan/topluluk.php [--kuru]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Indirici.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Kodlama.php';
require_once __DIR__ . '/src/Site.php';

use Valentra\Ajan\Site;

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$kuru = isset(getopt('', ['kuru'])['kuru']);

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
}

function ayar(string $ad): string
{
    $deger = getenv($ad);

    if (!is_string($deger) || trim($deger) === '') {
        fwrite(STDERR, "HATA: {$ad} ortam değişkeni tanımlı değil.\n");
        exit(1);
    }

    return trim($deger);
}

$dosya   = json_decode((string) file_get_contents(__DIR__ . '/veri/topluluk_hesaplari.json'), true);
$kisiler = is_array($dosya['hesaplar'] ?? null) ? $dosya['hesaplar'] : [];
$site    = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));

try {
    $durum = $site->toplulukMalzeme();

    if (!$kuru && $kisiler !== [] && count($durum['hesaplar']) < count($kisiler)) {
        $esit = $site->toplulukGonder(['hesaplar' => array_map(
            static fn (array $k): array => ['rumuz' => $k['rumuz'], 'tur' => $k['tur'], 'hakkinda' => $k['hakkinda']],
            $kisiler
        )]);
        gunluk(sprintf('Hesaplar eşitlendi: %d yeni, %d güncel, %d hata.',
            (int) ($esit['eklenen'] ?? 0), (int) ($esit['guncellenen'] ?? 0), count((array) ($esit['hatalar'] ?? []))));
    }

    gunluk(sprintf('%d hesap; YZ hesaplarının bugünkü beğenisi: %d.', count($durum['hesaplar']), $durum['begeni_bugun']));
} catch (Throwable $e) {
    gunluk('Siteye ulaşılamadı: ' . $e->getMessage());
}
