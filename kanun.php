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
 * baglantisina dusuyor; sebebi panelde /admin/kanunlar.php
 * sayfasinda butun kanunlar icin topluca gorulebiliyor.
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
        <a href="<?= e(kanunlar_yolu()) ?>">Kanun listesine dön</a>
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
        <a class="geri" href="<?= e(kanunlar_yolu()) ?>">&larr; Kanunlar</a>
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
$metin = kanun_gosterim_guvenli($kanun, !$tani);
?>

<?php if ($tani): ?>
    <div class="kanun-uyari">
        <strong>Tanı</strong>
        <ul>
        <?php foreach ($metin['denemeler'] as $deneme): ?>
            <li>
                <?= e($deneme['ad']) ?> —
                <?= $deneme['kod'] > 0 ? 'HTTP ' . (int) $deneme['kod'] . ' — ' : '' ?>
                <?= e($deneme['sonuc']) ?>
                <br><small><?= e($deneme['url']) ?></small>
                <?php if ($deneme['ayrinti'] !== ''): ?>
                    <br><small><?= e($deneme['ayrinti']) ?></small>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
        Seçilen yol: <strong><?= e($metin['tur']) ?></strong>
        <?php if ($metin['url'] !== ''): ?>
            <br><small><?= e($metin['url']) ?></small>
        <?php endif; ?>
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
<?php elseif ($metin['tur'] === 'yerel'): ?>
    <?php
    /*
     * Panelden yuklenmis kopya.
     *
     * Bunun bir kopya oldugu ve ne zaman yuklendigi SAKLANMIYOR:
     * ziyaretci yururlukteki metne mi yoksa bir suretine mi baktigini
     * bilmeli. Mevzuat sik degisiyor ve bir YMM sitesinde eski hukme
     * gore islem yapmak gercek bir risk.
     */
    $yuklenen = kanun_dosya_bilgisi((int) $kanun['no']);
    ?>
    <p class="kanun-kopya-uyari">
        <strong>Bu metin bir kopyadır.</strong>
        Resmî kaynağa şu anda ulaşılamadığı için
        <?= e(tarih_bicimle($yuklenen['tarih'], false)) ?>
        tarihinde yüklenen sureti gösteriliyor. O tarihten sonraki
        değişiklikleri içermeyebilir;
        <a href="<?= e($adres) ?>" target="_blank" rel="noopener">yürürlükteki
        metni mevzuat.gov.tr'de</a> teyit edin.
    </p>

    <iframe class="kanun-pdf"
            src="/api/kanun-pdf.php?k=<?= e(kanun_anahtari($kanun)) ?>"
            title="<?= e($kanun['ad']) ?> — yüklenen suret"></iframe>
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
    <?php
    /*
     * Metin gelmedi — ama bu ziyaretci icin cikmaz sokak degil.
     *
     * Erisemeyen taraf SUNUCUMUZ; ziyaretcinin kendi tarayicisi
     * mevzuat.gov.tr'ye gayet erisiyor. O yuzden burada bir hata
     * seridi degil, calisan iki yol sunuluyor: resmi sayfa ve resmi
     * PDF'in dogrudan adresi. Onceki halinde sayfanin geri kalani
     * bombostu ve kirik gorunuyordu; alta kanun listesi konarak
     * ziyaretci en azindan aradigi baska bir kanuna gecebiliyor.
     */
    $pdfAdresi = kanun_pdf_adaylari($kanun)[0]['url'] ?? $adres;
    ?>

    <section class="kanun-duser">
        <svg class="kanun-duser-simge" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <path d="M14 2v6h6"/>
        </svg>

        <h2>Metin şu anda buraya getirilemiyor</h2>

        <p>
            <?= e($kanun['ad']) ?> metnini resmî kaynaktan çekemedik.
            Kaynağa erişemeyen taraf bu site; kendi tarayıcınızdan
            aşağıdaki bağlantılar çalışır.
        </p>

        <div class="kanun-duser-yollar">
            <a class="dugme-birincil" href="<?= e($adres) ?>"
               target="_blank" rel="noopener">
                mevzuat.gov.tr'de aç &nearr;
            </a>
            <a class="dugme-ikincil" href="<?= e($pdfAdresi) ?>"
               target="_blank" rel="noopener">
                Resmî PDF'i aç &nearr;
            </a>
        </div>

        <p class="kanun-duser-not">
            Valentra kanun metinlerinin kopyasını tutmaz; her zaman
            yürürlükteki resmî metne bağlanır.
            <a href="<?= e(kanun_yolu(kanun_anahtari($kanun), true)) ?>">
                Neden getirilemedi?
            </a>
        </p>

        <?php
        /*
         * Sebebi yalnizca giris yapmis yoneticiye goster.
         *
         * Ziyaretcinin "SSL sertifikasi dogrulanamadi" gibi bir
         * ayrintiya ihtiyaci yok; ama bu bilgi olmadan sorunu
         * uzaktan cozmek korlemesine oluyor. Acilir bir blokta
         * duruyor ki sayfa bozuk gorunmesin.
         */
        oturum_baslat();
        ?>
        <?php if (oturum_acik()): ?>
            <details class="kanun-tani">
                <summary>Yönetici notu</summary>
                <p><?= e($metin['neden']) ?></p>
                <p><?= e(ca_paketi_durumu()) ?></p>
                <p>
                    <a href="/admin/kanunlar.php?k=<?= e(kanun_anahtari($kanun)) ?>">
                        Panelde ayrıntılı tanı
                    </a>
                </p>
            </details>
        <?php endif; ?>
    </section>

    <div class="bolum-basligi" style="margin-top:34px;">
        <h2>Diğer kanunlar</h2>
        <span class="cizgi"></span>
    </div>

    <section class="kanun-listesi">
        <?php foreach (kanun_listesi() as $diger): ?>
            <?php if (kanun_anahtari($diger) === kanun_anahtari($kanun)) { continue; } ?>
            <article class="kanun">
                <a href="<?= e(kanun_yolu(kanun_anahtari($diger))) ?>">
                    <div class="kanun-ust">
                        <h3><?= e($diger['ad']) ?></h3>
                        <span class="kanun-no"><?= (int) $diger['no'] ?> sayılı</span>
                    </div>
                    <p><?= e($diger['aciklama']) ?></p>
                    <span class="kanun-bag">Metni oku &rarr;</span>
                </a>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
