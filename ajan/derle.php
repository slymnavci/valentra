<?php
declare(strict_types=1);

/**
 * Derleme haber ajanı (site sahibinin kararı, 28.10.2026).
 *
 * Siteden son saatlerin haberlerini alir, en az iki farkli yayinin
 * verdigi en onemli olaylari sectirir, her olay icin butun kaynaklardan
 * tek bir derleme haber yazdirir ve siteye TASLAK olarak gonderir
 * (bkz. includes/derleme.php). Google'a yalnizca derlemeler sunuluyor.
 *
 * Gunde en fazla 20 derleme (sitenin tavani). Asamali: bir calismada en
 * fazla --en-fazla kadar (varsayilan 5); haber toplama iki saatte bir
 * calistigi icin gun icine yayiliyor ve her calismada yeni haberler
 * malzemeye giriyor.
 *
 * Calistirma:
 *   php ajan/derle.php [--zamanli] [--kuru] [--saat=24] [--en-erken=7] [--sure=15] [--en-fazla=5]
 */

// Yalnizca komut satirindan (ajan/.htaccess de kapatiyor; bu ikinci kilit).
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
require_once __DIR__ . '/src/Derleyici.php';
require_once __DIR__ . '/src/Bildirim.php';

use Valentra\Ajan\{Bildirim, Depo, Derleyici, Http, KotaBittiException, ModelErisimYokException, Sayfa, Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['zamanli', 'kuru', 'saat::', 'en-erken::', 'sure::', 'en-fazla::']);
$zamanli    = isset($secenekler['zamanli']);
$kuru       = isset($secenekler['kuru']);
$saat       = max(6, (int) ($secenekler['saat'] ?? 24));
$enErken    = max(0, min(23, (int) ($secenekler['en-erken'] ?? 7)));
$enFazla    = max(1, (int) ($secenekler['en-fazla'] ?? 5));

// Sure butcesi (bkz. kose.php SURE BUTCESI): butce dolunca yeni olaya
// gecilmiyor, model istegi butceyi asamiyor, her derleme yazildigi anda
// gonderiliyor.
$baslangic = time();
$bitis     = $baslangic + max(5, (int) ($secenekler['sure'] ?? 15)) * 60;

/** Bir olaya başlamak için kalması gereken en az süre (saniye). */
const OLAY_ASGARI_SURE = 4 * 60;

/** Olay başına okunacak en fazla kaynak sayfa. */
const KAYNAK_SAYFA_TAVANI = 2;

/*
 * HIZ (29.10.2026): ilk calismada tek derleme 14,5 dakika surdu ve butce
 * doldu (5 olaydan 1'i). Secim istemine 233 haberin hepsi gidiyordu
 * (~50 bin token) ve model istegi 180 saniye bekleyip iki kez yeniden
 * deneniyordu. Simdi: secime en onemli SECIM_HABER_TAVANI haber, hizli
 * haber modeli once, istek 120 sn ve tek yeniden deneme (sonra Claude /
 * Luna), her adimin suresi gunlukte.
 */
const SECIM_HABER_TAVANI = 150;

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
    $GLOBALS['derlemeGunlugu'][] = $mesaj;
}

// Calisma ozeti GitHub'da da gorunsun (bkz. kose.php).
$GLOBALS['derlemeGunlugu'] = [];

register_shutdown_function(static function (): void {
    if (getenv('GITHUB_ACTIONS') !== 'true' || $GLOBALS['derlemeGunlugu'] === []) {
        return;
    }

    $satirlar = array_slice($GLOBALS['derlemeGunlugu'], -25);
    $metin    = 'Derleme haberler:' . "\n" . implode("\n", array_map(static fn (string $s): string => mb_substr($s, 0, 300), $satirlar));

    echo '::notice title=Valentra derleme haberler::'
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
    gunluk("Saat {$enErken}:00'dan önce derleme yazılmıyor.");
    exit(0);
}

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));
$depo = new Depo(__DIR__ . '/onbellek');

gunluk('Derleme ajanı başlıyor' . ($kuru ? ' (KURU ÇALIŞMA — gönderim yok)' : ''));

// --- 0. Onceki calismadan gonderilemeyen derlemeler --------------------------

$bekleyen = $depo->oku('bekleyen_derleme', 2);

if (!$kuru && is_array($bekleyen) && $bekleyen !== []) {
    try {
        $sonuc = $site->derlemeGonder(array_values($bekleyen));
        $depo->sil('bekleyen_derleme');
        gunluk(count($bekleyen) . ' bekleyen derleme gönderildi: ' . (int) ($sonuc['eklenen'] ?? 0) . ' eklendi.');

        foreach ((array) ($sonuc['hatalar'] ?? []) as $hata) {
            gunluk('  hata: ' . json_encode($hata, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        gunluk('Bekleyen derlemeler yine gönderilemedi: ' . $e->getMessage());
        Bildirim::uyari('Derleme ajanı siteye ulaşamadı; ' . count($bekleyen)
            . ' derleme bekletiliyor (kaybolmadı). Teknik ayrıntı: ' . $e->getMessage());
        exit(0);
    }
}

// --- 1. Malzeme ---------------------------------------------------------------

try {
    $malzeme = $site->derlemeMalzeme($saat);
} catch (Throwable $e) {
    // Model cagrisindan ONCE: hicbir sey harcanmadan cikiliyor.
    gunluk('Malzeme alınamadı: ' . $e->getMessage());
    Bildirim::uyari('Derleme ajanı siteye ulaşamadı, bu çalışmada derleme yazılmadı; sonraki çalışmada yazılacak. '
        . 'Teknik ayrıntı: ' . $e->getMessage());
    exit(0);
}

$haberler = $malzeme['haberler'];
$kalan    = $malzeme['gunluk_tavan'] - $malzeme['bugun_sayisi'];

gunluk('Bugün ' . $malzeme['bugun_sayisi'] . ' / ' . $malzeme['gunluk_tavan'] . ' derleme var; '
    . count($haberler) . ' haber malzeme (son ' . $saat . ' saat).');

if ($kalan <= 0) {
    gunluk('Bugünün derleme tavanı dolu.');
    exit(0);
}

if (count($haberler) < Derleyici::ASGARI_YAYIN) {
    gunluk('Derlemeye yetecek haber yok.');
    exit(0);
}

$hedef = min($enFazla, $kalan);

// Malzeme onem sirasiyla geliyor (bkz. derleme_malzeme): secime en onemlileri.
$haberler = array_slice($haberler, 0, SECIM_HABER_TAVANI);

// Model: hizli haber modeli once, kose modeli yedek; Gemini yogunsa Claude ya da Luna.
$yazar = new Yazar(ayar('GEMINI_API_KEY'), Yazar::haberModeli(), trim((string) getenv('OPENAI_API_KEY')), trim((string) getenv('ANTHROPIC_API_KEY')));
$yazar->geminiOnce();
$yazar->sureSiniri(120, [10], $bitis);
$yazar->yedekModel(Yazar::koseModeli());
gunluk('Model: ' . $yazar->model() . '; bu çalışmada en fazla ' . $hedef . ' derleme.');

$derleyici = new Derleyici($yazar);
$sayfa     = new Sayfa(new Http());

// --- 2. Olaylar ----------------------------------------------------------------

$adimBas = time();

try {
    $olaylar = $derleyici->olaySec($haberler, $malzeme['onceki'], $malzeme['kategoriler'], $hedef);
} catch (ModelErisimYokException $e) {
    gunluk('Modele erişilemedi: ' . $e->getMessage());
    Bildirim::sorun($e->getMessage());
    exit(0);
} catch (KotaBittiException $e) {
    gunluk('Günlük model kotası dolu: ' . $e->getMessage());
    exit(0);
} catch (Throwable $e) {
    gunluk('Olaylar seçilemedi: ' . $e->getMessage());
    exit(0);
}

if ($olaylar === []) {
    gunluk('İki farklı yayının verdiği, derlemeye değer olay bulunamadı.');
    exit(0);
}

gunluk(count($olaylar) . ' olay seçildi (' . (time() - $adimBas) . ' sn, ' . count($haberler) . ' haber, yanıtlayan: '
    . $yazar->sonYanitlayan() . ').');

// --- 3. Yaz ve gonder -------------------------------------------------------

$yazilan        = 0;
$eklenen        = 0;
$gonderilemeyen = [];
$siteDustu      = false;

foreach ($olaylar as $no => $olay) {
    if ($bitis - time() < OLAY_ASGARI_SURE) {
        gunluk('Süre bütçesi doldu (' . intdiv(time() - $baslangic, 60) . ' dk); kalan '
            . (count($olaylar) - $no) . ' olay sonraki çalışmada yeniden seçilebilir.');
        break;
    }

    $yayinlar = array_unique(array_map(
        static fn (int $i): string => (string) ($haberler[$i]['yayin'] ?? $haberler[$i]['kaynak_adi'] ?? ''),
        $olay['haberler']
    ));
    gunluk('— ' . $olay['olay'] . ' (' . count($olay['haberler']) . ' haber: ' . implode(', ', $yayinlar) . ')');

    /*
     * Kaynak sayfalar: haberin metni kaynagin ozeti; derleme ayrinti
     * istiyor. Once site uzerinden (kamu siteleri GitHub IP'lerini
     * engelliyor), olmazsa dogrudan. Alinamazsa haber metniyle yaziliyor.
     */
    $kaynakMetinleri = [];
    $adimBas         = time();

    foreach (array_slice($olay['haberler'], 0, KAYNAK_SAYFA_TAVANI) as $indis) {
        if ($bitis - time() < OLAY_ASGARI_SURE / 2) {
            break;
        }

        $url = (string) ($haberler[$indis]['kaynak_url'] ?? '');

        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }

        $sayfaBas = time();
        $metin    = $site->sayfaGetir($url) ?? '';

        // Site uzerinden 15 saniyede gelmediyse dogrudan da denenmiyor: yavas kaynak.
        if (trim($metin) === '' && !$site->cekimserMi() && time() - $sayfaBas < 15) {
            $metin = $sayfa->metin($url, 12000);
        }

        if (trim($metin) !== '') {
            $kaynakMetinleri[$indis] = $metin;
        }
    }

    $sayfaSuresi = time() - $adimBas;
    $adimBas     = time();

    try {
        $yazi = $derleyici->yaz($olay, $haberler, $kaynakMetinleri);
    } catch (ModelErisimYokException $e) {
        gunluk('  modele erişilemedi: ' . $e->getMessage());
        Bildirim::sorun($e->getMessage());
        break;
    } catch (KotaBittiException $e) {
        gunluk('Günlük model kotası doldu; kalan olaylar atlanıyor.');
        break;
    } catch (Throwable $e) {
        gunluk('  yazılamadı: ' . $e->getMessage());
        continue;
    }

    if ($yazi === null) {
        gunluk('  geçerli metin çıkmadı (boş ya da çok kısa).');
        continue;
    }

    $yazilan++;

    $yeni = [
        'baslik'        => $yazi['baslik'],
        'ozet'          => $yazi['ozet'],
        'icerik'        => $yazi['icerik'],
        'neden_onemli'  => $yazi['neden_onemli'],
        'etiketler'     => $yazi['etiketler'],
        'resmi_dayanak' => $yazi['resmi_dayanak'],
        'kategori'      => $olay['kategori'],
        'haber_idleri'  => array_values(array_map(static fn (int $i): int => (int) ($haberler[$i]['id'] ?? 0), $olay['haberler'])),
        'ajan_notu'     => $yazi['editor_notu'],
        'model'         => $yazar->sonYanitlayan(),
    ];

    gunluk('  yazıldı: ' . $yazi['baslik'] . ' (' . count(preg_split('/\s+/u', $yazi['icerik']) ?: []) . ' kelime, '
        . count($kaynakMetinleri) . ' kaynak sayfa ' . $sayfaSuresi . ' sn, yazım ' . (time() - $adimBas) . ' sn ('
        . $yazar->sonYanitlayan() . '), toplam ' . (time() - $baslangic) . ' sn)');

    if ($kuru) {
        echo "\n==== " . $yeni['baslik'] . " ====\n" . $yeni['ozet'] . "\n\n" . $yeni['icerik']
           . "\n\n[Neden önemli] " . $yeni['neden_onemli'] . "\n[Editör notu] " . $yeni['ajan_notu'] . "\n";
        continue;
    }

    // Hemen gonder: calisma sonradan kesilse de kaybolmasin.
    if ($siteDustu) {
        $gonderilemeyen[] = $yeni;
        continue;
    }

    try {
        $sonuc    = $site->derlemeGonder([$yeni]);
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
    'Model kullanımı (Gemini): %d istek, %s giriş + %s çıkış token.',
    $kullanim['istek'],
    number_format($kullanim['girdi']),
    number_format($kullanim['cikti'])
));

if ($gonderilemeyen !== [] && $depo->yaz('bekleyen_derleme', $gonderilemeyen)) {
    gunluk(count($gonderilemeyen) . ' derleme saklandı; siteye ulaşılabilen ilk çalışmada gönderilecek.');
}

gunluk(sprintf(
    '%d derleme yazıldı, %d siteye eklendi%s; süre %d dk.',
    $yazilan,
    $eklenen,
    $gonderilemeyen !== [] ? ', ' . count($gonderilemeyen) . ' bekletiliyor' : '',
    intdiv(time() - $baslangic, 60)
));
