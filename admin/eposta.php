<?php
declare(strict_types=1);

/**
 * E-posta ayarlari (31.10.2026): V Network uyeliginde e-posta dogrulamasi
 * ve "sifremi unuttum" baglantilari bu ayarlarla gidiyor
 * (includes/eposta.php, includes/uye_eposta.php).
 *
 * SMTP sunucusu bos birakilirsa barindirmanin kendi postacisi (PHP mail())
 * kullaniliyor; bu cogu zaman calisir ama e-postalar istenmeyen klasorune
 * dusebilir. IHS'te acilan bir e-posta hesabinin SMTP bilgileri girilirse
 * teslim daha guvenilir. Sifre kaydedildikten sonra gosterilmiyor; bos
 * birakilirsa eskisi korunuyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/eposta.php';
require_once __DIR__ . '/../includes/uye_eposta.php';

giris_zorunlu();

$panelBasligi = 'E-posta';
$bildirim = '';
$hata     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);
    $islem = (string) ($_POST['islem'] ?? 'kaydet');

    if ($islem === 'kaydet') {
        $gonderen = trim((string) ($_POST['gonderen'] ?? ''));
        $host     = trim((string) ($_POST['host'] ?? ''));
        $port     = (int) ($_POST['port'] ?? 465);

        if ($gonderen !== '' && !filter_var($gonderen, FILTER_VALIDATE_EMAIL)) {
            $hata = 'Gönderen adresi geçerli değil.';
        } elseif ($host !== '' && !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            $hata = 'SMTP sunucusu yalnızca alan adı olmalı (örnek: mail.valentra.com.tr).';
        } elseif ($port < 1 || $port > 65535) {
            $hata = 'Port geçerli değil.';
        } else {
            ayar_yaz('eposta_gonderen', $gonderen);
            ayar_yaz('eposta_smtp_host', $host);
            ayar_yaz('eposta_smtp_port', (string) $port);
            ayar_yaz('eposta_smtp_guvenlik', (string) ($_POST['guvenlik'] ?? 'ssl') === 'tls' ? 'tls' : 'ssl');
            ayar_yaz('eposta_smtp_kullanici', trim((string) ($_POST['kullanici'] ?? '')));

            if ((string) ($_POST['sifre'] ?? '') !== '') {
                ayar_yaz('eposta_smtp_sifre', (string) $_POST['sifre']);
            }

            ayar_yaz('uye_dogrulama_zorunlu', !empty($_POST['zorunlu']) ? '1' : '0');
            $bildirim = 'Kaydedildi.';
        }
    } elseif ($islem === 'dene') {
        $kime = trim((string) ($_POST['kime'] ?? ''));

        try {
            eposta_gonder(
                $kime,
                'Valentra: deneme e-postası',
                "Bu bir deneme e-postasıdır. Valentra e-posta ayarları çalışıyor.\n",
                eposta_sablon('Deneme e-postası', 'Bu e-postayı aldıysanız Valentra e-posta ayarları çalışıyor; üyelik doğrulama ve şifre yenileme e-postaları gönderilebilir.', 'Valentra Network', site_adresi() . '/network', 'Valentra yönetim paneli deneme gönderimi.')
            );
            $bildirim = 'Deneme e-postası ' . $kime . ' adresine gönderildi. Gelen kutusunu (ve istenmeyen klasörünü) kontrol edin.';
        } catch (RuntimeException $e) {
            $hata = 'Gönderilemedi: ' . $e->getMessage();
        }
    }
}

$a = eposta_ayarlari();
$sifreVar = ayar_oku('eposta_smtp_sifre', '') !== '';

require __DIR__ . '/ust.php';
?>

<h1>E-posta</h1>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:16px;">
    <p class="ipucu" style="margin:0;">
        V Network üyelerine <strong>e-posta doğrulama</strong> ve <strong>şifre yenileme</strong> bağlantıları bu ayarlarla gider.
        SMTP sunucusunu boş bırakırsanız barındırmanın kendi postacısı kullanılır; bu çoğu zaman çalışır ama e-postalar istenmeyen klasörüne düşebilir.
        Daha güvenilir teslim için IHS panelinden bir e-posta hesabı açın (örnek: <code>bilgi@valentra.com.tr</code>) ve bilgilerini aşağıya girin.
        Kaydettikten sonra kendinize bir deneme e-postası gönderin.
    </p>
</div>

<form method="post" class="kutu" style="margin-top:16px;display:grid;gap:12px;max-width:640px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
    <input type="hidden" name="islem" value="kaydet">

    <label>Gönderen adresi
        <input type="email" name="gonderen" value="<?= e($a['gonderen']) ?>" placeholder="bilgi@valentra.com.tr">
    </label>
    <label>SMTP sunucusu (boş: sunucunun kendi postacısı)
        <input type="text" name="host" value="<?= e($a['host']) ?>" placeholder="mail.valentra.com.tr">
    </label>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <label>Port
            <input type="number" name="port" value="<?= (int) $a['port'] ?>" min="1" max="65535" style="width:110px;">
        </label>
        <label>Güvenlik
            <select name="guvenlik">
                <option value="ssl"<?= $a['guvenlik'] === 'ssl' ? ' selected' : '' ?>>SSL (genelde 465)</option>
                <option value="tls"<?= $a['guvenlik'] === 'tls' ? ' selected' : '' ?>>STARTTLS (genelde 587)</option>
            </select>
        </label>
    </div>
    <label>Kullanıcı adı (genelde e-posta adresinin kendisi)
        <input type="text" name="kullanici" value="<?= e($a['kullanici']) ?>" autocomplete="off">
    </label>
    <label>Şifre
        <input type="password" name="sifre" placeholder="<?= $sifreVar ? 'Kayıtlı — değiştirmek için yeni şifreyi yazın' : '' ?>" autocomplete="new-password">
    </label>
    <label style="display:flex;gap:8px;align-items:flex-start;">
        <input type="checkbox" name="zorunlu" value="1"<?= uye_dogrulama_zorunlu() ? ' checked' : '' ?> style="margin-top:4px;">
        <span><strong>E-posta doğrulaması zorunlu.</strong> İşaretliyse e-postasını doğrulamamış üye yorum, paylaşım ve mesaj yazamaz, yalnızca okur.
        Önce deneme e-postasının geldiğinden emin olun; e-posta gitmezse yeni üyeler hiçbir şey yazamaz.</span>
    </label>
    <div><button type="submit" class="dugme dugme-ana">Kaydet</button></div>
</form>

<form method="post" class="kutu" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;max-width:640px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
    <input type="hidden" name="islem" value="dene">
    <label style="flex:1;min-width:220px;">Deneme e-postası gönder
        <input type="email" name="kime" required placeholder="kendi adresiniz">
    </label>
    <button type="submit" class="dugme">Gönder</button>
</form>

<?php require __DIR__ . '/alt.php'; ?>
