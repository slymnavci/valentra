<?php
/** Bir kullanıcının tüm okuma ilerlemesini döner: { materyalId: { sayfaNo: tarih } }. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının ilerlemesi istenemez. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

$sorgu = $pdo->prepare('SELECT materyal_id, sayfa_no, tarih FROM kullanici_ilerleme WHERE kullanici_adi = ?');
$sorgu->execute([$kullaniciAdi]);

$sayfalar = [];
foreach ($sorgu->fetchAll() as $r) {
    if (!isset($sayfalar[$r['materyal_id']])) $sayfalar[$r['materyal_id']] = new stdClass();
    $sayfalar[$r['materyal_id']]->{$r['sayfa_no']} = $r['tarih'];
}

ppJsonYanit(['ok' => true, 'sayfalar' => $sayfalar ?: new stdClass()]);
