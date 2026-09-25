<?php
/**
 * Sağlık kontrolü — yetkilendirme gerektirmez. Yönetim panelindeki
 * "Bağlantıyı Test Et" düğmesi bunu çağırır: veritabanı bağlantısının
 * ve şema kurulumunun başarıyla çalıştığını doğrular.
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ppBaglan();
    echo json_encode(['ok' => true, 'mesaj' => 'Veritabanı bağlantısı ve tablo kurulumu başarılı.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'hata' => 'Veritabanına bağlanılamadı: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
