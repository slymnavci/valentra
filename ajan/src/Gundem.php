<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Google'da su an yukselen aramalar (08.11.2026, site sahibi: "Google'dan
 * gelen trafigi artiralim; gundemi yakalayalim").
 *
 * Google Trends'in Turkiye "Su an trend olanlar" beslemesi (RSS): her
 * girdi bir arama ("galatasaray", "deprem"), yaklasik arama sayisi
 * ("20.000+") ve o aramayla ilgili haber basliklari. Ajan bu konulari
 * veren adaylari one aliyor ve modele "Google'da cok aranan" diye
 * soyluyor: haber arama sonucunun ilk saatlerinde yazilirsa yeni bir
 * site de ilk sirada cikabiliyor.
 *
 * Besleme okunamazsa bos liste: ajan eskisi gibi calisir.
 */
final class Gundem
{
    public const ADRES = 'https://trends.google.com/trending/rss?geo=TR';

    /** Eslesme icin dikkate alinmayan kisa ve genel kelimeler. */
    private const BOS = ['ile', 've', 'bir', 'için', 'icin', 'son', 'dakika', 'mi', 'mı', 'de', 'da', 'the', 'vs'];

    /**
     * @return list<array{sorgu:string,trafik:int,kelimeler:list<string>,haberler:list<array{baslik:string,adres:string,kaynak:string}>}>
     */
    public static function oku(Http $http): array
    {
        $xml = $http->indir(self::ADRES, 1_000_000);

        return $xml === null ? [] : self::coz($xml);
    }

    /**
     * Beslemeyi cozer. Ad alani adresi degisse de calissin diye etiketler
     * yerel adlariyla (approx_traffic, news_item_title) aranıyor.
     *
     * @return list<array{sorgu:string,trafik:int,kelimeler:list<string>,haberler:list<array{baslik:string,adres:string,kaynak:string}>}>
     */
    public static function coz(string $xml): array
    {
        $onceki = libxml_use_internal_errors(true);
        $belge  = new \DOMDocument();
        $tamam  = $belge->loadXML($xml, LIBXML_NONET | 1);
        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        if (!$tamam) {
            return [];
        }

        $sonuc = [];

        foreach ($belge->getElementsByTagName('item') as $item) {
            $sorgu = '';
            $trafik = 0;
            $haberler = [];

            foreach ($item->getElementsByTagName('*') as $e) {
                $ad = $e->localName;

                if ($ad === 'title' && $e->parentNode === $item) {
                    $sorgu = trim($e->textContent);
                } elseif ($ad === 'approx_traffic') {
                    $trafik = (int) preg_replace('/\D/', '', $e->textContent);
                } elseif ($ad === 'news_item') {
                    $haber = ['baslik' => '', 'adres' => '', 'kaynak' => ''];

                    foreach ($e->childNodes as $c) {
                        $metin = trim(html_entity_decode(strip_tags((string) $c->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                        match ($c->localName) {
                            'news_item_title'  => $haber['baslik'] = $metin,
                            'news_item_url'    => $haber['adres'] = $metin,
                            'news_item_source' => $haber['kaynak'] = $metin,
                            default            => null,
                        };
                    }

                    if ($haber['baslik'] !== '' && preg_match('#^https?://#i', $haber['adres'])) {
                        $haberler[] = $haber;
                    }
                }
            }

            $kelimeler = self::kelimeler($sorgu);

            if ($sorgu !== '' && $kelimeler !== []) {
                $sonuc[] = ['sorgu' => $sorgu, 'trafik' => $trafik, 'kelimeler' => $kelimeler, 'haberler' => $haberler];
            }
        }

        usort($sonuc, static fn (array $a, array $b): int => $b['trafik'] <=> $a['trafik']);

        return $sonuc;
    }

    /**
     * Metin bu aramayla mi ilgili: aramanin butun anlamli kelimeleri
     * metindeki kelimelerin basinda geciyor (Turkce ek: "galatasaray"
     * -> "Galatasaray'in", "deprem" -> "depremde").
     *
     * @param list<string> $kelimeler
     */
    public static function eslesir(array $kelimeler, string $metin): bool
    {
        if ($kelimeler === []) {
            return false;
        }

        $metindeki = preg_split('/\s+/u', self::sade($metin), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($kelimeler as $k) {
            $bulundu = false;

            foreach ($metindeki as $m) {
                if (str_starts_with($m, $k)) {
                    $bulundu = true;
                    break;
                }
            }

            if (!$bulundu) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aday icin en cok aranan eslesen arama; yoksa null.
     *
     * @param list<array{sorgu:string,trafik:int,kelimeler:list<string>,haberler:list<array{baslik:string,adres:string,kaynak:string}>}> $gundem
     * @return array{sorgu:string,trafik:int,kelimeler:list<string>,haberler:list<array{baslik:string,adres:string,kaynak:string}>}|null
     */
    public static function bul(array $gundem, string $baslik, string $ozet): ?array
    {
        $metin = $baslik . ' ' . $ozet;

        foreach ($gundem as $g) {
            if (self::eslesir($g['kelimeler'], $metin)) {
                return $g;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function kelimeler(string $sorgu): array
    {
        $liste = preg_split('/\s+/u', self::sade($sorgu), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $liste,
            static fn (string $k): bool => (mb_strlen($k, 'UTF-8') >= 3 || preg_match('/\d/', $k) === 1)
                && !in_array($k, self::BOS, true)
        ));
    }

    private static function sade(string $metin): string
    {
        $metin = str_replace(['İ', 'I', 'ı'], 'i', $metin);
        $metin = mb_strtolower($metin, 'UTF-8');
        $metin = str_replace("\xCC\x87", '', $metin);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $metin) ?? $metin);
    }
}
