<?php
/**
 * Bir üyelik hesabını siler. Yalnızca yönetici erişebilir. Ana yönetici
 * hesabı (savci) hiçbir zaman silinemez.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
ppYoneticiDogrula($pdo);
$govde = ppGovdeOku();
$kullaniciAdi = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));

if ($kullaniciAdi === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'kullanici_adi zorunludur.'], 400);
}
if ($kullaniciAdi === 'savci') {
    ppJsonYanit(['ok' => false, 'hata' => 'Bu hesap silinemez.'], 400);
}

$pdo->prepare('DELETE FROM kullanici_hesap WHERE kullanici_adi = ?')->execute([$kullaniciAdi]);

ppJsonYanit(['ok' => true]);
