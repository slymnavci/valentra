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
    /*
     * Ekonomi, spor, kultur-sanat, magazin ve yemek tarifleri ValPapel,
     * ValSpor ve ValMagazin'in sayfalarinda (29.10.2026; bkz.
     * includes/gazeteler.php). Eski /kategori/... adresi kalici olarak
     * oraya yonleniyor (kategori.php).
     */
    if (isset(KATEGORI_GAZETESI[$slug])) {
        [$gazete, $bolum] = KATEGORI_GAZETESI[$slug];

        // On sayfa bir liste degil; 2. sayfa ve sonrasi "tum haberler".
        if ($bolum === '' && $sayfa > 1) {
            $bolum = 'haberler';
        }

        return gazete_yolu($gazete, $bolum, $sayfa);
    }

    return kategori_ham_yolu($slug, $sayfa);
}

/** Kategori sayfasinin kendi adresi (gazeteye eslenmeden; spor bolumleri). */
function kategori_ham_yolu(string $slug, int $sayfa = 1): string
{
    $yol = temiz_adres_acik()
        ? '/kategori/' . rawurlencode($slug)
        : '/kategori.php?k=' . rawurlencode($slug);

    if ($sayfa > 1) {
        $yol .= (temiz_adres_acik() ? '?' : '&') . 'sayfa=' . $sayfa;
    }

    return $yol;
}

/** Gazetesi olan kategoriler: slug => [gazete, bolum]. */
const KATEGORI_GAZETESI = [
    'ekonomi'         => ['valpapel', ''],
    'spor'            => ['valspor', ''],
    'kultur-sanat'    => ['valmagazin', 'sanat'],
    'magazin'         => ['valmagazin', 'sanatcilar'],
    'yemek-tarifleri' => ['valmagazin', 'yemek-tarifleri'],
];

/** ValPapel, ValSpor, ValMagazin: on sayfa ya da bir bolumu (bkz. includes/gazeteler.php). */
function gazete_yolu(string $gazete, string $bolum = '', int $sayfa = 1): string
{
    if (temiz_adres_acik()) {
        $yol = '/' . rawurlencode($gazete) . ($bolum !== '' ? '/' . rawurlencode($bolum) : '');

        return $sayfa > 1 ? $yol . '?sayfa=' . $sayfa : $yol;
    }

    $parametre = array_filter(['g' => $gazete, 'b' => $bolum, 'sayfa' => $sayfa > 1 ? (string) $sayfa : '']);

    return '/gazete.php?' . http_build_query($parametre);
}

/**
 * Etiket sayfasının adresi (28.10.2026; bkz. includes/etiket.php).
 * Etiket kucuk harfle ve oldugu gibi (Turkce harfler kodlanarak).
 */
function etiket_yolu(string $etiket, int $sayfa = 1): string
{
    require_once __DIR__ . '/etiket.php';

    $anahtar = rawurlencode(etiket_normal($etiket));
    $yol     = temiz_adres_acik() ? '/etiket/' . $anahtar : '/etiket.php?e=' . $anahtar;

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

/**
 * Vergi takvimi sayfası.
 *
 * Yil verilmezse icinde bulundugumuz yil acilir; adreste yil
 * gorunmez. Gecmis ve gelecek yil icin parametre ekleniyor.
 */
function takvim_yolu(?int $yil = null): string
{
    $taban = temiz_adres_acik() ? '/vergi-takvimi' : '/vergi-takvimi.php';

    if ($yil === null || $yil === (int) date('Y')) {
        return $taban;
    }

    /*
     * Her iki bicimde de '?'.
     *
     * Burada bir ara (temiz_adres_acik() ? '?yil=' : '&yil=') yaziliyordu.
     * Temiz adresler KAPALIYKEN — ki deponun belgeledigi varsayilan bu —
     * $taban '/vergi-takvimi.php' oluyor ve sorgu dizesi HIC yok; basina
     * '&' koymak '/vergi-takvimi.php&yil=2025' uretiyordu. Sunucu bunu
     * sorgu degil YOL parcasi sayar, $_GET['yil'] hic dolmaz ve
     * takvimdeki onceki/sonraki yil baglantilarinin ikisi de calismazdi.
     * Iki bicimde de $taban sorgusuz oldugu icin dogrusu '?'.
     */
    return $taban . '?yil=' . $yil;
}

/** Tek bir pratik bilginin kendi sayfası. */
function pratik_bilgi_yolu(string $anahtar): string
{
    return temiz_adres_acik()
        ? '/pratik/' . rawurlencode($anahtar)
        : '/pratik-bilgi.php?p=' . rawurlencode($anahtar);
}

/**
 * Resmî Gazete sayfası.
 *
 * Tarih verilmezse en son kayitli sayi acilir; adreste tarih gorunmez.
 * Temiz adreste tarih yolun parcasi (/resmi-gazete/2026-09-24): her gunun
 * sayisi kendi basina bir sayfa. Sorgulu bicimde '?' — takvim_yolu()'ndaki
 * '&' hatasinin aynisina dusmemek icin.
 */
function rg_yolu(string $tarih = ''): string
{
    if ($tarih === '') {
        return temiz_adres_acik() ? '/resmi-gazete' : '/resmi-gazete.php';
    }

    return temiz_adres_acik()
        ? '/resmi-gazete/' . rawurlencode($tarih)
        : '/resmi-gazete.php?tarih=' . rawurlencode($tarih);
}

/** Köşe yazısı ("Valentra Diyor ki…") sayfası. */
function kose_yolu(string $slug): string
{
    return temiz_adres_acik()
        ? '/valentra-diyor-ki/' . rawurlencode($slug)
        : '/kose-yazisi.php?y=' . rawurlencode($slug);
}

/** Tüm köşe yazıları, günlere göre. Sayfa numarası her iki biçimde sorguda. */
/** Piyasalar sayfası: BIST, döviz, altın, şirketler ve ekonomik göstergeler. */
function piyasalar_yolu(): string
{
    return temiz_adres_acik() ? '/piyasalar' : '/piyasalar.php';
}

/**
 * Şirket sayfasının adresi; kod boşsa şirket listesi. Dönem sorgu
 * dizesinde (varsayilan donem adreste yazilmiyor).
 */
function sirket_yolu(string $kod = '', string $donem = ''): string
{
    if ($kod === '') {
        $yol = temiz_adres_acik() ? '/sirket' : '/sirket.php';
    } else {
        $yol = temiz_adres_acik()
            ? '/sirket/' . rawurlencode($kod)
            : '/sirket.php?kod=' . rawurlencode($kod);
    }

    if ($donem !== '' && $donem !== '1y') {
        $yol .= (str_contains($yol, '?') ? '&' : '?') . 'donem=' . rawurlencode($donem);
    }

    return $yol;
}

function kose_liste_yolu(int $sayfa = 1): string
{
    $yol = temiz_adres_acik() ? '/valentra-diyor-ki' : '/kose-yazilari.php';

    return $sayfa > 1 ? $yol . '?sayfa=' . $sayfa : $yol;
}

/** Video haberler sayfası (30.10.2026); $kategori verilirse o başlık. */
function video_yolu(string $kategori = '', int $sayfa = 1): string
{
    $parametre = array_filter(['k' => $kategori, 'sayfa' => $sayfa > 1 ? (string) $sayfa : '']);
    $yol       = temiz_adres_acik() ? '/video' : '/video.php';

    return $parametre === [] ? $yol : $yol . '?' . http_build_query($parametre);
}

/** Hesaplama araçları sayfası. */
function araclar_yolu(): string
{
    return temiz_adres_acik() ? '/araclar' : '/araclar.php';
}

/** Site içi arama; sorgu verilirse ?q= ile. */
function arama_yolu(string $sorgu = ''): string
{
    $yol = temiz_adres_acik() ? '/ara' : '/ara.php';

    return $sorgu !== '' ? $yol . '?q=' . rawurlencode($sorgu) : $yol;
}

/** Uygulama rehberleri listesi. */
function rehberler_yolu(): string
{
    return temiz_adres_acik() ? '/rehberler' : '/rehberler.php';
}

/** Tek rehber. */
function rehber_yolu(string $slug): string
{
    return temiz_adres_acik()
        ? '/rehber/' . rawurlencode($slug)
        : '/rehber.php?r=' . rawurlencode($slug);
}
