<?php
declare(strict_types=1);

/**
 * Arama motoru ayarları.
 *
 * Iki is yapiyor:
 *   1) Google Search Console dogrulama kodunu saklamak. Sitenin
 *      indekslenip indekslenmedigi yalnizca orada gorulur; panelin
 *      ziyaret sayaci Googlebot'u bilerek saymadigi icin "Google geldi
 *      mi" sorusunu buradan cevaplamak mumkun degil.
 *   2) Temiz adresleri sinamak ve acmak. Temiz adresler mod_rewrite'a
 *      bagli; kapaliysa butun baglantilar 404 donerdi. Bu yuzden once
 *      gercekten deneniyor, calistigi gorulunce aciliyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/http_ortak.php';

giris_zorunlu();

$panelBasligi = 'Arama motoru';
$bildirim     = '';
$hata         = '';

/** @var array{denendi:bool,tamam:bool,mesaj:string,adres:string} */
$deneme = ['denendi' => false, 'tamam' => false, 'mesaj' => '', 'adres' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'dogrulama') {
        /*
         * Google'in verdigi deger ya sade bir anahtar ya da tam meta
         * etiketi olarak kopyalaniyor. Ikisini de kabul edip icinden
         * anahtari cikariyoruz; aksi halde etiketin tamamini yapistiran
         * kullanici sayfaya bozuk isaretleme koyardi.
         */
        $ham = trim((string) ($_POST['kod'] ?? ''));

        if (preg_match('/content=["\']([^"\']+)["\']/', $ham, $es) === 1) {
            $ham = $es[1];
        }

        $ham = trim($ham);

        if ($ham !== '' && preg_match('/^[A-Za-z0-9_\-]{10,120}$/', $ham) !== 1) {
            $hata = 'Doğrulama kodu beklenen biçimde değil. Google\'ın verdiği '
                  . 'meta etiketini olduğu gibi yapıştırabilirsiniz.';
        } else {
            ayar_yaz('google_dogrulama', $ham);
            $bildirim = $ham === ''
                ? 'Doğrulama kodu kaldırıldı.'
                : 'Doğrulama kodu kaydedildi. Search Console\'da "Doğrula" deyin.';
        }
    }

    if ($islem === 'temiz_dene' || $islem === 'temiz_ac') {
        $adres = site_adresi() . '/kanunlar';
        $yanit = http_getir($adres, 15);

        $deneme['denendi'] = true;
        $deneme['adres']   = $adres;

        /*
         * Yalnizca HTTP 200'e bakmak yetmez: mod_rewrite kapaliyken
         * bazi sunucular 404 yerine kendi hata sayfasini 200 ile
         * dondurur. Sayfanin gercekten kanun listesi oldugunu
         * isaretinden dogruluyoruz.
         */
        if (!$yanit['tamam']) {
            $deneme['mesaj'] = 'Adres açılamadı: ' . $yanit['neden'];
        } elseif (!str_contains($yanit['govde'], 'Vergi Kanunları')) {
            $deneme['mesaj'] = 'Adres açıldı ama beklenen sayfa gelmedi; '
                             . 'mod_rewrite kapalı olabilir.';
        } else {
            $deneme['tamam'] = true;
            $deneme['mesaj'] = 'Temiz adres çalışıyor.';
        }

        if ($islem === 'temiz_ac') {
            if ($deneme['tamam']) {
                ayar_yaz('temiz_adres', '1');
                $bildirim = 'Temiz adresler açıldı. Site içi bağlantılar '
                          . 'artık /haber/... biçiminde.';
            } else {
                $hata = 'Sınama geçmeden açılmadı; site bağlantıları kırılırdı.';
            }
        }
    }

    if ($islem === 'temiz_kapat') {
        ayar_yaz('temiz_adres', '0');
        $bildirim = 'Temiz adresler kapatıldı; bağlantılar eski biçime döndü.';
    }
}

$dogrulamaKodu = ayar_oku('google_dogrulama');
$temizAcik     = ayar_oku('temiz_adres') === '1';

require __DIR__ . '/ust.php';
?>

<h1>Arama motoru</h1>

<?php if ($bildirim !== ''): ?>
    <div class="uyari"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu">
    <h2 style="margin-top:0;">Google Search Console</h2>

    <p class="ipucu">
        Sitenin Google tarafından taranıp taranmadığı, hangi aramalarda
        çıktığı ve indeksleme hataları yalnızca Search Console'da görünür.
        Panelin ziyaret sayacı arama motoru botlarını bilerek saymaz, o
        yüzden bu soruyu buradan yanıtlamak mümkün değil.
    </p>

    <ol class="ipucu">
        <li>
            <a href="https://search.google.com/search-console" target="_blank"
               rel="noopener">search.google.com/search-console</a>
            adresinde <strong>URL öneki</strong> yöntemiyle
            <code><?= e(site_adresi()) ?></code> adresini ekleyin.
        </li>
        <li>Doğrulama yöntemi olarak <strong>HTML etiketi</strong>'ni seçin.</li>
        <li>Verilen etiketi aşağıya yapıştırıp kaydedin, sonra "Doğrula" deyin.</li>
        <li>
            Doğrulama geçince site haritasını gönderin:
            <code><?= e(site_adresi()) ?>/sitemap.php</code>
        </li>
    </ol>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="dogrulama">

        <label for="kod">Doğrulama etiketi ya da kodu</label>
        <input type="text" id="kod" name="kod" style="width:100%;"
               value="<?= e($dogrulamaKodu) ?>"
               placeholder='<meta name="google-site-verification" content="..." />'>

        <p class="ipucu">
            Etiketin tamamını yapıştırabilirsiniz; içinden kod ayıklanır.
            Boş bırakıp kaydederseniz etiket sayfalardan kaldırılır.
        </p>

        <button class="dugme dugme-ana" type="submit">Kaydet</button>
    </form>

    <?php if ($dogrulamaKodu !== ''): ?>
        <p class="ipucu" style="margin-bottom:0;">
            Şu an her sayfanın <code>&lt;head&gt;</code> bölümünde
            <code>google-site-verification</code> etiketi yayınlanıyor.
        </p>
    <?php endif; ?>
</div>

<div class="kutu" style="margin-top:22px;">
    <h2 style="margin-top:0;">Temiz adresler</h2>

    <p class="ipucu">
        Açıkken bağlantılar <code>/haber/kdv-tevkifat</code> biçiminde olur;
        kapalıyken <code>/haber.php?h=kdv-tevkifat</code>. Eski adresler her
        iki durumda da çalışır — kalıcı yönlendirmeyle yeni adrese taşınır,
        böylece paylaşılmış bağlantılar kırılmaz.
    </p>

    <p class="ipucu">
        <strong>Durum:</strong>
        <?= $temizAcik ? 'açık' : 'kapalı' ?>.
        Temiz adresler sunucunun <code>mod_rewrite</code> desteğine bağlı
        olduğu için önce sınanır: desteklenmiyorsa açmak bütün bağlantıları
        kırardı.
    </p>

    <?php if ($deneme['denendi']): ?>
        <div class="uyari <?= $deneme['tamam'] ? '' : 'uyari-hata' ?>">
            <strong><?= $deneme['tamam'] ? 'Sınama geçti.' : 'Sınama geçmedi.' ?></strong>
            <?= e($deneme['mesaj']) ?>
            <br><small><?= e($deneme['adres']) ?></small>
        </div>
    <?php endif; ?>

    <form method="post" style="display:inline;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="temiz_dene">
        <button class="dugme" type="submit">Sına</button>
    </form>

    <?php if (!$temizAcik): ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="temiz_ac">
            <button class="dugme dugme-ana" type="submit">Sına ve aç</button>
        </form>
    <?php else: ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="temiz_kapat">
            <button class="dugme" type="submit">Kapat</button>
        </form>
    <?php endif; ?>

    <p class="ipucu" style="margin-bottom:0;">
        Elle denemek için:
        <a href="<?= e(site_adresi()) ?>/kanunlar" target="_blank" rel="noopener">
            <?= e(site_adresi()) ?>/kanunlar
        </a>
    </p>
</div>

<div class="kutu" style="margin-top:22px;">
    <h2 style="margin-top:0;">Hazır olanlar</h2>

    <p class="ipucu">
        Aşağıdakiler kodda zaten var; ayar gerektirmiyor.
    </p>

    <table class="liste-tablo">
        <tbody>
            <tr>
                <td>Site haritası</td>
                <td><a href="/sitemap.php" target="_blank" rel="noopener">/sitemap.php</a></td>
            </tr>
            <tr>
                <td>Tarama yönergeleri</td>
                <td><a href="/robots.txt" target="_blank" rel="noopener">/robots.txt</a></td>
            </tr>
            <tr>
                <td>Yapısal veri</td>
                <td>NewsArticle · Organization · WebSite (her sayfada)</td>
            </tr>
            <tr>
                <td>Paylaşım etiketleri</td>
                <td>canonical · Open Graph · Twitter kartı</td>
            </tr>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/alt.php'; ?>
