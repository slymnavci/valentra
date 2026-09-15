<?php
declare(strict_types=1);

/**
 * Ajanin derledigi haberleri taslak olarak alan uc.
 *
 *   POST /api/ingest.php
 *   Authorization: Bearer <anahtar>
 *   Content-Type: application/json
 *
 *   {"haberler": [{"baslik": "...", "icerik": "...", "kaynak_url": "...", ...}]}
 *
 * Gelen her kayit HER ZAMAN 'taslak' olarak yazilir. Bu uc hicbir kosulda
 * haberi yayina alamaz; yayina alma yetkisi yalnizca yonetici panelindedir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !isset($veri['haberler']) || !is_array($veri['haberler'])) {
    ajan_json(400, ['hata' => 'Gövde {"haberler": [...]} biçiminde olmalı.']);
}

if (count($veri['haberler']) > 100) {
    ajan_json(400, ['hata' => 'Tek istekte en fazla 100 haber gönderilebilir.']);
}

$kayit = db()->prepare(
    'INSERT INTO ajan_kayitlari (durum, bulunan) VALUES (:durum, :bulunan)'
);
$kayit->execute(['durum' => 'calisiyor', 'bulunan' => count($veri['haberler'])]);
$kayitId = (int) db()->lastInsertId();

$eklenen = 0;
$yinelenen = 0;
$hatalar = [];

foreach ($veri['haberler'] as $sira => $haber) {
    if (!is_array($haber)) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kayıt nesne değil.'];
        continue;
    }

    try {
        $sonuc = haber_taslak_ekle($haber);

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

db()->prepare(
    'UPDATE ajan_kayitlari
        SET bitis = NOW(), durum = :durum, eklenen = :eklenen,
            yinelenen = :yinelenen, mesaj = :mesaj
      WHERE id = :id'
)->execute([
    'durum'     => $hatalar === [] ? 'tamam' : 'hata',
    'eklenen'   => $eklenen,
    'yinelenen' => $yinelenen,
    'mesaj'     => $hatalar === [] ? '' : mb_substr(count($hatalar) . ' kayıt alınamadı.', 0, 600, 'UTF-8'),
    'id'        => $kayitId,
]);

ajan_json(200, [
    'durum'     => 'tamam',
    'eklenen'   => $eklenen,
    'yinelenen' => $yinelenen,
    'hatalar'   => $hatalar,
    'not'       => 'Haberler taslak olarak kaydedildi, yönetici onayı bekliyor.',
]);
