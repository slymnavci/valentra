<?php
declare(strict_types=1);

/**
 * Telegram kanalına otomatik haber gönderimi.
 *
 * Site sahibinin istegi (03.11.2026, "baska neler yapabiliriz" onerisi):
 * onemli ve son dakika haberleri sitenin Telegram kanalina kendiliginden
 * dussun; okur kanaldan siteye gelsin.
 *
 * Kurulum panelde (Yonetim > Telegram): @BotFather'dan alinan bot anahtari
 * ve kanal adi; bot kanala yonetici olarak eklenir. Anahtar yoksa hicbir
 * istek yapilmaz.
 *
 * Hangi haber: yayina girdigi anda (haberin kendisi en fazla uc saatlik),
 * onemi esigin ustunde ya da "son dakika". Ayni haber bir kez; gunde en
 * fazla TELEGRAM_GUNLUK_TAVAN; gece TELEGRAM_SESSIZ_BAS-BIT arasi gonderilmez.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/seo.php';

/** Varsayılan önem eşiği (panelden değişir). */
const TELEGRAM_VARSAYILAN_ESIK = 70;

/** 24 saatte en fazla bu kadar gönderi. */
const TELEGRAM_GUNLUK_TAVAN = 40;

/** Bu saatler arasında (Türkiye) gönderilmez. */
const TELEGRAM_SESSIZ_BAS = 0;
const TELEGRAM_SESSIZ_BIT = 7;

/** @return array{token:string,kanal:string,acik:bool,esik:int} */
function telegram_ayarlari(): array
{
    return [
        'token' => trim(ayar_oku('telegram_token')),
        'kanal' => trim(ayar_oku('telegram_kanal')),
        'acik'  => ayar_oku('telegram_acik') === '1',
        'esik'  => max(0, min(100, (int) (ayar_oku('telegram_esik') ?: TELEGRAM_VARSAYILAN_ESIK))),
    ];
}

function telegram_kurulu(): bool
{
    $a = telegram_ayarlari();

    return $a['token'] !== '' && $a['kanal'] !== '';
}

/** "@kanal", "kanal", "https://t.me/kanal" ya da "-100…" -> API'nin istediği biçim. */
function telegram_kanal_coz(string $kanal): string
{
    $kanal = trim($kanal);

    if (preg_match('~^(?:https?://)?t\.me/([A-Za-z0-9_]{5,})/?$~', $kanal, $m) === 1) {
        return '@' . $m[1];
    }

    if (preg_match('/^-?\d+$/', $kanal) === 1) {
        return $kanal;
    }

    return '@' . ltrim($kanal, '@');
}

/**
 * Bot API çağrısı.
 *
 * @param array<string,mixed> $veri
 * @return array{tamam:bool,hata:string,yanit:array<string,mixed>}
 */
function telegram_istek(string $yontem, array $veri, ?string $token = null): array
{
    $token ??= telegram_ayarlari()['token'];

    if (preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
        return ['tamam' => false, 'hata' => 'Bot anahtarı geçerli görünmüyor (biçim: 123456789:ABC...).', 'yanit' => []];
    }

    require_once __DIR__ . '/http_ortak.php';

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $yontem);
    // array_replace: ortak seceneklerdeki HTTPHEADER'in yerine JSON basligi.
    curl_setopt_array($ch, array_replace(http_ortak_secenekler(8), [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode($veri, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]));
    $govde = curl_exec($ch);
    $hata  = curl_error($ch);
    curl_close($ch);

    $yanit = is_string($govde) ? json_decode($govde, true) : null;

    if (!is_array($yanit)) {
        return ['tamam' => false, 'hata' => 'Telegram\'a ulaşılamadı' . ($hata !== '' ? ': ' . $hata : '.'), 'yanit' => []];
    }

    if (empty($yanit['ok'])) {
        return ['tamam' => false, 'hata' => telegram_hata_aciklamasi((string) ($yanit['description'] ?? 'bilinmeyen hata')), 'yanit' => $yanit];
    }

    return ['tamam' => true, 'hata' => '', 'yanit' => $yanit];
}

/** Telegram hatasının panelde ne yapılacağını söyleyen açıklaması. */
function telegram_hata_aciklamasi(string $aciklama): string
{
    $kucuk = strtolower($aciklama);

    return match (true) {
        str_contains($kucuk, 'unauthorized')       => 'Bot anahtarı yanlış (Unauthorized). @BotFather\'dan aldığınız anahtarı yeniden kopyalayın.',
        str_contains($kucuk, 'chat not found')     => 'Kanal bulunamadı. Kanal adını @ ile birlikte yazın (örnek: @valentrahaber) ve botu kanala ekleyin.',
        str_contains($kucuk, 'not enough rights'),
        str_contains($kucuk, 'need administrator'),
        str_contains($kucuk, 'not a member')       => 'Bot kanalda yönetici değil. Kanal ayarları > Yöneticiler > Yönetici ekle ile botu ekleyip "Mesaj gönderme" iznini verin.',
        default                                    => 'Telegram hatası: ' . $aciklama,
    };
}

/**
 * Haberi kanala gönderir (görselli; görsel gönderilemezse yazıyla).
 *
 * @param array<string,mixed> $h baslik, ozet, slug, gorsel_url
 * @return array{tamam:bool,hata:string}
 */
function telegram_haber_gonder(array $h, bool $sonDakika = false): array
{
    $a   = telegram_ayarlari();
    $e   = static fn (string $s): string => htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    $url = site_adresi() . haber_yolu((string) $h['slug']) . '?utm_source=telegram_kanal&utm_medium=social';

    $metin = ($sonDakika ? '🔴 <b>SON DAKİKA</b>' . "\n" : '')
        . '<b>' . $e((string) $h['baslik']) . '</b>'
        . (trim((string) ($h['ozet'] ?? '')) !== '' ? "\n\n" . $e(mb_substr((string) $h['ozet'], 0, 600, 'UTF-8')) : '')
        . "\n\n" . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Haberin devamı →</a>';

    $kanal  = telegram_kanal_coz($a['kanal']);
    $gorsel = trim((string) ($h['gorsel_url'] ?? ''));

    if ($gorsel !== '' && !str_starts_with($gorsel, 'http')) {
        $gorsel = site_adresi() . '/' . ltrim($gorsel, '/');
    }

    if ($gorsel !== '' && mb_strlen($metin, 'UTF-8') <= 1000) {
        $sonuc = telegram_istek('sendPhoto', ['chat_id' => $kanal, 'photo' => $gorsel, 'caption' => $metin, 'parse_mode' => 'HTML']);

        if ($sonuc['tamam']) {
            return ['tamam' => true, 'hata' => ''];
        }
    }

    $sonuc = telegram_istek('sendMessage', [
        'chat_id' => $kanal, 'text' => $metin, 'parse_mode' => 'HTML',
        'link_preview_options' => ['url' => $url, 'prefer_large_media' => true],
    ]);

    return ['tamam' => $sonuc['tamam'], 'hata' => $sonuc['hata']];
}

/** Yayına giren haber kanala gitmeli mi; gitmeliyse gönderir (yanıttan sonra). */
function telegram_haber_yayinlandi(int $haberId): void
{
    try {
        $a = telegram_ayarlari();

        if (!$a['acik'] || $a['token'] === '' || $a['kanal'] === '') {
            return;
        }

        $saat = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('G');

        if ($saat >= TELEGRAM_SESSIZ_BAS && $saat < TELEGRAM_SESSIZ_BIT) {
            return;
        }

        $ifade = db()->prepare(
            "SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.onem, h.son_dakika, k.slug AS kategori
               FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.id = :id AND h.durum = 'yayinda'
                AND LEAST(h.olusturuldu, COALESCE(h.kaynak_tarihi, h.olusturuldu)) > NOW() - INTERVAL 3 HOUR"
        );
        $ifade->execute(['id' => $haberId]);
        $h = $ifade->fetch();

        if (!$h || ($h['kategori'] ?? '') === 'yemek-tarifleri') {
            return;
        }

        // Yapay zekanin karari (04.11.2026); kaynagin "son dakika" demesi yetmiyor.
        $sonDakika = (int) ($h['son_dakika'] ?? 0) === 1;

        if (!$sonDakika && (int) $h['onem'] < $a['esik']) {
            return;
        }

        // Gonderilenler: id => zaman (son 2 gun). Ayni haber bir kez; gunluk tavan.
        $gonderilen = json_decode(ayar_oku('telegram_gonderilen'), true);
        $gonderilen = is_array($gonderilen) ? array_filter($gonderilen, static fn ($z): bool => (int) $z > time() - 2 * 86400) : [];

        if (isset($gonderilen[$haberId])
            || count(array_filter($gonderilen, static fn ($z): bool => (int) $z > time() - 86400)) >= TELEGRAM_GUNLUK_TAVAN) {
            return;
        }

        $gonderilen[$haberId] = time();
        ayar_yaz('telegram_gonderilen', (string) json_encode($gonderilen));

        // Haberi yayina alan istek (ajan, panel) Telegram'i beklemesin.
        register_shutdown_function(static function () use ($h, $sonDakika): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            ignore_user_abort(true);
            $sonuc = telegram_haber_gonder($h, $sonDakika);

            if (!$sonuc['tamam']) {
                ayar_yaz('telegram_son_hata', date('d.m H:i') . ' — ' . $sonuc['hata']);
                error_log('[valentra] telegram: ' . $sonuc['hata']);
            } else {
                ayar_yaz('telegram_son_gonderim', date('d.m H:i') . ' — ' . (string) $h['baslik']);
            }
        });
    } catch (Throwable $e) {
        error_log('[valentra] telegram: ' . $e->getMessage());
    }
}
