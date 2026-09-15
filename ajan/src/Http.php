<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Dış kaynaklardan indirme. Beslemeler ve haber sayfaları ortak kullanır.
 */
final class Http
{
    public function __construct(
        private readonly int $zamanAsimi = 20,
        private readonly string $kullaniciAjani = 'ValentraBot/1.0 (+https://valentra.com.tr)',
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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $this->kullaniciAjani,
            CURLOPT_ENCODING       => '',
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
}
