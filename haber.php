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

// Haberin tek adresi: haber.php?h=... ya da sonda / ile gelse de ayni.
$seoAdres = site_adresi() . haber_yolu((string) $haber['slug']);

/*
 * Cok kisa haber Google'a sunulmuyor (bkz. haber_ince_mi): okuyucu icin
 * sayfa acik, "follow" sayesinde icindeki baglantilar yine taraniyor.
 */
if (haber_ince_mi($haber)) {
    $seoRobots = 'noindex, follow';
}

require_once __DIR__ . '/includes/kurumsal.php';

$nedenOnemli = trim((string) ($haber['neden_onemli'] ?? ''));
$ilgililer   = haber_ilgililer($haber, 4);

// Gorsel sitenin kendi sunucusundan (bkz. includes/gorsel_onbellek.php).
$haberGorseli = haber_gorsel($haber);

if ($haberGorseli !== '') {
    $seoGorsel = site_adresi() . $haberGorseli;
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
    <?php $etiketler = etiketleri_coz($haber['etiketler']); ?>
    <?php if (!empty($haber['kategori_slug'])): ?>
        <a class="etiket" href="<?= e(kategori_yolu((string) $haber['kategori_slug'])) ?>">
            <?= e($haber['kategori_adi']) ?>
        </a>
    <?php else: ?>
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
        <?php /* Imza yayin ilkelerine bagli: kimin, hangi kurallarla hazirladigi tek tikla. */ ?>
        <a class="hazirlayan" href="<?= e(kurumsal_yolu('yayin-ilkeleri')) ?>">Valentra Yayın Kurulu</a>
        <span>&middot; <?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></span>

        <?php if ($revize): ?>
            <span>&middot; Güncelleme: <?= e(tarih_bicimle((string) $haber['guncellendi'])) ?></span>
        <?php endif; ?>

        <?php if ($haber['kaynak_adi'] !== ''): ?>
            <?php $kunyeKaynakUrl = guvenli_url((string) $haber['kaynak_url']); ?>
            <span>&middot; Kaynak:
                <?php if ($kunyeKaynakUrl !== ''): ?>
                    <a href="<?= e($kunyeKaynakUrl) ?>" target="_blank" rel="noopener nofollow ugc"><?= e(kaynak_gorunen_ad((string) $haber['kaynak_adi'])) ?></a>
                <?php else: ?>
                    <?= e(kaynak_gorunen_ad((string) $haber['kaynak_adi'])) ?>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($haberGorseli !== ''): ?>
        <img src="<?= e($haberGorseli) ?>" alt="" referrerpolicy="no-referrer"
             onerror="this.remove()" style="border-radius:8px;margin-bottom:22px;">
    <?php endif; ?>

    <?php if ($haber['ozet'] !== ''): ?>
        <p class="spot"><?= e($haber['ozet']) ?></p>
    <?php endif; ?>

    <?php
    /*
     * NEDEN ONEMLI? — haberin kaynakta olmayan acisi (26.10.2026).
     *
     * Haber metni kaynagin ozeti; bu bolum Valentra'nin kendi katkisi:
     * gelismenin okur icin anlami. Google'in bir ozeti dizine almasinin
     * en guclu gerekcesi, aslinda olmayan bir sey sunmasi. Ajan
     * yaziyor, editor onayda duzeltiyor; bossa hic gosterilmiyor.
     */
    ?>
    <?php if ($nedenOnemli !== ''): ?>
        <section class="neden-onemli" aria-label="Neden önemli">
            <h2>Neden önemli?</h2>
            <?= bicimli_metin_html($nedenOnemli) ?>
        </section>
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
        'Kimleri ilgilendiriyor?' => trim((string) ($haber['analiz_etkilenen'] ?? '')),
        'Ne değişti?'             => trim((string) ($haber['analiz_degisen'] ?? '')),
        'İşletmeye etkisi'        => trim((string) ($haber['isletme_etkisi'] ?? '')),
        'Ne zaman uygulanacak?'   => trim((string) ($haber['analiz_zaman'] ?? '')),
        'Ne yapılmalı?'           => trim((string) ($haber['analiz_islem'] ?? '')),
    ], static fn (string $d): bool => $d !== '');

    $uygulamaOrnegi = trim((string) ($haber['uygulama_ornegi'] ?? ''));
    ?>

    <?php if ($analiz !== []): ?>
        <section class="analiz" aria-label="Valentra Analiz">
            <h2 class="analiz-baslik">Valentra Analiz</h2>

            <dl class="analiz-liste">
                <?php foreach ($analiz as $soru => $cevap): ?>
                    <div class="analiz-oge">
                        <dt><?= e($soru) ?></dt>
                        <?php /* "Ne yapilmali?" kontrol listesi olabilir ("- " satirlari). */ ?>
                        <dd><?= bicimli_metin_html($cevap) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endif; ?>

    <?php
    /*
     * UYGULAMA ORNEGI — hesaplama ya da muhasebe kaydi.
     *
     * Oran ve kural haberin konusu olan duzenlemeden; rakamlar
     * varsayimsal. Okuyucu bunu kendi rakami sanmasin diye not sabit.
     */
    ?>
    <?php if ($uygulamaOrnegi !== ''): ?>
        <section class="uygulama-ornegi" aria-label="Uygulama örneği">
            <h2>Uygulama örneği</h2>
            <?= bicimli_metin_html($uygulamaOrnegi) ?>
            <p class="uygulama-not">
                Rakamlar örnek amaçlı ve varsayımsaldır; oran ve kurallar habere
                konu düzenlemeden alınmıştır.
            </p>
        </section>
    <?php endif; ?>

    <?php
    /*
     * Haberin videosu (ajanin kaynak sayfada buldugu YouTube / Vimeo /
     * Dailymotion oynaticisi, 22.10.2026): metnin USTUNDE, 16:9. Video
     * kaynak platformdan oynuyor; dosya kopyalanmiyor. Panelden elle
     * girilen gomulu belge (video olmayan iframe) eskisi gibi metnin
     * altinda.
     */
    $iframeUrl = guvenli_url((string) ($haber['iframe_url'] ?? ''));
    $videoMu   = $iframeUrl !== '' && haber_video_adresi($iframeUrl) !== '';
    ?>
    <?php if ($videoMu): ?>
        <div class="gomulu-icerik video">
            <iframe
                src="<?= e($iframeUrl) ?>"
                title="<?= e($haber['baslik']) ?> — video"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="fullscreen; encrypted-media; picture-in-picture"
                allowfullscreen>
            </iframe>
        </div>
    <?php endif; ?>

    <div class="icerik">
        <?php
        /*
         * "## " ile baslayan paragraf ara baslik, butun satirlari "- " ile
         * baslayan paragraf madde listesi (28.10.2026; ozgun veri
         * haberleri bu bicimde yaziliyor, bkz. includes/ozgun_haber.php).
         * Diger paragraflar eskisi gibi.
         */
        ?>
        <?php foreach (preg_split('/\n\s*\n/u', trim($haber['icerik'])) ?: [] as $paragraf): ?>
            <?php
            $paragraf = trim($paragraf);
            $satirlar = preg_split('/\n/u', $paragraf) ?: [];
            ?>
            <?php if (str_starts_with($paragraf, '## ') && count($satirlar) === 1): ?>
                <h2><?= e(trim(substr($paragraf, 3))) ?></h2>
            <?php elseif (array_filter($satirlar, static fn (string $s): bool => !str_starts_with(trim($s), '- ')) === []): ?>
                <ul>
                    <?php foreach ($satirlar as $satir): ?>
                        <li><?= e(trim(substr(trim($satir), 2))) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (str_starts_with($paragraf, '## ')): ?>
                <h2><?= e(trim(substr($satirlar[0], 3))) ?></h2>
                <p><?= nl2br(e(trim(implode("\n", array_slice($satirlar, 1))))) ?></p>
            <?php else: ?>
                <?php
                // Duz metin ardindan madde listesi ("...:\n- a\n- b").
                $ilkMadde = null;
                foreach ($satirlar as $i => $satir) {
                    if (str_starts_with(trim($satir), '- ')) { $ilkMadde = $i; break; }
                }
                $maddeler = $ilkMadde !== null ? array_slice($satirlar, $ilkMadde) : [];
                $listeMi  = $ilkMadde !== null && $ilkMadde > 0
                    && array_filter($maddeler, static fn (string $s): bool => !str_starts_with(trim($s), '- ')) === [];
                ?>
                <?php if ($listeMi): ?>
                    <p><?= nl2br(e(trim(implode("\n", array_slice($satirlar, 0, $ilkMadde))))) ?></p>
                    <ul>
                        <?php foreach ($maddeler as $satir): ?>
                            <li><?= e(trim(substr(trim($satir), 2))) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p><?= nl2br(e($paragraf)) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($iframeUrl !== '' && !$videoMu): ?>
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

    <?php
    /*
     * KAYNAK — haberin nereden geldigi ve tamaminin nerede okunacagi.
     *
     * Ekim 2026'dan beri site genel haber OZETLERI yayimliyor (MSN
     * Haberler gibi): metin Valentra'nin kendi cumleleriyle yazilmis bir
     * ozet, haberin tamami kaynakta. Kaynak adi ve "haberin tamami"
     * baglantisi bu yuzden metnin hemen altinda ve belirgin; okuyucu
     * kaynaga bir tikla gidebilmeli. Telif acisindan da sart: ozet,
     * kaynagin yerine gecmiyor, ona yonlendiriyor.
     *
     * Resmi dayanak ve yururluk alanlari eski vergi haberlerinde dolu;
     * yenilerde bos kaliyor ve gosterilmiyor.
     */
    $kaynakUrl    = guvenli_url((string) $haber['kaynak_url']);
    $kaynakAdi    = $haber['kaynak_adi'] !== '' ? kaynak_gorunen_ad((string) $haber['kaynak_adi']) : 'kaynak';
    $resmiDayanak = trim((string) ($haber['resmi_dayanak'] ?? ''));
    $yururluk     = trim((string) ($haber['analiz_zaman'] ?? ''));

    // Ozgun veri haberi (includes/ozgun_haber.php): baska sitenin haberinin
    // ozeti degil; kaynak kutusu bunu soyluyor, bag sitenin veri sayfasina.
    $ozgunVeri = ($haber['ajan_modeli'] ?? '') === 'valentra-veri';
    ?>
    <?php if ($ozgunVeri): ?>
    <section class="kaynak-tarih" aria-label="Haberin kaynağı">
        <h2>Haberin kaynağı</h2>

        <p class="kaynak-tarih-ad">Valentra Veri</p>

        <?php if ($kaynakUrl !== ''): ?>
            <a class="kaynak-tarih-dugme" href="<?= e((string) preg_replace('/[?&]veri=[^&]*$/', '', $kaynakUrl)) ?>">
                Güncel verileri Valentra'da görün &rarr;
            </a>
        <?php endif; ?>

        <dl>
            <div><dt>Valentra'da yayım</dt><dd><?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></dd></div>
        </dl>

        <p class="kaynak-tarih-not">
            Bu haber, Valentra'nın kendi derlediği veriden otomatik olarak hazırlanmıştır. Rakamlar
            metinde adı geçen kaynakların verisidir; yayım anındaki değerleri gösterir.
        </p>
    </section>
    <?php else: ?>
    <section class="kaynak-tarih" aria-label="Haberin kaynağı">
        <h2>Haberin kaynağı</h2>

        <p class="kaynak-tarih-ad"><?= e($kaynakAdi) ?></p>

        <?php if ($kaynakUrl !== ''): ?>
            <a class="kaynak-tarih-dugme" href="<?= e($kaynakUrl) ?>" target="_blank" rel="noopener nofollow ugc">
                Haberin tamamını <?= e($kaynakAdi) ?> sitesinde okuyun &rarr;
            </a>
        <?php endif; ?>

        <dl>
            <?php if ($resmiDayanak !== ''): ?>
                <div><dt>Resmî dayanak</dt><dd><?= e($resmiDayanak) ?></dd></div>
            <?php endif; ?>
            <?php if ($yururluk !== ''): ?>
                <div><dt>Yürürlük</dt><dd><?= e($yururluk) ?></dd></div>
            <?php endif; ?>
            <div><dt>Valentra'da yayım</dt><dd><?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></dd></div>
        </dl>

        <p class="kaynak-tarih-not">
            Bu metin, <?= e($kaynakAdi) ?> tarafından yayımlanan haberden
            Valentra tarafından hazırlanmış bir özettir. Haberin tamamı ve
            ayrıntılar için kaynağına başvurun.
        </p>
    </section>
    <?php endif; ?>

    <?php if ($etiketler !== []): ?>
        <div class="kart-alt" style="margin-top:20px;">
            <?php foreach ($etiketler as $etiket): ?>
                <span class="etiket">#<?= e($etiket) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php /* Ilgili haberler: okuru sitede tutuyor, Google'in sayfalari bulmasini hizlandiriyor. */ ?>
    <?php if ($ilgililer !== []): ?>
        <section class="ilgili-haberler" aria-label="İlgili haberler">
            <h2>İlgili haberler</h2>
            <ul>
                <?php foreach ($ilgililer as $ilgili): ?>
                    <li>
                        <a href="<?= e(haber_yolu((string) $ilgili['slug'])) ?>"><?= e((string) $ilgili['baslik']) ?></a>
                        <span class="ilgili-tarih"><?= e(tarih_bicimle((string) $ilgili['yayin_tarihi'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</article>

</div>

<?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
