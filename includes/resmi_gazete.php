<?php
declare(strict_types=1);

/**
 * Resmî Gazete adreslerini tanır ve künye bilgisini çıkarır.
 *
 * Haber sayfasi Resmi Gazete'den gelen haberin altina "mevzuatin tam
 * metni" kutusunu bu bilgiyle basiyor: tarih, mukerrer sayi, PDF mi,
 * o gunun fihristi.
 *
 * Kalip ajandaki okuyucuyla (ajan/src/ResmiGazete.php, MADDE_KALIBI)
 * AYNI olmak zorunda; test ikisini karsilastiriyor.
 */

const RESMI_GAZETE_MADDE_KALIBI = '#/eskiler/(\d{4})/(\d{2})/(\d{8})(M\d+)?-\d+\.(?:htm|html|pdf)$#i';

/**
 * Adres bir Resmî Gazete mevzuat sayfasıysa künyesi, değilse null.
 *
 * @return array{tarih:string,tarih_metin:string,mukerrer:int,pdf:bool,fihrist:string}|null
 */
function resmi_gazete_kunyesi(string $url): ?array
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    if ($host !== 'www.resmigazete.gov.tr' && $host !== 'resmigazete.gov.tr') {
        return null;
    }

    $yol = (string) parse_url($url, PHP_URL_PATH);

    if (!preg_match(RESMI_GAZETE_MADDE_KALIBI, $yol, $m)) {
        return null;
    }

    $gun = DateTimeImmutable::createFromFormat('!Ymd', $m[3]);

    // Takvimde olmayan bir tarih (20261399) kunye uretmesin.
    if ($gun === false || $gun->format('Ymd') !== $m[3]) {
        return null;
    }

    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz',
              'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    /*
     * $m[4] (mukerrer) OLMAYABILIR: PHP eslesmeye katilmayan SONDAKI
     * gruplari diziye hic koymaz. "?? ''" olmadan normal sayidaki her
     * Resmi Gazete haberi sayfasi TypeError ile 500 veriyordu (testte
     * yakalandi).
     */
    $mukerrerEki = $m[4] ?? '';
    $mukerrer    = $mukerrerEki !== '' ? (int) substr($mukerrerEki, 1) : 0;

    return [
        'tarih'       => $gun->format('Y-m-d'),
        'tarih_metin' => (int) $gun->format('j') . ' ' . $aylar[(int) $gun->format('n')] . ' ' . $gun->format('Y'),
        'mukerrer'    => $mukerrer,
        'pdf'         => str_ends_with(strtolower($yol), '.pdf'),
        'fihrist'     => 'https://www.resmigazete.gov.tr/eskiler/' . $m[1] . '/' . $m[2] . '/'
                         . $m[3] . ($mukerrer > 0 ? 'M' . $mukerrer : '') . '.htm',
    ];
}
