<?php
declare(strict_types=1);

/**
 * Haber detay sayfasi. Sadece yayinda olan haberler acilir;
 * taslak veya reddedilmis kayitlar 404 doner.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim((string) ($_GET['h'] ?? ''));
$haber = $slug !== '' ? haber_yayinda_bul($slug) : null;

if ($haber === null) {
    http_response_code(404);
    $sayfaBasligi = 'Haber bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Haber bulunamadı.</strong>
        Aradığınız haber yayından kaldırılmış veya adres hatalı olabilir.
        <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$sayfaBasligi  = $haber['baslik'] . ' — Valentra';
$sayfaAciklama = $haber['ozet'];
$aktifKategori = (string) ($haber['kategori_slug'] ?? '');

// Panelde "hangi haberler okundu" listesini besler; sayfa_ust.php
// bu degiskeni gorurse ziyareti habere bagliyor.
$ziyaretHaberId = (int) $haber['id'];

// Arama motoruna bunun bir haber oldugunu acikca soyle; yayim tarihi
// ve gorsel de semaya giriyor.
require_once __DIR__ . '/includes/seo.php';

$seoTur  = 'article';
$seoSema = seo_haber_semasi($haber);

$haberGorseli = guvenli_url((string) ($haber['gorsel_url'] ?? ''));

if ($haberGorseli !== '') {
    $seoGorsel = $haberGorseli;
}

/*
 * Okuma sayfasi: kap daraliyor.
 *
 * Kap 1880'e cikinca haber govdesindeki duz paragraf satiri 1550
 * pikseli, yani ~190 karakteri buluyordu; goz bu uzunlukta satir
 * basini kaybediyor. "okuma" sinifi kapi 1320'ye indiriyor, yan
 * pencere de sag tarafi bos birakmadan dolduruyor.
 */
$govdeSinifi = 'okuma';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="ana-duzen">
<div class="ana-kolon">

<article class="detay">
    <?php if (!empty($haber['kategori_slug'])): ?>
        <a class="etiket" href="<?= e(kategori_yolu((string) $haber['kategori_slug'])) ?>">
            <?= e($haber['kategori_adi']) ?>
        </a>
    <?php else: ?>
        <?php $etiketler = etiketleri_coz($haber['etiketler']); ?>
        <?php if ($etiketler !== []): ?>
            <span class="etiket">#<?= e($etiketler[0]) ?></span>
        <?php endif; ?>
    <?php endif; ?>

    <h1><?= e($haber['baslik']) ?></h1>

    <?php
    /*
     * KUNYE: hazirlayan, yayin tarihi, guncelleme tarihi, kaynak.
     *
     * Google'in icerik rehberi yazar bilgisini ve acik kaynaklandirmayi
     * guven olcutu sayiyor; ikisi de burada.
     *
     * GUNCELLEME TARIHI yalnizca GERCEKTEN degismisse basiliyor.
     * guncellendi sutunu ON UPDATE CURRENT_TIMESTAMP tasidigi icin
     * her kayit dokunusunda degisiyor — onay isleminin kendisi bile
     * onu ileri atiyor. Her haberde "guncellendi" yazmak, okuyucuya
     * yapilmamis bir revizyonu bildirmek olurdu. Bir dakikalik pay,
     * ekleme ve onaylama arasindaki farki eliyor.
     */
    $yayin  = strtotime((string) $haber['yayin_tarihi']);
    $guncel = strtotime((string) ($haber['guncellendi'] ?? ''));
    $revize = $guncel && $yayin && ($guncel - $yayin) > 60;
    ?>
    <div class="kunye">
        <span class="hazirlayan">Valentra Yayın Kurulu</span>
        <span>&middot; <?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></span>

        <?php if ($revize): ?>
            <span>&middot; Güncelleme: <?= e(tarih_bicimle((string) $haber['guncellendi'])) ?></span>
        <?php endif; ?>

        <?php if ($haber['kaynak_adi'] !== ''): ?>
            <span>&middot; Kaynak: <?= e($haber['kaynak_adi']) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($haber['gorsel_url'])): ?>
        <img src="<?= e(guvenli_url($haber['gorsel_url'])) ?>" alt="" style="border-radius:8px;margin-bottom:22px;">
    <?php endif; ?>

    <?php if ($haber['ozet'] !== ''): ?>
        <p class="spot"><?= e($haber['ozet']) ?></p>
    <?php endif; ?>

    <?php
    /*
     * VALENTRA ANALIZ — haberin "bana ne" karsiligi.
     *
     * Metnin USTUNDE duruyor: okuyucu (mali musavir, muhasebe
     * calisani, isletme yoneticisi) once kendi isini ilgilendiren
     * kismi gormeli, haberin tamamini okumak isteyip istemedigine
     * ondan sonra karar vermeli.
     *
     * BOS ALAN BASILMIYOR ve blok hic doldurulmamissa hic
     * gorunmuyor. Dort basligi bos kutularla gostermek, bilgi varmis
     * izlenimi verip vermemek olurdu.
     */
    $analiz = array_filter([
        'Ne değişti?'            => trim((string) ($haber['analiz_degisen'] ?? '')),
        'Kimleri etkiliyor?'     => trim((string) ($haber['analiz_etkilenen'] ?? '')),
        'Ne zaman uygulanacak?'  => trim((string) ($haber['analiz_zaman'] ?? '')),
        'Hangi işlem yapılmalı?' => trim((string) ($haber['analiz_islem'] ?? '')),
    ], static fn (string $d): bool => $d !== '');
    ?>

    <?php if ($analiz !== []): ?>
        <section class="analiz" aria-label="Valentra Analiz">
            <h2 class="analiz-baslik">Valentra Analiz</h2>

            <dl class="analiz-liste">
                <?php foreach ($analiz as $soru => $cevap): ?>
                    <div class="analiz-oge">
                        <dt><?= e($soru) ?></dt>
                        <dd><?= e($cevap) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endif; ?>

    <div class="icerik">
        <?php foreach (preg_split('/\n\s*\n/u', trim($haber['icerik'])) ?: [] as $paragraf): ?>
            <p><?= nl2br(e(trim($paragraf))) ?></p>
        <?php endforeach; ?>
    </div>

    <?php $iframeUrl = guvenli_url((string) ($haber['iframe_url'] ?? '')); ?>
    <?php if ($iframeUrl !== ''): ?>
        <div class="gomulu-icerik">
            <iframe
                src="<?= e($iframeUrl) ?>"
                title="<?= e($haber['baslik']) ?>"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="fullscreen; autoplay; encrypted-media; picture-in-picture"
                allowfullscreen>
            </iframe>
        </div>
    <?php endif; ?>

    <?php if ($haber['kaynak_url'] !== ''): ?>
        <div class="kaynak-kutusu">
            Bu haber,
            <?= $haber['kaynak_adi'] !== ''
                    ? '<strong>' . e($haber['kaynak_adi']) . '</strong> kaynağında'
                    : 'kaynağında' ?>
            yayımlanan içerikten derlenmiştir.
            <a href="<?= e(guvenli_url($haber['kaynak_url'])) ?>" target="_blank" rel="noopener nofollow ugc">Orijinal habere git</a>
        </div>
    <?php endif; ?>

    <?php if ($etiketler !== []): ?>
        <div class="kart-alt" style="margin-top:20px;">
            <?php foreach ($etiketler as $etiket): ?>
                <span class="etiket">#<?= e($etiket) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>

</div>

<?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
