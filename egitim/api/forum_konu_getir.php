<?php
/** Bir konunun (thread) detayını ve tüm mesajlarını döner — herkese açıktır. */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();
$konuId = (int)($_GET['id'] ?? 0);

if ($konuId <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'id zorunludur.'], 400);
}

$konuSorgu = $pdo->prepare('SELECT id, kategori_id, baslik, kullanici_adi, olusturma_tarihi FROM forum_konu WHERE id = ?');
$konuSorgu->execute([$konuId]);
$konu = $konuSorgu->fetch();

if (!$konu) {
    ppJsonYanit(['ok' => false, 'hata' => 'Konu bulunamadı.'], 404);
}

$mesajSorgu = $pdo->prepare('SELECT id, kullanici_adi, icerik, olusturma_tarihi FROM forum_mesaj WHERE konu_id = ? ORDER BY id');
$mesajSorgu->execute([$konuId]);

ppJsonYanit(['ok' => true, 'konu' => [
    'id' => (int)$konu['id'],
    'kategoriId' => (int)$konu['kategori_id'],
    'baslik' => $konu['baslik'],
    'kullaniciAdi' => $konu['kullanici_adi'],
    'olusturmaTarihi' => $konu['olusturma_tarihi'],
], 'mesajlar' => array_map(fn($r) => [
    'id' => (int)$r['id'],
    'kullaniciAdi' => $r['kullanici_adi'],
    'icerik' => $r['icerik'],
    'olusturmaTarihi' => $r['olusturma_tarihi'],
], $mesajSorgu->fetchAll())]);
