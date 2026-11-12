<?php
declare(strict_types=1);

/**
 * Yayindaki haberlerin onem puanini guncel yonergeyle yeniden verir.
 *
 * Site sahibinin istegi (12.11.2026): "su anda manseti yeniden bu duzene
 * gore puanlandir". Onem puani haber toplanirken veriliyor; yonerge
 * degisince (siradan spor ana sayfaya girmez, milli mac sonucu girer,
 * baska ulkenin ic meselesi dusuk puan) yayindaki haberler eski puaniyla
 * kaliyordu. Bu is son saatlerin haberlerini AYNI olcuyle (Yazar::
 * onemOlcutu) yeniden puanlatiyor; ana sayfa sirasi, "5 gelisme" ve spor
 * esigi yeni puana gore kendiliginden degisiyor.
 *
 * Elle calisir (ajan.yml, mod: onem-denetim); yonerge her degistiginde.
 *
 * KAPSAM: yalnizca spor haberleri ve "baska ulkenin ic meselesi" sayilan
 * haberler. Ilk kuru calisma (12.11.2026) 400 haberin 310'unun puanini
 * degistirmek istedi; cogu yonergeyle ilgisiz, Turkiye gundemi haberlerinin
 * dusurulmesiydi ("Erdogan COP31 liderlerini karsiladi" 84 -> 50, "Benzine
 * indirim" 90 -> 72). Sebep olcu degil malzeme: toplama sirasinda model
 * haberin TAM metnini ve olayi veren kaynak sayisini goruyor, burada
 * yalnizca baslik ve spot var; daha az bilgiyle verilen puan sistematik
 * olarak dusuk cikiyor. Bu yuzden model her habere bir "kural" da yaziyor
 * ve yalnizca yonergede DEGISEN kurallara giren haberlerin puani yaziliyor;
 * gerisi toplanirken aldigi puanla kaliyor. --hepsi bu sinirlamayi kaldirir.
 *
 *   php ajan/onem_denetle.php             son 36 saatin haberleri
 *   php ajan/onem_denetle.php --saat=72   daha geriye
 *   php ajan/onem_denetle.php --kuru      yalnizca farklari goster
 *   php ajan/onem_denetle.php --hepsi     kural ayirmadan butun haberler
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

/** Bundan kucuk fark yazilmiyor: model ayni habere 2-3 puan oynak verebiliyor. */
const ASGARI_FARK = 5;

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

$secenekler = getopt('', ['kuru', 'hepsi', 'saat::', 'sure::']);
$kuru  = isset($secenekler['kuru']);
$hepsi = isset($secenekler['hepsi']);
$saat  = max(1, min(168, (int) ($secenekler['saat'] ?? 36)));
$bitis = time() + max(2, (int) ($secenekler['sure'] ?? 15)) * 60;

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));

try {
    $haberler = $site->onemDenetimListesi($saat);
} catch (Throwable $e) {
    gunluk('Önem listesi alınamadı: ' . $e->getMessage());
    exit(1);
}

if ($haberler === []) {
    gunluk('Puanlanacak haber yok.');
    exit(0);
}

gunluk(count($haberler) . ' haber yeniden puanlanacak (son ' . $saat . ' saat).');

$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::haberModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->sureSiniri(120, [10, 30], $bitis);

$yonerge = "Sen Valentra haber sitesinin editörüsün. Sana yayımlanmış haberlerin başlığı,\n"
    . "spotu (özeti), kategorisi ve aynı olayı veren kaynak sayısı verilecek. Her\n"
    . "habere aşağıdaki ölçüyle 0-100 arası bir önem puanı ver. Yalnızca verilen\n"
    . "bilgiye bak; haberi bilmiyormuş gibi tahmin yürütme. Kaynak sayısı 1 ise\n"
    . "\"Bu olayı veren kaynak sayısı\" kuralını uygulama; kaynağın tek olması\n"
    . "puanı düşürmek için sebep değildir.\n\n"
    . "Her haber için ayrıca \"kural\" alanını doldur:\n"
    . "- \"spor\": haber spor kategorisinde.\n"
    . "- \"yabanci\": haber ölçüdeki BAŞKA ÜLKENİN İÇ MESELESİ basamağına giriyor\n"
    . "  (yalnızca o ülkeyi ilgilendiriyor, Türkiye'deki okuru etkilemiyor) ve\n"
    . "  puanını 20-45 verdin. Ölçünün bu kuralın DIŞINDA saydığı haberler\n"
    . "  (Türkiye'yi etkileyen dış gelişme, savaş ve krizler, küresel piyasa\n"
    . "  kararları, büyük felaketler) \"yabanci\" DEĞİLDİR.\n"
    . "- \"diger\": geri kalan her şey (Türkiye gündemi, politika, ekonomi ...).\n\n"
    . Yazar::onemOlcutu();

$sema = [
    'type'       => 'object',
    'properties' => [
        'sonuclar' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'sira'  => ['type' => 'integer', 'description' => 'İstemdeki sıra numarası (1 tabanlı)'],
                    'onem'  => ['type' => 'integer', 'description' => '0-100 arası önem puanı'],
                    'kural' => ['type' => 'string',  'enum' => ['spor', 'yabanci', 'diger'], 'description' => 'Haberin girdiği kural (bkz. yönerge)'],
                    'neden' => ['type' => 'string',  'description' => 'Tek kısa cümle: puanın hangi basamağa dayandığı'],
                ],
                'required' => ['sira', 'onem', 'kural', 'neden'],
            ],
        ],
    ],
    'required' => ['sonuclar'],
];

$okunan  = 0;
$farkli  = 0;
$degisen = 0;
$kapsamDisi = 0;

foreach (array_chunk($haberler, PARTI) as $parti) {
    if (time() >= $bitis - 30) {
        gunluk('Süre doldu; kalanlar puanlanmadı.');
        break;
    }

    $istem = "Haberler:\n";

    foreach ($parti as $i => $h) {
        $istem .= ($i + 1) . '. [' . ((string) $h['kategori'] !== '' ? $h['kategori'] : 'kategorisiz') . '] ' . $h['baslik']
            . "\n   Spot: " . mb_substr((string) $h['ozet'], 0, 320)
            . "\n   Bu olayı veren kaynak sayısı: " . (int) $h['kaynak_sayisi'] . "\n";
    }

    try {
        $sonuclar = $yazar->semaliIstek($yonerge, $istem, $sema, 6000, count($parti), 'onem');
    } catch (Throwable $e) {
        gunluk('Model yanıt vermedi: ' . $e->getMessage());
        break;
    }

    $gonder = [];

    foreach ($parti as $i => $h) {
        $s = $sonuclar[$i] ?? null;

        if (!is_array($s) || !is_numeric($s['onem'] ?? null)) {
            continue;
        }

        $okunan++;
        $yeni = max(0, min(100, (int) $s['onem']));
        $eski = (int) $h['onem'];

        if (abs($yeni - $eski) < ASGARI_FARK) {
            continue;
        }

        // Kategori sitenin bilgisi; modelin "spor" demesine degil ona bakiliyor.
        $kural = (string) $h['kategori'] === 'spor' ? 'spor' : ((string) ($s['kural'] ?? '') === 'yabanci' ? 'yabanci' : 'diger');

        // "yabanci" yalnizca puani dusurur ve 45'i gecemez (basamagin tavani).
        if ($kural === 'yabanci' && ($yeni > 45 || $yeni >= $eski)) {
            $kural = 'diger';
        }

        if (!$hepsi && $kural === 'diger') {
            $kapsamDisi++;
            continue;
        }

        $farkli++;
        gunluk(sprintf('  %3d → %3d  %-7s [%s] %s  (%s)', $eski, $yeni, $kural, $h['kategori'], $h['baslik'], trim((string) ($s['neden'] ?? ''))));
        $gonder[] = ['id' => (int) $h['id'], 'onem' => $yeni];
    }

    if (!$kuru && $gonder !== []) {
        try {
            $degisen += $site->onemDenetimGonder($gonder);
        } catch (Throwable $e) {
            gunluk('Puanlar gönderilemedi: ' . $e->getMessage());
        }
    }
}

$ozet = 'Önem denetimi: ' . $okunan . ' haber puanlandı, ' . $farkli . ' tanesinin puanı en az ' . ASGARI_FARK . ' puan farklı'
    . ($hepsi ? '' : ' (spor ve başka ülkenin iç meselesi; ' . $kapsamDisi . ' haber kapsam dışı bırakıldı)')
    . ($kuru ? ' (kuru çalışma, siteye yazılmadı)' : ', ' . $degisen . ' haberin puanı güncellendi') . '.';
gunluk($ozet);

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Önem denetimi::' . $ozet . PHP_EOL;
}
