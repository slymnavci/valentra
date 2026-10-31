<?php
declare(strict_types=1);

/**
 * Valentra Network uyeligi: giris, kayit, cikis (31.10.2026).
 *
 *   /uye/giris   uye.php?islem=giris
 *   /uye/kayit   uye.php?islem=kayit
 *   /uye/cikis   uye.php?islem=cikis (yalnizca POST)
 *
 * Sayfalar onbellege girmiyor ve arama motoruna kapali (noindex).
 * Basarili giris/kayit "donus" adresine (site ici yol) geri gonderiyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/yorumlar.php';

$islem = (string) ($_GET['islem'] ?? 'giris');
$islem = in_array($islem, ['giris', 'kayit', 'cikis'], true) ? $islem : 'giris';
$donus = uye_guvenli_donus((string) ($_POST['donus'] ?? $_GET['donus'] ?? '')) ?: network_yolu();
$hata  = '';
$ad    = '';
$eposta = '';

header('Cache-Control: no-store');

if ($islem === 'cikis') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && uye_form_dogrula($_POST['jeton'] ?? null)) {
        uye_cikis();
    }

    header('Location: ' . $donus, true, 303);
    exit;
}

// Giris yapmis uye giris/kayit sayfasina gelirse dogrudan donuyor.
if (aktif_uye() !== null && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $donus, true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $eposta = trim((string) ($_POST['eposta'] ?? ''));
    $sifre  = (string) ($_POST['sifre'] ?? '');

    if (!uye_form_dogrula($_POST['jeton'] ?? null)) {
        $hata = 'Sayfanın süresi doldu; lütfen yeniden deneyin.';
    } elseif ((string) ($_POST['web'] ?? '') !== '') {
        // Bal kupu: gorunmeyen alani yalnizca botlar dolduruyor.
        $hata = 'İşlem tamamlanamadı.';
    } elseif ($islem === 'kayit') {
        $ad = (string) ($_POST['ad'] ?? '');

        if (empty($_POST['kurallar'])) {
            $hata = 'Üye olmak için yorum kurallarını ve aydınlatma metnini kabul etmelisiniz.';
        } elseif ($sifre !== (string) ($_POST['sifre2'] ?? '')) {
            $hata = 'Şifreler birbirini tutmuyor.';
        } else {
            try {
                uye_oturum_ac(uye_kaydet($ad, $eposta, $sifre));
                header('Location: ' . $donus, true, 303);
                exit;
            } catch (InvalidArgumentException $e) {
                $hata = $e->getMessage();
            }
        }
    } else {
        try {
            $uyeId = uye_dogrula($eposta, $sifre);

            if ($uyeId !== null) {
                uye_oturum_ac($uyeId);
                header('Location: ' . $donus, true, 303);
                exit;
            }

            // Kaba kuvvet denemelerini yavaslat.
            usleep(600000);
            $hata = 'E-posta ya da şifre hatalı.';
        } catch (InvalidArgumentException $e) {
            $hata = $e->getMessage();
        }
    }
}

$kayit         = $islem === 'kayit';
$aktifKategori = 'network';
$sayfaBasligi  = ($kayit ? 'Üye Ol' : 'Giriş Yap') . ' — Valentra Network';
$sayfaAciklama = 'Valentra Network üyeliği: haberlere yorum yapın, gündemi tartışın.';
$seoAdres      = site_adresi() . uye_yolu($islem);
$seoRobots     = 'noindex, nofollow';
$jeton         = uye_form_jetonu();

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="uye-sayfa">
    <div class="uye-kutu">
        <a class="nw-marka" href="<?= e(network_yolu()) ?>"><?= network_logo_html(64) ?></a>
        <h1><?= $kayit ? 'Üye olun' : 'Giriş yapın' ?></h1>
        <p class="uye-alt"><?= $kayit
            ? 'Ücretsiz üyelikle haberlere yorum yapabilir, gündemi tartışabilirsiniz.'
            : 'Haberlere yorum yapmak için hesabınıza girin.' ?></p>

        <?php if ($hata !== ''): ?>
            <p class="yorum-sonuc yorum-sonuc-hata" role="alert"><?= e($hata) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= e(uye_yolu($islem)) ?>" class="uye-form">
            <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
            <input type="hidden" name="donus" value="<?= e($donus) ?>">
            <div class="bal-kupu" aria-hidden="true"><label>Web sitesi <input type="text" name="web" tabindex="-1" autocomplete="off"></label></div>

            <?php if ($kayit): ?>
                <label>Adınız (yorumlarda görünür)
                    <input type="text" name="ad" value="<?= e($ad) ?>" required minlength="<?= UYE_AD_EN_AZ ?>" maxlength="<?= UYE_AD_EN_FAZLA ?>" autocomplete="nickname">
                </label>
            <?php endif; ?>

            <label>E-posta
                <input type="email" name="eposta" value="<?= e($eposta) ?>" required maxlength="190" autocomplete="email">
            </label>

            <label>Şifre<?= $kayit ? ' (en az ' . UYE_SIFRE_EN_AZ . ' karakter)' : '' ?>
                <input type="password" name="sifre" required <?= $kayit ? 'minlength="' . UYE_SIFRE_EN_AZ . '" autocomplete="new-password"' : 'autocomplete="current-password"' ?>>
            </label>

            <?php if ($kayit): ?>
                <label>Şifre (tekrar)
                    <input type="password" name="sifre2" required minlength="<?= UYE_SIFRE_EN_AZ ?>" autocomplete="new-password">
                </label>

                <label class="uye-onay">
                    <input type="checkbox" name="kurallar" value="1" required>
                    <span>
                        Yorumlarımda küfür, hakaret, suçlama ve nefret söylemi kullanmayacağımı;
                        kurallara uymayan yorumların kaldırılabileceğini ve üyeliğimin durdurulabileceğini kabul ediyorum.
                        Kişisel verilerim (ad, e-posta, IP adresi) yalnızca üyelik ve yasal yükümlülükler için saklanır.
                    </span>
                </label>
            <?php endif; ?>

            <button type="submit" class="uye-dugme"><?= $kayit ? 'Üye ol' : 'Giriş yap' ?></button>
        </form>

        <p class="uye-gecis">
            <?php if ($kayit): ?>
                Zaten üye misiniz? <a href="<?= e(uye_yolu('giris', $donus)) ?>">Giriş yapın</a>
            <?php else: ?>
                Hesabınız yok mu? <a href="<?= e(uye_yolu('kayit', $donus)) ?>">Ücretsiz üye olun</a>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
