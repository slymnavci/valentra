<?php
declare(strict_types=1);

/**
 * V Network yapay zeka ve kurum hesaplari ajani (31.10.2026).
 *
 * Hesaplar ajan/veri/topluluk_hesaplari.json'da (100 YZ hesabi: 50 kadin,
 * 50 erkek kurgusal kisilik; 5 Valentra kurum hesabi). Sitede YZ/Resmi
 * rozetiyle isaretliler (bkz. includes/topluluk.php).
 *
 * Her calismada: eksik hesaplar acilir, gunluk tavani dolmamis hesaplardan
 * birkaci secilir, model her biri icin kisa yorum/paylasim/begeni yazar,
 * site gercek uyelerin kurallariyla uygular.
 *
 * Calistirma:
 *   php ajan/topluluk.php [--kuru] [--sure=4] [--hesap=12]
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
require_once __DIR__ . '/src/Topluluk.php';
require_once __DIR__ . '/src/Bildirim.php';

use Valentra\Ajan\{Bildirim, KotaBittiException, ModelErisimYokException, Site, Topluluk, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['kuru', 'sure::', 'hesap::']);
$kuru       = isset($secenekler['kuru']);
$bitis      = time() + max(2, (int) ($secenekler['sure'] ?? 4)) * 60;
// Calisma basina YZ hesabi (iki saatte bir: gunde ~12 calisma).
$hesapAdedi = max(2, min(20, (int) ($secenekler['hesap'] ?? 10)));

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

$dosya = json_decode((string) file_get_contents(__DIR__ . '/veri/topluluk_hesaplari.json'), true);
$kisiler = is_array($dosya['hesaplar'] ?? null) ? $dosya['hesaplar'] : [];

if ($kisiler === []) {
    gunluk('Kişilik dosyası okunamadı.');
    exit(1);
}

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));

try {
    $malzeme = $site->toplulukMalzeme();

    // Sitede eksik hesap varsa ac (ilk calisma ya da dosyaya yeni kisi eklendi).
    if (!$kuru && count($malzeme['hesaplar']) < count($kisiler)) {
        $esit = $site->toplulukGonder(['hesaplar' => array_map(
            static fn (array $k): array => ['rumuz' => $k['rumuz'], 'tur' => $k['tur'], 'hakkinda' => $k['hakkinda']],
            $kisiler
        )]);
        gunluk(sprintf('Hesaplar eşitlendi: %d yeni, %d güncel, %d hata.',
            (int) ($esit['eklenen'] ?? 0), (int) ($esit['guncellenen'] ?? 0), count((array) ($esit['hatalar'] ?? []))));

        foreach (array_slice((array) ($esit['hatalar'] ?? []), 0, 5) as $h) {
            gunluk('  ' . $h);
        }

        $malzeme = $site->toplulukMalzeme();
    }
} catch (Throwable $e) {
    gunluk('Malzeme alınamadı: ' . $e->getMessage());
    Bildirim::uyari('Topluluk ajanı siteye ulaşamadı; bu çalışmada paylaşım yapılmadı. Teknik ayrıntı: ' . $e->getMessage());
    exit(0);
}

if ($malzeme['haberler'] === []) {
    gunluk('Son saatlerde yayımlanmış haber yok; topluluk eylemi yapılmadı.');
    exit(0);
}

// Gunluk tavani dolmamis hesaplar; YZ'den rastgele, kurumdan bir tane (sirayla).
$tavan  = (int) ($malzeme['tavan']['gunluk'] ?? 8);
$bugun  = [];

foreach ($malzeme['hesaplar'] as $h) {
    $bugun[(string) $h['rumuz']] = (int) $h['bugun'];
}

$uygun = static fn (string $tur): array => array_values(array_filter(
    $kisiler,
    static fn (array $k): bool => $k['tur'] === $tur && isset($bugun[$k['rumuz']]) && $bugun[$k['rumuz']] < $tavan - 1
));

$yzler = $uygun('yz');
shuffle($yzler);
$kurumlar = $uygun('kurum');
$secilen  = array_slice($yzler, 0, $hesapAdedi);

if ($kurumlar !== []) {
    $secilen[] = $kurumlar[(int) date('G') % count($kurumlar)];
}

if ($secilen === []) {
    gunluk('Bütün hesapların bugünkü tavanı dolu.');
    exit(0);
}

$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::haberModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->sureSiniri(150, [10], $bitis);

gunluk(sprintf('%d hesap seçildi (%s); %d haber, %d paylaşım.', count($secilen),
    implode(', ', array_column($secilen, 'rumuz')), count($malzeme['haberler']), count($malzeme['paylasimlar'])));

try {
    $eylemler = (new Topluluk($yazar))->eylemleriYaz($secilen, $malzeme['haberler'], $malzeme['paylasimlar']);
} catch (ModelErisimYokException | KotaBittiException $e) {
    gunluk('Model kullanılamıyor: ' . $e->getMessage());
    exit(0);
} catch (Throwable $e) {
    gunluk('Eylemler yazılamadı: ' . $e->getMessage());
    exit(0);
}

gunluk(sprintf('%d eylem yazıldı (model: %s).', count($eylemler), $yazar->sonYanitlayan()));

if ($kuru || $eylemler === []) {
    foreach ($eylemler as $e) {
        echo '- ' . $e['hesap'] . ' [' . $e['tur'] . ($e['hedef'] !== '' ? ' ' . $e['hedef'] : '') . '] ' . $e['metin'] . PHP_EOL;
    }

    exit(0);
}

// Ayni hesabin iki yorumu arka arkaya gitmesin (sitede siklik siniri):
// once herkesin ilk eylemi, sonra ikincisi.
$siradaki = [];

foreach ($eylemler as $e) {
    $siradaki[$e['hesap']][] = $e;
}

$dalgalar = [];

foreach ($siradaki as $liste) {
    foreach ($liste as $i => $e) {
        $dalgalar[$i][] = $e;
    }
}

$toplam = ['paylasim' => 0, 'yorum' => 0, 'begeni' => 0];
$hatalar = [];

foreach ($dalgalar as $i => $dalga) {
    if ($i > 0) {
        // Sitedeki paylasim araligi 30 sn; ikinci dalga ondan sonra.
        sleep(35);
    }

    try {
        $sonuc = $site->toplulukGonder(['eylemler' => $dalga]);
    } catch (Throwable $e) {
        gunluk('Gönderim başarısız: ' . $e->getMessage());
        break;
    }

    foreach ((array) ($sonuc['yapilan'] ?? []) as $tur => $n) {
        $toplam[$tur] = ($toplam[$tur] ?? 0) + (int) $n;
    }

    $hatalar = array_merge($hatalar, (array) ($sonuc['hatalar'] ?? []));
}

gunluk(sprintf('Sitede: %d paylaşım, %d yorum, %d beğeni; %d eylem yapılamadı.',
    $toplam['paylasim'], $toplam['yorum'], $toplam['begeni'], count($hatalar)));

foreach (array_slice($hatalar, 0, 8) as $h) {
    gunluk('  ' . $h);
}
