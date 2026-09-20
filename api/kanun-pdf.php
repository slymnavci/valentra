<?php
declare(strict_types=1);

/**
 * Kanunun resmî PDF metnini site üzerinden aktarır.
 *
 * Neden aracilik: PDF dogrudan mevzuat.gov.tr'den bir cerceve icine
 * konamiyor (X-Frame-Options). Kendi adresimizden sunuldugunda ise
 * tarayicinin kendi PDF goruntuleyicisi sayfanin icinde aciliyor —
 * istenen buydu: kanun metni Valentra sayfasindan cikmadan okunsun.
 *
 * Neden SSRF riski yok: adres istekten ALINMIYOR. Yalnizca kanun
 * numarasi aliniyor, adres kendi kanun listemizden uretiliyor.
 * Listede olmayan bir numara 404 aliyor.
 *
 * Dosya diske YAZILMIYOR: kanun metninin kalici kopyasini tutmamak
 * bilincli bir tercih (guncelligi garanti etmek icin). Bunun yerine
 * tarayiciya onbellek izni veriliyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/kanunlar.php';
require_once __DIR__ . '/../includes/kanun_metni.php';
require_once __DIR__ . '/../includes/http_ortak.php';

$kanun = kanun_bul(trim((string) ($_GET['k'] ?? '')));

if ($kanun === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kanun bulunamadı.';
    exit;
}

require_once __DIR__ . '/../includes/kanun_dosya.php';

/*
 * Hangi adayin calistigini sayfa zaten bulmus ve onbellege yazmis
 * oluyor; burada ayni aramayi bastan yapmanin anlami yok. Onbellek
 * yoksa adaylar yeniden deneniyor.
 */
$adres = kanun_pdf_adresi($kanun);

/*
 * Resmi kaynaklarin hicbiri vermiyorsa panelden yuklenmis kopya
 * gonderiliyor. Dosya includes/ altinda duruyor ve o klasor dogrudan
 * erisime kapali; tek cikis yolu burasi.
 *
 * Adres istekten ALINMIYOR: yalnizca kanun numarasi aliniyor ve yol
 * ondan uretiliyor, yani istekle baska bir dosya istenemez.
 */
if ($adres === null && kanun_dosya_var_mi((int) $kanun['no'])) {
    $yol = kanun_dosya_yolu((int) $kanun['no']);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($yol));
    header('Content-Disposition: inline; filename="' . (int) $kanun['no'] . '.pdf"');
    // Yuklenen dosya panelden degistirilebiliyor; uzun onbellek eski
    // kopyayi ziyaretcide birakirdi.
    header('Cache-Control: public, max-age=900');

    readfile($yol);
    exit;
}

if ($adres === null) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Resmî metin şu anda alınamadı.';
    exit;
}

/*
 * Dosya parca parca aktariliyor, tamami bellege alinmadan.
 *
 * Kanun PDF'leri birkac megabayti buluyor; tamamini bellekte tutmak
 * paylasimli hostingin bellek sinirina dayanabiliyor ve ilk bayt
 * ziyaretciye ancak indirme bitince ulasiyordu.
 *
 * Imza kontrolu ilk parcada yapiliyor: kaynak hata sayfasi dondurmus
 * olabilir ve o HTML'i PDF diye gondermek tarayicida bozuk dosya
 * uyarisi cikarirdi. Basliklar ancak imza dogrulandiktan SONRA
 * gonderiliyor; boylece hata durumunda 502 donebiliyoruz.
 *
 * Aktarim ORTASINDA kopmaya karsi: basliklar gittikten sonra 502
 * donemeyiz, yani yarim kalmis bir PDF "basarili" gorunur ve alti saat
 * boyunca tarayici onbelleginde kalabilir. Bunu kaynagin bildirdigi
 * Content-Length'i aynen gecirerek onluyoruz — govde kisa kalirsa
 * tarayici yaniti eksik sayip atar. Uzunluk bilinmiyorsa tamligi
 * dogrulayamayiz, o yuzden o yanit onbellege hic verilmiyor.
 */
$basladi   = false;
$ilk       = '';
$uzunluk   = null;

// Sunucunun kendi tamponu araya girmesin; PDF akarak gitsin.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$ch = curl_init($adres);
curl_setopt_array($ch, http_ortak_secenekler(40, 8, kanun_referer($kanun)));
curl_setopt($ch, CURLOPT_ENCODING, 'identity');
curl_setopt(
    $ch,
    CURLOPT_HEADERFUNCTION,
    static function ($islem, string $baslik) use (&$uzunluk): int {
        /*
         * Yonlendirme varsa her adimin kendi basliklari gelir; yeni bir
         * yanit basladiginda onceki adimin uzunlugu gecersizdir.
         */
        if (stripos($baslik, 'HTTP/') === 0) {
            $uzunluk = null;
        } elseif (preg_match('/^Content-Length:\s*(\d+)/i', $baslik, $es) === 1) {
            $uzunluk = (int) $es[1];
        }

        return strlen($baslik);
    }
);
curl_setopt(
    $ch,
    CURLOPT_WRITEFUNCTION,
    static function ($islem, string $parca) use (&$basladi, &$ilk, &$uzunluk, $kanun): int {
        if ($basladi) {
            echo $parca;

            return strlen($parca);
        }

        $ilk .= $parca;

        // Imzayi gorecek kadar veri gelmediyse beklemeye devam.
        if (strlen($ilk) < 4) {
            return strlen($parca);
        }

        if (!str_starts_with($ilk, '%PDF')) {
            // Farkli bir uzunluk dondurmek aktarimi keser.
            return 0;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . (int) $kanun['no'] . '.pdf"');

        if ($uzunluk !== null) {
            header('Content-Length: ' . $uzunluk);
            // Kaynak sayfayi her acilista yormamak icin tarayici onbellegi.
            header('Cache-Control: public, max-age=21600');
        } else {
            // Tamligi dogrulayamayacagimiz yaniti onbellege vermeyiz.
            header('Cache-Control: no-store');
        }

        $basladi = true;
        echo $ilk;

        return strlen($parca);
    }
);

curl_exec($ch);
$hataNo = curl_errno($ch);
curl_close($ch);

/*
 * Aktarim basladiktan sonraki hatada yapabilecegimiz tek sey govdeyi
 * orada birakmak: bildirilen Content-Length tutmayacagi icin tarayici
 * yarim dosyayi kabul etmez. Baslamadiysa acik bir 502 donuyoruz.
 */
if (!$basladi) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Resmî metin şu anda alınamadı.';
} elseif ($hataNo !== 0) {
    error_log('kanun-pdf: aktarim yarida kesildi (' . (int) $kanun['no']
            . ', curl ' . $hataNo . ')');
}
