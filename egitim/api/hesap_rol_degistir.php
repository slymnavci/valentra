<?php
/**
 * Bir üyenin rolünü (uye/yonetici) değiştirir. Yalnızca yönetici erişebilir.
 * Ana yönetici hesabının (savci) rolü değiştirilemez.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
ppYoneticiDogrula($pdo);
$govde = ppGovdeOku();
$kullaniciAdi = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));
$rol = ($govde['rol'] ?? '') === 'yonetici' ? 'yonetici' : 'uye';

if ($kullaniciAdi === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'kullanici_adi zorunludur.'], 400);
}
if ($kullaniciAdi === 'savci') {
    ppJsonYanit(['ok' => false, 'hata' => 'Bu hesabın rolü değiştirilemez.'], 400);
}

$pdo->prepare('UPDATE kullanici_hesap SET rol = ? WHERE kullanici_adi = ?')->execute([$rol, $kullaniciAdi]);

ppJsonYanit(['ok' => true]);
