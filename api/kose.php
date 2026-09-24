<?php
declare(strict_types=1);

/**
 * Köşe yazısı ajanının ucu.
 *
 *   GET  -> yazi malzemesi: son saatlerin haberleri, son gunlerin yazi
 *           basliklari ve bugun kac yazi oldugu
 *   POST -> {"yazilar": [{"gun","sira","gundem","baslik","ozet","icerik","haber_idleri"}]}
 *
 * Gelen her yazi HER ZAMAN taslak olarak yazilir; bu uc hicbir kosulda
 * yazi yayimlayamaz. Yayina alma yalnizca panelde.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/kose.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $saat = max(6, min(72, (int) ($_GET['saat'] ?? 36)));

    ajan_json(200, [
        'bugun'        => date('Y-m-d'),
        'bugun_sayisi' => kose_bugun_sayisi(),
        'gunluk_tavan' => KOSE_GUNLUK_TAVAN,
        'haberler'     => kose_malzeme($saat),
        'onceki'       => kose_onceki(10),
    ]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !isset($veri['yazilar']) || !is_array($veri['yazilar'])) {
    ajan_json(400, ['hata' => 'Gövde {"yazilar": [...]} biçiminde olmalı.']);
}

if (count($veri['yazilar']) > KOSE_GUNLUK_TAVAN * 3) {
    ajan_json(400, ['hata' => 'Tek istekte en fazla ' . KOSE_GUNLUK_TAVAN * 3 . ' yazı gönderilebilir.']);
}

$eklenen   = 0;
$yinelenen = 0;
$hatalar   = [];

foreach ($veri['yazilar'] as $sira => $yazi) {
    if (!is_array($yazi)) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kayıt nesne değil.'];
        continue;
    }

    try {
        $sonuc = kose_taslak_ekle($yazi);

        if ($sonuc['durum'] === 'eklendi') {
            $eklenen++;
        } else {
            $yinelenen++;
        }
    } catch (InvalidArgumentException $e) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => $e->getMessage()];
    } catch (PDOException $e) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kaydedilemedi.'];
    }
}

ajan_json(200, [
    'durum'     => 'tamam',
    'eklenen'   => $eklenen,
    'yinelenen' => $yinelenen,
    'hatalar'   => $hatalar,
    'not'       => 'Yazılar taslak olarak kaydedildi, yönetici onayı bekliyor.',
]);
