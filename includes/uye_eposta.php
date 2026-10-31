<?php
declare(strict_types=1);

/**
 * Uyelik e-postalari: e-posta dogrulamasi ve "sifremi unuttum"
 * (31.10.2026, site sahibinin secimi). Gonderim includes/eposta.php.
 *
 * Baglantidaki anahtar rastgele 64 karakter; veritabaninda yalnizca
 * SHA-256 ozeti (uye_jetonlari). Dogrulama baglantisi 3 gun, sifre
 * sifirlama 1 saat gecerli ve tek kullanimlik. Ayni uyeye 10 dakikada en
 * fazla 3 e-posta.
 *
 * DOGRULAMA ZORUNLULUGU panelden (admin/eposta.php, uye_dogrulama_zorunlu):
 * aciksa e-postasini dogrulamamis uye yorum, paylasim ve mesaj yazamaz,
 * yalnizca okur. Kapaliyken (varsayilan) dogrulama e-postasi yine gider
 * ama zorunlu degil: e-posta gonderimi panelden sinanmadan acilirsa yeni
 * uyeler hicbir sey yazamaz hale gelirdi.
 */

require_once __DIR__ . '/eposta.php';
require_once __DIR__ . '/uyelik.php';

const UYE_JETON_DOGRULAMA_SAAT = 72;
const UYE_JETON_SIFRE_DAKIKA   = 60;
const UYE_EPOSTA_SINIRI        = 3;

function uye_dogrulama_zorunlu(): bool
{
    return ayar_oku('uye_dogrulama_zorunlu', '0') === '1';
}

/** Uye yazabilir mi? Degilse ziyaretciye gosterilecek aciklama. */
function uye_yazma_engeli(array $uye): ?string
{
    if (uye_dogrulama_zorunlu() && empty($uye['eposta_dogrulandi'])) {
        return 'Yazabilmek için önce e-posta adresinizi doğrulayın. Doğrulama bağlantısı e-posta kutunuzda; gelmediyse yeniden gönderebilirsiniz.';
    }

    return null;
}

/** Yeni anahtar uretir, ozetini kaydeder, anahtarin kendisini dondurur. */
function uye_jeton_uret(int $uyeId, string $tur): string
{
    $say = db()->prepare('SELECT COUNT(*) FROM uye_jetonlari WHERE uye_id = :u AND olusturuldu > NOW() - INTERVAL 10 MINUTE');
    $say->execute(['u' => $uyeId]);

    if ((int) $say->fetchColumn() >= UYE_EPOSTA_SINIRI) {
        throw new InvalidArgumentException('Çok sık e-posta istendi; birkaç dakika sonra yeniden deneyin.');
    }

    // Ayni turden eski anahtarlar gecersiz (yalnizca son gonderilen calissin).
    db()->prepare('DELETE FROM uye_jetonlari WHERE uye_id = :u AND tur = :t')->execute(['u' => $uyeId, 't' => $tur]);

    $anahtar = bin2hex(random_bytes(32));
    $dakika  = $tur === 'sifre' ? UYE_JETON_SIFRE_DAKIKA : UYE_JETON_DOGRULAMA_SAAT * 60;

    db()->prepare('INSERT INTO uye_jetonlari (anahtar, uye_id, tur, bitis) VALUES (:a, :u, :t, NOW() + INTERVAL ' . $dakika . ' MINUTE)')
        ->execute(['a' => hash('sha256', $anahtar), 'u' => $uyeId, 't' => $tur]);

    return $anahtar;
}

/** Gecerli anahtarin uyesi; anahtar tek kullanimlik, $tuket ile siliniyor. */
function uye_jeton_coz(string $anahtar, string $tur, bool $tuket): ?int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $anahtar)) {
        return null;
    }

    $ozet  = hash('sha256', $anahtar);
    $ifade = db()->prepare("SELECT j.uye_id FROM uye_jetonlari j JOIN uyeler u ON u.id = j.uye_id
                             WHERE j.anahtar = :a AND j.tur = :t AND j.bitis > NOW() AND u.durum = 'aktif'");
    $ifade->execute(['a' => $ozet, 't' => $tur]);
    $uyeId = $ifade->fetchColumn();

    if ($uyeId === false) {
        return null;
    }

    if ($tuket) {
        db()->prepare('DELETE FROM uye_jetonlari WHERE anahtar = :a')->execute(['a' => $ozet]);
    }

    return (int) $uyeId;
}

/** Dogrulama e-postasi (kayittan sonra ve "yeniden gonder" ile). */
function uye_dogrulama_gonder(int $uyeId): void
{
    $ifade = db()->prepare('SELECT ad, eposta, eposta_dogrulandi FROM uyeler WHERE id = :id');
    $ifade->execute(['id' => $uyeId]);
    $uye = $ifade->fetch();

    if (!is_array($uye) || !empty($uye['eposta_dogrulandi'])) {
        return;
    }

    $adres = site_adresi() . '/uye/dogrula?t=' . uye_jeton_uret($uyeId, 'dogrulama');
    $ad    = (string) $uye['ad'];

    eposta_gonder(
        (string) $uye['eposta'],
        'Valentra Network: e-posta adresinizi doğrulayın',
        "Merhaba {$ad},\n\nValentra Network üyeliğinizi tamamlamak için e-posta adresinizi doğrulayın:\n{$adres}\n\nBağlantı 3 gün geçerlidir. Bu üyeliği siz açmadıysanız bu e-postayı yok sayabilirsiniz.\n\nValentra",
        eposta_sablon(
            'Merhaba ' . $ad . ',',
            'Valentra Network üyeliğinizi tamamlamak için aşağıdaki düğmeyle e-posta adresinizi doğrulayın. Bağlantı 3 gün geçerlidir.',
            'E-postamı doğrula',
            $adres,
            'Bu üyeliği siz açmadıysanız bu e-postayı yok sayabilirsiniz. Düğme çalışmazsa bu adresi tarayıcınıza yapıştırın:'
        )
    );
}

function uye_dogrula_jetonla(string $anahtar): bool
{
    $uyeId = uye_jeton_coz($anahtar, 'dogrulama', true);

    if ($uyeId === null) {
        return false;
    }

    db()->prepare('UPDATE uyeler SET eposta_dogrulandi = COALESCE(eposta_dogrulandi, NOW()) WHERE id = :id')->execute(['id' => $uyeId]);

    return true;
}

/**
 * "Sifremi unuttum": adres kayitliysa sifirlama baglantisi gider. Kayitli
 * degilse de ayni sonuc gosterilir (hangi adresin uye oldugu
 * ogrenilemesin); cagiran her durumda ayni mesaji gosterir.
 */
function uye_sifre_baglantisi_gonder(string $eposta): void
{
    $ifade = db()->prepare("SELECT id, ad, eposta FROM uyeler WHERE eposta = :e AND durum = 'aktif'");
    $ifade->execute(['e' => mb_strtolower(trim($eposta), 'UTF-8')]);
    $uye = $ifade->fetch();

    if (!is_array($uye)) {
        // Zamanlama farki da adresin varligini ele vermesin.
        usleep(random_int(300000, 700000));

        return;
    }

    $adres = site_adresi() . '/uye/yenile?t=' . uye_jeton_uret((int) $uye['id'], 'sifre');
    $ad    = (string) $uye['ad'];

    eposta_gonder(
        (string) $uye['eposta'],
        'Valentra Network: şifre yenileme',
        "Merhaba {$ad},\n\nŞifrenizi yenilemek için bu bağlantıyı açın:\n{$adres}\n\nBağlantı 1 saat geçerlidir ve bir kez kullanılabilir. Bu isteği siz yapmadıysanız bu e-postayı yok sayın; şifreniz değişmez.\n\nValentra",
        eposta_sablon(
            'Merhaba ' . $ad . ',',
            'Şifrenizi yenilemek için aşağıdaki düğmeyi kullanın. Bağlantı 1 saat geçerlidir ve bir kez kullanılabilir.',
            'Şifremi yenile',
            $adres,
            'Bu isteği siz yapmadıysanız bu e-postayı yok sayın; şifreniz değişmez. Düğme çalışmazsa bu adresi tarayıcınıza yapıştırın:'
        )
    );
}

/** Yeni sifre: anahtari tuketir, butun oturumlari kapatir, uyeyi oturuma alir. */
function uye_sifre_yenile(string $anahtar, string $sifre): void
{
    if (mb_strlen($sifre, 'UTF-8') < UYE_SIFRE_EN_AZ) {
        throw new InvalidArgumentException('Şifre en az ' . UYE_SIFRE_EN_AZ . ' karakter olmalı.');
    }

    $uyeId = uye_jeton_coz($anahtar, 'sifre', true);

    if ($uyeId === null) {
        throw new InvalidArgumentException('Bağlantının süresi dolmuş ya da daha önce kullanılmış. Yeni bir bağlantı isteyin.');
    }

    // Baglanti e-postaya geldigine gore adres de dogrulanmis sayilir.
    db()->prepare('UPDATE uyeler SET sifre = :s, eposta_dogrulandi = COALESCE(eposta_dogrulandi, NOW()) WHERE id = :id')
        ->execute(['s' => password_hash($sifre, PASSWORD_DEFAULT), 'id' => $uyeId]);
    db()->prepare('DELETE FROM uye_oturumlari WHERE uye_id = :id')->execute(['id' => $uyeId]);
    uye_oturum_ac($uyeId);
}
