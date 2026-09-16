<?php
declare(strict_types=1);

/**
 * Pratik bilgi toplayıcı.
 *
 * Siteden "hangi bilgiler toplanacak" listesini alir, her birinin
 * resmi kaynak sayfasini okur, degeri modele buldurur ve siteye ADAY
 * olarak gonderir. Yayindaki deger onay verilene kadar degismez.
 *
 * Neden ayri bir arac: bu is haber toplamaya benzemiyor. Haberde
 * kaynak tarayip ilgili olani secmek gerekiyor; burada ne aranacagi
 * bastan belli, is o sayfadaki dogru sayiyi bulmak. Degerler de nadiren
 * (yilda birkac kez) degisiyor, gunde on kez calismasi anlamsiz.
 *
 * Calistirma:
 *   php ajan/pratik.php [--kuru] [--grup=3]
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Sayfa.php';
require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/src/KotaBittiException.php';
require_once __DIR__ . '/src/Yazar.php';
require_once __DIR__ . '/src/DegerOkuyucu.php';

use Valentra\Ajan\{DegerOkuyucu, Http, KotaBittiException, Sayfa, Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['kuru', 'grup::']);
$kuru       = isset($secenekler['kuru']);
$grupBoyu   = max(1, (int) ($secenekler['grup'] ?? 3));

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

$site   = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));
$http   = new Http();
$sayfa  = new Sayfa($http);
$yazar  = new Yazar(ayar('GEMINI_API_KEY'));
$okuyucu = new DegerOkuyucu($yazar);

gunluk('Pratik bilgi toplayıcı başlıyor' . ($kuru ? ' (KURU ÇALIŞMA — gönderim yok)' : ''));

// --- 1. Toplanacak bilgileri al -------------------------------------------

try {
    $bilgiler = $site->pratikBilgiler();
} catch (Throwable $e) {
    fwrite(STDERR, 'Bilgi listesi alınamadı: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($bilgiler === []) {
    gunluk('Toplanacak bilgi tanımlı değil.');
    exit(0);
}

gunluk(count($bilgiler) . ' bilgi toplanacak.');

// --- 2. Kaynak sayfalarini oku --------------------------------------------

$adaylar = [];

foreach ($bilgiler as $bilgi) {
    $url   = (string) ($bilgi['kaynak_url'] ?? '');
    $metin = $url !== '' ? $sayfa->metin($url, 8000) : '';

    if (trim($metin) === '') {
        gunluk('  ' . $bilgi['baslik'] . ': kaynak sayfası okunamadı (' . $url . ')');
        continue;
    }

    $adaylar[] = ['bilgi' => $bilgi, 'sayfaMetni' => $metin];
    gunluk('  ' . $bilgi['baslik'] . ': sayfa okundu (' . mb_strlen($metin) . ' karakter)');
}

if ($adaylar === []) {
    gunluk('Hiçbir kaynak sayfası okunamadı.');
    exit(0);
}

// --- 3. Degerleri okut ------------------------------------------------------

$gruplar   = array_chunk($adaylar, $grupBoyu);
$sonuclar  = [];
$bulunmadi = 0;
$hatali    = 0;

gunluk(count($gruplar) . ' grup halinde işlenecek (grup başına en fazla ' . $grupBoyu . ').');

foreach ($gruplar as $grupNo => $grup) {
    if ($grupNo > 0) {
        sleep(4);
    }

    $no = $grupNo + 1;

    try {
        $cikti = $okuyucu->oku($grup);
    } catch (KotaBittiException $e) {
        gunluk("  [grup {$no}] günlük model kotası doldu, çalışma erken bitiyor.");
        break;
    } catch (Throwable $e) {
        $hatali += count($grup);
        gunluk("  [grup {$no}] HATA: " . $e->getMessage());
        continue;
    }

    foreach ($grup as $sira => $aday) {
        $bilgi  = $aday['bilgi'];
        $sonuc  = $cikti[$sira] ?? null;
        $baslik = (string) $bilgi['baslik'];

        if ($sonuc === null) {
            $hatali++;
            gunluk("  yanıtta yok — {$baslik}");
            continue;
        }

        if (empty($sonuc['bulundu']) || trim((string) ($sonuc['deger'] ?? '')) === '') {
            $bulunmadi++;
            $neden = trim((string) ($sonuc['not'] ?? 'belirtilmedi'));
            gunluk("  bulunamadı — {$baslik} ({$neden})");
            continue;
        }

        $guven = (int) ($sonuc['guven'] ?? 0);

        /*
         * Dusuk guvenli degeri hic gondermiyoruz.
         *
         * Haberde dusuk guven "editor baksin" demek; burada rakam
         * dogrudan hesaplamada kullaniliyor ve model kendi de emin
         * degilse o rakami onaya koymak, onaylayani yanlisa
         * yaklastirmaktan baska ise yaramaz.
         */
        if ($guven < 60) {
            $bulunmadi++;
            gunluk("  düşük güven (%{$guven}), gönderilmedi — {$baslik}");
            continue;
        }

        $sonuclar[] = [
            'anahtar' => (string) $bilgi['anahtar'],
            'deger'   => trim((string) $sonuc['deger']),
            'donem'   => trim((string) ($sonuc['donem'] ?? '')),
            'not'     => trim((string) ($sonuc['not'] ?? '')),
            'guven'   => $guven,
        ];

        gunluk("  okundu (%{$guven}) — {$baslik}: "
            . mb_substr(trim((string) $sonuc['deger']), 0, 60));
    }
}

// --- 4. Siteye gonder -------------------------------------------------------

gunluk('---');
gunluk(count($sonuclar) . ' değer okundu, ' . $bulunmadi . ' bulunamadı, ' . $hatali . ' hata.');

$kullanim = $yazar->kullanim();

if ($kullanim['istek'] > 0) {
    gunluk(sprintf(
        'Model kullanımı: %d istek, %s giriş + %s çıkış token.',
        $kullanim['istek'],
        number_format($kullanim['girdi']),
        number_format($kullanim['cikti'])
    ));
}

if ($kuru) {
    gunluk('Kuru çalışma: gönderim yapılmadı.');

    foreach ($sonuclar as $s) {
        echo PHP_EOL, '--- ', $s['anahtar'], ' ---', PHP_EOL;
        echo 'Dönem: ', $s['donem'], ' | Güven: %', $s['guven'], PHP_EOL;
        echo 'Not: ', $s['not'], PHP_EOL;
        echo $s['deger'], PHP_EOL;
    }

    exit(0);
}

if ($sonuclar === []) {
    gunluk('Gönderilecek değer yok.');
    exit(0);
}

try {
    $yanit = $site->pratikGonder($sonuclar);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gönderim başarısız: ' . $e->getMessage() . "\n");
    fwrite(STDERR, json_encode($sonuclar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

gunluk(
    'Siteye gönderildi: ' . ($yanit['aday'] ?? 0) . ' değer onay bekliyor, '
    . ($yanit['degismedi'] ?? 0) . ' değişmemiş.'
);
gunluk('Hiçbir değer onaysız yayımlanmadı.');
