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
function eposta_gonder(string $kime, string $konu, string $duzMetin, string $html): void
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

/** @param array{gonderen:string,host:string,port:int,guvenlik:string,kullanici:string,sifre:string} $a */
function eposta_smtp(array $a, string $kime, string $ileti): void
{
    $baglam = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $adres  = ($a['guvenlik'] === 'ssl' ? 'ssl://' : 'tcp://') . $a['host'] . ':' . $a['port'];
    $soket  = @stream_socket_client($adres, $no, $hata, EPOSTA_ZAMAN_ASIMI, STREAM_CLIENT_CONNECT, $baglam);

    if ($soket === false) {
        throw new RuntimeException('SMTP sunucusuna bağlanılamadı (' . $a['host'] . ':' . $a['port'] . '): ' . $hata);
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

            if (!@stream_socket_enable_crypto($soket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS ile şifreli bağlantı kurulamadı.');
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
