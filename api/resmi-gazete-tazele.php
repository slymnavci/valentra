<?php
declare(strict_types=1);

/**
 * Resmî Gazete fihristini tazeleme ucu.
 *
 *   POST -> bugunun ve dunun fihristini ceker, resmi_gazete tablosuna yazar
 *
 * Ajan her calismasinda buraya durtuyor. Fihristi SITE cekiyor: site
 * Turkiye'de ve resmigazete.gov.tr'ye dogrudan ulasiyor. Dun de
 * cekiliyor ki gece yarisina yakin cikan mukerrer sayilar kacmasin.
 *
 * Kayit silmez; alinamayan gunun eski kaydi oldugu gibi kalir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/resmi_gazete.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

@set_time_limit(90);

try {
    $ozet = rg_tazele(2, 15);
} catch (Throwable $e) {
    // Tablo yoksa (sema yukseltmesi yapilmadiysa) anlasilir bir yanit.
    ajan_json(500, ['hata' => 'Resmî Gazete tazelenemedi: ' . $e->getMessage()]);
}

ajan_json($ozet['gunler'] > 0 ? 200 : 502, ['durum' => $ozet['gunler'] > 0 ? 'tamam' : 'alinamadi'] + $ozet);
