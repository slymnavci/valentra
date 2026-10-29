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
require_once __DIR__ . '/../includes/olcum_reklam.php';
require_once __DIR__ . '/../includes/kurumsal.php';

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

    // Google Analytics ve AdSense kimlikleri (29.10.2026; bkz. includes/olcum_reklam.php).
    if ($islem === 'olcum') {
        $ga  = strtoupper(trim((string) ($_POST['ga4'] ?? '')));
        $ad  = trim((string) ($_POST['adsense'] ?? ''));
        $bir = trim((string) ($_POST['birim'] ?? ''));

        if (preg_match('/(ca-)?pub-\d{10,20}/', $ad, $es) === 1) {
            $ad = str_starts_with($es[0], 'ca-') ? $es[0] : 'ca-' . $es[0];
        }

        if ($ga !== '' && preg_match('/^G-[A-Z0-9]{4,15}$/', $ga) !== 1) {
            $hata = 'Google Analytics ölçüm kimliği "G-" ile başlamalı (ör. G-AB12CD34EF).';
        } elseif ($ad !== '' && preg_match('/^ca-pub-\d{10,20}$/', $ad) !== 1) {
            $hata = 'AdSense yayıncı kimliği "ca-pub-" ile başlayan sayı olmalı (ör. ca-pub-1234567890123456).';
        } elseif ($bir !== '' && preg_match('/^\d{6,12}$/', $bir) !== 1) {
            $hata = 'Reklam birimi kimliği yalnızca rakam olmalı (AdSense\'teki data-ad-slot değeri).';
        } else {
            ayar_yaz('ga4_kimlik', $ga);
            ayar_yaz('adsense_kimlik', $ad);
            ayar_yaz('adsense_birim', $bir);
            $bildirim = 'Ölçüm ve reklam ayarları kaydedildi.';
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

/*
 * Panelde girilmemisse kodda tanimli varsayilan gosteriliyor; sayfalara
 * basilan deger de o. Aksi halde kutu bos gorunur ve "etiket sitede var
 * mi" sorusu panelden cevaplanamazdi.
 */
$dogrulamaKodu = ayar_oku('google_dogrulama', SEO_GOOGLE_DOGRULAMA);
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
    <h2 style="margin-top:0;">Google Analytics ve reklam (AdSense)</h2>
    <p class="ipucu">
        Kimlikleri girdiğinizde ölçüm ve reklam kodları bütün sayfalara kendiliğinden eklenir; kaydırınca gelen
        her haber Google Analytics'te ayrı sayfa görüntülemesi sayılır ve her haberin altında bir reklam alanı olur.
        Sitede çerez bildirimi ve <a href="<?= e(kurumsal_yolu('gizlilik')) ?>" target="_blank">Gizlilik ve Çerezler</a>
        sayfası da açılır. Boş bırakırsanız hiçbir kod yüklenmez.
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="olcum">

        <label for="ga4">Google Analytics ölçüm kimliği</label>
        <input type="text" id="ga4" name="ga4" value="<?= e(ayar_oku('ga4_kimlik')) ?>" placeholder="G-XXXXXXXXXX" style="width:100%;max-width:360px;">
        <p class="ipucu">analytics.google.com → Yönetici → Veri akışları → web akışı → "Ölçüm kimliği".</p>

        <label for="adsense">AdSense yayıncı kimliği</label>
        <input type="text" id="adsense" name="adsense" value="<?= e(ayar_oku('adsense_kimlik')) ?>" placeholder="ca-pub-1234567890123456" style="width:100%;max-width:360px;">
        <p class="ipucu">adsense.google.com → Hesap → Hesap bilgileri → "Yayıncı kimliği". Kaydedince
            <code><?= e(site_adresi()) ?>/ads.txt</code> dosyası da kendiliğinden hazırlanır.</p>

        <label for="birim">Haber içi reklam birimi (isteğe bağlı)</label>
        <input type="text" id="birim" name="birim" value="<?= e(ayar_oku('adsense_birim')) ?>" placeholder="1234567890" style="width:100%;max-width:360px;">
        <p class="ipucu">AdSense → Reklamlar → Reklam birimine göre → Görüntülü reklam; kodundaki <code>data-ad-slot</code>
            değeri. Boş bırakılırsa haber altındaki alanı AdSense otomatik doldurur.</p>

        <button class="dugme dugme-ana" type="submit">Kaydet</button>
    </form>
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
