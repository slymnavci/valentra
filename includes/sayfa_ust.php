<?php
declare(strict_types=1);

/** Herkese acik sayfalarin ortak ust bolumu. */

$sayfaBasligi  = $sayfaBasligi ?? 'Valentra — Vergi Haberleri';
$sayfaAciklama = $sayfaAciklama ?? 'Vergi mevzuatı, tebliğler ve ekonomi gündeminden derlenen güncel vergi haberleri.';
$aktifKategori = $aktifKategori ?? '';

require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/ziyaret.php';

/*
 * Ziyaret kaydi.
 *
 * Buraya konuldu cunku herkese acik her sayfa bu dosyayi cagiriyor;
 * tek tek sayfalara eklemek er ya da gec bir sayfanin unutulmasi
 * demekti. $ziyaretHaberId'yi haber sayfasi dolduruyor, boylece
 * "hangi haber okundu" sorusu cevaplanabiliyor.
 *
 * Yonetici oturumu acikken ve bot imzasi tasiyan isteklerde
 * sayilmiyor (bkz. ziyaret_sayilmali).
 */
ziyaret_kaydet(
    isset($ziyaretHaberId) ? (int) $ziyaretHaberId : null,
    $sayfaBasligi
);

$menu = kategori_menusu();

/*
 * Arama motoru etiketleri.
 *
 * $seoGorsel ve $seoSema sayfalar tarafindan doldurulabilir; haber
 * sayfasi kendi gorselini ve NewsArticle semasini veriyor, digerleri
 * varsayilanla yetiniyor.
 */
$seoAdres  = $seoAdres  ?? gecerli_adres();
$seoGorsel = $seoGorsel ?? (site_adresi() . '/assets/logo.svg');
$seoTur    = $seoTur    ?? 'website';
$seoSema   = $seoSema   ?? seo_site_semasi();

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

    <?php /* Ayni icerige birden fazla adresten ulasiliyorsa hangisinin
             asil oldugunu soyler; aksi halde arama motoru ikisini ayri
             sayfa sanip ikisinin de degerini dusurur. */ ?>
    <link rel="canonical" href="<?= e($seoAdres) ?>">

    <meta property="og:type" content="<?= e($seoTur) ?>">
    <meta property="og:site_name" content="Valentra">
    <meta property="og:locale" content="tr_TR">
    <meta property="og:title" content="<?= e($sayfaBasligi) ?>">
    <meta property="og:description" content="<?= e($sayfaAciklama) ?>">
    <meta property="og:url" content="<?= e($seoAdres) ?>">
    <meta property="og:image" content="<?= e($seoGorsel) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($sayfaBasligi) ?>">
    <meta name="twitter:description" content="<?= e($sayfaAciklama) ?>">
    <meta name="twitter:image" content="<?= e($seoGorsel) ?>">

    <?php /* Yapisal veri: arama motoruna sayfanin ne oldugunu acikca
             soyler. Haber siteleri icin belirleyici — haber olarak
             taninmayan sayfa Haberler sekmesine hic girmez. */ ?>
    <script type="application/ld+json"><?= $seoSema ?></script>
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
                <a class="menu-oge <?= $aktifKategori === '' ? 'aktif' : '' ?>" href="/">Ana Sayfa</a>

                <a class="menu-oge <?= ($aktifKategori ?? '') === 'pratik' ? 'aktif' : '' ?>"
                   href="/pratik-bilgiler.php">Pratik Bilgiler</a>

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
                                <?php
                                /*
                                 * Kanun metinleri sayfasi bu acilir
                                 * menunun icinde. Kategori olarak
                                 * eklenmiyor: haber grubu degil, ajan
                                 * oraya haber atamamali ve yaninda
                                 * haber sayisi gostermek anlamsiz olur.
                                 */
                                ?>
                                <?php if ($grup['slug'] === 'vergi-kanunlari'): ?>
                                    <a href="/kanunlar.php"
                                       class="<?= ($aktifKategori ?? '') === 'kanunlar' ? 'aktif' : '' ?>">
                                        <span>Kanun Metinleri</span>
                                    </a>
                                <?php endif; ?>

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
