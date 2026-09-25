<?php
/**
 * Hangi sağlayıcılar için anahtar tanımlı olduğunu döner.
 * GÜVENLİK: Anahtarın kendisi ASLA döndürülmez — yalnızca tanımlı/model.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının AI anahtar durumu okunamaz. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

$durum = [
    'openai' => ['tanimli' => false, 'model' => null],
    'anthropic' => ['tanimli' => false, 'model' => null],
    'gemini' => ['tanimli' => false, 'model' => null],
];

$sorgu = $pdo->prepare('SELECT saglayici, model FROM kullanici_sir WHERE kullanici_adi = ?');
$sorgu->execute([$kullaniciAdi]);
foreach ($sorgu->fetchAll() as $r) {
    if (isset($durum[$r['saglayici']])) $durum[$r['saglayici']] = ['tanimli' => true, 'model' => $r['model']];
}

ppJsonYanit(['ok' => true, 'durum' => $durum]);
