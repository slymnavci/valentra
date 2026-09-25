<?php
/**
 * Çıkış — sunucudaki oturum jetonunu geçersiz kılar. KASITLI OLARAK
 * X-App-Key gerektirmez (giriş/şifre değiştirme gibi): güvenlik, yalnızca
 * isteği yapanın kendi X-Session-Token'ının silinmesinden gelir, başka
 * hiçbir kullanıcının oturumuna dokunulmaz. Jeton eksik/geçersizse de
 * sorunsuz "ok" döner — istemci taraf zaten localStorage'ı temizleyecektir
 * (bkz. auth.js: Auth.cikis).
 */
require_once __DIR__ . '/db.php';

$pdo = ppBaglan();
$token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';

if ($token !== '') {
    $pdo->prepare('DELETE FROM kullanici_oturum WHERE token_hash = ?')->execute([hash('sha256', $token)]);
}

ppJsonYanit(['ok' => true]);
