<?php
/** Yönetici: bir forum kategorisini ve içindeki tüm konu/mesajları siler. */
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

$konular = $pdo->prepare('SELECT id FROM forum_konu WHERE kategori_id = ?');
$konular->execute([$id]);
$konuIdleri = array_column($konular->fetchAll(), 'id');

if ($konuIdleri) {
    $yerTutucu = implode(',', array_fill(0, count($konuIdleri), '?'));
    $pdo->prepare("DELETE FROM forum_mesaj WHERE konu_id IN ($yerTutucu)")->execute($konuIdleri);
    $pdo->prepare("DELETE FROM forum_konu WHERE id IN ($yerTutucu)")->execute($konuIdleri);
}

$pdo->prepare('DELETE FROM forum_kategori WHERE id = ?')->execute([$id]);

ppJsonYanit(['ok' => true]);
