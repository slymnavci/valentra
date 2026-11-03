<?php
declare(strict_types=1);

/**
 * Telegram kanalı ayarları (03.11.2026; bkz. includes/telegram.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/telegram.php';

giris_zorunlu();

$panelBasligi = 'Telegram';
$bildirim = '';
$hata     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);
    $islem = (string) ($_POST['islem'] ?? 'kaydet');

    if ($islem === 'kaydet') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $kanal = trim((string) ($_POST['kanal'] ?? ''));

        if ($token !== '' && preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
            $hata = 'Bot anahtarı geçerli görünmüyor. @BotFather\'ın verdiği anahtar "123456789:ABC..." biçimindedir.';
        } elseif ($kanal !== '' && preg_match('~^(@?[A-Za-z0-9_]{5,}|-?\d+|(https?://)?t\.me/[A-Za-z0-9_]{5,}/?)$~', $kanal) !== 1) {
            $hata = 'Kanal adı geçerli değil. Örnek: @valentrahaber';
        } else {
            if ($token !== '') {
                ayar_yaz('telegram_token', $token);
            }

            ayar_yaz('telegram_kanal', $kanal);
            ayar_yaz('telegram_esik', (string) max(0, min(100, (int) ($_POST['esik'] ?? TELEGRAM_VARSAYILAN_ESIK))));
            ayar_yaz('telegram_acik', !empty($_POST['acik']) ? '1' : '0');
            $bildirim = 'Kaydedildi.';
        }
    } elseif ($islem === 'dene') {
        if (!telegram_kurulu()) {
            $hata = 'Önce bot anahtarını ve kanal adını kaydedin.';
        } else {
            $son = db()->query("SELECT baslik, slug, ozet, gorsel_url FROM haberler WHERE durum = 'yayinda' ORDER BY onem DESC, yayin_tarihi DESC LIMIT 1")->fetch();
            $sonuc = $son ? telegram_haber_gonder($son) : ['tamam' => false, 'hata' => 'Yayında haber yok.'];
            $sonuc['tamam'] ? $bildirim = 'Deneme gönderisi kanala gitti: ' . $son['baslik'] : $hata = $sonuc['hata'];
        }
    } elseif ($islem === 'sil') {
        ayar_yaz('telegram_token', '');
        ayar_yaz('telegram_acik', '0');
        $bildirim = 'Bot anahtarı silindi; gönderim kapalı.';
    }
}

$a = telegram_ayarlari();

require __DIR__ . '/ust.php';
?>

<h1>Telegram kanalı</h1>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:16px;max-width:720px;">
    <h2 style="margin-top:0;">Kurulum (bir kez)</h2>
    <ol style="line-height:1.7;margin:0;padding-left:20px;">
        <li>Telegram'da <strong>Yeni kanal</strong> açın (örnek ad: Valentra Haber), kanalı <strong>herkese açık</strong> yapıp bir bağlantı adı seçin (örnek: <code>valentrahaber</code>).</li>
        <li>Telegram'da <strong>@BotFather</strong>'ı açın, <code>/newbot</code> yazın, bota bir ad ve sonu <code>bot</code> ile biten bir kullanıcı adı verin. BotFather size bir <strong>anahtar</strong> (token) verir.</li>
        <li>Kanal ayarları → <strong>Yöneticiler</strong> → Yönetici ekle → botunuzu bulun ve <strong>Mesaj gönderme</strong> iznini açık bırakın.</li>
        <li>Anahtarı ve kanal adını aşağıya girin, <strong>Açık</strong> kutusunu işaretleyip kaydedin; ardından <strong>Deneme gönderisi</strong> ile kontrol edin.</li>
    </ol>
</div>

<form method="post" class="kutu" style="margin-top:16px;display:grid;gap:12px;max-width:720px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
    <input type="hidden" name="islem" value="kaydet">

    <label>Bot anahtarı (token)
        <input type="password" name="token" autocomplete="off" placeholder="<?= $a['token'] !== '' ? 'Kayıtlı — değiştirmek için yeni anahtarı yazın' : '123456789:ABC...' ?>">
    </label>

    <label>Kanal adı
        <input type="text" name="kanal" value="<?= e($a['kanal']) ?>" placeholder="@valentrahaber">
    </label>

    <label>Önem eşiği (0–100): bu puan ve üstündeki haberler ile "son dakika" haberleri gönderilir
        <input type="number" name="esik" min="0" max="100" value="<?= (int) $a['esik'] ?>">
    </label>

    <label style="display:flex;gap:8px;align-items:center;">
        <input type="checkbox" name="acik" value="1" <?= $a['acik'] ? 'checked' : '' ?>> Açık: önemli haberler yayına girince kanala gönderilsin
    </label>

    <p class="ipucu" style="margin:0;">Gece <?= TELEGRAM_SESSIZ_BAS ?>:00–0<?= TELEGRAM_SESSIZ_BIT ?>:00 arası gönderilmez; günde en fazla <?= TELEGRAM_GUNLUK_TAVAN ?> gönderi. Bağlantılarda <code>utm_source=telegram_kanal</code> var: Analytics'te kanaldan gelen okur ayrı görünür.</p>

    <div><button type="submit" class="dugme dugme-ana">Kaydet</button></div>
</form>

<div class="kutu" style="margin-top:16px;max-width:720px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <form method="post" style="margin:0;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="dene">
        <button type="submit" class="dugme">Deneme gönderisi</button>
    </form>
    <?php if ($a['token'] !== ''): ?>
        <form method="post" style="margin:0;" onsubmit="return confirm('Bot anahtarı silinsin mi?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="sil">
            <button type="submit" class="dugme dugme-ikincil">Anahtarı sil</button>
        </form>
    <?php endif; ?>
    <div class="ipucu" style="flex-basis:100%;margin:0;">
        Son gönderi: <?= e(ayar_oku('telegram_son_gonderim') ?: '—') ?><br>
        Son hata: <?= e(ayar_oku('telegram_son_hata') ?: '—') ?>
    </div>
</div>

<?php require __DIR__ . '/alt.php'; ?>
