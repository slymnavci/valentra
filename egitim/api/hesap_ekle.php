<?php
/**
 * Yönetici tarafından yeni bir üye hesabı ekler. Yalnızca yönetici bu
 * uç noktaya erişebilir (uygulama anahtarı ile — bkz. yetki.php).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
ppYoneticiDogrula($pdo);
$govde = ppGovdeOku();
$kullaniciAdi = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));
$ad = trim((string)($govde['ad'] ?? ''));
$eposta = trim((string)($govde['eposta'] ?? ''));
$sifre = (string)($govde['sifre'] ?? '');
$rol = ($govde['rol'] ?? 'uye') === 'yonetici' ? 'yonetici' : 'uye';
/* menuIzin gönderilmediyse (eski istemci ya da bilinçli tercih) NULL
   saklanır = kısıtlama yok. Gönderilmişse (dizi, boş olsa da) üye o
   dizideki menülerle sınırlanır — bkz. yetki.php: ppMenuDogrula. */
$menuIzin = null;
if (array_key_exists('menuIzin', $govde) && is_array($govde['menuIzin'])) {
    $menuIzin = json_encode(array_values(array_unique(array_map('strval', $govde['menuIzin']))), JSON_UNESCAPED_UNICODE);
}

if (strlen($kullaniciAdi) < 3 || !preg_match('/^[a-z0-9_.]+$/', $kullaniciAdi)) {
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı adı en az 3 karakter olmalı ve yalnızca harf, rakam, nokta, alt çizgi içerebilir.'], 400);
}
if ($ad === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Ad soyad zorunludur.'], 400);
}
if (strlen($sifre) < 6) {
    ppJsonYanit(['ok' => false, 'hata' => 'Şifre en az 6 karakter olmalıdır.'], 400);
}

$var = $pdo->prepare('SELECT 1 FROM kullanici_hesap WHERE kullanici_adi = ?');
$var->execute([$kullaniciAdi]);
if ($var->fetch()) {
    ppJsonYanit(['ok' => false, 'hata' => 'Bu kullanıcı adı zaten kayıtlı.'], 400);
}

$ekle = $pdo->prepare('INSERT INTO kullanici_hesap (kullanici_adi, ad, eposta, sifre_hash, rol, kayit_tarihi, menu_izin) VALUES (?, ?, ?, ?, ?, ?, ?)');
$ekle->execute([$kullaniciAdi, $ad, $eposta !== '' ? $eposta : null, password_hash($sifre, PASSWORD_DEFAULT), $rol, gmdate('Y-m-d H:i:s'), $menuIzin]);

ppJsonYanit(['ok' => true]);
