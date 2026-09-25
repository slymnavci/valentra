<?php
/** Bir konu sınavı sonucunu kaydeder (state.sinavlar'ın backend karşılığı). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkası adına konu sınavı kaydedilemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$konuId = trim((string)($govde['konu_id'] ?? ''));
$istemciId = trim((string)($govde['istemci_id'] ?? ''));
$dogru = (int)($govde['dogru'] ?? -1);
$toplam = (int)($govde['toplam'] ?? -1);
$puan = (int)($govde['puan'] ?? -1);
$gecti = !empty($govde['gecti']);

if ($konuId === '' || $dogru < 0 || $toplam <= 0 || $puan < 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'konu_id, dogru, toplam ve puan zorunludur.'], 400);
}

/* istemci_id verildiyse: aynı kayıt zaten varsa (senkron/ağ tekrarı) yeniden eklemeden döner. */
if ($istemciId !== '') {
    $mevcut = $pdo->prepare('SELECT id FROM kullanici_konu_sinav WHERE kullanici_adi = ? AND konu_id = ? AND istemci_id = ?');
    $mevcut->execute([$kullaniciAdi, $konuId, $istemciId]);
    $satir = $mevcut->fetch();
    if ($satir) ppJsonYanit(['ok' => true, 'id' => (int)$satir['id']]);
}

$ekle = $pdo->prepare(
    'INSERT INTO kullanici_konu_sinav (kullanici_adi, konu_id, istemci_id, tarih, dogru, toplam, puan, gecti) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$ekle->execute([$kullaniciAdi, $konuId, $istemciId !== '' ? $istemciId : null, gmdate('Y-m-d H:i:s'), $dogru, $toplam, $puan, $gecti ? 1 : 0]);

ppJsonYanit(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
