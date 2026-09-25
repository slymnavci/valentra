<?php
/** Bir kategorideki tüm konuları (thread'leri) döner — herkese açıktır. */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();
$kategoriId = (int)($_GET['kategori_id'] ?? 0);

if ($kategoriId <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'kategori_id zorunludur.'], 400);
}

$satirlar = $pdo->prepare(
    "SELECT kn.id, kn.baslik, kn.kullanici_adi, kn.olusturma_tarihi, kn.son_aktivite,
            (SELECT COUNT(*) FROM forum_mesaj WHERE konu_id = kn.id) AS mesaj_sayisi
     FROM forum_konu kn
     WHERE kn.kategori_id = ?
     ORDER BY kn.son_aktivite DESC"
);
$satirlar->execute([$kategoriId]);

$konular = array_map(fn($r) => [
    'id' => (int)$r['id'],
    'baslik' => $r['baslik'],
    'kullaniciAdi' => $r['kullanici_adi'],
    'olusturmaTarihi' => $r['olusturma_tarihi'],
    'sonAktivite' => $r['son_aktivite'],
    'mesajSayisi' => (int)$r['mesaj_sayisi'],
], $satirlar->fetchAll());

ppJsonYanit(['ok' => true, 'konular' => $konular]);
