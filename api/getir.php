<?php
declare(strict_types=1);

/**
 * Sayfa getirme ucu — dis sayfalari SITE sunucusundan okur.
 *
 * Neden var: Turk kamu siteleri (GIB, TUIK, HMB, CSGB, mevzuat.gov.tr)
 * veri merkezi IP'lerini engelliyor. Ajan GitHub uzerinde calistigi
 * icin bu sayfalarin hicbirine ULASAMIYOR; pratik bilgi toplama
 * calismasinda 15 sayfanin 13'u "okunamadi" dondu.
 *
 * Site ise Turkiye'de barindiriliyor ve ayni adreslere ulasabiliyor:
 * panelden yapilan testte GIB "HTTP 404" dondurdu — yani baglanti
 * kuruldu, yalnizca adres yanlisti. Baglanti engellenseydi hic yanit
 * gelmezdi.
 *
 * Bu yuzden ajan sayfayi kendisi indirmek yerine siteden istiyor.
 *
 * GUVENLIK — bu uc acik bir vekil sunucu DEGIL:
 *   - Ajan anahtari zorunlu.
 *   - Yalnizca veritabaninda TANIMLI adresler getirilebilir. Serbest
 *     adres kabul edilseydi, anahtari ele geciren biri bunu ic ag
 *     taramasi icin kullanabilirdi (SSRF).
 *   - Yalnizca http/https.
 *   - Yanit duz metne indirgenip kirpilarak donuyor; ham HTML
 *     tasinmiyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/http_ortak.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$url = guvenli_url((string) ($_GET['url'] ?? ''));

if ($url === '') {
    ajan_json(400, ['hata' => 'url parametresi http/https adresi olmalı.']);
}

/**
 * İki adresin aynı siteye ait olup olmadığına bakar.
 *
 * "www." onekini yok sayiyoruz: ayni site bazen www ile bazen onsuz
 * yaziliyor ve bu ayrimi ciddiye almak izin listesini sebepsiz yere
 * bozuyor (ismmmo.org.tr / www.ismmmo.org.tr gibi).
 */
function sunucu_esit(string $sunucu, string $tanimliUrl): bool
{
    $tanimli = strtolower((string) parse_url($tanimliUrl, PHP_URL_HOST));

    if ($tanimli === '') {
        return false;
    }

    $sadelestir = static fn (string $ad): string
        => preg_replace('/^www\./', '', $ad) ?? $ad;

    return $sadelestir($sunucu) === $sadelestir($tanimli);
}

/*
 * Izin listesi: adresin SUNUCUSU veritabaninda tanimli olmali.
 *
 * Tam adres esitligi cok darmis: derleme sayfalarinin bir kismi
 * fihrist cikiyor ve asil rakamlar alt sayfalarda. Alt sayfalari
 * getiremeyince o bilgiler hic toplanamiyordu.
 *
 * Bu yuzden olcut "ayni sunucu" oldu. SSRF korumasi duruyor: sunucu
 * yine de yoneticinin tanimladigi kaynaklardan biri olmak zorunda,
 * yani ic aga ya da rastgele bir siteye istek yapilamiyor.
 */
$sunucu = strtolower((string) parse_url($url, PHP_URL_HOST));

if ($sunucu === '') {
    ajan_json(400, ['hata' => 'Adres çözümlenemedi.']);
}

$izinli = false;

/*
 * Karsilastirma SQL'de degil PHP'de: "www." onekini yok saymak ve
 * sunucu adini ayiklamak SQL'de cirkin bir ifade olurdu. Tanimli
 * adres sayisi birkac yuz, maliyeti onemsiz.
 */
foreach ([
    'SELECT kaynak_url FROM pratik_bilgiler
      WHERE kaynak_url IS NOT NULL AND kaynak_url <> \'\'',
    'SELECT besleme_url FROM kaynaklar WHERE besleme_url IS NOT NULL',
    'SELECT liste_url   FROM kaynaklar WHERE liste_url   IS NOT NULL',
    'SELECT site_url    FROM kaynaklar WHERE site_url    IS NOT NULL',
] as $sorgu) {
    foreach (db()->query($sorgu)->fetchAll(PDO::FETCH_COLUMN) as $tanimli) {
        if (sunucu_esit($sunucu, (string) $tanimli)) {
            $izinli = true;
            break 2;
        }
    }
}

if (!$izinli) {
    ajan_json(403, [
        'hata' => 'Bu adresin sunucusu tanımlı kaynaklar arasında değil. '
                . 'Yalnızca panelde tanımlı sitelerden sayfa getirilebilir.',
    ]);
}

$indirme = http_getir($url, 25);

if (!$indirme['tamam']) {
    ajan_json(502, [
        'hata' => $indirme['neden'],
        'kod'  => $indirme['kod'],
    ]);
}

$ham = $indirme['govde'];
$kod = $indirme['kod'];

/*
 * Duz metne indirgeme.
 *
 * Ajanin ihtiyaci metin; ham HTML'i tasimak hem gereksiz buyuk hem de
 * ajan tarafinda ayrica temizlik gerektirir.
 */
$temiz = (string) preg_replace(
    '#<(script|style|noscript|svg|nav|footer|header|form)\b[^>]*>.*?</\1>#is',
    ' ',
    $ham
);

$temiz = (string) preg_replace('#</(p|div|li|tr|h[1-6]|br)\s*/?>#i', "\n", $temiz);
$metin = html_entity_decode(strip_tags($temiz), ENT_QUOTES | ENT_HTML5, 'UTF-8');

$satirlar = [];

foreach (preg_split('/\R/u', $metin) ?: [] as $satir) {
    $satir = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $satir));

    if ($satir !== '') {
        $satirlar[] = $satir;
    }
}

/*
 * Sayfadaki baglantilar da doniyor.
 *
 * Derleme sayfalarinin bir kismi fihrist: yalnizca baslik listesi
 * tasiyor, rakamlar alt sayfalarda. Ajan boyle bir sayfaya dustugunde
 * aradigi basliga giden baglantiyi bulup oraya inebilsin diye
 * baglantilar da gonderiliyor. Yalnizca ayni sitedekiler ve makul
 * bir sayida.
 */
$baglar  = [];
$gorulen = [];

if (preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $ham, $eslesmeler, PREG_SET_ORDER)) {
    foreach ($eslesmeler as $eslesme) {
        $hedef = guvenli_url(besleme_url_birlestir($url, html_entity_decode(
            trim($eslesme[1]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )));

        if ($hedef === '' || !sunucu_esit($sunucu, $hedef) || isset($gorulen[$hedef])) {
            continue;
        }

        $yazi = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($eslesme[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        // Yazisi olmayan baglanti (logo, ikon) eslestirmede ise yaramaz.
        if ($yazi === '' || mb_strlen($yazi, 'UTF-8') > 160) {
            continue;
        }

        $gorulen[$hedef] = true;
        $baglar[]        = ['yazi' => $yazi, 'url' => $hedef];

        if (count($baglar) >= 400) {
            break;
        }
    }
}

/*
 * Kirpma siniri yuksek tutuluyor.
 *
 * Onceki sinir 20.000 karakterdi ve pratik bilgi derlemeleri bundan
 * cok daha uzun: Alomaliye sayfasi tam 20.000'de kesilmisti, yani
 * aranan tablolarin cogu metne hic girmemisti. Alti bilgiden besi
 * "sayfada yok" diye donmustu — oysa sayfadaydilar.
 */
$sinir = (int) ($_GET['uzunluk'] ?? 200000);
$sinir = max(2000, min(400000, $sinir));

ajan_json(200, [
    'url'    => $url,
    'kod'    => $kod,
    'metin'  => mb_substr(implode("\n", $satirlar), 0, $sinir, 'UTF-8'),
    'baglar' => $baglar,
]);
