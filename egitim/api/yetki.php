<?php
/**
 * Pratik sistemi API uç noktaları için paylaşılan anahtar kontrolü ve
 * oturum jetonu doğrulaması.
 * Her yazma/okuma uç noktası (ping.php hariç) bu dosyayı include edip
 * ppYetkiKontrol() çağırır. Anahtar uyuşmazsa 401 JSON döner ve süreci
 * sonlandırır. Kullanıcıya özel verilere erişen uç noktalar ayrıca
 * ppOturumDogrula()/ppYoneticiDogrula() ile isteği yapanın gerçek
 * kimliğini oturum jetonundan doğrular (bkz. giris_dogrula.php).
 */

require_once __DIR__ . '/config.php';

function ppYetkiKontrol(): void {
    $gelen = $_SERVER['HTTP_X_APP_KEY'] ?? '';
    if (!defined('APP_ANAHTARI') || APP_ANAHTARI === '' || $gelen === '' || !hash_equals(APP_ANAHTARI, $gelen)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Yetkisiz erişim: X-App-Key başlığı eksik veya hatalı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Oturum jetonunu (X-Session-Token) doğrular ve isteği yapan GERÇEK
 * kullanıcının kimliğini döner. Kullanıcı adı artık istek gövdesi/
 * sorgu dizesindeki kullanici_adi alanından DEĞİL, yalnızca burada
 * doğrulanan jetondan belirlenmelidir — bkz. giris_dogrula.php (jeton
 * üretimi) ve kullanici_oturum tablosu (db.php).
 *
 * Geçerli bir jeton yoksa/süresi dolmuşsa 401 JSON döner ve süreci
 * sonlandırır. Aksi hâlde ['kullaniciAdi' => ..., 'rol' => ...] döner
 * ve oturumu bir sonraki 30 gün için uzatır (kayan oturum süresi).
 */
function ppOturumDogrula(PDO $pdo): array {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($token === '') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Oturum bulunamadı: lütfen tekrar giriş yapın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tokenHash = hash('sha256', $token);
    $simdi = gmdate('Y-m-d H:i:s');
    $sorgu = $pdo->prepare(
        'SELECT o.kullanici_adi, h.rol FROM kullanici_oturum o
         JOIN kullanici_hesap h ON h.kullanici_adi = o.kullanici_adi
         WHERE o.token_hash = ? AND o.sona_erme > ?'
    );
    $sorgu->execute([$tokenHash, $simdi]);
    $satir = $sorgu->fetch();

    if (!$satir) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Oturum geçersiz veya süresi dolmuş: lütfen tekrar giriş yapın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $yeniSonaErme = gmdate('Y-m-d H:i:s', time() + 30 * 24 * 60 * 60);
    $pdo->prepare('UPDATE kullanici_oturum SET sona_erme = ? WHERE token_hash = ?')->execute([$yeniSonaErme, $tokenHash]);

    return ['kullaniciAdi' => $satir['kullanici_adi'], 'rol' => $satir['rol']];
}

/** ppOturumDogrula gibidir, ayrıca rolün "yonetici" olmasını zorunlu kılar. */
function ppYoneticiDogrula(PDO $pdo): array {
    $oturum = ppOturumDogrula($pdo);
    if ($oturum['rol'] !== 'yonetici') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu işlem için yönetici yetkisi gereklidir.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $oturum;
}

/**
 * ppOturumDogrula gibidir, ayrıca kullanıcının belirtilen menü kimliğine
 * (ör. 'finansallar') AÇIKÇA izinli olmasını zorunlu kılar. Yönetici rolü
 * bu kontrolü her zaman atlar. Bu fonksiyon, önceden ppYoneticiDogrula ile
 * (yani yalnızca yönetici) korunan hassas uç noktalarda kullanılır — bu
 * yüzden sıradan bir üye için varsayılan REDDİR: kullanici_hesap.menu_izin
 * NULL/boşsa (menü izin sistemiyle hiç eşleştirilmemiş üye) bu uç noktaya
 * erişemez; yalnızca yönetici tarafından menu_izin dizisine bu id açıkça
 * eklenmiş üyeler geçer. (Not: sidebar'da menü görünürlüğü için kullanılan
 * app.js:menuCiz farklı, daha esnek bir varsayılana sahiptir — kısıtlanmamış
 * bir üye halihazırda herkese açık, hassas olmayan bölümleri görmeye devam
 * eder; bu fonksiyon yalnızca gerçekten korunan veri uç noktaları içindir.)
 */
function ppMenuDogrula(PDO $pdo, string $menuId): array {
    $oturum = ppOturumDogrula($pdo);
    if ($oturum['rol'] === 'yonetici') return $oturum;
    $q = $pdo->prepare('SELECT menu_izin FROM kullanici_hesap WHERE kullanici_adi = ?');
    $q->execute([$oturum['kullaniciAdi']]);
    $ham = $q->fetch()['menu_izin'] ?? null;
    $izinler = ($ham !== null && $ham !== '') ? json_decode((string)$ham, true) : [];
    if (!is_array($izinler) || !in_array($menuId, $izinler, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu bölüme erişim izniniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $oturum;
}
