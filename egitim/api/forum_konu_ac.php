<?php
/**
 * Bir kategori altında yeni konu (thread) açar — açılış mesajıyla birlikte.
 * Uygulama anahtarı gerektirmez; kullanıcı kimliği oturum jetonundan
 * (X-Session-Token) doğrulanır — gövdedeki kullanici_adi'ne güvenilmez.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';

$pdo = ppBaglan();
$govde = ppGovdeOku();

$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$kategoriId = (int)($govde['kategori_id'] ?? 0);
$baslik = trim((string)($govde['baslik'] ?? ''));
$icerik = trim((string)($govde['icerik'] ?? ''));

if ($kategoriId <= 0 || $baslik === '' || $icerik === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Kategori, başlık ve içerik zorunludur.'], 400);
}

$kategoriVar = $pdo->prepare('SELECT 1 FROM forum_kategori WHERE id = ?');
$kategoriVar->execute([$kategoriId]);
if (!$kategoriVar->fetch()) {
    ppJsonYanit(['ok' => false, 'hata' => 'Kategori bulunamadı.'], 404);
}

$simdi = gmdate('Y-m-d H:i:s');
$pdo->beginTransaction();
$ekleKonu = $pdo->prepare('INSERT INTO forum_konu (kategori_id, baslik, kullanici_adi, olusturma_tarihi, son_aktivite) VALUES (?, ?, ?, ?, ?)');
$ekleKonu->execute([$kategoriId, $baslik, $kullaniciAdi, $simdi, $simdi]);
$konuId = (int)$pdo->lastInsertId();

$ekleMesaj = $pdo->prepare('INSERT INTO forum_mesaj (konu_id, kullanici_adi, icerik, olusturma_tarihi) VALUES (?, ?, ?, ?)');
$ekleMesaj->execute([$konuId, $kullaniciAdi, $icerik, $simdi]);
$pdo->commit();

ppJsonYanit(['ok' => true, 'id' => $konuId]);
