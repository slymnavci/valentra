<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * RSS ve Atom beslemelerini okur.
 *
 * Harici bağımlılık yok: SimpleXML PHP ile birlikte gelir. Bozuk ya da
 * ulaşılamayan bir besleme tüm çalışmayı durdurmaz; boş dizi döner ve
 * çağıran tarafta kaydedilir.
 */
final class Besleme
{
    public function __construct(private readonly Http $http = new Http())
    {
    }

    /**
     * Beslemeden son $saat içindeki girdileri döndürür.
     *
     * @return list<array{baslik:string,baglanti:string,ozet:string,tarih:?string,gorsel:string}>
     */
    public function oku(string $url, int $saat = 36): array
    {
        $ham = $this->http->indir($url);

        if ($ham === null) {
            return [];
        }

        $onceki = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($ham);
        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        if ($xml === false) {
            return [];
        }

        $girdiler = $this->girdileriTopla($xml);
        $sinir    = time() - ($saat * 3600);
        $sonuc    = [];

        foreach ($girdiler as $girdi) {
            // Tarihi olmayan girdiyi elemiyoruz; parmak izi zaten kopyayı önler.
            if ($girdi['zaman'] !== null && $girdi['zaman'] < $sinir) {
                continue;
            }

            if ($girdi['baslik'] === '' || $girdi['baglanti'] === '') {
                continue;
            }

            $sonuc[] = [
                'baslik'   => $girdi['baslik'],
                'baglanti' => $girdi['baglanti'],
                'ozet'     => $girdi['ozet'],
                'tarih'    => $girdi['zaman'] !== null ? date('Y-m-d H:i:s', $girdi['zaman']) : null,
                'gorsel'   => $girdi['gorsel'],
            ];
        }

        return $sonuc;
    }

    /**
     * RSS <item> ve Atom <entry> ögelerini ortak biçime indirger.
     *
     * @return list<array{baslik:string,baglanti:string,ozet:string,zaman:?int,gorsel:string}>
     */
    private function girdileriTopla(\SimpleXMLElement $xml): array
    {
        $ogeler = [];

        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $oge) {
                $ogeler[] = [
                    'baslik'   => $this->metin($oge->title),
                    'baglanti' => $this->metin($oge->link),
                    'ozet'     => $this->metin($oge->description),
                    'zaman'    => $this->zaman($this->metin($oge->pubDate)),
                    'gorsel'   => $this->gorsel($oge),
                ];
            }

            return $ogeler;
        }

        if (isset($xml->entry)) {
            foreach ($xml->entry as $oge) {
                $baglanti = $this->metin($oge->link);

                if ($baglanti === '' && isset($oge->link['href'])) {
                    $baglanti = (string) $oge->link['href'];
                }

                $ozet = $this->metin($oge->summary);

                if ($ozet === '') {
                    $ozet = $this->metin($oge->content);
                }

                $ogeler[] = [
                    'baslik'   => $this->metin($oge->title),
                    'baglanti' => $baglanti,
                    'ozet'     => $ozet,
                    'zaman'    => $this->zaman($this->metin($oge->updated) ?: $this->metin($oge->published)),
                    'gorsel'   => $this->gorsel($oge),
                ];
            }
        }

        return $ogeler;
    }

    /**
     * Besleme girdisindeki görseli bulur.
     *
     * Beslemeler görseli tek bir yerde taşımıyor: kimi <enclosure>,
     * kimi Media RSS (media:content / media:thumbnail), kimi de yalnızca
     * açıklama HTML'inin içindeki <img> ile veriyor. Hepsi sırayla
     * denenir; bulunamazsa haber sayfasından og:image'a düşülür.
     */
    private function gorsel(\SimpleXMLElement $oge): string
    {
        // 1) RSS enclosure: tür resim olmalı, yoksa ses/video eki olabilir.
        if (isset($oge->enclosure['url'])) {
            $tur = strtolower((string) ($oge->enclosure['type'] ?? ''));

            if ($tur === '' || str_starts_with($tur, 'image/')) {
                $adres = trim((string) $oge->enclosure['url']);

                if ($this->gorselMi($adres)) {
                    return $adres;
                }
            }
        }

        // 2) Media RSS.
        //
        // Ad uzayli bir ogenin on eksiz niteligine $oge['url'] ile
        // erisilemiyor; SimpleXML bunu ancak attributes() uzerinden
        // veriyor. Dogrudan indislemek sessizce bos donuyordu.
        foreach (['http://search.yahoo.com/mrss/', 'http://search.yahoo.com/mrss'] as $uzay) {
            $media = $oge->children($uzay);

            if ($media === null) {
                continue;
            }

            foreach (['content', 'thumbnail'] as $etiket) {
                foreach ($media->{$etiket} ?? [] as $dugum) {
                    $nitelikler = $dugum->attributes();
                    $adres = trim((string) ($nitelikler['url'] ?? ''));

                    if ($this->gorselMi($adres)) {
                        return $adres;
                    }
                }
            }
        }

        // 3) Aciklama HTML'indeki ilk <img>.
        foreach (['description', 'content', 'summary'] as $alan) {
            if (!isset($oge->{$alan})) {
                continue;
            }

            $ham = (string) $oge->{$alan};

            if (preg_match('#<img[^>]+src=["\']([^"\']+)["\']#i', $ham, $eslesme) === 1) {
                $adres = html_entity_decode(trim($eslesme[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($this->gorselMi($adres)) {
                    return $adres;
                }
            }
        }

        // 4) content:encoded (icerik HTML'i ayri ad uzayinda gelir).
        $icerik = $oge->children('http://purl.org/rss/1.0/modules/content/');

        if ($icerik !== null && isset($icerik->encoded)) {
            if (preg_match('#<img[^>]+src=["\']([^"\']+)["\']#i', (string) $icerik->encoded, $eslesme) === 1) {
                $adres = html_entity_decode(trim($eslesme[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($this->gorselMi($adres)) {
                    return $adres;
                }
            }
        }

        return '';
    }

    /** Adres http/https ile baslayan makul bir gorsel adresi mi? */
    private function gorselMi(string $adres): bool
    {
        if ($adres === '' || !preg_match('#^https?://#i', $adres)) {
            return false;
        }

        // 1x1 takip pikselleri haber gorseli degil.
        return !preg_match('#(1x1|pixel|spacer|blank)\.(gif|png)#i', $adres);
    }

    private function metin(mixed $dugum): string
    {
        if ($dugum === null) {
            return '';
        }

        $ham = trim(html_entity_decode(strip_tags((string) $dugum), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return (string) preg_replace('/\s+/u', ' ', $ham);
    }

    private function zaman(string $tarih): ?int
    {
        if ($tarih === '') {
            return null;
        }

        $zaman = strtotime($tarih);

        return $zaman === false ? null : $zaman;
    }
}
