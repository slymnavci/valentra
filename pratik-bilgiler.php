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
        Günlük işte en çok kullanılan değerler. Başlığa tıklayın:
        değerin tamamı, geçerlilik dönemi ve resmî kaynağı kendi
        sayfasında.
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
            <?php
            /*
             * Liste, kart degil.
             *
             * Onceki halinde her degerin tamami kartin icine
             * basiliyordu; harcirah ve gelir vergisi tarifesi gibi
             * onlarca satirlik degerler kartlari kilometrelerce
             * uzatiyor, tek rakamlik degerler ise aralarinda
             * kayboluyordu. Simdi giriste yalnizca baslik ve tek
             * satirlik ozet var; tamami kendi sayfasinda.
             */
            ?>
            <?php foreach ($gruplar as $grup => $satirlar): ?>
                <div class="bolum-basligi">
                    <h2><?= e(pratik_grup_adi((string) $grup)) ?></h2>
                    <span class="cizgi"></span>
                </div>

                <ul class="pratik-liste">
                    <?php foreach ($satirlar as $satir): ?>
                        <li>
                            <a href="<?= e(pratik_bilgi_yolu((string) $satir['anahtar'])) ?>">
                                <span class="pratik-liste-ad">
                                    <?= e((string) $satir['baslik']) ?>
                                    <?php if (!empty($satir['donem'])): ?>
                                        <small><?= e((string) $satir['donem']) ?></small>
                                    <?php endif; ?>
                                </span>
                                <span class="pratik-liste-deger">
                                    <?= e(pratik_ozet((string) $satir['deger'])) ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
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
