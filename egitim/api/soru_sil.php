<?php
/** Tek bir pratik sorusunu siler. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();
$id = (int)($govde['id'] ?? 0);

if ($id <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçersiz soru id.'], 400);
}

$pdo->prepare('DELETE FROM pratik_sorular WHERE id = ?')->execute([$id]);

ppJsonYanit(['ok' => true]);
