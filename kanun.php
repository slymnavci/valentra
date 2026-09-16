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
require_once __DIR__ . '/includes/auth.php';

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

<?php
/*
 * ?tani=1 ile acildiginda hangi yolun nerede takildigi gosteriliyor.
 *
 * Bunlar zaten herkese acik adresler; gizli bir sey icermiyor. Buna
 * karsilik sorunun sebebini uzaktan gormenin tek pratik yolu bu:
 * mevzuat.gov.tr'ye ne bu gelistirme ortamindan ne de GitHub'dan
 * cikis var, yani denemeyi ancak sitenin kendi sunucusu yapabiliyor.
 */
$tani  = isset($_GET['tani']);
$metin = kanun_gosterim($kanun, !$tani);
?>

<?php if ($tani): ?>
    <div class="kanun-uyari">
        <strong>Tanı</strong>
        <ul>
        <?php foreach ($metin['denemeler'] as $deneme): ?>
            <li>
                <?= e($deneme['ad']) ?> — HTTP <?= (int) $deneme['kod'] ?> —
                <?= e($deneme['sonuc']) ?>
                <br><small><?= e($deneme['url']) ?></small>
            </li>
        <?php endforeach; ?>
        </ul>
        Seçilen yol: <strong><?= e($metin['tur']) ?></strong>
    </div>
<?php endif; ?>

<?php if ($metin['tur'] === 'pdf'): ?>
    <iframe class="kanun-pdf"
            src="/api/kanun-pdf.php?k=<?= e(kanun_anahtari($kanun)) ?>"
            title="<?= e($kanun['ad']) ?> — resmî metin"></iframe>

    <p class="ipucu kanun-kaynak">
        Metin <strong>mevzuat.gov.tr</strong> üzerindeki resmî yayından
        anlık olarak alınmaktadır; Valentra kalıcı bir kopya tutmaz.
        Açılmazsa
        <a href="<?= e($adres) ?>" target="_blank" rel="noopener">resmî kaynağa</a>
        gidebilirsiniz.
    </p>
<?php elseif ($metin['tur'] === 'html'): ?>

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
        Kanunun tam ve resmî metnini
        <a href="<?= e($adres) ?>" target="_blank" rel="noopener">mevzuat.gov.tr'de açabilirsiniz</a>.
        <a class="kanun-tani-bag"
           href="/kanun.php?k=<?= e(kanun_anahtari($kanun)) ?>&amp;tani=1">Neden?</a>

        <?php
        /*
         * Sebebi yalnizca giris yapmis yoneticiye goster.
         *
         * Ziyaretcinin "SSL sertifikasi dogrulanamadi" gibi bir
         * ayrintiya ihtiyaci yok; ama bu bilgi olmadan sorunu
         * uzaktan cozmek korlemesine oluyor. Ilk denemede tam bu
         * yuzden "olmadi"dan oteye gidemedik.
         */
        oturum_baslat();
        ?>
        <?php if (oturum_acik()): ?>
            <span class="kanun-tani">
                Yönetici notu: <?= e($metin['neden']) ?>
            </span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
