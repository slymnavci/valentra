<?php
declare(strict_types=1);

namespace Valentra\Ajan;

require_once __DIR__ . '/Kodlama.php';

/**
 * Haber sayfasından okunabilir metni çıkarır.
 *
 * Amaç haberi ANLAMAK için bağlam toplamaktır; çıkarılan metin olduğu
 * gibi yayımlanmaz. Yazar sınıfı bu metni okuyup kendi cümleleriyle
 * yeni bir haber yazar.
 */
final class Sayfa
{
    public function __construct(private readonly Indirici $http = new Http())
    {
        // guvenli_url ve besleme_url_birlestir paylasilan dosyada.
        require_once dirname(__DIR__, 2) . '/includes/url.php';
    }

    /**
     * Sayfanın gövde metnini döndürür; alınamazsa boş dizge.
     */
    public function metin(string $url, int $enFazlaKarakter = 6000): string
    {
        return $this->oku($url, $enFazlaKarakter)['metin'];
    }

    /**
     * Sayfayı bir kez indirip metnini ve öne çıkan görselini döndürür.
     *
     * İkisi ayrı metot olsaydı aynı sayfa iki kez inecekti; kaynak
     * sitelere gereksiz yük ve çalışma süresi demek.
     *
     * @return array{metin:string,gorsel:string,video:string,zaman:?int}
     */
    public function oku(string $url, int $enFazlaKarakter = 6000): array
    {
        /*
         * Sinir 2 MB'tan 12 MB'a cikti: Resmi Gazete'nin ekli tablolu
         * PDF'leri birkac megabayti rahat asiyor ve kesilmis bir PDF
         * okunamaz.
         */
        $html = $this->http->indir($url, 12_000_000);

        if ($html === null) {
            return ['metin' => '', 'gorsel' => '', 'video' => '', 'zaman' => null];
        }

        /*
         * PDF ise etiket soymak anlamsiz; metin pdftotext ile cikiyor.
         * Once bu adimda model "sayfa metni alinamadi" deyip Resmi
         * Gazete'deki tablolu kararlari eliyordu.
         */
        if (Kodlama::pdfMi($html)) {
            $metin = trim((string) preg_replace("/[ \t]+\n/u", "\n", Kodlama::pdfMetni($html)));
            $metin = (string) preg_replace("/\n{3,}/u", "\n\n", $metin);

            return [
                'metin'  => mb_substr($metin, 0, $enFazlaKarakter, 'UTF-8'),
                'gorsel' => '',
                'video'  => '',
                'zaman'  => null,
            ];
        }

        // Windows-1254 sayfalarda Turkce karakterler bozuk geliyordu.
        $html = Kodlama::utf8($html);

        $gorsel = $this->gorseliBul($html, $url);
        $video  = self::videoBul($html);
        $tarif  = self::tarifVerisi($html);
        $zaman  = self::yayinZamani($html);

        // Metin taşımayan bloklar önce tamamen atılır.
        $html = (string) preg_replace(
            '#<(script|style|noscript|svg|nav|footer|header|aside|form)\b[^>]*>.*?</\1>#is',
            ' ',
            $html
        );

        /*
         * Paragraf VE TABLO sınırlarını koru, kalan etiketleri düşür.
         *
         * Once yalnizca p/div/li/h/br satir sonu sayiliyordu; td ve tr
         * listede yoktu. Sonuc olarak bir tarife tablosu tek bir
         * yapisik dizgeye doniyordu:
         *   "190.000 TL ye kadar%15400.000 TL%20"
         * Model bunu ayristiramaz. Oysa haberin en degerli kismi tam
         * da bu tablolar.
         *
         * Hucre arasina sekme, satir sonuna yeni satir konuyor;
         * boylece tablo satir satir ve sutunlari ayrilmis geliyor.
         */
        $html = (string) preg_replace('#</(td|th)\s*>#i', "\t", $html);
        $html = (string) preg_replace('#</(p|div|li|tr|h[1-6]|br)\s*/?>#i', "\n", $html);
        $metin = strip_tags($html);
        $metin = html_entity_decode($metin, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        /*
         * Satırları temizle, menü artığı kısa satırları ele.
         *
         * Uzunluk esigi tek basina yetmiyordu: tarife dilimleri, oran
         * listeleri ve had tablolari kisa satirlardan olusuyor
         * ("190.000 TL'ye kadar %15") ve 40 karakter esigine takilip
         * atiliyordu. Haberin en degerli kismi tam da bunlar.
         *
         * Bu yuzden kisa satirlar da, icinde SAYI varsa aliniyor.
         * Menu ve buton yazilari ("Ana Sayfa", "İletişim") sayi
         * tasimadigi icin elenmeye devam ediyor.
         */
        $satirlar = [];

        foreach (preg_split('/\R/u', $metin) ?: [] as $satir) {
            $satir   = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $satir));
            $uzunluk = mb_strlen($satir, 'UTF-8');

            if ($uzunluk >= 40) {
                $satirlar[] = $satir;
                continue;
            }

            if ($uzunluk >= 6 && preg_match('/\d/u', $satir) === 1) {
                $satirlar[] = $satir;
            }
        }

        $sonuc = trim(implode("\n", $satirlar));

        // Tarifin yapilandirilmis verisi metnin BASINA: kirpilsa da kalsin.
        if ($tarif !== '') {
            $sonuc = $tarif . "\n\n" . $sonuc;
        }

        return [
            'metin'  => mb_substr($sonuc, 0, $enFazlaKarakter, 'UTF-8'),
            'gorsel' => $gorsel,
            'video'  => $video,
            'zaman'  => $zaman,
        ];
    }

    /**
     * Sayfanın kendi yayın zamanı (Unix); bulunamazsa null.
     *
     * NEDEN (23.10.2026): ana sayfasi kazinan kaynaklarin (IHA, DHA)
     * girdilerinde tarih yok. Tarihsiz haberin yasi toplandigi andan
     * sayiliyordu; IHA ana sayfasinin "Editorun Sectikleri" kutusundaki
     * dunku haber Valentra'da ertesi gun 1. haber oldu. Haber siteleri
     * yayin zamanini arama motorlari icin makine okunur biciminde
     * veriyor; sirayla bakilan yerler:
     *   1. <meta property="article:published_time">
     *   2. JSON-LD "datePublished"
     *   3. itemprop="datePublished" ve benzeri meta adlari
     *   4. <time datetime="...">
     * Gelecekteki ya da bir yildan eski zaman yok sayiliyor.
     *
     * @return int|null
     */
    public static function yayinZamani(string $html): ?int
    {
        $desenler = [
            '~<meta[^>]+(?:property|name)\s*=\s*["\'](?:article:published_time|og:published_time)["\'][^>]*content\s*=\s*["\']([^"\']+)~i',
            '~<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]*(?:property|name)\s*=\s*["\'](?:article:published_time|og:published_time)["\']~i',
            '~"datePublished"\s*:\s*"([^"]+)"~i',
            '~"(?:uploadDate|publishDate)"\s*:\s*"([^"]+)"~',
            '~<meta[^>]+(?:itemprop|name)\s*=\s*["\'](?:datePublished|pubdate|publish-date|publishdate|sailthru\.date|parsely-pub-date|dc\.date\.issued)["\'][^>]*content\s*=\s*["\']([^"\']+)~i',
            '~<time[^>]+datetime\s*=\s*["\']([^"\']+)~i',
        ];

        foreach ($desenler as $desen) {
            if (preg_match($desen, $html, $eslesme) !== 1) {
                continue;
            }

            $zaman = strtotime(trim(html_entity_decode($eslesme[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if ($zaman !== false && $zaman <= time() + 3600 && $zaman >= time() - 365 * 86400) {
                return $zaman;
            }
        }

        return null;
    }

    /**
     * Sayfadaki schema.org Recipe verisi (JSON-LD), okunur metin olarak.
     *
     * Tarif sitelerinin cogu malzeme listesini ve adimlari bu blokta
     * eksiksiz veriyor. Gövde metni ise yetmiyordu: satir suzgeci 40
     * karakterden kisa ve sayisiz satirlari (menu artigi diye) atiyor;
     * "Tuz", "Yarım demet maydanoz" gibi malzeme satirlari da gidiyordu.
     * Model "malzeme listesi eksik" deyip tarifleri eliyordu (22.10.2026).
     * Tarif yoksa bos dizge.
     */
    public static function tarifVerisi(string $html): string
    {
        if (stripos($html, 'application/ld+json') === false) {
            return '';
        }

        preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $bloklar);

        foreach ($bloklar[1] as $json) {
            $veri  = json_decode(trim(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
            $tarif = is_array($veri) ? self::tarifBul($veri) : null;

            if ($tarif !== null) {
                return self::tarifMetni($tarif);
            }
        }

        return '';
    }

    /** @param array<mixed> $veri */
    private static function tarifBul(array $veri, int $derinlik = 0): ?array
    {
        if ($derinlik > 6) {
            return null;
        }

        $tur = $veri['@type'] ?? null;

        if ($tur === 'Recipe' || (is_array($tur) && in_array('Recipe', $tur, true))) {
            return $veri;
        }

        foreach ($veri as $alt) {
            if (is_array($alt) && ($bulunan = self::tarifBul($alt, $derinlik + 1)) !== null) {
                return $bulunan;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $t */
    private static function tarifMetni(array $t): string
    {
        $duz = static fn (mixed $x): string => is_scalar($x)
            ? trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $x), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            : '';

        // ISO 8601 sure (PT1H30M) -> "1 sa 30 dk"
        $sure = static function (mixed $x) use ($duz): string {
            $s = $duz($x);

            if (preg_match('/^P(?:T)?(?:(\d+)H)?(?:(\d+)M)?$/i', $s, $m) === 1) {
                return trim((($m[1] ?? '') !== '' ? $m[1] . ' sa ' : '') . (($m[2] ?? '') !== '' ? $m[2] . ' dk' : ''));
            }

            return $s;
        };

        $satirlar = ['TARİF VERİSİ (sayfanın yapılandırılmış verisi):', 'Ad: ' . $duz($t['name'] ?? '')];

        $porsiyon = $t['recipeYield'] ?? '';
        $porsiyon = is_array($porsiyon) ? $duz(reset($porsiyon)) : $duz($porsiyon);

        foreach (['Porsiyon' => $porsiyon, 'Hazırlık' => $sure($t['prepTime'] ?? ''),
                  'Pişirme' => $sure($t['cookTime'] ?? ''), 'Toplam süre' => $sure($t['totalTime'] ?? '')] as $ad => $deger) {
            if ($deger !== '') {
                $satirlar[] = $ad . ': ' . $deger;
            }
        }

        $malzemeler = array_values(array_filter(array_map($duz, (array) ($t['recipeIngredient'] ?? $t['ingredients'] ?? []))));

        if ($malzemeler !== []) {
            $satirlar[] = 'Malzemeler:';

            foreach ($malzemeler as $m) {
                $satirlar[] = '- ' . $m;
            }
        }

        $adimlar = [];
        $topla = static function (mixed $x) use (&$topla, &$adimlar, $duz): void {
            if (is_string($x)) {
                foreach (preg_split('/\R+/u', $x) ?: [] as $parca) {
                    if (($p = $duz($parca)) !== '') {
                        $adimlar[] = $p;
                    }
                }
            } elseif (is_array($x)) {
                if (isset($x['itemListElement'])) {
                    if (($ad = $duz($x['name'] ?? '')) !== '') {
                        $adimlar[] = '[' . $ad . ']';
                    }
                    $topla($x['itemListElement']);
                } elseif (isset($x['text'])) {
                    $topla((string) $x['text']);
                } else {
                    foreach ($x as $alt) {
                        $topla($alt);
                    }
                }
            }
        };
        $topla($t['recipeInstructions'] ?? []);

        if ($adimlar !== []) {
            $satirlar[] = 'Yapılışı:';

            $no = 0;

            foreach ($adimlar as $a) {
                // Bolum basligi ("[Hamuru için]") numara almiyor.
                $satirlar[] = str_starts_with($a, '[') ? $a : (++$no) . '. ' . $a;
            }
        }

        // Ne malzeme ne adim varsa veri ise yaramaz.
        return $malzemeler === [] && $adimlar === [] ? '' : implode("\n", $satirlar);
    }

    /**
     * Haberin videosu: YouTube, Vimeo ya da Dailymotion oynatıcı adresi.
     *
     * Site sahibinin istegi (22.10.2026): "videolari da alabiliyor muyuz".
     * Video DOSYASI indirilmiyor ve kopyalanmiyor: telif kaynakta. Yalnizca
     * haber sayfasinin kendi gomdugu, gomulmeye izin veren platformlarin
     * resmi oynaticisi aliniyor; video yine o platformdan oynuyor. Haber
     * sitelerinin kendi oynaticilari (kendi sunucularindaki mp4, reklamli
     * oynaticilar) alinmiyor: onlari baska sitede gostermek izinsiz
     * kullanim olur.
     *
     * Once sayfanin paylasim bildirimi (og:video, twitter:player), sonra
     * govdedeki ilk iframe. YouTube adresi gizlilik modlu alana
     * (youtube-nocookie.com) cevriliyor: oynatilmadikca cerez yazilmiyor.
     */
    public static function videoBul(string $html): string
    {
        $adaylar = [];

        foreach ([
            '#<meta[^>]+(?:property|name)=["\'](?:og:video(?::secure_url|:url)?|twitter:player)["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:video(?::secure_url|:url)?|twitter:player)["\']#i',
            '#<iframe[^>]+src=["\']([^"\']+)["\']#i',
        ] as $desen) {
            if (preg_match_all($desen, $html, $eslesmeler) > 0) {
                array_push($adaylar, ...$eslesmeler[1]);
            }
        }

        foreach ($adaylar as $aday) {
            $adres = self::oynaticiAdresi(html_entity_decode(trim($aday), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($adres !== '') {
                return $adres;
            }
        }

        return '';
    }

    /**
     * Tek bir adres izinli bir platformun videosuysa resmi gomme adresi;
     * degilse bos. Haber baglantisinin kendisi bir YouTube videosu
     * oldugunda (YouTube kanal beslemeleri) video dogrudan buradan.
     */
    public static function oynaticiAdresi(string $aday): string
    {
        $aday = trim($aday);

        if (str_starts_with($aday, '//')) {
            $aday = 'https:' . $aday;
        }

        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?(?:.*&)?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $aday, $e)) {
            return 'https://www.youtube-nocookie.com/embed/' . $e[1];
        }

        if (preg_match('#^https?://(?:player\.)?vimeo\.com/(?:video/)?(\d{6,12})#', $aday, $e)) {
            return 'https://player.vimeo.com/video/' . $e[1];
        }

        if (preg_match('#^https?://(?:www\.)?dailymotion\.com/(?:embed/)?video/([A-Za-z0-9]{5,12})#', $aday, $e)) {
            return 'https://www.dailymotion.com/embed/video/' . $e[1];
        }

        return '';
    }

    /**
     * Sayfanın öne çıkan görselini bulur.
     *
     * Sırayla og:image, twitter:image ve link rel=image_src denenir.
     * Bunlar sitenin kendi belirlediği paylaşım görselidir; gövdedeki
     * ilk <img> ise sıklıkla logo, reklam ya da yazar avatarı oluyor,
     * o yüzden en sona bırakılıp yalnızca boyut ipucu varsa alınır.
     */
    private function gorseliBul(string $html, string $tabanUrl): string
    {
        $desenler = [
            '#<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']#i',
            '#<meta[^>]+name=["\']twitter:image(?::src)?["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image(?::src)?["\']#i',
            '#<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']#i',
        ];

        foreach ($desenler as $desen) {
            if (preg_match($desen, $html, $eslesme) !== 1) {
                continue;
            }

            $adres = besleme_url_birlestir($tabanUrl, html_entity_decode(
                trim($eslesme[1]),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));

            if (guvenli_url($adres) !== '') {
                return $adres;
            }
        }

        return '';
    }
}
