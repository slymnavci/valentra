<?php
declare(strict_types=1);

/**
 * Ayni olayi veren haberleri tek habere indirir.
 *
 * Site sahibinin istegi (07.11.2026): "ayni haberi birden cok kez
 * yayinlama; ana sayfadaki 20 haberin hicbirisi ayni olmamali; benzer
 * konusu ayni haberi birlestir, tek haber yap". Site basligi cok benzeyen
 * haberleri ve derlemenin dayandigi haberleri kendisi birlestiriyor
 * (includes/kopya.php). Bu betik kalanlari modele okutuyor: "Merkez
 * Bankasi faizi sabit tuttu" ile "TCMB politika faizini degistirmedi"
 * gibi kelimeleri farkli ama olayi ayni haberleri buluyor ve siteye
 * gonderiyor. Yayinda hangisinin kalacagini site seciyor.
 *
 *   php ajan/kopya.php            son 36 saatin haberleri
 *   php ajan/kopya.php --saat=72  ilk temizlik
 *   php ajan/kopya.php --kuru     yalnizca bulunan gruplari goster
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

/** Modele bir kerede okutulan en fazla haber (en yeniler). */
const EN_FAZLA = 220;

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

$secenekler = getopt('', ['kuru', 'saat::', 'sure::']);
$kuru  = isset($secenekler['kuru']);
$saat  = max(6, min(96, (int) ($secenekler['saat'] ?? 36)));
$bitis = time() + max(2, (int) ($secenekler['sure'] ?? 4)) * 60;

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));

try {
    $liste = $site->kopyaListe($saat);
} catch (Throwable $e) {
    gunluk('Haber listesi alınamadı: ' . $e->getMessage());
    exit(0);
}

$haberler = array_slice($liste['haberler'], -EN_FAZLA);
gunluk('Site kendiliğinden ' . $liste['birlesen'] . ' kopya haberi birleştirdi; ' . count($haberler) . ' haber modele okutulacak.');

if (count($haberler) < 2) {
    exit(0);
}

$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::haberModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->sureSiniri(150, [10, 30], $bitis);

$yonerge = <<<'METIN'
Sen Valentra haber sitesinin editörüsün. Görevin aynı olayı birden çok kez
yayımlamamak. Sana son saatlerde yayımlanan haberlerin numaralı listesi
(eskiden yeniye; başlık, kategori, spot) verilecek.

AYNI OLAY: İki haber aynı gelişmeyi anlatıyor; birini okuyan okur
ötekinin başlığından yeni bir şey öğrenmiyor. Kelimeler farklı olabilir:
- "Merkez Bankası faizi sabit tuttu" = "TCMB politika faizini değiştirmedi"
- "Malatya'da 5,1 büyüklüğünde deprem" = "Malatya sallandı: AFAD depremin büyüklüğünü açıkladı" (aynı deprem)
- "Galatasaray, Fenerbahçe'yi 2-1 yendi" = "Derbide kazanan Galatasaray"
- Aynı açıklamanın, aynı kararın, aynı kazanın farklı kaynaklardan haberleri.

AYNI OLAY DEĞİL (ayrı kalsın):
- Aynı konuda FARKLI gelişmeler: maçın öncesi ile sonucu; faiz kararı ile
  kararın piyasaya etkisi; bir davanın iki ayrı duruşması.
- Aynı kişi ya da kurumun farklı açıklamaları, farklı günlerin verileri.
- Farklı yerlerdeki benzer olaylar (iki ayrı deprem, iki ayrı kaza).
- Rakamları farklı olan haberler (farklı skor, farklı büyüklük) ayrı olaydır.

Yalnızca listede DAHA ÖNCE geçen bir haberle aynı olayı anlatan haberleri
yaz: sira = bu haberin numarası, ayni = aynı olayı anlatan önceki haberin
numarası. Hiçbir haber tekrar değilse boş liste döndür. Emin değilsen
yazma: farklı iki haberi birleştirmek, bir haberi siteden kaldırır.
METIN;

$sema = [
    'type'       => 'object',
    'properties' => [
        'sonuclar' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'sira'  => ['type' => 'integer', 'description' => 'Tekrar olan haberin numarası'],
                    'ayni'  => ['type' => 'integer', 'description' => 'Aynı olayı anlatan ÖNCEKİ haberin numarası'],
                    'neden' => ['type' => 'string',  'description' => 'Ortak olay, birkaç kelime'],
                ],
                'required' => ['sira', 'ayni', 'neden'],
            ],
        ],
    ],
    'required' => ['sonuclar'],
];

$istem = "Haberler:\n";

foreach ($haberler as $i => $h) {
    $istem .= ($i + 1) . '. [' . ($h['kategori'] !== '' ? $h['kategori'] : 'Genel') . '] ' . $h['baslik']
        . "\n   Spot: " . mb_substr((string) $h['ozet'], 0, 200) . "\n";
}

try {
    $sonuclar = $yazar->semaliIstek($yonerge, $istem, $sema, 6000, count($haberler), 'ayni');
} catch (Throwable $e) {
    gunluk('Model yanıt vermedi: ' . $e->getMessage());
    exit(0);
}

// Birlesim kumesi: 3 = 2 ve 2 = 1 ise uculu tek grup.
$ust = array_keys($haberler);
$bul = static function (int $i) use (&$ust): int {
    while ($ust[$i] !== $i) {
        $i = $ust[$i] = $ust[$ust[$i]];
    }

    return $i;
};

foreach ($sonuclar as $sira => $s) {
    $ayni = (int) ($s['ayni'] ?? 0) - 1;

    if ($ayni < 0 || $ayni >= $sira || !isset($haberler[$ayni])) {
        continue;
    }

    gunluk('  = ' . $haberler[$sira]['baslik'] . "\n      ~ " . $haberler[$ayni]['baslik'] . '  (' . trim((string) ($s['neden'] ?? '')) . ')');
    $ust[$bul($sira)] = $bul($ayni);
}

$gruplar = [];

foreach (array_keys($haberler) as $i) {
    $gruplar[$bul($i)][] = (int) $haberler[$i]['id'];
}

// Cok buyuk grup modelin yanlisi olabilir (bir konuyu tek olay sanmak): gonderilmiyor.
$gruplar = array_values(array_filter($gruplar, static fn (array $g): bool => count($g) > 1 && count($g) <= 6));

$birlesen = 0;

if (!$kuru && $gruplar !== []) {
    try {
        $birlesen = $site->kopyaBirlestir($gruplar);
    } catch (Throwable $e) {
        gunluk('Birleştirme gönderilemedi: ' . $e->getMessage());
    }
}

$ozet = 'Kopya haber: site ' . $liste['birlesen'] . ' haberi kendiliğinden birleştirdi; model ' . count($gruplar) . ' grup buldu'
    . ($kuru ? ' (kuru çalışma, siteye yazılmadı)' : ', ' . $birlesen . ' haber ana haberine katıldı') . '.';
gunluk($ozet);

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Kopya haber::' . $ozet . PHP_EOL;
}
