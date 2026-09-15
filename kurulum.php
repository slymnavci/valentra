<?php
declare(strict_types=1);

/**
 * Tek seferlik kurulum.
 *
 * İki adım: (1) veritabanı tablolarını oluştur, (2) ilk yönetici hesabını aç.
 * Hesap oluşturulduktan sonra sayfa kendini kapatır.
 *
 * Parola buraya tarayıcıdan girilir, veritabanına yalnızca bcrypt özeti
 * yazılır; hiçbir dosyaya kaydedilmez.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sema.php';

oturum_baslat();

$tablolar  = sema_durumu();
$semaHazir = !in_array(false, $tablolar, true);

// Tablolar varsa ve icinde yonetici varsa kurulum bitmistir.
$kilitli = $semaHazir && yonetici_var_mi();

$hata     = '';
$basari   = false;
$semaNotu = '';

if (!$kilitli && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'sema') {
        $sonuc = sema_kur(__DIR__ . '/sql/schema.sql');

        if ($sonuc['tamam']) {
            $semaNotu  = $sonuc['calisan'] . ' ifade çalıştırıldı, tablolar hazır.';
            $tablolar  = sema_durumu();
            $semaHazir = true;
        } else {
            $hata = $sonuc['mesaj'];
            $tablolar = sema_durumu();
            $semaHazir = !in_array(false, $tablolar, true);
        }

    } elseif ($islem === 'yonetici' && $semaHazir) {
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
            try {
                db()->prepare(
                    'INSERT INTO yoneticiler (kullanici_adi, ad, parola_hash)
                     VALUES (:kad, :ad, :hash)'
                )->execute([
                    'kad'  => $kullaniciAdi,
                    'ad'   => $ad !== '' ? $ad : $kullaniciAdi,
                    'hash' => password_hash($parola, PASSWORD_BCRYPT, ['cost' => 12]),
                ]);

                $basari  = true;
                $kilitli = true;
            } catch (PDOException $e) {
                $hata = 'Hesap oluşturulamadı. Bu kullanıcı adı zaten kullanılıyor olabilir.';
            }
        }
    }
}

$eksikTablolar = array_keys(array_filter($tablolar, static fn (bool $v): bool => !$v));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kurulum — Valentra</title>
    <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e_varlik('/assets/admin.css') ?>">
</head>
<body>
    <div class="giris-sayfa">
        <div class="giris-kutusu" style="max-width:460px;">
            <div class="logo">
                <img src="/assets/logo.svg" alt="" width="54" height="47">
                <span class="ad">VALENTRA</span>
            </div>
            <div class="aciklama">Kurulum</div>

            <?php if ($basari): ?>
                <div class="uyari uyari-basari">
                    <strong>Kurulum tamamlandı.</strong>
                    Artık <a href="/admin/giris.php">giriş yapabilirsiniz</a>.
                </div>
                <div class="uyari uyari-bilgi">
                    Bu sayfa kendini kapattı. Yine de <code>kurulum.php</code>
                    dosyasını sunucudan silmeniz önerilir.
                </div>

            <?php elseif ($kilitli): ?>
                <div class="uyari uyari-bilgi">
                    Kurulum daha önce tamamlanmış. Yönetici hesabı zaten var,
                    bu sayfa devre dışı.
                    <a href="/admin/giris.php">Giriş sayfasına git</a>
                </div>

            <?php else: ?>

                <?php if ($hata !== ''): ?>
                    <div class="uyari uyari-hata"><?= e($hata) ?></div>
                <?php endif; ?>

                <?php if ($semaNotu !== ''): ?>
                    <div class="uyari uyari-basari"><?= e($semaNotu) ?></div>
                <?php endif; ?>

                <!-- Adım 1: tablolar -->
                <div class="kutu">
                    <h2 style="margin:0 0 4px;font-size:1rem;">
                        1. Veritabanı tabloları
                        <?php if ($semaHazir): ?>
                            <span class="rozet rozet-yayinda">hazır</span>
                        <?php else: ?>
                            <span class="rozet rozet-taslak">eksik</span>
                        <?php endif; ?>
                    </h2>

                    <?php if ($semaHazir): ?>
                        <p class="ipucu" style="margin:8px 0 0;">
                            <?= count($tablolar) ?> tablonun tamamı yerinde.
                        </p>
                    <?php else: ?>
                        <p class="ipucu" style="margin:8px 0 14px;">
                            Eksik: <?= e(implode(', ', $eksikTablolar)) ?>.
                            Aşağıdaki düğme <code>sql/schema.sql</code> dosyasını
                            çalıştırarak tabloları oluşturur; mevcut tablolara
                            dokunmaz.
                        </p>

                        <form method="post" action="kurulum.php">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="sema">
                            <button type="submit" class="dugme dugme-ana" style="width:100%;">
                                Tabloları oluştur
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Adım 2: yonetici -->
                <div class="kutu" <?= $semaHazir ? '' : 'style="opacity:.5;"' ?>>
                    <h2 style="margin:0 0 14px;font-size:1rem;">2. Yönetici hesabı</h2>

                    <?php if (!$semaHazir): ?>
                        <p class="ipucu" style="margin:0;">
                            Önce tabloları oluşturun.
                        </p>
                    <?php else: ?>
                        <form method="post" action="kurulum.php">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="yonetici">

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
                                Hesabı oluştur ve kurulumu bitir
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>
