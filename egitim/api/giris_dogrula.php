<?php
/**
 * Giriş doğrulama — KASITLI OLARAK herkese açıktır (ppYetkiKontrol
 * çağırmaz). Güvenlik uygulama anahtarından değil, bcrypt ile doğrulanan
 * şifreden gelir. Başarılı girişte kullanıcıya özel bir oturum jetonu
 * üretilir (bkz. db.php: ppOturumAc, yetki.php: ppOturumDogrula).
 *
 * Giriş kullanıcı adıyla YA DA e-posta adresiyle yapılabilir ("@"
 * içeren giriş e-posta sayılır). Aynı IP'den 15 dakikada 10 hatalı
 * denemeden sonra geçici olarak kilitlenir.
 */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();
$govde = ppGovdeOku();

$giris = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));
$sifre = (string)($govde['sifre'] ?? '');

if ($giris === '' || $sifre === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı adı (ya da e-posta) ve şifre zorunludur.'], 400);
}

ppDenemeSiniri($pdo, 'giris', 10, 15, false);

$sorgu = strpos($giris, '@') !== false
    ? $pdo->prepare('SELECT * FROM kullanici_hesap WHERE LOWER(eposta) = ? LIMIT 1')
    : $pdo->prepare('SELECT * FROM kullanici_hesap WHERE kullanici_adi = ? LIMIT 1');
$sorgu->execute([$giris]);
$hesap = $sorgu->fetch();

if (!$hesap || !password_verify($sifre, $hesap['sifre_hash'])) {
    ppDenemeSiniri($pdo, 'giris', 10, 15);
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı adı/e-posta veya şifre hatalı.'], 401);
}

ppJsonYanit(['ok' => true, 'kullanici' => ppOturumAc($pdo, $hesap)]);
