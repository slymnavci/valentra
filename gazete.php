<?php
declare(strict_types=1);

/**
 * ValPapel, ValSpor, ValMagazin: gazetenin on sayfasi ya da bir basligi.
 *
 *   gazete.php?g=valpapel              on sayfa: manset, basliklar
 *   gazete.php?g=valpapel&b=doviz      baslik: haberler (+ piyasa tablolari)
 *   gazete.php?g=valpapel&b=haberler   gazetenin butun haberleri
 *
 * Temiz adres: /valpapel, /valpapel/doviz. Tanimlar includes/gazeteler.php.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

// Ziyaretciye bir dakikalik kopya (bkz. includes/sayfa_onbellek.php).
sayfa_onbellek(['g', 'b', 'sayfa']);
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/gazeteler.php';

$gazete = (string) ($_GET['g'] ?? '');
$g      = gazete_bul($gazete);
$bolum  = (string) ($_GET['b'] ?? '');
$b      = $g['bolumler'][$bolum] ?? null;

if ($g === null || ($bolum !== '' && $bolum !== 'haberler' && $b === null)) {
    http_response_code(404);
    $sayfaBasligi = 'Sayfa bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Aradığınız sayfa bulunamadı.</strong>
        <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

// Baglanti basligi (puan durumu, piyasa sayfalari, burclar) kendi sayfasinda.
if ($b !== null && isset($b['yol'])) {
    header('Location: ' . gazete_bolum_yolu($gazete, $bolum), true, 301);
    exit;
}

$onSayfa = $bolum === '';
$sayfa   = $onSayfa ? 1 : max(1, (int) ($_GET['sayfa'] ?? 1));
// Sayfa basligi uzun ad ("Turkiye Ekonomisi"); sekmede kisa ad ("Turkiye").
$bolumAd = $bolum === 'haberler' ? 'Tüm Haberler' : (string) ($b['baslik'] ?? $b['ad'] ?? '');

$aktifKategori = gazete_bolum_anahtari($gazete, $bolum);
$sayfaBasligi  = $onSayfa
    ? $g['ad'] . ' — ' . $g['slogan']
    : $bolumAd . ' Haberleri — ' . $g['ad'];
$sayfaAciklama = $onSayfa || $bolum === 'haberler' ? $g['aciklama'] : (string) $b['aciklama'];
$seoAdres      = site_adresi() . gazete_yolu($gazete, $bolum, $sayfa);

if ($sayfa > 1) {
    $seoRobots = 'noindex, follow';
}

/*
 * On sayfa: alti haberlik manset (gazetenin butun basliklarindan), sonra her haber
 * basligindan dort haber. Ust bloklarda cikan haber alttakilerde tekrar
 * etmiyor: bir doviz haberi Turkiye Ekonomisi'ne de uyabiliyor.
 */
$mansetler = [];
$bloklar   = [];
$haberler  = [];
$toplam    = 0;
$sonSayfa  = 1;

if ($onSayfa) {
    $mansetler  = gazete_haberleri($gazete, '', 6);
    $gosterilen = array_map(static fn (array $h): int => (int) $h['id'], $mansetler);

    // Ust basliklarin bloklari (ValSpor'da futbol, basketbol, voleybol,
    // diger); alt basliklar kendi sporunun sayfasinda.
    foreach ($g['bolumler'] as $anahtar => $bb) {
        if (!isset($bb['kosullar']) || isset($bb['ust'])) {
            continue;
        }

        // Baslik bos kalmasin (site sahibi: "girdigimizde doviz, altin,
        // kripto gibi basliklar olsun"): tekrar engeli bolumu bosaltirsa
        // ustte gosterilmis haberler de aliniyor.
        $blok = gazete_haberleri($gazete, $anahtar, 4, 0, $gosterilen)
            ?: gazete_haberleri($gazete, $anahtar, 4);

        if ($blok === []) {
            continue;
        }

        $bloklar[$anahtar] = $blok;

        foreach ($blok as $h) {
            $gosterilen[] = (int) $h['id'];
        }
    }
} elseif ($bolum === 'haberler' || isset($b['kosullar'])) {
    // Kategori sayfasi gibi: ilk sayfada bes haberlik manset, sonra izgara.
    $mansetAdet = 5;
    $adet       = 12;
    $toplam     = gazete_haber_sayisi($gazete, $bolum);
    $tuketilen  = min($mansetAdet, $toplam);
    $sonSayfa   = max(1, (int) ceil(max(0, $toplam - $tuketilen) / $adet));
    $mansetler  = $sayfa === 1 ? gazete_haberleri($gazete, $bolum, $mansetAdet) : [];
    $haberler   = gazete_haberleri($gazete, $bolum, $adet, $tuketilen + ($sayfa - 1) * $adet);
}

/*
 * Canli veri. ValPapel on sayfasinda piyasa kartlari, piyasa basliklarinda
 * o bolumun tablolari; ValSpor on sayfasinda Super Lig puan durumu;
 * ValMagazin on sayfasinda gunun burclari. Veri alinamazsa bolum hic
 * basilmiyor, haberler yine gorunuyor.
 */
$canli = '';

if ($gazete === 'valpapel' && $sayfa === 1 && ($onSayfa || isset($b['piyasa']))) {
    require_once __DIR__ . '/includes/piyasa_panel.php';

    if ($onSayfa) {
        $canli = piyasa_kartlari_html();
    } else {
        $pb    = piyasa_bolumleri()[$b['piyasa']] ?? null;
        $canli = $pb === null ? '' : ($pb['araclar'] !== [] ? piyasa_kartlari_html($pb['araclar']) : '')
            . piyasa_tablolar_html($pb['tablolar'] ?? []);

        if ($canli !== '') {
            $canli .= '<p class="gazete-devam"><a href="' . e(piyasa_bolum_yolu((string) $b['piyasa'])) . '">'
                    . e($pb['ad']) . ': grafikler ve dönem analizi &rarr;</a></p>';
        }
    }
}

// Basligin istatistikleri (ValSpor'da futbolun puan durumu, gol kralligi).
$veri = $sayfa === 1 && isset($b['veri']) ? gazete_veri_html((string) $b['veri']) : '';

$burclar = [];

if ($gazete === 'valmagazin' && $onSayfa) {
    require_once __DIR__ . '/includes/burclar.php';

    $burcGunu = burc_son_gun();
    $burclar  = $burcGunu !== null ? burc_gun_yorumlari($burcGunu) : [];
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<?= gazete_ust_html($gazete, $bolum) ?>

<div class="ana-duzen gazete-<?= e($gazete) ?>">
    <div class="ana-kolon">
        <?php if (!$onSayfa): ?>
            <div class="kategori-basligi gazete-bolum-basligi">
                <h1><?= e($bolumAd) ?></h1>
                <p><?= e($sayfaAciklama) ?></p>
            </div>
        <?php endif; ?>

        <?php // Istatistikler once (site sahibi: "futbola bastigimizda puan durumu, gol kralligi gelsin"). ?>
        <?php if ($veri !== ''): ?>
            <?php require_once __DIR__ . '/includes/canli_betik.php'; ?>
            <section class="gazete-veri"><?= $veri ?></section>
            <?= canli_betik() ?>
        <?php endif; ?>

        <?php if ($mansetler !== []): ?>
            <?php require __DIR__ . '/includes/kaydirak.php'; ?>
        <?php endif; ?>

        <?php if ($canli !== ''): ?>
            <?php require_once __DIR__ . '/includes/canli_betik.php'; ?>
            <?php if ($onSayfa): ?>
                <div class="bolum-basligi"><h2><a href="<?= e(piyasalar_yolu()) ?>">Piyasalar</a></h2><span class="cizgi"></span></div>
            <?php endif; ?>
            <section class="piyasa-panel gazete-canli"><?= $canli ?></section>
            <?= canli_betik() ?>
        <?php endif; ?>

        <?php if ($burclar !== []): ?>
            <div class="bolum-basligi"><h2><a href="<?= e(burclar_yolu()) ?>">Günlük Burçlar</a></h2><span class="cizgi"></span></div>
            <ul class="gazete-burclar">
                <?php foreach (BURCLAR as $anahtar => [$ad, $sembol]): ?>
                    <li>
                        <a href="<?= e(burc_yolu($anahtar)) ?>">
                            <span class="burc-sembol" aria-hidden="true"><?= $sembol ?></span>
                            <span class="burc-ad"><?= e($ad) ?></span>
                            <?php if (isset($burclar[$anahtar])): ?>
                                <span class="burc-baslik"><?= e((string) $burclar[$anahtar]['baslik']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($onSayfa): ?>
            <?php foreach ($bloklar as $anahtar => $izgaraHaberleri): ?>
                <div class="bolum-basligi" id="<?= e($anahtar) ?>">
                    <h2><a href="<?= e(gazete_yolu($gazete, $anahtar)) ?>"><?= e($g['bolumler'][$anahtar]['baslik'] ?? $g['bolumler'][$anahtar]['ad']) ?></a></h2>
                    <span class="cizgi"></span>
                    <a class="gazete-tumu" href="<?= e(gazete_yolu($gazete, $anahtar)) ?>">Tümü &rarr;</a>
                </div>
                <?php require __DIR__ . '/includes/kart_izgara.php'; ?>
            <?php endforeach; ?>

            <?php if ($mansetler === [] && $bloklar === []): ?>
                <div class="bos-durum">
                    <strong>Henüz haber yok.</strong>
                    Yeni haberler toplandıkça burada yayımlanacak.
                </div>
            <?php else: ?>
                <p class="gazete-devam"><a href="<?= e(gazete_yolu($gazete, 'haberler')) ?>"><?= e($g['ad']) ?>: tüm haberler &rarr;</a></p>
            <?php endif; ?>
        <?php elseif ($bolum === 'haberler' || isset($b['kosullar'])): ?>
            <?php if ($haberler === [] && $mansetler === []): ?>
                <div class="bos-durum">
                    <strong>Bu başlıkta henüz haber yok.</strong>
                    Bu konuda gelişme olduğunda haberler burada listelenecek.
                </div>
            <?php else: ?>
                <?php if ($haberler !== []): ?>
                    <div class="bolum-basligi"><h2><?= $mansetler !== [] ? 'Diğer Haberler' : e($bolumAd) ?></h2><span class="cizgi"></span></div>
                    <?php $izgaraHaberleri = $haberler; ?>
                    <?php require __DIR__ . '/includes/kart_izgara.php'; ?>
                <?php endif; ?>

                <?php if ($sonSayfa > 1): ?>
                    <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;">
                        <?php if ($sayfa > 1): ?>
                            <a href="<?= e(gazete_yolu($gazete, $bolum, $sayfa - 1)) ?>">&larr; Önceki</a>
                        <?php endif; ?>
                        <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
                        <?php if ($sayfa < $sonSayfa): ?>
                            <a href="<?= e(gazete_yolu($gazete, $bolum, $sayfa + 1)) ?>">Sonraki &rarr;</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
