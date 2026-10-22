<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Kaynak sitelerin "En Çok Okunanlar" listelerini okur.
 *
 * NEDEN: site sahibi "en cok okunan haberler one" istedi (22.10.2026).
 * Haber sitelerinin okunma sayilari disariya acik degil; beslemeler de
 * vermiyor. Ulasilabilen en yakin gosterge, sitelerin ana sayfasindaki
 * "En Cok Okunanlar" kutusu: sayi vermiyor ama haberin o sitede cok
 * okunanlar arasinda olup olmadigini soyluyor.
 *
 * YONTEM sayfa duzenine bagli degil: her sitenin kutusu baska bir CSS
 * sinifiyla geliyor ve sik degisiyor. Bunun yerine sayfada kutunun
 * BASLIGI ("En Çok Okunanlar", "Çok Okunan", "Most Read") aranıyor ve
 * basligin ardindaki ilk haber baglantilari aliniyor. Menudeki
 * "En Cok Okunanlar" sayfasi baglantisi da ayni metni tasidigi icin
 * yalnizca haber adresine benzeyen baglantilar sayiliyor (yolunda en az
 * uc tire ya da uzun bir sayi). Bulunamazsa liste bos doner; bu bir
 * hata degil, o site icin sinyal yok demek.
 *
 * Yalnizca dogrudan indirme (Http) kullaniliyor, site sunucusu
 * uzerinden degil: o yolun cagri tavani haber sayfalari icin var ve
 * bu ek sinyal onu tuketmemeli.
 */
final class CokOkunan
{
    /** Kutu basliklari; kucuk harfle, Turkce karakterli ve karaktersiz. */
    private const BASLIKLAR = [
        'en çok okunan', 'en cok okunan', 'çok okunanlar', 'cok okunanlar',
        'en çok okunanlar', 'en çok tıklanan', 'en cok tiklanan',
        'most read', 'most popular', 'most viewed',
    ];

    /** Bir kutudan alinan en fazla haber. */
    private const KUTU_BASINA = 10;

    /** Basligin ardinda bakilan HTML uzunlugu. */
    private const PENCERE = 20_000;

    public function __construct(private readonly Indirici $http = new Http())
    {
    }

    /**
     * Sayfadaki "En Çok Okunanlar" listesi, sırasıyla.
     *
     * @return list<array{baglanti:string,baslik:string,sira:int}>
     */
    public function oku(string $sayfaUrl): array
    {
        $html = $this->http->indir($sayfaUrl, 3_000_000);

        return $html === null ? [] : self::ayikla($html, $sayfaUrl);
    }

    /**
     * HTML'den listeyi çıkarır (ağdan bağımsız; test edilebilsin diye ayrı).
     *
     * @return list<array{baglanti:string,baslik:string,sira:int}>
     */
    public static function ayikla(string $html, string $sayfaUrl): array
    {
        $sunucu = self::sunucu($sayfaUrl);

        if ($sunucu === '') {
            return [];
        }

        $metin = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $kucuk = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $metin), 'UTF-8');

        /*
         * Arama ve ayiklama AYNI kucuk harfli metinde: mb_strtolower bazi
         * harflerde bayt uzunlugunu degistirdigi icin konumlar asil
         * metne tasinamaz. Bu yuzden adresler kucuk harfle donuyor;
         * karsilastirma adres_anahtari() ile, iki tarafta ayni bicimde.
         */
        $sonuc = [];
        $gorulen = [];

        foreach (self::BASLIKLAR as $baslik) {
            $konum = 0;
            $deneme = 0;

            while (($bulunan = strpos($kucuk, $baslik, $konum)) !== false && $deneme < 4) {
                $deneme++;
                $konum  = $bulunan + strlen($baslik);

                // Baglanti icindeki metin kutu basligi degil, menudeki
                // "En Cok Okunanlar" sayfasina giden baglanti.
                if (self::baglanti_icinde($kucuk, $bulunan)) {
                    continue;
                }

                $parca  = substr($kucuk, $konum, self::PENCERE);
                $kutu   = [];

                preg_match_all('~<a\b([^>]*)\bhref\s*=\s*["\']([^"\'#]+)["\']([^>]*)>(.*?)</a>~is', $parca, $eslesmeler, PREG_SET_ORDER);

                foreach ($eslesmeler as [, $once, $adres, $sonra, $ic]) {
                    $tam = self::mutlak(trim($adres), $sayfaUrl);

                    if ($tam === '' || isset($gorulen[$tam]) || !self::haber_adresi_mi($tam, $sunucu)) {
                        continue;
                    }

                    // Basindaki sira numarasi ("1 Merkez Bankasi...") atiliyor.
                    $yazi = trim(preg_replace(['/\s+/u', '/^\d{1,2}[.\s]+/u'], [' ', ''], trim(strip_tags($ic))) ?? '');

                    if ($yazi === '' && preg_match('~\btitle\s*=\s*["\']([^"\']+)~i', $once . $sonra . $ic, $t) === 1) {
                        $yazi = trim($t[1]);
                    }

                    $gorulen[$tam] = true;
                    $kutu[] = $tam;
                    $sonuc[] = ['baglanti' => $tam, 'baslik' => $yazi, 'sira' => count($kutu)];

                    if (count($kutu) >= self::KUTU_BASINA) {
                        break;
                    }
                }

                // Haber cikaran ilk kutu yeter; ayni sayfadaki ikinci
                // eslesme cogu zaman menu ya da alt bilgi.
                if ($kutu !== []) {
                    return $sonuc;
                }
            }
        }

        return $sonuc;
    }

    /**
     * Karşılaştırma anahtarı: ayıklanan adresle adayın adresi aynı biçime iner.
     *
     * $sade: url_sadelestir() ciktisi (ajan/topla.php).
     */
    public static function adres_anahtari(string $sade): string
    {
        return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $sade), 'UTF-8');
    }

    /** "www." olmadan, küçük harfle alan adı. */
    public static function sunucu(string $url): string
    {
        $alan = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $alan) ?? $alan;
    }

    /** Konum bir <a> etiketinin içinde mi? */
    private static function baglanti_icinde(string $html, int $konum): bool
    {
        $once = substr($html, max(0, $konum - 2000), min($konum, 2000));
        $acilis = max((int) strrpos($once, '<a '), (int) strrpos($once, '<a>'));
        $kapanis = strrpos($once, '</a>');

        return ($acilis > 0 || str_starts_with($once, '<a')) && ($kapanis === false || $kapanis < $acilis);
    }

    private static function mutlak(string $adres, string $taban): string
    {
        if (preg_match('~^https?://~i', $adres) === 1) {
            return $adres;
        }

        if (str_starts_with($adres, '//')) {
            return 'https:' . $adres;
        }

        if (!str_starts_with($adres, '/')) {
            return '';
        }

        $parca = parse_url($taban);

        return isset($parca['host'])
            ? ($parca['scheme'] ?? 'https') . '://' . $parca['host'] . $adres
            : '';
    }

    /**
     * Haber sayfası adresine benziyor mu?
     *
     * Ayni sitenin (ya da alt alan adinin) adresi olmali ve yolu bir
     * haber yolu gibi gorunmeli: kategori, etiket ve menu sayfalarinin
     * yolu kisa, haberinki baslik slug'i ya da kimlik numarasi tasiyor.
     */
    private static function haber_adresi_mi(string $url, string $sunucu): bool
    {
        $alan = self::sunucu($url);

        if ($alan !== $sunucu && !str_ends_with($alan, '.' . $sunucu) && !str_ends_with($sunucu, '.' . $alan)) {
            return false;
        }

        $yol = (string) parse_url($url, PHP_URL_PATH);

        return substr_count($yol, '-') >= 3 || preg_match('/\d{5,}/', $yol) === 1;
    }
}
