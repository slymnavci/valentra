<?php
declare(strict_types=1);

/**
 * Kanun metni sayfası.
 *
 * Metin sunucu tarafinda resmi kaynaktan cekilip burada gosteriliyor.
 * Kopyasi tutulmuyor; kisa sureli onbellek disinda her seferinde
 * kaynaktan aliniyor, yani gosterilen metin yururlukteki hali.
 *
 * Once cerceve (iframe) denendi ama olmadi: mevzuat.gov.tr sayfanin
 * baska bir site icinde gosterilmesine izin vermiyor ve kutu bombos
 * kaliyordu. Ustelik engellenen bir cerceve tarayicida yine "load"
 * olayini tetikledigi icin bunu JavaScript ile fark etmek de
 * guvenilir degil — ilk denemede uyari hic gorunmedi.
 *
 * Erisim yine de garanti degil (kaynak veri merkezi IP'lerini
 * engelliyor). Metin alinamazsa sayfa duzgunce resmi kaynak
 * baglantisina dusuyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kanunlar.php';
require_once __DIR__ . '/includes/kanun_metni.php';

$anahtar = trim((string) ($_GET['k' ] ?? ''));
$kanun   = $anahtar !== '' ? kanun_bul($anahtar) : null;

if ($kanun === null) {
    http_response_code(404);
    $sayfaBasligi  = 'Kanun bulunamadı — Valentra';
    $aktifKategori = 'kanunlar';

    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Kanun bulunamadı.</strong>
        <a href="/kanunlar.php">Kanun listesine dön</a>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$adres         = kanun_adresi($kanun);
$aktifKategori = 'kanunlar';
$sayfaBasligi  = $kanun['ad'] . ' — Valentra';
$sayfaAciklama = $kanun['aciklama'];

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kanun-basligi">
    <div>
        <a class="geri" href="/kanunlar.php">&larr; Kanunlar</a>
        <h1><?= e($kanun['ad']) ?></h1>
        <p><?= (int) $kanun['no'] ?> sayılı Kanun &middot; <?= e($kanun['aciklama']) ?></p>
    </div>

    <a class="dis-bag" href="<?= e($adres) ?>" target="_blank" rel="noopener">
        Resmî kaynakta aç &nearr;
    </a>
</div>

<?php $metin = kanun_metni_getir($kanun); ?>

<?php if ($metin['tamam']): ?>
    <article class="kanun-metin"><?= $metin['govde'] ?></article>

    <p class="ipucu kanun-kaynak">
        Metin <strong>mevzuat.gov.tr</strong> üzerindeki resmî yayından
        alınmıştır. Valentra metnin kalıcı bir kopyasını tutmaz.
        Kesin hüküm için
        <a href="<?= e($adres) ?>" target="_blank" rel="noopener">resmî kaynağa</a>
        bakınız.
    </p>
<?php else: ?>
    <div class="kanun-uyari">
        <strong>Metin şu anda buraya getirilemedi.</strong>
        <?= e($metin['neden']) ?>
        Kanunun tam ve resmî metnini
        <a href="<?= e($adres) ?>" target="_blank" rel="noopener">mevzuat.gov.tr'de açabilirsiniz</a>.
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
