<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Dış kaynaklardan indirme. Beslemeler ve haber sayfaları ortak kullanır.
 */
final class Http implements Indirici
{
    /**
     * Tarayıcı kimliği.
     *
     * Onceden "ValentraBot/1.0" yaziyordu ve bu, kaynaklarin yarisinin
     * neden bos dondugunun buyuk bir kismiydi: 80 kaynagin 39'u "yeni
     * girdi yok" veriyordu ve aralarinda Resmi Gazete, GIB, Hazine,
     * KGK, TUIK, Alomaliye, ISMMMO, MuhasebeTR gibi sitenin TUM cekirdek
     * vergi kaynaklari vardi. Kamu ve kurum siteleri bilinmeyen
     * istemcileri reddediyor.
     *
     * Site tarafinda ayni sorun yasanmis ve ayni sekilde cozulmustu
     * (includes/http_ortak.php); ajan tarafi geride kalmisti.
     */
    private const TARAYICI = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                           . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                           . 'Chrome/128.0.0.0 Safari/537.36';

    public function __construct(
        private readonly int $zamanAsimi = 15,
        private readonly string $kullaniciAjani = self::TARAYICI,
    ) {
    }

    /** Başarısızlıkta null döner; çağıran tarafta akış durmaz. */
    public function indir(string $url, int $enFazlaBayt = 2_000_000): ?string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $this->zamanAsimi,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_USERAGENT      => $this->kullaniciAjani,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.9, */*;q=0.8',
                'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            ],
            CURLOPT_ENCODING       => '',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            // Devasa bir dosyayı belleğe çekmemek için erken kes.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $inecek, $inen) use ($enFazlaBayt): int {
                return $inen > $enFazlaBayt ? 1 : 0;
            },
        ]);

        $govde = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($govde) || $kod < 200 || $kod >= 300) {
            return null;
        }

        return $govde;
    }

    /**
     * Tani amacli indirme: basarisizlikta da ayrinti dondurur.
     *
     * indir() hatayi yutup null dondurur; akis icin dogru ama "bu kaynak
     * neden okunmuyor" sorusunu cevaplamiyor. Burada HTTP kodu, icerik
     * turu ve curl hatasi birlikte geliyor.
     *
     * @return array{kod:int,tur:string,hata:string,boyut:int,govde:?string,adres:string}
     */
    public function dene(string $url, int $enFazlaBayt = 2_000_000): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $this->zamanAsimi,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_USERAGENT      => $this->kullaniciAjani,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.9, */*;q=0.8',
                'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            ],
            CURLOPT_ENCODING       => '',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $inecek, $inen) use ($enFazlaBayt): int {
                return $inen > $enFazlaBayt ? 1 : 0;
            },
        ]);

        $govde = curl_exec($ch);

        $sonuc = [
            'kod'   => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'tur'   => (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? ''),
            'hata'  => curl_error($ch),
            'boyut' => is_string($govde) ? strlen($govde) : 0,
            'govde' => is_string($govde) ? $govde : null,
            'adres' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        ];

        curl_close($ch);

        return $sonuc;
    }
}
