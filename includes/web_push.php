<?php
declare(strict_types=1);

/**
 * Tarayici bildirimi (Web Push; 03.11.2026, site sahibi: "kullaniciyi geri
 * ceken ozellikler, sirayla yapmaya basla").
 *
 * Okur sitede "Bildirimleri ac" der; tarayicisi bir abonelik (adres +
 * iki anahtar) verir, push_aboneleri tablosuna yaziliyor. Onemli haber
 * yayina girince (son dakika ya da yuksek onem) ya da panelden elle
 * gonderilince her aboneye sifreli kisa bir mesaj gidiyor; tarayici
 * /sw.js ile bildirimi gosteriyor, tiklaninca habere aciyor.
 *
 * Sunucuda Composer yok: sifreleme RFC 8291 (aes128gcm) ve VAPID
 * (RFC 8292, ES256) dogrudan OpenSSL ile yapiliyor. VAPID anahtari ilk
 * ihtiyacta uretilip ayarlarda saklaniyor; kimse elle girmiyor.
 *
 * Gonderim kuyrukla: push_gonderimleri satiri aciliyor, aboneler
 * sayfa gonderildikten SONRA (fastcgi_finish_request) parti parti,
 * curl_multi ile paralel isleniyor. Bitmeyen gonderim ziyaretci
 * goruntulemesiyle (api/gorunum.php) ve panelden devam ediyor.
 *
 * Kurallar (otomatik): gunde en fazla PUSH_GUNLUK_TAVAN, iki bildirim
 * arasi en az PUSH_EN_KISA_ARA dakika, gece 00:00-07:00 sessiz.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/seo.php';

const PUSH_GUNLUK_TAVAN   = 5;
const PUSH_EN_KISA_ARA    = 45;     // dakika
const PUSH_ONEM_ESIGI     = 85;     // bu onem ve ustu otomatik bildirim
const PUSH_PARTI          = 400;    // bir calismada en fazla abone
const PUSH_ESZAMANLI      = 25;     // curl_multi ayni anda
const PUSH_TTL            = 6 * 3600;
const PUSH_SESSIZ_BAS     = 0;      // saat (Turkiye)
const PUSH_SESSIZ_BIT     = 7;

/** Bildirim gonderilebilecek servisler: baska adrese istek atilmasin (SSRF). */
const PUSH_SERVISLERI = [
    'fcm.googleapis.com',
    'updates.push.services.mozilla.com',
    'web.push.apple.com',
    'notify.windows.com',
    'push.services.mozilla.com',
];

function push_b64(string $veri): string
{
    return rtrim(strtr(base64_encode($veri), '+/', '-_'), '=');
}

function push_b64_coz(string $metin): string
{
    $metin = strtr(trim($metin), '-_', '+/');
    $cozum = base64_decode($metin . str_repeat('=', (4 - strlen($metin) % 4) % 4), true);

    return is_string($cozum) ? $cozum : '';
}

/** Ham (65 bayt, 0x04 ile baslayan) P-256 acik anahtarini OpenSSL anahtarina cevirir. */
function push_acik_anahtar(string $ham): OpenSSLAsymmetricKey|false
{
    if (strlen($ham) !== 65 || $ham[0] !== "\x04") {
        return false;
    }

    // SubjectPublicKeyInfo (id-ecPublicKey, prime256v1) + BIT STRING
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $ham;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

    return openssl_pkey_get_public($pem);
}

/** EC anahtarinin ham acik kismi (65 bayt). */
function push_ham_acik(OpenSSLAsymmetricKey $anahtar): string
{
    $d = openssl_pkey_get_details($anahtar);
    $x = str_pad((string) ($d['ec']['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
    $y = str_pad((string) ($d['ec']['y'] ?? ''), 32, "\0", STR_PAD_LEFT);

    return "\x04" . $x . $y;
}

/**
 * Sitenin VAPID anahtari; yoksa uretir.
 *
 * @return array{ozel:string,acik:string}|null  ozel PEM, acik base64url (65 bayt)
 */
function push_vapid(): ?array
{
    $kayit = json_decode(ayar_oku('push_vapid'), true);

    if (is_array($kayit) && ($kayit['ozel'] ?? '') !== '' && ($kayit['acik'] ?? '') !== '') {
        return ['ozel' => (string) $kayit['ozel'], 'acik' => (string) $kayit['acik']];
    }

    $anahtar = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

    if ($anahtar === false || !openssl_pkey_export($anahtar, $pem)) {
        error_log('[valentra] VAPID anahtari uretilemedi: ' . openssl_error_string());

        return null;
    }

    $kayit = ['ozel' => $pem, 'acik' => push_b64(push_ham_acik($anahtar))];
    ayar_yaz('push_vapid', (string) json_encode($kayit));

    return $kayit;
}

/** Sayfaya konan acik anahtar (tarayici aboneligi bununla aciyor). */
function push_acik_vapid(): string
{
    return push_vapid()['acik'] ?? '';
}

/** DER ECDSA imzasini JWT'nin istedigi 64 baytlik r||s bicimine cevirir. */
function push_der_imza_ham(string $der): string
{
    $o = 2;

    if ((ord($der[1]) & 0x80) !== 0) {
        $o += ord($der[1]) & 0x7f;
    }

    $parcalar = [];

    for ($i = 0; $i < 2; $i++) {
        $uzunluk = ord($der[$o + 1]);
        $sayi    = substr($der, $o + 2, $uzunluk);
        $sayi    = ltrim($sayi, "\0");
        $parcalar[] = str_pad($sayi, 32, "\0", STR_PAD_LEFT);
        $o += 2 + $uzunluk;
    }

    return $parcalar[0] . $parcalar[1];
}

/** Bir push servisi icin VAPID "Authorization" basligi. */
function push_vapid_basligi(string $uc): string
{
    $vapid = push_vapid();
    $p     = parse_url($uc);

    if ($vapid === null || !isset($p['scheme'], $p['host'])) {
        return '';
    }

    $hedef = $p['scheme'] . '://' . $p['host'];
    static $onbellek = [];

    if (isset($onbellek[$hedef]) && $onbellek[$hedef]['bitis'] > time() + 600) {
        return $onbellek[$hedef]['baslik'];
    }

    $bitis = time() + 12 * 3600;
    $girdi = push_b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
           . '.' . push_b64((string) json_encode(['aud' => $hedef, 'exp' => $bitis, 'sub' => site_adresi()], JSON_UNESCAPED_SLASHES));

    if (!openssl_sign($girdi, $der, $vapid['ozel'], OPENSSL_ALGO_SHA256)) {
        return '';
    }

    $baslik = 'vapid t=' . $girdi . '.' . push_b64(push_der_imza_ham($der)) . ', k=' . $vapid['acik'];
    $onbellek[$hedef] = ['bitis' => $bitis, 'baslik' => $baslik];

    return $baslik;
}

/**
 * Mesaji abonenin anahtarlariyla sifreler (RFC 8291, aes128gcm).
 *
 * @return string|null  istek govdesi
 */
function push_sifrele(string $mesaj, string $p256dh, string $auth): ?string
{
    $aboneAcik = push_b64_coz($p256dh);
    $sir       = push_b64_coz($auth);
    $aboneAnahtar = push_acik_anahtar($aboneAcik);

    if ($aboneAnahtar === false || strlen($sir) !== 16) {
        return null;
    }

    $gecici = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

    if ($gecici === false) {
        return null;
    }

    $paylasilan = openssl_pkey_derive($aboneAnahtar, $gecici, 32);

    if (!is_string($paylasilan) || strlen($paylasilan) !== 32) {
        return null;
    }

    $sunucuAcik = push_ham_acik($gecici);
    $ikm  = hash_hkdf('sha256', $paylasilan, 32, "WebPush: info\0" . $aboneAcik . $sunucuAcik, $sir);
    $tuz  = random_bytes(16);
    $cek  = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $tuz);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $tuz);

    // Tek kayit: sonuna 0x02 (son kayit ayraci), dolgu yok.
    $sifreli = openssl_encrypt($mesaj . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiket, '', 16);

    if (!is_string($sifreli)) {
        return null;
    }

    return $tuz . pack('N', 4096) . chr(65) . $sunucuAcik . $sifreli . $etiket;
}

/** Abonelik adresi izinli bir push servisine mi ait? */
function push_uc_gecerli(string $uc): bool
{
    $p = parse_url($uc);

    if (($p['scheme'] ?? '') !== 'https' || !isset($p['host']) || strlen($uc) > 1000) {
        return false;
    }

    $host = strtolower((string) $p['host']);

    foreach (PUSH_SERVISLERI as $servis) {
        if ($host === $servis || str_ends_with($host, '.' . $servis)) {
            return true;
        }
    }

    return false;
}

/**
 * Aboneligi kaydeder (ayni adres yeniden gelirse anahtarlar guncellenir).
 */
function push_abone_kaydet(string $uc, string $p256dh, string $auth, string $tarayici = ''): bool
{
    if (!push_uc_gecerli($uc) || strlen(push_b64_coz($p256dh)) !== 65 || strlen(push_b64_coz($auth)) !== 16) {
        return false;
    }

    db()->prepare(
        'INSERT INTO push_aboneleri (uc_ozet, uc, p256dh, auth, tarayici)
         VALUES (:o, :u, :p, :a, :t)
         ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), hata = 0, guncellendi = NOW()'
    )->execute([
        'o' => hash('sha256', $uc), 'u' => $uc, 'p' => $p256dh, 'a' => $auth,
        't' => mb_substr($tarayici, 0, 40),
    ]);

    return true;
}

function push_abone_sil(string $uc): void
{
    db()->prepare('DELETE FROM push_aboneleri WHERE uc_ozet = :o')->execute(['o' => hash('sha256', $uc)]);
}

function push_abone_sayisi(): int
{
    try {
        return (int) db()->query('SELECT COUNT(*) FROM push_aboneleri')->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/* ------------------------------------------------------------------------
 * Gonderim kuyrugu
 * --------------------------------------------------------------------- */

/**
 * Yeni gonderim acar. Basliklar kisaltiliyor (bildirimde iki satir).
 *
 * @return int gonderim kimligi
 */
function push_gonderim_ac(string $baslik, string $metin, string $adres, string $gorsel = '', ?int $haberId = null, string $tur = 'elle'): int
{
    $kisalt = static fn (string $s, int $n): string => mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;

    db()->prepare(
        'INSERT INTO push_gonderimleri (haber_id, tur, baslik, metin, adres, gorsel, hedef)
         VALUES (:h, :t, :b, :m, :a, :g, (SELECT COUNT(*) FROM push_aboneleri))'
    )->execute([
        'h' => $haberId, 't' => $tur === 'otomatik' ? 'otomatik' : 'elle',
        'b' => $kisalt(trim($baslik), 120), 'm' => $kisalt(trim($metin), 180),
        'a' => mb_substr($adres, 0, 500), 'g' => mb_substr($gorsel, 0, 500),
    ]);

    $id = (int) db()->lastInsertId();
    ayar_yaz('push_bekleyen', '1');
    push_sayfa_sonunda_isle();

    return $id;
}

/** Kuyrugu sayfa gonderildikten sonra isler (bir kez kurulur). */
function push_sayfa_sonunda_isle(): void
{
    static $kuruldu = false;

    if ($kuruldu) {
        return;
    }

    $kuruldu = true;

    register_shutdown_function(static function (): void {
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            ignore_user_abort(true);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            @set_time_limit(90);
            push_kuyrugu_isle();
        } catch (Throwable $e) {
            error_log('[valentra] bildirim kuyrugu: ' . $e->getMessage());
        }
    });
}

/** Bekleyen gonderim varsa (ucuz denetim) sayfa sonunda devam et. */
function push_bekleyen_varsa_devam(): void
{
    if (ayar_oku('push_bekleyen') === '1') {
        push_sayfa_sonunda_isle();
    }
}

/**
 * Bekleyen gonderimlerden bir parti isler. Ayni anda tek isci (GET_LOCK).
 *
 * @return array{gonderilen:int,basarili:int,silinen:int}
 */
function push_kuyrugu_isle(int $sureButcesi = 50): array
{
    $sonuc = ['gonderilen' => 0, 'basarili' => 0, 'silinen' => 0];

    if ((int) db()->query("SELECT GET_LOCK('valentra_push', 0)")->fetchColumn() !== 1) {
        return $sonuc;
    }

    $bitis = time() + $sureButcesi;

    try {
        while (time() < $bitis) {
            $g = db()->query("SELECT * FROM push_gonderimleri WHERE durum <> 'bitti' ORDER BY id LIMIT 1")->fetch();

            if (!$g) {
                ayar_yaz('push_bekleyen', '0');
                break;
            }

            $aboneler = db()->prepare('SELECT id, uc, p256dh, auth FROM push_aboneleri WHERE id > :s ORDER BY id LIMIT ' . PUSH_PARTI);
            $aboneler->execute(['s' => (int) $g['son_abone']]);
            $liste = $aboneler->fetchAll();

            if ($liste === []) {
                db()->prepare("UPDATE push_gonderimleri SET durum = 'bitti', bitti = NOW() WHERE id = :id")->execute(['id' => $g['id']]);
                continue;
            }

            db()->prepare("UPDATE push_gonderimleri SET durum = 'gonderiliyor' WHERE id = :id")->execute(['id' => $g['id']]);

            $mesaj = (string) json_encode([
                'baslik' => $g['baslik'],
                'metin'  => $g['metin'],
                'adres'  => $g['adres'],
                'gorsel' => $g['gorsel'],
                'etiket' => 'v' . $g['id'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $parti = push_parti_gonder($liste, $mesaj);

            db()->prepare(
                'UPDATE push_gonderimleri SET son_abone = :s, basarili = basarili + :b, basarisiz = basarisiz + :f WHERE id = :id'
            )->execute(['s' => (int) end($liste)['id'], 'b' => $parti['basarili'], 'f' => $parti['basarisiz'], 'id' => $g['id']]);

            $sonuc['gonderilen'] += count($liste);
            $sonuc['basarili']   += $parti['basarili'];
            $sonuc['silinen']    += $parti['silinen'];

            if ($parti['kota']) {
                break; // servis yavasla dedi; bir sonraki tetiklemede devam
            }
        }
    } finally {
        db()->query("SELECT RELEASE_LOCK('valentra_push')");
    }

    return $sonuc;
}

/**
 * Bir abone listesine ayni mesaji paralel gonderir.
 *
 * @param list<array<string,mixed>> $liste
 * @return array{basarili:int,basarisiz:int,silinen:int,kota:bool}
 */
function push_parti_gonder(array $liste, string $mesaj): array
{
    $sonuc = ['basarili' => 0, 'basarisiz' => 0, 'silinen' => 0, 'kota' => false];
    $coklu = curl_multi_init();
    $bekleyen = $liste;
    $calisan  = [];

    $baslat = static function (array $abone) use ($coklu, $mesaj, &$calisan, &$sonuc): void {
        $govde  = push_sifrele($mesaj, (string) $abone['p256dh'], (string) $abone['auth']);
        $yetki  = push_vapid_basligi((string) $abone['uc']);

        if ($govde === null || $yetki === '' || !push_uc_gecerli((string) $abone['uc'])) {
            $sonuc['basarisiz']++;

            return;
        }

        $ch = curl_init((string) $abone['uc']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $govde,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . PUSH_TTL,
                'Urgency: high',
                'Authorization: ' . $yetki,
            ],
        ]);
        curl_multi_add_handle($coklu, $ch);
        $calisan[(int) spl_object_id($ch)] = ['ch' => $ch, 'id' => (int) $abone['id']];
    };

    while ($bekleyen !== [] || $calisan !== []) {
        while ($bekleyen !== [] && count($calisan) < PUSH_ESZAMANLI) {
            $baslat(array_shift($bekleyen));
        }

        if ($calisan === []) {
            continue;
        }

        do {
            $durum = curl_multi_exec($coklu, $aktif);
        } while ($durum === CURLM_CALL_MULTI_PERFORM);

        while ($bilgi = curl_multi_info_read($coklu)) {
            $ch  = $bilgi['handle'];
            $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ab  = $calisan[(int) spl_object_id($ch)]['id'] ?? 0;
            unset($calisan[(int) spl_object_id($ch)]);
            curl_multi_remove_handle($coklu, $ch);
            curl_close($ch);

            if ($kod >= 200 && $kod < 300) {
                $sonuc['basarili']++;
            } elseif ($kod === 404 || $kod === 410) {
                // Abonelik iptal edilmis ya da suresi dolmus: sil.
                db()->prepare('DELETE FROM push_aboneleri WHERE id = :id')->execute(['id' => $ab]);
                $sonuc['silinen']++;
                $sonuc['basarisiz']++;
            } else {
                $sonuc['basarisiz']++;

                if ($kod === 429) {
                    $sonuc['kota'] = true;
                }

                // Surekli hata veren abonelik (bozuk anahtar vb.) bir sure sonra silinsin.
                db()->prepare('UPDATE push_aboneleri SET hata = hata + 1 WHERE id = :id')->execute(['id' => $ab]);
            }
        }

        if ($calisan !== []) {
            curl_multi_select($coklu, 1.0);
        }
    }

    curl_multi_close($coklu);
    db()->exec('DELETE FROM push_aboneleri WHERE hata >= 5');

    return $sonuc;
}

/* ------------------------------------------------------------------------
 * Otomatik bildirim: haber yayina girince
 * --------------------------------------------------------------------- */

/** Otomatik bildirim acik mi (panelden kapatilabilir; varsayilan acik). */
function push_otomatik_acik(): bool
{
    return ayar_oku('push_otomatik', '1') === '1';
}

/**
 * Haber yayina girdi: son dakika ya da onemliyse ve kurallar izin veriyorsa
 * bildirim kuyruga.
 */
function push_haber_yayinlandi(int $haberId): void
{
    try {
        if (!push_otomatik_acik() || push_abone_sayisi() === 0) {
            return;
        }

        $saat = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('G');

        if ($saat >= PUSH_SESSIZ_BAS && $saat < PUSH_SESSIZ_BIT) {
            return;
        }

        $h = db()->prepare(
            "SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.onem, h.son_dakika, k.slug AS kategori
               FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.id = :id AND h.durum = :d
                AND LEAST(h.olusturuldu, COALESCE(h.kaynak_tarihi, h.olusturuldu)) > NOW() - INTERVAL 3 HOUR"
        );
        $h->execute(['id' => $haberId, 'd' => HABER_YAYINDA]);
        $h = $h->fetch();

        // Eski taslak toplu yayina alinirsa bildirim gitmesin: yalnizca son uc saatin haberi.
        if (!$h || ($h['kategori'] ?? '') === 'yemek-tarifleri') {
            return;
        }

        // Yapay zekanin karari (04.11.2026); kaynagin "son dakika" demesi yetmiyor.
        $sonDakika = (int) ($h['son_dakika'] ?? 0) === 1;

        if (!$sonDakika && (int) $h['onem'] < PUSH_ONEM_ESIGI) {
            return;
        }

        // Ayni haber ikinci kez gitmesin; gunluk tavan ve en kisa ara.
        $once = db()->prepare('SELECT 1 FROM push_gonderimleri WHERE haber_id = :id LIMIT 1');
        $once->execute(['id' => $haberId]);

        if ($once->fetchColumn()) {
            return;
        }

        $son = db()->query(
            "SELECT COUNT(*) AS gun, MAX(olusturuldu) AS son FROM push_gonderimleri
              WHERE tur = 'otomatik' AND olusturuldu > NOW() - INTERVAL 24 HOUR"
        )->fetch();
        $tavan = max(1, (int) ayar_oku('push_gunluk_tavan', (string) PUSH_GUNLUK_TAVAN));

        if ((int) $son['gun'] >= $tavan
            || ($son['son'] !== null && strtotime((string) $son['son']) > time() - PUSH_EN_KISA_ARA * 60)) {
            return;
        }

        push_haber_gonder($h, 'otomatik');
    } catch (Throwable $e) {
        error_log('[valentra] otomatik bildirim: ' . $e->getMessage());
    }
}

/**
 * Bir haber icin bildirim acar.
 *
 * @param array<string,mixed> $h haberler satiri (id, baslik, slug, ozet, gorsel_url)
 */
function push_haber_gonder(array $h, string $tur = 'elle'): int
{
    require_once __DIR__ . '/haberler.php';
    require_once __DIR__ . '/adres.php';
    require_once __DIR__ . '/gorsel_onbellek.php';

    $gorsel = haber_gorsel($h);

    if ($gorsel !== '' && !str_starts_with($gorsel, 'http')) {
        $gorsel = site_adresi() . '/' . ltrim($gorsel, '/');
    }

    $baslik = preg_replace('/^\s*son\s*dakika\s*[:!\-–—|]?\s*/iu', '', (string) $h['baslik']) ?? (string) $h['baslik'];

    return push_gonderim_ac(
        $baslik,
        trim(strip_tags((string) ($h['ozet'] ?? ''))),
        ($yol = haber_yolu((string) $h['slug'])) . (str_contains($yol, '?') ? '&' : '?') . 'utm_source=bildirim&utm_medium=push',
        $gorsel,
        (int) $h['id'],
        $tur
    );
}
