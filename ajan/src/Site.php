<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Valentra sitesiyle konuşur: yapılandırmayı çeker, haberleri gönderir.
 */
final class Site
{
    /**
     * Site baglanti seviyesinde bir kez tamamen dustu mu?
     *
     * NEDEN VAR: yeniden deneme pencereleri tek bir cagri icin
     * tasarlandi, arka arkaya ONLARCA cagri icin degil. Site 443'te
     * baglanti kabul etmedigi bir calismada olcum sudur:
     * yapilandirma cagrisi 11 dakika (9 deneme), ardindan her kaynak
     * icin 3,5 dakika (5 deneme). Uc kaynak 10,5 dakika yedi ve 80
     * kaynagin 72'si zaman butcesi doldugu icin hic taranmadi. O
     * calismada sifir haber yazildi.
     *
     * Ayni dakikalarda ulasilamayan bir sunucuya ayni sabirla tekrar
     * tekrar gitmek bilgi uretmiyor; yalnizca calismayi yiyor.
     *
     * Latch SESSIZ DEGIL, HIZLI: sonraki cagrilar yine deneniyor ama
     * tek seferde ve kisa zaman asimiyla. Site geri gelirse ilk
     * basarili cagri latch'i acar. Buyuk fark: basarisizligin bedeli
     * 3,5 dakikadan ~8 saniyeye iniyor.
     */
    private bool $cekimser = false;

    public function __construct(
        private readonly string $taban,
        private readonly string $anahtar,
        private readonly int $zamanAsimi = 30,
    ) {
    }

    /** Siteye baglanti seviyesinde ulasilamiyor mu; gunluge yazmak icin. */
    public function cekimserMi(): bool
    {
        return $this->cekimser;
    }

    /**
     * Taranacak kaynaklar ve konu grupları.
     *
     * @return array{kaynaklar:list<array<string,mixed>>,kategoriler:list<array<string,mixed>>,bilinen:list<string>}
     */
    public function yapilandirma(): array
    {
        // Sabirli: bu cagri duserse butun calisma bos gider.
        [$kod, $govde] = $this->istek('GET', '/api/kaynaklar.php', null, true);

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
            'bilinen'        => $veri['bilinen'] ?? [],
            'bilinen_url'    => $veri['bilinen_url'] ?? [],
            'bilinen_baslik' => $veri['bilinen_baslik'] ?? [],
            'son_basliklar'  => $veri['son_basliklar'] ?? [],
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
     * Adresin HAM gövdesini site sunucusu üzerinden getirir.
     *
     * Besleme okurken metne indirgenmis govde ise yaramaz: etiketler
     * atilinca XML yapisi da gider. Bu yuzden ayri bir uc.
     *
     * Neden site uzerinden: Turk kamu ve meslek siteleri veri merkezi
     * IP'lerini engelliyor, ajan ise GitHub'da calisiyor. Son
     * calismada 80 kaynagin 39'u bos dondu ve aralarinda Resmi Gazete,
     * GIB, Hazine, TUIK, Alomaliye, ISMMMO vardi. Site Turkiye'de
     * barindigi icin ayni adreslere ulasabiliyor.
     */
    /**
     * Son hamGetir cagrisinin basarisizlik sebebi.
     *
     * Uc zaten sebebi soyluyor — 502 yanitinda "Guvenlik sertifikasi
     * dogrulanamadi" ya da "Sunucu HTTP 403 dondu" yaziyor — ama bu
     * bilgi eskiden burada atiliyordu: metot yalnizca null donuyordu.
     * Ajan gunlugunde bu yuzden 29 kaynak icin tek ve ayni cumle
     * goruluyordu: "okunamadi veya yeni girdi yok". Sebebi bilmeden
     * hicbirini duzeltmek mumkun degil.
     */
    private string $sonHata = '';

    public function sonHamHatasi(): string
    {
        return $this->sonHata;
    }

    public function hamGetir(string $url): ?string
    {
        $this->sonHata = '';

        [$kod, $govde] = $this->istek(
            'GET',
            '/api/getir.php?ham=1&url=' . rawurlencode($url)
        );

        if ($kod !== 200) {
            $veri = json_decode($govde, true);

            /*
             * Ucun kendi acikladigi sebep varsa o kullaniliyor; yoksa
             * en azindan HTTP kodu yaziliyor. 403 "adres tanimli
             * kaynaklar arasinda degil", 502 "kaynak sunucuya
             * ulasilamadi" demek ve ikisi bambaska islere bakar.
             */
            $this->sonHata = is_array($veri) && isset($veri['hata'])
                ? 'site ucu: ' . (string) $veri['hata']
                : 'site ucu HTTP ' . $kod . ' döndü';

            return null;
        }

        $veri = json_decode($govde, true);

        if (!is_array($veri) || !isset($veri['ham'])) {
            $this->sonHata = 'site ucu beklenen yanıtı vermedi';

            return null;
        }

        $ham = base64_decode((string) $veri['ham'], true);

        if (!is_string($ham) || $ham === '') {
            $this->sonHata = 'site ucu boş gövde döndü';

            return null;
        }

        return $ham;
    }

    /**
     * Sayfanın metnini ve içindeki bağlantıları birlikte döndürür.
     *
     * Baglantilar fihrist sayfalari icin gerekli: bazi derlemeler
     * yalnizca baslik listesi tasiyor, aranan rakam alt sayfada
     * duruyor. Baglantilar olmadan oraya inmenin yolu yok.
     *
     * @return array{metin:string,baglar:list<array{no:int,yazi:string,url:string}>}|null
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
                $baglar[] = [
                    // Numara metindeki [BAG:n] isaretiyle ayni; model
                    // izlenecek baglantiyi bu numarayla soyluyor.
                    'no'   => (int) ($bag['no'] ?? 0),
                    'yazi' => (string) $bag['yazi'],
                    'url'  => (string) $bag['url'],
                ];
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
    private function istek(
        string $yontem,
        string $yol,
        ?string $govde = null,
        bool $sabirli = false
    ): array
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

        /*
         * SABIRLI MOD: yapilandirma icin biraz daha uzun bekle.
         *
         * IHS 443'te araliklarla baglanti kabul etmiyor; ayni
         * dakikalarda FTP calistigi icin sunucu ayakta ama
         * baglantilar TCP seviyesinde zaman asimina ugruyor. Bir
         * gunde olcum: 20:07 dustu, 20:11 calisti, 20:17 calisti,
         * 20:26 dustu.
         *
         * Pencere bir ara ~9 dakikaya cikarilmisti: o zaman
         * yapilandirma cagrisinin dusmesi butun calismayi olduruyordu
         * (kaynak taranmiyor, model cagrilmiyor, hicbir haber
         * yazilmiyor) ve beklemek her seye degerdi.
         *
         * ARTIK DEGMIYOR, cunku o varsayim dogru degil: yapilandirma
         * alinamazsa onbellekteki kopyaya, o da yoksa depodaki tohum
         * listesine dusuluyor ve tarama normal sekilde yapiliyor.
         * Dokuz denemeyle beklemenin olculen bedeli ise agir — site
         * kapaliyken tek basina 11 DAKIKA yiyor ve bu sure dogrudan
         * kaynak taramasindan kesiliyor.
         *
         * Pencere ~4,5 dakika: bir FTP yayin penceresi (1-2 dakika)
         * rahatlikla sigiyor. Daha uzun suren bir kesintide beklemek
         * degil, yedek listeyle taramaya baslamak dogru.
         */
        if ($sabirli) {
            $beklemeler = [5, 15, 30, 45, 60];
        }

        /*
         * Site zaten dustuyse tek deneme.
         *
         * Sabirli mod dahil butun beklemeler iptal: ayni calismada
         * dakikalar once baglanti kabul etmeyen sunucuya dokuz kez
         * daha gitmenin karsiligi yok. Yine de bir deneme yapiliyor
         * (sifir deneme degil) cunku site donebilir ve donduyse
         * bunu ogrenmenin bedeli birkac saniye.
         */
        if ($this->cekimser) {
            $beklemeler = [];
        }

        $enFazlaDeneme = count($beklemeler) + 1;

        for ($deneme = 1; $deneme <= $enFazlaDeneme; $deneme++) {
            $ch = curl_init($adres);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $yontem,
                CURLOPT_HTTPHEADER     => $basliklar,
                CURLOPT_TIMEOUT        => $this->cekimser ? 15 : max($this->zamanAsimi, 45),
                CURLOPT_CONNECTTIMEOUT => $this->cekimser ? 8 : 20,
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
                // Site dondu: latch acilir, sonraki cagrilar yine
                // sabirli davranir.
                $this->cekimser = false;

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

        /*
         * Butun denemeler baglanti seviyesinde tukendiyse latch kapanir.
         *
         * Yalnizca baglanti hatalarinda: sunucunun 500 dondurmesi
         * "ulasilamiyor" demek degil, o durumda sabri kismak yanlis
         * olur.
         */
        if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT], true)) {
            $this->cekimser = true;
        }

        throw new \RuntimeException(
            'Siteye ulaşılamadı: ' . ($sonHata !== '' ? $sonHata : 'HTTP ' . $sonKod)
        );
    }
}
