<?php
declare(strict_types=1);

/**
 * Kanunlar sayfası.
 *
 * Metinler burada tutulmuyor, resmî kaynağa bağlanıyor; gerekçe
 * includes/kanunlar.php başında.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kanunlar.php';

$kanunlar      = kanun_listesi();
$aktifKategori = 'kanunlar';
$sayfaBasligi  = 'Vergi Kanunları — Valentra';
$sayfaAciklama = 'Temel vergi kanunlarının güncel resmî metinlerine hızlı erişim.';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Vergi Kanunları</h1>
    <p>
        Temel vergi kanunlarının güncel metinleri. Bağlantılar
        <strong>mevzuat.gov.tr</strong> üzerindeki resmî metne gider;
        böylece her zaman yürürlükteki hâli görürsünüz.
    </p>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">
        <div class="bolum-basligi">
            <h2><?= count($kanunlar) ?> Kanun</h2>
            <span class="cizgi"></span>
        </div>

        <section class="kanun-listesi">
            <?php foreach ($kanunlar as $kanun): ?>
                <article class="kanun">
                    <?php /* Site icinde acilir; o sayfada resmi kaynaga
                             giden bag da duruyor. */ ?>
                    <a href="/kanun.php?k=<?= e(kanun_anahtari($kanun)) ?>">
                        <div class="kanun-ust">
                            <h3><?= e($kanun['ad']) ?></h3>
                            <span class="kanun-no"><?= (int) $kanun['no'] ?> sayılı</span>
                        </div>
                        <p><?= e($kanun['aciklama']) ?></p>
                        <span class="kanun-bag">Metni oku &rarr;</span>
                    </a>
                </article>
            <?php endforeach; ?>
        </section>

        <p class="ipucu" style="margin:22px 0 32px;">
            Bu sayfa kanun metinlerinin kopyasını tutmaz. Mevzuat sık
            değişir ve güncel olmayan bir metne göre işlem yapmak risk
            taşır; bu yüzden her bağlantı doğrudan resmî kaynağa gider.
        </p>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
