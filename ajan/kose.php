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
 *   php ajan/kose.php [--zamanli] [--kuru] [--saat=36] [--en-erken=12] [--sure=18] [--en-fazla=4]
 *
 * --sure: dakika cinsinden calisma butcesi (bkz. SURE BUTCESI).
 * --en-fazla: bir calismada yazacak en fazla yazar (bkz. ASAMALI YAZIM).
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

$secenekler = getopt('', ['zamanli', 'kuru', 'saat::', 'en-erken::', 'sure::', 'en-fazla::']);
$zamanli    = isset($secenekler['zamanli']);
$kuru       = isset($secenekler['kuru']);
$saat       = max(6, (int) ($secenekler['saat'] ?? 36));
$enErken    = max(0, min(23, (int) ($secenekler['en-erken'] ?? 12)));

/*
 * ASAMALI YAZIM (26.10.2026, site sahibinin istegi): 12 yazar tek
 * calismaya sigmiyordu; haber toplamadan sonra kalan sureyle isin 45
 * dakikalik siniri yazilari kesiyordu. Bir calismada en fazla bu kadar
 * yazar yaziyor; kalanlar bugun yazmamis sayildigi icin sonraki
 * calismalarda (iki saatte bir) siradakiler yaziyor: 12 yazar 3 asamada.
 */
$enFazla    = max(1, (int) ($secenekler['en-fazla'] ?? 4));

/*
 * SURE BUTCESI (26.10.2026).
 *
 * Kose adimi bir calismada 44 dakika surdu, isin 45 dakikalik sinirina
 * takilip iptal edildi ve yazilan yazilarin hicbiri siteye gitmedi
 * (yazilar en sonda toplu gonderiliyordu). Simdi:
 *   - butce dolunca yeni yazara gecilmiyor; kalanlar bugun yazmamis
 *     sayildigi icin sonraki calismada yaziyor,
 *   - model istegi butceyi asamiyor (Yazar::sureSiniri),
 *   - her yazi yazildigi anda gonderiliyor.
 */
$baslangic = time();
$bitis     = $baslangic + max(5, (int) ($secenekler['sure'] ?? 18)) * 60;

/** Bir yazara baslamak icin kalmasi gereken en az sure (saniye): konu + yazi. */
const YAZAR_ASGARI_SURE = 4 * 60;

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

/**
 * Haber, yazarın sözcük süzgecinden geçiyor mu?
 *
 * $gerekli bos degilse en az biri, $haric'ten hicbiri baslikta ya da
 * ozette gecmeli (buyuk-kucuk harf farksiz, Turkce harflerle).
 *
 * @param list<string> $gerekli
 * @param list<string> $haric
 */
function kelime_uyar(array $haber, array $gerekli, array $haric): bool
{
    if ($gerekli === [] && $haric === []) {
        return true;
    }

    $metin = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], (string) ($haber['baslik'] ?? '') . ' ' . (string) ($haber['ozet'] ?? '')), 'UTF-8');

    foreach ($haric as $k) {
        if ($k !== '' && str_contains($metin, mb_strtolower((string) $k, 'UTF-8'))) {
            return false;
        }
    }

    if ($gerekli === []) {
        return true;
    }

    foreach ($gerekli as $k) {
        if ($k !== '' && str_contains($metin, mb_strtolower((string) $k, 'UTF-8'))) {
            return true;
        }
    }

    return false;
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
    // Uyari (24.10.2026): model cagrisi yapilmadi, hicbir sey kaybolmadi;
    // sonraki calisma yaziyor. Hosting gunde birkac kez GitHub'dan gelen
    // baglantiyi 15-20 dakika kabul etmiyor (bkz. topla.php gonderim).
    Bildirim::uyari('Köşe yazısı ajanı siteye ulaşamadı, bu çalışmada yazı yazılmadı; sonraki çalışmada yazılacak. '
        . 'Teknik ayrıntı: ' . $e->getMessage());
    exit(0);
}

$haberler = $malzeme['haberler'];

/*
 * YAZARLAR (site sahibinin istegi, 23.10.2026): her gun her yazar kendi
 * alanindan TEK konu secip kendi uslubuyla bir yazi yaziyor (en fazla 12
 * yazi). Site yazar listesini ve bugun yazanlari veriyor; eski site
 * surumu vermiyorsa tek bir "Valentra Yorumu" yazari varsayiliyor.
 * Valentra Yorumu en sonda: diger yazarlarin almadigi en onemli konu.
 */
$yazarlar = $malzeme['yazarlar'] !== []
    ? $malzeme['yazarlar']
    : [['anahtar' => 'valentra', 'ad' => 'Valentra Yorumu', 'alan' => 'Günün en önemli konusu',
        'kategoriler' => [], 'uslup' => '', 'bugun_yazdi' => $malzeme['bugun_sayisi'] > 0]];

// Siteden ne geldi: teshis icin (bir calismada "hepsi yazdi" deyip yazisiz bitti).
gunluk($malzeme['yazarlar'] === []
    ? 'Site yazar listesi göndermedi (eski site sürümü olabilir); bugün ' . $malzeme['bugun_sayisi'] . ' yazı var.'
    : 'Yazarlar: ' . implode(', ', array_map(
        static fn (array $y): string => $y['ad'] . (empty($y['bugun_yazdi']) ? ' (yazacak)' : ' (bugün yazdı)'),
        $yazarlar
    )));

$bekleyenYazarlar = array_values(array_filter($yazarlar, static fn (array $y): bool => empty($y['bugun_yazdi'])));

if ($bekleyenYazarlar === []) {
    gunluk('Bugün bütün yazarların yazısı var.');
    exit(0);
}

if (count($bekleyenYazarlar) > $enFazla) {
    $sonraki = array_map(static fn (array $y): string => (string) $y['ad'], array_slice($bekleyenYazarlar, $enFazla));
    gunluk('Bu çalışmada en fazla ' . $enFazla . ' yazar yazıyor; sonraki çalışmaya kalan ' . count($sonraki)
        . ' yazar: ' . implode(', ', $sonraki) . '.');
    $bekleyenYazarlar = array_slice($bekleyenYazarlar, 0, $enFazla);
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

// Uzun yazi 90 saniyeyi asabiliyor: istege 180 saniye, ama zaman asimi
// ya da 5xx'te yalnizca iki yeniden deneme (eskiden alti, 11 dakikaya kadar).
$yazar->sureSiniri(180, [10, 30], $bitis);

// Kose modeli yogunken (503) ayni istek haber modeline (bkz. Yazar::yedekModel).
$yazar->yedekModel(Yazar::haberModeli());
gunluk('Model: ' . $yazar->model() . ' (köşe yazısı; GEMINI_KOSE_MODEL ile değiştirilebilir; yoğunlukta yedek: '
    . Yazar::haberModeli() . ').');
$kose  = new KoseYazari($yazar);
$sayfa = new Sayfa(new Http());

$yazilar    = [];
$kullanilan = [];   // bu calismada baska yazarin konusu olmus haberler (indis)
$sira       = $malzeme['bugun_sayisi'];

// Siteye gonderilemeyenler; calisma sonunda depoya (bkz. 0. adim).
$gonderilemeyen = [];
$eklenen        = 0;
$siteDustu      = false;

foreach ($bekleyenYazarlar as $yazarNo => $kalem) {
    $ad = (string) $kalem['ad'];

    if ($bitis - time() < YAZAR_ASGARI_SURE) {
        $kalanlar = array_map(static fn (array $y): string => (string) $y['ad'], array_slice($bekleyenYazarlar, $yazarNo));
        gunluk('Süre bütçesi doldu (' . intdiv(time() - $baslangic, 60) . ' dk); sonraki çalışmaya kalan '
            . count($kalanlar) . ' yazar: ' . implode(', ', $kalanlar) . '.');
        break;
    }

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

        // Kulup ve basketbol yazarlari: baslikta ya da ozette sozcuk aranir
        // (25.10.2026; bkz. includes/yazarlar.php).
        if (!kelime_uyar($h, (array) ($kalem['anahtar_kelimeler'] ?? []), (array) ($kalem['haric_kelimeler'] ?? []))) {
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
        // Sayfa okumak istege bagli: sure azaldiysa yazi haber metniyle.
        if ($bitis - time() < YAZAR_ASGARI_SURE / 2) {
            break;
        }

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

    $yazilar[] = $yeni = [
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
         . count($kaynakMetinleri) . ' kaynak sayfa, ' . (time() - $baslangic) . ' sn)');

    /*
     * Yazi hemen gonderiliyor: calisma sonradan kesilse de kaybolmasin.
     * Site bir kez dustuyse bu calismada yeniden denenmiyor (her deneme
     * dakikalar suruyor); kalanlar depoda bekliyor.
     */
    if ($kuru) {
        continue;
    }

    if ($siteDustu) {
        $gonderilemeyen[] = $yeni;
        continue;
    }

    try {
        $sonuc = $site->koseGonder([$yeni]);
        $eklenen += (int) ($sonuc['eklenen'] ?? 0);

        foreach ((array) ($sonuc['hatalar'] ?? []) as $hata) {
            gunluk('  hata: ' . json_encode($hata, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        gunluk('  gönderilemedi, bekletiliyor: ' . $e->getMessage());
        $gonderilemeyen[] = $yeni;
        $siteDustu        = true;
    }
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

// Yazilar yazildikca gonderildi; burada yalnizca gonderilemeyenler.
if ($gonderilemeyen !== [] && $depo->yaz('bekleyen_kose', $gonderilemeyen)) {
    gunluk(count($gonderilemeyen) . ' yazı saklandı; siteye ulaşılabilen ilk çalışmada gönderilecek.');
}

gunluk(sprintf(
    '%d yazı yazıldı, %d siteye eklendi%s; süre %d dk.',
    count($yazilar),
    $eklenen,
    $gonderilemeyen !== [] ? ', ' . count($gonderilemeyen) . ' bekletiliyor' : '',
    intdiv(time() - $baslangic, 60)
));
