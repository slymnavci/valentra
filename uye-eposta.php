<?php
declare(strict_types=1);

/**
 * Uyelik e-posta sayfalari (31.10.2026; bkz. includes/uye_eposta.php):
 *
 *   /uye/sifre            sifremi unuttum: e-posta adresi -> yenileme baglantisi
 *   /uye/yenile?t=...     yeni sifre (baglanti 1 saat, tek kullanimlik)
 *   /uye/dogrula?t=...    e-posta dogrulamasi; POST: dogrulama e-postasini
 *                         yeniden gonder (giris yapmis uye)
 *
 * Sayfalar onbellege girmiyor ve arama motoruna kapali.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/yorumlar.php';
require_once __DIR__ . '/includes/uye_eposta.php';

header('Cache-Control: no-store');

$islem  = (string) ($_GET['islem'] ?? 'sifre');
$islem  = in_array($islem, ['sifre', 'yenile', 'dogrula'], true) ? $islem : 'sifre';
$anahtar = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$post   = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$hata   = '';
$tamam  = '';
$eposta = '';

if ($post && !uye_form_dogrula($_POST['jeton'] ?? null)) {
    $hata = 'Sayfanın süresi doldu; lütfen yeniden deneyin.';
} elseif ($islem === 'sifre' && $post) {
    $eposta = trim((string) ($_POST['eposta'] ?? ''));

    if (!filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
        $hata = 'Geçerli bir e-posta adresi yazın.';
    } else {
        try {
            uye_sifre_baglantisi_gonder($eposta);
        } catch (InvalidArgumentException | RuntimeException $e) {
            // Gonderim hatasi ve siklik siniri da ayni mesajla: farkli mesaj,
            // adresin kayitli oldugunu ele verirdi. Hata kayitta; panelden
            // deneme e-postasiyla gorulur.
            error_log('[valentra] sifre e-postasi gonderilemedi: ' . $e->getMessage());
        }

        // Adres kayitli olsun olmasin ayni mesaj (bkz. uye_sifre_baglantisi_gonder).
        if ($hata === '') {
            $tamam = 'Bu adresle bir üyelik varsa şifre yenileme bağlantısı gönderildi. Gelen kutunuzu (ve istenmeyen klasörünü) kontrol edin; bağlantı 1 saat geçerlidir.';
        }
    }
} elseif ($islem === 'yenile' && $post) {
    if ((string) ($_POST['sifre'] ?? '') !== (string) ($_POST['sifre2'] ?? '')) {
        $hata = 'Şifreler birbirini tutmuyor.';
    } else {
        try {
            uye_sifre_yenile($anahtar, (string) ($_POST['sifre'] ?? ''));
            header('Location: ' . network_yolu(), true, 303);
            exit;
        } catch (InvalidArgumentException $e) {
            $hata = $e->getMessage();
        }
    }
} elseif ($islem === 'yenile' && uye_jeton_coz($anahtar, 'sifre', false) === null) {
    $hata = 'Bağlantının süresi dolmuş ya da daha önce kullanılmış. Yeni bir bağlantı isteyin.';
} elseif ($islem === 'dogrula' && $post) {
    $uye = aktif_uye();

    if ($uye === null) {
        header('Location: ' . uye_yolu('giris', '/uye/dogrula'), true, 303);
        exit;
    }

    try {
        uye_dogrulama_gonder((int) $uye['id']);
        $tamam = 'Doğrulama bağlantısı ' . $uye['eposta'] . ' adresine gönderildi. Gelen kutunuzu (ve istenmeyen klasörünü) kontrol edin.';
    } catch (InvalidArgumentException $e) {
        $hata = $e->getMessage();
    } catch (RuntimeException $e) {
        error_log('[valentra] dogrulama e-postasi gonderilemedi: ' . $e->getMessage());
        $hata = 'E-posta şu anda gönderilemiyor; lütfen daha sonra yeniden deneyin.';
    }
} elseif ($islem === 'dogrula' && $anahtar !== '') {
    if (uye_dogrula_jetonla($anahtar)) {
        $tamam = 'E-posta adresiniz doğrulandı. Teşekkürler!';
    } else {
        $hata = 'Doğrulama bağlantısının süresi dolmuş ya da daha önce kullanılmış.';
    }
}

$basliklar = ['sifre' => 'Şifremi unuttum', 'yenile' => 'Yeni şifre', 'dogrula' => 'E-posta doğrulama'];
$aktifKategori = 'network';
$sayfaBasligi  = $basliklar[$islem] . ' — Valentra Network';
$sayfaAciklama = 'Valentra Network üyeliği.';
$seoAdres      = site_adresi() . '/uye/' . $islem;
$seoRobots     = 'noindex, nofollow';
$jeton         = uye_form_jetonu();
$uye           = aktif_uye();

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="uye-sayfa">
    <div class="uye-kutu">
        <a class="nw-marka" href="<?= e(network_yolu()) ?>"><?= network_logo_html(64) ?></a>
        <h1><?= e($basliklar[$islem]) ?></h1>

        <?php if ($hata !== ''): ?>
            <p class="yorum-sonuc yorum-sonuc-hata" role="alert"><?= e($hata) ?></p>
        <?php endif; ?>
        <?php if ($tamam !== ''): ?>
            <p class="yorum-sonuc yorum-sonuc-basari" role="status"><?= e($tamam) ?></p>
        <?php endif; ?>

        <?php if ($islem === 'sifre' && $tamam === ''): ?>
            <p class="uye-alt">Üye olurken kullandığınız e-posta adresini yazın; şifrenizi yenilemeniz için bir bağlantı gönderelim.</p>
            <form method="post" action="/uye/sifre" class="uye-form">
                <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
                <label>E-posta
                    <input type="email" name="eposta" value="<?= e($eposta) ?>" required maxlength="190" autocomplete="email">
                </label>
                <button type="submit" class="uye-dugme">Bağlantı gönder</button>
            </form>
        <?php elseif ($islem === 'yenile' && ($hata === '' || $post)): ?>
            <p class="uye-alt">Yeni şifrenizi belirleyin. Bütün cihazlardaki oturumlarınız kapanır, bu cihazda oturumunuz açılır.</p>
            <form method="post" action="/uye/yenile" class="uye-form">
                <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
                <input type="hidden" name="t" value="<?= e($anahtar) ?>">
                <label>Yeni şifre (en az <?= UYE_SIFRE_EN_AZ ?> karakter)
                    <input type="password" name="sifre" required minlength="<?= UYE_SIFRE_EN_AZ ?>" autocomplete="new-password">
                </label>
                <label>Yeni şifre (tekrar)
                    <input type="password" name="sifre2" required minlength="<?= UYE_SIFRE_EN_AZ ?>" autocomplete="new-password">
                </label>
                <button type="submit" class="uye-dugme">Şifremi değiştir</button>
            </form>
        <?php elseif ($islem === 'dogrula' && $uye !== null && empty($uye['eposta_dogrulandi']) && $tamam === ''): ?>
            <p class="uye-alt"><?= e((string) $uye['eposta']) ?> adresine doğrulama bağlantısı gönderebiliriz.</p>
            <form method="post" action="/uye/dogrula" class="uye-form">
                <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
                <button type="submit" class="uye-dugme">Doğrulama bağlantısı gönder</button>
            </form>
        <?php endif; ?>

        <p class="uye-gecis">
            <?php if ($islem === 'yenile' && $hata !== '' && !$post): ?>
                <a href="/uye/sifre">Yeni bağlantı iste</a> ·
            <?php endif; ?>
            <a href="<?= e($uye !== null ? network_yolu() : uye_yolu('giris')) ?>"><?= $uye !== null ? 'V Network\'e dön' : 'Giriş sayfasına dön' ?></a>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
