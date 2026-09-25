<?php
/**
 * Kullanıcının kendi şifresini değiştirmesi — KASITLI OLARAK herkese
 * açıktır (ppYetkiKontrol çağırmaz, tıpkı giris_dogrula.php gibi).
 * Güvenlik, mevcut şifrenin doğrulanmasından gelir.
 */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();
$govde = ppGovdeOku();

$kullaniciAdi = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));
$eskiSifre = (string)($govde['eski_sifre'] ?? '');
$yeniSifre = (string)($govde['yeni_sifre'] ?? '');

if ($kullaniciAdi === '' || $eskiSifre === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Mevcut şifrenizi girin.'], 400);
}
if (strlen($yeniSifre) < 6) {
    ppJsonYanit(['ok' => false, 'hata' => 'Yeni şifre en az 6 karakter olmalıdır.'], 400);
}

$sorgu = $pdo->prepare('SELECT sifre_hash FROM kullanici_hesap WHERE kullanici_adi = ?');
$sorgu->execute([$kullaniciAdi]);
$hesap = $sorgu->fetch();

if (!$hesap || !password_verify($eskiSifre, $hesap['sifre_hash'])) {
    ppJsonYanit(['ok' => false, 'hata' => 'Mevcut şifre hatalı.'], 401);
}

$pdo->prepare('UPDATE kullanici_hesap SET sifre_hash = ? WHERE kullanici_adi = ?')
    ->execute([password_hash($yeniSifre, PASSWORD_DEFAULT), $kullaniciAdi]);

ppJsonYanit(['ok' => true]);
