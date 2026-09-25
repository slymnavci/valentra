<?php
/** Bir ders+konu için pratik soru setlerini (soru sayılarıyla) listeler. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();

$dersId = trim((string)($_GET['ders_id'] ?? ''));
$konuId = trim((string)($_GET['konu_id'] ?? ''));

if ($dersId === '' || $konuId === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'ders_id ve konu_id zorunludur.'], 400);
}

$sorgu = $pdo->prepare(
    'SELECT s.*, (SELECT COUNT(*) FROM pratik_sorular p WHERE p.set_id = s.id) AS soru_sayisi
     FROM soru_setleri s WHERE s.ders_id = ? AND s.konu_id = ? ORDER BY s.olusturma_tarihi DESC'
);
$sorgu->execute([$dersId, $konuId]);

ppJsonYanit(['ok' => true, 'setler' => $sorgu->fetchAll()]);
