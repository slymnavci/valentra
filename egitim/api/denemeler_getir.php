<?php
/** Bir setin deneme geçmişini döner (kullanici_adi verilirse yalnızca o kullanıcının). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$setId = (int)($_GET['set_id'] ?? 0);
$filtreIstendi = trim((string)($_GET['kullanici_adi'] ?? '')) !== '';
/* Belirli bir kullanıcıya göre filtre istendiyse, kimlik istekten değil
   oturum jetonundan alınır — başkasının deneme geçmişi istenemez.
   Filtre istenmezse (setin tüm denemeleri) mevcut davranış değişmez. */
$kullaniciAdi = '';
if ($filtreIstendi) {
    $oturum = ppOturumDogrula($pdo);
    $kullaniciAdi = $oturum['kullaniciAdi'];
}

if ($setId <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçersiz set id.'], 400);
}

if ($kullaniciAdi !== '') {
    $sorgu = $pdo->prepare('SELECT * FROM denemeler WHERE set_id = ? AND kullanici_adi = ? ORDER BY tarih DESC');
    $sorgu->execute([$setId, $kullaniciAdi]);
} else {
    $sorgu = $pdo->prepare('SELECT * FROM denemeler WHERE set_id = ? ORDER BY tarih DESC');
    $sorgu->execute([$setId]);
}

$denemeler = array_map(function ($r) {
    $r['detay'] = $r['detay'] ? json_decode($r['detay'], true) : null;
    return $r;
}, $sorgu->fetchAll());

ppJsonYanit(['ok' => true, 'denemeler' => $denemeler]);
