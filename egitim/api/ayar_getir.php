<?php
/** Kullanıcının kaydedilmiş ayarlarını döner (yoksa veri: null). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının ayarları okunamaz. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

$sorgu = $pdo->prepare('SELECT veri, guncelleme_tarihi FROM kullanici_ayar WHERE kullanici_adi = ?');
$sorgu->execute([$kullaniciAdi]);
$satir = $sorgu->fetch();

if (!$satir) {
    ppJsonYanit(['ok' => true, 'veri' => null, 'guncelleme_tarihi' => null]);
}

ppJsonYanit(['ok' => true, 'veri' => json_decode($satir['veri'], true), 'guncelleme_tarihi' => $satir['guncelleme_tarihi']]);
