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

header('Content-Type: application/json; charset=utf-8');

function json_cikis(int $kod, array $govde): void
{
    http_response_code($kod);
    echo json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_cikis(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

/** Authorization basligini sunucu farkliliklarina ragmen bulur. */
function yetki_basligi(): string
{
    $baslik = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($baslik === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $ad => $deger) {
            if (strcasecmp($ad, 'Authorization') === 0) {
                $baslik = $deger;
                break;
            }
        }
    }

    return is_string($baslik) ? $baslik : '';
}

$baslik = yetki_basligi();

if (!preg_match('/^Bearer\s+(\S+)$/i', trim($baslik), $eslesme)) {
    json_cikis(401, ['hata' => 'Authorization başlığı eksik.']);
}

$ifade = db()->prepare(
    'SELECT id FROM ajan_anahtarlari WHERE anahtar_hash = :hash AND aktif = 1 LIMIT 1'
);
$ifade->execute(['hash' => hash('sha256', $eslesme[1])]);
$anahtarId = $ifade->fetchColumn();

if ($anahtarId === false) {
    json_cikis(401, ['hata' => 'Geçersiz anahtar.']);
}

db()->prepare('UPDATE ajan_anahtarlari SET son_kullanim = NOW() WHERE id = :id')
    ->execute(['id' => $anahtarId]);

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !isset($veri['haberler']) || !is_array($veri['haberler'])) {
    json_cikis(400, ['hata' => 'Gövde {"haberler": [...]} biçiminde olmalı.']);
}

if (count($veri['haberler']) > 100) {
    json_cikis(400, ['hata' => 'Tek istekte en fazla 100 haber gönderilebilir.']);
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

json_cikis(200, [
    'durum'     => 'tamam',
    'eklenen'   => $eklenen,
    'yinelenen' => $yinelenen,
    'hatalar'   => $hatalar,
    'not'       => 'Haberler taslak olarak kaydedildi, yönetici onayı bekliyor.',
]);
