<?php
declare(strict_types=1);

/**
 * Valentra vergi haberleri ajanı.
 *
 * Akış:
 *   1. Siteden taranacak kaynakları ve konu gruplarını çeker
 *   2. Her kaynağın RSS/Atom beslemesini okur
 *   3. Anahtar kelime ön elemesiyle açıkça alakasız girdileri atar
 *   4. Kalan her aday için Gemini'ye sorar: vergiyle ilgili mi, ilgiliyse
 *      özgün haber metnini yazdırır ve bir konu grubuna atatır
 *   5. Kabul edilenleri siteye TASLAK olarak gönderir
 *
 * Hiçbir haber doğrudan yayımlanmaz; yayına alma yetkisi yalnızca
 * yönetim panelindedir.
 *
 * Çalıştırma:
 *   php ajan/topla.php [--kuru] [--saat=36] [--enfazla=25] [--grup=5]
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Besleme.php';
require_once __DIR__ . '/src/Sayfa.php';
require_once __DIR__ . '/src/Kazima.php';
require_once __DIR__ . '/src/Suzgec.php';
require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/src/KotaBittiException.php';
require_once __DIR__ . '/src/Yazar.php';

use Anthropic\Client;
use Valentra\Ajan\{Besleme, Http, Kazima, KotaBittiException, Sayfa, Site, Suzgec, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

/** Komut satırı seçenekleri */
$secenekler = getopt('', ['kuru', 'saat::', 'enfazla::', 'grup::']);
$kuruCalisma = isset($secenekler['kuru']);
$saat        = max(1, (int) ($secenekler['saat'] ?? 36));
$enFazlaAday = max(1, (int) ($secenekler['enfazla'] ?? 25));

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

$siteUrl   = ayar('VALENTRA_SITE_URL');
$ajanKey   = ayar('VALENTRA_AGENT_KEY');
$apiKey    = ayar('GEMINI_API_KEY');

gunluk('Valentra ajanı başlıyor' . ($kuruCalisma ? ' (KURU ÇALIŞMA — gönderim yok)' : ''));

$site    = new Site($siteUrl, $ajanKey);
$http    = new Http();
$besleme = new Besleme($http);
$sayfa   = new Sayfa($http);
$kazima  = new Kazima($http);
$suzgec  = new Suzgec();
$yazar   = new Yazar($apiKey);

// --- 1. Yapılandırma -------------------------------------------------------

try {
    $yapilandirma = $site->yapilandirma();
} catch (Throwable $e) {
    fwrite(STDERR, 'Yapılandırma alınamadı: ' . $e->getMessage() . "\n");
    exit(1);
}

$kaynaklar   = $yapilandirma['kaynaklar'];
$kategoriler = $yapilandirma['kategoriler'];

if ($kaynaklar === []) {
    gunluk('Aktif kaynak yok. Yönetim panelinden kaynak ekleyin.');
    exit(0);
}

gunluk(count($kaynaklar) . ' kaynak, ' . count($kategoriler) . ' konu grubu alındı.');

// --- 2 ve 3. Beslemeleri oku, ön elemeden geçir ---------------------------

$adaylar = [];

foreach ($kaynaklar as $kaynak) {
    $beslemeUrl = (string) ($kaynak['besleme_url'] ?? '');
    $listeUrl   = (string) ($kaynak['liste_url'] ?? '');

    $girdiler = [];
    $yontem   = '';

    // Once RSS: varsa ve haber veriyorsa en guvenilir yol.
    if ($beslemeUrl !== '') {
        $girdiler = $besleme->oku($beslemeUrl, $saat);
        $yontem   = 'besleme';
    }

    // Besleme yoksa ya da bos dondüyse duyuru sayfasini kaziyoruz.
    if ($girdiler === [] && $listeUrl !== '') {
        $girdiler = $kazima->oku($listeUrl, (string) ($kaynak['liste_secici'] ?? ''));
        $yontem   = 'kazıma';
    }

    if ($girdiler === []) {
        $neden = $beslemeUrl === '' && $listeUrl === ''
            ? 'adres tanımlı değil'
            : 'okunamadı veya yeni girdi yok';
        gunluk("  {$kaynak['ad']}: {$neden}");
        continue;
    }

    $gecen = 0;

    foreach ($girdiler as $girdi) {
        if (!$suzgec->gecer($girdi['baslik'], $girdi['ozet'])) {
            continue;
        }

        $adaylar[] = [
            'girdi'  => $girdi,
            'kaynak' => $kaynak,
            'puan'   => $suzgec->puan($girdi['baslik'], $girdi['ozet']),
        ];
        $gecen++;
    }

    gunluk("  {$kaynak['ad']} ({$yontem}): " . count($girdiler) . " girdi, {$gecen} aday");
}

if ($adaylar === []) {
    gunluk('Ön elemeden geçen aday yok. Çalışma tamamlandı.');
    exit(0);
}

// Güçlü sinyal verenler önce; bütçe sınırına takılırsa en iyileri işlensin.
usort($adaylar, static fn (array $a, array $b): int => $b['puan'] <=> $a['puan']);
$adaylar = array_slice($adaylar, 0, $enFazlaAday);

gunluk(count($adaylar) . ' aday modele gönderilecek.');

// --- 4. Gemini: sınıflandır ve yaz ----------------------------------------

$haberler  = [];
$elenen    = 0;
$hatali    = 0;
$kotaBitti = false;

// Adaylar gruplar halinde islenir: tek istekte birden cok haber.
// Ucretsiz katmanda istek sayisi sinirli oldugu icin her haber icin ayri
// cagri yapmak kotayi hemen tuketiyor.
$grupBoyu = max(1, (int) ($secenekler['grup'] ?? 5));
$gruplar  = array_chunk($adaylar, $grupBoyu);

gunluk(count($gruplar) . ' grup halinde işlenecek (grup başına en fazla ' . $grupBoyu . ' aday).');

foreach ($gruplar as $grupNo => $grup) {
    if ($grupNo > 0) {
        sleep(4);
    }

    // Sayfa metinleri once toplanir; model cagrisi tek seferde yapilir.
    $modelAdaylari = [];

    foreach ($grup as $aday) {
        $modelAdaylari[] = [
            'girdi'      => $aday['girdi'],
            'sayfaMetni' => $sayfa->metin($aday['girdi']['baglanti']),
            'kaynakAdi'  => (string) $aday['kaynak']['ad'],
            'kaynakTuru' => (string) ($aday['kaynak']['tur'] ?? 'rss'),
        ];
    }

    $no = $grupNo + 1;

    try {
        $sonuclar = $yazar->topluIsle($modelAdaylari, $kategoriler);
    } catch (KotaBittiException $e) {
        $kalan = count($adaylar) - ($grupNo * $grupBoyu);
        gunluk("  [grup {$no}] günlük model kotası doldu — kalan {$kalan} aday atlanıyor.");
        gunluk('  ' . $e->getMessage());
        $kotaBitti = true;
        break;
    } catch (Throwable $e) {
        $hatali += count($grup);
        gunluk("  [grup {$no}] HATA: " . $e->getMessage());
        continue;
    }

    if ($sonuclar === []) {
        $hatali += count($grup);
        gunluk("  [grup {$no}] yanıt çözümlenemedi.");
        continue;
    }

    foreach ($grup as $sira => $aday) {
        $girdi  = $aday['girdi'];
        $kaynak = $aday['kaynak'];
        $sonuc  = $sonuclar[$sira] ?? null;

        $kisaBaslik = mb_substr($girdi['baslik'], 0, 60, 'UTF-8');

        if ($sonuc === null) {
            $hatali++;
            gunluk("  [grup {$no}] yanıtta yok — {$kisaBaslik}");
            continue;
        }

        if (empty($sonuc['ilgili'])) {
            $elenen++;
            $neden = trim((string) ($sonuc['red_nedeni'] ?? 'belirtilmedi'));
            gunluk("  vergi dışı — {$kisaBaslik} ({$neden})");
            continue;
        }

        $etiketler = $sonuc['etiketler'] ?? [];

        $haberler[] = [
            'baslik'      => (string) $sonuc['baslik'],
            'ozet'        => (string) $sonuc['ozet'],
            'icerik'      => (string) $sonuc['icerik'],
            'etiketler'   => is_array($etiketler) ? implode(', ', $etiketler) : (string) $etiketler,
            'kategori'    => (string) ($sonuc['kategori'] ?? 'genel'),
            'kaynak_id'   => (int) $kaynak['id'],
            'kaynak_adi'  => (string) $kaynak['ad'],
            'kaynak_url'  => $girdi['baglanti'],
            'guven_skoru' => (int) ($sonuc['guven_skoru'] ?? 0),
            'ajan_notu'   => (string) ($sonuc['ajan_notu'] ?? ''),
        ];

        gunluk("  kabul (%{$sonuc['guven_skoru']}, {$sonuc['kategori']}) — {$sonuc['baslik']}");
    }
}

// --- 5. Siteye gönder ------------------------------------------------------

gunluk('---');
gunluk(count($haberler) . ' haber yazıldı, ' . $elenen . ' eleme, ' . $hatali . ' hata.');

if ($kotaBitti) {
    gunluk('Günlük model kotası dolduğu için çalışma erken bitti.');
    gunluk('Kota yenilendiğinde ajan kaldığı yerden devam eder; aynı haber');
    gunluk('iki kez eklenmez, kaynak adresi üzerinden kopya engeli var.');
}

if ($kuruCalisma) {
    gunluk('Kuru çalışma: gönderim yapılmadı.');

    foreach ($haberler as $haber) {
        echo PHP_EOL, '--- ', $haber['baslik'], ' ---', PHP_EOL;
        echo 'Grup: ', $haber['kategori'], ' | Güven: %', $haber['guven_skoru'], PHP_EOL;
        echo 'Not: ', $haber['ajan_notu'], PHP_EOL;
        echo $haber['icerik'], PHP_EOL;
    }

    exit(0);
}

if ($haberler === []) {
    gunluk('Gönderilecek haber yok.');
    exit(0);
}

try {
    $yanit = $site->gonder($haberler);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gönderim başarısız: ' . $e->getMessage() . "\n");
    exit(1);
}

gunluk(
    'Siteye gönderildi: ' . ($yanit['eklenen'] ?? 0) . ' yeni taslak, '
    . ($yanit['yinelenen'] ?? 0) . ' zaten vardı.'
);
gunluk('Haberler yönetim panelinde onay bekliyor.');
