<?php
declare(strict_types=1);

/**
 * Haberin gövdesi (<article>): haber sayfası ve kaydırınca gelen sıradaki
 * haber (haber.php?sonraki=1) ayni sablonu kullaniyor (28.10.2026).
 *
 * Bekledigi: $haber (haber_yayinda_bul satiri). $govdeVeri varsa
 * <article>'a data- nitelikleri olarak yaziliyor (sonsuz kaydirma
 * betigi okuyor).
 */

require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/kurumsal.php';
require_once __DIR__ . '/etiket.php';

$nedenOnemli  = trim((string) ($haber['neden_onemli'] ?? ''));
$haberGorseli = haber_gorsel($haber);
$govdeNitelik = '';

foreach (($govdeVeri ?? []) as $ad => $deger) {
    $govdeNitelik .= ' data-' . $ad . '="' . e((string) $deger) . '"';
}
?>
<article class="detay<?= haber_derleme_mi($haber) ? ' detay-derleme' : '' ?>"<?= $govdeNitelik ?>>
    <?php $etiketler = etiketleri_coz($haber['etiketler']); ?>
    <?php if (!empty($haber['kategori_slug'])): ?>
        <a class="etiket" href="<?= e(kategori_yolu((string) $haber['kategori_slug'])) ?>">
            <?= e($haber['kategori_adi']) ?>
        </a>
    <?php else: ?>
        <?php if ($etiketler !== []): ?>
            <a class="etiket" href="<?= e(etiket_yolu($etiketler[0])) ?>">#<?= e($etiketler[0]) ?></a>
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
        <?php if (haber_derleme_mi($haber) || str_starts_with((string) ($haber['ajan_modeli'] ?? ''), 'valentra-')): ?>
            <?php /* Derleme ve ozgun haber (Valentra Veri / Dosya): ara basliklar ("## ") ve madde listeleri. */ ?>
            <?= bicimli_metin_html((string) $haber['icerik']) ?>
        <?php else: ?>
            <?php foreach (preg_split('/\n\s*\n/u', trim($haber['icerik'])) ?: [] as $paragraf): ?>
                <p><?= nl2br(e(trim($paragraf))) ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
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
     * ozet, haberin tamami kaynakta. Kaynak, metnin altinda kucuk bir
     * satirda ve asil habere bagli (asagida KAYNAK SATIRI): ozet kaynagin
     * yerine gecmiyor, ona yonlendiriyor.
     */
    $kaynakUrl    = guvenli_url((string) $haber['kaynak_url']);
    $kaynakAdi    = $haber['kaynak_adi'] !== '' ? kaynak_gorunen_ad((string) $haber['kaynak_adi']) : 'kaynak';

    /*
     * Derleme haber (28.10.2026): tek kaynak yerine yararlanilan butun
     * kaynaklar, her biri kendi haberine bagli. Hem okuyucu icin acik
     * kaynaklandirma hem telif: derleme kaynaklarin yerine gecmiyor.
     */
    require_once __DIR__ . '/derleme.php';
    $derlemeKaynaklari = haber_derleme_mi($haber) ? derleme_kaynaklari_coz((string) ($haber['derleme_kaynaklari'] ?? '')) : [];

    // Ozgun haber (28.10.2026): Valentra Veri (includes/ozgun_haber.php,
    // sablonla veriden) ve Valentra Dosya aciklayici yazilari
    // (includes/dosya.php). Baska sitenin haberinin ozeti degil.
    $ozgunVeri  = str_starts_with((string) ($haber['ajan_modeli'] ?? ''), 'valentra-');
    $ozgunDosya = str_starts_with((string) ($haber['ajan_modeli'] ?? ''), 'valentra-dosya');
    ?>
    <?php
    /*
     * KAYNAK SATIRI (site sahibinin istegi, 29.10.2026: "kaynak gosterme,
     * sadece haberin altinda cok kucuk yazalim"). Onceden metnin altinda
     * buyuk bir "Haberin kaynagi" kutusu ve kunyede "Kaynak:" vardi; ana
     * sayfa kartlarinda da kaynak adi yaziyordu. Artik yalnizca metnin
     * altinda tek, kucuk bir satir: kaynak adi (kaynaga bagli) ya da
     * derlemede kaynaklarin adlari. Bag kalsin: ozet kaynagin yerine
     * gecmiyor, okur isterse asil habere gidebiliyor.
     */
    if ($derlemeKaynaklari !== []) {
        $kaynakSatiri = [];

        foreach ($derlemeKaynaklari as $dk) {
            $dkUrl = guvenli_url((string) $dk['url']);
            $ad    = e(kaynak_gorunen_ad((string) $dk['ad']));
            $kaynakSatiri[$ad] = $dkUrl !== ''
                ? '<a href="' . e($dkUrl) . '" target="_blank" rel="noopener nofollow ugc">' . $ad . '</a>'
                : $ad;
        }

        $kaynakEtiketi = 'Kaynaklar';
        $kaynakSatiri  = implode(', ', $kaynakSatiri);
    } elseif ($ozgunVeri) {
        $kaynakEtiketi = 'Kaynak';
        $kaynakSatiri  = $ozgunDosya ? 'Valentra Dosya' : 'Valentra Veri';
    } elseif ($haber['kaynak_adi'] !== '') {
        $kaynakEtiketi = 'Kaynak';
        $kaynakSatiri  = $kaynakUrl !== ''
            ? '<a href="' . e($kaynakUrl) . '" target="_blank" rel="noopener nofollow ugc">' . e($kaynakAdi) . '</a>'
            : e($kaynakAdi);
    } else {
        $kaynakSatiri = '';
    }
    ?>
    <?php /* Etiketler etiket sayfasina bagli (28.10.2026; bkz. includes/etiket.php). */ ?>
    <?php if ($etiketler !== []): ?>
        <div class="kart-alt haber-etiketleri" style="margin-top:20px;">
            <?php foreach ($etiketler as $etiket): ?>
                <a class="etiket" href="<?= e(etiket_yolu($etiket)) ?>">#<?= e($etiket) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php /* Kaynak haberin EN ALTINDA, kucuk (site sahibinin istegi, 29.10.2026). */ ?>
    <?php if ($kaynakSatiri !== ''): ?>
        <p class="kaynak-satiri"><?= $kaynakEtiketi ?>: <?= $kaynakSatiri ?></p>
    <?php endif; ?>

</article>
