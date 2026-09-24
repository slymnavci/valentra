<?php
declare(strict_types=1);

/**
 * "Valentra Diyor ki…" köşe yazısı ajanı.
 *
 * Siteden son saatlerin haberlerini alir, gunun 3-4 gundem maddesini
 * sectirir, her madde icin ayri bir yazi yazdirir ve yazilari siteye
 * TASLAK olarak gonderir. Yayina alma panelde.
 *
 * Gunde bir tur: zamanli calismada bugunun yazisi zaten varsa ya da saat
 * henuz erkense (gunun gundemi ogleden once oturmuyor) hicbir sey
 * yapmadan cikiyor. GitHub zamanli calismalarin bir kismini dusurdugu
 * icin tek bir saate baglanmadi: ogleden sonraki ilk calisma yaziyor,
 * o dustuyse sonraki.
 *
 * Elle calistirmada saat siniri yok; bugun icin eksik kalan kadar
 * (gunluk tavana kadar) yazi yazar. Reddedilen yazilar sayilmiyor, yani
 * panelde reddedip yeniden calistirmak yeni yazi uretir.
 *
 * Calistirma:
 *   php ajan/kose.php [--zamanli] [--kuru] [--saat=36] [--en-erken=12]
 */

// Yalnizca komut satirindan: sunucuya yuklenen kopya tarayicidan calismasin
// (ajan/.htaccess de kapatiyor; bu ikinci kilit).
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
require_once __DIR__ . '/src/Depo.php';
require_once __DIR__ . '/src/KotaBittiException.php';
require_once __DIR__ . '/src/SemaliIstemci.php';
require_once __DIR__ . '/src/Yazar.php';
require_once __DIR__ . '/src/KoseYazari.php';

use Valentra\Ajan\{Depo, Http, KoseYazari, KotaBittiException, Sayfa, Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['zamanli', 'kuru', 'saat::', 'en-erken::']);
$zamanli    = isset($secenekler['zamanli']);
$kuru       = isset($secenekler['kuru']);
$saat       = max(6, (int) ($secenekler['saat'] ?? 36));
$enErken    = max(0, min(23, (int) ($secenekler['en-erken'] ?? 12)));

/** Madde başına okunacak en fazla kaynak sayfa. */
const KAYNAK_SAYFA_TAVANI = 2;

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

if ($zamanli && (int) date('G') < $enErken) {
    gunluk("Saat {$enErken}:00'dan önce köşe yazısı yazılmıyor; günün gündemi henüz oturmadı.");
    exit(0);
}

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));
$depo = new Depo(__DIR__ . '/onbellek');

gunluk('Köşe yazısı ajanı başlıyor' . ($kuru ? ' (KURU ÇALIŞMA — gönderim yok)' : ''));

// --- 0. Onceki calismadan gonderilemeyen yazilar -----------------------------

/*
 * Model cagrisi yapilmis, yazi yazilmis ama site o dakikalarda
 * ulasilamamissa yazi kaybolmasin: depoda bekliyor, burada gonderiliyor.
 * Parmak izi (gun + baslik) ayni yazinin iki kez girmesini engelliyor.
 */
$bekleyen = $depo->oku('bekleyen_kose', 3);

if (!$kuru && is_array($bekleyen) && $bekleyen !== []) {
    try {
        $sonuc = $site->koseGonder(array_values($bekleyen));
        $depo->sil('bekleyen_kose');
        gunluk(count($bekleyen) . ' bekleyen yazı gönderildi: ' . (int) ($sonuc['eklenen'] ?? 0) . ' eklendi.');
    } catch (Throwable $e) {
        gunluk('Bekleyen yazılar yine gönderilemedi: ' . $e->getMessage());
        exit(0);
    }
}

// --- 1. Malzeme ---------------------------------------------------------------

try {
    $malzeme = $site->koseMalzeme($saat);
} catch (Throwable $e) {
    // Model cagrisindan ONCE: site yoksa hicbir sey harcanmadan cikiliyor.
    gunluk('Malzeme alınamadı: ' . $e->getMessage());
    exit($zamanli ? 0 : 1);
}

$tavan  = max(1, $malzeme['gunluk_tavan']);
$mevcut = $malzeme['bugun_sayisi'];

if ($zamanli && $mevcut > 0) {
    gunluk("Bugünün yazıları zaten var ({$mevcut}).");
    exit(0);
}

$hedef = $tavan - $mevcut;

if ($hedef < 1) {
    gunluk("Bugün için {$mevcut} yazı var; günlük tavan ({$tavan}) dolu. "
         . 'Yenisini istiyorsanız panelde birini reddedin.');
    exit(0);
}

$haberler = $malzeme['haberler'];

if (count($haberler) < 2) {
    gunluk('Son ' . $saat . ' saatte yazıya malzeme olacak kadar haber yok (' . count($haberler) . ').');
    exit(0);
}

gunluk(count($haberler) . ' haber malzeme, ' . count($malzeme['onceki']) . ' önceki yazı; en fazla ' . $hedef . ' yazı.');

$yazar = new Yazar(ayar('GEMINI_API_KEY'));
$kose  = new KoseYazari($yazar);
$sayfa = new Sayfa(new Http());

// --- 2. Gundem maddeleri ------------------------------------------------------

try {
    $maddeler = $kose->gundemSec($haberler, $malzeme['onceki'], $hedef);
} catch (KotaBittiException $e) {
    gunluk('Günlük model kotası dolu: ' . $e->getMessage());
    exit(0);
} catch (Throwable $e) {
    gunluk('Gündem seçilemedi: ' . $e->getMessage());
    exit(1);
}

if ($maddeler === []) {
    gunluk('Model yazıya değer bir gündem maddesi seçmedi.');
    exit(0);
}

foreach ($maddeler as $i => $m) {
    gunluk('  ' . ($i + 1) . '. ' . $m['gundem'] . ' (' . count($m['haberler']) . ' haber) — ' . $m['aci']);
}

// --- 3. Her madde icin yazi -----------------------------------------------------

$yazilar = [];

foreach ($maddeler as $i => $madde) {
    /*
     * Kaynak sayfalar: haberin metni kaynagin ozeti; yazinin "detay"
     * vaat etmesi icin asil sayfa da okunuyor. Once site uzerinden (Turk
     * kamu siteleri GitHub IP'lerini engelliyor), olmazsa dogrudan.
     * Alinamazsa yazi haber metniyle yaziliyor.
     */
    $kaynakMetinleri = [];

    foreach (array_slice($madde['haberler'], 0, KAYNAK_SAYFA_TAVANI) as $indis) {
        $url = (string) ($haberler[$indis]['kaynak_url'] ?? '');

        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }

        $metin = $site->sayfaGetir($url) ?? '';

        if (trim($metin) === '' && !$site->cekimserMi()) {
            $metin = $sayfa->metin($url, 12000);
        }

        if (trim($metin) !== '') {
            $kaynakMetinleri[$indis] = $metin;
        }
    }

    try {
        $yazi = $kose->yaz($madde, $haberler, $kaynakMetinleri);
    } catch (KotaBittiException $e) {
        gunluk('Günlük model kotası doldu; kalan maddeler atlanıyor.');
        break;
    } catch (Throwable $e) {
        gunluk('  "' . $madde['gundem'] . '" yazılamadı: ' . $e->getMessage());
        continue;
    }

    if ($yazi === null) {
        gunluk('  "' . $madde['gundem'] . '" için geçerli yazı çıkmadı (boş ya da çok kısa).');
        continue;
    }

    $idler = [];

    foreach ($madde['haberler'] as $indis) {
        $idler[] = (int) ($haberler[$indis]['id'] ?? 0);
    }

    $yazilar[] = [
        'gun'          => $malzeme['bugun'],
        'sira'         => $mevcut + count($yazilar) + 1,
        'gundem'       => $madde['gundem'],
        'baslik'       => $yazi['baslik'],
        'ozet'         => $yazi['ozet'],
        'icerik'       => $yazi['icerik'],
        'haber_idleri' => array_values(array_filter($idler)),
        'ajan_notu'    => $yazi['editor_notu'],
    ];

    gunluk('  yazıldı: ' . $yazi['baslik'] . ' (' . count(preg_split('/\s+/u', $yazi['icerik']) ?: []) . ' kelime, '
         . count($kaynakMetinleri) . ' kaynak sayfa)');
}

$kullanim = $yazar->kullanim();
gunluk(sprintf(
    'Model kullanımı: %d istek, %s giriş + %s çıkış token.',
    $kullanim['istek'],
    number_format($kullanim['girdi']),
    number_format($kullanim['cikti'])
));

if ($yazilar === []) {
    gunluk('Gönderilecek yazı yok.');
    exit(0);
}

if ($kuru) {
    foreach ($yazilar as $y) {
        echo "\n==== " . $y['baslik'] . " ====\n" . $y['ozet'] . "\n\n" . $y['icerik']
           . "\n\n[Editör notu] " . $y['ajan_notu'] . "\n";
    }

    exit(0);
}

// --- 4. Gonder ------------------------------------------------------------------

try {
    $sonuc = $site->koseGonder($yazilar);
} catch (Throwable $e) {
    gunluk('Gönderim başarısız: ' . $e->getMessage());

    if ($depo->yaz('bekleyen_kose', $yazilar)) {
        gunluk(count($yazilar) . ' yazı saklandı; siteye ulaşılabilen ilk çalışmada gönderilecek.');
    }

    exit(0);
}

gunluk(sprintf(
    '%d yazı onay bekliyor (%d yinelenen, %d hata).',
    (int) ($sonuc['eklenen'] ?? 0),
    (int) ($sonuc['yinelenen'] ?? 0),
    count((array) ($sonuc['hatalar'] ?? []))
));

foreach ((array) ($sonuc['hatalar'] ?? []) as $hata) {
    gunluk('  hata: ' . json_encode($hata, JSON_UNESCAPED_UNICODE));
}
