<?php
declare(strict_types=1);

/** Konu grubu sayfasi. */

require_once __DIR__ . '/includes/bootstrap.php';

$slug     = trim((string) ($_GET['k'] ?? ''));
$kategori = $slug !== '' ? kategori_slug_bul($slug) : null;

if ($kategori === null) {
    http_response_code(404);
    $sayfaBasligi = 'Konu bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Konu grubu bulunamadı.</strong>
        <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

/*
 * Eski vergi ve muhasebe kategorileri -> Ekonomi (22.10.2026).
 *
 * Bu kategorilerin haberlerinin hepsi Ekonomi basligina tasindi (bkz.
 * sql/schema.sql, "Genel habere donus"). Sayfalari bos kalacagi icin
 * eski adresler (arama motorunda, yer imlerinde) kalici olarak
 * Ekonomi'ye yonleniyor. Panelden bu kategorilere yeniden haber
 * atanirsa yonlendirme kendiliginden duruyor.
 */
if (!in_array((string) $kategori['slug'], HABER_AKIS_KATEGORILERI, true)
    && haber_kategoride_sayi((int) $kategori['id']) === 0) {
    header('Location: ' . kategori_yolu('ekonomi'), true, 301);
    exit;
}

/*
 * ValPapel, ValSpor, ValMagazin (29.10.2026, site sahibinin istegi; bkz.
 * includes/gazeteler.php): ekonomi, spor, kultur-sanat, magazin ve yemek
 * tarifleri haberleri gazetelerin sayfalarinda. Eski adres kalici olarak
 * oraya yonleniyor. Spor'un puan durumu, fikstur, kulup ve mac bolumleri
 * (?bolum=) burada kaliyor, ValSpor'un kunyesiyle.
 */
if (isset(KATEGORI_GAZETESI[(string) $kategori['slug']])
    && !($kategori['slug'] === 'spor' && (string) ($_GET['bolum'] ?? '') !== '')) {
    header('Location: ' . kategori_yolu((string) $kategori['slug'], max(1, (int) ($_GET['sayfa'] ?? 1))), true, 301);
    exit;
}

/*
 * Konu sayfasinin basinda ana sayfadaki gibi kayan manset (site
 * sahibinin istegi, 23.10.2026: "spor haberlerine tikladim; ana sayfadaki
 * gibi 10 haberlik bir slayt kaydirici gelsin, yalnizca spor haberlerini
 * gostersin; ekonomide de yalnizca ekonomi haberleri"). Yalnizca ilk
 * sayfada. Ana sayfadaki gibi kaydirak tukettigi haberleri izgaradan
 * dusuyor: izgara 11. haberden basliyor, sonraki sayfalar da bu 10'u
 * atliyor. Ikisi ayni sirayi kullaniyor (haber_kategoride).
 */
$mansetAdet = 10;
$sayfa      = max(1, (int) ($_GET['sayfa'] ?? 1));
$adet       = 12;
$toplam     = haber_kategoride_sayi((int) $kategori['id']);
$tuketilen  = min($mansetAdet, $toplam);
$sonSayfa   = max(1, (int) ceil(max(0, $toplam - $tuketilen) / $adet));
$mansetler  = $sayfa === 1 ? haber_kategoride((int) $kategori['id'], $mansetAdet, 0) : [];
$haberler   = haber_kategoride((int) $kategori['id'], $adet, $tuketilen + ($sayfa - 1) * $adet);

$aktifKategori = $kategori['slug'];
require_once __DIR__ . '/includes/seo.php';
$seoAdres      = site_adresi() . kategori_yolu((string) $kategori['slug'], $sayfa);

// Liste sayfalarinin 2., 3. ... sayfalari Google'a sunulmuyor (26.10.2026):
// icerikleri haberlerin kendisinde; 'follow' ile baglantilar yine taraniyor.
if ($sayfa > 1) {
    $seoRobots = 'noindex, follow';
}
$sayfaBasligi  = $kategori['ad'] . ' — Valentra';
$sayfaAciklama = $kategori['aciklama'] !== ''
    ? $kategori['aciklama']
    : $kategori['ad'] . ' konusundaki güncel haberler.';

/*
 * Spor: puan durumu, fikstur, gol kralligi, kulup ve mac sayfalari ve
 * spor haberleri AYRI sayfalar (23.10.2026, site sahibinin istegi; bkz.
 * includes/spor_panel.php). ?bolum= yoksa yalnizca haberler. Bilinmeyen
 * bolum adi haber sayfasina duser.
 */
$sporBolum = '';
$sporP     = [];

if ($kategori['slug'] === 'spor') {
    require_once __DIR__ . '/includes/spor_panel.php';

    $istenen = (string) ($_GET['bolum'] ?? '');
    $sporP   = spor_ulke_ligine_uyarla(spor_parametreleri($_GET));

    if (isset(SPOR_BOLUMLERI[$istenen]) || isset(SPOR_ALT_BOLUMLER[$istenen])) {
        $sporBolum     = $istenen;
        $aktifKategori = match ($sporBolum) {
            'puan-durumu'  => 'spor-puan',
            'fikstur'      => 'spor-fikstur',
            'gol-kralligi' => 'spor-gol',
            'asist-kralligi' => 'spor-asist',
            default        => 'spor',
        };

        $ligAdi = futbol_api_acik()
            ? (futbol_lig_katalog_adi($sporP['lig']) ?: (string) (futbol_lig($sporP['lig'])['ad'] ?? ''))
            : 'Süper Lig';
        $baslik = SPOR_BOLUMLERI[$sporBolum] ?? SPOR_ALT_BOLUMLER[$sporBolum];

        // Bolum sayfasinin kanonigi kendisi (28.10.2026 Google denetimi):
        // Spor ana sayfasini gosterdigi icin site haritasindaki puan
        // durumu, fikstur ve gol kralligi Google'da "kopya" sayiliyordu.
        // Hafta/gun gezintisi ayri sayfa sayilmiyor.
        $seoAdres = site_adresi() . spor_bolum_yolu($sporBolum, [
            'lig'   => $sporP['lig'] !== FUTBOL_VARSAYILAN_LIG ? $sporP['lig'] : '',
            'takim' => $sporP['takim'],
            'mac'   => $sporP['mac'],
        ]);

        $sayfaBasligi  = trim($ligAdi . ' ' . $baslik) . ' — Valentra';
        $sayfaAciklama = match ($sporBolum) {
            'puan-durumu'  => $ligAdi . ' güncel puan durumu.',
            'fikstur'      => $ligAdi . ' fikstürü, maç sonuçları ve canlı skorlar.',
            'gol-kralligi' => $ligAdi . ' gol krallığı.',
            'asist-kralligi' => $ligAdi . ' asist krallığı.',
            'kulup'        => 'Kulübün sezon analizi, son maçları ve golleri.',
            default        => 'Maçın golleri, kartları, istatistikleri ve kadroları.',
        };
    } else {
        $aktifKategori = 'spor-haberler';
    }
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<?php if ($kategori['slug'] === 'spor'): ?>
    <?php require_once __DIR__ . '/includes/gazeteler.php'; ?>
    <?= gazete_ust_html('valspor', isset(SPOR_BOLUMLERI[$sporBolum]) ? $sporBolum : '') ?>
<?php else: ?>
<div class="kategori-basligi">
    <h1><?= e($kategori['ad']) ?></h1>
    <?php if ($kategori['aciklama'] !== ''): ?>
        <p><?= e($kategori['aciklama']) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($kategori['slug'] === 'spor'): ?>
    <?php if (isset(SPOR_BOLUMLERI[$sporBolum])): ?>
        <?= spor_secici_html($sporBolum, $sporP) ?>
    <?php endif; ?>
<?php endif; ?>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php if ($sporBolum === '' && $mansetler !== []): ?>
            <?php require __DIR__ . '/includes/kaydirak.php'; ?>
        <?php endif; ?>

        <?php if ($kategori['slug'] === 'spor' && $sporBolum === '' && $sayfa === 1): ?>
            <?php require __DIR__ . '/includes/spor_yazarlari.php'; ?>
        <?php endif; ?>

        <?php
        /*
         * Canli bolumler (22.10.2026, site sahibinin istegi): Spor'un
         * puan durumu ve fikstur sayfalari (TFF), Ekonomi'de piyasa
         * kartlari, grafikleri ve borsa sirketleri. Ekonomi'de yalnizca
         * ilk sayfada; veri yoksa bolum hic basilmiyor.
         */
        $canliPanel = '';

        if ($sporBolum !== '') {
            $canliPanel = spor_paneli_html($sporBolum, $sporP);
        }

        /*
         * Ekonomi: EN ONDE guncel haberler (kaydirak + izgara), piyasa
         * verisi haberlerin ALTINDA "Ekonomik Gundem" kutusunda, BIST 100,
         * doviz, altin, petrol ve gostergelerin ayri sayfalarina
         * baglantilarla (site sahibinin istegi, 23.10.2026). Onceden BIST
         * grafigi ve sirket secici haberlerin ustundeydi.
         */

        if ($canliPanel !== '') {
            require_once __DIR__ . '/includes/canli_betik.php';
            echo $canliPanel, canli_betik();
        }
        ?>

        <?php if ($sporBolum !== '' && $canliPanel === ''): ?>
            <div class="bos-durum">
                <?php if (!futbol_api_acik() && !in_array($sporBolum, ['puan-durumu', 'fikstur'], true)): ?>
                    <strong>Bu bölüm şu an kapalı.</strong>
                    Dünya ligleri, kulüp ve maç ayrıntıları spor veri kaynağı açılınca görünecek.
                <?php else: ?>
                    <strong>Veri şu an alınamıyor.</strong>
                    Birkaç dakika sonra sayfayı yenileyin.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($sporBolum === ''): ?>
        <div class="bolum-basligi" id="<?= e((string) $kategori['slug']) ?>-haberleri">
            <?php
            /*
             * Ilk sayfada kaydirak ilk 10 haberi tuketiyor ve izgara
             * kalanlari gosteriyor; "13 Haber" yazip 3 kart gostermek
             * eksik haber izlenimi veriyordu (site sahibi, 23.10.2026).
             */
            ?>
            <h2><?= $canliPanel !== '' || in_array($kategori['slug'], ['spor', 'ekonomi'], true)
                ? 'Güncel ' . e((string) $kategori['ad']) . ' Haberleri'
                : ($mansetler !== [] ? 'Diğer Haberler' : $toplam . ' Haber') ?></h2>
            <span class="cizgi"></span>
        </div>

        <?php if ($haberler === [] && $mansetler === []): ?>
            <div class="bos-durum">
                <strong>Bu grupta henüz haber yok.</strong>
                Bu konuda gelişme olduğunda haberler burada listelenecek.
            </div>
        <?php else: ?>
            <?php $izgaraHaberleri = $haberler; ?>
            <?php require __DIR__ . '/includes/kart_izgara.php'; ?>

            <?php if ($kategori['slug'] === 'ekonomi' && $sayfa === 1): ?>
                <?php require_once __DIR__ . '/includes/piyasa_panel.php'; ?>
                <?= ekonomik_gundem_html() ?>
            <?php endif; ?>

            <?php if ($sonSayfa > 1): ?>
                <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;">
                    <?php if ($sayfa > 1): ?>
                        <a href="<?= e(kategori_yolu((string) $kategori['slug'], $sayfa - 1)) ?>">&larr; Önceki</a>
                    <?php endif; ?>
                    <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
                    <?php if ($sayfa < $sonSayfa): ?>
                        <a href="<?= e(kategori_yolu((string) $kategori['slug'], $sayfa + 1)) ?>">Sonraki &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
