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
    /**
     * Bir calismada site uzerinden yapilabilecek en fazla istek.
     *
     * Tavan SART. Bu yol acilmadan once site zaten araliklarla
     * ulasilamaz oluyordu ve ajan gunde on kez kosuyor; her calismada
     * paylasimli hostinge onlarca ek istek yagdirmak IHS'nin hiz
     * sinirlayicisini tetikleyip durumu kotulestirebilir. Sinira
     * gelindiginde yalnizca bu yol kapanir, dogrudan indirme
     * calismaya devam eder.
     */
    private const SITE_ISTEK_TAVANI = 25;

    /** @var array<string,bool> Site yolunun denendigi ama tutmadigi sunucular */
    private array $umitsiz = [];

    private int $siteyleGelen = 0;
    private int $siteIstegi   = 0;

    public function __construct(
        private readonly Http $http,
        private readonly ?Site $site = null,
    ) {
    }

    public function indir(string $url, int $enFazlaBayt = 2_000_000): ?string
    {
        $govde = $this->http->indir($url, $enFazlaBayt);

        if ($govde !== null && trim($govde) !== '') {
            return $govde;
        }

        if ($this->site === null) {
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

        if ($this->siteIstegi >= self::SITE_ISTEK_TAVANI) {
            return null;
        }

        $this->siteIstegi++;
        $siteden = $this->site->hamGetir($url);

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
        return $this->siteIstegi >= self::SITE_ISTEK_TAVANI;
    }
}
