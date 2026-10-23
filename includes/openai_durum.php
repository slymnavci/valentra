<?php
declare(strict_types=1);

/**
 * OpenAI (GPT-6 Luna) kredi/anahtar durumu.
 *
 * Site sahibinin istegi (23.10.2026): "yonetimde bir uyari paneli ac;
 * kredi biterse bana haber ver, ben kredi yuklerim". Ajan her toplama
 * calismasinin sonunda durumu bildiriyor (api/ajan-durum.php); kredi
 * bitmisse ya da anahtar gecersizse panelin her sayfasinda uyari
 * cikiyor. Kredi yuklenip OpenAI bir istegi cevaplayinca ajan "tamam"
 * bildiriyor ve uyari kendiliginden kalkiyor.
 */

require_once __DIR__ . '/ayarlar.php';

const OPENAI_DURUM_AYARI = 'openai_durum';

/**
 * @param array{model:string,durum:string,mesaj:string} $durum
 */
function openai_durum_yaz(array $durum): void
{
    $onceki = openai_durum_oku();

    $kayit = [
        'model' => mb_substr($durum['model'], 0, 80, 'UTF-8'),
        'durum' => $durum['durum'],
        'mesaj' => mb_substr($durum['mesaj'], 0, 300, 'UTF-8'),
        'zaman' => time(),
        // Sorun ilk ne zaman goruldu: "3 gundur kredi yok" diyebilmek icin.
        'ilk'   => ($onceki !== null && $onceki['durum'] === $durum['durum'])
            ? (int) ($onceki['ilk'] ?? $onceki['zaman'])
            : time(),
    ];

    ayar_yaz(OPENAI_DURUM_AYARI, (string) json_encode($kayit, JSON_UNESCAPED_UNICODE));
}

/**
 * @return array{model:string,durum:string,mesaj:string,zaman:int,ilk:int}|null
 */
function openai_durum_oku(): ?array
{
    $veri = json_decode(ayar_oku(OPENAI_DURUM_AYARI), true);

    return is_array($veri) && isset($veri['durum'], $veri['zaman']) ? $veri : null;
}

/**
 * Panelde uyari gosterilecek durum (kredi bitti ya da anahtar gecersiz), yoksa null.
 *
 * @return array{model:string,durum:string,mesaj:string,zaman:int,ilk:int}|null
 */
function openai_durum_uyarisi(): ?array
{
    $durum = openai_durum_oku();

    return $durum !== null && in_array($durum['durum'], ['kredi', 'anahtar'], true) ? $durum : null;
}
