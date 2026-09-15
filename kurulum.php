<?php
declare(strict_types=1);

/**
 * Tek seferlik kurulum.
 *
 * Yalnizca veritabaninda hic yonetici yokken calisir; ilk hesap
 * olusturulduktan sonra kendini kapatir. Parola buraya tarayicidan girilir,
 * veritabanina yalnizca bcrypt ozeti yazilir; hicbir dosyaya kaydedilmez.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

oturum_baslat();

$semaHazir = true;
try {
    db()->query('SELECT 1 FROM yoneticiler LIMIT 1');
} catch (PDOException $e) {
    $semaHazir = false;
}

$kilitli = $semaHazir && yonetici_var_mi();
$hata    = '';
$basari  = false;

if (!$kilitli && $semaHazir && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $kullaniciAdi = mb_strtolower(trim((string) ($_POST['kullanici_adi'] ?? '')), 'UTF-8');
    $ad           = trim((string) ($_POST['ad'] ?? ''));
    $parola       = (string) ($_POST['parola'] ?? '');
    $parolaTekrar = (string) ($_POST['parola_tekrar'] ?? '');

    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $kullaniciAdi)) {
        $hata = 'Kullanıcı adı 3-60 karakter olmalı; harf, rakam, nokta, alt çizgi ve tire kullanılabilir.';
    } elseif (mb_strlen($parola, 'UTF-8') < 10) {
        $hata = 'Parola en az 10 karakter olmalı.';
    } elseif (!hash_equals($parola, $parolaTekrar)) {
        $hata = 'Parolalar eşleşmiyor.';
    } else {
        $ifade = db()->prepare(
            'INSERT INTO yoneticiler (kullanici_adi, ad, parola_hash)
             VALUES (:kad, :ad, :hash)'
        );
        $ifade->execute([
            'kad'  => $kullaniciAdi,
            'ad'   => $ad !== '' ? $ad : $kullaniciAdi,
            'hash' => password_hash($parola, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        $basari  = true;
        $kilitli = true;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kurulum — Valentra</title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <div class="giris-sayfa">
        <div class="giris-kutusu">
            <div class="logo">
                <img src="/assets/logo.svg" alt="" width="54" height="47">
                <span class="ad">VALENTRA</span>
            </div>
            <div class="aciklama">Kurulum</div>

            <?php if (!$semaHazir): ?>
                <div class="uyari uyari-hata">
                    Veritabanı tabloları bulunamadı. Önce <code>sql/schema.sql</code>
                    dosyasını veritabanınızda çalıştırın.
                </div>

            <?php elseif ($basari): ?>
                <div class="uyari uyari-basari">
                    Yönetici hesabı oluşturuldu.
                    <a href="/admin/giris.php">Giriş yapabilirsiniz.</a>
                </div>
                <div class="uyari uyari-bilgi">
                    Bu sayfa artık kendini kapattı. Yine de
                    <code>kurulum.php</code> dosyasını sunucudan silmeniz önerilir.
                </div>

            <?php elseif ($kilitli): ?>
                <div class="uyari uyari-bilgi">
                    Kurulum tamamlanmış. Yönetici hesabı zaten var, bu sayfa devre dışı.
                    <a href="/admin/giris.php">Giriş sayfasına git</a>
                </div>

            <?php else: ?>
                <?php if ($hata !== ''): ?>
                    <div class="uyari uyari-hata"><?= e($hata) ?></div>
                <?php endif; ?>

                <form class="kutu" method="post" action="kurulum.php">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">

                    <div class="alan">
                        <label for="kullanici_adi">Kullanıcı adı</label>
                        <input type="text" id="kullanici_adi" name="kullanici_adi"
                               autocomplete="username" autofocus required>
                    </div>

                    <div class="alan">
                        <label for="ad">Görünen ad</label>
                        <input type="text" id="ad" name="ad" placeholder="İsteğe bağlı">
                    </div>

                    <div class="alan">
                        <label for="parola">Parola</label>
                        <input type="password" id="parola" name="parola"
                               autocomplete="new-password" required>
                        <div class="ipucu">En az 10 karakter.</div>
                    </div>

                    <div class="alan">
                        <label for="parola_tekrar">Parola (tekrar)</label>
                        <input type="password" id="parola_tekrar" name="parola_tekrar"
                               autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="dugme dugme-ana" style="width:100%;">
                        Yönetici hesabını oluştur
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
