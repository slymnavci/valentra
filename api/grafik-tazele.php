<?php
declare(strict_types=1);

/**
 * Grafik verisini tazeleme ucu.
 *
 *   POST -> eskimis grafiklerin verisini EVDS'den ceker
 *
 * Ajan her calismasinda (4 saatte bir) buraya durtuyor. Veriyi SITE
 * cekiyor, ajan degil: EVDS anahtari sitede kayitli ve site Turkiye'de
 * barindigi icin TCMB'ye ulasiyor; GitHub'in IP'si engellenebiliyor.
 *
 * Bu uc hicbir grafigi yayina almaz ya da kaldirmaz; yalnizca yayindaki
 * tanimlarin verisini tazeler. Basarisiz cekimde son iyi veri korunur.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/grafikler.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

// Barindirici izin veriyorsa sureyi uzat; vermiyorsa sure butcesi zaten
// 40 saniyede kesiyor.
@set_time_limit(90);

try {
    $ozet = grafik_bayatlari_tazele(3, 8, 40);
} catch (Throwable $e) {
    // Tablo yoksa (sema yukseltmesi yapilmadiysa) anlasilir bir yanit.
    ajan_json(500, ['hata' => 'Grafikler tazelenemedi: ' . $e->getMessage()]);
}

ajan_json(200, ['durum' => 'tamam'] + $ozet);
