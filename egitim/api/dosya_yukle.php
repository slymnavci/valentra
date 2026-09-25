<?php
/**
 * PDF/görsel dosyalarını doğrudan bu sunucunun (suleymanavci.com.tr)
 * public/materyaller/ klasörüne yazar. GitHub'a gerek yok — Yönetim
 * panelindeki her dosya seçimi bu uç nokta üzerinden anında canlıya
 * kaydedilir.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

if (!isset($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
    $kod = $_FILES['dosya']['error'] ?? UPLOAD_ERR_NO_FILE;
    $mesaj = $kod === UPLOAD_ERR_INI_SIZE || $kod === UPLOAD_ERR_FORM_SIZE
        ? 'Dosya çok büyük.' : 'Dosya yüklenemedi (eksik veya hatalı istek).';
    ppJsonYanit(['ok' => false, 'hata' => $mesaj], 400);
}

$dosya = $_FILES['dosya'];
$boyutSiniri = 20 * 1024 * 1024; // 20 MB
if ($dosya['size'] > $boyutSiniri) {
    ppJsonYanit(['ok' => false, 'hata' => 'Dosya 20 MB sınırını aşıyor.'], 400);
}

$izinliUzantilar = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
$adiOrijinal = $dosya['name'];
$uzanti = strtolower(pathinfo($adiOrijinal, PATHINFO_EXTENSION));
if (!in_array($uzanti, $izinliUzantilar, true)) {
    ppJsonYanit(['ok' => false, 'hata' => 'İzin verilmeyen dosya türü. (pdf, png, jpg, jpeg, gif, webp)'], 400);
}

$materyalKlasoru = dirname(__DIR__) . '/materyaller';
if (!is_dir($materyalKlasoru) || !is_writable($materyalKlasoru)) {
    ppJsonYanit(['ok' => false, 'hata' => 'Sunucuda public/materyaller/ klasörüne yazma izni yok.'], 500);
}

$yeniAd = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $uzanti;
$hedefYol = $materyalKlasoru . '/' . $yeniAd;

if (!move_uploaded_file($dosya['tmp_name'], $hedefYol)) {
    ppJsonYanit(['ok' => false, 'hata' => 'Dosya sunucuya kaydedilemedi.'], 500);
}

ppJsonYanit(['ok' => true, 'yol' => 'materyaller/' . $yeniAd, 'ad' => $adiOrijinal]);
