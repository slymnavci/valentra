<?php
/** Yönetici: yeni forum kategorisi (başlığı) ekler. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
ppYoneticiDogrula($pdo);
$govde = ppGovdeOku();

$ad = trim((string)($govde['ad'] ?? ''));
$aciklama = trim((string)($govde['aciklama'] ?? ''));

if ($ad === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Başlık adı zorunludur.'], 400);
}

$siraSonuc = $pdo->query('SELECT COALESCE(MAX(sira), -1) AS m FROM forum_kategori')->fetch();
$sira = (int)$siraSonuc['m'] + 1;

$ekle = $pdo->prepare('INSERT INTO forum_kategori (ad, aciklama, sira, olusturma_tarihi) VALUES (?, ?, ?, ?)');
$ekle->execute([$ad, $aciklama !== '' ? $aciklama : null, $sira, gmdate('Y-m-d H:i:s')]);

ppJsonYanit(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
