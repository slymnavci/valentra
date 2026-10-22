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

$sayfa    = max(1, (int) ($_GET['sayfa'] ?? 1));
$adet     = 12;
$toplam   = haber_kategoride_sayi((int) $kategori['id']);
$sonSayfa = max(1, (int) ceil($toplam / $adet));
$haberler = haber_kategoride((int) $kategori['id'], $adet, ($sayfa - 1) * $adet);

$aktifKategori = $kategori['slug'];
$sayfaBasligi  = $kategori['ad'] . ' — Valentra';
$sayfaAciklama = $kategori['aciklama'] !== ''
    ? $kategori['aciklama']
    : $kategori['ad'] . ' konusundaki güncel haberler.';

/*
 * Spor: puan durumu, fikstur ve spor haberleri AYRI sayfalar
 * (23.10.2026, site sahibinin istegi). ?bolum= yoksa yalnizca haberler.
 * Bilinmeyen bolum adi haber sayfasina duser.
 */
$sporBolum = '';

if ($kategori['slug'] === 'spor') {
    require_once __DIR__ . '/includes/spor_panel.php';

    $istenen = (string) ($_GET['bolum'] ?? '');

    if (isset(SPOR_BOLUMLERI[$istenen])) {
        $sporBolum     = $istenen;
        $aktifKategori = $sporBolum === 'puan-durumu' ? 'spor-puan' : 'spor-fikstur';
        $sayfaBasligi  = 'Süper Lig ' . SPOR_BOLUMLERI[$sporBolum] . ' — Valentra';
        $sayfaAciklama = $sporBolum === 'puan-durumu'
            ? 'Trendyol Süper Lig güncel puan durumu (TFF).'
            : 'Trendyol Süper Lig fikstürü: haftanın maçları, sonuçlar ve sezonun bütün haftaları (TFF).';
    } else {
        $aktifKategori = 'spor-haberler';
    }
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1><?= e($kategori['ad']) ?></h1>
    <?php if ($kategori['aciklama'] !== ''): ?>
        <p><?= e($kategori['aciklama']) ?></p>
    <?php endif; ?>
</div>

<?php if ($kategori['slug'] === 'spor'): ?>
    <?= spor_sekmeleri_html($sporBolum) ?>
<?php endif; ?>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php
        /*
         * Canli bolumler (22.10.2026, site sahibinin istegi): Spor'un
         * puan durumu ve fikstur sayfalari (TFF), Ekonomi'de piyasa
         * kartlari, grafikleri ve borsa sirketleri. Ekonomi'de yalnizca
         * ilk sayfada; veri yoksa bolum hic basilmiyor.
         */
        $canliPanel = '';

        if ($sporBolum !== '') {
            $canliPanel = spor_paneli_html($sporBolum);
        } elseif ($sayfa === 1 && $kategori['slug'] === 'ekonomi') {
            require_once __DIR__ . '/includes/piyasa_panel.php';
            $canliPanel = piyasa_paneli_html((string) ($_GET['donem'] ?? '3a'));
        }

        if ($canliPanel !== '') {
            require_once __DIR__ . '/includes/canli_betik.php';
            echo $canliPanel, canli_betik();
        }
        ?>

        <?php if ($sporBolum !== '' && $canliPanel === ''): ?>
            <div class="bos-durum">
                <strong><?= e(SPOR_BOLUMLERI[$sporBolum]) ?> şu an alınamıyor.</strong>
                Veri TFF'den çekiliyor; birkaç dakika sonra sayfayı yenileyin.
            </div>
        <?php endif; ?>

        <?php if ($sporBolum === ''): ?>
        <div class="bolum-basligi" id="<?= e((string) $kategori['slug']) ?>-haberleri">
            <h2><?= $canliPanel !== '' || $kategori['slug'] === 'spor' ? 'Güncel ' . e((string) $kategori['ad']) . ' Haberleri' : $toplam . ' Haber' ?></h2>
            <span class="cizgi"></span>
        </div>

        <?php if ($haberler === []): ?>
            <div class="bos-durum">
                <strong>Bu grupta henüz haber yok.</strong>
                Bu konuda gelişme olduğunda haberler burada listelenecek.
            </div>
        <?php else: ?>
            <?php $izgaraHaberleri = $haberler; ?>
            <?php require __DIR__ . '/includes/kart_izgara.php'; ?>

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
