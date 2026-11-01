<?php
declare(strict_types=1);

/**
 * V Network akisinda okunan kart = bir sayfa goruntulenmesi (01.11.2026,
 * site sahibi: "network'te her okunan haber bir sayfa gibi gorunsun;
 * asagiya kaydirdikca okunan sayfa sayisi da degissin").
 *
 *   POST /network-okundu.php   hedef=h12 | g5, k=<tarayici anahtari>
 *
 * Akis betigi (nw_okuma_betigi) kart ekranda en az yarisi gorunur halde
 * 1,5 saniye kalinca bir kez gonderiyor. Haber karti o haberin okunmasi
 * olarak yaziliyor (haber_id, adres /network/h/12): okunma sayilari ve
 * panelin haber raporlari bunu goruyor. Uye paylasimi haber_id'siz bir
 * sayfa goruntulenmesi (/network/g/5).
 *
 * Satir dogrudan "insan" isaretli: istek ancak sayfa tarayicida acik ve
 * kart gercekten ekrandayken betikten geliyor (ikinci asama, bkz.
 * includes/ziyaret.php). Ayni ziyaretci ayni karti 30 dakikada bir kez
 * sayabiliyor; bot imzasi, yonetici ve "bu cihazi sayma" cerezi yine
 * ziyaret_sayilmali'da eleniyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ziyaret.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
http_response_code(204);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}

$hedef   = (string) ($_POST['hedef'] ?? '');
$anahtar = strtolower((string) ($_POST['k'] ?? ''));

if (!preg_match('/^([hg])(\d{1,10})$/', $hedef, $e)) {
    exit;
}

$haber = $e[1] === 'h';
$id    = (int) $e[2];

try {
    if ($haber) {
        $ifade = db()->prepare('SELECT baslik FROM haberler WHERE id = :id AND durum = :d');
        $ifade->execute(['id' => $id, 'd' => HABER_YAYINDA]);
        $baslik = $ifade->fetchColumn();
        $adres  = '/network/h/' . $id;
        $baslik = $baslik === false ? false : $baslik . ' — V Network';
    } else {
        $ifade = db()->prepare("SELECT u.ad FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id WHERE g.id = :id AND g.durum = 'yayinda'");
        $ifade->execute(['id' => $id]);
        $ad     = $ifade->fetchColumn();
        $adres  = '/network/g/' . $id;
        $baslik = $ad === false ? false : $ad . ' paylaştı — V Network';
    }

    if ($baslik === false) {
        exit;
    }

    // Ayni kisi ayni karti yarim saatte bir kez (sayfayi yenileyip
    // kaydirmak sayiyi sisirmesin).
    $son = db()->prepare('SELECT 1 FROM ziyaretler WHERE ziyaretci = :z AND yol = :y AND zaman > NOW() - INTERVAL 30 MINUTE LIMIT 1');
    $son->execute(['z' => ziyaretci_imzasi(), 'y' => $adres]);

    if ($son->fetchColumn() !== false) {
        exit;
    }

    // Satirin adresi kartin kendi adresi olsun (panelde "hangi sayfa okundu").
    // Sayac yalnizca GET sayfa isteklerini sayiyor (ziyaret_sayilmali); bu
    // istek POST ama bir sayfa okumasini bildiriyor, o yuzden GET gibi.
    $_SERVER['REQUEST_URI'] = $adres;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ziyaret_kaydet($haber ? $id : null, (string) $baslik);
    $zid = (int) ($GLOBALS['ziyaret_son_id'] ?? 0);

    if ($zid > 0) {
        db()->prepare("UPDATE ziyaretler SET insan = 1, tarayici_id = IF(tarayici_id = '', :k, tarayici_id) WHERE id = :id")
            ->execute(['k' => preg_match('/^[0-9a-f]{16}$/', $anahtar) ? $anahtar : '', 'id' => $zid]);
    }
} catch (PDOException $e) {
    error_log('[valentra] network okuma kaydi: ' . $e->getMessage());
}
