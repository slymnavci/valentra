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
 *   php ajan/burc.php [--kuru] [--yeniden] [--sure=8]
 *
 * --yeniden: bugunun yorumlari varsa da yeniden yazar (elle calistirma;
 * yazim kurallari degisince ayni gun yenilensin).
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

$secenekler = getopt('', ['kuru', 'yeniden', 'sure::']);
$kuru       = isset($secenekler['kuru']);
$yeniden    = isset($secenekler['yeniden']);
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
    yorum sıcak, akıcı, umut veren ve günlük hayata dokunan bir dille,
    astrolojinin kendi diliyle yazılmalı (site sahibinin istegi, 29.10.2026).

    HER BURÇ İÇİN:
    - baslik: günün temasını veren kısa bir cümle (6-10 kelime).
    - genel: 100-140 kelime. Gökyüzüyle başla: o burca özel bir gökyüzü
      cümlesi kur ("Ay bugün sizin için ilişkiler evinizden geçiyor",
      "Ay'ın Güneş'e yaklaşması bir dönemin kapanışını hatırlatıyor" gibi).
      Sonra gün içinde yaşanabilecek 2-3 SOMUT an anlat: uzun zamandır
      haber alınmayan birinden gelen mesaj, iş yerinde beklenmedik bir
      toplantı ya da övgü, ertelenen bir telefonun nihayet açılması,
      alışverişte küçük bir fırsat, evde bir eşyanın yerini değiştirme
      isteği, yolda karşılaşılan tanıdık bir yüz, akşam bir davet... Her
      burçta farklı anlar seç.
    - ask: 40-70 kelime; ilişkisi olanlar ve yalnızlar için somut bir an
      ve küçük bir öneri (bir mesaj atmak, birlikte yürüyüşe çıkmak...).
    - para: 40-70 kelime; iş hayatı ve bütçe için somut bir durum ve
      olumlu bir öneri.
    - saglik: 30-60 kelime; enerji, dinlenme, denge için günlük küçük
      öneriler (bol su, kısa bir yürüyüş, erken yatmak, sevdiği bir müzik).
    - sans_sayisi: 1-99 arası bir sayı; sans_rengi: Türkçe bir renk adı.

    GÖKYÜZÜ:
    - İstemde günün GERÇEK gökyüzü bilgisi var (Güneş'in ve Ay'ın burcu,
      Ay'ın evresi ve Güneş'e yakınlığı, Ay'ın her burç için hangi evden
      geçtiği ve o burçla açısı). Astrolojik yorumu bunlara dayandır;
      evlerin geleneksel anlamlarını, açının uyumlu ya da gerilimli
      olmasını ve burcun elementini, mizacını kullan.
    - Listede olmayan gezegen geçişi, retro, tutulma, derece ya da tarih
      UYDURMA (Merkür, Venüs, Mars... yalnızca verilmişse). Güneş ile Ay
      arasındaki açıyı yalnızca verildiği gibi an; "yok" diyorsa Güneş-Ay
      açısından söz etme. Burçla ilgili açı, Ay'ın o burca açısıdır.

    ÜSLUP:
    - Olumlu ve yapıcı ol; zorlukları bile fırsat ve tavsiye olarak anlat.
      Bilimsel açıklama, istatistik ya da "bilimsel olarak" gibi ifadeler
      kullanma; yorumu "eğlence amaçlıdır" diye kendin kesme (sitede not var).
    - Her burcun yorumu farklı olsun; aynı cümleleri, kalıpları ve
      tavsiyeleri tekrarlama.
    - Korkutucu, kesin ve kaderci ifadeler yok ("kaza geçireceksiniz",
      "ayrılacaksınız", "kesinlikle kazanacaksınız"). Olasılık dili kullan
      ("bir mesaj gelebilir", "fırsat doğabilir").
    - Sağlıkta teşhis, ilaç ya da tedavi önerme; parada belirli yatırım
      aracı, hisse, kripto ya da bahis önerme.
    - Siyaset, din ve gündemdeki haberler yok.
    - Türkçe yaz; emoji, madde işareti ve biçimlendirme kullanma.
    - Sonuçları istemdeki burç sırasıyla döndür (sira 1'den başlar).
    METIN;

const BURC_SEMA = [
    'type' => 'object',
    'properties' => [
        'sonuclar' => [
            'type'  => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'sira'        => ['type' => 'integer', 'description' => 'istemdeki burç sırası, 1\'den başlar'],
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

/* ------------------------------------------------------------------------
 * Gunun gokyuzu (29.10.2026). Site sahibi "Ay'in Gunes'le yakinlasmasi
 * gibi burca ozel laflar" istedi; model bunlari uydurmasin diye Gunes ve
 * Ay'in konumu burada HESAPLANIYOR (dusuk duyarlikli Meeus formulleri,
 * tropikal zodyak; Ay icin ~0,5 derece). Denendi: 3 Ocak 2026 dolunayi
 * 180,0, 18 Ocak 2026 yeni ayi 0,0, 20 Mart 2026 ekinoksu Gunes 0,0.
 * --------------------------------------------------------------------- */

const GOK_EVLER = [
    1 => 'kişilik ve yeni başlangıçlar', 2 => 'para ve değerler', 3 => 'iletişim, kardeşler ve kısa yolculuklar',
    4 => 'ev ve aile', 5 => 'aşk, eğlence ve yaratıcılık', 6 => 'iş rutini ve sağlık',
    7 => 'ilişkiler ve ortaklıklar', 8 => 'ortak kaynaklar ve dönüşüm', 9 => 'uzak yolculuklar ve öğrenme',
    10 => 'kariyer ve toplumdaki yer', 11 => 'arkadaşlar ve hedefler', 12 => 'iç dünya ve dinlenme',
];

function gok_norm(float $derece): float
{
    $derece = fmod($derece, 360.0);

    return $derece < 0 ? $derece + 360.0 : $derece;
}

function gok_sin(float $derece): float
{
    return sin(deg2rad($derece));
}

/** @return array{gunes:float,ay:float,uzanim:float} ekliptik boylamlar (derece) */
function gok_konum(int $zaman): array
{
    $d  = $zaman / 86400.0 + 2440587.5 - 2451545.0;
    $g  = 357.528 + 0.9856003 * $d;
    $gunes = gok_norm(280.460 + 0.9856474 * $d + 1.915 * gok_sin($g) + 0.020 * gok_sin(2 * $g));

    $mAy = 134.963 + 13.064993 * $d;
    $f   = 93.272 + 13.229350 * $d;
    $uz  = 297.850 + 12.190749 * $d;
    $mGu = 357.529 + 0.98560028 * $d;
    $ay  = gok_norm(218.316 + 13.176396 * $d + 6.289 * gok_sin($mAy) - 1.274 * gok_sin($mAy - 2 * $uz)
        + 0.658 * gok_sin(2 * $uz) + 0.214 * gok_sin(2 * $mAy) - 0.186 * gok_sin($mGu) - 0.114 * gok_sin(2 * $f));

    return ['gunes' => $gunes, 'ay' => $ay, 'uzanim' => gok_norm($ay - $gunes)];
}

/** Boylamin burcu (0 Koç ... 11 Balık). */
function gok_burc(float $boylam): int
{
    return (int) floor($boylam / 30.0) % 12;
}

/** Istem icin gunun gokyuzu metni (Istanbul saatiyle o gun). */
function gokyuzu_metni(string $gun): string
{
    $adlar = array_values(BURC_ADLARI);
    $ogle  = (int) strtotime($gun . ' 12:00 Europe/Istanbul');
    $k     = gok_konum($ogle);
    $ayB   = gok_burc($k['ay']);
    $e     = $k['uzanim'];

    $evre = match (true) {
        $e < 12 || $e >= 348 => 'yeni ay: Ay Güneş\'le kavuşumda',
        $e < 80              => 'büyüyen hilal: Ay Güneş\'ten yeni uzaklaşıyor',
        $e < 100             => 'ilk dördün: Ay Güneş\'le kare açıda',
        $e < 168             => 'büyüyen ay: dolunaya doğru',
        $e < 192             => 'dolunay: Ay Güneş\'in tam karşısında',
        $e < 260             => 'küçülen ay: dolunayın ardından',
        $e < 280             => 'son dördün: Ay Güneş\'le kare açıda',
        default              => 'küçülen hilal: Ay Güneş\'e yaklaşıyor, yeni ay yakın',
    };

    // Gunes-Ay acisi: model "Gunes Ay'la altigen" gibi acilari uydurmasin.
    $aci = min($e, 360 - $e);
    $gunesAy = match (true) {
        $aci <= 8              => 'kavuşum',
        abs($aci - 60) <= 6    => 'altıgen (uyumlu)',
        abs($aci - 90) <= 7    => 'kare (gerilimli)',
        abs($aci - 120) <= 7   => 'üçgen (uyumlu)',
        $aci >= 172            => 'karşıt',
        default                => 'yok (belirgin bir açı kurmuyorlar)',
    };

    $satirlar = [
        'Güneş ' . $adlar[gok_burc($k['gunes'])] . ' burcunda.',
        'Güneş ile Ay arasındaki açı: ' . $gunesAy . ' (aralarında yaklaşık ' . (int) round($aci) . ' derece).',
        'Ay ' . $adlar[$ayB] . ' burcunda; ' . $evre . ' (aydınlık %' . (int) round((1 - cos(deg2rad($e))) / 2 * 100) . ').',
        ($e < 12 || $e >= 348 ? 'Bugün yeni ay.' : 'Yeni aya yaklaşık ' . max(1, (int) round((360 - $e) / 12.19)) . ' gün var.')
        . ' ' . (abs($e - 180) < 12 ? 'Bugün dolunay.' : 'Dolunaya yaklaşık ' . max(1, (int) round(gok_norm(180 - $e) / 12.19)) . ' gün var.'),
    ];

    // Ay gun icinde burc degistiriyor mu? (saat saat bakiliyor)
    $once = gok_burc(gok_konum((int) strtotime($gun . ' 00:00 Europe/Istanbul'))['ay']);

    for ($saat = 1; $saat <= 24; $saat++) {
        $simdi = gok_burc(gok_konum((int) strtotime($gun . ' 00:00 Europe/Istanbul') + $saat * 3600)['ay']);

        if ($simdi !== $once) {
            $satirlar[] = 'Ay gün içinde (yaklaşık ' . sprintf('%02d:00', $saat) . ') ' . $adlar[$once] . ' burcundan ' . $adlar[$simdi] . ' burcuna geçiyor.';
            break;
        }
    }

    $satirlar[] = '';
    $satirlar[] = 'AY HER BURÇ İÇİN (burçtan sayılan ev ve Ay\'ın burca açısı):';

    foreach ($adlar as $i => $ad) {
        $ev   = ($ayB - $i + 12) % 12 + 1;
        $fark = ($ayB - $i + 12) % 12;
        $aci  = match (min($fark, 12 - $fark)) {
            0 => 'kavuşum (Ay burcunuzda)', 2 => 'altıgen, uyumlu', 4 => 'üçgen, uyumlu',
            3 => 'kare, hareketli', 6 => 'karşıt, dengelenmesi gereken', default => 'hafif',
        };
        $satirlar[] = $ad . ': Ay ' . $ev . '. evde (' . GOK_EVLER[$ev] . '); açı: ' . $aci . '.';
    }

    return implode("\n", $satirlar);
}

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

if ($durum['var'] && !$kuru && !$yeniden) {
    gunluk('Bugünün (' . $durum['bugun'] . ') burç yorumları zaten var.');
    exit(0);
}

$gunAdlari = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
$aylar     = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$t         = new DateTimeImmutable($durum['bugun']);
$tarih     = (int) $t->format('j') . ' ' . $aylar[(int) $t->format('n') - 1] . ' ' . $t->format('Y') . ' ' . $gunAdlari[(int) $t->format('N') - 1];

$gokyuzu = gokyuzu_metni($durum['bugun']);

/*
 * Model sirasi ve parcalama (29.10.2026). Uzun yorumlar istenince tek
 * istekte 12 burc Gemini'de 109 saniyede tek bayt donmeden zaman asimina
 * dustu ve hicbir yorum yazilmadi. Simdi derleme gibi Claude once (sonra
 * Luna, en son Gemini) ve alti burcluk iki istek: biri dusse digerinin
 * yorumlari yine siteye gidiyor.
 */
$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::haberModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->sureSiniri(150, [10], $bitis);

$anahtarlar = array_keys(BURC_ADLARI);
$sonuclar   = [];

foreach (array_chunk($anahtarlar, 6, true) as $parca) {
    $adlar = array_map(static fn (string $k): string => BURC_ADLARI[$k], array_values($parca));
    $istem = 'TARİH: ' . $tarih . "\n\nGÜNÜN GÖKYÜZÜ (hesaplandı, gerçek):\n" . $gokyuzu
        . "\n\nBU İSTEKTE YAZILACAK BURÇLAR (bu sırayla; sira 1'den başlar):\n"
        . implode("\n", array_map(static fn (int $i, string $ad): string => ($i + 1) . '. ' . $ad, array_keys($adlar), $adlar));

    try {
        $parcaSonuc = $yazar->semaliIstek(BURC_YONERGE, $istem, BURC_SEMA, 16000, count($adlar), 'genel');
    } catch (Throwable $e) {
        gunluk('Burç yorumları yazılamadı (' . implode(', ', $adlar) . '): ' . $e->getMessage());
        continue;
    }

    foreach (array_values($parca) as $i => $burc) {
        if (isset($parcaSonuc[$i])) {
            $sonuclar[array_search($burc, $anahtarlar, true)] = $parcaSonuc[$i];
        }
    }
}

ksort($sonuclar);
$yorumlar = [];

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

// Kayitta ornek: uslup denetlenebilsin.
if ($yorumlar !== []) {
    gunluk('Örnek (' . BURC_ADLARI[$yorumlar[0]['burc']] . '): ' . $yorumlar[0]['baslik'] . ' — ' . $yorumlar[0]['genel']);
}

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
