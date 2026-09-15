<?php
declare(strict_types=1);

/**
 * Yonetici oturum yonetimi.
 */

function oturum_baslat(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $guvenli = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $guvenli,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('valentra_admin');
    session_start();
}

/**
 * Kullanici adi + parola dogrulamasi. Basarili ise oturumu acar.
 */
function giris_yap(string $kullaniciAdi, string $parola): bool
{
    $ifade = db()->prepare('SELECT * FROM yoneticiler WHERE kullanici_adi = :kad LIMIT 1');
    $ifade->execute(['kad' => mb_strtolower(trim($kullaniciAdi), 'UTF-8')]);
    $yonetici = $ifade->fetch();

    // Kullanici bulunamasa bile dogrulama maliyetini odeyerek zamanlama
    // farkindan hesap varligi sizmasini engelliyoruz.
    $hash = $yonetici !== false
        ? $yonetici['parola_hash']
        : '$2y$12$usuqqcJtpFEZF1iZ5QC0h.mVgJqbYbpN6EOjGQdmp8eMIWhJ0nSIi';

    if (!password_verify($parola, $hash) || $yonetici === false) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['yonetici_id']  = (int) $yonetici['id'];
    $_SESSION['yonetici_ad']  = $yonetici['ad'];
    $_SESSION['giris_zamani'] = time();

    db()->prepare('UPDATE yoneticiler SET son_giris = NOW() WHERE id = :id')
        ->execute(['id' => $yonetici['id']]);

    return true;
}

function cikis_yap(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
}

function oturum_acik(): bool
{
    return !empty($_SESSION['yonetici_id']);
}

function aktif_yonetici_id(): int
{
    return (int) ($_SESSION['yonetici_id'] ?? 0);
}

function aktif_yonetici_ad(): string
{
    return (string) ($_SESSION['yonetici_ad'] ?? '');
}

/**
 * Korumali admin sayfalarinin basinda cagrilir.
 */
function giris_zorunlu(): void
{
    oturum_baslat();

    if (!oturum_acik()) {
        yonlendir('giris.php');
    }
}

/**
 * En az bir yonetici var mi? (kurulum kontrolu)
 */
function yonetici_var_mi(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM yoneticiler')->fetchColumn() > 0;
}
