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
     * @return list<array{baslik:string,baglanti:string,ozet:string,tarih:?string}>
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
            ];
        }

        return $sonuc;
    }

    /**
     * RSS <item> ve Atom <entry> ögelerini ortak biçime indirger.
     *
     * @return list<array{baslik:string,baglanti:string,ozet:string,zaman:?int}>
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
                ];
            }
        }

        return $ogeler;
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
