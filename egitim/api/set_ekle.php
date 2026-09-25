<?php
/** Yeni bir pratik soru seti oluşturur (örn. "Sınav 1"). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

$dersId = trim((string)($govde['ders_id'] ?? ''));
$konuId = trim((string)($govde['konu_id'] ?? ''));
$ad = trim((string)($govde['ad'] ?? ''));

if ($dersId === '' || $konuId === '' || $ad === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'ders_id, konu_id ve ad zorunludur.'], 400);
}

$ekle = $pdo->prepare('INSERT INTO soru_setleri (ders_id, konu_id, ad, olusturma_tarihi) VALUES (?, ?, ?, ?)');
$ekle->execute([$dersId, $konuId, $ad, gmdate('Y-m-d H:i:s')]);

ppJsonYanit(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
