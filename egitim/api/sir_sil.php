<?php
/** Bir sağlayıcı için kayıtlı API anahtarını siler. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının anahtarı silinemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$saglayici = trim((string)($govde['saglayici'] ?? ''));

if ($saglayici === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'saglayici zorunludur.'], 400);
}

$sil = $pdo->prepare('DELETE FROM kullanici_sir WHERE kullanici_adi = ? AND saglayici = ?');
$sil->execute([$kullaniciAdi, $saglayici]);

ppJsonYanit(['ok' => true]);
