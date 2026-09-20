<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Kaynak ve konu grubu listesini depodaki sql/schema.sql'den okur.
 *
 * NEDEN VAR — SON CARE: ajan yapilandirmayi siteden aliyor, site
 * duserse onbellekteki kopyadan devam ediyor. Ama onbellek HENUZ
 * BOSSA (ilk calisma, ya da onbellek suresi dolmussa) ikisi de yok
 * demektir ve ajan duruyordu. IHS saatlerce 443'te baglanti kabul
 * etmedigi icin tam bu duruma dusuldu: ajan gunlerce hicbir sey
 * yapamaz hale geldi.
 *
 * Oysa kaynak listesi ZATEN depoda: veritabani bu dosyadan
 * tohumlaniyor. Siteye hic ulasilamasa bile ajan buradan okuyup
 * kaynaklari tarayabilir; yazdigi haberler depoda bekler ve siteye
 * ulasilan ilk calismada gonderilir.
 *
 * SINIRI: bu liste TOHUM listesidir, panelden yapilan degisiklikleri
 * (kapatilan kaynak, duzeltilen adres) icermez. Bu yuzden site ve
 * onbellek yoluyla alinan yapilandirma her zaman oncelikli; buraya
 * yalnizca ikisi de yoksa dusuluyor.
 */
final class TohumYapilandirma
{
    public function __construct(private readonly string $semaYolu)
    {
    }

    /**
     * @return array{kaynaklar:list<array<string,mixed>>,kategoriler:list<array<string,mixed>>,
     *               bilinen:list<string>,bilinen_url:list<string>,
     *               bilinen_baslik:list<string>,son_basliklar:list<string>}|null
     */
    public function oku(): ?array
    {
        if (!is_readable($this->semaYolu)) {
            return null;
        }

        $sema = (string) file_get_contents($this->semaYolu);

        $kaynaklar = $this->satirlariCoz($sema, 'kaynaklar');

        if ($kaynaklar === []) {
            return null;
        }

        return [
            'kaynaklar'   => $this->kaynaklariDuzenle($kaynaklar),
            'kategoriler' => $this->kategorileriDuzenle($this->satirlariCoz($sema, 'kategoriler')),

            /*
             * Bilinen haber listeleri BOS.
             *
             * Bunlar veritabaninda duruyor ve siteye ulasilamadigi
             * icin alinamiyor. Sonuc: ajan sitede zaten olan bir
             * haberi yeniden yazabilir ve model cagrisi bosa gider.
             * Veri acisindan tehlike yok — gonderim aninda site
             * tarafindaki dort katmanli kopya engeli bunlari
             * "yinelenen" sayip atiyor.
             *
             * Bosa giden model cagrisini azaltmak icin cagiran taraf
             * depodaki bekleyen haberlerin parmak izlerini bu listeye
             * ekliyor (bkz. topla.php).
             */
            'bilinen'        => [],
            'bilinen_url'    => [],
            'bilinen_baslik' => [],
            'son_basliklar'  => [],
        ];
    }

    /**
     * Bir tablonun INSERT satirlarini sutun adlariyla eslestirir.
     *
     * Elle yazilmis bir SQL ayristiricisi degil; yalnizca bu dosyadaki
     * bicimi cozuyor: "INSERT ... INTO <tablo> (sutunlar) VALUES
     * (...),(...);" Dosya bizim denetimimizde oldugu icin bu yeterli.
     *
     * @return list<array<string,string|null>>
     */
    private function satirlariCoz(string $sema, string $tablo): array
    {
        $desen = '/INSERT\s+(?:IGNORE\s+)?INTO\s+' . preg_quote($tablo, '/')
               . '\s*\(([^)]+)\)\s*VALUES(.*?);/is';

        if (preg_match_all($desen, $sema, $bloklar, PREG_SET_ORDER) === 0) {
            return [];
        }

        $sonuc = [];

        foreach ($bloklar as $blok) {
            $sutunlar = array_map(
                static fn (string $s): string => trim($s),
                explode(',', $blok[1])
            );

            foreach ($this->demetleriAyir($blok[2]) as $demet) {
                $degerler = $this->degerleriAyir($demet);

                if (count($degerler) !== count($sutunlar)) {
                    // Beklenmeyen bicim: sessizce atla, yarim satir uretme.
                    continue;
                }

                $sonuc[] = array_combine($sutunlar, $degerler);
            }
        }

        return $sonuc;
    }

    /**
     * "(...),(...)" dizisini tek tek demetlere böler.
     *
     * Parantez sayarak ilerliyor ve tirnak icindeki parantezleri
     * saymiyor; adreslerde ve aciklamalarda parantez gecebiliyor.
     *
     * @return list<string>
     */
    private function demetleriAyir(string $metin): array
    {
        $demetler = [];
        $derinlik = 0;
        $tirnakta = false;
        $baslangic = 0;
        $uzunluk = strlen($metin);

        for ($i = 0; $i < $uzunluk; $i++) {
            $k = $metin[$i];

            if ($tirnakta) {
                // SQL'de tirnak iki kez yazilarak kacirilir ('' ).
                if ($k === "'" && ($metin[$i + 1] ?? '') === "'") {
                    $i++;
                } elseif ($k === "'") {
                    $tirnakta = false;
                }

                continue;
            }

            if ($k === "'") {
                $tirnakta = true;
            } elseif ($k === '(') {
                if ($derinlik === 0) {
                    $baslangic = $i + 1;
                }

                $derinlik++;
            } elseif ($k === ')') {
                $derinlik--;

                if ($derinlik === 0) {
                    $demetler[] = substr($metin, $baslangic, $i - $baslangic);
                }
            }
        }

        return $demetler;
    }

    /**
     * Tek bir demetin degerlerini ayirir.
     *
     * @return list<string|null>
     */
    private function degerleriAyir(string $demet): array
    {
        $degerler = [];
        $tampon   = '';
        $tirnakta = false;
        $uzunluk  = strlen($demet);

        for ($i = 0; $i < $uzunluk; $i++) {
            $k = $demet[$i];

            if ($tirnakta) {
                if ($k === "'" && ($demet[$i + 1] ?? '') === "'") {
                    $tampon .= "'";
                    $i++;
                } elseif ($k === "'") {
                    $tirnakta = false;
                } else {
                    $tampon .= $k;
                }

                continue;
            }

            if ($k === "'") {
                $tirnakta = true;
            } elseif ($k === ',') {
                $degerler[] = $this->degeriCevir($tampon);
                $tampon = '';
            } else {
                $tampon .= $k;
            }
        }

        $degerler[] = $this->degeriCevir($tampon);

        return $degerler;
    }

    private function degeriCevir(string $ham): ?string
    {
        $temiz = trim($ham);

        return strtoupper($temiz) === 'NULL' ? null : $temiz;
    }

    /**
     * @param list<array<string,string|null>> $satirlar
     * @return list<array<string,mixed>>
     */
    private function kaynaklariDuzenle(array $satirlar): array
    {
        $sonuc = [];
        $id    = 0;

        foreach ($satirlar as $satir) {
            if (($satir['aktif'] ?? '1') === '0') {
                continue;
            }

            $sonuc[] = [
                // Tohum listesinde gercek kimlik yok; siteye gonderilen
                // haberde kaynak_id bos kaliyor, kaynak adi tasiniyor.
                'id'           => ++$id,
                'ad'           => (string) ($satir['ad'] ?? ''),
                'site_url'     => (string) ($satir['site_url'] ?? ''),
                'besleme_url'  => $satir['besleme_url'] ?? null,
                'liste_url'    => $satir['liste_url'] ?? null,
                'liste_secici' => $satir['liste_secici'] ?? null,
                'tur'          => (string) ($satir['tur'] ?? 'rss'),
            ];
        }

        return $sonuc;
    }

    /**
     * @param list<array<string,string|null>> $satirlar
     * @return list<array<string,mixed>>
     */
    private function kategorileriDuzenle(array $satirlar): array
    {
        $sonuc = [];

        foreach ($satirlar as $satir) {
            $slug = (string) ($satir['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            $sonuc[] = [
                'slug'     => $slug,
                'ad'       => (string) ($satir['ad'] ?? $slug),
                'aciklama' => (string) ($satir['aciklama'] ?? ''),
            ];
        }

        return $sonuc;
    }
}
