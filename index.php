<?php
declare(strict_types=1);

/**
 * Valentra - gecici test ana sayfasi.
 * Sistemin ve deploy akisinin calistigini dogrulamak icin kullanilir.
 */

$baslik   = 'VALENTRA';
$altYazi  = 'Sistem başarıyla çalışıyor';
$phpSurum = PHP_VERSION;
$zaman    = date('d.m.Y H:i');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($baslik, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            color-scheme: light dark;
            --zemin: #0f1115;
            --zemin-yumusak: #161a21;
            --metin: #f4f5f7;
            --metin-soluk: #9aa2b1;
            --cizgi: rgba(255, 255, 255, .08);
            --vurgu: #6ea8fe;
        }

        @media (prefers-color-scheme: light) {
            :root {
                --zemin: #f7f8fa;
                --zemin-yumusak: #ffffff;
                --metin: #14171c;
                --metin-soluk: #5c6472;
                --cizgi: rgba(0, 0, 0, .08);
                --vurgu: #2563eb;
            }
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            padding: 48px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--zemin);
            color: var(--metin);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .kutu {
            width: 100%;
            max-width: 640px;
            text-align: center;
        }

        h1 {
            margin: 0;
            font-size: clamp(2.75rem, 13vw, 6.5rem);
            font-weight: 700;
            letter-spacing: .14em;
            line-height: 1.05;
            text-indent: .14em;
        }

        .alt-yazi {
            margin: 20px 0 0;
            font-size: clamp(1rem, 3.4vw, 1.35rem);
            font-weight: 400;
            color: var(--metin-soluk);
        }

        .durum {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-top: 36px;
            padding: 9px 18px;
            border: 1px solid var(--cizgi);
            border-radius: 999px;
            background: var(--zemin-yumusak);
            font-size: .82rem;
            color: var(--metin-soluk);
        }

        .nokta {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--vurgu);
            flex: none;
        }

        .bilgi {
            margin: 28px 0 0;
            font-size: .78rem;
            letter-spacing: .03em;
            color: var(--metin-soluk);
        }
    </style>
</head>
<body>
    <main class="kutu">
        <h1><?= htmlspecialchars($baslik, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="alt-yazi"><?= htmlspecialchars($altYazi, ENT_QUOTES, 'UTF-8') ?></p>

        <div class="durum">
            <span class="nokta" aria-hidden="true"></span>
            <span>Yayında</span>
        </div>

        <p class="bilgi">
            PHP <?= htmlspecialchars($phpSurum, ENT_QUOTES, 'UTF-8') ?>
            &middot; <?= htmlspecialchars($zaman, ENT_QUOTES, 'UTF-8') ?>
        </p>
    </main>
</body>
</html>
