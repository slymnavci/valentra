<?php
declare(strict_types=1);

/**
 * Valentra ana sayfasi.
 * Yalnizca yonetici tarafindan onaylanmis ("yayinda") haberleri gosterir.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kose.php';

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
 * Manset 20 haber — SITE SAHIBININ KARARI, degistirmeyin.
 *
 * Iki kez dusuruldu (tasarim turunda 5'e, Eylul 2026'daki ana sayfa
 * duzeninde 3'e) ve ikisinde de site sahibi 10'a geri aldirdi; 22.10.2026
 * site sahibinin istegiyle 20'ye cikti. "Bugun bilmeniz gerekenler"
 * kutusu mansetle birlikte duruyor; manset sayisini azaltmayi
 * gerektirmiyor. Kaydirak tukettigi haberleri izgaradan dusuyor, yani
 * ilk sayfadaki izgara 21. haberden basliyor. Secici dugmeler tek satir;
 * telefonda numarasiz noktalar (assets/style.css, .kaydirak-sayfalar).
 */
$mansetAdet = ANA_SAYFA_MANSET_ADEDI;   // 20; kaydiraktaki haber sayisi (spor kotasi da buna gore)

/*
 * Resmi Gazete kaynakli haberler ana sayfa akisinda yok; kendi
 * sayfalarinda (resmi-gazete.php). Eski vergi haberleri akista kaliyor
 * (bkz. haber_ana_akis_kosulu). Sayi, manset ve izgara ayni kosulu
 * kullaniyor ki sayfalama tutarli kalsin.
 */
$toplam = haber_yayinda_sayisi(true);

// Kaydirak yalnizca ilk sayfada; tukettigi haberler izgarada tekrarlanmaz.
// Iki sorgu da ayni siralamayi (one_cikan, yayin_tarihi) kullandigi icin
// kaydiragin tukettigi kadar atlamak yeterli.
// Bugun toplanip taslakta kalan haberler (otomatik yayin acikken).
haber_bugunku_taslaklari_yayinla_arada();

// Ajan iki saatte bir: GitHub zamanlamasi guvenilmez, site de tetikliyor
// (bkz. ajan_otomatik_tetikle_arada). Sayfa gittikten sonra calisir.
require_once __DIR__ . '/includes/ajan_tetikle.php';
ajan_otomatik_tetikle_sayfa_sonunda();

// Ajanin siteye gonderemedigi (IHS zaman asimi) haberleri site GitHub'dan
// kendisi aliyor; en fazla yarim saatte bir, sayfa gittikten sonra.
require_once __DIR__ . '/includes/ajan_teslim.php';
ajan_teslim_sayfa_sonunda();

$mansetler = $sayfa === 1 ? haber_manset($mansetAdet, true) : [];
$tuketilen = min($mansetAdet, $toplam);

$haberler = haber_yayindakiler($adet, $tuketilen + ($sayfa - 1) * $adet, true);

$kalan    = max(0, $toplam - $tuketilen);
$sonSayfa = max(1, (int) ceil($kalan / $adet));

$sayfaBasligi = $sayfa > 1
    ? 'Güncel Haberler — Sayfa ' . $sayfa . ' | Valentra'
    : 'Valentra — Güncel Haberler';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<?php if ($toplam === 0): ?>
    <div class="bos-durum">
        <?php /* Ana sayfa kaynakta bugun ve dun yayimlanmis haberleri gosteriyor
                 (haber_ana_akis_kosulu); eskiler konu sayfalarinda. */ ?>
        <strong>Henüz haber yok.</strong>
        Yeni haberler gün içinde burada görünecek; önceki haberler konu sayfalarında.
    </div>
<?php else: ?>

    <?php
    /*
     * VITRIN (site sahibinin istegi, 24.10.2026: "yazarlar en sagda;
     * hava durumunun boyu ile slaytlarin boyu, yazarlarin boyu ayni; alti
     * haber olacak; seritte bosluklar istemiyorum, yerlesim cok muntazam
     * olmali" ve "hava durumunda seride paralel buyuk sehir havasi").
     *
     *   1. sira, ayni yukseklikte: kaydirak | hava durumu | Yazarlarimiz
     *   2. sira, ayni yukseklikte: piyasa seridi | buyuk sehirler seridi
     *   Altinda haberler (ana kolon) ve yan pencerenin geri kalani.
     *
     * Dar ekranda kaydirak tam genislikte, hava ve Yazarlarimiz yan
     * yana; telefonda hepsi alt alta (assets/style.css, .vitrin).
     */
    require_once __DIR__ . '/includes/kose.php';

    try {
        $yazarYazilari = $sayfa === 1 ? kose_yazarlarin_son_yazilari() : [];
    } catch (PDOException $e) {
        $yazarYazilari = [];
    }

    $havaSehirListesi = false;
    ?>
    <?php if ($sayfa === 1): ?>
    <section class="vitrin">
        <div class="vitrin-manset">
            <?php require __DIR__ . '/includes/kaydirak.php'; ?>
        </div>

        <?php require __DIR__ . '/includes/hava_kutu.php'; ?>
        <?php require __DIR__ . '/includes/kose_kutu.php'; ?>

        <div class="vitrin-piyasa">
            <?php require __DIR__ . '/includes/piyasa_serit.php'; ?>
        </div>

        <div class="vitrin-sehirler">
            <?php require __DIR__ . '/includes/hava_sehir_seridi.php'; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php /* Ana kolon + yan pencere. Yan pencere mobilde ana kolonun altina duser.
             Vitrin (Yazarlarimiz, hava) yalnizca ilk sayfada. */ ?>
    <div class="ana-duzen anasayfa">
        <div class="ana-kolon">

            <?php
            /*
             * "Valentra Analiz" bolumu kaldirildi (Ekim 2026): vergi
             * haberleri icin "ne degisti / kimleri etkiliyor" ozetiydi;
             * genel haber ozetlerinde yorum katmani yok.
             */
            ?>

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
