<?php
declare(strict_types=1);

require_once __DIR__ . '/url.php';

/**
 * HTML kazıma çekirdeği.
 *
 * RSS yayınlamayan siteler (resmî kurumların çoğu) için duyuru listesi
 * sayfasından haber bağlantılarını çıkarır. Hem yönetim panelindeki test
 * hem de ajan bu dosyayı kullanır — iki ayrı kopya tutulmaz.
 *
 * Yaklaşım: bir duyuru sayfasındaki haber bağlantıları aynı adres
 * kalıbını paylaşır (/duyurular/2026/..., /haber/12345 gibi). Sayfadaki
 * bağlantılar kalıplarına göre gruplanır ve en kalabalık grup haber
 * listesi kabul edilir. Böylece kullanıcının CSS seçicisi yazması
 * gerekmez; yine de isteyen seçici verebilir.
 */

/** Menü ve altbilgi bağlantılarını elemek için. */
const KAZIMA_ELENEN_METINLER = [
    'ana sayfa', 'iletişim', 'hakkımızda', 'giriş', 'kayıt ol', 'devamı',
    'devamını oku', 'tümü', 'tümünü gör', 'daha fazla', 'sonraki', 'önceki',
    'kurumsal', 'mevzuat', 'sıkça sorulan', 'site haritası', 'gizlilik',
    'çerez', 'kvkk', 'arama', 'ara', 'menü', 'paylaş',
];

/**
 * Basit CSS seçiciyi XPath'e çevirir.
 *
 * Desteklenen: etiket, .sinif, #kimlik ve bunların boşlukla ayrılmış
 * ardışık hâlleri (".liste .baslik a" gibi). Daha karmaşık seçiciler
 * için sezgisel yönteme düşülür.
 */
function kazima_secici_xpath(string $secici): ?string
{
    $secici = trim($secici);

    if ($secici === '') {
        return null;
    }

    $xpath = '';

    foreach (preg_split('/\s+/', $secici) ?: [] as $parca) {
        if (!preg_match('/^([a-z0-9]*)((?:[.#][A-Za-z0-9_-]+)*)$/i', $parca, $m)) {
            return null;
        }

        $etiket = $m[1] !== '' ? $m[1] : '*';
        $kosul  = '';

        if (preg_match_all('/([.#])([A-Za-z0-9_-]+)/', $m[2], $ekler, PREG_SET_ORDER)) {
            foreach ($ekler as $ek) {
                $kosul .= $ek[1] === '#'
                    ? "[@id='" . $ek[2] . "']"
                    : "[contains(concat(' ', normalize-space(@class), ' '), ' " . $ek[2] . " ')]";
            }
        }

        $xpath .= '//' . $etiket . $kosul;
    }

    return $xpath;
}

/** Bağlantı metnini normalleştirir. */
function kazima_metin(string $ham): string
{
    $metin = trim(preg_replace('/\s+/u', ' ', html_entity_decode($ham, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

    return $metin;
}

/**
 * Adresin "kalıbını" çıkarır: rakamlar # ile değiştirilir, ilk iki yol
 * parçası alınır. /haber/12345 ve /haber/67890 aynı kalıba düşer.
 */
function kazima_kalip(string $url): string
{
    $yol = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $yol = (string) preg_replace('/\d+/', '#', $yol);

    $parcalar = array_values(array_filter(explode('/', $yol), static fn (string $p): bool => $p !== ''));

    if ($parcalar === []) {
        return '/';
    }

    return '/' . implode('/', array_slice($parcalar, 0, 2));
}

/**
 * Sayfadaki baglanti adaylarini toplar.
 *
 * kazima_haberleri_bul() ile kazima_tani() bu isi paylasiyor; ayni
 * fonksiyonu kullandiklari icin panelin gosterdigi tani, ajanin
 * gerceklestirdigi elemeyle birebir ayni.
 *
 * @return array{toplam_a:int,kapsam_var:bool,adaylar:array<string,array{baslik:string,baglanti:string,kalip:string}>}
 */
function kazima_adaylari_topla(string $html, string $tabanUrl, string $secici = ''): array
{
    $bos = ['toplam_a' => 0, 'kapsam_var' => false, 'adaylar' => []];

    if (trim($html) === '') {
        return $bos;
    }

    $belge = new DOMDocument();
    $onceki = libxml_use_internal_errors(true);
    $belge->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);

    $xpath = new DOMXPath($belge);
    $kapsam = null;

    /*
     * YOL SUZGECI: "yol:/vergi-sirkuleri/"
     *
     * CSS secici sayfanin DOM'unu bilmeyi gerektiriyor; bazi sitelerde
     * bilinmiyor ya da sik degisiyor. Oysa haber adresleri cogu zaman
     * sabit bir yol altinda: Grant Thornton sirkulerleri
     * /vergi-sirkuleri/ altinda. Genel kalip kurali o sayfada
     * sirkulerleri degil menudeki hizmet sayfalarini seciyordu
     * ("Danismanlik Hizmetleri", 19 bağlantı) — cunku menu daha
     * kalabalikti. Yol suzgeci yalnizca bu yolun ALTINDAKI
     * baglantilari aliyor; listeleme sayfasinin kendisini degil.
     */
    $yolSuzgeci = '';

    if (str_starts_with($secici, 'yol:')) {
        $yolSuzgeci = trim(substr($secici, 4));
        $secici     = '';
    }

    if ($secici !== '') {
        $ifade = kazima_secici_xpath($secici);

        if ($ifade !== null) {
            $bulunan = $xpath->query($ifade);

            if ($bulunan !== false && $bulunan->length > 0) {
                $kapsam = $bulunan;
            }
        }
    }

    $baglantilar = [];

    if ($kapsam !== null) {
        foreach ($kapsam as $dugum) {
            foreach ($xpath->query('.//a[@href]|self::a[@href]', $dugum) ?: [] as $a) {
                $baglantilar[] = $a;
            }
        }
    } else {
        foreach ($belge->getElementsByTagName('a') as $a) {
            if ($a->hasAttribute('href')) {
                $baglantilar[] = $a;
            }
        }
    }

    $tabanHost = parse_url($tabanUrl, PHP_URL_HOST);
    $adaylar = [];

    foreach ($baglantilar as $a) {
        $metin = kazima_metin($a->textContent);

        // Basligi olmayan ya da menu ogesi gorunumlu baglantilari at.
        if (mb_strlen($metin, 'UTF-8') < 20 || mb_strlen($metin, 'UTF-8') > 300) {
            continue;
        }

        $kucuk = mb_strtolower($metin, 'UTF-8');

        foreach (KAZIMA_ELENEN_METINLER as $elenen) {
            if ($kucuk === $elenen) {
                continue 2;
            }
        }

        $href = trim($a->getAttribute('href'));

        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            continue;
        }

        $mutlak = besleme_url_birlestir($tabanUrl, $href);

        if ($mutlak === '' || parse_url($mutlak, PHP_URL_HOST) !== $tabanHost) {
            continue;
        }

        if ($yolSuzgeci !== '') {
            $yol     = rtrim((string) parse_url($mutlak, PHP_URL_PATH), '/');
            $tabanYol = rtrim((string) parse_url($tabanUrl, PHP_URL_PATH), '/');
            $konum   = strpos($yol . '/', $yolSuzgeci);

            // Yolun altinda olmali; listeleme sayfasi ya da onun ustu degil.
            if ($konum === false
                || strlen($yol . '/') <= $konum + strlen($yolSuzgeci)
                || $yol === $tabanYol
                || str_starts_with($tabanYol . '/', $yol . '/')) {
                continue;
            }
        }

        // Ayni adres birden cok kez gecebilir (gorsel + baslik baglantisi).
        $anahtar = strtok($mutlak, '#');

        if (isset($adaylar[$anahtar])) {
            continue;
        }

        $adaylar[$anahtar] = [
            'baslik'   => $metin,
            'baglanti' => $anahtar,
            'kalip'    => kazima_kalip($anahtar),
        ];
    }

    return [
        'toplam_a'   => count($baglantilar),
        // Yol suzgeci de kapsami daraltir: kalip kuraliyla yeniden elenmesin.
        'kapsam_var' => $kapsam !== null || $yolSuzgeci !== '',
        'adaylar'    => $adaylar,
    ];
}

/**
 * Listeleme sayfasındaki haber bağlantılarını bulur.
 *
 * @return list<array{baslik:string,baglanti:string}>
 */
/**
 * Adres tek tek bakıldığında haber adresine benziyor mu?
 *
 * Kalip kurali tutmadiginda kullanilan ikinci olcut. Haber adresleri
 * son parcalarinda uzun, tireli bir baslik tasir; gezinme adresleri
 * kisadir. Olcut adresin KENDISINE bakar, digerleriyle iliskisine
 * degil — zaten kalip kuralinin yapamadigi da tam olarak bu.
 *
 * Esik dort tire: "/son-dakika-haberleri" (iki tire) gibi bolum
 * adreslerini disarida birakacak kadar yuksek. Sonu uzun bir sayiyla
 * biten adreslerde uc tire yetiyor, cunku sayi zaten haber kimligi
 * oldugunu gosteriyor.
 */
function kazima_makale_adresi_mi(string $url): bool
{
    $yol = (string) parse_url($url, PHP_URL_PATH);
    $yol = rtrim($yol, '/');

    if ($yol === '') {
        return false;
    }

    $parcalar = explode('/', $yol);
    $son      = (string) end($parcalar);

    // Sayfa numarasi ya da kimlik tek basina haber basligi degildir.
    if ($son === '' || preg_match('/^\d+$/', $son) === 1) {
        return false;
    }

    $tire = substr_count($son, '-');

    if ($tire >= 4) {
        return true;
    }

    return $tire >= 2 && preg_match('/-\d{4,}$/', $son) === 1;
}

function kazima_haberleri_bul(string $html, string $tabanUrl, string $secici = '', int $enFazla = 40): array
{
    $toplama = kazima_adaylari_topla($html, $tabanUrl, $secici);
    $adaylar = $toplama['adaylar'];

    if ($adaylar === []) {
        return [];
    }

    // Seçici verilmişse kapsam zaten daraltılmıştır; kalıba göre ayıklama
    // yapmadan hepsini döndür.
    if ($toplama['kapsam_var']) {
        return array_map(
            static fn (array $a): array => ['baslik' => $a['baslik'], 'baglanti' => $a['baglanti']],
            array_slice(array_values($adaylar), 0, $enFazla)
        );
    }

    // En kalabalık adres kalıbı haber listesidir.
    $sayimlar = [];

    foreach ($adaylar as $aday) {
        $sayimlar[$aday['kalip']] = ($sayimlar[$aday['kalip']] ?? 0) + 1;
    }

    arsort($sayimlar);
    $enIyiKalip = (string) array_key_first($sayimlar);

    // Tek başına duran bir kalıp haber listesi değildir.
    if ($sayimlar[$enIyiKalip] < 3) {
        /*
         * DUZ ADRESLI SITELER ICIN IKINCI OLCUT.
         *
         * Kalip kurali gezinme sayfasini haber listesi sanmayi
         * onluyor ve bu is goruyor — ama yalnizca adreslerini
         * bolumlere ayiran sitelerde. Bloomberg HT gibi haberi
         * dogrudan kokte yayimlayan sitelerde her haberin kalibi
         * benzersiz ve sayisi 1 oluyor; eldeki butun haberler
         * atiliyor.
         *
         * Olculdu: bloomberght.com/ekonomik-veriler-ve-gundem
         * sayfasinda 152 baglantidan 52'si suzgecten gecti ve
         * kalip kurali yuzunden 52'si de atildi. Mynet'in vergi
         * sayfasi da ayni sebeple sifir dondu.
         *
         * Ikinci olcut adresin SON parcasina bakiyor: haber
         * adresleri uzun tireli bir baslik tasiyor
         * ("...-tuketici-guven-on-endeksi-geriledi-3788892"),
         * gezinme adresleri kisa olur ("/sondakika",
         * "/borsa/en-cok", "/son-dakika-haberleri").
         *
         * En az uc tane bulunmasi sart: tek basina duran bir
         * baglanti yine haber listesi sayilmaz.
         */
        $duzAdresli = [];

        foreach ($adaylar as $aday) {
            if (kazima_makale_adresi_mi($aday['baglanti'])) {
                $duzAdresli[] = [
                    'baslik'   => $aday['baslik'],
                    'baglanti' => $aday['baglanti'],
                ];
            }
        }

        if (count($duzAdresli) < 3) {
            return [];
        }

        return array_slice($duzAdresli, 0, $enFazla);
    }

    $sonuc = [];

    foreach ($adaylar as $aday) {
        if ($aday['kalip'] !== $enIyiKalip) {
            continue;
        }

        $sonuc[] = ['baslik' => $aday['baslik'], 'baglanti' => $aday['baglanti']];

        if (count($sonuc) >= $enFazla) {
            break;
        }
    }

    return $sonuc;
}

/**
 * Kazimanin neden sonuc vermedigini anlatir.
 *
 * Bir kaynak "okunamadi" dediginde sebebi tek basina bilinmiyor: sayfa
 * bos mu geldi, baglantilar mi elendi, yoksa kalip esigi mi tutmadi?
 * Burada her adimin sayisi ve en kalabalik adres kaliplari donuyor.
 *
 * @return array{
 *     toplam_a:int, aday:int, kapsam_var:bool, en_iyi_kalip:string,
 *     en_iyi_adet:int, kaliplar:array<string,int>, ornekler:list<string>,
 *     sonuc:int
 * }
 */
function kazima_tani(string $html, string $tabanUrl, string $secici = ''): array
{
    $toplama = kazima_adaylari_topla($html, $tabanUrl, $secici);
    $adaylar = $toplama['adaylar'];

    $sayimlar = [];

    foreach ($adaylar as $aday) {
        $sayimlar[$aday['kalip']] = ($sayimlar[$aday['kalip']] ?? 0) + 1;
    }

    arsort($sayimlar);

    $enIyiKalip = $sayimlar === [] ? '' : (string) array_key_first($sayimlar);
    $enIyiAdet  = $enIyiKalip === '' ? 0 : $sayimlar[$enIyiKalip];

    $ornekler = [];

    foreach (array_slice(array_values($adaylar), 0, 3) as $aday) {
        $ornekler[] = $aday['baslik'] . ' -> ' . $aday['baglanti'];
    }

    return [
        'toplam_a'     => $toplama['toplam_a'],
        'aday'         => count($adaylar),
        'kapsam_var'   => $toplama['kapsam_var'],
        'en_iyi_kalip' => $enIyiKalip,
        'en_iyi_adet'  => $enIyiAdet,
        'kaliplar'     => array_slice($sayimlar, 0, 5, true),
        'ornekler'     => $ornekler,
        'sonuc'        => count(kazima_haberleri_bul($html, $tabanUrl, $secici)),
    ];
}
