<?php
declare(strict_types=1);

/**
 * Kisa sureli sayfa onbellegi (ana sayfa, gazeteler, kategori, video, haber).
 *
 * Site sahibinin istegi (30.10.2026, "site hizi ve SEO denetimi"):
 * GitHub Actions'tan olculen ilk bayt suresi ana sayfada 1,5-1,7 sn,
 * ValPapel'de 1,8-2 sn, ValSpor ve Video'da 1,2 sn idi (kategori sayfasi
 * 0,5 sn). Sebep veritabani: ana sayfa ve gazeteler her istekte 40'tan
 * fazla sorgu calistiriyor, cogu yayindaki butun haberleri tariyor
 * (baslik/etiket LIKE kosullari dizin kullanamiyor). 21.000 haberlik
 * yerel denemede ana sayfa 0,8-1 sn, ValPapel 2,6 sn surdu.
 *
 * Ziyaretciye ayni dakika icinde ayni sayfa uretilmiyor: ilk istek
 * sayfayi uretip dosyaya yaziyor, sonrakiler SAYFA_ONBELLEK_SURE saniye
 * boyunca dosyadan okunuyor. Yeni haber en gec bu sure sonunda gorunur.
 *
 * Ziyaret sayaci (includes/ziyaret.php) her istekte calismaya devam
 * ediyor: onbellekten sunulan sayfada da satir yaziliyor ve imzali
 * dogrulama betigi isteğe ozel olarak yerine konuyor. Sayfada kisiye
 * ozel baska bir sey yok (tema secimi ve cerez onayi tarayicida).
 *
 * Onbellek YOK: yonetici cerezi olan tarayicilar (yonetici her zaman
 * guncel sayfayi gormeli), GET/HEAD disi istekler, 200 disi yanitlar ve
 * sayfanin tanimadigi sorgu parametreleri (sinirsiz dosya uretilmesin).
 * Klasor yazilamazsa sayfa eskisi gibi her istekte uretiliyor.
 */

const SAYFA_ONBELLEK_SURE   = 60;
const SAYFA_ONBELLEK_DIZIN  = __DIR__ . '/../onbellek/sayfa';
const SAYFA_ONBELLEK_ZIYARET = '<!--valentra-ziyaret-betigi-->';

/** Izleme parametreleri anahtara girmiyor, onbellegi de engellemiyor. */
const SAYFA_ONBELLEK_YOK_SAYILAN = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
                                    'fbclid', 'gclid', 'yclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'amp', 'source', 'src', 'igshid'];

/**
 * Sayfanin basinda, bootstrap'tan hemen sonra cagrilir. Taze kopya varsa
 * onu basar ve istegi bitirir; yoksa uretilen sayfayi yakalar.
 *
 * @param list<string> $parametreler sayfanin kullandigi sorgu parametreleri
 */
function sayfa_onbellek(array $parametreler): void
{
    $yontem = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (($yontem !== 'GET' && $yontem !== 'HEAD') || isset($_COOKIE['valentra_admin']) || PHP_SAPI === 'cli') {
        return;
    }

    $sorgu = array_diff_key($_GET, array_flip(SAYFA_ONBELLEK_YOK_SAYILAN));

    if (array_diff_key($sorgu, array_flip($parametreler)) !== []) {
        return;
    }

    foreach ($sorgu as $deger) {
        if (!is_string($deger) || strlen($deger) > 200) {
            return;
        }
    }

    // Anahtar: adres yolu (/haber/x ile haber.php?h=x'in canonical'i farkli),
    // sayfanin parametreleri ve alan adi.
    ksort($sorgu);
    $yol   = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $dosya = SAYFA_ONBELLEK_DIZIN . '/' . md5(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) . '|' . $yol . '?' . http_build_query($sorgu)) . '.html';
    $GLOBALS['sayfa_onbellek_dosya'] = $dosya;

    $zaman = @filemtime($dosya);

    if ($zaman !== false && time() - $zaman < SAYFA_ONBELLEK_SURE && sayfa_onbellek_sun($dosya)) {
        exit;
    }

    ob_start('sayfa_onbellek_yaz');
}

/** Onbellekteki kopyayi basar; okunamazsa false (sayfa yeniden uretilir). */
function sayfa_onbellek_sun(string $dosya): bool
{
    $icerik = @file_get_contents($dosya);

    if ($icerik === false || !str_starts_with($icerik, '{')) {
        return false;
    }

    // Ilk satir ziyaret kaydinin bilgisi: {"h":haber_id,"b":"baslik"}
    [$ust, $html] = explode("\n", $icerik, 2) + [1 => ''];
    $bilgi        = json_decode($ust, true);

    if (!is_array($bilgi) || $html === '') {
        return false;
    }

    require_once __DIR__ . '/ziyaret.php';
    ziyaret_kaydet(isset($bilgi['h']) ? (int) $bilgi['h'] : null, (string) ($bilgi['b'] ?? ''));

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Valentra-Onbellek: var');
    echo str_replace(SAYFA_ONBELLEK_ZIYARET, ziyaret_betigi(), $html);

    return true;
}

/** sayfa_ust.php: ziyaret kaydinin bilgisi onbellege de yaziliyor. */
function sayfa_onbellek_bilgi(?int $haberId, string $baslik): void
{
    $GLOBALS['sayfa_onbellek_bilgi'] = ['h' => $haberId, 'b' => $baslik];
}

/**
 * sayfa_alt.php: ziyaret betiginin yeri. Onbellek aciksa yer tutucu
 * (yazarken ve sunarken isteğe ozel betikle degistiriliyor).
 */
function sayfa_onbellek_ziyaret_betigi(): string
{
    return isset($GLOBALS['sayfa_onbellek_dosya']) ? SAYFA_ONBELLEK_ZIYARET : ziyaret_betigi();
}

/** ob_start geri cagirisi: sayfayi dosyaya yazar, ziyaretciye betikli halini dondurur. */
function sayfa_onbellek_yaz(string $html): string
{
    $dosya = (string) ($GLOBALS['sayfa_onbellek_dosya'] ?? '');
    $bilgi = $GLOBALS['sayfa_onbellek_bilgi'] ?? null;

    if ($dosya !== '' && is_array($bilgi) && http_response_code() === 200
        && str_contains($html, '</html>') && !headers_list_yonlendirme()) {
        if (!is_dir(SAYFA_ONBELLEK_DIZIN)) {
            @mkdir(SAYFA_ONBELLEK_DIZIN, 0755, true);
        }

        $gecici = $dosya . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($gecici, json_encode($bilgi, JSON_UNESCAPED_UNICODE) . "\n" . $html) !== false) {
            @rename($gecici, $dosya);
        } else {
            @unlink($gecici);
        }

        // Ara sira eski dosyalari temizle (klasor sismesin).
        if (random_int(1, 200) === 1) {
            foreach (glob(SAYFA_ONBELLEK_DIZIN . '/*') ?: [] as $eski) {
                // index.html klasorun bos sayfasi, onbellek degil.
                if ((str_ends_with($eski, '.html') || str_ends_with($eski, '.tmp')) && basename($eski) !== 'index.html') {
                    if (time() - (int) @filemtime($eski) > 3600) {
                        @unlink($eski);
                    }
                }
            }
        }
    }

    return str_replace(SAYFA_ONBELLEK_ZIYARET, function_exists('ziyaret_betigi') ? ziyaret_betigi() : '', $html);
}

function headers_list_yonlendirme(): bool
{
    foreach (headers_list() as $baslik) {
        if (stripos($baslik, 'Location:') === 0) {
            return true;
        }
    }

    return false;
}
