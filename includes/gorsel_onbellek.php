<?php
declare(strict_types=1);

/**
 * Haber gorselleri sitenin kendi sunucusundan.
 *
 * Site sahibinin istegi (22.10.2026): "Merkez Bankasi Politika Faizini
 * Yuzde 36 Seviyesine Indirdi haberde resim yok". Gorsel kaydi vardi ama
 * <img> dogrudan kaynak sitenin sunucusunu gosteriyordu; bazi kaynaklar
 * baska siteden gelen gorsel istegini (hotlink) engelliyor ve gorsel
 * sessizce bos kaliyordu.
 *
 * Simdi gorsel ilk istendiginde SUNUCU kaynaktan bir kez indiriyor
 * (gorsel.php), gercekten resim mi diye bakip gorseller/haber/ altina
 * yaziyor; sonraki gosterimler dogrudan o dosyadan. Indirilemezse
 * kaynagin adresine donuluyor (referrer gondermeden, bkz. sablonlar).
 *
 * Dosya adi gorsel ADRESININ ozeti: panelden adres degisirse yeni dosya
 * iner, eskisi kullanilmaz. Klasor deploy ile silinmiyor (FTP mirror
 * --delete'siz) ve depoya girmiyor (.gitignore).
 */

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/http_ortak.php';

const HABER_GORSEL_YOLU = '/gorseller/haber';

/** Kabul edilen tur => uzanti. SVG yok: icinde betik tasiyabilir. */
const HABER_GORSEL_TURLERI = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'image/avif' => 'avif',
];

/** Bundan buyuk dosya indirilmiyor. */
const HABER_GORSEL_EN_FAZLA = 8_000_000;

/** Indirilemeyen gorsel bu kadar sure yeniden denenmiyor (saniye). */
const HABER_GORSEL_BEKLEME = 6 * 3600;

function haber_gorsel_klasoru(): string
{
    return dirname(__DIR__) . HABER_GORSEL_YOLU;
}

/** Onbellekteki dosyanin site icindeki yolu; yoksa bos. */
function haber_gorsel_yerel(string $url): string
{
    $ad = sha1($url);

    foreach (HABER_GORSEL_TURLERI as $uzanti) {
        if (is_file(haber_gorsel_klasoru() . '/' . $ad . '.' . $uzanti)) {
            return HABER_GORSEL_YOLU . '/' . $ad . '.' . $uzanti;
        }
    }

    return '';
}

/**
 * Sablonlarin kullandigi gorsel adresi.
 *
 * Onbellekte varsa yerel dosya; yoksa gorsel.php (ilk istekte indirir);
 * haberin kimligi yoksa kaynagin kendi adresi.
 */
function haber_gorsel(array $haber): string
{
    $url = guvenli_url((string) ($haber['gorsel_url'] ?? ''));

    if ($url === '') {
        return '';
    }

    // Sitenin kendi gorseli (ozgun veri haberleri, /assets/ozgun/):
    // indirmeye gerek yok, dogrudan yol.
    $taban = rtrim(site_adresi(), '/');

    if (str_starts_with($url, $taban . '/assets/')) {
        return substr($url, strlen($taban));
    }

    $yerel = haber_gorsel_yerel($url);

    if ($yerel !== '') {
        return $yerel;
    }

    $id = (int) ($haber['id'] ?? 0);

    return $id > 0 ? '/gorsel.php?id=' . $id : $url;
}

/** Adresin butun IP'leri genel internette mi? (ic aga istek atilmasin) */
function haber_gorsel_genel_adres_mi(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ipler = [$host];
    } else {
        $ipler = gethostbynamel($host) ?: [];
    }

    if ($ipler === []) {
        return false;
    }

    foreach ($ipler as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }

    return true;
}

/**
 * Gorseli kaynaktan indirip onbellege yazar; yerel yolu dondurur.
 *
 * Basarisizlikta bos doner ve bir ".yok" isareti birakir: ayni bozuk
 * adres her sayfa gosteriminde yeniden indirilmeye calisilmasin.
 */
function haber_gorsel_indir(string $url): string
{
    $url = guvenli_url($url);

    if ($url === '') {
        return '';
    }

    $yerel = haber_gorsel_yerel($url);

    if ($yerel !== '') {
        return $yerel;
    }

    $klasor = haber_gorsel_klasoru();
    $ad     = sha1($url);
    $isaret = $klasor . '/' . $ad . '.yok';

    if (is_file($isaret) && time() - (int) filemtime($isaret) < HABER_GORSEL_BEKLEME) {
        return '';
    }

    if (!is_dir($klasor) && !@mkdir($klasor, 0755, true) && !is_dir($klasor)) {
        return '';
    }

    $basarisiz = static function () use ($isaret): string {
        @touch($isaret);

        return '';
    };

    $host = (string) parse_url($url, PHP_URL_HOST);

    if (!haber_gorsel_genel_adres_mi($host)) {
        return $basarisiz();
    }

    /*
     * Referer kaynagin KENDI ana sayfasi: hotlink korumasi cogunlukla
     * "istek kendi sitemden mi" diye bakiyor. Gorseli tarayicida acan
     * okuyucu ayni sayfayi zaten goruyor; burada da ayni dosya iniyor.
     */
    $referer = parse_url($url, PHP_URL_SCHEME) . '://' . $host . '/';

    $ch = curl_init($url);
    curl_setopt_array($ch, http_ortak_secenekler(15, 6, $referer));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
    ]);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_MAXFILESIZE, HABER_GORSEL_EN_FAZLA);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($ch, $toplam, $inen): int {
        // Boyutunu bildirmeyen sunucuda da sinir: sifir disi = kes.
        return $inen > HABER_GORSEL_EN_FAZLA ? 1 : 0;
    });

    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ip    = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    curl_close($ch);

    // Yonlendirme ic aga gittiyse sonuc kullanilmiyor.
    if (!is_string($govde) || $govde === '' || $kod < 200 || $kod >= 300
        || ($ip !== '' && !haber_gorsel_genel_adres_mi($ip))) {
        return $basarisiz();
    }

    // Uzanti sunucunun beyanindan degil, dosyanin kendisinden.
    $bilgi    = @getimagesizefromstring($govde);
    $tur      = is_array($bilgi) ? (string) ($bilgi['mime'] ?? '') : '';
    $uzanti   = HABER_GORSEL_TURLERI[$tur] ?? '';

    // 1x1 takip pikselleri ve simgeler haber gorseli degil.
    if ($uzanti === '' || (int) $bilgi[0] < 50 || (int) $bilgi[1] < 50) {
        return $basarisiz();
    }

    $hedef  = $klasor . '/' . $ad . '.' . $uzanti;
    $gecici = $hedef . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (@file_put_contents($gecici, $govde) === false || !@rename($gecici, $hedef)) {
        @unlink($gecici);

        return '';
    }

    @unlink($isaret);

    return HABER_GORSEL_YOLU . '/' . $ad . '.' . $uzanti;
}
