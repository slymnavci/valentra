<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Valentra sitesiyle konuşur: yapılandırmayı çeker, haberleri gönderir.
 */
final class Site
{
    public function __construct(
        private readonly string $taban,
        private readonly string $anahtar,
        private readonly int $zamanAsimi = 30,
    ) {
    }

    /**
     * Taranacak kaynaklar ve konu grupları.
     *
     * @return array{kaynaklar:list<array<string,mixed>>,kategoriler:list<array<string,mixed>>,bilinen:list<string>}
     */
    public function yapilandirma(): array
    {
        [$kod, $govde] = $this->istek('GET', '/api/kaynaklar.php');

        if ($kod !== 200) {
            throw new \RuntimeException(
                'Yapılandırma alınamadı (HTTP ' . $kod . '): ' . $this->hatayiOku($govde)
            );
        }

        $veri = json_decode($govde, true);

        if (!is_array($veri)) {
            throw new \RuntimeException('Yapılandırma yanıtı çözümlenemedi.');
        }

        return [
            'kaynaklar'   => $veri['kaynaklar'] ?? [],
            'kategoriler' => $veri['kategoriler'] ?? [],
            // Eski surum bir site bu alani gondermez; bos liste ile
            // calismak yine dogru, yalnizca kopya suzgeci devre disi
            // kalir ve eski davranisa donulur.
            'bilinen'     => $veri['bilinen'] ?? [],
        ];
    }

    /**
     * Haberleri taslak olarak gönderir.
     *
     * @param list<array<string,mixed>> $haberler
     * @return array<string,mixed>
     */
    public function gonder(array $haberler): array
    {
        if ($haberler === []) {
            return ['eklenen' => 0, 'yinelenen' => 0, 'hatalar' => []];
        }

        [$kod, $govde] = $this->istek(
            'POST',
            '/api/ingest.php',
            json_encode(['haberler' => $haberler], JSON_UNESCAPED_UNICODE)
        );

        $veri = json_decode($govde, true);

        if ($kod !== 200 || !is_array($veri)) {
            throw new \RuntimeException(
                'Gönderim başarısız (HTTP ' . $kod . '): ' . $this->hatayiOku($govde)
            );
        }

        return $veri;
    }

    /**
     * Bir dış sayfayı SİTE sunucusu üzerinden okur.
     *
     * Turk kamu siteleri veri merkezi IP'lerini engelliyor; ajan
     * GitHub'da calistigi icin GIB, TUIK, HMB ve CSGB sayfalarinin
     * hicbirine ulasamiyor. Site Turkiye'de barindiriliyor ve ayni
     * adreslere ulasabiliyor (panel testinde GIB "HTTP 404" dondurdu,
     * yani baglanti kuruldu). Bu yuzden sayfayi siteden istiyoruz.
     *
     * Basarisizlikta null doner; cagiran taraf dogrudan indirmeye
     * dusebilir.
     */
    public function sayfaGetir(string $url): ?string
    {
        $sonuc = $this->sayfaAyrinti($url);

        return $sonuc === null ? null : $sonuc['metin'];
    }

    /**
     * Sayfanın metnini ve içindeki bağlantıları birlikte döndürür.
     *
     * Baglantilar fihrist sayfalari icin gerekli: bazi derlemeler
     * yalnizca baslik listesi tasiyor, aranan rakam alt sayfada
     * duruyor. Baglantilar olmadan oraya inmenin yolu yok.
     *
     * @return array{metin:string,baglar:list<array{yazi:string,url:string}>}|null
     */
    public function sayfaAyrinti(string $url): ?array
    {
        [$kod, $govde] = $this->istek('GET', '/api/getir.php?url=' . rawurlencode($url));

        if ($kod !== 200) {
            return null;
        }

        $veri = json_decode($govde, true);

        if (!is_array($veri) || !isset($veri['metin'])) {
            return null;
        }

        $metin = trim((string) $veri['metin']);

        if ($metin === '') {
            return null;
        }

        $baglar = [];

        foreach ((array) ($veri['baglar'] ?? []) as $bag) {
            if (is_array($bag) && isset($bag['yazi'], $bag['url'])) {
                $baglar[] = ['yazi' => (string) $bag['yazi'], 'url' => (string) $bag['url']];
            }
        }

        return ['metin' => $metin, 'baglar' => $baglar];
    }

    /**
     * Toplanacak pratik bilgilerin listesi.
     *
     * @return list<array<string,mixed>>
     */
    public function pratikBilgiler(): array
    {
        [$kod, $govde] = $this->istek('GET', '/api/pratik.php');

        if ($kod !== 200) {
            throw new \RuntimeException(
                'Bilgi listesi alınamadı (HTTP ' . $kod . '): ' . $this->hatayiOku($govde)
            );
        }

        $veri = json_decode($govde, true);

        if (!is_array($veri)) {
            throw new \RuntimeException('Bilgi listesi yanıtı çözümlenemedi.');
        }

        return $veri['bilgiler'] ?? [];
    }

    /**
     * Okunan değerleri ADAY olarak gönderir.
     *
     * Yayindaki degeri degistirmez; onay panelde veriliyor.
     *
     * @param list<array<string,mixed>> $bilgiler
     * @return array<string,mixed>
     */
    public function pratikGonder(array $bilgiler): array
    {
        if ($bilgiler === []) {
            return ['aday' => 0, 'degismedi' => 0, 'hatalar' => []];
        }

        [$kod, $govde] = $this->istek(
            'POST',
            '/api/pratik.php',
            json_encode(['bilgiler' => $bilgiler], JSON_UNESCAPED_UNICODE)
        );

        $veri = json_decode($govde, true);

        if ($kod !== 200 || !is_array($veri)) {
            throw new \RuntimeException(
                'Gönderim başarısız (HTTP ' . $kod . '): ' . $this->hatayiOku($govde)
            );
        }

        return $veri;
    }

    /**
     * Sunucunun JSON hata yanıtını okunur hâle getirir.
     *
     * Uç, sorunun ne olduğunu ve nasıl çözüleceğini "detay" alanında
     * söylüyor; ham JSON yerine onu göstermek gerekiyor.
     */
    private function hatayiOku(string $govde): string
    {
        $veri = json_decode($govde, true);

        if (!is_array($veri)) {
            return $govde;
        }

        $mesaj = (string) ($veri['hata'] ?? 'bilinmeyen hata');

        if (isset($veri['detay']) && $veri['detay'] !== '') {
            $mesaj .= "\n  -> " . $veri['detay'];
        }

        return $mesaj;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function istek(string $yontem, string $yol, ?string $govde = null): array
    {
        $adres = rtrim($this->taban, '/') . $yol;

        // Iki baslik birden gonderiliyor.
        //
        // Paylasimli hostinglerde Apache "Authorization" basligini PHP'ye
        // gecirmeyebilir (AllowOverride kapaliysa .htaccess ile de
        // asilamaz). Ozel basliklar ise her zaman gecer, bu yuzden
        // X-Valentra-Key yedek yol olarak birlikte gonderilir.
        $basliklar = [
            'Authorization: Bearer ' . $this->anahtar,
            'X-Valentra-Key: ' . $this->anahtar,
        ];

        if ($govde !== null) {
            $basliklar[] = 'Content-Type: application/json';
        }

        $sonHata = '';
        $sonKod  = 0;

        // IHS paylasimli hosting ile GitHub Actions arasinda zaman zaman
        // baglanti kurma zaman asimi gorulebiliyor. IPv4'e zorlamak,
        // daha uzun baglanti suresi vermek ve gecici ag hatalarinda
        // yeniden denemek bu durumu buyuk olcude giderir.
        //
        // Bekleme suresi kasten uzun: site yayina alinirken (FTP
        // yuklemesi sirasinda) bir-iki dakika cevap vermeyebiliyor.
        // Uc deneme 70 saniyeye sigiyordu ve bir deploy penceresine
        // denk gelen calisma bu yuzden tamamen dusuyordu. Bes deneme
        // ile pencere ~3,5 dakikaya cikiyor. Gonderim adimi ozellikle
        // onemli: model cagrilari zaten yapilmis oluyor, burada
        // vazgecmek para harcanmis sonucu cope atmak demek.
        $beklemeler = [5, 15, 30, 60];
        $enFazlaDeneme = count($beklemeler) + 1;

        for ($deneme = 1; $deneme <= $enFazlaDeneme; $deneme++) {
            $ch = curl_init($adres);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $yontem,
                CURLOPT_HTTPHEADER     => $basliklar,
                CURLOPT_TIMEOUT        => max($this->zamanAsimi, 45),
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            if ($govde !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
            }

            $yanit   = curl_exec($ch);
            $sonKod  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $sonHata = curl_error($ch);
            $errno   = curl_errno($ch);
            curl_close($ch);

            if (is_string($yanit)) {
                return [$sonKod, $yanit];
            }

            // DNS, baglanti ve timeout hatalari gecici olabilir.
            if (!in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT], true)) {
                break;
            }

            if ($deneme < $enFazlaDeneme) {
                sleep($beklemeler[$deneme - 1]);
            }
        }

        throw new \RuntimeException(
            'Siteye ulaşılamadı: ' . ($sonHata !== '' ? $sonHata : 'HTTP ' . $sonKod)
        );
    }
}
