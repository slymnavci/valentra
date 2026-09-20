<?php
declare(strict_types=1);

namespace Valentra\Ajan;

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
     * @return array{metin:string,gorsel:string}
     */
    public function oku(string $url, int $enFazlaKarakter = 6000): array
    {
        $html = $this->http->indir($url);

        if ($html === null) {
            return ['metin' => '', 'gorsel' => ''];
        }

        $gorsel = $this->gorseliBul($html, $url);

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
        ];
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
