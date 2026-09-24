<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($panelBasligi ?? 'Yönetim Paneli') ?> — Valentra</title>
    <link rel="stylesheet" href="<?= e_varlik('/assets/admin.css') ?>">
</head>
<body>
    <header class="panel-ust">
        <?php
    require_once __DIR__ . '/../includes/sema.php';

    // Veritabani guncellenmemisse panelin her sayfasinda uyar: kullanici
    // aksi halde bunu yalnizca site hata verdiginde fark eder.
    if (!sema_guncel_mi() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'veritabani.php'):
    ?>
        <div class="sinirli">
            <div class="uyari uyari-hata" style="margin-top:18px;">
                <strong>Veritabanı güncellenmesi gerekiyor.</strong>
                Site son güncellemede yeni tablo ve sütunlar kazandı; bunlar
                henüz uygulanmadığı için kaynak listesi ve konu grupları boş
                görünüyor, site hata verebilir.
                <a href="veritabani.php" class="dugme dugme-ana"
                   style="margin-left:10px;">Şimdi güncelle</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="sinirli">
            <a class="logo" href="index.php" style="color:#fff;display:flex;align-items:center;gap:10px;"><img src="/assets/logo.svg" alt="" width="30" height="26" style="background:#fff;border-radius:4px;padding:2px;">VALENTRA</a>
            <nav>
                <a href="/" target="_blank" rel="noopener">Siteyi gör</a>
                <a href="istatistik.php">Ziyaretçiler</a>
                <a href="kose-yazilari.php">Köşe yazıları</a>
                <a href="ajan.php">Ajan</a>
                <a href="pratik.php">Pratik bilgiler</a>
                <a href="grafikler.php">Grafikler</a>
                <a href="kaynaklar.php">Kaynaklar</a>
                <a href="kanunlar.php">Kanun metinleri</a>
                <a href="seo.php">Arama motoru</a>
                <a href="veritabani.php">Veritabanı</a>
                <a href="anahtarlar.php">Ajan anahtarları</a>
                <a href="cikis.php"><?= e(aktif_yonetici_ad()) ?> — Çıkış</a>
            </nav>
        </div>
    </header>

    <div class="sinirli">
