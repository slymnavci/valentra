<?php
declare(strict_types=1);

/**
 * Valentra ana sayfasi.
 * Yalnizca yonetici tarafindan onaylanmis ("yayinda") haberleri listeler.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$sayfa   = max(1, (int) ($_GET['sayfa'] ?? 1));
$adet    = 13;
$haberler = haber_yayindakiler($adet, ($sayfa - 1) * $adet);
$toplam  = haber_yayinda_sayisi();
$sonSayfa = max(1, (int) ceil($toplam / $adet));

$manset  = $sayfa === 1 ? array_shift($haberler) : null;
$yan     = $sayfa === 1 ? array_splice($haberler, 0, 2) : [];

$sayfaBasligi = $sayfa > 1
    ? 'Vergi Haberleri — Sayfa ' . $sayfa . ' | Valentra'
    : 'Valentra — Vergi Haberleri';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<?php if ($toplam === 0): ?>
    <div class="bos-durum">
        <strong>Henüz yayımlanmış haber yok.</strong>
        Ajanın derlediği haberler yönetici onayından geçtikten sonra burada görünecek.
    </div>
<?php else: ?>

    <div class="bolum-basligi">
        <h2>Vergi Gündemi</h2>
        <span class="cizgi"></span>
    </div>

    <?php if ($manset !== null): ?>
        <section class="manset">
            <?php $mansetGorsel = guvenli_url($manset['gorsel_url'] ?? ''); ?>
            <article class="manset-ana <?= $mansetGorsel === '' ? 'yazili' : '' ?>">
                <a href="/haber.php?h=<?= e($manset['slug']) ?>">
                    <?php if ($mansetGorsel !== ''): ?>
                        <img class="gorsel" src="<?= e($mansetGorsel) ?>" alt="" loading="eager">
                    <?php endif; ?>
                    <div class="govde">
                        <?php $etiketler = etiketleri_coz($manset['etiketler']); ?>
                        <?php if ($etiketler !== []): ?>
                            <span class="etiket">#<?= e($etiketler[0]) ?></span>
                        <?php endif; ?>
                        <h1><?= e($manset['baslik']) ?></h1>
                        <p class="ozet"><?= e($manset['ozet']) ?></p>
                        <div class="kart-alt">
                            <span><?= e(tarih_bicimle($manset['yayin_tarihi'])) ?></span>
                            <?php if ($manset['kaynak_adi'] !== ''): ?>
                                <span>&middot; <?= e($manset['kaynak_adi']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </article>

            <div class="manset-yan">
                <?php foreach ($yan as $haber): ?>
                    <?php $kartGorsel = guvenli_url($haber['gorsel_url'] ?? ''); ?>
                    <article class="kart <?= $kartGorsel === '' ? 'yazili' : '' ?>">
                        <a href="/haber.php?h=<?= e($haber['slug']) ?>">
                            <?php if ($kartGorsel !== ''): ?>
                                <img class="gorsel" src="<?= e($kartGorsel) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <div class="govde">
                                <?php $etiketler = etiketleri_coz($haber['etiketler']); ?>
                                <?php if ($etiketler !== []): ?>
                                    <span class="etiket">#<?= e($etiketler[0]) ?></span>
                                <?php endif; ?>
                                <h3><?= e($haber['baslik']) ?></h3>
                                <p class="ozet"><?= e(kisalt($haber['ozet'], 110)) ?></p>
                                <div class="kart-alt">
                                    <span><?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></span>
                                    <?php if ($haber['kaynak_adi'] !== ''): ?>
                                        <span>&middot; <?= e($haber['kaynak_adi']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($haberler !== []): ?>
        <div class="bolum-basligi">
            <h2>Son Haberler</h2>
            <span class="cizgi"></span>
        </div>

        <section class="kart-izgara">
            <?php foreach ($haberler as $haber): ?>
                <?php $kartGorsel = guvenli_url($haber['gorsel_url'] ?? ''); ?>
                <article class="kart <?= $kartGorsel === '' ? 'yazili' : '' ?>">
                    <a href="/haber.php?h=<?= e($haber['slug']) ?>">
                        <?php if ($kartGorsel !== ''): ?>
                            <img class="gorsel" src="<?= e($kartGorsel) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <div class="govde">
                            <?php $etiketler = etiketleri_coz($haber['etiketler']); ?>
                            <?php if ($etiketler !== []): ?>
                                <span class="etiket">#<?= e($etiketler[0]) ?></span>
                            <?php endif; ?>
                            <h3><?= e($haber['baslik']) ?></h3>
                            <p class="ozet"><?= e(kisalt($haber['ozet'], 120)) ?></p>
                            <div class="kart-alt">
                                <span><?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></span>
                                <?php if ($haber['kaynak_adi'] !== ''): ?>
                                    <span>&middot; <?= e($haber['kaynak_adi']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </section>
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

<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
