<?php
/** Bir pratik soru setini ve içindeki tüm soru/deneme kayıtlarını siler. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();
$id = (int)($govde['id'] ?? 0);

if ($id <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçersiz set id.'], 400);
}

$pdo->prepare('DELETE FROM denemeler WHERE set_id = ?')->execute([$id]);
$pdo->prepare('DELETE FROM pratik_sorular WHERE set_id = ?')->execute([$id]);
$pdo->prepare('DELETE FROM soru_setleri WHERE id = ?')->execute([$id]);

ppJsonYanit(['ok' => true]);
