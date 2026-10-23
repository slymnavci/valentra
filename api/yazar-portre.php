<?php
declare(strict_types=1);

/**
 * Köşe yazarı fotoğrafı ucu (ajan; bkz. ajan/portre.php).
 *
 *   GET  -> {"eksik": [{"anahtar","ad","gorunum"}]}  fotografi olmayan yazarlar
 *   POST -> {"anahtar": "...", "veri": "<base64 resim>"}
 *
 * Dosya JSON icinde base64: multipart istekler PHP2Shell onlemi yuzunden
 * PHP'ye ulasmadan reddediliyor (api/.htaccess). Resim icerigiyle
 * dogrulaniyor (JPEG/PNG/WebP, en az 200 piksel, en fazla 6 MB), GD varsa
 * 400x400 kare JPEG'e kucultuluyor; gorseller/yazar/<anahtar>.jpg.
 * Yalnizca KOSE_YAZARLARI'ndaki, cizim portresi olan yazarlar (Valentra
 * Yorumu logo).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/yazarlar.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $eksik = [];

    foreach (KOSE_YAZARLARI as $anahtar => $y) {
        if (($y['gorunum'] ?? '') !== '' && yazar_foto($anahtar) === null) {
            $eksik[] = ['anahtar' => $anahtar, 'ad' => $y['ad'], 'gorunum' => $y['gorunum']];
        }
    }

    ajan_json(200, ['eksik' => $eksik]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri    = json_decode((string) file_get_contents('php://input'), true);
$anahtar = (string) ($veri['anahtar'] ?? '');
$yazar   = yazar_bul($anahtar);

if ($yazar === null || ($yazar['gorunum'] ?? '') === '') {
    ajan_json(400, ['hata' => 'Bilinmeyen yazar.']);
}

$ham = base64_decode((string) ($veri['veri'] ?? ''), true);

if ($ham === false || $ham === '' || strlen($ham) > 6_000_000) {
    ajan_json(400, ['hata' => 'Resim verisi okunamadı ya da çok büyük.']);
}

$bilgi = @getimagesizefromstring($ham);

if (!is_array($bilgi) || !in_array($bilgi['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
    || $bilgi[0] < 200 || $bilgi[1] < 200) {
    ajan_json(400, ['hata' => 'Geçerli bir resim değil (JPEG/PNG/WebP, en az 200 piksel).']);
}

$klasor = dirname(__DIR__) . YAZAR_FOTO_YOLU;

if (!is_dir($klasor) && !@mkdir($klasor, 0755, true) && !is_dir($klasor)) {
    ajan_json(500, ['hata' => 'Fotoğraf klasörü oluşturulamadı.']);
}

/*
 * 400x400 kare JPEG: ortadan kare kirpilip kucultuluyor (portre ortada).
 * GD yoksa yalnizca JPEG kabul edilip oldugu gibi yaziliyor.
 */
$cikti = null;

if (function_exists('imagecreatefromstring') && ($kaynak = @imagecreatefromstring($ham)) !== false) {
    $en   = imagesx($kaynak);
    $boy  = imagesy($kaynak);
    $kare = min($en, $boy);
    $hedef = imagecreatetruecolor(400, 400);
    imagecopyresampled($hedef, $kaynak, 0, 0, (int) (($en - $kare) / 2), (int) (($boy - $kare) / 2), 400, 400, $kare, $kare);
    ob_start();
    imagejpeg($hedef, null, 86);
    $cikti = (string) ob_get_clean();
} elseif ($bilgi['mime'] === 'image/jpeg') {
    $cikti = $ham;
}

if ($cikti === null || $cikti === '') {
    ajan_json(500, ['hata' => 'Resim dönüştürülemedi (sunucuda GD yok).']);
}

$dosya  = $klasor . '/' . $anahtar . '.jpg';
$gecici = $dosya . '.' . bin2hex(random_bytes(4)) . '.tmp';

if (@file_put_contents($gecici, $cikti) === false || !@rename($gecici, $dosya)) {
    @unlink($gecici);
    ajan_json(500, ['hata' => 'Fotoğraf yazılamadı.']);
}

ajan_json(200, ['durum' => 'tamam', 'anahtar' => $anahtar, 'boyut' => strlen($cikti)]);
