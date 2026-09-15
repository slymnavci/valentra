<?php
declare(strict_types=1);

/**
 * Ajan çalışmasının durumunu JSON olarak verir.
 *
 * Ajan sayfası bunu yokluyor: çalışma bitince kullanıcıya haber verilir,
 * yoksa "birkaç dakika sonra sayfayı yenileyin" deyip bırakıyorduk ve
 * kullanıcı bittiğini ancak elle yenileyerek öğreniyordu.
 *
 * Yalnızca okur; hiçbir şey tetiklemez ya da yazmaz.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ayarlar.php';
require_once __DIR__ . '/../includes/ajan_tetikle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

oturum_baslat();

// Oturumsuz istek giris sayfasina yonlenmemeli; yoklama JSON bekliyor.
if (!oturum_acik()) {
    http_response_code(401);
    echo json_encode(['hata' => 'oturum yok'], JSON_UNESCAPED_UNICODE);
    exit;
}

$son      = ajan_son_calisma();
$bekleyen = haber_durum_sayilari()[HABER_TASLAK] ?? 0;

$sonGonderim = db()->query(
    'SELECT bitis, durum, eklenen, yinelenen
       FROM ajan_kayitlari
      WHERE bitis IS NOT NULL
      ORDER BY bitis DESC
      LIMIT 1'
)->fetch();

echo json_encode([
    'calisiyor'    => $son['var'] && $son['durum'] !== 'completed',
    'durum'        => $son['durum'],
    'sonuc'        => $son['sonuc'],
    'baslangic'    => $son['baslangic'],
    'bekleyen'     => $bekleyen,
    'son_gonderim' => $sonGonderim !== false ? [
        'bitis'     => (string) $sonGonderim['bitis'],
        'durum'     => (string) $sonGonderim['durum'],
        'eklenen'   => (int) $sonGonderim['eklenen'],
        'yinelenen' => (int) $sonGonderim['yinelenen'],
    ] : null,
], JSON_UNESCAPED_UNICODE);
