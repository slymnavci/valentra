<?php
declare(strict_types=1);

/** Herkese acik sayfalarin ortak ust bolumu. $sayfaBasligi disaridan gelir. */

$sayfaBasligi = $sayfaBasligi ?? 'Valentra — Vergi Haberleri';
$sayfaAciklama = $sayfaAciklama ?? 'Vergi mevzuatı, tebliğler ve ekonomi gündeminden derlenen güncel vergi haberleri.';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="<?= e($sayfaAciklama) ?>">
    <title><?= e($sayfaBasligi) ?></title>
    <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="ust-bant">
        <div class="sinirli">
            <a class="logo" href="/">
                <img class="marka" src="/assets/logo.svg" alt="" width="40" height="35">
                <span class="yazi">
                    <span class="ad">VALENTRA</span>
                    <span class="alt">YEMİNLİ MALİ MÜŞAVİRLİK</span>
                </span>
            </a>
            <div class="ust-bilgi"><?= e(tarih_bicimle(date('Y-m-d H:i:s'), false)) ?> &middot; Vergi Gündemi</div>
        </div>
    </header>

    <main class="sinirli">
