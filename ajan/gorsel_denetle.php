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

/**
 * Dosya adında ya da atıfta haber kuruluşu / ekran görüntüsü izi.
 * Özgür lisanslı da olsa bu görseller kanal logosu, alt bant ya da ajans
 * filigranı taşıyabiliyor; site sahibinin kuralı: haber sitesi adı olmayacak.
 */
const HABER_KURULUSU_DESENI = '/\\b(todo noticias|tn\\.com|entrevista de tn|cnn|bbc|reuters|afp|associated press|ap photo|getty|telesur|euronews|al jazeera|aljazeera|fox news|sky news|infobae|clar[ií]n|televisa|tv azteca|ntv|trt|a haber|habert[uü]rk|h[uü]rriyet|anadolu ajans|ihlas|demir[oö]ren|screenshot|screen ?capture|captura de pantalla|ekran g[oö]r[uü]nt[uü]s[uü]|noticiero|telediario|news ?channel)\\b/iu';

/** Bir istekte modele giden görsel sayısı. */
const PARTI = 6;

/** Bir haber için en fazla kaç arama sorgusu denenir. */
const EN_FAZLA_SORGU = 3;

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
    ['haberler' => $haberler, 'kullanilan' => $kullanilanListe] = $site->gorselDenetimListesi($gun, $adet);
    $kullanilan = array_fill_keys($kullanilanListe, true);
} catch (Throwable $e) {
    gunluk('Görsel listesi alınamadı: ' . $e->getMessage());
    exit(0);
}

if ($haberler === []) {
    gunluk('Denetlenecek yeni görsel yok.');
    exit(0);
}

$araSayi = count(array_filter($haberler, static fn (array $h): bool => !empty($h['ara'])));
gunluk(count($haberler) . ' görsel denetlenecek' . ($araSayi > 0 ? ' (' . $araSayi . ' haber için doğrudan uygun görsel aranacak: görselsiz ya da Infobae)' : '') . '.');

$geminiModel = ayar('GEMINI_MODEL', false) ?: 'gemini-3.8-flash';
$claudeModel = ayar('CLAUDE_MODEL', false) ?: 'claude-haiku-5-5';
$denetci = new GorselDenetci(ayar('GEMINI_API_KEY', false), $geminiModel, ayar('ANTHROPIC_API_KEY', false), $claudeModel, $bitis);

$toplam = ['temiz' => 0, 'degisen' => 0, 'korunan' => 0, 'konu_gorseli' => 0, 'karar_yok' => 0];

/**
 * Logolu görselin yerine konacak özgür lisanslı görseli arar.
 *
 * KİŞİ ÖNCE (site sahibi, 08.11.2026: "Erdoğan Bakü'de diyorsa Bakü'deki
 * bir Erdoğan resmi varsa onu kullan; başlıkta A Haber yazıyorsa kullanma,
 * benzer bir fotoğraf bul"). Haberdeki tanınmış kişinin adıyla yapılan
 * aramada aday, arşivdeki dosya adında o kişinin soyadını taşımalı;
 * yoksa başka birinin fotoğrafı olabilir.
 *
 * @param array{id:int,baslik:string,ozet:string} $haber
 * @param array{liste:list<string>,kisiler:list<string>} $sorgular
 * @return ?array{url:string,ad:string,atif:string,sayfa:string}
 */
function yedek_bul(GorselDenetci $denetci, array $haber, array $sorgular, int $bitis, array &$kullanilan): ?array
{
    // Soyadlari ("Recep Tayyip Erdoğan" -> "erdogan").
    $soyadlar = [];

    foreach ($sorgular['kisiler'] as $kisi) {
        $parcalar = preg_split('/\s+/', GorselDenetci::katla($kisi)) ?: [];
        $soyad = (string) end($parcalar);

        if (strlen($soyad) >= 3) {
            $soyadlar[] = $soyad;
        }
    }

    foreach (array_slice($sorgular['liste'], 0, EN_FAZLA_SORGU) as $sorgu) {
        if (time() >= $bitis - 45) {
            return null;
        }

        // Ayni gorsel iki haberde olmasin (son iki haftada konulanlar dahil).
        // Haber kanali/ajans kaynakli gorsel (TV ekran goruntusu, ajans
        // fotografi) aday degil: logo ya da bant tasiyabilir (09.11.2026:
        // Milei haberine "TN roportaji" ekran goruntusu kondu).
        $adaylar = array_values(array_filter(
            $denetci->ara($sorgu, PARTI * 2),
            static fn (array $a): bool => !isset($kullanilan[$a['url']])
                // Kartpostal, harita, grafik, logo, eski tarihli tarama aday degil (11.11.2026).
                && !GorselDenetci::fotografDisi(str_replace(['_', '-', '.'], ' ', rawurldecode(basename($a['url'])) . ' ' . $a['ad']))
                && preg_match(HABER_KURULUSU_DESENI, str_replace(['_', '-', '.'], ' ',
                    rawurldecode($a['url']) . ' ' . $a['ad']) . ' ' . $a['atif']) !== 1
        ));
        $katliSorgu = GorselDenetci::katla($sorgu);
        $sorgudakiler = array_values(array_filter($soyadlar, static fn (string $s): bool => str_contains($katliSorgu, $s)));

        // Kisi aramasi: dosya adinda o kisi gecmeyen aday elenir.
        if ($sorgudakiler !== []) {
            $adaylar = array_values(array_filter($adaylar, static function (array $a) use ($sorgudakiler): bool {
                $ad = GorselDenetci::katla($a['ad']);

                foreach ($sorgudakiler as $s) {
                    if (!str_contains($ad, $s)) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $adaylar = array_slice($adaylar, 0, PARTI);

        if ($adaylar === []) {
            continue;
        }

        $resimler = $denetci->indir(array_column($adaylar, 'url'));
        $ogeler = [];
        $sira   = [];

        foreach ($adaylar as $i => $a) {
            // Indirilen dosyanin gercek boyutu da yeterli olmali (arsiv boyutu
            // bildirmediyse ilk kez burada goruluyor): kucuk gorsel mansette bulanik.
            if ($resimler[$i] !== null
                && GorselDenetci::boyutUygun((int) $resimler[$i]['genislik'], (int) $resimler[$i]['yukseklik'])) {
                $ogeler[] = ['resim' => $resimler[$i], 'baslik' => $haber['baslik'], 'ad' => $a['ad'],
                             'ozet' => mb_substr((string) ($haber['ozet'] ?? ''), 0, 300, 'UTF-8')];
                $sira[]   = $a;
            }
        }

        foreach ($denetci->denetle($ogeler) as $i => $karar) {
            if ($karar !== null && $karar['temiz'] && $karar['uygun']) {
                $kullanilan[$sira[$i]['url']] = true;

                return $sira[$i];
            }
        }
    }

    return null;
}

/*
 * Dogrudan uygun gorsel aranacaklar (09.11.2026, site sahibi: "Infobae'den
 * gelen haberler resimsiz ya da Infobae resimli geliyor; yerine konuya uygun
 * bir resim bulalim"): gorselsiz / genel konu gorselli haberler ve Infobae
 * haberleri. Logo denetimi yok; bulunan aday ayni denetimden geciyor (temiz
 * + konuya uygun). Bulunamazsa site konu gorselini koyuyor.
 */
$arananlar = array_values(array_filter($haberler, static fn (array $h): bool => !empty($h['ara'])));
$haberler  = array_values(array_filter($haberler, static fn (array $h): bool => empty($h['ara'])));

// Surenin en fazla yarisi aramaya: logo denetimi de her calismada ilerlesin.
$araBitis = $arananlar === [] ? $bitis : time() + intdiv(max(0, $bitis - time()), 2);

foreach (array_chunk($arananlar, PARTI) as $parti) {
    if (time() >= $araBitis - 90) {
        gunluk('Süre doldu; görsel aranacak kalan haberler sonraki çalışmada.');
        break;
    }

    $sorgular = $denetci->sorgular(array_map(
        static fn (array $h): array => ['baslik' => (string) $h['baslik'], 'ozet' => (string) $h['ozet'], 'metin' => (string) ($h['metin'] ?? '')],
        $parti
    ));
    $sonuclar = [];

    foreach ($parti as $i => $h) {
        $bulunan = ($sorgular[$i]['liste'] ?? []) !== [] ? yedek_bul($denetci, $h, $sorgular[$i], $araBitis, $kullanilan) : null;

        if ($bulunan !== null) {
            gunluk('#' . $h['id'] . ' (' . ($h['neden'] ?? '') . ') → ' . $bulunan['url'] . ' [' . $bulunan['atif'] . '] — ' . $h['baslik']);
            $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false, 'yeni' => $bulunan['url'], 'atif' => $bulunan['atif']];
            $toplam['degisen']++;
        } elseif (time() < $araBitis - 45) {
            // Aranip bulunamadi: konu gorseli (sure dolduysa dokunma, sonra yeniden).
            gunluk('#' . $h['id'] . ' (' . ($h['neden'] ?? '') . ') uygun görsel bulunamadı → konu görseli (sorgu: '
                . implode(' | ', $sorgular[$i]['liste'] ?? []) . ') — ' . $h['baslik']);
            $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false];
            $toplam['konu_gorseli']++;
        }
    }

    if ($sonuclar !== [] && !$kuru) {
        try {
            $site->gorselDenetimGonder($sonuclar);
        } catch (Throwable $e) {
            gunluk('Sonuçlar yazılamadı: ' . $e->getMessage());
        }
    }
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
            // Haberin kendi fotografi kaliyor (site sahibi, 08.11.2026: gercek fotograflar gorunsun).
            gunluk('#' . $h['id'] . ' görsel indirilemedi → olduğu gibi kalıyor');
            $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false, 'koru' => true];
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
            static fn (array $h): array => ['baslik' => (string) $h['baslik'], 'ozet' => (string) $h['ozet'], 'metin' => (string) ($h['metin'] ?? '')],
            $logolu
        ));

        foreach ($logolu as $i => $h) {
            $bulunan = $sorgular[$i]['liste'] !== [] ? yedek_bul($denetci, $h, $sorgular[$i], $bitis, $kullanilan) : null;

            if ($bulunan !== null) {
                gunluk('   → yerine: ' . $bulunan['url'] . ' [' . $bulunan['atif'] . '] (sorgu: ' . implode(' | ', $sorgular[$i]['liste']) . ')');
                $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false, 'yeni' => $bulunan['url'], 'atif' => $bulunan['atif']];
            } else {
                // Konu gorseli KONMUYOR (#300: "gercek fotograflari devreye sok"):
                // haberin kendi fotografi kaliyor.
                gunluk('   → uygun özgür görsel bulunamadı, haberin kendi fotoğrafı kalıyor (sorgu: ' . implode(' | ', $sorgular[$i]['liste']) . ')');
                $sonuclar[] = ['id' => (int) $h['id'], 'temiz' => false, 'koru' => true];
            }
        }
    }

    if ($sonuclar === []) {
        continue;
    }

    if ($kuru) {
        foreach ($sonuclar as $s) {
            $toplam[!empty($s['temiz']) ? 'temiz' : (isset($s['yeni']) ? 'degisen' : (!empty($s['koru']) ? 'korunan' : 'konu_gorseli'))]++;
        }

        continue;
    }

    try {
        $yazilan = $site->gorselDenetimGonder($sonuclar);

        foreach ($yazilan as $k => $n) {
            $toplam[$k] = ($toplam[$k] ?? 0) + $n;
        }
    } catch (Throwable $e) {
        gunluk('Sonuçlar yazılamadı: ' . $e->getMessage());
    }
}

$s = $denetci->sayac();
gunluk(sprintf(
    'Bitti%s: %d temiz, %d görsel özgür lisanslı görselle değişti, %d logolu görsel yerine uygun fotoğraf bulunamadığı için kaldı; %d karar alınamadı (istek: Gemini %d, Claude %d, başarısız %d).',
    $kuru ? ' (kuru)' : '',
    $toplam['temiz'], $toplam['degisen'], $toplam['korunan'] + $toplam['konu_gorseli'], $toplam['karar_yok'],
    $s['gemini'], $s['claude'], $s['hata']
));
