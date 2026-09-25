<?php
/**
 * Bir konuya cevap (mesaj) ekler. Uygulama anahtarı gerektirmez; kullanıcı
 * kimliği oturum jetonundan (X-Session-Token) doğrulanır — gövdedeki
 * kullanici_adi'ne güvenilmez.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';

$pdo = ppBaglan();
$govde = ppGovdeOku();

$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$konuId = (int)($govde['konu_id'] ?? 0);
$icerik = trim((string)($govde['icerik'] ?? ''));

if ($konuId <= 0 || $icerik === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Konu ve içerik zorunludur.'], 400);
}

$konuVar = $pdo->prepare('SELECT 1 FROM forum_konu WHERE id = ?');
$konuVar->execute([$konuId]);
if (!$konuVar->fetch()) {
    ppJsonYanit(['ok' => false, 'hata' => 'Konu bulunamadı.'], 404);
}

$simdi = gmdate('Y-m-d H:i:s');
$ekle = $pdo->prepare('INSERT INTO forum_mesaj (konu_id, kullanici_adi, icerik, olusturma_tarihi) VALUES (?, ?, ?, ?)');
$ekle->execute([$konuId, $kullaniciAdi, $icerik, $simdi]);

$pdo->prepare('UPDATE forum_konu SET son_aktivite = ? WHERE id = ?')->execute([$simdi, $konuId]);

ppJsonYanit(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
