<?php
declare(strict_types=1);

/**
 * Pratik bilgiler ucu.
 *
 *   GET  -> ajanin toplayacagi bilgilerin listesi
 *   POST -> ajanin getirdigi degerler (yalnizca ADAY olarak yazilir)
 *
 * Bu uc hicbir kosulda yayindaki degeri degistiremez; yayina alma
 * yetkisi yalnizca yonetim panelindedir. Haber ucuyla ayni ilke, ama
 * burada daha kritik: buradaki rakam dogrudan hesaplamada kullaniliyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/pratik.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ajan_json(200, ['bilgiler' => pratik_toplanacaklar()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !isset($veri['bilgiler']) || !is_array($veri['bilgiler'])) {
    ajan_json(400, ['hata' => 'Gövde {"bilgiler": [...]} biçiminde olmalı.']);
}

$aday = 0;
$degismedi = 0;
$hatalar = [];

foreach ($veri['bilgiler'] as $sira => $bilgi) {
    if (!is_array($bilgi)) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kayıt nesne değil.'];
        continue;
    }

    try {
        $sonuc = pratik_aday_yaz($bilgi);

        if ($sonuc['durum'] === 'aday') {
            $aday++;
        } else {
            $degismedi++;
        }
    } catch (InvalidArgumentException $e) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => $e->getMessage()];
    } catch (PDOException $e) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kaydedilemedi.'];
    }
}

ajan_json(200, [
    'durum'     => $hatalar === [] ? 'tamam' : 'hata',
    'aday'      => $aday,
    'degismedi' => $degismedi,
    'hatalar'   => $hatalar,
    'not'       => 'Değerler onay bekliyor; yayındaki değerler değişmedi.',
]);
