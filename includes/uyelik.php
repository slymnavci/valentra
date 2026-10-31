<?php
declare(strict_types=1);

/**
 * Valentra Network uyeligi (31.10.2026).
 *
 * Site sahibinin istegi: "Valentra Network'te guncel haberler sirayla
 * dussun; Facebook ve Instagram'daki gibi haberlerin altina kullanicilar
 * yorum yapabilsin; yorum icin uyelik sarti". Tablolar sql/schema.sql
 * (VALENTRA NETWORK).
 *
 * OTURUM: PHP oturumu (session) yonetici paneline ayrilmis (valentra_admin
 * cerezi); uyeler ayri bir cerezle (valentra_uye) taniniyor. Cerezde
 * rastgele 64 karakterlik bir anahtar, veritabaninda yalnizca SHA-256
 * ozeti. 60 gun gecerli. Cerez varken sayfa onbellegi devre disi (bkz.
 * includes/sayfa_onbellek.php): uyeye kendi adi ve yorum kutusu gorunsun.
 *
 * CSRF: oturum olmadigi icin form jetonu oturum anahtarindan turetiliyor
 * (HMAC). Girissiz formlarda (kayit, giris) cerez yok; bunlarda ayri bir
 * on-jeton cerezi (valentra_uye_on) kullaniliyor.
 *
 * E-posta dogrulamasi simdilik yok (site e-posta gondermiyor). Sifresini
 * unutan uyenin sifresi panelden sifirlanmiyor; yeni hesap acabilir ya da
 * site yonetimine yazar. Dogrulama eklenince burada.
 *
 * RUMUZ (31.10.2026, site sahibi: "uyelerin isimleri yerine rumuzlari
 * gorunsun"): uyeler.ad sutunu artik rumuz; sitede her yerde gorunen tek
 * ad o. Bosluksuz, 3-20 karakter, buyuk-kucuk harf farki gozetmeden
 * benzersiz; giris e-posta ya da rumuzla. Bazi rumuzlar (admin, yonetici,
 * valentra...) uyelere kapali, yalnizca panelden yonetici hesabina.
 *
 * YONETICI (uyeler.rol = 'yonetici'): V Network'te her paylasimi ve yorumu
 * silebilir, uye durdurabilir, takiplesme sarti olmadan herkese mesaj
 * yazabilir; profilinde "Yonetici" rozeti var. Panelden atanir
 * (admin/yorumlar.php, Uyeler sekmesi); sifre depoda tutulmuyor.
 */

const UYE_CEREZI        = 'valentra_uye';
const UYE_ON_CEREZI     = 'valentra_uye_on';
const UYE_OTURUM_GUN    = 60;
const UYE_SIFRE_EN_AZ   = 8;
/** Rumuz uzunlugu (uyeler.ad; bkz. dosya basi). */
const UYE_AD_EN_AZ      = 3;
const UYE_AD_EN_FAZLA   = 20;
/** Uyelerin alamadigi rumuzlar (kucuk harfle); yonetici hesabi panelden alabilir. */
const UYE_AYRILMIS_RUMUZLAR = [
    'admin', 'administrator', 'yonetici', 'yönetici', 'moderator', 'moderatör',
    'valentra', 'valhaber', 'valspor', 'valpapel', 'valmagazin', 'vnetwork',
    'destek', 'iletisim', 'site', 'sistem', 'root',
];
/** Ayni IP adresinden bir saatte en fazla bu kadar kayit (bot korumasi). */
const UYE_SAATLIK_KAYIT = 5;

function uye_cerez_secenekleri(int $bitis): array
{
    $guvenli = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return ['expires' => $bitis, 'path' => '/', 'secure' => $guvenli, 'httponly' => true, 'samesite' => 'Lax'];
}

/** Giris yapmis uye; yoksa null. Istek boyunca bir kez sorgulaniyor. */
function aktif_uye(): ?array
{
    static $uye = false;

    if ($uye !== false) {
        return $uye;
    }

    $uye    = null;
    $cerez  = (string) ($_COOKIE[UYE_CEREZI] ?? '');

    if (!preg_match('/^[0-9a-f]{64}$/', $cerez)) {
        return null;
    }

    try {
        $ifade = db()->prepare(
            "SELECT u.id, u.ad, u.eposta, u.durum, u.rol, u.foto, u.eposta_dogrulandi
               FROM uye_oturumlari o
               JOIN uyeler u ON u.id = o.uye_id
              WHERE o.anahtar = :a AND o.bitis > NOW() AND u.durum = 'aktif'"
        );
        $ifade->execute(['a' => hash('sha256', $cerez)]);
        $satir = $ifade->fetch();
        $uye   = is_array($satir) ? $satir : null;
    } catch (PDOException $e) {
        // Tablo henuz yoksa (sema yukseltmesi bekliyor) site dusmesin.
        $uye = null;
    }

    return $uye;
}

/** Uyeyi oturuma alir: yeni anahtar, cerez. */
function uye_oturum_ac(int $uyeId): void
{
    $anahtar = bin2hex(random_bytes(32));
    $bitis   = time() + UYE_OTURUM_GUN * 86400;

    db()->prepare('INSERT INTO uye_oturumlari (anahtar, uye_id, bitis) VALUES (:a, :u, FROM_UNIXTIME(:b))')
        ->execute(['a' => hash('sha256', $anahtar), 'u' => $uyeId, 'b' => $bitis]);
    db()->prepare('UPDATE uyeler SET son_giris = NOW() WHERE id = :id')->execute(['id' => $uyeId]);

    // Suresi dolmus oturumlar ara sira temizleniyor.
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM uye_oturumlari WHERE bitis < NOW()');
    }

    setcookie(UYE_CEREZI, $anahtar, uye_cerez_secenekleri($bitis));
    $_COOKIE[UYE_CEREZI] = $anahtar;
}

function uye_cikis(): void
{
    $cerez = (string) ($_COOKIE[UYE_CEREZI] ?? '');

    if (preg_match('/^[0-9a-f]{64}$/', $cerez)) {
        db()->prepare('DELETE FROM uye_oturumlari WHERE anahtar = :a')->execute(['a' => hash('sha256', $cerez)]);
    }

    setcookie(UYE_CEREZI, '', uye_cerez_secenekleri(time() - 3600));
    unset($_COOKIE[UYE_CEREZI]);
}

/**
 * Form jetonu. Giris yapmis uyede oturum anahtarindan, girissizde on-jeton
 * cerezinden turetiliyor; ikisi de baska bir sitenin bilemeyecegi degerler.
 */
function uye_form_jetonu(): string
{
    $kaynak = (string) ($_COOKIE[UYE_CEREZI] ?? '');

    if (!preg_match('/^[0-9a-f]{64}$/', $kaynak)) {
        $kaynak = (string) ($_COOKIE[UYE_ON_CEREZI] ?? '');

        if (!preg_match('/^[0-9a-f]{32}$/', $kaynak)) {
            $kaynak = bin2hex(random_bytes(16));
            setcookie(UYE_ON_CEREZI, $kaynak, uye_cerez_secenekleri(0));
            $_COOKIE[UYE_ON_CEREZI] = $kaynak;
        }
    }

    return hash_hmac('sha256', 'uye-form|' . $kaynak, uye_gizli_tuz());
}

function uye_form_dogrula(?string $gelen): bool
{
    return is_string($gelen) && $gelen !== '' && hash_equals(uye_form_jetonu(), $gelen);
}

/** Jetonlar icin sunucuya ozgu tuz (ayarlarda bir kez uretiliyor). */
function uye_gizli_tuz(): string
{
    require_once __DIR__ . '/ayarlar.php';

    $tuz = ayar_oku('uye_tuz', '');

    if (strlen($tuz) < 32) {
        $tuz = bin2hex(random_bytes(32));
        ayar_yaz('uye_tuz', $tuz);
    }

    return $tuz;
}

/** Giris yapmis uye (ya da verilen satir) V Network yoneticisi mi? */
function uye_yonetici_mi(?array $uye = null): bool
{
    $uye ??= aktif_uye();

    return ($uye['rol'] ?? '') === 'yonetici';
}

/**
 * Rumuzu sinar; temiz rumuzu dondurur ya da InvalidArgumentException.
 *
 * $haricId: profilini duzenleyen uyenin kendi kaydi (kendi rumuzuyla
 * cakismasin). $yonetici: ayrilmis rumuzlar serbest (panelden).
 */
function uye_rumuz_sina(string $rumuz, int $haricId = 0, bool $yonetici = false): string
{
    $rumuz   = trim($rumuz);
    $uzunluk = mb_strlen($rumuz, 'UTF-8');

    if ($uzunluk < UYE_AD_EN_AZ || $uzunluk > UYE_AD_EN_FAZLA || !preg_match('/^[\p{L}\p{M}0-9._-]+$/u', $rumuz)) {
        throw new InvalidArgumentException('Rumuz ' . UYE_AD_EN_AZ . '-' . UYE_AD_EN_FAZLA . ' karakter olmalı; boşluksuz, harf, rakam, nokta, alt çizgi ve tire kullanabilirsiniz.');
    }

    if (yorum_uygunsuz_mu($rumuz)
        || (!$yonetici && in_array(mb_strtolower($rumuz, 'UTF-8'), UYE_AYRILMIS_RUMUZLAR, true))) {
        throw new InvalidArgumentException('Bu rumuz kullanılamıyor; başka bir rumuz seçin.');
    }

    // Harmanlama (utf8mb4_unicode_ci) buyuk-kucuk harfi ayirmiyor: "Deniz" ile "deniz" ayni.
    $var = db()->prepare('SELECT 1 FROM uyeler WHERE ad = :ad AND id <> :id LIMIT 1');
    $var->execute(['ad' => $rumuz, 'id' => $haricId]);

    if ($var->fetchColumn()) {
        throw new InvalidArgumentException('Bu rumuz başka bir üyede; başka bir rumuz seçin.');
    }

    return $rumuz;
}

/**
 * Yeni uye. Basariliysa uye kimligi; degilse InvalidArgumentException
 * (mesaj ziyaretciye gosterilecek Turkce aciklama). $ad rumuz.
 */
function uye_kaydet(string $ad, string $eposta, string $sifre): int
{
    $eposta = mb_strtolower(trim($eposta), 'UTF-8');
    $ad     = uye_rumuz_sina($ad);

    if (!filter_var($eposta, FILTER_VALIDATE_EMAIL) || strlen($eposta) > 190) {
        throw new InvalidArgumentException('Geçerli bir e-posta adresi yazın.');
    }

    if (mb_strlen($sifre, 'UTF-8') < UYE_SIFRE_EN_AZ) {
        throw new InvalidArgumentException('Şifre en az ' . UYE_SIFRE_EN_AZ . ' karakter olmalı.');
    }

    $ip    = function_exists('ziyaret_adresi') ? ziyaret_adresi() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $sayac = db()->prepare('SELECT COUNT(*) FROM uyeler WHERE kayit_ip = :ip AND olusturuldu > NOW() - INTERVAL 1 HOUR');
    $sayac->execute(['ip' => $ip]);

    if ((int) $sayac->fetchColumn() >= UYE_SAATLIK_KAYIT) {
        throw new InvalidArgumentException('Bu bağlantıdan çok fazla kayıt yapıldı; bir saat sonra yeniden deneyin.');
    }

    try {
        db()->prepare('INSERT INTO uyeler (ad, eposta, sifre, kayit_ip) VALUES (:ad, :e, :s, :ip)')
            ->execute(['ad' => $ad, 'e' => $eposta, 's' => password_hash($sifre, PASSWORD_DEFAULT), 'ip' => mb_substr($ip, 0, 45)]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            throw new InvalidArgumentException('Bu e-posta adresiyle zaten bir üyelik var; giriş yapabilirsiniz.');
        }

        throw $e;
    }

    return (int) db()->lastInsertId();
}

/**
 * E-posta ya da rumuz ve sifre dogruysa uye kimligi; degilse null.
 *
 * Rumuz benzersizligi 31.10.2026'dan once yoktu; ayni ada sahip birden cok
 * eski hesap olabilir, sifresi tutan aliniyor.
 */
function uye_dogrula(string $giris, string $sifre): ?int
{
    $giris = trim($giris);
    $ifade = db()->prepare('SELECT id, sifre, durum FROM uyeler WHERE eposta = :e OR ad = :r ORDER BY id');
    $ifade->execute(['e' => mb_strtolower($giris, 'UTF-8'), 'r' => $giris]);
    $uye = null;

    foreach ($ifade->fetchAll() as $aday) {
        if (password_verify($sifre, (string) $aday['sifre'])) {
            $uye = $aday;
            break;
        }
    }

    if ($uye === null) {
        return null;
    }

    if ($uye['durum'] !== 'aktif') {
        throw new InvalidArgumentException('Bu üyelik site yönetimi tarafından durdurulmuş.');
    }

    if (password_needs_rehash((string) $uye['sifre'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE uyeler SET sifre = :s WHERE id = :id')
            ->execute(['s' => password_hash($sifre, PASSWORD_DEFAULT), 'id' => (int) $uye['id']]);
    }

    return (int) $uye['id'];
}

/** Uyelik sayfasinin adresi; donus adresi yalnizca site ici yol. */
/**
 * V Network yonetici hesabini olusturur ya da gunceller (yalnizca panelden).
 *
 * Sifre depoya yazilmiyor: panelde giris yapmis site yoneticisi formdan
 * giriyor, veritabaninda yalnizca ozeti duruyor. Ayni rumuzlu hesap varsa
 * sifresi ve rolu guncelleniyor. Giris rumuzla; e-posta alani benzersiz
 * olmak zorunda oldugu icin site icinde kullanilmayan bir adres.
 */
function uye_yonetici_kaydet(string $rumuz, string $sifre): int
{
    $rumuz = trim($rumuz);

    if (mb_strlen($sifre, 'UTF-8') < UYE_SIFRE_EN_AZ) {
        throw new InvalidArgumentException('Şifre en az ' . UYE_SIFRE_EN_AZ . ' karakter olmalı.');
    }

    $var = db()->prepare('SELECT id FROM uyeler WHERE ad = :ad ORDER BY id LIMIT 1');
    $var->execute(['ad' => $rumuz]);
    $id = (int) $var->fetchColumn();
    $ozet = password_hash($sifre, PASSWORD_DEFAULT);

    if ($id > 0) {
        db()->prepare("UPDATE uyeler SET sifre = :s, rol = 'yonetici', durum = 'aktif' WHERE id = :id")
            ->execute(['s' => $ozet, 'id' => $id]);
        // Eski oturumlar kapansin: sifre degisti.
        db()->prepare('DELETE FROM uye_oturumlari WHERE uye_id = :id')->execute(['id' => $id]);

        return $id;
    }

    $rumuz = uye_rumuz_sina($rumuz, 0, true);

    db()->prepare("INSERT INTO uyeler (ad, eposta, sifre, rol, kayit_ip) VALUES (:ad, :e, :s, 'yonetici', 'panel')")
        ->execute(['ad' => $rumuz, 'e' => 'yonetici-' . bin2hex(random_bytes(6)) . '@uye.valentra.invalid', 's' => $ozet]);

    return (int) db()->lastInsertId();
}

function uye_yolu(string $islem = 'giris', string $donus = ''): string
{
    $donus = uye_guvenli_donus($donus);

    return '/uye/' . $islem . ($donus !== '' ? '?donus=' . rawurlencode($donus) : '');
}

/** Acik yonlendirme olmasin: yalnizca "/" ile baslayan, "//" ile baslamayan yol. */
function uye_guvenli_donus(string $donus): string
{
    return preg_match('#^/(?![/\\\\])[^\s]*$#', $donus) ? mb_substr($donus, 0, 300) : '';
}

/**
 * Valentra Network logosu (31.10.2026, site sahibinin verdigi gorsel):
 * kirmizi V ve el yazisi "Network". Gece modunda yazisi acik renk olan
 * kopya (assets/network-logo-koyu.webp); hangisinin gorunecegi CSS'te,
 * elle secilen temayi da izliyor. Metin ekran okuyucu icin.
 */
function network_logo_html(int $yukseklik, string $sinif = ''): string
{
    $genislik = (int) round($yukseklik * 480 / 242);
    $ozellik  = 'width="' . $genislik . '" height="' . $yukseklik . '" alt="" decoding="async"';

    return '<span class="nw-logo ' . e($sinif) . '">'
         . '<img class="nw-logo-acik" src="' . e(varlik('/assets/network-logo.webp')) . '" ' . $ozellik . '>'
         . '<img class="nw-logo-koyu" src="' . e(varlik('/assets/network-logo-koyu.webp')) . '" ' . $ozellik . '>'
         . '<span class="gizli-etiket">VALENTRA Network</span></span>';
}

function network_yolu(int $sayfa = 1): string
{
    return '/network' . ($sayfa > 1 ? '?sayfa=' . $sayfa : '');
}
