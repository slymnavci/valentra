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

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$url = guvenli_url((string) ($_GET['url'] ?? ''));

if ($url === '') {
    ajan_json(400, ['hata' => 'url parametresi http/https adresi olmalı.']);
}

/*
 * Izin listesi: adres veritabaninda tanimli olmali.
 *
 * Hem pratik bilgilerin kaynaklari hem de haber kaynaklarinin
 * adresleri kabul ediliyor; ikisi de yonetici tarafindan girilmis
 * adresler.
 */
$izinli = false;

/*
 * Her yer tutucu AYRI adla veriliyor.
 *
 * Emulate prepares kapali oldugu icin (bootstrap'ta oyle ayarli) MySQL
 * ayni isimli yer tutucunun tekrar kullanilmasina izin vermiyor;
 * "Invalid parameter number" ile dusuyor. Ilk surumde sorgu bu yuzden
 * catlamis ve haber kaynaklarinin izin listesi hic calismamisti.
 */
foreach ([
    ['SELECT 1 FROM pratik_bilgiler WHERE kaynak_url = :u1 LIMIT 1',
     ['u1' => $url]],
    ['SELECT 1 FROM kaynaklar
       WHERE besleme_url = :u1 OR liste_url = :u2 OR site_url = :u3
       LIMIT 1',
     ['u1' => $url, 'u2' => $url, 'u3' => $url]],
] as [$sorgu, $degerler]) {
    $ifade = db()->prepare($sorgu);
    $ifade->execute($degerler);

    if ($ifade->fetchColumn() !== false) {
        $izinli = true;
        break;
    }
}

if (!$izinli) {
    ajan_json(403, [
        'hata' => 'Bu adres tanımlı kaynaklar arasında değil. '
                . 'Yalnızca panelde tanımlı adresler getirilebilir.',
    ]);
}

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_ENCODING       => '',
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                            . 'Chrome/128.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9',
    ],
]);

$ham  = curl_exec($ch);
$kod  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$hata = curl_error($ch);
curl_close($ch);

if (!is_string($ham)) {
    ajan_json(502, ['hata' => 'Sayfaya ulaşılamadı: ' . $hata, 'kod' => 0]);
}

if ($kod < 200 || $kod >= 300) {
    ajan_json(502, ['hata' => 'Sunucu HTTP ' . $kod . ' döndü.', 'kod' => $kod]);
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

$temiz = (string) preg_replace('#</(p|div|li|tr|h[1-6]|br)\s*/?>#i', "\n", $temiz);
$metin = html_entity_decode(strip_tags($temiz), ENT_QUOTES | ENT_HTML5, 'UTF-8');

$satirlar = [];

foreach (preg_split('/\R/u', $metin) ?: [] as $satir) {
    $satir = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $satir));

    if ($satir !== '') {
        $satirlar[] = $satir;
    }
}

ajan_json(200, [
    'url'   => $url,
    'kod'   => $kod,
    'metin' => mb_substr(implode("\n", $satirlar), 0, 20000, 'UTF-8'),
]);
