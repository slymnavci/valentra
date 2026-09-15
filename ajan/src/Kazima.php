<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * RSS yayınlamayan sitelerin duyuru listesini kazır.
 *
 * Kazıma mantığı includes/kazima.php dosyasındadır; panel de aynı
 * dosyayı kullanır, böylece paneldeki test ile ajanın gerçekte yaptığı
 * iş birebir aynı olur.
 */
final class Kazima
{
    public function __construct(private readonly Http $http = new Http())
    {
        // Paylasilan cekirdek. Ajan depo koku altindan calistigi icin
        // dosya her iki ortamda da ayni yerdedir.
        require_once dirname(__DIR__, 2) . '/includes/kazima.php';
    }

    /**
     * Listeleme sayfasından haber girdilerini çıkarır.
     *
     * Besleme girdileriyle aynı biçimi döndürür, böylece ajan iki kaynak
     * türünü aynı şekilde işleyebilir. Kazınan sayfada tarih bilgisi
     * güvenilir olmadığı için tarih null bırakılır; kopya engeli zaten
     * kaynak adresi üzerinden çalışır.
     *
     * @return list<array{baslik:string,baglanti:string,ozet:string,tarih:?string,gorsel:string}>
     */
    public function oku(string $listeUrl, string $secici = '', int $enFazla = 40): array
    {
        $html = $this->http->indir($listeUrl);

        if ($html === null) {
            return [];
        }

        $bulunan = kazima_haberleri_bul($html, $listeUrl, $secici, $enFazla);

        return array_map(
            // Gorsel liste sayfasindan degil haber sayfasindan alinir:
            // listedeki kucuk resimler sikca kirpik ya da yer tutucu.
            static fn (array $h): array => [
                'baslik'   => $h['baslik'],
                'baglanti' => $h['baglanti'],
                'ozet'     => '',
                'tarih'    => null,
                'gorsel'   => '',
            ],
            $bulunan
        );
    }
}
