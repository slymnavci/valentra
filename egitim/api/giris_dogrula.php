<?php
/**
 * Giriş doğrulama — KASITLI OLARAK herkese açıktır (ppYetkiKontrol
 * çağırmaz). Uygulama anahtarı yalnızca Yönetim panelinden bağlanan
 * yöneticide tanımlıdır; sıradan bir üyenin daha ilk girişte bu anahtarı
 * bilmesi beklenemez. Güvenlik burada uygulama anahtarından değil,
 * kullanıcı adı + bcrypt ile doğrulanan şifreden gelir.
 * Başarılı girişte ayrıca kullanıcıya özel bir oturum jetonu üretilir
 * (bkz. kullanici_oturum tablosu, yetki.php: ppOturumDogrula) — diğer
 * uç noktalar artık isteğin kullanici_adi alanına değil, bu jetona
 * bağlı kimliğe güvenir.
 */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();
$govde = ppGovdeOku();

$kullaniciAdi = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));
$sifre = (string)($govde['sifre'] ?? '');

if ($kullaniciAdi === '' || $sifre === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı adı ve şifre zorunludur.'], 400);
}

$sorgu = $pdo->prepare('SELECT * FROM kullanici_hesap WHERE kullanici_adi = ?');
$sorgu->execute([$kullaniciAdi]);
$hesap = $sorgu->fetch();

if (!$hesap || !password_verify($sifre, $hesap['sifre_hash'])) {
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı adı veya şifre hatalı.'], 401);
}

$simdi = gmdate('Y-m-d H:i:s');
$pdo->prepare('UPDATE kullanici_hesap SET son_giris = ? WHERE kullanici_adi = ?')->execute([$simdi, $kullaniciAdi]);

/* Başarılı giriş: kullanıcıya özel, tahmin edilemeyen bir oturum jetonu
   üret ve sakla (yalnızca hash'i) — sonraki her istek artık gövdedeki
   kullanici_adi'ne değil, bu jetona bağlı kimliğe göre yetkilendirilir
   (bkz. yetki.php: ppOturumDogrula). */
$pdo->prepare('DELETE FROM kullanici_oturum WHERE sona_erme < ?')->execute([$simdi]);
$token = bin2hex(random_bytes(32));
$sonaErme = gmdate('Y-m-d H:i:s', time() + 30 * 24 * 60 * 60);
$pdo->prepare('INSERT INTO kullanici_oturum (token_hash, kullanici_adi, olusturma_tarihi, sona_erme) VALUES (?, ?, ?, ?)')
    ->execute([hash('sha256', $token), $kullaniciAdi, $simdi, $sonaErme]);

$menuIzin = null;
if (($hesap['menu_izin'] ?? null) !== null && $hesap['menu_izin'] !== '') {
    $d = json_decode((string)$hesap['menu_izin'], true);
    $menuIzin = is_array($d) ? $d : null;
}

ppJsonYanit(['ok' => true, 'kullanici' => [
    'kullaniciAdi' => $hesap['kullanici_adi'],
    'ad' => $hesap['ad'],
    'eposta' => $hesap['eposta'],
    'rol' => $hesap['rol'],
    'kayitTarihi' => $hesap['kayit_tarihi'],
    'sonGiris' => $simdi,
    'token' => $token,
    'menuIzin' => $menuIzin,
]]);
