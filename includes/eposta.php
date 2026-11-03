<?php
declare(strict_types=1);

/**
 * E-posta gonderimi (31.10.2026; V Network uyeliginde e-posta dogrulamasi
 * ve "sifremi unuttum" icin, site sahibinin secimi).
 *
 * Ayarlar panelde (admin/eposta.php, ayarlar tablosu):
 *   eposta_gonderen   gonderen adresi (or. bilgi@valentra.com.tr)
 *   eposta_smtp_host  bossa PHP'nin mail() islevi (barindirmanin kendi
 *                     postacisi); doluysa asagidaki SMTP sunucusu
 *   eposta_smtp_port  465 (SSL) ya da 587 (STARTTLS)
 *   eposta_smtp_guvenlik  ssl | tls
 *   eposta_smtp_kullanici, eposta_smtp_sifre
 *
 * SMTP istemcisi kucuk ve bagimsiz (PHPMailer yalnizca ajanin vendor
 * klasorunde, siteye yuklenmiyor): EHLO, STARTTLS, AUTH LOGIN, tek alici.
 * Sertifika dogrulamasi acik.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/http_ortak.php';

const EPOSTA_ZAMAN_ASIMI = 20;

/** @return array{gonderen:string,host:string,port:int,guvenlik:string,kullanici:string,sifre:string} */
function eposta_ayarlari(): array
{
    $gonderen = trim(ayar_oku('eposta_gonderen', ''));

    return [
        'gonderen'  => $gonderen !== '' ? $gonderen : 'bilgi@valentra.com.tr',
        'host'      => trim(ayar_oku('eposta_smtp_host', '')),
        'port'      => (int) (ayar_oku('eposta_smtp_port', '465') ?: 465),
        'guvenlik'  => ayar_oku('eposta_smtp_guvenlik', 'ssl') === 'tls' ? 'tls' : 'ssl',
        'kullanici' => trim(ayar_oku('eposta_smtp_kullanici', '')),
        'sifre'     => ayar_oku('eposta_smtp_sifre', ''),
    ];
}

/**
 * E-posta gonderir; basarisizlikta RuntimeException (mesaj panelde
 * gosteriliyor, uyeye degil).
 */
function eposta_gonder(string $kime, string $konu, string $duzMetin, string $html, array $ekBasliklar = []): void
{
    if (!filter_var($kime, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $kime)) {
        throw new RuntimeException('Alıcı adresi geçersiz.');
    }

    $a        = eposta_ayarlari();
    $sinir    = 'vlt' . bin2hex(random_bytes(12));
    $alanAdi  = substr(strrchr($a['gonderen'], '@') ?: '@valentra.com.tr', 1);
    $konuKod  = '=?UTF-8?B?' . base64_encode($konu) . '?=';
    $gonderen = '=?UTF-8?B?' . base64_encode('Valentra') . '?= <' . $a['gonderen'] . '>';

    $basliklar = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $gonderen,
        'Reply-To: ' . $a['gonderen'],
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $alanAdi . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $sinir . '"',
    ];

    // Ek basliklar (bulten: List-Unsubscribe; 03.11.2026). Satir sonu
    // iceren baslik kabul edilmiyor (baslik enjeksiyonu).
    foreach ($ekBasliklar as $ekBaslik) {
        if (is_string($ekBaslik) && !preg_match('/[\r\n]/', $ekBaslik) && preg_match('/^[A-Za-z-]+: /', $ekBaslik)) {
            $basliklar[] = $ekBaslik;
        }
    }

    $govde = '--' . $sinir . "\r\n"
           . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($duzMetin)) . "\r\n"
           . '--' . $sinir . "\r\n"
           . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($html)) . "\r\n"
           . '--' . $sinir . "--\r\n";

    if ($a['host'] === '') {
        if (!@mail($kime, $konuKod, $govde, implode("\r\n", array_slice($basliklar, 1)), '-f' . $a['gonderen'])) {
            throw new RuntimeException('Sunucunun mail() işlevi e-postayı kabul etmedi. Panelden SMTP ayarlarını girin.');
        }

        return;
    }

    $ileti = implode("\r\n", array_merge($basliklar, ['To: <' . $kime . '>', 'Subject: ' . $konuKod])) . "\r\n\r\n" . $govde;
    eposta_smtp($a, $kime, $ileti);
}

/**
 * Bağlantı ya da STARTTLS hatasının okunur açıklaması ve ne yapılacağı.
 *
 * @param list<string> $uyarilar Yakalanan PHP uyarilari (OpenSSL mesajlari)
 */
function eposta_tls_ayrinti(string $hata, array $uyarilar, array $sertifikaAdlari = []): string
{
    $temiz   = array_map(static fn (string $u): string => preg_replace('/^stream_[a-z_]+\(\):\s*/', '', $u) ?? $u, $uyarilar);
    $ayrinti = trim($hata . ' ' . implode(' ', array_unique($temiz)));
    $ipucu   = '';

    if (eposta_ad_uyusmazligi_mi($ayrinti) && $sertifikaAdlari !== []) {
        $ipucu = ' Sunucunun sertifikası şu adlara ait: ' . implode(', ', array_slice($sertifikaAdlari, 0, 5))
               . '. SMTP sunucusu alanına bunlardan birini yazın (ör. ' . $sertifikaAdlari[0] . ').';
    } elseif (eposta_ad_uyusmazligi_mi($ayrinti)) {
        $ipucu = ' Sunucunun sertifikası bu adla eşleşmiyor: SMTP sunucusuna IHS panelindeki e-posta '
               . 'ayarlarında yazan sunucu adını (ör. sunucunun kendi adı) yazın.';
    } elseif (stripos($ayrinti, 'certificate verify failed') !== false) {
        $ipucu = ' Sunucunun sertifikası doğrulanamadı.';
    } elseif (stripos($ayrinti, 'timed out') !== false || stripos($ayrinti, 'refused') !== false) {
        $ipucu = ' Bu porta bağlantı yok: Port 587 ve Güvenlik "TLS" deneyin.';
    }

    return ($ayrinti !== '' ? mb_substr($ayrinti, 0, 300) : 'sebep bildirilmedi') . '.' . $ipucu;
}

function eposta_ad_uyusmazligi_mi(string $ayrinti): bool
{
    return stripos($ayrinti, 'did not match') !== false || stripos($ayrinti, 'peer_name') !== false;
}

/**
 * Sunucunun sertifikasının ait olduğu adlar (ad uyuşmazlığında ipucu için).
 *
 * 01.11.2026: mail.valentra.com.tr'nin sertifikasi barindiricinin sunucu
 * adina ve IHS panelinde o ad gorunmuyor. Hata aninda sertifika ayrica
 * okunup adlari mesaja yaziliyor. Yalnizca sertifika okunuyor: kullanici
 * adi ve sifre gonderilmiyor, baglanti hemen kapaniyor; bu yuzden bu tek
 * okumada dogrulama gevsek. Okunamazsa bos liste.
 *
 * @param array{host:string,port:int,guvenlik:string} $a
 * @return list<string>
 */
function eposta_sertifika_adlari(array $a): array
{
    $baglam = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'capture_peer_cert' => true,
    ]]);
    $adres  = ($a['guvenlik'] === 'ssl' ? 'ssl://' : 'tcp://') . $a['host'] . ':' . $a['port'];
    $soket  = @stream_socket_client($adres, $no, $hata, 10, STREAM_CLIENT_CONNECT, $baglam);

    if ($soket === false) {
        return [];
    }

    try {
        stream_set_timeout($soket, 10);

        if ($a['guvenlik'] === 'tls') {
            $satir = static function () use ($soket): string {
                $metin = '';

                while (($s = fgets($soket, 1024)) !== false) {
                    $metin .= $s;

                    if (strlen($s) < 4 || $s[3] !== '-') {
                        break;
                    }
                }

                return $metin;
            };

            $satir();
            fwrite($soket, "EHLO valentra.com.tr\r\n");
            $satir();
            fwrite($soket, "STARTTLS\r\n");

            if (strncmp($satir(), '220', 3) !== 0
                || @stream_socket_enable_crypto($soket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)) !== true) {
                return [];
            }
        }

        $sertifika = stream_context_get_params($soket)['options']['ssl']['peer_certificate'] ?? null;
        $bilgi     = $sertifika !== null ? openssl_x509_parse($sertifika) : false;
    } finally {
        fclose($soket);
    }

    if (!is_array($bilgi)) {
        return [];
    }

    $adlar = [];

    foreach (explode(',', (string) ($bilgi['extensions']['subjectAltName'] ?? '')) as $parca) {
        if (preg_match('/^\s*DNS:(\S+)$/', $parca, $e) === 1) {
            $adlar[] = $e[1];
        }
    }

    if ($adlar === [] && isset($bilgi['subject']['CN'])) {
        $adlar[] = (string) $bilgi['subject']['CN'];
    }

    // Joker adlar (*.ornek.com) dogrudan yazilamaz; somut adlar once.
    usort($adlar, static fn (string $x, string $y): int => (int) str_starts_with($x, '*') <=> (int) str_starts_with($y, '*'));

    return array_values(array_unique($adlar));
}

/** @param array{gonderen:string,host:string,port:int,guvenlik:string,kullanici:string,sifre:string} $a */
function eposta_smtp(array $a, string $kime, string $ileti): void
{
    /*
     * Kok sertifika listesi sitenin kendisi (ca_paketi, 01.11.2026).
     *
     * Paylasimli hostingin listesi eksik; haber cekme ayni nedenle zaten
     * includes/sertifika'daki listeyi kullaniyor (bkz. http_ortak.php).
     * SMTP baglantisi kullanmiyordu ve mail.valentra.com.tr:465 "baglanilamadi"
     * diye, sebepsiz dusuyordu. Dogrulama KAPATILMIYOR.
     */
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true];

    if (($ca = ca_paketi()) !== null) {
        $ssl['cafile'] = $ca;
    }

    $baglam = stream_context_create(['ssl' => $ssl]);
    $adres  = ($a['guvenlik'] === 'ssl' ? 'ssl://' : 'tcp://') . $a['host'] . ':' . $a['port'];

    // TLS hatasinda $hata bos geliyor; asil sebep PHP uyarisinda (OpenSSL).
    $uyarilar = [];
    set_error_handler(static function (int $no, string $mesaj) use (&$uyarilar): bool {
        $uyarilar[] = $mesaj;

        return true;
    });

    try {
        $soket = stream_socket_client($adres, $no, $hata, EPOSTA_ZAMAN_ASIMI, STREAM_CLIENT_CONNECT, $baglam);
    } finally {
        restore_error_handler();
    }

    if ($soket === false) {
        $ayrinti = $hata . ' ' . implode(' ', $uyarilar);

        throw new RuntimeException('SMTP sunucusuna bağlanılamadı (' . $a['host'] . ':' . $a['port'] . '): '
            . eposta_tls_ayrinti($hata, $uyarilar, eposta_ad_uyusmazligi_mi($ayrinti) ? eposta_sertifika_adlari($a) : []));
    }

    stream_set_timeout($soket, EPOSTA_ZAMAN_ASIMI);

    $oku = static function () use ($soket): array {
        $metin = '';

        while (($satir = fgets($soket, 1024)) !== false) {
            $metin .= $satir;

            // Cok satirli yanitta "250-", son satirda "250 ".
            if (strlen($satir) < 4 || $satir[3] !== '-') {
                break;
            }
        }

        return [(int) substr($metin, 0, 3), trim($metin)];
    };

    $komut = static function (string $satir, array $beklenen, string $gizli = '') use ($soket, $oku): void {
        fwrite($soket, $satir . "\r\n");
        [$kod, $yanit] = $oku();

        if (!in_array($kod, $beklenen, true)) {
            throw new RuntimeException('SMTP hatası (' . ($gizli !== '' ? $gizli : strtok($satir, ' ')) . '): ' . $yanit);
        }
    };

    try {
        [$kod, $yanit] = $oku();

        if ($kod !== 220) {
            throw new RuntimeException('SMTP sunucusu karşılamadı: ' . $yanit);
        }

        $ben = parse_url(site_adresi(), PHP_URL_HOST) ?: 'valentra.com.tr';
        $komut('EHLO ' . $ben, [250]);

        if ($a['guvenlik'] === 'tls') {
            $komut('STARTTLS', [220]);

            // TLS 1.3 de kabul (PHP'nin TLS_CLIENT sabiti 1.0-1.2); sebep 465'teki gibi
            // PHP uyarisindan (01.11.2026: "STARTTLS ile sifreli baglanti kurulamadi.").
            $yontem = STREAM_CRYPTO_METHOD_TLS_CLIENT
                | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            $uyarilar = [];
            set_error_handler(static function (int $no, string $mesaj) use (&$uyarilar): bool {
                $uyarilar[] = $mesaj;

                return true;
            });

            try {
                $sifreli = stream_socket_enable_crypto($soket, true, $yontem);
            } finally {
                restore_error_handler();
            }

            if ($sifreli !== true) {
                throw new RuntimeException('STARTTLS ile şifreli bağlantı kurulamadı (' . $a['host'] . ':' . $a['port'] . '): '
                    . eposta_tls_ayrinti('', $uyarilar, eposta_ad_uyusmazligi_mi(implode(' ', $uyarilar)) ? eposta_sertifika_adlari($a) : []));
            }

            $komut('EHLO ' . $ben, [250]);
        }

        if ($a['kullanici'] !== '') {
            $komut('AUTH LOGIN', [334]);
            $komut(base64_encode($a['kullanici']), [334], 'kullanıcı adı');
            $komut(base64_encode($a['sifre']), [235], 'şifre');
        }

        $komut('MAIL FROM:<' . $a['gonderen'] . '>', [250]);
        $komut('RCPT TO:<' . $kime . '>', [250, 251]);
        $komut('DATA', [354]);
        // Nokta ile baslayan satirlar ikilenir (RFC 5321).
        $veri = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $ileti)) ?? $ileti;
        $komut($veri . "\r\n.", [250], 'ileti');
        fwrite($soket, "QUIT\r\n");
    } finally {
        fclose($soket);
    }
}

/** Basit, kurumsal e-posta sablonu (HTML). */
function eposta_sablon(string $baslik, string $paragraf, string $dugmeMetni, string $dugmeAdresi, string $alt): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html><html lang="tr"><body style="margin:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
         . '<table role="presentation" width="100%" style="max-width:520px;background:#ffffff;border-radius:10px;border-top:4px solid #d7161f" cellpadding="0" cellspacing="0">'
         . '<tr><td style="padding:28px 28px 8px;font-size:22px;font-weight:800;letter-spacing:.06em;color:#d7161f">VALENTRA</td></tr>'
         . '<tr><td style="padding:4px 28px 0;font-size:20px;font-weight:700">' . $e($baslik) . '</td></tr>'
         . '<tr><td style="padding:12px 28px;font-size:15px;line-height:1.6;color:#3f3f46">' . $e($paragraf) . '</td></tr>'
         . '<tr><td style="padding:8px 28px 20px"><a href="' . $e($dugmeAdresi) . '" style="display:inline-block;background:#d7161f;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px">' . $e($dugmeMetni) . '</a></td></tr>'
         . '<tr><td style="padding:0 28px 24px;font-size:12px;line-height:1.5;color:#71717a">' . $e($alt) . '<br>' . $e($dugmeAdresi) . '</td></tr>'
         . '</table></td></tr></table></body></html>';
}
