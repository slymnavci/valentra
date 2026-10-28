<?php
declare(strict_types=1);

/**
 * "Valentra Dosya" açıklayıcı yazı ajanı.
 *
 * Site sahibinin istegi (28.10.2026, ozgun haber onerilerinin hepsi):
 * Search Console'daki firsat sorgularina ("cok gosterim, az tiklama")
 * sitenin kendi haberlerinden "nedir, nasil" cevabi. Gunluk tavan sitede
 * (1); doluysa model cagrilmiyor. Yazilar sitede TASLAK, panelde onay
 * bekliyor.
 *
 * Calistirma:
 *   php ajan/aciklayici.php [--kuru] [--sure=6]
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
require_once __DIR__ . '/src/KotaBittiException.php';
require_once __DIR__ . '/src/ModelErisimYokException.php';
require_once __DIR__ . '/src/SemaliIstemci.php';
require_once __DIR__ . '/src/Yazar.php';
require_once __DIR__ . '/src/KoseYazari.php';
require_once __DIR__ . '/src/Aciklayici.php';
require_once __DIR__ . '/src/Bildirim.php';

use Valentra\Ajan\{Aciklayici, Bildirim, KotaBittiException, ModelErisimYokException, Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['kuru', 'sure::']);
$kuru       = isset($secenekler['kuru']);
$bitis      = time() + max(3, (int) ($secenekler['sure'] ?? 6)) * 60;

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
    $malzeme = $site->dosyaMalzeme();
} catch (Throwable $e) {
    gunluk('Malzeme alınamadı: ' . $e->getMessage());
    Bildirim::uyari('Açıklayıcı yazı ajanı siteye ulaşamadı; bu çalışmada yazı yazılmadı. Teknik ayrıntı: ' . $e->getMessage());
    exit(0);
}

$kalan = (int) ($malzeme['tavan']['aciklayici'] ?? 0) - (int) ($malzeme['sayilar']['aciklayici'] ?? 0);

if ($kalan <= 0) {
    gunluk('Bugünün açıklayıcı yazısı var.');
    exit(0);
}

if ($malzeme['firsatlar'] === []) {
    gunluk('Sitede haberi olan fırsat sorgusu yok (Search Console verisi ya da ilgili haber yok).');
    exit(0);
}

$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::koseModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->geminiOnce();
$yazar->sureSiniri(180, [10, 30], $bitis);
$yazar->yedekModel(Yazar::haberModeli());
$aciklayici = new Aciklayici($yazar);
$yazilar   = [];

// --- Aciklayici: firsat sorgusuna sitenin haberlerinden cevap ----------------
if (time() < $bitis - 120) {
    $oncekiBasliklar = mb_strtolower(implode(' | ', array_column($malzeme['onceki'], 'baslik')), 'UTF-8');

    foreach ($malzeme['firsatlar'] as $firsat) {
        // Ayni sorgu son iki haftada yazildiysa atla.
        if (str_contains($oncekiBasliklar, mb_strtolower((string) $firsat['sorgu'], 'UTF-8'))) {
            continue;
        }

        try {
            gunluk('Açıklayıcı: "' . $firsat['sorgu'] . '" (' . (int) $firsat['gosterim'] . ' gösterim, ' . count($firsat['haberler']) . ' ilgili haber)');
            $yazi = $aciklayici->aciklayiciYaz($firsat);
        } catch (ModelErisimYokException | KotaBittiException $e) {
            gunluk('Model kullanılamıyor: ' . $e->getMessage());
            break;
        } catch (Throwable $e) {
            gunluk('  yazılamadı: ' . $e->getMessage());
            continue;
        }

        if ($yazi === null) {
            gunluk('  malzeme yetmedi, atlandı.');

            if (time() >= $bitis - 120) {
                break;
            }

            continue;
        }

        $yazilar[] = $yazi + [
            'tur'          => 'aciklayici',
            'kategori'     => 'gundem',
            'onem'         => 60,
            'haber_idleri' => array_map(static fn (array $h): int => (int) ($h['id'] ?? 0), $firsat['haberler']),
        ];
        gunluk('  yazıldı: ' . $yazi['baslik']);
        break;
    }
}

if ($yazilar === []) {
    gunluk('Gönderilecek açıklayıcı yazı yok.');
    exit(0);
}

if ($kuru) {
    foreach ($yazilar as $y) {
        echo "\n==== [" . $y['tur'] . '] ' . $y['baslik'] . " ====\n" . $y['ozet'] . "\n\n" . $y['icerik'] . "\n\n[Editör notu] " . $y['editor_notu'] . "\n";
    }

    exit(0);
}

try {
    $sonuc = $site->dosyaGonder($yazilar);
    gunluk(sprintf('%d açıklayıcı yazı onaya gönderildi (%d yinelenen, %d tavan, %d hata).',
        (int) ($sonuc['eklenen'] ?? 0), (int) ($sonuc['yinelenen'] ?? 0), (int) ($sonuc['tavan'] ?? 0), count((array) ($sonuc['hatalar'] ?? []))));
} catch (Throwable $e) {
    gunluk('Gönderim başarısız: ' . $e->getMessage());
}
