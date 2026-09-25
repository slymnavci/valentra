<?php
/**
 * Kullanıcı ayarlarını (tema, seçilen dersler, sınav tarihi vb.) kaydeder.
 * GÜVENLİK: AI API anahtarları buraya YAZILMAZ — güvenlik gereği, gelen
 * veride "Anahtar" ile biten alanlar sunucu tarafında ayıklanır. Anahtarlar
 * ayrı bir uç noktada (kullanici_sir), sunucudan asla geri okunmayacak
 * şekilde saklanacaktır.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının ayarları değiştirilemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$veri = $govde['veri'] ?? null;

if (!is_array($veri)) {
    ppJsonYanit(['ok' => false, 'hata' => 'veri (nesne) zorunludur.'], 400);
}

foreach (array_keys($veri) as $anahtar) {
    if (str_ends_with($anahtar, 'Anahtar')) unset($veri[$anahtar]);
}

$mevcut = $pdo->prepare('SELECT 1 FROM kullanici_ayar WHERE kullanici_adi = ?');
$mevcut->execute([$kullaniciAdi]);

if ($mevcut->fetch()) {
    $guncelle = $pdo->prepare('UPDATE kullanici_ayar SET veri = ?, guncelleme_tarihi = ? WHERE kullanici_adi = ?');
    $guncelle->execute([json_encode($veri, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s'), $kullaniciAdi]);
} else {
    $ekle = $pdo->prepare('INSERT INTO kullanici_ayar (kullanici_adi, veri, guncelleme_tarihi) VALUES (?, ?, ?)');
    $ekle->execute([$kullaniciAdi, json_encode($veri, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
}

ppJsonYanit(['ok' => true]);
