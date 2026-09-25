<?php
/**
 * Kullanıcıya ait ayar/çalışma planı, okuma ilerlemesi ve konu sınavlarını
 * yalnızca X-Session-Token ile senkronlar. Uygulama anahtarı gerekmez.
 * Kimlik her zaman doğrulanmış oturum jetonundan alınır.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';

$pdo = ppBaglan();
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ayarS = $pdo->prepare('SELECT veri, guncelleme_tarihi FROM kullanici_ayar WHERE kullanici_adi = ?');
    $ayarS->execute([$kullaniciAdi]);
    $ayarR = $ayarS->fetch();
    $ayar = $ayarR ? json_decode($ayarR['veri'], true) : null;

    $ilerS = $pdo->prepare('SELECT materyal_id, sayfa_no, tarih FROM kullanici_ilerleme WHERE kullanici_adi = ?');
    $ilerS->execute([$kullaniciAdi]);
    $sayfalar = [];
    foreach ($ilerS->fetchAll() as $r) {
        if (!isset($sayfalar[$r['materyal_id']])) $sayfalar[$r['materyal_id']] = [];
        $sayfalar[$r['materyal_id']][(string)$r['sayfa_no']] = $r['tarih'];
    }

    $sinS = $pdo->prepare('SELECT konu_id, istemci_id, tarih, dogru, toplam, puan, gecti FROM kullanici_konu_sinav WHERE kullanici_adi = ? ORDER BY tarih ASC');
    $sinS->execute([$kullaniciAdi]);
    $sinavlar = [];
    foreach ($sinS->fetchAll() as $r) {
        if (!isset($sinavlar[$r['konu_id']])) $sinavlar[$r['konu_id']] = [];
        $sinavlar[$r['konu_id']][] = [
            'istemciId' => $r['istemci_id'],
            'tarih' => $r['tarih'],
            'dogru' => (int)$r['dogru'],
            'toplam' => (int)$r['toplam'],
            'puan' => (int)$r['puan'],
            'gecti' => (bool)$r['gecti']
        ];
    }

    ppJsonYanit([
        'ok' => true,
        'ayar' => $ayar,
        'ayar_guncelleme_tarihi' => $ayarR['guncelleme_tarihi'] ?? null,
        'sayfalar' => $sayfalar ?: new stdClass(),
        'sinavlar' => $sinavlar ?: new stdClass()
    ]);
}

$govde = ppGovdeOku();
$islem = trim((string)($govde['islem'] ?? ''));

if ($islem === 'ayar') {
    $veri = $govde['veri'] ?? null;
    if (!is_array($veri)) ppJsonYanit(['ok' => false, 'hata' => 'veri zorunludur.'], 400);
    foreach (array_keys($veri) as $anahtar) {
        if (str_ends_with($anahtar, 'Anahtar')) unset($veri[$anahtar]);
    }
    $json = json_encode($veri, JSON_UNESCAPED_UNICODE);
    $simdi = gmdate('Y-m-d H:i:s');
    $mevcut = $pdo->prepare('SELECT 1 FROM kullanici_ayar WHERE kullanici_adi = ?');
    $mevcut->execute([$kullaniciAdi]);
    if ($mevcut->fetch()) {
        $s = $pdo->prepare('UPDATE kullanici_ayar SET veri = ?, guncelleme_tarihi = ? WHERE kullanici_adi = ?');
        $s->execute([$json, $simdi, $kullaniciAdi]);
    } else {
        $s = $pdo->prepare('INSERT INTO kullanici_ayar (kullanici_adi, veri, guncelleme_tarihi) VALUES (?, ?, ?)');
        $s->execute([$kullaniciAdi, $json, $simdi]);
    }
    ppJsonYanit(['ok' => true]);
}

if ($islem === 'ilerleme') {
    $materyalId = trim((string)($govde['materyal_id'] ?? ''));
    $sayfaNo = (int)($govde['sayfa_no'] ?? 0);
    $deger = !empty($govde['deger']);
    if ($materyalId === '' || $sayfaNo <= 0) ppJsonYanit(['ok' => false, 'hata' => 'materyal_id ve sayfa_no zorunludur.'], 400);
    $sil = $pdo->prepare('DELETE FROM kullanici_ilerleme WHERE kullanici_adi = ? AND materyal_id = ? AND sayfa_no = ?');
    $sil->execute([$kullaniciAdi, $materyalId, $sayfaNo]);
    if ($deger) {
        $ekle = $pdo->prepare('INSERT INTO kullanici_ilerleme (kullanici_adi, materyal_id, sayfa_no, tarih) VALUES (?, ?, ?, ?)');
        $ekle->execute([$kullaniciAdi, $materyalId, $sayfaNo, gmdate('Y-m-d H:i:s')]);
    }
    ppJsonYanit(['ok' => true]);
}

if ($islem === 'sinav') {
    $konuId = trim((string)($govde['konu_id'] ?? ''));
    $istemciId = trim((string)($govde['istemci_id'] ?? ''));
    $dogru = (int)($govde['dogru'] ?? -1);
    $toplam = (int)($govde['toplam'] ?? -1);
    $puan = (int)($govde['puan'] ?? -1);
    $gecti = !empty($govde['gecti']);
    if ($konuId === '' || $dogru < 0 || $toplam <= 0 || $puan < 0) ppJsonYanit(['ok' => false, 'hata' => 'konu_id, dogru, toplam ve puan zorunludur.'], 400);
    if ($istemciId !== '') {
        $m = $pdo->prepare('SELECT id FROM kullanici_konu_sinav WHERE kullanici_adi = ? AND konu_id = ? AND istemci_id = ?');
        $m->execute([$kullaniciAdi, $konuId, $istemciId]);
        $r = $m->fetch();
        if ($r) ppJsonYanit(['ok' => true, 'id' => (int)$r['id']]);
    }
    $e = $pdo->prepare('INSERT INTO kullanici_konu_sinav (kullanici_adi, konu_id, istemci_id, tarih, dogru, toplam, puan, gecti) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $e->execute([$kullaniciAdi, $konuId, $istemciId !== '' ? $istemciId : null, gmdate('Y-m-d H:i:s'), $dogru, $toplam, $puan, $gecti ? 1 : 0]);
    ppJsonYanit(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

ppJsonYanit(['ok' => false, 'hata' => 'Geçersiz işlem.'], 400);
