<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Bir adresi indirebilen her şey.
 *
 * Arayuz oldu cunku indirmenin iki yolu var: dogrudan (Http) ve site
 * sunucusu uzerinden (Getirici). Besleme, Kazima ve Sayfa hangisini
 * kullandiklarini bilmek zorunda degil; sinifin somut turune baglanmak
 * ikinci yolu eklemeyi imkansiz kilardi.
 */
interface Indirici
{
    /** Başarısızlıkta null döner; çağıran tarafta akış durmaz. */
    public function indir(string $url, int $enFazlaBayt = 2_000_000): ?string;
}
