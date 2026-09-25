<?php
/**
 * Eğitim platformu — Valentra içinde çalışan yapılandırma.
 *
 * Platform suleymanavci.com.tr'den Eylul 2026'da tasindi. Oradaki
 * config.php (veritabani bilgisi + uygulama anahtari elle yuklenen bir
 * dosya) yerine burada:
 *   - Veritabani VALENTRA'NIN veritabani: includes/database.php (deploy
 *     sirasinda GitHub Secrets'tan uretilir) $pdo'yu tanimliyor; db.php
 *     ppBaglan() o baglantiyi kullaniyor. Tablo adlari Valentra'nin
 *     tablolariyla cakismiyor (kullanici_*, forum_*, soru_setleri ...).
 *   - Uygulama anahtari (APP_ANAHTARI) Valentra ayarlarinda
 *     ('egitim_app_anahtari'); Valentra panelindeki Egitim sayfasindan
 *     aktarim sirasinda eski siteninkiyle ayni olarak yaziliyor.
 *
 * Bu dosyada gizli bilgi YOK; depoda duruyor.
 */

$valentraAyar = dirname(__DIR__, 2) . '/includes/database.php';

if (!is_file($valentraAyar)) {
    $valentraAyar = dirname(__DIR__, 2) . '/includes/database.local.php';
}

require_once $valentraAyar;

// Valentra panelinden bir fonksiyon icinde yuklendiginde (bkz.
// includes/egitim_aktarim.php) baglanti yerel kapsamda degil, globalde.
if (!isset($pdo) && isset($GLOBALS['pdo'])) {
    $pdo = $GLOBALS['pdo'];
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'hata' => 'Veritabanı bağlantısı kurulamadı.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$GLOBALS['egitim_pdo'] = $pdo;

if (!defined('APP_ANAHTARI')) {
    try {
        $anahtarSorgu = $pdo->prepare("SELECT deger FROM ayarlar WHERE anahtar = 'egitim_app_anahtari' LIMIT 1");
        $anahtarSorgu->execute();
        $anahtarDeger = $anahtarSorgu->fetchColumn();
    } catch (PDOException $e) {
        $anahtarDeger = false;
    }

    // Bos anahtar: ppYetkiKontrol() her istegi reddeder (aktarim yapilana
    // ya da panelden anahtar belirlenene kadar).
    define('APP_ANAHTARI', is_string($anahtarDeger) ? $anahtarDeger : '');
}

// Sunucuda duzenlenen klasorler deploy'da ustune yazilmiyor (bkz.
// .github/workflows/deploy.yml); ilk kurulumda yoklarsa olustur.
foreach (['content', 'materyaller'] as $egitimKlasor) {
    $egitimYol = dirname(__DIR__) . '/' . $egitimKlasor;

    if (!is_dir($egitimYol)) {
        @mkdir($egitimYol, 0755, true);
    }
}
