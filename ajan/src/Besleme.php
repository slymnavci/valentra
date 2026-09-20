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
    /**
     * libxml'in kurtarma bayragi (XML_PARSE_RECOVER).
     *
     * SAYI olarak yaziliyor, LIBXML_RECOVER sabitiyle degil. Sabit her
     * PHP yapisinda YOK: gelistirme ortamindaki PHP 8.4'te tanimli ama
     * ajanin kostugu PHP 8.3'te tanimsiz ve tum kaynak testi
     * "Undefined constant" ile olumcul hataya dustu. Yerel testler
     * gecmisti; fark ancak gercek ortamda ortaya cikti.
     *
     * Ayni tuzaga daha once curl hata sabitlerinde de dusuldu
     * (CURLE_PEER_FAILED_VERIFICATION), orada da sayilara gecildi.
     *
     * Deger libxml'de sabittir ve degismez: XML_PARSE_RECOVER = 1.
     */
    private const KURTAR = 1;

    public function __construct(private readonly Indirici $http = new Http())
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

        $xml = $this->xmlCozumle($ham);

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
     * Beslemeyi çözümler; katı ayrıştırma düşerse kurtarmayı dener.
     *
     * NEDEN GEREKLI: kaynak testinde Milliyet, Muhasebe News, VOA ve
     * Patronlar Dunyasi "HTTP 200, gecerli XML" donduruyor ama
     * ayristirma sifir girdi veriyordu. Govdenin ilk satiri duzgun bir
     * <?xml ... ?><rss> basligi; sorun asagida, tek bir bozuk yerde.
     * libxml varsayilan olarak KATI: belgenin herhangi bir yerindeki
     * tek bir kacisi yapilmamis "&" ya da gecersiz denetim karakteri
     * butun belgeyi cope atiyor ve sessizce sifir haber donuyor.
     *
     * Iki asamali cozum:
     *   1. Once katı ayristirma. Saglam besleme hicbir bedel odemiyor.
     *   2. Dusarse govde temizlenip LIBXML_RECOVER ile yeniden
     *      deneniyor; libxml bozuk kismi atlayip geri kalanini veriyor.
     *
     * Kurtarma "her seyi kabul et" demek degil: kok oge yine RSS ya da
     * Atom olmak zorunda, aksi halde girdi toplayici zaten bos doner.
     *
     * @return \SimpleXMLElement|false
     */
    private function xmlCozumle(string $ham)
    {
        $onceki = libxml_use_internal_errors(true);

        $xml = simplexml_load_string($ham, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            $xml = simplexml_load_string(
                $this->xmlTemizle($ham),
                'SimpleXMLElement',
                LIBXML_NOCDATA | self::KURTAR | LIBXML_NOWARNING | LIBXML_NOERROR
            );
        }

        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        /*
         * Kurtarma bazen YARIM bir nesne dondurur.
         *
         * Cok bozuk bir belgede LIBXML kurtarma modu false yerine
         * "properly initialized" olmayan bir SimpleXMLElement
         * veriyor. Nesne elde var gorunuyor ama ilk erisimde
         * "SimpleXMLElement is not properly initialized" ile olumcul
         * hataya dusuyor — gercek bir calismada tam bu oldu ve
         * Haberturk'ten sonraki butun kaynaklar hic taranamadi.
         *
         * Bu yuzden dondurmeden once nesneye dokunup kullanilabilir
         * oldugu dogrulaniyor.
         */
        if ($xml !== false && !$this->kullanilabilir($xml)) {
            return false;
        }

        return $xml;
    }

    /**
     * Nesne gerçekten kullanılabilir mi?
     *
     * Kok ogenin adini okumak en ucuz dokunus; yarim nesne burada
     * hata firlatiyor ve biz onu yutup "besleme okunamadi" diyoruz.
     */
    private function kullanilabilir(\SimpleXMLElement $xml): bool
    {
        try {
            return $xml->getName() !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Gövdeyi ayrıştırılabilir hâle getirir.
     *
     * Uc yaygin bozukluk gideriliyor:
     *   - BOM ve <?xml öncesi bosluk: libxml bunu "content before
     *     document element" diye reddediyor
     *   - XML'de yasak denetim karakterleri (bazi yonetim panelleri
     *     metne 0x0C gibi baytlar birakiyor)
     *   - kacisi yapilmamis "&": "Ar-Ge & inovasyon" gibi basliklarda
     *     cok sik; varlik referansi olmayan & işareti &amp;'e cevriliyor
     */
    private function xmlTemizle(string $ham): string
    {
        // BOM ve bildirim oncesi bosluk.
        $ham = preg_replace('/^[\x{FEFF}\s]+(?=<)/u', '', $ham) ?? $ham;

        // XML 1.0'da yasak denetim karakterleri (tab, LF, CR haric).
        $ham = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $ham) ?? $ham;

        // Varlik referansi olmayan & isareti.
        $ham = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#x[0-9a-fA-F]+);)/', '&amp;', $ham) ?? $ham;

        return $ham;
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
                $ogeler[] = $this->rssOgesi($oge);
            }

            return $ogeler;
        }

        // RSS 1.0 / RDF: DW gibi bazı büyük yayıncılar item öğelerini
        // channel altında değil doğrudan rdf:RDF kökünde taşır. RSS 1.0
        // çoğu kez öğeleri varsayılan http://purl.org/rss/1.0/ ad alanına
        // koyduğu için hem doğrudan hem namespace üzerinden bakılır.
        if (isset($xml->item)) {
            foreach ($xml->item as $oge) {
                $ogeler[] = $this->rssOgesi($oge);
            }

            return $ogeler;
        }

        $rss10 = $xml->children('http://purl.org/rss/1.0/');

        if ($rss10 !== null && isset($rss10->item)) {
            foreach ($rss10->item as $oge) {
                $ogeler[] = $this->rssOgesi($oge);
            }

            return $ogeler;
        }

        $atomEntries = [];

        if (isset($xml->entry)) {
            $atomEntries = $xml->entry;
        } else {
            $atom = $xml->children('http://www.w3.org/2005/Atom');
            if ($atom !== null && isset($atom->entry)) {
                $atomEntries = $atom->entry;
            }
        }

        if ($atomEntries !== []) {
            foreach ($atomEntries as $oge) {
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
     * RSS 2.0 ve RSS 1.0/RDF ogelerini ortak sekle cevirir.
     *
     * @return array{baslik:string,baglanti:string,ozet:string,zaman:?int,gorsel:string}
     */
    private function rssOgesi(\SimpleXMLElement $oge): array
    {
        $tarih = $this->metin($oge->pubDate);

        if ($tarih === '') {
            $dc = $oge->children('http://purl.org/dc/elements/1.1/');
            if ($dc !== null && isset($dc->date)) {
                $tarih = $this->metin($dc->date);
            }
        }

        return [
            'baslik'   => $this->metin($oge->title),
            'baglanti' => $this->metin($oge->link),
            'ozet'     => $this->metin($oge->description),
            'zaman'    => $this->zaman($tarih),
            'gorsel'   => $this->gorsel($oge),
        ];
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
