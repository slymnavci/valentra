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
        <div class="sinirli">
            <a class="logo" href="index.php" style="color:#fff;display:flex;align-items:center;gap:10px;"><img src="/assets/logo.svg" alt="" width="30" height="26" style="background:#fff;border-radius:4px;padding:2px;">VALENTRA</a>
            <nav>
                <a href="/" target="_blank" rel="noopener">Siteyi gör</a>
                <a href="kaynaklar.php">Kaynaklar</a>
                <a href="veritabani.php">Veritabanı</a>
                <a href="anahtarlar.php">Ajan anahtarları</a>
                <a href="cikis.php"><?= e(aktif_yonetici_ad()) ?> — Çıkış</a>
            </nav>
        </div>
    </header>

    <div class="sinirli">
