<?php
/**
 * Yönetici tarafından mevcut bir üyenin menü erişim listesini günceller
 * (bkz. yetki.php: ppMenuDogrula). menuIzin gönderilmezse ya da dizi
 * değilse istek reddedilir — kısıtlamayı tamamen kaldırmak isteyen
 * yönetici boş bir dizi değil, açıkça `sinirsiz: true` göndermelidir.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
ppYoneticiDogrula($pdo);
$govde = ppGovdeOku();
$kullaniciAdi = strtolower(trim((string)($govde['kullanici_adi'] ?? '')));

if ($kullaniciAdi === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı adı zorunludur.'], 400);
}
if ($kullaniciAdi === 'savci') {
    ppJsonYanit(['ok' => false, 'hata' => 'Bu hesabın erişimi kısıtlanamaz.'], 400);
}

$var = $pdo->prepare('SELECT 1 FROM kullanici_hesap WHERE kullanici_adi = ?');
$var->execute([$kullaniciAdi]);
if (!$var->fetch()) {
    ppJsonYanit(['ok' => false, 'hata' => 'Kullanıcı bulunamadı.'], 404);
}

if (!empty($govde['sinirsiz'])) {
    $menuIzin = null; // kısıtlama tamamen kaldırılıyor
} elseif (is_array($govde['menuIzin'] ?? null)) {
    $menuIzin = json_encode(array_values(array_unique(array_map('strval', $govde['menuIzin']))), JSON_UNESCAPED_UNICODE);
} else {
    ppJsonYanit(['ok' => false, 'hata' => 'menuIzin bir dizi olmalıdır (kısıtlamayı kaldırmak için sinirsiz:true gönderin).'], 400);
}

$pdo->prepare('UPDATE kullanici_hesap SET menu_izin = ? WHERE kullanici_adi = ?')->execute([$menuIzin, $kullaniciAdi]);

ppJsonYanit(['ok' => true]);
