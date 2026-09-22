<?php
declare(strict_types=1);

/**
 * Valentra ana sayfasi.
 * Yalnizca yonetici tarafindan onaylanmis ("yayinda") haberleri gosterir.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$sayfa = max(1, (int) ($_GET['sayfa'] ?? 1));

/*
 * 12, 10 degil: son satirda tek kart kalmasin diye.
 *
 * Izgara genis ekranda dort, ortada uc, darda iki sutun. 10 haberde
 * ucunde de son satir yarim kaliyordu (4+4+2, 3+3+3+1, 2x5). 12 hepsinde
 * tam bolunuyor.
 */
$adet       = 12;   // izgaradaki kart sayisi
/*
 * On slayt cok: kimse onuncu slayta kadar gelmiyor ve numaralar
 * seridi kalabaliklastiriyor. Bes secilmis haber mansetin isini
 * goruyor; gerisi zaten hemen altindaki izgarada.
 */
$mansetAdet = 5;    // kaydiraktaki haber sayisi

$toplam = haber_yayinda_sayisi();

// Kaydirak yalnizca ilk sayfada; tukettigi haberler izgarada tekrarlanmaz.
// Iki sorgu da ayni siralamayi (one_cikan, yayin_tarihi) kullandigi icin
// kaydiragin tukettigi kadar atlamak yeterli.
$mansetler = $sayfa === 1 ? haber_manset($mansetAdet) : [];
$tuketilen = min($mansetAdet, $toplam);

$haberler = haber_yayindakiler($adet, $tuketilen + ($sayfa - 1) * $adet);

$kalan    = max(0, $toplam - $tuketilen);
$sonSayfa = max(1, (int) ceil($kalan / $adet));

$sayfaBasligi = $sayfa > 1
    ? 'Vergi Haberleri — Sayfa ' . $sayfa . ' | Valentra'
    : 'Valentra — Vergi Haberleri';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<?php if ($toplam === 0): ?>
    <div class="bos-durum">
        <strong>Henüz yayımlanmış haber yok.</strong>
        Ajanın derlediği haberler yönetici onayından geçtikten sonra burada görünecek.
    </div>
<?php else: ?>

    <?php /* Ana kolon + yan pencere. Yan pencere mobilde ana kolonun altina duser. */ ?>
    <div class="ana-duzen">
        <div class="ana-kolon">
            <?php require __DIR__ . '/includes/kaydirak.php'; ?>

            <?php /* Piyasa seridi kaydiragin hemen altinda: sayfa acilir
                     acilmaz gorunen, surekli degisen tek veri. */ ?>
            <?php require __DIR__ . '/includes/piyasa_serit.php'; ?>

            <?php if ($haberler !== []): ?>
                <div class="bolum-basligi">
                    <h2><?= $sayfa > 1 ? 'Haberler' : 'Son Haberler' ?></h2>
                    <span class="cizgi"></span>
                </div>

                <?php $izgaraHaberleri = $haberler; ?>
                <?php require __DIR__ . '/includes/kart_izgara.php'; ?>
            <?php endif; ?>

            <?php if ($sonSayfa > 1): ?>
                <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;">
                    <?php if ($sayfa > 1): ?>
                        <a href="/?sayfa=<?= $sayfa - 1 ?>">&larr; Önceki</a>
                    <?php endif; ?>
                    <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
                    <?php if ($sayfa < $sonSayfa): ?>
                        <a href="/?sayfa=<?= $sayfa + 1 ?>">Sonraki &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>

        <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
    </div>

    <?php /* Alt pencereler tam genislikte: konu konu bloklar. */ ?>
    <?php require __DIR__ . '/includes/alt_pencereler.php'; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
