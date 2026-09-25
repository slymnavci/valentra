<?php
/**
 * Bir AI sağlayıcısı için yalnızca MODEL alanını günceller — anahtarı
 * değiştirmez, gerektirmez de (sir_kaydet.php'nin aksine). Yönetim
 * panelinde "anahtarı olduğu gibi bırak, modeli değiştir" akışı için:
 * anahtarı yeniden yazdırmadan modeli güncel tutmak amacıyla kullanılır.
 * Sağlayıcı için daha önce bir anahtar kaydedilmemişse hata döner —
 * güncellenecek bir satır yoktur.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının modeli güncellenemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$saglayici = trim((string)($govde['saglayici'] ?? ''));
$model = trim((string)($govde['model'] ?? ''));

if (!in_array($saglayici, ['openai', 'anthropic', 'gemini'], true) || $model === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçerli bir saglayici (openai/anthropic/gemini) ve model zorunludur.'], 400);
}

$guncelle = $pdo->prepare('UPDATE kullanici_sir SET model = ?, guncelleme_tarihi = ? WHERE kullanici_adi = ? AND saglayici = ?');
$guncelle->execute([$model, gmdate('Y-m-d H:i:s'), $kullaniciAdi, $saglayici]);

if ($guncelle->rowCount() === 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'Bu sağlayıcı için önce bir API anahtarı kaydetmelisiniz.'], 404);
}

ppJsonYanit(['ok' => true]);
