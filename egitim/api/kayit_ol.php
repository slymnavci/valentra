<?php
/**
 * Herkese açık üye kaydı: ad soyad, e-posta, kullanıcı adı ve kişinin
 * kendi belirlediği şifre. Başarılı kayıtta oturum da açılır (giriş
 * yanıtıyla aynı biçim).
 *
 * Yeni üye 'uye' rolüyle ve menü kısıtlaması olmadan açılır; yönetici
 * Yönetim → Üyeler'den rolünü ya da erişimini değiştirebilir.
 *
 * Koruma: aynı IP'den saatte en fazla 5 kayıt; "web" alanı botlar için
 * tuzak (insan kullanıcıda boş gelir, doluysa kayıt sessizce reddedilir).
 */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();
$govde = ppGovdeOku();

if (trim((string)($govde['web'] ?? '')) !== '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Kayıt tamamlanamadı.'], 400);
}

$ad = trim(preg_replace('/\s+/u', ' ', (string)($govde['ad'] ?? '')));
$eposta = strtolower(trim((string)($govde['eposta'] ?? '')));
$kullaniciAdi = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));
$sifre = (string)($govde['sifre'] ?? '');

if (mb_strlen($ad) < 3 || mb_strlen($ad) > 120) {
    ppJsonYanit(['ok' => false, 'hata' => 'Ad soyad 3–120 karakter olmalıdır.'], 400);
}
if (!filter_var($eposta, FILTER_VALIDATE_EMAIL) || strlen($eposta) > 160) {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçerli bir e-posta adresi girin.'], 400);
}
if (!preg_match('/^[a-z0-9_.]{3,40}$/', $kullaniciAdi)) {
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı adı 3–40 karakter olmalı; yalnızca küçük harf, rakam, nokta ve alt çizgi içerebilir.'], 400);
}
if (strlen($sifre) < 8 || !preg_match('/[A-Za-z]/', $sifre) || !preg_match('/[0-9]/', $sifre)) {
    ppJsonYanit(['ok' => false, 'hata' => 'Şifre en az 8 karakter olmalı ve harf ile rakam içermelidir.'], 400);
}

ppDenemeSiniri($pdo, 'kayit', 5, 60);

$var = $pdo->prepare('SELECT kullanici_adi FROM kullanici_hesap WHERE kullanici_adi = ? OR LOWER(eposta) = ? LIMIT 1');
$var->execute([$kullaniciAdi, $eposta]);
$mevcut = $var->fetch();

if ($mevcut) {
    ppJsonYanit(['ok' => false, 'hata' => $mevcut['kullanici_adi'] === $kullaniciAdi
        ? 'Bu kullanıcı adı alınmış; başka bir kullanıcı adı seçin.'
        : 'Bu e-posta adresiyle zaten bir hesap var. Giriş yapmayı deneyin.'], 409);
}

$simdi = gmdate('Y-m-d H:i:s');
$pdo->prepare('INSERT INTO kullanici_hesap (kullanici_adi, ad, eposta, sifre_hash, rol, kayit_tarihi, menu_izin) VALUES (?, ?, ?, ?, ?, ?, NULL)')
    ->execute([$kullaniciAdi, $ad, $eposta, password_hash($sifre, PASSWORD_DEFAULT), 'uye', $simdi]);

$hesap = $pdo->prepare('SELECT * FROM kullanici_hesap WHERE kullanici_adi = ?');
$hesap->execute([$kullaniciAdi]);

ppJsonYanit(['ok' => true, 'kullanici' => ppOturumAc($pdo, $hesap->fetch())]);
