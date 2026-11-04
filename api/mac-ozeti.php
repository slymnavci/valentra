<?php
declare(strict_types=1);

/**
 * Mac ozetleri (ajan/mac_ozetleri.php; bkz. includes/mac_ozeti.php).
 *
 *   GET  -> {"kanallar": ["https://www.youtube.com/@..."], "bilinen": ["videoId", ...]}
 *   POST {"ozetler": [{"video_id", "baslik", "aciklama", "kanal", "yayin"}]} -> {"eklenen": n}
 *
 * Basliginda "ozet" gecmeyen video sitede de eleniyor (mac_ozeti_mi).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/mac_ozeti.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $bilinen = db()->query('SELECT video_id FROM mac_ozetleri WHERE olusturuldu > NOW() - INTERVAL 10 DAY')->fetchAll(PDO::FETCH_COLUMN);
    ajan_json(200, ['kanallar' => mac_ozeti_kanallari(), 'bilinen' => array_values(array_map('strval', $bilinen))]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri = json_decode((string) file_get_contents('php://input'), true);
$eklenen = mac_ozeti_ekle(array_values(array_filter((array) ($veri['ozetler'] ?? []), 'is_array')));

if ($eklenen > 0) {
    // Arama motorlarina yeni sayfalar (IndexNow).
    require_once __DIR__ . '/../includes/arama_bildirim.php';
    arama_bildir_kuyruk(mac_ozetleri_yolu());
}

ajan_json(200, ['eklenen' => $eklenen]);
