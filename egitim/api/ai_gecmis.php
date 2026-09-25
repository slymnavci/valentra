<?php
/**
 * Ders Asistanı soru/cevap geçmişi.
 * Kullanıcı kimliği yalnızca X-Session-Token üzerinden doğrulanır;
 * ayrı uygulama anahtarı gerekmez. Böylece geçmiş cihazlar arasında ortak olur.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';

$pdo = ppBaglan();
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$idSutunu = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
$pdo->exec("CREATE TABLE IF NOT EXISTS ai_soru_gecmis (
    id $idSutunu,
    kullanici_adi VARCHAR(120) NOT NULL,
    ders_id VARCHAR(120) NULL,
    konu_id VARCHAR(120) NULL,
    ders_ad VARCHAR(200) NULL,
    konu_ad VARCHAR(200) NULL,
    soru TEXT NOT NULL,
    cevap TEXT NOT NULL,
    tarih DATETIME NOT NULL
)$motor");

$yontem = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($yontem === 'GET') {
    $dersId = trim((string)($_GET['ders_id'] ?? ''));
    $konuId = trim((string)($_GET['konu_id'] ?? ''));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));

    $sql = 'SELECT id, ders_id, konu_id, ders_ad, konu_ad, soru, cevap, tarih FROM ai_soru_gecmis WHERE kullanici_adi = ?';
    $param = [$kullaniciAdi];
    if ($dersId !== '') { $sql .= ' AND ders_id = ?'; $param[] = $dersId; }
    if ($konuId !== '') { $sql .= ' AND konu_id = ?'; $param[] = $konuId; }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $s = $pdo->prepare($sql);
    $s->execute($param);
    ppJsonYanit(['ok' => true, 'kayitlar' => $s->fetchAll()]);
}

if ($yontem === 'POST') {
    $g = ppGovdeOku();
    $soru = trim((string)($g['soru'] ?? ''));
    $cevap = trim((string)($g['cevap'] ?? ''));
    if ($soru === '' || $cevap === '') {
        ppJsonYanit(['ok' => false, 'hata' => 'soru ve cevap zorunludur.'], 400);
    }
    /* Aşırı büyük kayıtların yanlışlıkla veritabanını şişirmesini engelle. */
    $soru = mb_substr($soru, 0, 12000);
    $cevap = mb_substr($cevap, 0, 50000);
    $ekle = $pdo->prepare('INSERT INTO ai_soru_gecmis (kullanici_adi, ders_id, konu_id, ders_ad, konu_ad, soru, cevap, tarih) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $ekle->execute([
        $kullaniciAdi,
        trim((string)($g['ders_id'] ?? '')) ?: null,
        trim((string)($g['konu_id'] ?? '')) ?: null,
        trim((string)($g['ders_ad'] ?? '')) ?: null,
        trim((string)($g['konu_ad'] ?? '')) ?: null,
        $soru,
        $cevap,
        gmdate('Y-m-d H:i:s')
    ]);
    ppJsonYanit(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($yontem === 'DELETE') {
    $g = ppGovdeOku();
    $id = (int)($g['id'] ?? 0);
    if ($id > 0) {
        $sil = $pdo->prepare('DELETE FROM ai_soru_gecmis WHERE id = ? AND kullanici_adi = ?');
        $sil->execute([$id, $kullaniciAdi]);
        ppJsonYanit(['ok' => true]);
    }

    $dersId = trim((string)($g['ders_id'] ?? ''));
    $konuId = trim((string)($g['konu_id'] ?? ''));
    $sql = 'DELETE FROM ai_soru_gecmis WHERE kullanici_adi = ?';
    $param = [$kullaniciAdi];
    if ($dersId !== '') { $sql .= ' AND ders_id = ?'; $param[] = $dersId; }
    if ($konuId !== '') { $sql .= ' AND konu_id = ?'; $param[] = $konuId; }
    $sil = $pdo->prepare($sql);
    $sil->execute($param);
    ppJsonYanit(['ok' => true]);
}

ppJsonYanit(['ok' => false, 'hata' => 'Desteklenmeyen yöntem.'], 405);
