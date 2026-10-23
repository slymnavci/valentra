<?php
declare(strict_types=1);

/**
 * Köşe yazarlarının portrelerini üretip siteye yükler.
 *
 * Site sahibinin karari (23.10.2026): yazar portreleri gercekci fotograf;
 * yazar sayfasinda tek satir not (yazar.php). Her yazar icin BIR KEZ:
 * site fotografi olmayan yazarlari veriyor (api/yazar-portre.php), ajan
 * Gemini'nin goruntu modeliyle uretip yukluyor. Fotografi olan yazar
 * atlaniyor; yeniden uretmek icin sunucudaki gorseller/yazar/<anahtar>.jpg
 * silinir.
 *
 *   php ajan/portre.php
 *
 * Ortam: GEMINI_API_KEY, GEMINI_GORSEL_MODEL (istege bagli),
 * VALENTRA_SITE_URL, VALENTRA_AGENT_KEY.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Indirici.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/src/GorselUretici.php';
require_once __DIR__ . '/src/Bildirim.php';

use Valentra\Ajan\{Bildirim, GorselUretici, Site};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

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

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));

try {
    $eksikler = $site->portreEksikleri();
} catch (Throwable $e) {
    gunluk('Yazar listesi alınamadı: ' . $e->getMessage());
    exit(0);
}

if ($eksikler === []) {
    gunluk('Bütün yazarların fotoğrafı var.');
    exit(0);
}

$uretici = new GorselUretici(ayar('GEMINI_API_KEY'), (string) getenv('GEMINI_GORSEL_MODEL'));
gunluk(count($eksikler) . ' yazarın fotoğrafı üretilecek; model: ' . $uretici->model());

$yuklenen = 0;

foreach ($eksikler as $yazar) {
    /*
     * Hayali bir kisi: gercek ya da taninmis hicbir kisiye benzememeli.
     * Gazete koseleri icin olagan, sade bir vesikalik.
     */
    $istem = 'Photorealistic professional headshot portrait for a newspaper column byline: '
        . $yazar['gorunum'] . '. Head and shoulders, facing the camera, friendly confident expression, '
        . 'soft natural studio lighting, plain light grey background, sharp focus, 85mm lens look. '
        . 'This is a fictional person: the face must be original and must not resemble any real, famous or '
        . 'public person. No text, no logo, no watermark, no frame.';

    try {
        $ikili = $uretici->uret($istem);
        $site->portreGonder((string) $yazar['anahtar'], $ikili);
        $yuklenen++;
        gunluk('  yüklendi: ' . $yazar['ad']);
    } catch (Throwable $e) {
        gunluk('  ' . $yazar['ad'] . ': ' . $e->getMessage());
        Bildirim::uyari('Yazar fotoğrafı üretilemedi (' . $yazar['ad'] . '): ' . $e->getMessage());

        // Model ya da anahtar sorunu butun yazarlarda ayni: bosuna deneme.
        if (str_contains($e->getMessage(), 'HTTP 4')) {
            break;
        }
    }
}

gunluk("{$yuklenen} yazarın fotoğrafı yüklendi.");

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Valentra ajanı::Yazar fotoğrafları: ' . $yuklenen . ' / ' . count($eksikler) . ' yüklendi.' . PHP_EOL;
}
