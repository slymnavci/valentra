<?php
declare(strict_types=1);

/**
 * Sunucudaki PHP surumu icin guvenlik denetimi.
 *
 * Ekim 2026'daki PHP2Shell aciklari (CVE-2026-109618, CVE-2026-109619)
 * sitenin kodunda degil, sunucudaki PHP'nin kendisinde. Duzeltme
 * hosting firmasinin isi; panel yalnizca surumun yamali olup olmadigini
 * gosteriyor ki guncellemenin yapildigi buradan gorulebilsin.
 *
 * Gecici onlemler (.user.ini, .htaccess) riski yalnizca azaltiyor:
 * PHP'nin duyurusuna gore hicbir ayar aciklari tamamen kapatmiyor ve
 * .user.ini FPM'de istek isleme basladiktan sonra okunuyor. Bu yuzden
 * ini_get() ile "onlem devrede" denmiyor; ayar okunmus gorunse bile
 * koruma saglandigi anlamina gelmez.
 */

/**
 * Her surum dali icin aciklarin kapandigi ilk surum (21.10.2026).
 * Listede olmayan eski dallar artik guncelleme almiyor.
 */
const PHP_GUVENLIK_YAMALI = [
    '8.2' => '8.2.35',
    '8.3' => '8.3.36',
    '8.4' => '8.4.27',
    '8.5' => '8.5.12',
    // 8.6 henuz on surumde: duzeltmeler RC4 ile geldi.
    '8.6' => '8.6.0RC4',
];

/**
 * Sunucu yamasizsa panelde gosterilecek uyari, yamaliysa null.
 */
function php_guvenlik_uyarisi(string $surum = PHP_VERSION): ?string
{
    $parca = explode('.', $surum);
    $dal   = (int) $parca[0] . '.' . (int) ($parca[1] ?? 0);

    if (isset(PHP_GUVENLIK_YAMALI[$dal])) {
        if (version_compare($surum, PHP_GUVENLIK_YAMALI[$dal], '>=')) {
            return null;
        }
    } elseif (version_compare($dal, '8.6', '>')) {
        // Yamalardan sonra cikan dallar zaten duzeltmeyi iceriyor.
        return null;
    }

    return 'Sunucudaki PHP sürümü (' . $surum . ') PHP2Shell güvenlik açığına karşı güncel değil. '
         . 'Hosting panelinden 8.3.36, 8.4.27 ya da daha yeni bir sürüm seçin; listede yoksa IHS\'den isteyin. '
         . 'Sitedeki geçici önlemler riski azaltır ama açığı kapatmaz.';
}
