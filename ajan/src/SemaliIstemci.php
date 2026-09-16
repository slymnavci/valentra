<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Şema kısıtlı model isteği atabilen istemci.
 *
 * DegerOkuyucu dogrudan Yazar'a baglanmiyor; bu arayuze bagli.
 * Sebep tasarimdan cok dogrulanabilirlik: aradaki en kritik ayrinti,
 * istemdeki sira numarasi ile yanittaki sira numarasinin ayni tabani
 * kullanmasi. Bir kez kaydi ve SGK'ya ait deger TCMB basligina
 * baglandi. Arayuz sayesinde bu hizalama modele hic gitmeden
 * sinanabiliyor.
 */
interface SemaliIstemci
{
    /**
     * @param array<string,mixed> $sema
     * @param int    $adayAdedi   Sira denetimi icin aday sayisi
     * @param string $zorunluAlan Sonucta bulunmasi gereken alan
     * @return array<int,array<string,mixed>> 0 tabanli sira => sonuc
     */
    public function semaliIstek(
        string $yonerge,
        string $istem,
        array $sema,
        int $enFazlaToken,
        int $adayAdedi = 0,
        string $zorunluAlan = ''
    ): array;
}
