<?php
declare(strict_types=1);

/**
 * Tek bir pratik bilginin kendi sayfası.
 *
 * Neden ayri sayfa: degerlerin bir kismi tek rakam, bir kismi onlarca
 * satirlik tarife (harcirah, gelir vergisi dilimleri). Hepsini tek
 * sayfada yan yana kartlara dokmek uzun olanlari okunmaz, kisa olanlari
 * kaybedilmis hale getiriyordu. Ayrica her degerin kendi adresi olunca
 * paylasilabiliyor ve arama motorunda "kidem tazminati tavani" gibi bir
 * aramaya dogrudan cevap verebiliyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pratik.php';
require_once __DIR__ . '/includes/ekonomi.php';
require_once __DIR__ . '/includes/grafik.php';

$anahtar = trim((string) ($_GET['p'] ?? ''));
$bilgi   = $anahtar !== '' ? pratik_bul($anahtar) : null;

if ($bilgi === null) {
    http_response_code(404);
    $sayfaBasligi  = 'Bilgi bulunamadı — Valentra';
    $aktifKategori = 'pratik';

    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Bu bilgi bulunamadı.</strong>
        <a href="<?= e(pratik_yolu()) ?>">Pratik bilgilere dön</a>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$bolumler = pratik_deger_bolumleri((string) $bilgi['deger']);
$komsular = pratik_grup_komsulari((string) $bilgi['grup'], (string) $bilgi['anahtar']);
$kaynak   = guvenli_url((string) ($bilgi['kaynak_url'] ?? ''));

/*
 * Grafik: yalnizca YAYINDAKI seriden. Aday seri burada hic okunmuyor;
 * onaylanmamis bir nokta ziyaretciye gorunmemeli.
 */
$ekonomiSeri = ekonomi_serisi((string) $bilgi['anahtar']);
$grafikSeri  = pratik_seri_oku($bilgi['seri'] ?? null);

$aktifKategori = 'pratik';
$sayfaBasligi  = $bilgi['baslik'] . ' — Valentra';

/*
 * Aciklama arama sonucunda gorunen metin. Degerin kendisini de iceriyor
 * ki sonucu tiklamadan once cevap gorulsun.
 */
$sayfaAciklama = trim(
    (string) ($bilgi['aciklama'] ?? '') . ' '
    . pratik_ozet((string) $bilgi['deger'])
    . (!empty($bilgi['donem']) ? ' (' . $bilgi['donem'] . ')' : '')
);

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="ana-duzen">
    <div class="ana-kolon">
        <article class="pratik-detay">
            <a class="geri" href="<?= e(pratik_yolu()) ?>">&larr; Pratik Bilgiler</a>

            <h1><?= e((string) $bilgi['baslik']) ?></h1>

            <?php if (!empty($bilgi['aciklama'])): ?>
                <p class="pratik-detay-aciklama"><?= e((string) $bilgi['aciklama']) ?></p>
            <?php endif; ?>

            <div class="pratik-deger-kutu">
                <?php foreach ($bolumler as $bolum): ?>
                    <?php if ($bolum['tur'] === 'baslik'): ?>
                        <h2 class="pratik-deger-baslik"><?= e($bolum['metin']) ?></h2>
                    <?php else: ?>
                        <p class="pratik-deger-satir"><?= e($bolum['metin']) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if (isset($ekonomiSeri['grafik']) && $grafikSeri !== []): ?>
                <?= grafik_ciz($grafikSeri, [
                    'tur'    => (string) $ekonomiSeri['grafik']['tur'],
                    'baslik' => (string) $ekonomiSeri['grafik']['baslik'],
                    'birim'  => (string) $ekonomiSeri['birim'],
                    'kaynak' => (string) ($bilgi['kaynak_adi'] ?? ''),
                    'ad'     => (string) $bilgi['baslik'],
                ]) ?>
            <?php endif; ?>

            <dl class="pratik-kunye">
                <?php if (!empty($bilgi['donem'])): ?>
                    <div>
                        <dt>Geçerlilik</dt>
                        <dd><?= e((string) $bilgi['donem']) ?></dd>
                    </div>
                <?php endif; ?>

                <?php if ($kaynak !== ''): ?>
                    <div>
                        <dt>Kaynak</dt>
                        <dd>
                            <a href="<?= e($kaynak) ?>" target="_blank" rel="noopener">
                                <?= e((string) ($bilgi['kaynak_adi'] ?: $kaynak)) ?> &nearr;
                            </a>
                        </dd>
                    </div>
                <?php endif; ?>

                <?php if (!empty($bilgi['onay_tarihi'])): ?>
                    <div>
                        <dt>Son güncelleme</dt>
                        <dd><?= e(tarih_bicimle((string) $bilgi['onay_tarihi'], false)) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <p class="ipucu">
                Bu değer resmî kaynaktan derlenip yayımlanmadan önce kontrol
                edilir. Yine de işlem yapmadan önce kaynağından teyit etmenizi
                öneririz; mevzuat sık değişir.
            </p>
        </article>

        <?php if ($komsular !== []): ?>
            <div class="bolum-basligi" style="margin-top:30px;">
                <h2><?= e(pratik_grup_adi((string) $bilgi['grup'])) ?></h2>
                <span class="cizgi"></span>
            </div>

            <ul class="pratik-liste">
                <?php foreach ($komsular as $komsu): ?>
                    <li>
                        <a href="<?= e(pratik_bilgi_yolu((string) $komsu['anahtar'])) ?>">
                            <span class="pratik-liste-ad"><?= e((string) $komsu['baslik']) ?></span>
                            <span class="pratik-liste-deger">
                                <?= e(pratik_ozet((string) $komsu['deger'])) ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
