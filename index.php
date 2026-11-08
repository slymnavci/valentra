<?php
declare(strict_types=1);

/**
 * Valentra ana sayfasi.
 * Yalnizca yonetici tarafindan onaylanmis ("yayinda") haberleri gosterir.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

// Ziyaretciye bir dakikalik kopya (bkz. includes/sayfa_onbellek.php).
sayfa_onbellek(['sayfa']);
require_once __DIR__ . '/includes/kose.php';

// #300'un tek seferlik geri almasi (haber_gorsel_ozgunleri_geri_al) burada
// calisiyordu; yapildi. Cagri kaldirildi: yeniden acilan yumusak denetimin
// (yalnizca logolu gorsel degisiyor) yeni degisikliklerini geri almasin.

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
$mansetAdet = ANA_SAYFA_MANSET_ADEDI;   // 20; kaydiraktaki haber sayisi

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
// Taslakta kalan haberler (otomatik yayin acikken; bkz. haber_bugunku_taslaklari_yayinla).
haber_bugunku_taslaklari_yayinla_arada();

// Ajan iki saatte bir: GitHub zamanlamasi guvenilmez, site de tetikliyor
// (bkz. ajan_otomatik_tetikle_arada). Sayfa gittikten sonra calisir.
require_once __DIR__ . '/includes/ajan_tetikle.php';
ajan_otomatik_tetikle_sayfa_sonunda();

// Ajanin siteye gonderemedigi (IHS zaman asimi) haberleri site GitHub'dan
// kendisi aliyor; en fazla yarim saatte bir, sayfa gittikten sonra.
require_once __DIR__ . '/includes/ajan_teslim.php';
ajan_teslim_sayfa_sonunda();

// Esik ustu yeni deprem varsa haber (en fazla uc dakikada bir; bkz. includes/deprem.php).
require_once __DIR__ . '/includes/deprem.php';
deprem_denetle_sayfa_sonunda();

$mansetler = $sayfa === 1 ? haber_manset($mansetAdet, true) : [];
$tuketilen = min($mansetAdet, $toplam);

$haberler = haber_yayindakiler($adet, $tuketilen + ($sayfa - 1) * $adet, true);

$kalan    = max(0, $toplam - $tuketilen);
$sonSayfa = max(1, (int) ceil($kalan / $adet));

$sayfaBasligi = $sayfa > 1
    ? 'Güncel Haberler — Sayfa ' . $sayfa . ' | Valentra'
    : 'Valentra — Güncel Haberler';

// Ana sayfanin 2., 3. ... sayfalari Google'a sunulmuyor (26.10.2026):
// icerikleri haberlerin kendisinde; 'follow' ile baglantilar yine taraniyor.
if ($sayfa > 1) {
    $seoRobots = 'noindex, follow';
}

// Ilk manset gorseli <head>'de onceden isteniyor (bkz. includes/sayfa_ust.php);
// adresler includes/kaydirak.php'deki ilk slaytla ayni.
if (isset($mansetler[0]) && haber_gorsel($mansetler[0]) !== '') {
    $lcpGorsel = [
        'src'    => haber_gorsel_kucuk($mansetler[0], 960),
        'srcset' => haber_gorsel_srcset($mansetler[0], [480, 960, 1440]),
        'sizes'  => '(min-width: 1400px) calc(100vw - 780px), calc(100vw - 48px)',
    ];
}

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
     *   2. sira: piyasa seridi, tam genislikte (buyuk sehirler hava seridi
     *      kaldirildi; site sahibi, 30.10.2026)
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

    ?>
    <?php if ($sayfa === 1): ?>
    <section class="vitrin">
        <div class="vitrin-manset">
            <?php require __DIR__ . '/includes/kaydirak.php'; ?>
        </div>

        <?php
        /* Hava kutusunun yerinde "Bugun bilmeniz gereken 5 gelisme"; hava piyasa
           seridinin altinda ince kayan seritte (site sahibi, 08.11.2026). */
        require_once __DIR__ . '/includes/bes_gelisme.php';
        echo bes_gelisme_html();
        ?>
        <?php require __DIR__ . '/includes/kose_kutu.php'; ?>

        <div class="vitrin-piyasa">
            <?php require __DIR__ . '/includes/piyasa_serit.php'; ?>
            <?php require __DIR__ . '/includes/hava_seridi.php'; ?>
        </div>
    </section>

    <?php
    /* Dosyalar ve hesaplamalar ustte (08.11.2026); alt bantta tekrar edilmiyor. */
    require_once __DIR__ . '/includes/hesap_rehber.php';
    echo hesap_rehber_html();
    $hesapRehberUstte = true;
    ?>

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

            <?php
            /* SENIN ICIN (03.11.2026; bkz. includes/senin_icin.php): sehrim ve
               ilgi alanlarim. Secim tarayicida; haberleri betik getiriyor
               (sayfa onbellekte, herkese ayni). Betik yoksa bolum gizli kalir. */
            ?>
            <?php if ($sayfa === 1): ?>
                <?php require_once __DIR__ . '/includes/senin_icin.php'; ?>
                <section class="senin-icin" id="senin-icin" hidden
                         data-konular="<?= e((string) json_encode(senin_konular(), JSON_UNESCAPED_UNICODE)) ?>"
                         data-sehirler="<?= e((string) json_encode(senin_sehirler(), JSON_UNESCAPED_UNICODE)) ?>">
                    <div class="bolum-basligi">
                        <h2>Senin İçin</h2>
                        <span class="cizgi"></span>
                        <button type="button" class="senin-ayarla" data-senin="ayarla">⚙ Ayarla</button>
                    </div>
                    <p class="senin-ozet" data-senin-ozet></p>
                    <div class="senin-ayarlar" data-senin-ayarlar hidden>
                        <label class="senin-sehir">Şehrim
                            <select data-senin-sehir><option value="">Seçmeyin</option></select>
                        </label>
                        <div class="senin-konular" data-senin-konular role="group" aria-label="İlgi alanlarım"></div>
                        <div class="senin-dugmeler">
                            <button type="button" data-senin="kaydet" class="ana">Kaydet</button>
                            <button type="button" data-senin="gizle">Bu bölümü gizle</button>
                        </div>
                    </div>
                    <div class="senin-tanit" data-senin-tanit hidden>
                        <strong>Haberleri size göre ayarlayın</strong>
                        <span>Şehrinizi ve ilgi alanlarınızı seçin; size özel haberler burada en üstte.</span>
                        <button type="button" data-senin="ayarla" class="ana">Şehrimi ve konularımı seç</button>
                        <button type="button" data-senin="gizle" class="senin-kapat" aria-label="Kapat">×</button>
                    </div>
                    <div class="senin-haberler" data-senin-haberler aria-live="polite"></div>
                </section>
                <script src="<?= e(varlik('/assets/senin-icin.js')) ?>" defer></script>
            <?php endif; ?>

            <?php if ($haberler !== []): ?>
                <div class="bolum-basligi">
                    <?php /* "Son Haberler" degil (08.11.2026, okur denetimi): izgara
                       kronolojik degil, mansetle ayni onem sirasinin devami;
                       sabah haberi aksam haberinin onunde gorunebiliyor. */ ?>
                    <h2><?= $sayfa > 1 ? 'Haberler' : 'Diğer Haberler' ?></h2>
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

    <?php /* Video seridi (30.10.2026): en yeni dort videolu haber, tam genislikte. */ ?>
    <?php require __DIR__ . '/includes/video_seridi.php'; ?>

    <?php /* Alt pencereler tam genislikte: konu konu bloklar. */ ?>
    <?php require __DIR__ . '/includes/alt_pencereler.php'; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
