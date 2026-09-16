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
require_once __DIR__ . '/src/SemaliIstemci.php';
require_once __DIR__ . '/src/Yazar.php';
require_once __DIR__ . '/src/DegerOkuyucu.php';

use Valentra\Ajan\{DegerOkuyucu, Http, KotaBittiException, Sayfa, Site, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['kuru', 'grup::']);
$kuru       = isset($secenekler['kuru']);
$grupBoyu   = max(1, (int) ($secenekler['grup'] ?? 3));

/*
 * Kirpma sinirlari.
 *
 * Eski sinir 20.000 karakterdi ve pratik bilgi derlemeleri bundan cok
 * daha uzun: Alomaliye sayfasi tam 20.000'de kesilmisti, yani aranan
 * tablolarin cogu metne hic girmemisti. Yedi bilginin altisi "sayfada
 * yok" diye dondu — oysa sayfadaydilar.
 */
const SAYFA_SINIRI = 200000;
const MODEL_SINIRI = 120000;

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

/*
 * Ayni sayfa BIR KEZ indiriliyor.
 *
 * Pratik bilgilerin cogu Alomaliye ve ISMMMO'nun derli toplu
 * sayfalarindan geliyor; on bes satirin on biri iki adrese bakiyor.
 * Satir basina indirmek ayni sayfayi on bir kez cekmek olurdu — hem
 * bosuna hem de kaynak siteyi yoran bir davranis.
 */
$sayfaOnbellek = [];

/**
 * Sayfayı bir kez indirir; metnini ve bağlantılarını döndürür.
 *
 * Once SITE uzerinden deneniyor. Turk kamu ve meslek siteleri veri
 * merkezi IP'lerini engelleyebiliyor; ajan GitHub'da calistigi icin
 * ilk calismada on bes sayfanin on ucu okunamadi. Site Turkiye'de
 * barindigi icin ayni adreslere ulasabiliyor. Site yolu duserse
 * dogrudan indirmeye donuluyor.
 *
 * @return array{metin:string,baglar:list<array{yazi:string,url:string}>}
 */
$sayfaOku = static function (string $url) use (&$sayfaOnbellek, $site, $sayfa): array {
    if (array_key_exists($url, $sayfaOnbellek)) {
        return $sayfaOnbellek[$url];
    }

    $sonuc = $site->sayfaAyrinti($url);

    if ($sonuc === null) {
        // Dogrudan indirmede baglanti listesi yok; yalnizca metin.
        $sonuc = ['metin' => $sayfa->metin($url, SAYFA_SINIRI), 'baglar' => []];
    }

    $sayfaOnbellek[$url] = $sonuc;

    return $sonuc;
};

$adaylar = [];

foreach ($bilgiler as $bilgi) {
    $url = (string) ($bilgi['kaynak_url'] ?? '');

    if ($url === '') {
        gunluk('  ' . $bilgi['baslik'] . ': kaynak adresi tanımlı değil');
        continue;
    }

    $yeniIndirme = !array_key_exists($url, $sayfaOnbellek);
    $sayfaVeri   = $sayfaOku($url);
    $metin       = $sayfaVeri['metin'];

    if (trim($metin) === '') {
        gunluk('  ' . $bilgi['baslik'] . ': kaynak sayfası okunamadı (' . $url . ')');
        continue;
    }

    $adaylar[] = [
        'bilgi'      => $bilgi,
        'url'        => $url,
        'sayfaMetni' => mb_substr($metin, 0, MODEL_SINIRI, 'UTF-8'),
    ];

    gunluk(sprintf(
        '  %s: %s (%d karakter)',
        $bilgi['baslik'],
        $yeniIndirme ? 'sayfa okundu' : 'aynı sayfadan',
        mb_strlen($metin)
    ));
}

if ($adaylar === []) {
    gunluk('Hiçbir kaynak sayfası okunamadı.');
    exit(0);
}

gunluk(count($sayfaOnbellek) . ' ayrı sayfa indirildi, ' . count($adaylar) . ' bilgi işlenecek.');

// --- 3. Degerleri okut ------------------------------------------------------

$sonuclar  = [];
$hatali    = 0;

$kalan = degerleriOku($okuyucu, $adaylar, $grupBoyu, $sonuclar, $hatali);

// --- 3b. Fihrist sayfalarindan alt sayfaya in -------------------------------

/*
 * Bazi derlemeler yalnizca baslik listesi.
 *
 * Ilk calismada ISMMMO sayfasindan alti bilginin altisi da
 * "sayfada yalnizca baslik var, rakam yok" diye dondu — sayfa gercekten
 * bir fihristti, rakamlar alt sayfalardaydi. Bulunamayanlar icin o
 * sayfadaki basliga en cok benzeyen baglanti izleniyor ve deger orada
 * araniyor.
 *
 * Yalnizca BIR adim iniliyor: her bulunamayan icin sinirsiz gezinmek
 * calisma suresini ve model maliyetini ongorulemez hale getirirdi.
 */
$kalanSon = $kalan;

if ($kalan !== []) {
    gunluk('---');
    gunluk(count($kalan) . ' bilgi için alt sayfa aranıyor.');

    $altAdaylar = [];
    $kalanSon   = [];

    foreach ($kalan as $aday) {
        $baglar = $sayfaOnbellek[$aday['url']]['baglar'] ?? [];
        $hedef  = $baglar === [] ? '' : enYakinBag($aday['bilgi'], $baglar);

        if ($hedef === '' || $hedef === $aday['url']) {
            $kalanSon[] = $aday;
            continue;
        }

        $altVeri = $sayfaOku($hedef);

        if (trim($altVeri['metin']) === '') {
            $kalanSon[] = $aday;
            gunluk('  ' . $aday['bilgi']['baslik'] . ': alt sayfa okunamadı (' . $hedef . ')');
            continue;
        }

        gunluk(sprintf(
            '  %s: alt sayfa (%d karakter) %s',
            $aday['bilgi']['baslik'],
            mb_strlen($altVeri['metin']),
            $hedef
        ));

        $altAdaylar[] = [
            'bilgi'      => $aday['bilgi'],
            'url'        => $hedef,
            'sayfaMetni' => mb_substr($altVeri['metin'], 0, MODEL_SINIRI, 'UTF-8'),
        ];
    }

    if ($altAdaylar !== []) {
        $kalanSon = array_merge(
            $kalanSon,
            degerleriOku($okuyucu, $altAdaylar, $grupBoyu, $sonuclar, $hatali)
        );
    }
}

// --- 4. Siteye gonder -------------------------------------------------------

gunluk('---');
gunluk(count($sonuclar) . ' değer okundu, ' . count($kalanSon) . ' bulunamadı, ' . $hatali . ' hata.');

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

/**
 * Aday değerleri modele okutur; okunanları $sonuclar'a yazar.
 *
 * Gruplama SAYFAYA gore, sayiya gore degil. On bes bilginin on biri
 * iki sayfadan geliyor; sayiya gore gruplansaydi ayni sayfa metni her
 * aday icin istege yeniden kopyalanirdi. Sayfaya gore gruplayinca
 * metin bir kez gidiyor ve model o sayfadaki butun degerleri birlikte
 * ariyor — hem ucuz hem daha isabetli.
 *
 * @param list<array{bilgi:array,url:string,sayfaMetni:string}> $adaylar
 * @param list<array<string,mixed>>                             $sonuclar
 * @return list<array{bilgi:array,url:string,sayfaMetni:string}> okunamayanlar
 */
function degerleriOku(
    DegerOkuyucu $okuyucu,
    array $adaylar,
    int $grupBoyu,
    array &$sonuclar,
    int &$hatali
): array {
    $sayfayaGore = [];

    foreach ($adaylar as $aday) {
        $sayfayaGore[$aday['url']][] = $aday;
    }

    $gruplar = [];

    foreach ($sayfayaGore as $ayniSayfa) {
        foreach (array_chunk($ayniSayfa, max($grupBoyu, 8)) as $parca) {
            $gruplar[] = $parca;
        }
    }

    gunluk(count($gruplar) . ' grup halinde işlenecek (sayfa başına bir istek).');

    $kalan = [];

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
                $kalan[] = $aday;
                $neden   = trim((string) ($sonuc['not'] ?? 'belirtilmedi'));
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
                $kalan[] = $aday;
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

    return $kalan;
}

/**
 * Aranan bilgiye en çok benzeyen bağlantının adresini döndürür.
 *
 * Olcut ortak kelime sayisi. Yalnizca BASLIK yetmiyor: "Enflasyon
 * (TÜFE)" ile "Tüketici Fiyat Endeksi, Ağustos 2026" baglantisinin
 * tek ortak kelimesi bile yok. Bu yuzden basligin yani sira aciklama
 * ve arama ipucu da eslestirmeye giriyor — ipucunda zaten sayfada
 * gecen ifade yaziyor.
 *
 * Turkce katlama (I/İ/ı -> i) yapiliyor, yoksa "İndirimli" ile
 * "indirimli" bulusmaz.
 *
 * Hic yeterli ortaklik yoksa bos donuyor: yanlis sayfaya inip oradaki
 * alakasiz bir rakami getirmek, hic getirmemekten kotu.
 *
 * @param array<string,mixed>                 $bilgi
 * @param list<array{yazi:string,url:string}> $baglar
 */
function enYakinBag(array $bilgi, array $baglar): string
{
    $kelimeler = array_values(array_unique(array_merge(
        pratikKelimeler((string) ($bilgi['baslik'] ?? '')),
        pratikKelimeler((string) ($bilgi['aciklama'] ?? '')),
        pratikKelimeler((string) ($bilgi['arama_ipucu'] ?? ''))
    )));

    if ($kelimeler === []) {
        return '';
    }

    $enIyi     = '';
    $enIyiPuan = 0;

    foreach ($baglar as $bag) {
        $ortak = count(array_intersect($kelimeler, pratikKelimeler($bag['yazi'])));

        if ($ortak > $enIyiPuan) {
            $enIyiPuan = $ortak;
            $enIyi     = $bag['url'];
        }
    }

    /*
     * En az iki ortak kelime.
     *
     * Tek kelimelik denk gelme ("vergi", "oran", "tutar") neredeyse
     * her sayfada olur ve yanlis sayfaya inmeye yeter.
     */
    return $enIyiPuan >= 2 ? $enIyi : '';
}

/**
 * Metni karşılaştırmaya uygun kelimelere ayırır.
 *
 * @return list<string>
 */
function pratikKelimeler(string $metin): array
{
    $metin = str_replace(['İ', 'I', 'ı'], 'i', $metin);
    $metin = mb_strtolower($metin, 'UTF-8');
    $metin = str_replace("\xCC\x87", '', $metin);

    $kelimeler = preg_split('/[^\p{L}\p{N}]+/u', $metin, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique(array_filter(
        $kelimeler,
        // Uc harf de sayiliyor: "KDV", "SGK", "OTV" gibi kisaltmalar
        // tam da eslesmeyi tasiyan kelimeler.
        static fn (string $k): bool => mb_strlen($k, 'UTF-8') >= 3
    )));
}
