<?php
declare(strict_types=1);

/**
 * Bir RSS/Atom beslemesini deneyip sonucu raporlar.
 *
 * Kaynak adreslerinin calisip calismadigini panelden gormek icin.
 * Ajanin kullandigi ayristirma mantiginin aynisini uygular.
 *
 * @return array{tamam:bool,mesaj:string,adet:int,ornek:string}
 */
function besleme_dene(string $url, int $zamanAsimi = 15): array
{
    $url = guvenli_url($url);

    if ($url === '') {
        return ['tamam' => false, 'mesaj' => 'Adres http:// veya https:// ile başlamalı.', 'adet' => 0, 'ornek' => ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $zamanAsimi,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'ValentraBot/1.0 (+https://valentra.com.tr)',
        CURLOPT_ENCODING       => '',
    ]);

    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata  = curl_error($ch);
    curl_close($ch);

    if (!is_string($govde)) {
        return ['tamam' => false, 'mesaj' => 'Bağlantı kurulamadı: ' . $hata, 'adet' => 0, 'ornek' => ''];
    }

    if ($kod < 200 || $kod >= 300) {
        return ['tamam' => false, 'mesaj' => 'Sunucu HTTP ' . $kod . ' döndü.', 'adet' => 0, 'ornek' => ''];
    }

    $onceki = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($govde);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);

    if ($xml === false) {
        return [
            'tamam' => false,
            'mesaj' => 'Yanıt geçerli XML değil. Adres RSS beslemesi yerine normal sayfa olabilir.',
            'adet'  => 0,
            'ornek' => '',
        ];
    }

    $ogeler = [];

    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $oge) {
            $ogeler[] = trim((string) $oge->title);
        }
    } elseif (isset($xml->entry)) {
        foreach ($xml->entry as $oge) {
            $ogeler[] = trim((string) $oge->title);
        }
    }

    if ($ogeler === []) {
        return [
            'tamam' => false,
            'mesaj' => 'XML okundu ama içinde haber öğesi (item/entry) yok.',
            'adet'  => 0,
            'ornek' => '',
        ];
    }

    return [
        'tamam' => true,
        'mesaj' => count($ogeler) . ' haber okundu.',
        'adet'  => count($ogeler),
        'ornek' => mb_substr($ogeler[0], 0, 110, 'UTF-8'),
    ];
}
