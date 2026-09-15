<?php
declare(strict_types=1);

/** Herkese acik sayfalarin ortak ust bolumu. */

$sayfaBasligi  = $sayfaBasligi ?? 'Valentra — Vergi Haberleri';
$sayfaAciklama = $sayfaAciklama ?? 'Vergi mevzuatı, tebliğler ve ekonomi gündeminden derlenen güncel vergi haberleri.';
$aktifKategori = $aktifKategori ?? '';

$menu = kategori_menusu();

/** Bu ust baslik ya da altlarindan biri aktif mi? */
$menuAktif = static function (array $grup) use ($aktifKategori): bool {
    if ($aktifKategori === '') {
        return false;
    }

    if ($grup['slug'] === $aktifKategori) {
        return true;
    }

    return in_array($aktifKategori, array_column($grup['altlar'], 'slug'), true);
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="<?= e($sayfaAciklama) ?>">
    <title><?= e($sayfaBasligi) ?></title>
    <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(varlik('/assets/style.css')) ?>">
</head>
<body>
    <header class="ust-bant">
        <div class="sinirli ust-satir">
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

    <?php if ($menu !== []): ?>
        <nav class="menu-bant" aria-label="Konu grupları">
            <div class="sinirli menu-satir">
                <a class="menu-oge <?= $aktifKategori === '' ? 'aktif' : '' ?>" href="/">Gündem</a>

                <?php foreach ($menu as $grup): ?>
                    <?php if ($grup['altlar'] === []): ?>
                        <a class="menu-oge <?= $menuAktif($grup) ? 'aktif' : '' ?>"
                           href="/kategori.php?k=<?= e($grup['slug']) ?>">
                            <?= e($grup['ad']) ?>
                        </a>
                    <?php else: ?>
                        <div class="menu-grup <?= $menuAktif($grup) ? 'aktif' : '' ?>">
                            <button type="button" class="menu-oge" aria-expanded="false"
                                    aria-haspopup="true">
                                <?= e($grup['ad']) ?>
                                <svg class="ok" width="9" height="6" viewBox="0 0 9 6" aria-hidden="true">
                                    <path d="M1 1l3.5 3.5L8 1" fill="none" stroke="currentColor"
                                          stroke-width="1.6" stroke-linecap="round"/>
                                </svg>
                            </button>

                            <div class="menu-acilir">
                                <?php foreach ($grup['altlar'] as $alt): ?>
                                    <a href="/kategori.php?k=<?= e($alt['slug']) ?>"
                                       class="<?= $aktifKategori === $alt['slug'] ? 'aktif' : '' ?>">
                                        <span><?= e($alt['ad']) ?></span>
                                        <?php if ($alt['adet'] > 0): ?>
                                            <span class="adet"><?= $alt['adet'] ?></span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </nav>

        <script>
        (function () {
            var gruplar = document.querySelectorAll('.menu-grup');

            function hepsiniKapat(haric) {
                gruplar.forEach(function (g) {
                    if (g !== haric) {
                        g.classList.remove('acik');
                        g.querySelector('button').setAttribute('aria-expanded', 'false');
                    }
                });
            }

            gruplar.forEach(function (grup) {
                var dugme = grup.querySelector('button');

                dugme.addEventListener('click', function (olay) {
                    olay.stopPropagation();
                    var acik = grup.classList.toggle('acik');
                    dugme.setAttribute('aria-expanded', acik ? 'true' : 'false');
                    hepsiniKapat(grup);
                });
            });

            document.addEventListener('click', function () { hepsiniKapat(null); });

            document.addEventListener('keydown', function (olay) {
                if (olay.key === 'Escape') { hepsiniKapat(null); }
            });
        })();
        </script>
    <?php endif; ?>

    <main class="sinirli">
