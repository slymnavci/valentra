<?php
/** Yönetici: bir konuyu ve tüm mesajlarını siler (moderasyon). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
ppYoneticiDogrula($pdo);
$govde = ppGovdeOku();
$id = (int)($govde['id'] ?? 0);

if ($id <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçerli bir id zorunludur.'], 400);
}

$pdo->prepare('DELETE FROM forum_mesaj WHERE konu_id = ?')->execute([$id]);
$pdo->prepare('DELETE FROM forum_konu WHERE id = ?')->execute([$id]);

ppJsonYanit(['ok' => true]);
