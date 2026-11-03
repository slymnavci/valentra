<?php
declare(strict_types=1);

/**
 * Paylasim dugmeleri ve WhatsApp kanali cagrisi (03.11.2026, site sahibi:
 * "kullaniciyi geri ceken ozellikler, sirayla"; Turkiye'de haber en cok
 * WhatsApp'tan yayiliyor).
 *
 *   paylas_cubugu_html($baslik, $yol, 'ust')  kunyenin altinda kucuk simgeler
 *   paylas_cubugu_html($baslik, $yol, 'alt')  metnin sonunda buyuk cubuk,
 *                                            WhatsApp one cikiyor
 *   whatsapp_kanal_html()                     "Kanala katil" kutusu (adres
 *                                            panelde Kunye'de girildiyse)
 *
 * Baglantilar sitenin hic betigi olmadan da calisiyor; assets/paylas.js
 * yalnizca "Baglantiyi kopyala"yi ve telefonun kendi paylas menusunu
 * ekliyor (kaydirma akisinda gelen haberde de: olay yetkisi belgeye).
 * Her baglantida utm_source: hangi kanalin ziyaretci getirdigi Analytics'te
 * gorunuyor.
 */

require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/ayarlar.php';

/** Gecerli WhatsApp kanal adresi; yoksa bos. */
function whatsapp_kanal_adresi(): string
{
    $adres = trim(ayar_oku('kunye_whatsapp'));

    return preg_match('#^https://(www\.)?whatsapp\.com/channel/[A-Za-z0-9_-]{10,}/?$#', $adres) === 1 ? $adres : '';
}

/** Paylasilan adres: kanal izi eklenmis tam adres. */
function paylas_adresi(string $yol, string $kaynak): string
{
    $tam = str_starts_with($yol, 'http') ? $yol : site_adresi() . $yol;

    return $tam . (str_contains($tam, '?') ? '&' : '?') . 'utm_source=' . rawurlencode($kaynak) . '&utm_medium=paylasim';
}

/** @return array<string,string> simge adi => SVG */
function paylas_simgeleri(): array
{
    $svg = static fn (string $ic, string $ek = ''): string =>
        '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"' . $ek . '>' . $ic . '</svg>';

    return [
        'whatsapp' => $svg('<path fill="currentColor" d="M12 2.2A9.7 9.7 0 0 0 3.6 16.8L2.3 21.7l5-1.3A9.7 9.7 0 1 0 12 2.2zm0 17.7a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 19.9zm4.4-6c-.2-.1-1.4-.7-1.7-.8-.2-.1-.4-.1-.5.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 2s.8 2.3.9 2.5c.1.2 1.6 2.5 4 3.5 1.5.6 2 .7 2.8.6.4-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1 0-.1-.2-.2-.4-.3z"/>'),
        'x'        => $svg('<path fill="currentColor" d="M17.8 2.5h3.2l-7 8 8.2 11h-6.4l-5-6.6-5.8 6.6H1.8l7.5-8.6L1.4 2.5H8l4.6 6 5.2-6zm-1.1 17h1.8L7.4 4.3H5.5l11.2 15.2z"/>'),
        'facebook' => $svg('<path fill="currentColor" d="M13.5 21.5v-8h2.7l.4-3.2h-3.1V8.3c0-.9.3-1.5 1.6-1.5h1.7V4a22 22 0 0 0-2.5-.1c-2.4 0-4.1 1.5-4.1 4.2v2.3H7.5v3.2h2.7v8h3.3z"/>'),
        'telegram' => $svg('<path fill="currentColor" d="M21.4 3.6 2.9 10.8c-1.3.5-1.2 1.2-.2 1.5l4.7 1.5 1.8 5.6c.2.6.4.8.9.8.4 0 .6-.2.9-.4l2.3-2.2 4.7 3.5c.9.5 1.5.2 1.7-.8l3.1-14.6c.3-1.3-.5-1.9-1.4-1.5zM9.6 14.2l8.7-7.8c.4-.3-.1-.5-.6-.2l-10.7 6.7"/>'),
        'baglanti' => $svg('<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>'),
        'paylas'   => $svg('<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v12M7.5 7.5 12 3l4.5 4.5M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/>'),
    ];
}

/**
 * Paylasim cubugu. $konum 'ust' (kucuk simgeler) ya da 'alt' (buyuk,
 * "Bu haberi paylasin").
 */
function paylas_cubugu_html(string $baslik, string $yol, string $konum = 'alt'): string
{
    $s = paylas_simgeleri();
    $metin = trim($baslik);
    $baglar = [
        'whatsapp' => ['WhatsApp', 'https://api.whatsapp.com/send?text=' . rawurlencode($metin . "\n" . paylas_adresi($yol, 'whatsapp'))],
        'x'        => ['X', 'https://twitter.com/intent/tweet?text=' . rawurlencode($metin) . '&url=' . rawurlencode(paylas_adresi($yol, 'x'))],
        'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode(paylas_adresi($yol, 'facebook'))],
        'telegram' => ['Telegram', 'https://t.me/share/url?url=' . rawurlencode(paylas_adresi($yol, 'telegram')) . '&text=' . rawurlencode($metin)],
    ];

    $html = '<div class="paylas paylas-' . ($konum === 'ust' ? 'ust' : 'alt') . '" data-paylas-baslik="' . e($metin) . '" data-paylas-adres="' . e(paylas_adresi($yol, 'paylas')) . '">';

    if ($konum !== 'ust') {
        $html .= '<span class="paylas-etiket">Bu haberi paylaşın</span>';
    }

    foreach ($baglar as $ad => [$gorunen, $url]) {
        $yazi = $konum !== 'ust' && $ad === 'whatsapp' ? '<span>WhatsApp\'ta paylaş</span>' : '<span class="gizli-etiket">' . e($gorunen) . ' ile paylaş</span>';
        $html .= '<a class="paylas-dugme paylas-' . $ad . '" href="' . e($url) . '" target="_blank" rel="noopener nofollow" data-paylas="' . $ad . '" title="' . e($gorunen) . '">'
            . $s[$ad] . $yazi . '</a>';
    }

    $html .= '<button type="button" class="paylas-dugme paylas-baglanti" data-paylas="kopyala" title="Bağlantıyı kopyala">' . $s['baglanti']
        . '<span class="gizli-etiket">Bağlantıyı kopyala</span></button>';
    // Telefonun kendi paylas menusu (Instagram, SMS, ...): betik destek varsa gosteriyor.
    $html .= '<button type="button" class="paylas-dugme paylas-yerel" data-paylas="yerel" title="Paylaş" hidden>' . $s['paylas']
        . '<span' . ($konum === 'ust' ? ' class="gizli-etiket"' : '') . '>Diğer</span></button>';

    return $html . '</div>';
}

/** "Valentra WhatsApp kanalina katilin" kutusu; adres girilmediyse bos. */
function whatsapp_kanal_html(): string
{
    $adres = whatsapp_kanal_adresi();

    if ($adres === '') {
        return '';
    }

    return '<aside class="wa-kanal"><span class="wa-kanal-simge">' . paylas_simgeleri()['whatsapp'] . '</span>'
        . '<div><strong>Valentra WhatsApp kanalına katılın</strong>'
        . '<span>Günün önemli haberleri ve son dakika gelişmeleri doğrudan telefonunuza.</span></div>'
        . '<a href="' . e($adres) . '" target="_blank" rel="noopener" data-paylas="kanal">Kanala katıl</a></aside>';
}
