<?php
declare(strict_types=1);

/**
 * Kanun metni sayfası.
 *
 * Metin bizim veritabanimizda tutulmuyor; mevzuat.gov.tr'deki resmi
 * sayfa cerceve icinde gosteriliyor. Boylece okuyucu siteden cikmiyor
 * ama gordugu metin her zaman resmi ve guncel oluyor.
 *
 * Cerceve engellenebilir: bircok kurum sitesi X-Frame-Options ya da
 * CSP frame-ancestors ile baska sitelerde gosterilmeyi kapatir. Bunu
 * sunucu tarafindan onceden bilmek mumkun degil, o yuzden sayfa iki
 * duruma da hazir: cerceve bos kalirsa JavaScript bunu fark edip
 * "resmi kaynakta ac" baglantisini one cikariyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kanunlar.php';

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

<div class="kanun-uyari" id="kanunUyari" hidden>
    <strong>Metin burada gösterilemiyor.</strong>
    mevzuat.gov.tr bu sayfanın başka bir site içinde gösterilmesine izin
    vermiyor. Metni okumak için
    <a href="<?= e($adres) ?>" target="_blank" rel="noopener">resmî kaynakta açın</a>.
</div>

<div class="kanun-cerceve">
    <iframe id="kanunCerceve"
            src="<?= e($adres) ?>"
            title="<?= e($kanun['ad']) ?> — resmî metin"
            loading="lazy"
            referrerpolicy="no-referrer"></iframe>
</div>

<p class="ipucu kanun-kaynak">
    Metin <strong>mevzuat.gov.tr</strong> üzerindeki resmî yayından
    gösterilmektedir. Valentra metnin bir kopyasını tutmaz; gördüğünüz
    hâli her zaman yürürlükteki hâlidir.
</p>

<script>
(function () {
    var cerceve = document.getElementById('kanunCerceve');
    var uyari   = document.getElementById('kanunUyari');

    if (!cerceve || !uyari) { return; }

    /*
     * Cerceve engellendiginde tarayici "load" olayini yine tetikler ama
     * icerik bos kalir; engellendigini dogrudan ogrenmenin standart bir
     * yolu yok (icerige erismek ayni kaynak kurali yuzunden mumkun
     * degil). Bu yuzden yuklenip yuklenmedigi zamanla olculuyor:
     * makul bir sure icinde load gelmediyse uyari gosteriliyor.
     */
    var yuklendi = false;

    cerceve.addEventListener('load', function () {
        yuklendi = true;
    });

    setTimeout(function () {
        if (!yuklendi) {
            uyari.hidden = false;
        }
    }, 6000);
})();
</script>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
