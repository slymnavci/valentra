<?php
declare(strict_types=1);

/**
 * GOOGLE ILE GIRIS / UYELIK (13.11.2026).
 *
 * Site sahibinin istegi: "Google ile uye ol gibi secenekleri de koyalim."
 *
 * OAuth 2.0 yetki kodu akisi (OpenID Connect), sunucu tarafinda:
 *   1. /uye-google.php?donus=...   -> Google'in izin sayfasi (state cerezde)
 *   2. Google -> /uye-google.php?code=...&state=...
 *   3. Kod, Google'in jeton ucunda kimlik jetonuyla degisilir (sunucudan,
 *      TLS ile; istemci gizli anahtari tarayiciya hic gitmez).
 *   4. Jetondaki Google kimligi (sub) kayitliysa o uye; degilse ayni
 *      dogrulanmis e-postali uye bu Google hesabina baglanir; o da yoksa
 *      yeni uye acilir (e-postasi dogrulanmis sayilir, sifresi rastgele).
 *
 * KURULUM (panel > Site > Google ile giris): Google Cloud Console'da bir
 * "OAuth istemci kimligi" (Web uygulamasi) acilir; yetkili yonlendirme
 * adresi olarak panelde yazan adres girilir; istemci kimligi ve gizli
 * anahtar panele yazilir. Ikisi yazilmadan dugme sitede GORUNMEZ.
 *
 * Google'dan yalnizca ad, e-posta ve hesap kimligi alinir (openid email
 * profile); kisiler, takvim vb. hicbir izin istenmez.
 */

require_once __DIR__ . '/uyelik.php';
require_once __DIR__ . '/ayarlar.php';

const GOOGLE_GIRIS_ISTEMCI_AYARI = 'google_giris_istemci';
const GOOGLE_GIRIS_GIZLI_AYARI   = 'google_giris_gizli';
const GOOGLE_GIRIS_CEREZI        = 'v_gdurum';

/** Sutun veritabaninda var mi. */
function google_giris_hazir(): bool
{
    static $hazir = null;

    if ($hazir === null) {
        try {
            $hazir = (bool) db()->query("SHOW COLUMNS FROM uyeler LIKE 'google_kimlik'")->fetch();
        } catch (Throwable $e) {
            $hazir = false;
        }
    }

    return $hazir;
}

/** Google ile giris kurulu mu (dugme gosterilsin mi). */
function google_giris_acik(): bool
{
    return google_giris_hazir()
        && ayar_oku(GOOGLE_GIRIS_ISTEMCI_AYARI, '') !== ''
        && ayar_oku(GOOGLE_GIRIS_GIZLI_AYARI, '') !== '';
}

/** Google'a bildirilen yonlendirme adresi (Google Cloud Console'a da bu yazilir). */
function google_giris_donus_adresi(): string
{
    if (!function_exists('site_adresi')) {
        require_once __DIR__ . '/seo.php';
    }

    return rtrim(site_adresi(), '/') . '/uye-google.php';
}

/** Sitedeki "Google ile devam et" baglantisinin adresi. */
function google_giris_yolu(string $donus = ''): string
{
    $donus = uye_guvenli_donus($donus);

    return '/uye-google.php' . ($donus !== '' ? '?donus=' . rawurlencode($donus) : '');
}

/**
 * "Google ile devam et" dugmesi; kurulu degilse bos.
 * Google'in logosu yerine sade "G" harfi: marka gorseli kullanilmiyor.
 */
function google_giris_dugmesi(string $donus = '', string $yazi = 'Google ile devam et'): string
{
    if (!google_giris_acik()) {
        return '';
    }

    return '<a class="uye-dugme uye-dugme-google" href="' . e(google_giris_yolu($donus)) . '" rel="nofollow">'
        . '<span class="uye-google-g" aria-hidden="true">G</span>' . e($yazi) . '</a>';
}

/** 1. adim: state uretir, cereze yazar, Google'in izin adresini dondurur. */
function google_giris_baslat(string $donus): string
{
    $durum = bin2hex(random_bytes(16));
    $deger = $durum . '|' . uye_guvenli_donus($donus);

    setcookie(GOOGLE_GIRIS_CEREZI, $deger, [
        'expires' => time() + 600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => ayar_oku(GOOGLE_GIRIS_ISTEMCI_AYARI, ''),
        'redirect_uri'  => google_giris_donus_adresi(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $durum,
        'prompt'        => 'select_account',
    ]);
}

/**
 * Cerezdeki state ile Google'dan donen state ayni mi; ayniysa donus yolu.
 * Cerez her durumda silinir (tek kullanimlik).
 */
function google_giris_durum_dogrula(string $gelen): ?string
{
    $cerez = (string) ($_COOKIE[GOOGLE_GIRIS_CEREZI] ?? '');
    setcookie(GOOGLE_GIRIS_CEREZI, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);

    [$durum, $donus] = array_pad(explode('|', $cerez, 2), 2, '');

    if ($durum === '' || $gelen === '' || !hash_equals($durum, $gelen)) {
        return null;
    }

    return uye_guvenli_donus($donus);
}

/**
 * 3. adim: yetki kodunu Google'in jeton ucunda kimlik bilgisine cevirir.
 *
 * @return array{sub:string,eposta:string,ad:string} InvalidArgumentException: okura gosterilecek mesaj
 */
function google_giris_kimlik(string $kod, ?callable $istek = null): array
{
    $istek = $istek ?? static function (array $alanlar): array {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($alanlar), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $govde = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$kod, is_string($govde) ? $govde : ''];
    };

    $istemci = ayar_oku(GOOGLE_GIRIS_ISTEMCI_AYARI, '');

    [$durumKodu, $govde] = $istek([
        'code'          => $kod,
        'client_id'     => $istemci,
        'client_secret' => ayar_oku(GOOGLE_GIRIS_GIZLI_AYARI, ''),
        'redirect_uri'  => google_giris_donus_adresi(),
        'grant_type'    => 'authorization_code',
    ]);

    $yanit = json_decode($govde, true);
    $jeton = is_array($yanit) ? (string) ($yanit['id_token'] ?? '') : '';

    if ($durumKodu !== 200 || $jeton === '') {
        // Govde gunluge yazilmaz (jeton tasiyabilir); yalnizca Google'in hata kodu.
        error_log('[valentra] google giris: jeton alinamadi (HTTP ' . $durumKodu . ') '
            . mb_substr(is_array($yanit) ? (string) ($yanit['error'] ?? '') : '', 0, 80));

        throw new InvalidArgumentException('Google ile giriş tamamlanamadı; lütfen yeniden deneyin.');
    }

    /*
     * Kimlik jetonu dogrudan Google'in jeton ucundan, TLS uzerinden alindi;
     * bu yolda imza denetimi gerekmiyor (Google OpenID Connect belgesi).
     * Yine de alici (aud), veren (iss) ve sure denetleniyor.
     */
    $parcalar = explode('.', $jeton);
    $yuk      = count($parcalar) === 3 ? json_decode((string) base64_decode(strtr($parcalar[1], '-_', '+/'), true), true) : null;

    if (!is_array($yuk)
        || (string) ($yuk['aud'] ?? '') !== $istemci
        || !in_array((string) ($yuk['iss'] ?? ''), ['https://accounts.google.com', 'accounts.google.com'], true)
        || (int) ($yuk['exp'] ?? 0) < time() - 60
        || (string) ($yuk['sub'] ?? '') === '') {
        error_log('[valentra] google giris: kimlik jetonu gecersiz');

        throw new InvalidArgumentException('Google ile giriş tamamlanamadı; lütfen yeniden deneyin.');
    }

    $eposta = mb_strtolower(trim((string) ($yuk['email'] ?? '')), 'UTF-8');

    if ($eposta === '' || !filter_var($eposta, FILTER_VALIDATE_EMAIL) || !in_array($yuk['email_verified'] ?? false, [true, 'true'], true)) {
        throw new InvalidArgumentException('Google hesabınızın e-posta adresi doğrulanmamış; önce Google\'da doğrulayın ya da e-postayla üye olun.');
    }

    return ['sub' => mb_substr((string) $yuk['sub'], 0, 40), 'eposta' => mb_substr($eposta, 0, 190), 'ad' => trim((string) ($yuk['name'] ?? ''))];
}

/** Google adindan kullanilabilir, benzersiz bir rumuz uretir. */
function google_giris_rumuz(string $ad, string $eposta): string
{
    $taban = (string) preg_replace('/[^\p{L}\p{M}0-9._-]+/u', '', $ad);
    $taban = $taban !== '' ? $taban : (string) preg_replace('/[^a-z0-9._-]+/i', '', (string) strstr($eposta, '@', true));
    $taban = mb_substr($taban, 0, max(UYE_AD_EN_AZ, UYE_AD_EN_FAZLA - 4), 'UTF-8');

    foreach (array_merge([$taban], array_map(static fn (): string => $taban . random_int(10, 9999), range(1, 6)), ['uye' . random_int(100000, 999999)]) as $aday) {
        try {
            return uye_rumuz_sina($aday);
        } catch (InvalidArgumentException $e) {
            continue;
        }
    }

    return 'uye' . bin2hex(random_bytes(4));
}

/**
 * 4. adim: Google kimligine karsilik gelen uyeyi bulur ya da acar.
 *
 * @param array{sub:string,eposta:string,ad:string} $kimlik
 * @return array{id:int,yeni:bool} InvalidArgumentException: okura gosterilecek mesaj
 */
function google_giris_uye(array $kimlik): array
{
    if (!google_giris_hazir()) {
        throw new InvalidArgumentException('Google ile giriş şu an kullanılamıyor.');
    }

    $ifade = db()->prepare('SELECT id, durum, tur, google_kimlik, eposta_dogrulandi FROM uyeler WHERE google_kimlik = :g OR eposta = :e ORDER BY (google_kimlik = :g2) DESC, id LIMIT 1');
    $ifade->execute(['g' => $kimlik['sub'], 'g2' => $kimlik['sub'], 'e' => $kimlik['eposta']]);
    $uye = $ifade->fetch();

    if ($uye !== false) {
        if ((string) $uye['durum'] !== 'aktif' || (string) $uye['tur'] !== 'uye') {
            throw new InvalidArgumentException('Bu hesapla giriş yapılamıyor.');
        }

        /*
         * Ayni e-postayla sifreli uyelik varsa Google hesabi ona baglanir:
         * e-postanin sahibi oldugunu Google dogruladi. Baska bir Google
         * hesabina bagli uyelik devralinmaz.
         */
        if ((string) ($uye['google_kimlik'] ?? '') === '') {
            /*
             * E-postasi HIC dogrulanmamis uyelik: o hesabi e-postanin gercek
             * sahibi acmamis olabilir (biri baskasinin adresiyle onceden kayit
             * olup sifresini bilir). Baglarken eski sifre gecersiz kilinir ve
             * acik oturumlar kapatilir; yoksa o kisi hesaba girmeye devam eder.
             */
            if ($uye['eposta_dogrulandi'] === null) {
                db()->prepare('UPDATE uyeler SET sifre = :s WHERE id = :id')
                    ->execute(['s' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'id' => (int) $uye['id']]);
                db()->prepare('DELETE FROM uye_oturumlari WHERE uye_id = :id')->execute(['id' => (int) $uye['id']]);
            }

            db()->prepare('UPDATE uyeler SET google_kimlik = :g, eposta_dogrulandi = COALESCE(eposta_dogrulandi, NOW()) WHERE id = :id')
                ->execute(['g' => $kimlik['sub'], 'id' => (int) $uye['id']]);
        } elseif (!hash_equals((string) $uye['google_kimlik'], $kimlik['sub'])) {
            throw new InvalidArgumentException('Bu e-posta başka bir Google hesabına bağlı.');
        }

        return ['id' => (int) $uye['id'], 'yeni' => false];
    }

    $ip    = function_exists('ziyaret_adresi') ? ziyaret_adresi() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $sayac = db()->prepare('SELECT COUNT(*) FROM uyeler WHERE kayit_ip = :ip AND olusturuldu > NOW() - INTERVAL 1 HOUR');
    $sayac->execute(['ip' => $ip]);

    if ((int) $sayac->fetchColumn() >= UYE_SAATLIK_KAYIT) {
        throw new InvalidArgumentException('Bu bağlantıdan çok fazla kayıt yapıldı; bir saat sonra yeniden deneyin.');
    }

    // Sifre rastgele: uye Google ile girer; isterse "sifremi unuttum" ile sifre belirler.
    db()->prepare('INSERT INTO uyeler (ad, eposta, eposta_dogrulandi, sifre, kayit_ip, google_kimlik) VALUES (:ad, :e, NOW(), :s, :ip, :g)')
        ->execute([
            'ad' => google_giris_rumuz($kimlik['ad'], $kimlik['eposta']),
            'e'  => $kimlik['eposta'],
            's'  => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'ip' => mb_substr($ip, 0, 45),
            'g'  => $kimlik['sub'],
        ]);

    return ['id' => (int) db()->lastInsertId(), 'yeni' => true];
}
