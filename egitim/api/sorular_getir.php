<?php
/** Bir setin sorularını sıralarına göre döner. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$setId = (int)($_GET['set_id'] ?? 0);

if ($setId <= 0) {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçersiz set id.'], 400);
}

$sorgu = $pdo->prepare('SELECT * FROM pratik_sorular WHERE set_id = ? ORDER BY sira');
$sorgu->execute([$setId]);

$sorular = array_map(function ($r) {
    $r['secenekler'] = $r['secenekler'] ? json_decode($r['secenekler'], true) : [];
    return $r;
}, $sorgu->fetchAll());

ppJsonYanit(['ok' => true, 'sorular' => $sorular]);
