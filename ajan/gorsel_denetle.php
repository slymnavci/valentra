<?php
declare(strict_types=1);

/**
 * Haber görsellerinde haber sitesi adı / logosu denetimi.
 *
 * Site sahibinin karari (08.11.2026): "ana sayfada bir haberdeki resimde
 * kesinlikle bir haber sitesinin adi olmayacak. Ozgun resimler kullanalim;
 * uygun resim yoksa internetten konuya uygun bir resim bulup yerlestirelim.
 * Ama haber sitesi adi istemiyorum."
 *
 *  1. Site denetlenmemis gorselleri veriyor (api/gorsel-denetim.php), ana
 *     sayfadakiler once.
 *  2. Her gorsel yapay zekaya gosteriliyor (src/GorselDenetci.php): haber
 *     sitesi, kanal, gazete, ajans adi/logosu/filigrani var mi?
 *  3. Varsa Wikimedia Commons ve Openverse'te konuya uygun, ozgur lisansli
 *     gorsel araniyor; aday da ayni denetimden geciyor (temiz + konuya uygun).
 *  4. Bulunamazsa site haberin konu gorselini koyuyor.
 *
 * Denetlenmemis gorsel ana sayfada zaten gosterilmiyor (yerine konu gorseli;
 * bkz. haber_gorsel_denetimli_mi), yani yarim kalan is sonraki calismada
 * tamamlaniyor.
 *
 *   php ajan/gorsel_denetle.php                son 3 gun
 *   php ajan/gorsel_denetle.php --gun=14 --sure=20   ilk temizlik
 *   php ajan/gorsel_denetle.php --kuru         yalnizca kararlari goster
 *
 * Ortam: VALENTRA_SITE_URL, VALENTRA_AGENT_KEY, GEMINI_API_KEY
 * (ANTHROPIC_API_KEY yedek), GEMINI_MODEL, CLAUDE_MODEL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/src/GorselDenetci.php';

use Valentra\Ajan\{GorselDenetci, Site};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

/** Bir istekte modele giden görsel sayısı. */
const PARTI = 6;

/** Bir haber için en fazla kaç arama sorgusu denenir. */
const EN_FAZLA_SORGU = 2;

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
}

function ayar(string $ad, bool $zorunlu = true): string
{
    $deger = trim((string) getenv($ad));

    if ($deger === '' && $zorunlu) {
        fwrite(STDERR, "HATA: {$ad} ortam değişkeni tanımlı değil.\n");
        exit(1);
    }

    return $deger;
}

$secenekler = getopt('', ['kuru', 'gun::', 'sure::', 'adet::']);
$kuru  = isset($secenekler['kuru']);
$gun   = max(1, min(60, (int) ($secenekler['gun'] ?? 3)));
$adet  = max(1, min(400, (int) ($secenekler['adet'] ?? 120)));
$bitis = time() + max(2, (int) ($secenekler['sure'] ?? 6)) * 60;

$site = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));

try {
    $haberler = $site->gorselDenetimListesi($gun, $adet);
} catch (Throwable $e) {
    gunluk('Görsel listesi alınamadı: ' . $e->getMessage());
    exit(0);
}

if ($haberler === []) {
    gunluk('Denetlenecek yeni görsel yok.');
    exit(0);
}

gunluk(count($haberler) . ' görsel denetlenecek.');

$geminiModel = ayar('GEMINI_MODEL', false) ?: 'gemini-3.8-flash';
$claudeModel = ayar('CLAUDE_MODEL', false) ?: 'claude-haiku-5-5';
$denetci = new GorselDenetci(ayar('GEMINI_API_KEY', false), $geminiModel, ayar('ANTHROPIC_API_KEY', false), $claudeModel, $bitis);

$toplam = ['temiz' => 0, 'degisen' => 0, 'konu_gorseli' => 0, 'karar_yok' => 0];

/**
 * Logolu görselin yerine konacak özgür lisanslı görseli arar.
 *
 * @param array{id:int,baslik:string,ozet:string} $haber
 * @param list<string> $sorgular
 * @return ?array{url:string,atif:string,sayfa:string}
 */
function yedek_bul(GorselDenetci $denetci, array $haber, array $sorgular, int $bitis): ?array
{
    foreach (array_slice($sorgular, 0, EN_FAZLA_SORGU) as $sorgu) {
        if (time() >= $bitis - 45) {
            return null;
        }

        $adaylar = $denetci->ara($sorgu, PARTI);

        if ($adaylar === []) {
            continue;
        }

        $resimler = $denetci->indir(array_column($adaylar, 'url'));
        $ogeler = [];
        $sira   = [];

        foreach ($adaylar as $i => $a) {
            if ($resimler[$i] !== null) {
                $ogeler[] = ['resim' => $resimler[$i], 'baslik' => $haber['baslik']];
                $sira[]   = $a;
            }
        }

        foreach ($denetci->denetle($ogeler) as $i => $karar) {
            if ($karar !== null && $karar['temiz'] && $karar['uygun']) {
                return $sira[$i];
            }
        }
    }

    return null;
}

foreach (array_chunk($haberler, PARTI) as $parti) {
    if (time() >= $bitis - 60) {
        gunluk('Süre doldu; kalanlar sonraki çalışmada (ana sayfada şimdilik konu görseli).');
        break;
    }

    // Once kaynagin kendi adresi; vermezse sitenin onbellekteki kopyasi.
    $resimler = $denetci->indir(array_column($parti, 'gorsel'));
    $eksik = [];

    foreach ($parti as $i => $h) {
        if ($resimler[$i] === null && (string) ($h['yedek'] ?? '') !== '') {
            $eksik[$i] = (string) $h['yedek'];
        }
    }

    if ($eksik !== []) {
        foreach ($denetci->indir($eksik) as $i => $r) {
            $resimler[$i] = $r;
        }
    }

    // Partide hicbiri inmediyse sorun ag (site ya da GitHub): gorsele dokunma.
    if (array_filter($resimler) === []) {
        gunluk('Partideki görsellerin hiçbiri indirilemedi; ağ sorunu olabilir, sonraki çalışmada yeniden.');
        $toplam['karar_yok'] += count($parti);
        continue;
    }

    $ogeler = [];
    $yer    = [];
    $sonuclar = [];
    $logolu = [];

    foreach ($parti as $i => $h) {
        if ($resimler[$i] === null) {
            // Ne kaynaktan ne siteden indirilebiliyor: gosterilemeyen gorsel yerine konu gorseli.
            gunluk('#' . $h['id'] . ' görsel indirilemedi → konu görseli');
            $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false, 'bulunan' => 'indirilemedi'];
            continue;
        }

        $ogeler[] = ['resim' => $resimler[$i], 'baslik' => (string) $h['baslik']];
        $yer[]    = $h;
    }

    foreach ($denetci->denetle($ogeler) as $i => $karar) {
        $h = $yer[$i];

        if ($karar === null) {
            $toplam['karar_yok']++;
            continue;
        }

        if ($karar['temiz']) {
            $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => true];
            continue;
        }

        gunluk('#' . $h['id'] . ' logo/ad: ' . ($karar['bulunan'] !== '' ? $karar['bulunan'] : '?') . ' — ' . $h['baslik']);
        $logolu[] = $h;
    }

    if ($logolu !== []) {
        $sorgular = $denetci->sorgular(array_map(
            static fn (array $h): array => ['baslik' => (string) $h['baslik'], 'ozet' => (string) $h['ozet']],
            $logolu
        ));

        foreach ($logolu as $i => $h) {
            $bulunan = $sorgular[$i] !== [] ? yedek_bul($denetci, $h, $sorgular[$i], $bitis) : null;

            if ($bulunan !== null) {
                gunluk('   → yerine: ' . $bulunan['url'] . ' [' . $bulunan['atif'] . '] (sorgu: ' . implode(' | ', $sorgular[$i]) . ')');
                $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false, 'yeni' => $bulunan['url'], 'atif' => $bulunan['atif']];
            } else {
                gunluk('   → uygun özgür görsel bulunamadı, konu görseli (sorgu: ' . implode(' | ', $sorgular[$i]) . ')');
                $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false];
            }
        }
    }

    if ($sonuclar === []) {
        continue;
    }

    if ($kuru) {
        foreach ($sonuclar as $s) {
            $toplam[!empty($s['temiz']) ? 'temiz' : (isset($s['yeni']) ? 'degisen' : 'konu_gorseli')]++;
        }

        continue;
    }

    try {
        $yazilan = $site->gorselDenetimGonder($sonuclar);

        foreach ($yazilan as $k => $n) {
            $toplam[$k] += $n;
        }
    } catch (Throwable $e) {
        gunluk('Sonuçlar yazılamadı: ' . $e->getMessage());
    }
}

$s = $denetci->sayac();
gunluk(sprintf(
    'Bitti%s: %d temiz, %d görsel özgür lisanslı görselle değişti, %d konu görseli; %d karar alınamadı (istek: Gemini %d, Claude %d, başarısız %d).',
    $kuru ? ' (kuru)' : '',
    $toplam['temiz'], $toplam['degisen'], $toplam['konu_gorseli'], $toplam['karar_yok'],
    $s['gemini'], $s['claude'], $s['hata']
));
