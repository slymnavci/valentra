<?php
/** Yönetici: tek bir mesajı siler (moderasyon). Konunun açılış mesajı
 *  silinirse konu boş kalabilir — bu durumda konuyu da kaldırırız. */
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

$mesajSorgu = $pdo->prepare('SELECT konu_id FROM forum_mesaj WHERE id = ?');
$mesajSorgu->execute([$id]);
$mesaj = $mesajSorgu->fetch();
if (!$mesaj) {
    ppJsonYanit(['ok' => true]);
}

$pdo->prepare('DELETE FROM forum_mesaj WHERE id = ?')->execute([$id]);

$kalan = $pdo->prepare('SELECT COUNT(*) AS n FROM forum_mesaj WHERE konu_id = ?');
$kalan->execute([$mesaj['konu_id']]);
if ((int)$kalan->fetch()['n'] === 0) {
    $pdo->prepare('DELETE FROM forum_konu WHERE id = ?')->execute([$mesaj['konu_id']]);
}

ppJsonYanit(['ok' => true]);
