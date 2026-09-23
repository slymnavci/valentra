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
 * Makine okunur veri uclari: sabit izin listesi.
 *
 * Bu adresler hicbir kaynak satirinda yazmiyor — ajan onlari koddan
 * kuruyor (includes/ekonomi.php). Yine de site uzerinden getirilmeleri
 * gerekebiliyor: ajan GitHub'da kosuyor ve TCMB veri merkezi
 * IP'lerini engelleyebiliyor; site Turkiye'de barindigi icin
 * ulasabiliyor.
 *
 * Liste SABIT. "Her hosta izin ver" demek SSRF kapisini acmak olurdu;
 * burada yalnizca uc alan adi var ve ucu de resmi istatistik ucu.
 */
const VERI_UCLARI = [
    'evds3.tcmb.gov.tr',   // TCMB EVDS
    'api.worldbank.org',   // Dunya Bankasi
    'www.imf.org',         // IMF DataMapper
];

if (in_array($sunucu, VERI_UCLARI, true)) {
    $izinli = true;
}

/*
 * Karsilastirma SQL'de degil PHP'de: "www." onekini yok saymak ve
 * sunucu adini ayiklamak SQL'de cirkin bir ifade olurdu. Tanimli
 * adres sayisi birkac yuz, maliyeti onemsiz.
 *
 * Uc zaten izinliyse sorgu hic calismiyor.
 */
foreach ($izinli ? [] : [
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

/*
 * EVDS anahtari: SITE ekliyor, ajan gondermiyor.
 *
 * EVDS anahtari yalnizca "key" basligiyla kabul ediyor ve bu uc
 * ajanin basliklarini kaynaga tasimiyor. Anahtar zaten sitede kayitli
 * (panelde girildi); EVDS adresi istendiginde site kendisi ekliyor.
 * Boylece anahtar ne URL'ye ne ajan gunlugune yaziliyor.
 *
 * Yalnizca EVDS sunucusuna gidiyor: baska bir hosta gonderilseydi
 * anahtar ucuncu bir tarafa sizardi.
 */
$basliklar = [];

if ($sunucu === 'evds3.tcmb.gov.tr') {
    require_once __DIR__ . '/../includes/ayarlar.php';

    $evdsAnahtari = ayar_oku('evds_anahtari');

    if ($evdsAnahtari !== '') {
        $basliklar[] = 'key: ' . $evdsAnahtari;
    }
}

$indirme = http_getir($url, 25, '', $basliklar);

if (!$indirme['tamam']) {
    ajan_json(502, [
        'hata' => $indirme['neden'],
        'kod'  => $indirme['kod'],
    ]);
}

$ham = $indirme['govde'];
$kod = $indirme['kod'];

/*
 * HAM MOD.
 *
 * Ajan besleme (RSS/Atom) okurken HTML'den arindirilmis metin ISE
 * YARAMAZ; etiketler atilinca XML yapisi da gider. Bu yuzden ham=1
 * ile govde oldugu gibi donuyor.
 *
 * base64 ile gonderiliyor: beslemelerin bir kismi ISO-8859-9 gibi
 * UTF-8 olmayan kodlamalarda ve bunlari dogrudan JSON'a koymak
 * json_encode'u bos string dondurmeye iter. Kodlama cevrimini ajan
 * tarafi zaten yapiyor.
 */
if (isset($_GET['ham'])) {
    ajan_json(200, [
        'url'   => $url,
        'kod'   => $kod,
        'ham'   => base64_encode($ham),
        'bayt'  => strlen($ham),
    ]);
}

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

/*
 * Baglantilar metnin ICINE isaretle yaziliyor.
 *
 * Derleme sayfalarinin bir kismi degeri kendi tasimiyor; "Kidem
 * Tazminati Tavani ... Tiklayiniz" deyip baska sayfaya gonderiyor.
 * Baglantilari ayri bir liste olarak vermek yetmedi: "Tiklayiniz"
 * yazisinin kendisi hangi bilgiye ait oldugunu soylemiyor, listede
 * hangisinin izlenecegi anlasilmiyordu.
 *
 * Metnin icinde, tam durdugu yerde bir isaret olarak gectiginde ise
 * baglam korunuyor: "Kidem Tazminati Tavani ... Tiklayiniz [BAG:12]".
 * Ajan boylece hangi baglantiyi izleyecegini sorabiliyor.
 */
$baglar  = [];
$gorulen = [];
$sayfaKimligi = strtok($url, '#');

$temiz = (string) preg_replace_callback(
    '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
    static function (array $eslesme) use (&$baglar, &$gorulen, $url, $sunucu, $sayfaKimligi): string {
        $yazi = trim((string) preg_replace('/\s+/u', ' ', strip_tags($eslesme[2])));

        $hedef = guvenli_url(besleme_url_birlestir($url, html_entity_decode(
            trim($eslesme[1]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )));

        if ($hedef === '' || !sunucu_esit($sunucu, $hedef)) {
            return $yazi;
        }

        /*
         * Ayni sayfaya giden capa baglantilari atiliyor.
         *
         * Calismada ajan "#kidem-tazminati-tavani" capasini izleyip
         * ayni sayfayi bir kez daha modele gonderdi; bir istek bosa
         * gitti ve sonuc degismedi.
         */
        if (strtok($hedef, '#') === $sayfaKimligi) {
            return $yazi;
        }

        if (isset($gorulen[$hedef])) {
            return $yazi . ' [BAG:' . $gorulen[$hedef] . ']';
        }

        if (count($baglar) >= 400) {
            return $yazi;
        }

        $no              = count($baglar) + 1;
        $gorulen[$hedef] = $no;
        $baglar[]        = ['no' => $no, 'yazi' => $yazi, 'url' => $hedef];

        return $yazi . ' [BAG:' . $no . ']';
    },
    $temiz
) ?: $temiz;

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
