<?php
declare(strict_types=1);

/**
 * Pratik bilgiler sayfası.
 *
 * Yalnizca onaylanmis degerler gosteriliyor. Her degerin yaninda
 * kaynagi ve gecerlilik donemi duruyor: okuyucu neye baktigini ve
 * degerin ne kadar guncel oldugunu gorebilmeli.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pratik.php';

$gruplar       = pratik_yayindakiler();
$aktifKategori = 'pratik';
$sayfaBasligi  = 'Pratik Bilgiler — Valentra';
$sayfaAciklama = 'Asgari ücret, vergi oranları, hadler ve ekonomik göstergeler '
               . 'tek sayfada, resmî kaynaklarıyla birlikte.';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Pratik Bilgiler</h1>
    <p>
        Günlük işte en çok kullanılan değerler. Her satırın yanında
        kaynağı ve geçerlilik dönemi yazıyor.
    </p>
</div>

<?php if ($gruplar === []): ?>
    <div class="bos-durum">
        <strong>Henüz yayımlanmış değer yok.</strong>
        Ajan resmî kaynaklardan derlediğinde yönetici onayından geçip burada görünecek.
    </div>
<?php else: ?>

    <div class="ana-duzen">
        <div class="ana-kolon">
            <?php foreach ($gruplar as $grup => $satirlar): ?>
                <div class="bolum-basligi">
                    <h2><?= e(pratik_grup_adi((string) $grup)) ?></h2>
                    <span class="cizgi"></span>
                </div>

                <section class="pratik-izgara">
                    <?php foreach ($satirlar as $satir): ?>
                        <article class="pratik-kart">
                            <h3><?= e((string) $satir['baslik']) ?></h3>

                            <pre class="pratik-deger"><?= e((string) $satir['deger']) ?></pre>

                            <div class="pratik-alt">
                                <?php if (!empty($satir['donem'])): ?>
                                    <span class="donem"><?= e((string) $satir['donem']) ?></span>
                                <?php endif; ?>

                                <?php $kaynak = guvenli_url((string) ($satir['kaynak_url'] ?? '')); ?>
                                <?php if ($kaynak !== ''): ?>
                                    <a href="<?= e($kaynak) ?>" target="_blank" rel="noopener">
                                        <?= e((string) $satir['kaynak_adi']) ?> &nearr;
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($satir['onay_tarihi'])): ?>
                                <span class="pratik-tarih">
                                    Son güncelleme:
                                    <?= e(tarih_bicimle((string) $satir['onay_tarihi'], false)) ?>
                                </span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <p class="ipucu" style="margin:24px 0 34px;">
                Bu sayfadaki değerler resmî kaynaklardan derlenip yayımlanmadan
                önce kontrol edilir. Yine de işlem yapmadan önce yanındaki
                kaynak bağlantısından teyit etmenizi öneririz; mevzuat sık
                değişir.
            </p>
        </div>

        <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
