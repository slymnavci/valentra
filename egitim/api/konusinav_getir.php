<?php
/** Bir kullanıcının tüm konu sınavı geçmişini döner: { konuId: [ {tarih, dogru, toplam, puan, gecti}, ... ] }. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının konu sınavı geçmişi okunamaz. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

$sorgu = $pdo->prepare('SELECT konu_id, istemci_id, tarih, dogru, toplam, puan, gecti FROM kullanici_konu_sinav WHERE kullanici_adi = ? ORDER BY tarih ASC');
$sorgu->execute([$kullaniciAdi]);

$sinavlar = [];
foreach ($sorgu->fetchAll() as $r) {
    $sinavlar[$r['konu_id']][] = [
        'istemciId' => $r['istemci_id'],
        'tarih' => $r['tarih'],
        'dogru' => (int)$r['dogru'],
        'toplam' => (int)$r['toplam'],
        'puan' => (int)$r['puan'],
        'gecti' => (bool)$r['gecti']
    ];
}

ppJsonYanit(['ok' => true, 'sinavlar' => $sinavlar ?: new stdClass()]);
