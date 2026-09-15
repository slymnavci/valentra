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
    public function __construct(private readonly Http $http = new Http())
    {
    }

    /**
     * Sayfanın gövde metnini döndürür; alınamazsa boş dizge.
     */
    public function metin(string $url, int $enFazlaKarakter = 6000): string
    {
        $html = $this->http->indir($url);

        if ($html === null) {
            return '';
        }

        // Metin taşımayan bloklar önce tamamen atılır.
        $html = (string) preg_replace(
            '#<(script|style|noscript|svg|nav|footer|header|aside|form)\b[^>]*>.*?</\1>#is',
            ' ',
            $html
        );

        // Paragraf sınırlarını koru, kalan etiketleri düşür.
        $html = (string) preg_replace('#</(p|div|li|h[1-6]|br)\s*/?>#i', "\n", $html);
        $metin = strip_tags($html);
        $metin = html_entity_decode($metin, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Satırları temizle, menü artığı kısa satırları ele.
        $satirlar = [];

        foreach (preg_split('/\R/u', $metin) ?: [] as $satir) {
            $satir = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $satir));

            if (mb_strlen($satir, 'UTF-8') >= 40) {
                $satirlar[] = $satir;
            }
        }

        $sonuc = trim(implode("\n", $satirlar));

        return mb_substr($sonuc, 0, $enFazlaKarakter, 'UTF-8');
    }
}
