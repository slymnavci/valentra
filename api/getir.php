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

ajan_json(200, [
    'url'   => $url,
    'kod'   => $kod,
    'metin' => mb_substr(implode("\n", $satirlar), 0, 20000, 'UTF-8'),
]);
