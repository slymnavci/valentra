<?php
declare(strict_types=1);

/**
 * Vergi takvimi sayfası.
 *
 * Tarihler tek tek saklanmiyor; kural saklaniyor ("her ayin 26'si") ve
 * sayfa o kuraldan hesapliyor. Boylece liste her yil elle
 * yenilenmiyor ve gelecek yillara da bakilabiliyor.
 *
 * SORUMLULUK: bu sayfa bilgi amaclidir. Sure sonu hafta sonuna ya da
 * resmi tatile denk geldiginde ilk is gunune kayar ve GIB sik sik
 * sure uzatimi yayimlar. Bu yuzden her ekranda GIB'in resmi takvimine
 * yonlendiren bir uyari duruyor; kaldirilmamali.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/takvim.php';

$buYil = (int) date('Y');
$yil   = (int) ($_GET['yil'] ?? $buYil);

// Anlamsiz yillari kabul etmiyoruz: adres cubuguna yazilan bir sayi
// bos bir takvim ya da tuhaf tarihler uretmesin.
if ($yil < $buYil - 1 || $yil > $buYil + 1) {
    $yil = $buYil;
}

$yillik   = takvim_yili($yil);
$yaklasan = $yil === $buYil ? takvim_yaklasanlar(60, 6) : [];
$buAy     = (int) date('n');
$buGun    = (int) date('j');

$aktifKategori = 'takvim';
$sayfaBasligi  = $yil . ' Vergi Takvimi — Beyan ve Ödeme Tarihleri | Valentra';
$sayfaAciklama = $yil . ' yılı beyan ve ödeme süreleri: muhtasar, KDV, damga vergisi, '
               . 'geçici vergi, Form Ba-Bs ve yıllık beyannameler ay ay.';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1><?= e((string) $yil) ?> Vergi Takvimi</h1>
    <p>
        Beyan ve ödeme süreleri ay ay. Tarihler bilgi amaçlıdır; süre uzatımı
        ve tatil kaymaları için
        <a href="https://www.gib.gov.tr/vergi-takvimi" target="_blank" rel="noopener">GİB
        vergi takvimini</a> esas alın.
    </p>
</div>

<?php if ($yaklasan !== []): ?>
    <section class="takvim-vurgu">
        <h2>Yaklaşan Tarihler</h2>

        <div class="takvim-vurgu-liste">
            <?php foreach ($yaklasan as $olay): ?>
                <div class="takvim-vurgu-oge">
                    <span class="takvim-gun">
                        <strong><?= e(date('j', strtotime($olay['tarih']))) ?></strong>
                        <span><?= e(ay_kisa((int) date('n', strtotime($olay['tarih'])))) ?></span>
                    </span>
                    <span class="takvim-yazi">
                        <span class="ad"><?= e($olay['baslik']) ?></span>
                        <span class="ust-bilgi">
                            <?= $olay['kalan'] === 0 ? 'bugün son gün' : $olay['kalan'] . ' gün kaldı' ?>
                        </span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php
// Butun aylar bossa tablo yerine aciklama basiyoruz; bos bir yil
// gridi ziyaretciye hicbir sey anlatmaz.
$doluAy = 0;

foreach ($yillik as $olaylar) {
    if ($olaylar !== []) {
        $doluAy++;
    }
}
?>

<?php if ($doluAy === 0): ?>
    <div class="bos-durum">
        <strong>Takvim henüz doldurulmamış.</strong>
        Beyan ve ödeme süreleri yönetim panelinden girildiğinde burada görünecek.
    </div>
<?php else: ?>

    <div class="takvim-yil">
        <?php for ($ay = 1; $ay <= 12; $ay++): ?>
            <?php
            $olaylar = $yillik[$ay] ?? [];
            $gecmis  = $yil < $buYil || ($yil === $buYil && $ay < $buAy);
            $suAnki  = $yil === $buYil && $ay === $buAy;
            ?>
            <section class="takvim-ay <?= $gecmis ? 'gecmis' : '' ?> <?= $suAnki ? 'su-anki' : '' ?>">
                <h2>
                    <?= e(takvim_ay_adi($ay)) ?>
                    <?php if ($suAnki): ?><span class="rozet">bu ay</span><?php endif; ?>
                </h2>

                <?php if ($olaylar === []): ?>
                    <p class="takvim-bos">Bu ay için kayıtlı yükümlülük yok.</p>
                <?php else: ?>
                    <ul class="takvim-ay-liste">
                        <?php foreach ($olaylar as $olay): ?>
                            <?php
                            // Bu ayin gecmis gunlerini soluklastiriyoruz:
                            // okuyucu neyin kaldigini bir bakista gormeli.
                            $olduBitti = $gecmis || ($suAnki && $olay['gun'] < $buGun);
                            ?>
                            <li class="<?= $olduBitti ? 'oldu' : '' ?>">
                                <span class="gun"><?= e((string) $olay['gun']) ?></span>
                                <span class="yazi">
                                    <span class="ad"><?= e($olay['baslik']) ?></span>
                                    <?php if ($olay['aciklama'] !== ''): ?>
                                        <span class="ust-bilgi"><?= e($olay['aciklama']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endfor; ?>
    </div>

    <nav class="takvim-yil-gecis">
        <?php if ($yil > $buYil - 1): ?>
            <a href="<?= e(takvim_yolu($yil - 1)) ?>">&larr; <?= $yil - 1 ?></a>
        <?php endif; ?>
        <span><?= e((string) $yil) ?></span>
        <?php if ($yil < $buYil + 1): ?>
            <a href="<?= e(takvim_yolu($yil + 1)) ?>"><?= $yil + 1 ?> &rarr;</a>
        <?php endif; ?>
    </nav>

<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
