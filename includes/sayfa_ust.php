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
    <?php /* Gece/gunduz modu: ziyaretcinin sectigi tema stil dosyasindan
             ONCE uygulaniyor, yoksa sayfa bir an yanlis renkte gorunup
             sonra degisirdi. Secim yoksa sistem ayari gecerli. */ ?>
    <script>(function(){try{var t=localStorage.getItem('valentra-tema');if(t==='koyu'||t==='acik'){document.documentElement.setAttribute('data-tema',t);}}catch(e){}})();</script>
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
    <?php
    /*
     * UST BOLUM (site sahibinin istegi, 24.10.2026: "menuleri daha kolay
     * ve akici yapalim; Milliyet'e benzesin ama tam benzemesin; beyaz ve
     * kirmizi tonlar, agirlikli beyaz; bir yere hava durumunu koyalim").
     *
     *   1. Bilgi cubugu: tarih, gece/gunduz. (Hava durumu 24.10.2026'da
     *      yan penceredeki karta tasindi, bkz. includes/hava_kutu.php.)
     *   2. Logo satiri: menu dugmesi (acilir cekmece), logo, arama; beyaz.
     *   3. Menu: beyaz zemin, koyu kalin yazi, aktif ogede kirmizi cizgi;
     *      sayfadaki tek yapiskan serit.
     */
    ?>
    <?php
    /*
     * KAYAN SON DAKIKA SERIDI (site sahibinin istegi, 24.10.2026: "en
     * ustte de kayan son dakika haberleri gecsin"). Kaynakta bugun
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

    <div class="bilgi-cubugu">
        <div class="sinirli bilgi-satir">
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

    <header class="ust-bant">
        <div class="sinirli ust-satir">
            <button type="button" class="cekmece-dugme" id="cekmece-dugme"
                    aria-controls="cekmece" aria-expanded="false" aria-label="Menüyü aç">
                <span></span><span></span><span></span>
            </button>

            <a class="logo" href="/">
                <img class="marka" src="/assets/logo.svg" alt="" width="40" height="35">
                <span class="yazi">
                    <span class="ad">VALENTRA</span>
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
                       href="<?= e($oge['href']) ?>"><?= e($oge['ad']) ?></a>
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
