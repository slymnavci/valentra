<?php
declare(strict_types=1);

/**
 * Site içi adresler.
 *
 * Baglantilar tek yerden uretiliyor. Sebebi su: temiz adres
 * ("/haber/kdv-teblig") ile sorgulu adres ("/haber.php?h=kdv-teblig")
 * arasinda gecis yapabilmek. Adresler sablonlara elle yazilsaydi bu
 * gecis on kusur dosyaya dagilirdi ve biri unutuldugunda sessizce
 * kirik bag kalirdi.
 *
 * Neden temiz adres: arama motorlari icin okunabilir adres kucuk ama
 * gercek bir fark; ayrica adres paylasildiginda ne oldugu anlasiliyor.
 * Sorgu dizesi sayfalamada kaliyor, orasi indekslenmiyor zaten.
 *
 * Neden anahtarla acilip kapaniyor: temiz adresler mod_rewrite'a
 * bagli. Paylasimli hostingde bunun acik oldugunu onceden denemek
 * mumkun degil ve kapaliysa TUM baglantilar 404 dondururdu — yani
 * siteyi topluca dusuren bir degisiklik olurdu. Bu yuzden varsayilan
 * KAPALI: panelden once sinaniyor, calistigi gorulunce aciliyor.
 * Eski adresler her iki durumda da calismaya devam ediyor.
 */

require_once __DIR__ . '/ayarlar.php';

/**
 * Temiz adresler açık mı?
 *
 * Deger her baglantida degil istek basina bir kez okunuyor; tek bir
 * sayfada yuzlerce bag uretiliyor ve her biri icin sorgu calistirmak
 * gereksiz yuk olurdu.
 */
function temiz_adres_acik(): bool
{
    static $acik = null;

    if ($acik === null) {
        $acik = ayar_oku('temiz_adres') === '1';
    }

    return $acik;
}

/** Haber sayfasının adresi. */
function haber_yolu(string $slug): string
{
    return temiz_adres_acik()
        ? '/haber/' . rawurlencode($slug)
        : '/haber.php?h=' . rawurlencode($slug);
}

/**
 * Kategori sayfasının adresi.
 *
 * Sayfa numarasi her iki bicimde de sorgu dizesinde kaliyor: robots.txt
 * "?sayfa=" iceren adresleri indekslemeye kapatiyor, yani orada temiz
 * adresin kazandiracagi bir sey yok.
 */
function kategori_yolu(string $slug, int $sayfa = 1): string
{
    $yol = temiz_adres_acik()
        ? '/kategori/' . rawurlencode($slug)
        : '/kategori.php?k=' . rawurlencode($slug);

    if ($sayfa > 1) {
        $yol .= (temiz_adres_acik() ? '?' : '&') . 'sayfa=' . $sayfa;
    }

    return $yol;
}

/** Kanun metni sayfasının adresi. */
function kanun_yolu(string $anahtar, bool $tani = false): string
{
    $yol = temiz_adres_acik()
        ? '/kanun/' . rawurlencode($anahtar)
        : '/kanun.php?k=' . rawurlencode($anahtar);

    if ($tani) {
        $yol .= (temiz_adres_acik() ? '?' : '&') . 'tani=1';
    }

    return $yol;
}

/** Kanun listesi sayfası. */
function kanunlar_yolu(): string
{
    return temiz_adres_acik() ? '/kanunlar' : '/kanunlar.php';
}

/** Pratik bilgiler sayfası. */
function pratik_yolu(): string
{
    return temiz_adres_acik() ? '/pratik-bilgiler' : '/pratik-bilgiler.php';
}
