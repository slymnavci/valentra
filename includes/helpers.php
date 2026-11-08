<?php
declare(strict_types=1);

require_once __DIR__ . '/url.php';

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
/**
 * Ay numarasından kısa Türkçe ay adı ("Eyl").
 *
 * Takvim kutusunda gun ve ay alt alta duruyor; tam ad ("Eylül")
 * kutuyu tasiriyor.
 */
function ay_kisa(int $ay): string
{
    $adlar = [
        1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis',
        5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Ağu',
        9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara',
    ];

    return $adlar[$ay] ?? '';
}

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

/**
 * Dar bicimli metni HTML'e cevirir: bos satirla ayrilan paragraflar,
 * "## " ile baslayan ara basliklar, satirlari "- " ile baslayan maddeler.
 *
 * Kose yazilari, haberlerin "Ne yapilmali?" kontrol listesi ve "Uygulama
 * ornegi" bu bicimde. Model ve panel yalnizca bunu uretiyor; ham HTML
 * kabul edilmiyor, her sey kacisliyor.
 */
/**
 * Yapay zeka metnindeki "calisma dili" kalintilari (08.11.2026, dis denetim
 * raporu: "Bu tablonun surup surmeyecegi malzemede belli degil").
 * Yonergeler artik bunu yasakliyor; bu suzgec onceden yayimlanmis
 * metinleri de okura uygun hale getiriyor. Yemek tariflerinde
 * kullanilmiyor: orada "malzeme" gercek kelime.
 */
function okur_dili(string $metin): string
{
    static $kaliplar = null;

    $kaliplar ??= [
        '/\bmalzemede\s+(?:henüz\s+)?belli\s+değil/ui'                          => 'henüz belli değil',
        '/\bmalzemede\s+(?:yer\s+almıyor|geçmiyor|belirtilmiyor)/ui'               => 'haberlerde yer almıyor',
        '/\bmalzemede\s+(?:açıklanmıyor|netleşmiyor)/ui'                            => 'henüz açıklanmadı',
        // Tek basina "malzemede/malzemeden/malzemeye gore" DEGISTIRILMIYOR:
        // "geri donusturulmus malzemeden uretildi", "malzemedeki asbest",
        // "kullanilan malzemeye gore degisiyor" gercek haber dili.
        '/\b(?:verilen|elimizdeki)\s+(?:haberlerde|kaynaklarda|metinlerde|bilgilerde)\b/ui' => 'haberlerde',
    ];

    foreach ($kaliplar as $kalip => $yerine) {
        // Cumle basindaysa buyuk harfle ("Malzemede yer almiyor" -> "Haberlerde ...").
        $metin = (string) preg_replace_callback($kalip, static fn (array $m): string =>
            mb_strtoupper(mb_substr($m[0], 0, 1)) === mb_substr($m[0], 0, 1)
                ? mb_strtoupper(mb_substr($yerine, 0, 1)) . mb_substr($yerine, 1)
                : $yerine, $metin);
    }

    return $metin;
}

function bicimli_metin_html(string $icerik): string
{
    $html = '';

    foreach (preg_split('/\n\s*\n/u', trim($icerik)) ?: [] as $blok) {
        $blok = trim($blok);

        if ($blok === '') {
            continue;
        }

        if (preg_match('/^#{2,3}\s+(.+)$/u', $blok, $m) && !str_contains($blok, "\n")) {
            $html .= '<h2>' . e(trim($m[1])) . "</h2>\n";
            continue;
        }

        $satirlar = preg_split('/\n/u', $blok) ?: [];
        $maddeMi  = $satirlar !== [] && array_reduce(
            $satirlar,
            static fn (bool $t, string $s): bool => $t && preg_match('/^\s*[-•]\s+/u', $s) === 1,
            true
        );

        if ($maddeMi) {
            $html .= "<ul>\n";

            foreach ($satirlar as $satir) {
                $html .= '<li>' . e(trim((string) preg_replace('/^\s*[-•]\s+/u', '', $satir))) . "</li>\n";
            }

            $html .= "</ul>\n";
            continue;
        }

        $html .= '<p>' . nl2br(e($blok)) . "</p>\n";
    }

    return $html;
}
