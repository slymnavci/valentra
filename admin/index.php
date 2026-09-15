<?php
declare(strict_types=1);

/**
 * Yonetim paneli ana ekrani.
 * Ajanin biriktirdigi taslaklar burada onay bekler; onaylanan haber
 * aninda ana sayfaya duser.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

giris_zorunlu();

$gecerliDurumlar = [HABER_TASLAK, HABER_YAYINDA, HABER_REDDEDILDI];
$durum = (string) ($_GET['durum'] ?? HABER_TASLAK);

if (!in_array($durum, $gecerliDurumlar, true)) {
    $durum = HABER_TASLAK;
}

$sayilar  = haber_durum_sayilari();
$haberler = haber_listele($durum);

$bildirimler = [
    'onaylandi'  => ['basari', 'Haber onaylandı ve ana sayfaya alındı.'],
    'reddedildi' => ['bilgi',  'Haber reddedildi.'],
    'geri'       => ['bilgi',  'Haber yayından kaldırıldı, taslaklara alındı.'],
    'guncellendi'=> ['basari', 'Haber güncellendi.'],
    'silindi'    => ['bilgi',  'Haber silindi.'],
];

$bildirim = $bildirimler[(string) ($_GET['bildirim'] ?? '')] ?? null;

$panelBasligi = 'Haberler';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== null): ?>
    <div class="uyari uyari-<?= e($bildirim[0]) ?>" style="margin-top:20px;"><?= e($bildirim[1]) ?></div>
<?php endif; ?>

<nav class="sekmeler">
    <a href="?durum=taslak" class="<?= $durum === HABER_TASLAK ? 'aktif' : '' ?>">
        Onay bekleyen
        <?php if ($sayilar[HABER_TASLAK] > 0): ?>
            <span class="adet"><?= $sayilar[HABER_TASLAK] ?></span>
        <?php endif; ?>
    </a>
    <a href="?durum=yayinda" class="<?= $durum === HABER_YAYINDA ? 'aktif' : '' ?>">
        Yayında (<?= $sayilar[HABER_YAYINDA] ?>)
    </a>
    <a href="?durum=reddedildi" class="<?= $durum === HABER_REDDEDILDI ? 'aktif' : '' ?>">
        Reddedilen (<?= $sayilar[HABER_REDDEDILDI] ?>)
    </a>
</nav>

<?php if ($haberler === []): ?>
    <div class="bos-durum">
        <?php if ($durum === HABER_TASLAK): ?>
            <strong>Onay bekleyen haber yok.</strong>
            Ajan yeni haber derlediğinde burada listelenecek.
        <?php elseif ($durum === HABER_YAYINDA): ?>
            <strong>Yayında haber yok.</strong>
            Onay bekleyen sekmesinden haber onaylayın.
        <?php else: ?>
            <strong>Reddedilen haber yok.</strong>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php foreach ($haberler as $haber): ?>
        <article class="haber-satiri">
            <div>
                <h3>
                    <a href="duzenle.php?id=<?= (int) $haber['id'] ?>"><?= e($haber['baslik']) ?></a>
                </h3>

                <?php if ($haber['ozet'] !== ''): ?>
                    <p class="ozet"><?= e(kisalt($haber['ozet'], 190)) ?></p>
                <?php endif; ?>

                <div class="satir-bilgi">
                    <span class="rozet rozet-<?= e($haber['durum']) ?>"><?= e($haber['durum']) ?></span>

                    <?php if ((int) $haber['guven_skoru'] > 0): ?>
                        <span class="rozet rozet-skor">güven %<?= (int) $haber['guven_skoru'] ?></span>
                    <?php endif; ?>

                    <?php if (!empty($haber['kategori_adi'])): ?>
                        <span class="rozet rozet-skor"><?= e($haber['kategori_adi']) ?></span>
                    <?php endif; ?>

                    <?php if ($haber['kaynak_adi'] !== ''): ?>
                        <span><?= e($haber['kaynak_adi']) ?></span>
                    <?php endif; ?>

                    <span><?= e(tarih_bicimle($haber['olusturuldu'])) ?></span>

                    <?php if ($haber['kaynak_url'] !== ''): ?>
                        <a href="<?= e(guvenli_url($haber['kaynak_url'])) ?>" target="_blank" rel="noopener nofollow">kaynak</a>
                    <?php endif; ?>
                </div>

                <?php if ($haber['ajan_notu'] !== ''): ?>
                    <div class="satir-bilgi" style="margin-top:8px;font-style:italic;">
                        Ajan notu: <?= e($haber['ajan_notu']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="satir-islem">
                <a class="dugme" href="duzenle.php?id=<?= (int) $haber['id'] ?>">İncele</a>

                <?php if ($haber['durum'] === HABER_TASLAK): ?>
                    <form method="post" action="islem.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $haber['id'] ?>">
                        <input type="hidden" name="islem" value="onayla">
                        <button type="submit" class="dugme dugme-onay" style="width:100%;">Onayla</button>
                    </form>

                    <form method="post" action="islem.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $haber['id'] ?>">
                        <input type="hidden" name="islem" value="reddet">
                        <button type="submit" class="dugme dugme-ret" style="width:100%;">Reddet</button>
                    </form>

                <?php elseif ($haber['durum'] === HABER_YAYINDA): ?>
                    <a class="dugme" href="/haber.php?h=<?= e($haber['slug']) ?>" target="_blank" rel="noopener">Sitede gör</a>

                    <form method="post" action="islem.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $haber['id'] ?>">
                        <input type="hidden" name="islem" value="geri_al">
                        <button type="submit" class="dugme dugme-ret" style="width:100%;">Yayından kaldır</button>
                    </form>

                <?php else: ?>
                    <form method="post" action="islem.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $haber['id'] ?>">
                        <input type="hidden" name="islem" value="onayla">
                        <button type="submit" class="dugme dugme-onay" style="width:100%;">Yine de yayınla</button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>

<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
