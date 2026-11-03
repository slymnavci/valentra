<?php
declare(strict_types=1);

/**
 * Son depremler (03.11.2026; bkz. includes/deprem.php).
 *
 * Son 24 saatte Turkiye ve cevresinde olan depremler, yeniden eskiye.
 * Veri AFAD'dan (ulasilamazsa Kandilli), iki dakikalik onbellek. Sayfa
 * acikken iki dakikada bir kendini yeniler.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

// Ziyaretciye bir dakikalik kopya; ?deprem= haberin kaynak adresi, ayni sayfa.
sayfa_onbellek([]);

require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/deprem.php';

$veri  = deprem_son_liste();
$liste = $veri['liste'];

$enBuyuk = null;
$dortUstu = 0;

foreach ($liste as $d) {
    if ($enBuyuk === null || $d['buyukluk'] > $enBuyuk['buyukluk']) {
        $enBuyuk = $d;
    }

    $dortUstu += $d['buyukluk'] >= 4.0 ? 1 : 0;
}

$aktifKategori = 'deprem';
$sayfaBasligi  = 'Son Depremler: AFAD ve Kandilli Canlı Deprem Listesi — Valentra';
$sayfaAciklama = 'Türkiye ve çevresinde son 24 saatte olan depremler: büyüklük, yer, saat ve derinlik. '
    . 'AFAD verisiyle iki dakikada bir güncellenen canlı deprem listesi.';
$seoAdres      = site_adresi() . deprem_yolu();

// Bugunun deprem haberleri (Valentra Veri).
try {
    $haberler = db()->query(
        "SELECT baslik, slug, yayin_tarihi FROM haberler
          WHERE durum = 'yayinda' AND ajan_modeli = 'valentra-veri' AND kaynak_url LIKE '%deprem=%'
          ORDER BY yayin_tarihi DESC LIMIT 6"
    )->fetchAll();
} catch (Throwable $e) {
    $haberler = [];
}

$tr = new DateTimeZone('Europe/Istanbul');

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Son Depremler</h1>
    <p>Türkiye ve çevresinde son 24 saatte olan <?= e(deprem_buyukluk_yazi(DEPREM_LISTE_ESIGI)) ?> ve üzeri büyüklükteki depremler.
        <?php if ($veri['zaman'] > 0): ?>
            Kaynak: <?= e($veri['kaynak']) ?> · Güncelleme <?= e((new DateTimeImmutable('@' . $veri['zaman']))->setTimezone($tr)->format('H.i')) ?>
        <?php endif; ?>
    </p>
</div>

<div class="ana-duzen tam">
    <div class="ana-kolon">
        <?php if ($liste === []): ?>
            <div class="bos-durum">
                <strong>Deprem verisi şu an alınamadı.</strong>
                Birkaç dakika içinde yeniden deneyin.
            </div>
        <?php else: ?>
            <div class="deprem-ozet">
                <div><strong><?= count($liste) ?></strong><span>deprem (24 saat)</span></div>
                <?php if ($enBuyuk !== null): ?>
                    <div><strong><?= e(deprem_buyukluk_yazi((float) $enBuyuk['buyukluk'])) ?></strong>
                        <span>en büyüğü · <?= e(deprem_yer_adi($enBuyuk)) ?></span></div>
                <?php endif; ?>
                <div><strong><?= $dortUstu ?></strong><span>4 ve üzeri</span></div>
            </div>

            <?php if ($haberler !== []): ?>
                <div class="deprem-haberler">
                    <h2>Deprem haberleri</h2>
                    <ul>
                        <?php foreach ($haberler as $h): ?>
                            <li><a href="<?= e(haber_yolu((string) $h['slug'])) ?>"><?= e((string) $h['baslik']) ?></a>
                                <time><?= e(date('d.m H.i', strtotime((string) $h['yayin_tarihi']))) ?></time></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="deprem-tablo-kap">
                <table class="deprem-tablo">
                    <thead>
                        <tr><th>Büyüklük</th><th>Yer</th><th>Saat</th><th>Derinlik</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liste as $d): ?>
                            <?php
                            $z   = (new DateTimeImmutable('@' . $d['zaman']))->setTimezone($tr);
                            $sev = $d['buyukluk'] >= 5 ? 'b5' : ($d['buyukluk'] >= 4 ? 'b4' : ($d['buyukluk'] >= 3 ? 'b3' : ''));
                            ?>
                            <tr class="<?= $sev ?>">
                                <td><span class="deprem-b"><?= e(deprem_buyukluk_yazi((float) $d['buyukluk'])) ?></span></td>
                                <td><?= e(deprem_yer_adi($d)) ?></td>
                                <td><time datetime="<?= e($z->format(DATE_ATOM)) ?>"><?= e($z->format('H.i')) ?></time>
                                    <small><?= e($z->format('d.m')) ?></small></td>
                                <td><?= e(number_format((float) $d['derinlik'], 1, ',', '')) ?> km</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="ipucu" style="margin-top:18px;">
            Değerler ilk verilerdir; AFAD ve Kandilli analiz tamamlandıkça büyüklük ve yeri güncelleyebilir.
            <?= e(deprem_buyukluk_yazi(DEPREM_HABER_ESIGI)) ?> ve üzeri Türkiye depremleri için Valentra kısa süre içinde haber yayımlar.
            Deprem anında ne yapmanız gerektiğini AFAD'ın
            <a href="https://www.afad.gov.tr/afetlere-hazirlik" target="_blank" rel="noopener">afetlere hazırlık</a> sayfasında bulabilirsiniz.
        </p>
    </div>
</div>

<script>
// Sayfa acikken iki dakikada bir yenile (sekme arka plandaysa bekle).
setInterval(function () { if (!document.hidden) { location.reload(); } }, 120000);
</script>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
