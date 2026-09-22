<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Önce doğrudan, olmazsa site sunucusu üzerinden indirir.
 *
 * NEDEN VAR: ajan GitHub uzerinde calisiyor ve Turk kamu ile meslek
 * siteleri veri merkezi IP'lerini engelliyor. Son calismada 80
 * kaynagin 39'u "yeni girdi yok" dondu; aralarinda Resmi Gazete, GIB,
 * Hazine ve Maliye, KGK, TUIK, Alomaliye, ISMMMO, MuhasebeTR — yani
 * sitenin butun cekirdek vergi kaynaklari vardi. Bu kaynaklar
 * olmadan ajan yalnizca yabanci kurum sayfalarini topluyor.
 *
 * Site Turkiye'de barindigi icin ayni adreslere ulasabiliyor. Pratik
 * bilgi toplayici bu yolu zaten kullaniyordu; haber toplayici
 * kullanmiyordu.
 *
 * SIRA ONEMLI: once dogrudan deneniyor. Site uzerinden gecmek bir
 * ekstra tur demek; calisan kaynaklar icin bu bedeli odemeye gerek
 * yok. Yalnizca dogrudan yol duserse ikinci yol deneniyor.
 */
final class Getirici implements Indirici
{
    /** @var array<string,bool> Site yolunun denendigi ama tutmadigi sunucular */
    private array $umitsiz = [];

    /**
     * Site sunucusunun kendisi bu calismada ulasilamaz mi?
     *
     * $umitsiz'den farkli: orada "bu KAYNAK site uzerinden de
     * gelmedi" bilgisi tutuluyor. Burada tutulan "SITE ayakta degil"
     * — yani hicbir kaynak icin bu yolun denenmesinin anlami yok.
     */
    private bool $siteKapali = false;

    private int $siteyleGelen = 0;
    private int $siteIstegi   = 0;
    private float $sonSiteIstegi = 0.0;

    /**
     * @param int   $siteTavani Bir calismada site uzerinden en fazla istek
     * @param float $siteAraligi Iki site istegi arasindaki en az sure (sn)
     *
     * TAVAN VE ARALIK SART — bedeli olcusuz degil, olculdu.
     *
     * Bu yol acildiktan sonra site araliklarla ulasilamaz olmaya
     * basladi. Sebep bulundu: kaynak testi basarisiz her kaynak icin
     * hem RSS hem kazima adresini siteden istiyor, ustune kesif
     * cagrilari da ayni yoldan geciyordu. 32 basarisiz kaynakta bu,
     * uc dakikada 128 istek demek — paylasimli hostinglerdeki
     * guvenlik duvarlari (Imunify360, fail2ban) tam bu davranista
     * IP'yi gecici olarak yasaklar.
     *
     * Zaman cizelgesi de uyuyordu: yasaklar her seferinde test
     * kosturmalarindan sonra basliyordu. Yani cozumumuz kendi
     * sorunumuzu uretiyordu.
     *
     * Tavana gelindiginde yalnizca bu yol kapanir; dogrudan indirme
     * calismaya devam eder.
     *
     * TAVAN 8'DEN 20'YE CIKTI, ARALIK 1 SN'DEN 2 SN'YE.
     *
     * Sekiz istek mevzuat kaynaklarina yetmiyordu: yalnizca Turk kamu
     * sitelerinden on ucu bu yola muhtac ve bircogu hem besleme hem
     * liste adresi istiyor, yani hak dort kaynakta tukeniyordu.
     * Gerisi her calismada ayni yerde eleniyor, mevzuat haberi
     * gelmiyordu.
     *
     * Hacmi artirirken HIZ DUSURULDU, cunku yasaklanmanin sebebi
     * toplam degil hizdi: eski ayar saniyede bir istek demekti, yeni
     * ayar saniyede yarim. Yirmi istek iki saniye arayla kirk saniyeye
     * yayiliyor — eskisinin sekiz saniyesine kiyasla daha uzun sure
     * ama daha seyrek. Paylasimli hostinglerdeki guvenlik duvarlari
     * (Imunify360, fail2ban) ani yogunluga tepki veriyor.
     */
    public function __construct(
        private readonly Http $http,
        private readonly ?Site $site = null,
        private readonly int $siteTavani = 20,
        private readonly float $siteAraligi = 2.0,
    ) {
    }

    public function indir(string $url, int $enFazlaBayt = 2_000_000): ?string
    {
        $govde = $this->http->indir($url, $enFazlaBayt);

        if ($govde !== null && trim($govde) !== '') {
            return $govde;
        }

        if ($this->site === null || $this->siteKapali) {
            return $govde;
        }

        /*
         * Ayni sunucu icin site yolu bir kez basarisiz olduysa bir daha
         * denenmiyor. Bir kaynaktan onlarca adres geliyor; her biri icin
         * bosuna bir tur atmak calismanin zaman butcesini yer.
         */
        $sunucu = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($sunucu !== '' && isset($this->umitsiz[$sunucu])) {
            return null;
        }

        if ($this->siteIstegi >= $this->siteTavani) {
            return null;
        }

        /*
         * Istekler arasina zorunlu bosluk.
         *
         * Tavan tek basina yetmiyor: sekiz istegi de ayni saniyede
         * gondermek hiz sinirlayicisi acisindan "sekiz istek/saniye"
         * demek ve tam da yasaklanan davranis bu.
         */
        $gecen = microtime(true) - $this->sonSiteIstegi;

        if ($this->sonSiteIstegi > 0.0 && $gecen < $this->siteAraligi) {
            usleep((int) (($this->siteAraligi - $gecen) * 1_000_000));
        }

        $this->sonSiteIstegi = microtime(true);
        $this->siteIstegi++;

        /*
         * SITE HATASI KAYNAGIN HATASI DEGILDIR.
         *
         * hamGetir baglanti kurulamazsa istisna firlatiyor ve bu
         * istisna buradan cikinca topla.php'deki kaynak kalkanina
         * dusuyordu: kaynak "HATA, atlandı" diye isaretleniyor,
         * ustelik her biri icin dakikalarca beklendikten sonra.
         * Gercek calismada Resmi Gazete, GIB ve BDO boyle elendi —
         * ucunun de kendi sunucusu saglamdi, dusen bizim sitemizdi.
         *
         * Dogru davranis: bu adres icin ikinci yol tutmadi (null),
         * kaynak dongusu bozulmadan devam etsin. Ustelik site
         * cokmusse yol butun calisma icin kapanir; bir daha hicbir
         * kaynak bu bedeli odemez.
         */
        try {
            $siteden = $this->site->hamGetir($url);
        } catch (\Throwable $e) {
            $this->siteKapali = true;

            return null;
        }

        if ($siteden === null) {
            if ($sunucu !== '') {
                $this->umitsiz[$sunucu] = true;
            }

            return null;
        }

        $this->siteyleGelen++;

        return $siteden;
    }

    /** Kac adresin site uzerinden geldigi; gunluge yazmak icin. */
    public function siteyleGelenSayisi(): int
    {
        return $this->siteyleGelen;
    }

    /** Site istek tavanina gelindi mi; gunluge yazmak icin. */
    public function siteTavaniDoldu(): bool
    {
        return $this->siteIstegi >= $this->siteTavani;
    }

    /** Site yolu bu calismada tamamen kapandi mi; gunluge yazmak icin. */
    public function siteKapaliMi(): bool
    {
        return $this->siteKapali;
    }
}
