<?php
/** Bir materyal sayfasını okundu/okunmadı işaretler (state.sayfalar'ın backend karşılığı). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının ilerlemesi değiştirilemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$materyalId = trim((string)($govde['materyal_id'] ?? ''));
$sayfaNo = (int)($govde['sayfa_no'] ?? 0);
$deger = !empty($govde['deger']);

if ($materyalId === '' || $sayfaNo <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'materyal_id ve sayfa_no zorunludur.'], 400);
}

$sil = $pdo->prepare('DELETE FROM kullanici_ilerleme WHERE kullanici_adi = ? AND materyal_id = ? AND sayfa_no = ?');
$sil->execute([$kullaniciAdi, $materyalId, $sayfaNo]);

if ($deger) {
    $ekle = $pdo->prepare('INSERT INTO kullanici_ilerleme (kullanici_adi, materyal_id, sayfa_no, tarih) VALUES (?, ?, ?, ?)');
    $ekle->execute([$kullaniciAdi, $materyalId, $sayfaNo, gmdate('Y-m-d H:i:s')]);
}

ppJsonYanit(['ok' => true]);
