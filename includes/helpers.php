<?php
declare(strict_types=1);

/**
 * Cikti kacislama. HTML icine basilan her dinamik deger bundan gecer.
 */
function e(?string $deger): string
{
    return htmlspecialchars($deger ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Turkce karakterleri koruyarak URL uyumlu slug uretir.
 */
function slug_uret(string $metin): string
{
    $harita = [
        'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'I' => 'i',
        'İ' => 'i', 'i' => 'i', 'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's',
        'ü' => 'u', 'Ü' => 'u', 'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i',
        'û' => 'u', 'Û' => 'u',
    ];

    $metin = strtr($metin, $harita);
    $metin = mb_strtolower($metin, 'UTF-8');
    $metin = preg_replace('/[^a-z0-9]+/u', '-', $metin) ?? '';
    $metin = trim($metin, '-');

    if ($metin === '') {
        return 'haber';
    }

    return mb_substr($metin, 0, 200, 'UTF-8');
}

/**
 * Metni kelime sinirinda kisaltir.
 */
function kisalt(string $metin, int $uzunluk = 160): string
{
    $metin = trim(preg_replace('/\s+/u', ' ', strip_tags($metin)) ?? '');

    if (mb_strlen($metin, 'UTF-8') <= $uzunluk) {
        return $metin;
    }

    $kesit = mb_substr($metin, 0, $uzunluk, 'UTF-8');
    $bosluk = mb_strrpos($kesit, ' ', 0, 'UTF-8');

    if ($bosluk !== false && $bosluk > (int) ($uzunluk * 0.6)) {
        $kesit = mb_substr($kesit, 0, $bosluk, 'UTF-8');
    }

    return rtrim($kesit, " ,.;:-") . '…';
}

/**
 * "12 Eylül 2026, 14:30" bicimi.
 */
function tarih_bicimle(?string $tarih, bool $saatIle = true): string
{
    if ($tarih === null || $tarih === '' || str_starts_with($tarih, '0000')) {
        return '';
    }

    $zaman = strtotime($tarih);
    if ($zaman === false) {
        return '';
    }

    $aylar = [
        1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
        'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık',
    ];

    $bicim = (int) date('j', $zaman) . ' ' . $aylar[(int) date('n', $zaman)] . ' ' . date('Y', $zaman);

    return $saatIle ? $bicim . ', ' . date('H:i', $zaman) : $bicim;
}

/**
 * Etiket dizisini virgullu metinden cozer.
 */
function etiketleri_coz(string $etiketler): array
{
    $parcalar = array_map('trim', explode(',', $etiketler));

    return array_values(array_filter($parcalar, static fn (string $t): bool => $t !== ''));
}

/**
 * Oturuma bagli CSRF jetonu uretir/dondurur.
 */
function csrf_jeton(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * CSRF jetonunu dogrular; hatali istekte 400 ile durur.
 */
function csrf_dogrula(?string $gonderilen): void
{
    if (!is_string($gonderilen) || empty($_SESSION['csrf'])
        || !hash_equals($_SESSION['csrf'], $gonderilen)) {
        http_response_code(400);
        exit('Geçersiz istek.');
    }
}

function yonlendir(string $hedef): void
{
    header('Location: ' . $hedef);
    exit;
}

/**
 * Yalnizca http/https adreslerine izin verir.
 *
 * Ajan disaridan URL gonderdigi icin sema dogrulamasi sart:
 * htmlspecialchars "javascript:" adresini zararsiz hale getirmez,
 * href icinde tiklandiginda yine calisir.
 */
function guvenli_url(?string $url): string
{
    $url = trim((string) $url);

    if ($url === '') {
        return '';
    }

    $sema = parse_url($url, PHP_URL_SCHEME);

    if (!is_string($sema) || !in_array(strtolower($sema), ['http', 'https'], true)) {
        return '';
    }

    if (parse_url($url, PHP_URL_HOST) === null) {
        return '';
    }

    return $url;
}

/**
 * Varlık adresine sürüm ekler.
 *
 * Deploy sonrası tarayıcı eski CSS'i önbellekten vermesin diye dosyanın
 * değişiklik zamanını sorgu dizesine koyar. Dosya değişmediği sürece
 * adres sabit kalır, yani önbellek yine çalışır.
 */
function varlik(string $yol): string
{
    $tamYol = __DIR__ . '/..' . $yol;
    $zaman  = is_file($tamYol) ? filemtime($tamYol) : false;

    return $zaman === false ? $yol : $yol . '?s=' . $zaman;
}

/** varlik() + kaçış: HTML özniteliğine doğrudan basılabilir. */
function e_varlik(string $yol): string
{
    return e(varlik($yol));
}
