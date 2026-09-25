<?php
/**
 * CSV'den istemci tarafında ayrıştırılmış soruları toplu olarak bir sete
 * ekler. Beklenen gövde: { set_id, sorular: [{tip, soru, secenekler,
 * dogru_cevap, aciklama}, ...] } — tip "cok_secmeli" veya "bilgi_karti".
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

$setId = (int)($govde['set_id'] ?? 0);
$sorular = $govde['sorular'] ?? null;

if ($setId <= 0 || !is_array($sorular) || count($sorular) === 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'set_id ve en az bir soru zorunludur.'], 400);
}

$setKontrol = $pdo->prepare('SELECT id FROM soru_setleri WHERE id = ?');
$setKontrol->execute([$setId]);
if (!$setKontrol->fetch()) {
    ppJsonYanit(['ok' => false, 'hata' => 'Soru seti bulunamadı.'], 404);
}

$siraSorgu = $pdo->prepare('SELECT COALESCE(MAX(sira), -1) AS m FROM pratik_sorular WHERE set_id = ?');
$siraSorgu->execute([$setId]);
$sira = (int)$siraSorgu->fetch()['m'] + 1;

$ekle = $pdo->prepare(
    'INSERT INTO pratik_sorular (set_id, tip, soru, secenekler, dogru_cevap, aciklama, sira)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$eklenen = 0;
foreach ($sorular as $s) {
    $tip = (($s['tip'] ?? '') === 'bilgi_karti') ? 'bilgi_karti' : 'cok_secmeli';
    $soru = trim((string)($s['soru'] ?? ''));
    $dogruCevap = trim((string)($s['dogru_cevap'] ?? ''));
    if ($soru === '' || $dogruCevap === '') continue;

    $secenekler = null;
    if ($tip === 'cok_secmeli' && is_array($s['secenekler'] ?? null) && count($s['secenekler'])) {
        $secenekler = json_encode(array_values($s['secenekler']), JSON_UNESCAPED_UNICODE);
    }

    $aciklama = trim((string)($s['aciklama'] ?? ''));
    $ekle->execute([$setId, $tip, $soru, $secenekler, $dogruCevap, $aciklama !== '' ? $aciklama : null, $sira]);
    $sira++;
    $eklenen++;
}

ppJsonYanit(['ok' => true, 'eklenen' => $eklenen]);
