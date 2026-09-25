<?php
/** Bir pratik denemesinin (bilgi kartı veya çoktan seçmeli) sonucunu kaydeder. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkası adına deneme kaydedilemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$setId = (int)($govde['set_id'] ?? 0);
$dogru = (int)($govde['dogru'] ?? -1);
$toplam = (int)($govde['toplam'] ?? -1);
$puan = (int)($govde['puan'] ?? -1);
$detay = $govde['detay'] ?? null;

if ($setId <= 0 || $dogru < 0 || $toplam <= 0 || $puan < 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'set_id, dogru, toplam ve puan zorunludur.'], 400);
}

$ekle = $pdo->prepare(
    'INSERT INTO denemeler (set_id, kullanici_adi, tarih, dogru, toplam, puan, detay) VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$ekle->execute([
    $setId, $kullaniciAdi, gmdate('Y-m-d H:i:s'), $dogru, $toplam, $puan,
    $detay !== null ? json_encode($detay, JSON_UNESCAPED_UNICODE) : null
]);

ppJsonYanit(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
