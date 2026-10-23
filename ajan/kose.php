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
require_once __DIR__ . '/src/ModelErisimYokException.php';
require_once __DIR__ . '/src/SemaliIstemci.php';
require_once __DIR__ . '/src/Yazar.php';
require_once __DIR__ . '/src/KoseYazari.php';
require_once __DIR__ . '/src/Bildirim.php';

use Valentra\Ajan\{Bildirim, Depo, Http, KoseYazari, KotaBittiException, ModelErisimYokException, Sayfa, Site, Yazar};

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
    $GLOBALS['koseGunlugu'][] = $mesaj;
}

/*
 * Calisma ozeti GitHub'da da gorunsun (23.10.2026): kose adimi bir kez
 * 2 saniyede yazisiz bitti ve sebebi yalnizca okunamayan gunlukteydi.
 * Cikis noktasi ne olursa olsun son satirlar "notice" olarak yaziliyor:
 * hangi yazar yazdi, hangisi neden yazmadi.
 */
$GLOBALS['koseGunlugu'] = [];

register_shutdown_function(static function (): void {
    if (getenv('GITHUB_ACTIONS') !== 'true' || $GLOBALS['koseGunlugu'] === []) {
        return;
    }

    $satirlar = array_slice($GLOBALS['koseGunlugu'], -25);
    $metin    = 'Köşe yazıları:' . "\n" . implode("\n", array_map(static fn (string $s): string => mb_substr($s, 0, 300), $satirlar));

    echo '::notice title=Valentra köşe yazıları::'
        . str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $metin) . PHP_EOL;
});

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
        Bildirim::sorun('Köşe yazısı ajanı siteye ulaşamadı; ' . count($bekleyen)
            . ' yazı bekletiliyor (kaybolmadı). Teknik ayrıntı: ' . $e->getMessage());
        exit(0);
    }
}

// --- 1. Malzeme ---------------------------------------------------------------

try {
    $malzeme = $site->koseMalzeme($saat);
} catch (Throwable $e) {
    // Model cagrisindan ONCE: site yoksa hicbir sey harcanmadan cikiliyor.
    gunluk('Malzeme alınamadı: ' . $e->getMessage());

    /*
     * Cikis kodu 0: calismayi kirmiziya ceviren is akisinin son adimi.
     * Burada 1 donmek sonraki adimlari ve ajan deposunun kaydini
     * etkiliyordu; sorun yine de bildiriliyor.
     */
    Bildirim::sorun('Köşe yazısı ajanı siteye ulaşamadı, bu çalışmada yazı yazılmadı. '
        . 'Teknik ayrıntı: ' . $e->getMessage());
    exit(0);
}

$haberler = $malzeme['haberler'];

/*
 * YAZARLAR (site sahibinin istegi, 23.10.2026): her gun her yazar kendi
 * alanindan TEK konu secip kendi uslubuyla bir yazi yaziyor (en fazla 7
 * yazi). Site yazar listesini ve bugun yazanlari veriyor; eski site
 * surumu vermiyorsa tek bir "Valentra Yorumu" yazari varsayiliyor.
 * Valentra Yorumu en sonda: diger yazarlarin almadigi en onemli konu.
 */
$yazarlar = $malzeme['yazarlar'] !== []
    ? $malzeme['yazarlar']
    : [['anahtar' => 'valentra', 'ad' => 'Valentra Yorumu', 'alan' => 'Günün en önemli konusu',
        'kategoriler' => [], 'uslup' => '', 'bugun_yazdi' => $malzeme['bugun_sayisi'] > 0]];

$bekleyenYazarlar = array_values(array_filter($yazarlar, static fn (array $y): bool => empty($y['bugun_yazdi'])));

if ($bekleyenYazarlar === []) {
    gunluk('Bugün bütün yazarların yazısı var.');
    exit(0);
}

if (count($haberler) < 2) {
    gunluk('Son ' . $saat . ' saatte yazıya malzeme olacak kadar haber yok (' . count($haberler) . ').');
    exit(0);
}

$yazarAdlari = [];

foreach ($yazarlar as $y) {
    $yazarAdlari[(string) $y['anahtar']] = (string) $y['ad'];
}

// Onceki yazilar yazar adiyla: yazar kendi konusunu tekrarlamasin.
$onceki = array_map(static fn (array $o): array => $o + ['yazar_adi' => $yazarAdlari[$o['yazar'] ?? ''] ?? ''], $malzeme['onceki']);

gunluk(count($haberler) . ' haber malzeme; bugün yazacak ' . count($bekleyenYazarlar) . ' yazar: '
     . implode(', ', array_map(static fn (array $y): string => (string) $y['ad'], $bekleyenYazarlar)) . '.');

// Kose yazisi haberden ayri, daha guclu modelle (GEMINI_KOSE_MODEL; bkz. Yazar).
$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::koseModeli());
gunluk('Model: ' . $yazar->model() . ' (köşe yazısı; GEMINI_KOSE_MODEL ile değiştirilebilir).');
$kose  = new KoseYazari($yazar);
$sayfa = new Sayfa(new Http());

$yazilar    = [];
$kullanilan = [];   // bu calismada baska yazarin konusu olmus haberler (indis)
$sira       = $malzeme['bugun_sayisi'];

foreach ($bekleyenYazarlar as $kalem) {
    $ad = (string) $kalem['ad'];
    $kategoriler = array_map('strval', (array) ($kalem['kategoriler'] ?? []));

    // Yazarin alanindaki, baska yazara verilmemis haberler (Valentra: hepsi).
    $alt = [];

    foreach ($haberler as $indis => $h) {
        if (isset($kullanilan[$indis])) {
            continue;
        }

        if ($kategoriler !== [] && !in_array((string) ($h['kategori_slug'] ?? ''), $kategoriler, true)) {
            continue;
        }

        $alt[] = $indis;
    }

    if ($alt === []) {
        gunluk("— {$ad}: alanında bugün yazıya malzeme olacak haber yok.");
        continue;
    }

    $altHaberler = array_map(static fn (int $i): array => $haberler[$i], $alt);

    // --- Konu ---
    try {
        $maddeler = $kose->gundemSec($altHaberler, $onceki, 1, $kalem);
    } catch (ModelErisimYokException $e) {
        Bildirim::sorun($e->getMessage());
        break;
    } catch (KotaBittiException $e) {
        gunluk('Günlük model kotası dolu: ' . $e->getMessage());
        break;
    } catch (Throwable $e) {
        gunluk("— {$ad}: konu seçilemedi: " . $e->getMessage());
        continue;
    }

    $madde = $maddeler[0] ?? null;

    if ($madde === null) {
        gunluk("— {$ad}: model yazıya değer bir konu seçmedi.");
        continue;
    }

    gunluk("— {$ad}: " . $madde['gundem'] . ' (' . count($madde['haberler']) . ' haber) — ' . $madde['aci']);

    /*
     * Kaynak sayfalar: haberin metni kaynagin ozeti; yazinin "detay"
     * vaat etmesi icin asil sayfa da okunuyor. Once site uzerinden (Turk
     * kamu siteleri GitHub IP'lerini engelliyor), olmazsa dogrudan.
     * Alinamazsa yazi haber metniyle yaziliyor.
     */
    $kaynakMetinleri = [];

    foreach (array_slice($madde['haberler'], 0, KAYNAK_SAYFA_TAVANI) as $altIndis) {
        $url = (string) ($altHaberler[$altIndis]['kaynak_url'] ?? '');

        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }

        $metin = $site->sayfaGetir($url) ?? '';

        if (trim($metin) === '' && !$site->cekimserMi()) {
            $metin = $sayfa->metin($url, 12000);
        }

        if (trim($metin) !== '') {
            $kaynakMetinleri[$altIndis] = $metin;
        }
    }

    // --- Yazi ---
    try {
        $yazi = $kose->yaz($madde, $altHaberler, $kaynakMetinleri, $kalem);
    } catch (ModelErisimYokException $e) {
        Bildirim::sorun($e->getMessage());
        break;
    } catch (KotaBittiException $e) {
        gunluk('Günlük model kotası doldu; kalan yazarlar atlanıyor.');
        break;
    } catch (Throwable $e) {
        gunluk("  \"{$madde['gundem']}\" yazılamadı: " . $e->getMessage());
        continue;
    }

    if ($yazi === null) {
        gunluk("  \"{$madde['gundem']}\" için geçerli yazı çıkmadı (boş ya da çok kısa).");
        continue;
    }

    $idler = [];

    foreach ($madde['haberler'] as $altIndis) {
        $kullanilan[$alt[$altIndis]] = true;
        $idler[] = (int) ($altHaberler[$altIndis]['id'] ?? 0);
    }

    $yazilar[] = [
        'gun'          => $malzeme['bugun'],
        'sira'         => ++$sira,
        'gundem'       => $madde['gundem'],
        'yazar'        => (string) $kalem['anahtar'],
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
    '%d yazı gönderildi (%d yinelenen, %d hata). ' . (string) ($sonuc['not'] ?? ''),
    (int) ($sonuc['eklenen'] ?? 0),
    (int) ($sonuc['yinelenen'] ?? 0),
    count((array) ($sonuc['hatalar'] ?? []))
));

foreach ((array) ($sonuc['hatalar'] ?? []) as $hata) {
    gunluk('  hata: ' . json_encode($hata, JSON_UNESCAPED_UNICODE));
}
