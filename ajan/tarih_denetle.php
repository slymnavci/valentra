<?php
declare(strict_types=1);

/**
 * Son iki gunde toplanan haberlerin yayin tarihini kaynak sayfadan denetler.
 *
 * Site sahibinin istegi (23.10.2026): "haberlerin tarihleri cok onemli,
 * onlari mutlaka kontrol edelim". Beslemedeki tarih her zaman haberin
 * ilk yayin ani degil (bir gunluk faiz karari haberi yeni tarihle geldi
 * ve ana sayfada 2. oldu). Her haberin sayfasi bir kez aciliyor; sayfanin
 * kendi yayin zamani (Sayfa::yayinZamani) kayitli zamandan erkense site
 * onu yaziyor (api/tarih-denetim.php). Ana sayfa yalnizca kaynakta bugun
 * yayimlanmis haberleri gosterdigi icin eski haber oradan duser.
 *
 *   php ajan/tarih_denetle.php
 *
 * Ortam: VALENTRA_SITE_URL, VALENTRA_AGENT_KEY.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Indirici.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Kodlama.php';
require_once __DIR__ . '/src/Sayfa.php';
require_once __DIR__ . '/src/Site.php';

use Valentra\Ajan\{Http, Kodlama, Sayfa, Site};

date_default_timezone_set('Europe/Istanbul');

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
}

$siteUrl = trim((string) getenv('VALENTRA_SITE_URL'));
$anahtar = trim((string) getenv('VALENTRA_AGENT_KEY'));

if ($siteUrl === '' || $anahtar === '') {
    fwrite(STDERR, "HATA: VALENTRA_SITE_URL ve VALENTRA_AGENT_KEY gerekli.\n");
    exit(1);
}

$site = new Site($siteUrl, $anahtar);
$http = new Http(10);

try {
    $haberler = $site->tarihDenetimListesi();
} catch (Throwable $e) {
    gunluk('Denetim listesi alınamadı: ' . $e->getMessage());
    exit(0);
}

if ($haberler === []) {
    gunluk('Tarihi denetlenecek yeni haber yok.');
    exit(0);
}

$tarihler   = [];
$bulunamadi = 0;
$baslangic  = time();

foreach ($haberler as $h) {
    // Haber toplamayi geciktirmesin: en fazla 3 dakika.
    if (time() - $baslangic > 180) {
        gunluk('  süre doldu; kalanlar sonraki çalışmada.');
        break;
    }

    $html  = $http->indir((string) $h['url'], 3_000_000);
    $zaman = $html !== null ? Sayfa::yayinZamani(Kodlama::utf8($html)) : null;

    if ($html === null) {
        // Sayfa acilamadi: isaretlenmiyor, sonraki calismada yeniden.
        gunluk('  açılamadı — ' . $h['url']);
        continue;
    }

    if ($zaman === null) {
        $bulunamadi++;
    }

    $tarihler[] = ['id' => (int) $h['id'], 'zaman' => $zaman];
}

$duzeltilen = 0;

if ($tarihler !== []) {
    try {
        $duzeltilen = $site->tarihDenetimGonder($tarihler);
    } catch (Throwable $e) {
        gunluk('Sonuç gönderilemedi: ' . $e->getMessage());
    }
}

$ozet = 'Tarih denetimi: ' . count($tarihler) . ' haber denetlendi, ' . $duzeltilen
    . ' tanesinin tarihi kaynak sayfaya göre eskiye düzeltildi'
    . ($bulunamadi > 0 ? ', ' . $bulunamadi . ' sayfada tarih bulunamadı' : '') . '.';
gunluk($ozet);

if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Tarih denetimi::' . $ozet . PHP_EOL;
}
