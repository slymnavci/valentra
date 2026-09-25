<?php
/**
 * Tüm forum kategorilerini (başlıkları) döner — herkese açıktır
 * (content/dersler.json gibi; hassas veri içermez).
 */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();

$satirlar = $pdo->query(
    "SELECT k.id, k.ad, k.aciklama, k.sira,
            (SELECT COUNT(*) FROM forum_konu WHERE kategori_id = k.id) AS konu_sayisi,
            (SELECT MAX(son_aktivite) FROM forum_konu WHERE kategori_id = k.id) AS son_aktivite
     FROM forum_kategori k
     ORDER BY k.sira, k.id"
)->fetchAll();

$kategoriler = array_map(fn($r) => [
    'id' => (int)$r['id'],
    'ad' => $r['ad'],
    'aciklama' => $r['aciklama'],
    'konuSayisi' => (int)$r['konu_sayisi'],
    'sonAktivite' => $r['son_aktivite'],
], $satirlar);

ppJsonYanit(['ok' => true, 'kategoriler' => $kategoriler]);
