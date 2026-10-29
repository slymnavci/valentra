<?php
declare(strict_types=1);

/**
 * Günlük burç yorumu ajanı.
 *
 * Site sahibinin istegi (29.10.2026): "burc ve yorumlarini gunluk
 * yapalim; yorumlari yapay zeka yapsin". Bugunun 12 yorumu sitede yoksa
 * tek model istegiyle yazip gonderiyor; varsa model cagrilmadan cikiyor.
 * Ajan iki saatte bir calistigi icin gece yarisindan sonraki ilk calisma
 * gunun yorumlarini yaziyor.
 *
 * Calistirma:
 *   php ajan/burc.php [--kuru] [--sure=5]
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
require_once __DIR__ . '/src/Bildirim.php';

use Valentra\Ajan\{Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['kuru', 'sure::']);
$kuru       = isset($secenekler['kuru']);
$bitis      = time() + max(2, (int) ($secenekler['sure'] ?? 5)) * 60;

/** Burçlar, takvim sırasıyla (sitedeki BURCLAR ile ayni anahtarlar). */
const BURC_ADLARI = [
    'koc' => 'Koç', 'boga' => 'Boğa', 'ikizler' => 'İkizler', 'yengec' => 'Yengeç',
    'aslan' => 'Aslan', 'basak' => 'Başak', 'terazi' => 'Terazi', 'akrep' => 'Akrep',
    'yay' => 'Yay', 'oglak' => 'Oğlak', 'kova' => 'Kova', 'balik' => 'Balık',
];

const BURC_YONERGE = <<<'METIN'
    Valentra haber sitesinin "Günlük Burç Yorumları" bölümü için 12 burcun
    BUGÜNKÜ yorumlarını yazıyorsun. Okur her sabah kendi burcunu okuyor;
    yorum sıcak, akıcı, umut veren ve günlük hayata dokunan bir dille
    yazılmalı.

    HER BURÇ İÇİN:
    - baslik: günün temasını veren kısa bir cümle (6-10 kelime).
    - genel: 90-130 kelime; günün genel havası, fırsatlar, dikkat
      edilecekler.
    - ask: 40-70 kelime; ilişkisi olanlar ve yalnızlar için.
    - para: 40-70 kelime; iş hayatı ve para.
    - saglik: 30-60 kelime; enerji, dinlenme, denge.
    - sans_sayisi: 1-99 arası bir sayı; sans_rengi: Türkçe bir renk adı.

    KURALLAR:
    - Her burcun yorumu farklı olsun; aynı cümleleri, kalıpları ve
      tavsiyeleri tekrarlama. Burcun bilinen özelliklerini (element,
      mizaç) kullan.
    - Gezegen geçişi, Ay'ın konumu, retro gibi belirli astronomik olay,
      derece ya da tarih YAZMA; bunları doğrulayamıyoruz.
    - Korkutucu, kesin ve kaderci ifadeler yok ("kaza geçireceksiniz",
      "ayrılacaksınız", "kesinlikle kazanacaksınız"). Olasılık dili kullan.
    - Sağlıkta teşhis, ilaç ya da tedavi önerme; parada belirli yatırım
      aracı, hisse, kripto ya da bahis önerme.
    - Siyaset, din ve gündemdeki haberler yok.
    - Türkçe yaz; emoji, madde işareti ve biçimlendirme kullanma.
    - Sonuçları burçların verilen sırasıyla döndür: sira 1 Koç ... 12 Balık.
    METIN;

const BURC_SEMA = [
    'type' => 'object',
    'properties' => [
        'sonuclar' => [
            'type'  => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'sira'        => ['type' => 'integer', 'description' => '1 Koç ... 12 Balık'],
                    'baslik'      => ['type' => 'string'],
                    'genel'       => ['type' => 'string'],
                    'ask'         => ['type' => 'string'],
                    'para'        => ['type' => 'string'],
                    'saglik'      => ['type' => 'string'],
                    'sans_sayisi' => ['type' => 'integer'],
                    'sans_rengi'  => ['type' => 'string'],
                ],
                'required' => ['sira', 'baslik', 'genel', 'ask', 'para', 'saglik', 'sans_sayisi', 'sans_rengi'],
            ],
        ],
    ],
    'required' => ['sonuclar'],
];

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
    $durum = $site->burcDurum();
} catch (Throwable $e) {
    gunluk('Site durumu alınamadı: ' . $e->getMessage());
    exit(0);
}

if ($durum['var'] && !$kuru) {
    gunluk('Bugünün (' . $durum['bugun'] . ') burç yorumları zaten var.');
    exit(0);
}

$gunAdlari = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
$aylar     = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$t         = new DateTimeImmutable($durum['bugun']);
$tarih     = (int) $t->format('j') . ' ' . $aylar[(int) $t->format('n') - 1] . ' ' . $t->format('Y') . ' ' . $gunAdlari[(int) $t->format('N') - 1];

$istem = 'TARİH: ' . $tarih . "\n\nBURÇLAR (bu sırayla):\n"
    . implode("\n", array_map(static fn (int $i, string $ad): string => ($i + 1) . '. ' . $ad, array_keys(array_values(BURC_ADLARI)), array_values(BURC_ADLARI)));

$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::haberModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->geminiOnce();
$yazar->sureSiniri(180, [10, 30], $bitis);

try {
    $sonuclar = $yazar->semaliIstek(BURC_YONERGE, $istem, BURC_SEMA, 24000, count(BURC_ADLARI), 'genel');
} catch (Throwable $e) {
    gunluk('Burç yorumları yazılamadı: ' . $e->getMessage());
    exit(0);
}

$anahtarlar = array_keys(BURC_ADLARI);
$yorumlar   = [];

foreach ($sonuclar as $sira => $s) {
    $burc = $anahtarlar[$sira] ?? null;

    if ($burc === null || !is_array($s)) {
        continue;
    }

    $yorumlar[] = [
        'burc'        => $burc,
        'baslik'      => (string) ($s['baslik'] ?? ''),
        'genel'       => (string) ($s['genel'] ?? ''),
        'ask'         => (string) ($s['ask'] ?? ''),
        'para'        => (string) ($s['para'] ?? ''),
        'saglik'      => (string) ($s['saglik'] ?? ''),
        'sans_sayisi' => (int) ($s['sans_sayisi'] ?? 0),
        'sans_rengi'  => (string) ($s['sans_rengi'] ?? ''),
    ];
}

gunluk(count($yorumlar) . ' burç yorumu yazıldı (' . $tarih . ', model: ' . $yazar->sonYanitlayan() . ').');

if ($kuru) {
    foreach ($yorumlar as $y) {
        echo "\n== " . BURC_ADLARI[$y['burc']] . ': ' . $y['baslik'] . "\n" . $y['genel'] . "\nAşk: " . $y['ask']
            . "\nPara: " . $y['para'] . "\nSağlık: " . $y['saglik'] . "\nŞans: " . $y['sans_sayisi'] . ', ' . $y['sans_rengi'] . "\n";
    }

    exit(0);
}

if ($yorumlar === []) {
    exit(0);
}

try {
    $sonuc = $site->burcGonder($durum['bugun'], $yorumlar);
    gunluk((int) ($sonuc['kaydedilen'] ?? 0) . ' yorum sitede yayında.'
        . (($sonuc['hatalar'] ?? []) !== [] ? ' Hatalar: ' . implode('; ', (array) $sonuc['hatalar']) : ''));
} catch (Throwable $e) {
    gunluk('Gönderim başarısız: ' . $e->getMessage());
}
