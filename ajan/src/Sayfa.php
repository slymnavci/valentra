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
     * @return array{metin:string,gorsel:string,video:string}
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
            return ['metin' => '', 'gorsel' => '', 'video' => ''];
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
            ];
        }

        // Windows-1254 sayfalarda Turkce karakterler bozuk geliyordu.
        $html = Kodlama::utf8($html);

        $gorsel = $this->gorseliBul($html, $url);
        $video  = self::videoBul($html);

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

        return [
            'metin'  => mb_substr($sonuc, 0, $enFazlaKarakter, 'UTF-8'),
            'gorsel' => $gorsel,
            'video'  => $video,
        ];
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
            $aday = html_entity_decode(trim($aday), ENT_QUOTES | ENT_HTML5, 'UTF-8');

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
