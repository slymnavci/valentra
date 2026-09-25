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

require_once __DIR__ . '/menu.php';

$menu = site_menusu(kategori_menusu());

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

// Ana Sayfa yalnizca ana sayfada isaretli; kategorisiz baska sayfalarda
// (arama, hata) hicbir oge isaretli degil.
$anaSayfa = ($_SERVER['SCRIPT_NAME'] ?? '') === '/index.php';
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

    <?php if (($seoRobots ?? '') !== ''): ?>
        <meta name="robots" content="<?= e($seoRobots) ?>">
    <?php endif; ?>

    <?php
    /*
     * Search Console dogrulama etiketi.
     *
     * Panelden girilebiliyor (Arama motoru sayfasi); girilmemisse
     * kodda tanimli varsayilan kullaniliyor. Google siteyi ancak
     * dogruladiktan sonra indeksleme raporu gosteriyor; o rapor
     * olmadan "Google bizi goruyor mu" sorusunun cevabi yok.
     *
     * Deger gizli degil — zaten her sayfanin kaynagında herkese acik
     * duruyor. Islevi sahiplik kanitlamak: Search Console'a bu siteyi
     * ekleyen kisinin sunucuya dosya koyabildigini gosteriyor.
     */
    $googleKodu = ayar_oku('google_dogrulama', SEO_GOOGLE_DOGRULAMA);
    ?>
    <?php if ($googleKodu !== ''): ?>
        <meta name="google-site-verification" content="<?= e($googleKodu) ?>">
    <?php endif; ?>

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
<?php
/*
 * Sayfa kendi govde sinifini ekleyebilir. Su an tek kullanici haber
 * detayi: "okuma" sinifi butun bantlari (ust bant, menu, ana kolon,
 * alt bant) ayni dar kaba oturtuyor. Yalnizca <main>'i daraltmak
 * logonun sayfanin en solunda, yazinin ise 280 piksel icerde
 * baslamasi demekti; bantlar da birlikte daralinca hizalama bozulmuyor.
 */
$govdeSinifi = trim((string) ($govdeSinifi ?? ''));
?>
<body<?= $govdeSinifi !== '' ? ' class="' . e($govdeSinifi) . '"' : '' ?>>
    <header class="ust-bant">
        <div class="sinirli ust-satir">
            <a class="logo" href="/">
                <img class="marka" src="/assets/logo.svg" alt="" width="40" height="35">
                <span class="yazi">
                    <span class="ad">VALENTRA</span>
                    <span class="alt">VERGİ, MUHASEBE VE FİNANS</span>
                </span>
            </a>
            <div class="ust-sag">
                <div class="ust-bilgi"><?= e(tarih_bicimle(date('Y-m-d H:i:s'), false)) ?></div>

                <form class="ust-arama" action="<?= e(arama_yolu()) ?>" method="get" role="search">
                    <label class="gizli-etiket" for="ust-arama-q">Sitede ara</label>
                    <input id="ust-arama-q" type="search" name="q" placeholder="Haber, mevzuat, pratik bilgi ara"
                           value="<?= e(($aktifKategori ?? '') === 'ara' ? (string) ($_GET['q'] ?? '') : '') ?>"
                           maxlength="100" autocomplete="off">
                    <button type="submit" aria-label="Ara">
                        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
                            <circle cx="7" cy="7" r="5.2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M11 11l3.6 3.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <?php if ($menu !== []): ?>
        <nav class="menu-bant" aria-label="Ana menü">
            <div class="sinirli menu-satir">
                <a class="menu-oge <?= $aktifKategori === '' && $anaSayfa ? 'aktif' : '' ?>" href="/">Ana Sayfa</a>

                <?php foreach ($menu as $oge): ?>
                    <?php if ($oge['href'] !== null): ?>
                        <a class="menu-oge <?= site_menu_aktif($oge, $aktifKategori) ? 'aktif' : '' ?>"
                           href="<?= e($oge['href']) ?>"><?= e($oge['ad']) ?></a>
                    <?php else: ?>
                        <div class="menu-grup <?= site_menu_aktif($oge, $aktifKategori) ? 'aktif' : '' ?>">
                            <button type="button" class="menu-oge" aria-expanded="false"
                                    aria-haspopup="true">
                                <?= e($oge['ad']) ?>
                                <svg class="ok" width="9" height="6" viewBox="0 0 9 6" aria-hidden="true">
                                    <path d="M1 1l3.5 3.5L8 1" fill="none" stroke="currentColor"
                                          stroke-width="1.6" stroke-linecap="round"/>
                                </svg>
                            </button>

                            <div class="menu-acilir">
                                <?php foreach ($oge['altlar'] as $alt): ?>
                                    <a href="<?= e($alt['href']) ?>"
                                       class="<?= $aktifKategori === $alt['anahtar'] ? 'aktif' : '' ?>">
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
