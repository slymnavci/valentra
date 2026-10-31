<?php
declare(strict_types=1);

/** Herkese acik sayfalarin ortak ust bolumu. */

$sayfaBasligi  = $sayfaBasligi ?? 'Valentra — Güncel Haberler';
$sayfaAciklama = $sayfaAciklama ?? 'Türkiye ve dünyadan gündem, politika, ekonomi, spor, teknoloji, sağlık ve kültür-sanat haberlerinin tarafsız özetleri; her haberde kaynağı ve bağlantısıyla.';
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

// Onbellekten sunulan kopyada da ayni kayit yazilsin (bkz. includes/sayfa_onbellek.php).
if (function_exists('sayfa_onbellek_bilgi')) {
    sayfa_onbellek_bilgi(isset($ziyaretHaberId) ? (int) $ziyaretHaberId : null, $sayfaBasligi);
}

require_once __DIR__ . '/menu.php';

$menu = site_menusu();

/*
 * Arama motoru etiketleri.
 *
 * $seoGorsel ve $seoSema sayfalar tarafindan doldurulabilir; haber
 * sayfasi kendi gorselini ve NewsArticle semasini veriyor, digerleri
 * varsayilanla yetiniyor.
 */
$seoAdres  = $seoAdres  ?? gecerli_adres();
// Varsayilan paylasim gorseli PNG: SVG'yi Facebook, WhatsApp ve X gostermiyor.
$seoGorsel = $seoGorsel ?? (site_adresi() . '/assets/paylasim.jpg');
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
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/ikon-180.png">
    <?php /* Gece/gunduz modu: ziyaretcinin sectigi tema stil dosyasindan
             ONCE uygulaniyor, yoksa sayfa bir an yanlis renkte gorunup
             sonra degisirdi. Secim yoksa sistem ayari gecerli. */ ?>
    <script>(function(){try{var t=localStorage.getItem('valentra-tema');if(t==='koyu'||t==='acik'){document.documentElement.setAttribute('data-tema',t);}}catch(e){}})();</script>
    <link rel="stylesheet" href="<?= e(varlik('/assets/style.css')) ?>">

    <?php /* Ayni icerige birden fazla adresten ulasiliyorsa hangisinin
             asil oldugunu soyler; aksi halde arama motoru ikisini ayri
             sayfa sanip ikisinin de degerini dusurur. */ ?>
    <link rel="canonical" href="<?= e($seoAdres) ?>">

    <?php /* Dizine acik sayfalarda buyuk gorsel onizleme izni: yoksa
             Google Discover ve Haberler haberi kucuk resimle gosteriyor. */ ?>
    <meta name="robots" content="<?= e(($seoRobots ?? '') !== '' ? $seoRobots : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1') ?>">

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

    <?php /* Google Analytics ve AdSense (panelden; bos ise hicbir sey yok). */ ?>
    <?php require_once __DIR__ . '/olcum_reklam.php'; ?>
    <?= olcum_head_html() ?>
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
<?php if (!empty($networkSayfasi)): ?>
    <?php
    /* V Network kendi ust bandiyla ayri bir alan (31.10.2026, site sahibi:
       "buraya tikladigimizda farkli bir sayfaya gidecegiz"); bkz. includes/network_ust.php. */
    require __DIR__ . '/network_ust.php';
    ?>
<?php else: ?>
    <?php
    /*
     * UST BOLUM (site sahibinin istegi, 24.10.2026: "menuleri daha kolay
     * ve akici yapalim; Milliyet'e benzesin ama tam benzemesin; beyaz ve
     * kirmizi tonlar, agirlikli beyaz; bir yere hava durumunu koyalim").
     *
     *   1. Logo satiri: menu dugmesi (acilir cekmece), logo; sagda arama,
     *      yaninda tarih ve gece/gunduz (30.10.2026); beyaz.
     *   2. Menu: beyaz zemin, koyu kalin yazi, aktif ogede kirmizi cizgi;
     *      sayfadaki tek yapiskan serit.
     *   3. Kayan son dakika seridi (30.10.2026'ya kadar en ustteydi).
     */
    ?>

    <header class="ust-bant">
        <div class="sinirli ust-satir">
            <button type="button" class="cekmece-dugme" id="cekmece-dugme"
                    aria-controls="cekmece" aria-expanded="false" aria-label="Menüyü aç">
                <span></span><span></span><span></span>
            </button>

            <?php /* Logo (30.10.2026, site sahibinin yeni logosu): lacivert-kirmizi V ve
                     "Λ" harfli VALENTRA yazisi SVG olarak sayfada; koyu temada renkleri
                     CSS'ten degisiyor. */ ?>
            <a class="logo" href="/" aria-label="Valentra — Ana sayfa">
                <svg class="marka" viewBox="0 0 340 262" aria-hidden="true" focusable="false">
                    <defs>
                        <linearGradient id="ust-vl" x1="0" y1="0" x2="1" y2="1"><stop class="vl1" offset="0" stop-color="#121a2c"/><stop class="vl2" offset=".55" stop-color="#26324d"/><stop class="vl3" offset="1" stop-color="#2e3b58"/></linearGradient>
    <linearGradient id="ust-vk" x1="0" y1="0" x2=".4" y2="1"><stop offset="0" stop-color="#ff1f2a"/><stop offset="1" stop-color="#b30d16"/></linearGradient>
                    </defs>
                    <path class="vl" d="M8 12 H88 L232 254 H152 Z" fill="url(#ust-vl)" stroke="url(#ust-vl)" stroke-width="10" stroke-linejoin="round"/>
  <path d="M8 12 H88 L120 66 C90 50 40 46 14 22 Z" fill="#0a0f1a" opacity=".5"/>
  <path d="M218 6 H334 L226 190 L174 98 Z" fill="url(#ust-vk)" stroke="url(#ust-vk)" stroke-width="6" stroke-linejoin="round"/>
                </svg>
                <span class="yazi">
                    <svg class="ad" viewBox="0 0 806 100" fill="currentColor" aria-hidden="true" focusable="false">
                        <defs><clipPath id="ust-kes"><rect width="806" height="100"/></clipPath></defs>
                        <g clip-path="url(#ust-kes)">
    <path d="M-5 -12 L46 100 L97 -12" fill="none" stroke="currentColor" stroke-width="21"/>
    <path d="M107 112 L158 0 L209 112" fill="none" stroke="currentColor" stroke-width="21" stroke-miterlimit="10"/>
    <rect x="224" width="21" height="100"/><rect x="224" y="79" width="68" height="21"/>
    <rect x="312" width="21" height="100"/><rect x="312" width="70" height="21"/><rect x="312" y="39.5" width="64" height="21"/><rect x="312" y="79" width="70" height="21"/>
    <rect x="402" width="21" height="100"/><rect x="469" width="21" height="100"/><path d="M402 0 H423 L490 100 H469 Z"/>
    <rect x="510" width="80" height="21"/><rect x="539.5" width="21" height="100"/>
    <rect x="610" width="21" height="100"/><path d="M620.5 10.5 H650 A25.0 25.0 0 0 1 650 49.5 H620.5" fill="none" stroke="currentColor" stroke-width="21"/><path d="M639 52 H662 L695 100 H671 Z"/>
    <path d="M709 112 L760 0 L811 112" fill="none" stroke="currentColor" stroke-width="21" stroke-miterlimit="10"/>
  </g>
                    </svg>
                    <span class="gizli-etiket">Valentra</span>
                    <span class="alt">GÜNCEL HABER ÖZETLERİ</span>
                </span>
            </a>

            <form class="ust-arama" action="<?= e(arama_yolu()) ?>" method="get" role="search">
                <label class="gizli-etiket" for="ust-arama-q">Sitede ara</label>
                <input id="ust-arama-q" type="search" name="q" placeholder="Haber ara"
                       value="<?= e(($aktifKategori ?? '') === 'ara' ? (string) ($_GET['q'] ?? '') : '') ?>"
                       maxlength="100" autocomplete="off">
                <button type="submit" aria-label="Ara">
                    <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
                        <circle cx="7" cy="7" r="5.2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M11 11l3.6 3.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </button>
            </form>

            <?php /* Tarih ve gece/gunduz dugmesi aramanin yaninda, sagda (30.10.2026,
                     site sahibi: "tarih ve gece gunduz logonun uzerine saga, arama
                     cubugunun yanina"). Onceden logonun ustunde ayri bir serit
                     (bilgi-cubugu) idi. */ ?>
            <div class="ust-bilgi-grup">
                <span class="bilgi-tarih"><?= e(tarih_bicimle(date('Y-m-d H:i:s'), false)) ?></span>

                <button type="button" class="tema-dugme" id="tema-dugme"
                        aria-label="Gece / gündüz modu" title="Gece / gündüz modu">
                    <svg class="tema-ay" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    </svg>
                    <svg class="tema-gunes" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <?php
    /*
     * ACILIR MENU (site sahibinin istegi, 24.10.2026: "Valentra'nin yanina
     * acilir gizlenir bir menu isareti koyalim, basinca menu acilsin").
     * Soldan kayan cekmece: butun basliklar ve alt basliklar tek listede.
     * Ust menu de duruyor; cekmece ozellikle telefonda yatay kayan menu
     * yerine hepsini bir bakista gosteriyor.
     */
    ?>
    <div class="cekmece" id="cekmece" hidden>
        <div class="cekmece-perde" data-kapat></div>
        <nav class="cekmece-panel" aria-label="Tüm bölümler">
            <div class="cekmece-ust">
                <span class="cekmece-baslik">Menü</span>
                <button type="button" class="cekmece-kapat" data-kapat aria-label="Menüyü kapat">&times;</button>
            </div>

            <a class="cekmece-oge <?= $aktifKategori === '' && $anaSayfa ? 'aktif' : '' ?>" href="/">Ana Sayfa</a>

            <?php foreach ($menu as $oge): ?>
                <?php if ($oge['href'] !== null): ?>
                    <a class="cekmece-oge <?= site_menu_aktif($oge, $aktifKategori) ? 'aktif' : '' ?>"
                       href="<?= e($oge['href']) ?>"><?= site_menu_adi_html($oge) ?></a>
                <?php else: ?>
                    <details class="cekmece-grup" <?= site_menu_aktif($oge, $aktifKategori) ? 'open' : '' ?>>
                        <summary class="cekmece-oge"><?= e($oge['ad']) ?></summary>
                        <?php foreach ($oge['altlar'] as $alt): ?>
                            <a class="cekmece-alt <?= $aktifKategori === $alt['anahtar'] ? 'aktif' : '' ?>"
                               href="<?= e($alt['href']) ?>"><?= e($alt['ad']) ?></a>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>

    <script>
    (function () {
        var cekmece = document.getElementById('cekmece');
        var dugme = document.getElementById('cekmece-dugme');
        if (!cekmece || !dugme) { return; }

        function ac() {
            cekmece.hidden = false;
            // Bir kare sonra: gecis (transition) gizli durumdan baslasin.
            requestAnimationFrame(function () { cekmece.classList.add('acik'); });
            dugme.setAttribute('aria-expanded', 'true');
            document.documentElement.classList.add('cekmece-acik');
            var ilk = cekmece.querySelector('.cekmece-kapat');
            if (ilk) { ilk.focus(); }
        }

        function kapat() {
            cekmece.classList.remove('acik');
            dugme.setAttribute('aria-expanded', 'false');
            document.documentElement.classList.remove('cekmece-acik');
            setTimeout(function () { cekmece.hidden = true; }, 200);
            dugme.focus();
        }

        dugme.addEventListener('click', function () {
            if (cekmece.hidden) { ac(); } else { kapat(); }
        });

        cekmece.addEventListener('click', function (olay) {
            if (olay.target.closest('[data-kapat]')) { kapat(); }
        });

        document.addEventListener('keydown', function (olay) {
            if (olay.key === 'Escape' && !cekmece.hidden) { kapat(); }
        });
    })();
    </script>


    <script>
    (function () {
        // Gece/gunduz: secim tarayicida saklaniyor (yalnizca bu
        // ziyaretci icin). Ilk tiklamada o an gorulen temanin tersi.
        var kok = document.documentElement;
        var dugme = document.getElementById('tema-dugme');

        function koyuMu() {
            var t = kok.getAttribute('data-tema');
            if (t) { return t === 'koyu'; }
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        function etiket() {
            dugme.setAttribute('aria-pressed', koyuMu() ? 'true' : 'false');
        }

        if (!dugme) { return; }
        etiket();

        dugme.addEventListener('click', function () {
            var yeni = koyuMu() ? 'acik' : 'koyu';
            kok.setAttribute('data-tema', yeni);
            try { localStorage.setItem('valentra-tema', yeni); } catch (e) {}
            etiket();
        });
    })();
    </script>

    <?php if ($menu !== []): ?>
        <nav class="menu-bant" aria-label="Ana menü">
            <div class="sinirli menu-satir">
                <a class="menu-oge <?= $aktifKategori === '' && $anaSayfa ? 'aktif' : '' ?>" href="/">Ana Sayfa</a>

                <?php foreach ($menu as $oge): ?>
                    <?php if ($oge['href'] !== null): ?>
                        <a class="menu-oge <?= site_menu_aktif($oge, $aktifKategori) ? 'aktif' : '' ?><?= !empty($oge['network']) ? ' menu-oge-network' : '' ?>"
                           href="<?= e($oge['href']) ?>"><?= site_menu_adi_html($oge) ?></a>
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

    <?php
    /*
     * KAYAN SON DAKIKA SERIDI (site sahibinin istegi, 24.10.2026: "en
     * ustte de kayan son dakika haberleri gecsin"; 30.10.2026: "son
     * dakikayi asagiya alalim": artik logo ve menunun altinda). Kaynakta bugun
     * yayimlanmis en yeni haberler; liste iki kez basiliyor ki kayma
     * kesintisiz donsun (ikinci kopya ekran okuyucudan gizli). Uzerine
     * gelince ya da odaklaninca duruyor; hareket azaltma tercihinde
     * kaymiyor, yatay kaydirilabiliyor.
     */
    $sonDakikaSeridi = haber_son_dakika_seridi();
    ?>
    <?php if ($sonDakikaSeridi !== []): ?>
        <div class="sd-serit" aria-label="Son dakika haberleri">
            <div class="sinirli sd-satir">
                <span class="sd-etiket">Son Dakika</span>
                <div class="sd-pencere">
                    <div class="sd-akis" style="--sure: <?= max(30, count($sonDakikaSeridi) * 7) ?>s">
                        <?php foreach ([false, true] as $kopya): ?>
                            <ul class="sd-liste" <?= $kopya ? 'aria-hidden="true"' : '' ?>>
                                <?php foreach ($sonDakikaSeridi as $sd): ?>
                                    <li>
                                        <span class="sd-saat"><?= e($sd['zaman']) ?></span>
                                        <a href="<?= e(haber_yolu($sd['slug'])) ?>" <?= $kopya ? 'tabindex="-1"' : '' ?>><?= e($sd['baslik']) ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

    <main class="sinirli">
