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
require_once __DIR__ . '/../includes/http_ortak.php';

$kanun = kanun_bul(trim((string) ($_GET['k'] ?? '')));

if ($kanun === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kanun bulunamadı.';
    exit;
}

$adresler = kanun_metin_adresleri($kanun);
$sonuc    = http_getir($adresler['pdf'], 40);

/*
 * Imza kontrolu: kaynak hata sayfasi dondurmus olabilir. O HTML'i
 * PDF diye gondermek tarayicida bozuk dosya uyarisi cikarirdi;
 * bunun yerine acik bir hata donuyoruz.
 */
if (!$sonuc['tamam'] || !str_starts_with($sonuc['govde'], '%PDF')) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Resmî metin şu anda alınamadı.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($sonuc['govde']));
header('Content-Disposition: inline; filename="' . (int) $kanun['no'] . '.pdf"');
// Kaynak sayfayi her acilista yormamak icin tarayici onbellegi.
header('Cache-Control: public, max-age=21600');

echo $sonuc['govde'];
