<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

oturum_baslat();

if (oturum_acik()) {
    yonlendir('index.php');
}

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $kullaniciAdi = (string) ($_POST['kullanici_adi'] ?? '');
    $parola       = (string) ($_POST['parola'] ?? '');

    // Kaba kuvvet denemelerini yavaslatmak icin basit oturum bazli gecikme.
    $denemeler = (int) ($_SESSION['giris_denemesi'] ?? 0);
    if ($denemeler >= 5) {
        usleep(min(3, $denemeler - 4) * 1000000);
    }

    if ($kullaniciAdi === '' || $parola === '') {
        $hata = 'Kullanıcı adı ve parola gerekli.';
    } elseif (giris_yap($kullaniciAdi, $parola)) {
        unset($_SESSION['giris_denemesi']);
        yonlendir('index.php');
    } else {
        $_SESSION['giris_denemesi'] = $denemeler + 1;
        $hata = 'Kullanıcı adı veya parola hatalı.';
    }
}

$kurulumGerekli = !yonetici_var_mi();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Yönetici Girişi — Valentra</title>
    <link rel="stylesheet" href="<?= e_varlik('/assets/admin.css') ?>">
</head>
<body>
    <div class="giris-sayfa">
        <div class="giris-kutusu">
            <div class="logo">
                <img src="/assets/logo.svg" alt="" width="54" height="47">
                <span class="ad">VALENTRA</span>
            </div>
            <div class="aciklama">Yönetim Paneli</div>

            <?php if ($kurulumGerekli): ?>
                <div class="uyari uyari-bilgi">
                    Henüz yönetici hesabı yok.
                    <a href="/kurulum.php">Kurulum sayfasından</a> ilk hesabı oluşturun.
                </div>
            <?php endif; ?>

            <?php if ($hata !== ''): ?>
                <div class="uyari uyari-hata"><?= e($hata) ?></div>
            <?php endif; ?>

            <form class="kutu" method="post" action="giris.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">

                <div class="alan">
                    <label for="kullanici_adi">Kullanıcı adı</label>
                    <input type="text" id="kullanici_adi" name="kullanici_adi"
                           autocomplete="username" autofocus required>
                </div>

                <div class="alan">
                    <label for="parola">Parola</label>
                    <input type="password" id="parola" name="parola"
                           autocomplete="current-password" required>
                </div>

                <button type="submit" class="dugme dugme-ana" style="width:100%;">Giriş yap</button>
            </form>
        </div>
    </div>
</body>
</html>
