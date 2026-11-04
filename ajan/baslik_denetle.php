<?php
declare(strict_types=1);

/**
 * Yayindaki haber basliklarinin Turkcesini denetler ve duzeltir.
 *
 * Site sahibinin istegi (04.11.2026): "haberlerdeki basliklarin Turkcesini
 * cok iyi yapmamiz lazim. 'Trump uzayi tum insanligin ilan etti' denmis;
 * tum dunyanin ortak alani olarak kabul etti demen lazim. Benzer hatalar
 * da duzeltilsin." Yazim kurali istemde var (Yazar.php, BASLIK); bu is
 * yazilmis basliklari sonradan tarayan ikinci goz: anlasilmayan, ceviri
 * kalibi tasiyan, eksik tumlecli ya da yarim tirnakli basliklari dogal
 * Turkceyle yeniden kuruyor. Iyi basliga dokunmuyor.
 *
 *   php ajan/baslik_denetle.php            son 3 gunun denetlenmemis basliklari
 *   php ajan/baslik_denetle.php --gun=14   ilk temizlik: son iki hafta
 *   php ajan/baslik_denetle.php --kuru     yalnizca onerileri goster
 *
 * Ortam: VALENTRA_SITE_URL, VALENTRA_AGENT_KEY, GEMINI_API_KEY
 * (OPENAI_API_KEY, ANTHROPIC_API_KEY yedek).
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

use Valentra\Ajan\{Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

const PARTI = 25;

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

$secenekler = getopt('', ['kuru', 'gun::', 'sure::']);
$kuru  = isset($secenekler['kuru']);
$gun   = max(1, min(30, (int) ($secenekler['gun'] ?? 3)));
$bitis = time() + max(2, (int) ($secenekler['sure'] ?? 6)) * 60;

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));

try {
    $haberler = $site->baslikDenetimListesi($gun);
} catch (Throwable $e) {
    gunluk('Başlık listesi alınamadı: ' . $e->getMessage());
    exit(0);
}

if ($haberler === []) {
    gunluk('Denetlenecek yeni başlık yok.');
    exit(0);
}

$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::haberModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->sureSiniri(120, [10, 30], $bitis);

$yonerge = <<<'METIN'
Sen Valentra haber sitesinin Türkçe başlık editörüsün. Sana yayımlanmış
haberlerin başlığı ve spotu (özeti) verilecek. Her başlık için karar ver:

İYİ: Başlığı haberi hiç bilmeyen bir Türk okur tek okumada anlıyor,
doğal Türkçe. Dokunma (duzelt=false, yeni boş).

DÜZELT: Aşağıdakilerden biri varsa başlığı yeniden yaz (duzelt=true):
- Ne dediği anlaşılmıyor ya da cümle yarım kalmış.
- Fiilin istediği tümleç eksik ("uzayı 'tüm insanlığın' ilan etti":
  NE ilan edildiği yok).
- Yabancı başlığın kelimesi kelimesine çevirisi; Türkçede kurulmayan
  kalıp, deyim ya da kelime sırası.
- Çevrilmiş bir sözcük öbeği tırnağa alınıp anlamı askıda bırakılmış.
  Tırnak yalnızca birinin söylediği, tek başına anlamlı söz için kalır.
- Türkçe karşılığı olan yabancı terim, yanlış ek, anlatım bozukluğu.

YENİ BAŞLIĞIN KURALLARI:
- Aynı olayı anlatır; spotta ve başlıkta olmayan bilgi EKLEME, rakam ve
  isim değiştirme. Anlamı netleştir, haberi değiştirme.
- En fazla 70 karakter, kısa ve vurucu; ünlem, "şok", "flaş" yok.
- Kurum adları Türkçe bilinen biçimiyle (Beyaz Saray, Temsilciler Meclisi).

ÖRNEKLER:
- "Trump uzayı 'tüm insanlığın' ilan etti" → "Trump uzayı tüm insanlığın ortak alanı ilan etti"
- "Fed 'beklemede' kalmayı seçti" → "Fed faizi sabit tuttu"
- "TÜİK ekim enflasyonunu açıkladı" → iyi, dokunma.

Emin değilsen dokunma: iyi bir başlığı değiştirmek de hatadır.
METIN;

$sema = [
    'type'       => 'object',
    'properties' => [
        'sonuclar' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'sira'    => ['type' => 'integer', 'description' => 'İstemdeki sıra numarası (1 tabanlı)'],
                    'duzelt'  => ['type' => 'boolean', 'description' => 'Başlık düzeltilmeli mi'],
                    'yeni'    => ['type' => 'string',  'description' => 'Düzeltilmiş başlık; duzelt=false ise boş'],
                    'neden'   => ['type' => 'string',  'description' => 'Kısa gerekçe (ör. "eksik tümleç", "çeviri kalıbı")'],
                ],
                'required' => ['sira', 'duzelt', 'yeni', 'neden'],
            ],
        ],
    ],
    'required' => ['sonuclar'],
];

$denetlenen = 0;
$duzeltilen = 0;
$oneriler   = 0;

foreach (array_chunk($haberler, PARTI) as $parti) {
    if (time() >= $bitis - 30) {
        gunluk('Süre doldu; kalanlar sonraki çalışmada.');
        break;
    }

    $istem = "Başlıklar:\n";

    foreach ($parti as $i => $h) {
        $istem .= ($i + 1) . '. Başlık: ' . $h['baslik'] . "\n   Spot: " . mb_substr((string) $h['ozet'], 0, 300) . "\n";
    }

    try {
        $sonuclar = $yazar->semaliIstek($yonerge, $istem, $sema, 6000, count($parti), 'duzelt');
    } catch (Throwable $e) {
        gunluk('Model yanıt vermedi: ' . $e->getMessage());
        break;
    }

    $gonder = [];

    foreach ($parti as $i => $h) {
        $s    = $sonuclar[$i] ?? null;
        $yeni = is_array($s) && !empty($s['duzelt']) ? trim((string) ($s['yeni'] ?? '')) : '';

        if ($yeni !== '' && $yeni !== $h['baslik']) {
            $oneriler++;
            gunluk('  ✎ ' . $h['baslik'] . "\n             → " . $yeni . '  (' . trim((string) ($s['neden'] ?? '')) . ')');
        }

        // Model bu haberi atladiysa isaretlenmiyor; sonraki calismada yeniden.
        if ($s !== null) {
            $gonder[] = ['id' => (int) $h['id'], 'yeni' => $yeni !== '' ? $yeni : null];
        }
    }

    $denetlenen += count($gonder);

    if (!$kuru && $gonder !== []) {
        try {
            $duzeltilen += $site->baslikDenetimGonder($gonder);
        } catch (Throwable $e) {
            gunluk('Sonuç gönderilemedi: ' . $e->getMessage());
        }
    }
}

$ozet = 'Başlık denetimi: ' . $denetlenen . ' başlık okundu, ' . $oneriler . ' tanesi için düzeltme önerildi'
    . ($kuru ? ' (kuru çalışma, siteye yazılmadı)' : ', ' . $duzeltilen . ' başlık düzeltildi') . '.';
gunluk($ozet);

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Başlık denetimi::' . $ozet . PHP_EOL;
}
