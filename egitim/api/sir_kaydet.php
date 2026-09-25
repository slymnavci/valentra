<?php
/**
 * Bir AI sağlayıcısı için API anahtarını kaydeder/günceller.
 * GÜVENLİK: Bu uç nokta yalnızca YAZAR — hiçbir uç nokta anahtarı
 * istemciye geri döndürmez (bkz. sir_durum.php). Tüm AI istekleri
 * ai_sohbet.php üzerinden, anahtar sunucudan hiç çıkmadan yapılır.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkası adına API anahtarı kaydedilemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$saglayici = trim((string)($govde['saglayici'] ?? ''));
$anahtar = trim((string)($govde['anahtar'] ?? ''));
$model = trim((string)($govde['model'] ?? ''));

if (!in_array($saglayici, ['openai', 'anthropic', 'gemini'], true) || $anahtar === '') {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçerli bir saglayici (openai/anthropic/gemini) ve anahtar zorunludur.'], 400);
}

$sil = $pdo->prepare('DELETE FROM kullanici_sir WHERE kullanici_adi = ? AND saglayici = ?');
$sil->execute([$kullaniciAdi, $saglayici]);

$ekle = $pdo->prepare('INSERT INTO kullanici_sir (kullanici_adi, saglayici, anahtar, model, guncelleme_tarihi) VALUES (?, ?, ?, ?, ?)');
$ekle->execute([$kullaniciAdi, $saglayici, $anahtar, $model !== '' ? $model : null, gmdate('Y-m-d H:i:s')]);

ppJsonYanit(['ok' => true]);
