<?php
declare(strict_types=1);

/**
 * Google ile giriş ayarları (13.11.2026; bkz. includes/uye_google.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/uye_google.php';

giris_zorunlu();

$panelBasligi = 'Google ile giriş';
$bildirim = '';
$hata     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    if ((string) ($_POST['islem'] ?? '') === 'sil') {
        ayar_yaz(GOOGLE_GIRIS_ISTEMCI_AYARI, '');
        ayar_yaz(GOOGLE_GIRIS_GIZLI_AYARI, '');
        $bildirim = 'Google ile giriş kapatıldı; düğme sitede görünmez. Google ile üye olmuş kişiler şifre yenileme ile giriş yapabilir.';
    } else {
        $istemci = trim((string) ($_POST['istemci'] ?? ''));
        $gizli   = trim((string) ($_POST['gizli'] ?? ''));

        if (preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $istemci) !== 1) {
            $hata = 'İstemci kimliği geçerli görünmüyor. "123456789-abc...apps.googleusercontent.com" biçimindedir.';
        } elseif ($gizli === '' && ayar_oku(GOOGLE_GIRIS_GIZLI_AYARI, '') === '') {
            $hata = 'İstemci gizli anahtarını da yazın.';
        } else {
            ayar_yaz(GOOGLE_GIRIS_ISTEMCI_AYARI, $istemci);

            if ($gizli !== '') {
                ayar_yaz(GOOGLE_GIRIS_GIZLI_AYARI, $gizli);
            }

            $bildirim = 'Kaydedildi. "Google ile giriş yap" düğmesi üyelik sayfalarında ve V Network\'te görünüyor.';
        }
    }
}

$istemci = ayar_oku(GOOGLE_GIRIS_ISTEMCI_AYARI, '');
$gizli   = ayar_oku(GOOGLE_GIRIS_GIZLI_AYARI, '');

require __DIR__ . '/ust.php';
?>

<h1>Google ile giriş</h1>
<p class="ozet">Okurlar V Network'e Google hesaplarıyla tek dokunuşla üye olur ve giriş yapar. Google'dan yalnızca ad ve e-posta alınır.
    Durum: <span class="rozet <?= google_giris_acik() ? 'rozet-yayinda' : 'rozet-taslak' ?>"><?= google_giris_acik() ? 'açık' : 'kurulmadı' ?></span></p>

<?php if ($bildirim !== ''): ?><div class="uyari uyari-basari"><?= e($bildirim) ?></div><?php endif; ?>
<?php if ($hata !== ''): ?><div class="uyari uyari-hata"><?= e($hata) ?></div><?php endif; ?>
<?php if (!google_giris_hazir()): ?>
    <div class="uyari uyari-hata">Üyeler tablosunda gerekli sütun yok. <a href="veritabani.php">Veritabanı</a> sayfasından şemayı güncelleyin.</div>
<?php endif; ?>

<div class="kutu">
    <h2 style="margin:0 0 8px;font-size:1.05rem;">Kurulum (bir kez, yaklaşık 10 dakika)</h2>
    <ol style="margin:0;padding-left:20px;line-height:1.7;">
        <li><a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Kimlik bilgileri</a> sayfasını açın (Google hesabınızla; ücretsiz).</li>
        <li>Proje yoksa bir proje oluşturun. "OAuth izin ekranı"nda uygulama adını (Valentra), destek e-postasını ve alan adını (valentra.com.tr) girin; kullanıcı türü "Harici".</li>
        <li>"Kimlik bilgisi oluştur → OAuth istemci kimliği → Web uygulaması" seçin.</li>
        <li>"Yetkili yönlendirme URI'leri"ne tam olarak şunu ekleyin:<br>
            <code style="user-select:all;"><?= e(google_giris_donus_adresi()) ?></code></li>
        <li>Oluşan <strong>istemci kimliğini</strong> ve <strong>istemci gizli anahtarını</strong> aşağıya yazın.</li>
        <li>İzin ekranını "Yayınla" durumuna alın; "Test" durumunda yalnızca eklediğiniz deneme hesapları giriş yapabilir.</li>
    </ol>
</div>

<form method="post" class="kutu" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
    <div class="alan">
        <label for="istemci">İstemci kimliği (Client ID)</label>
        <input id="istemci" type="text" name="istemci" value="<?= e($istemci) ?>" placeholder="123456789-abc.apps.googleusercontent.com" autocomplete="off" required>
    </div>
    <div class="alan">
        <label for="gizli">İstemci gizli anahtarı (Client secret)</label>
        <input id="gizli" type="password" name="gizli" value="" placeholder="<?= $gizli !== '' ? 'Kayıtlı: ' . e(ayar_maskele($gizli)) . ' (değiştirmek için yeniden yazın)' : 'GOCSPX-...' ?>" autocomplete="new-password">
        <div class="ipucu">Anahtar yalnızca sunucuda kullanılır; okurun tarayıcısına gitmez.</div>
    </div>
    <button type="submit" class="dugme dugme-ana">Kaydet</button>
</form>

<?php if ($istemci !== '' || $gizli !== ''): ?>
    <form method="post" style="margin-top:12px;" onsubmit="return confirm('Google ile giriş kapatılsın mı?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="sil">
        <button type="submit" class="dugme dugme-ret">Google ile girişi kapat</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
