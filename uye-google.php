<?php
declare(strict_types=1);

/**
 * Google ile giriş / üyelik (13.11.2026; bkz. includes/uye_google.php).
 *
 *   /uye-google.php?donus=/network      Google'ın izin sayfasına gönderir
 *   /uye-google.php?code=...&state=...  Google'dan dönüş: oturum açar
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/uye_google.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/** Hata: giris sayfasina mesajla don. */
$geri = static function (string $mesaj, string $donus = ''): never {
    $adres = uye_yolu('giris', $donus);
    header('Location: ' . $adres . (str_contains($adres, '?') ? '&' : '?') . 'google=' . rawurlencode($mesaj), true, 302);
    exit;
};

if (!google_giris_acik()) {
    $geri('Google ile giriş şu an kullanılamıyor.');
}

// Google'dan donus.
if (isset($_GET['code']) || isset($_GET['error']) || isset($_GET['state'])) {
    $donus = google_giris_durum_dogrula((string) ($_GET['state'] ?? ''));

    if ($donus === null) {
        $geri('Giriş isteği doğrulanamadı; lütfen yeniden deneyin.');
    }

    if (isset($_GET['error']) || (string) ($_GET['code'] ?? '') === '') {
        $geri('Google ile giriş iptal edildi.', $donus);
    }

    try {
        $sonuc = google_giris_uye(google_giris_kimlik((string) $_GET['code']));
    } catch (InvalidArgumentException $e) {
        $geri($e->getMessage(), $donus);
    } catch (Throwable $e) {
        error_log('[valentra] google giris: ' . $e->getMessage());
        $geri('Google ile giriş tamamlanamadı; lütfen yeniden deneyin.', $donus);
    }

    uye_oturum_ac($sonuc['id']);

    // Yeni uye once rumuzunu gorsun / degistirsin (Network ayarlari).
    header('Location: ' . ($sonuc['yeni'] ? '/network/ayarlar?hosgeldin=1' : ($donus !== '' ? $donus : '/network')), true, 302);
    exit;
}

// Zaten giris yapmis uye.
if (aktif_uye() !== null) {
    header('Location: ' . (uye_guvenli_donus((string) ($_GET['donus'] ?? '')) ?: '/network'), true, 302);
    exit;
}

header('Location: ' . google_giris_baslat((string) ($_GET['donus'] ?? '')), true, 302);
exit;
