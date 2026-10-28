<?php
declare(strict_types=1);

/**
 * "Valentra Dosya" ajanı: derleme ve açıklayıcı yazılar.
 *
 * Site sahibinin istegi (28.10.2026, ozgun haber onerilerinin hepsi):
 *   - derleme: ayni olayi 3+ farkli kaynak veriyorsa "Ne biliyoruz, ne
 *     bilmiyoruz" (kronoloji, taraflarin aciklamalari, belli olmayanlar);
 *   - aciklayici: Search Console'daki firsat sorgularina sitenin kendi
 *     haberlerinden "nedir, nasil" cevabi.
 *
 * Gunluk tavan sitede (derleme 2, aciklayici 1); tavan doluysa model
 * cagrilmiyor. Yazilar sitede TASLAK, panelde onay bekliyor.
 *
 * Calistirma:
 *   php ajan/dosya.php [--kuru] [--sure=8]
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
require_once __DIR__ . '/src/Derleyici.php';
require_once __DIR__ . '/src/Bildirim.php';

use Valentra\Ajan\{Bildirim, Derleyici, KotaBittiException, ModelErisimYokException, Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['kuru', 'sure::']);
$kuru       = isset($secenekler['kuru']);
$bitis      = time() + max(3, (int) ($secenekler['sure'] ?? 8)) * 60;

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
    $malzeme = $site->dosyaMalzeme(36);
} catch (Throwable $e) {
    gunluk('Malzeme alınamadı: ' . $e->getMessage());
    Bildirim::uyari('Valentra Dosya ajanı siteye ulaşamadı; bu çalışmada yazı yazılmadı. Teknik ayrıntı: ' . $e->getMessage());
    exit(0);
}

$kalan = static fn (string $tur): int => (int) ($malzeme['tavan'][$tur] ?? 0) - (int) ($malzeme['sayilar'][$tur] ?? 0);

if ($kalan('derleme') <= 0 && $kalan('aciklayici') <= 0) {
    gunluk('Bugünün dosya yazıları tamam (derleme ' . (int) ($malzeme['sayilar']['derleme'] ?? 0)
        . ', açıklayıcı ' . (int) ($malzeme['sayilar']['aciklayici'] ?? 0) . ').');
    exit(0);
}

$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::koseModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->geminiOnce();
$yazar->sureSiniri(180, [10, 30], $bitis);
$yazar->yedekModel(Yazar::haberModeli());
$derleyici = new Derleyici($yazar);
$yazilar   = [];

// --- Derleme: en az 3 farkli kaynagin verdigi olay ---------------------------
if ($kalan('derleme') > 0 && time() < $bitis - 180) {
    try {
        $olay = $derleyici->olaySec($malzeme['haberler'], $malzeme['onceki']);

        if ($olay === null) {
            gunluk('Derleme: 3+ kaynağın verdiği uygun olay yok.');
        } else {
            gunluk('Derleme: ' . $olay['olay'] . ' (' . count($olay['haberler']) . ' haber)');
            $yazi = $derleyici->derlemeYaz($olay, $malzeme['haberler']);

            if ($yazi === null) {
                gunluk('  geçerli yazı çıkmadı.');
            } else {
                $yazilar[] = $yazi + [
                    'tur'          => 'derleme',
                    'kategori'     => $olay['kategori'],
                    'onem'         => 72,
                    'haber_idleri' => array_map(static fn (int $i): int => (int) ($malzeme['haberler'][$i]['id'] ?? 0), $olay['haberler']),
                ];
                gunluk('  yazıldı: ' . $yazi['baslik']);
            }
        }
    } catch (ModelErisimYokException | KotaBittiException $e) {
        gunluk('Model kullanılamıyor: ' . $e->getMessage());
    } catch (Throwable $e) {
        gunluk('Derleme yazılamadı: ' . $e->getMessage());
    }
}

// --- Aciklayici: firsat sorgusuna sitenin haberlerinden cevap ----------------
if ($kalan('aciklayici') > 0 && time() < $bitis - 120) {
    $oncekiBasliklar = mb_strtolower(implode(' | ', array_column($malzeme['onceki'], 'baslik')), 'UTF-8');

    foreach ($malzeme['firsatlar'] as $firsat) {
        // Ayni sorgu son iki haftada yazildiysa atla.
        if (str_contains($oncekiBasliklar, mb_strtolower((string) $firsat['sorgu'], 'UTF-8'))) {
            continue;
        }

        try {
            gunluk('Açıklayıcı: "' . $firsat['sorgu'] . '" (' . (int) $firsat['gosterim'] . ' gösterim, ' . count($firsat['haberler']) . ' ilgili haber)');
            $yazi = $derleyici->aciklayiciYaz($firsat);
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
    gunluk('Gönderilecek dosya yazısı yok.');
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
    gunluk(sprintf('%d yazı onaya gönderildi (%d yinelenen, %d tavan, %d hata).',
        (int) ($sonuc['eklenen'] ?? 0), (int) ($sonuc['yinelenen'] ?? 0), (int) ($sonuc['tavan'] ?? 0), count((array) ($sonuc['hatalar'] ?? []))));
} catch (Throwable $e) {
    gunluk('Gönderim başarısız: ' . $e->getMessage());
}
