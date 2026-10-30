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
    foreach ([rtrim(site_adresi(), '/'), 'https://valentra.com.tr'] as $taban) {
        if (str_starts_with($url, $taban . '/assets/')) {
            return substr($url, strlen($taban));
        }
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

/* ------------------------------------------------------------------------
 * Kucultulmus kopyalar (29.10.2026, site sahibinin istegi: "resimleri
 * kucult"). Lighthouse mobil hiz puani 51'di; en buyuk sebep kaynaktan
 * geldigi gibi (cogu 1500-3000 piksel JPEG) gonderilen haber gorselleri:
 * kartta 300 piksel gorunen resim icin 400-800 KB iniyordu.
 *
 * Her gorselin 480 ve 960 piksel genislikte WebP kopyasi (WebP yoksa
 * JPEG) ilk istendiginde gorsel.php?id=..&g=.. tarafindan uretiliyor ve
 * <ad>-<genislik>.webp olarak saklaniyor; sonraki gosterimler dogrudan
 * dosyadan. Asil dosya paylasim gorseli ve yapisal veri icin aynen
 * duruyor (Google Discover buyuk gorsel istiyor).
 * --------------------------------------------------------------------- */

/** Uretilen genislikler. */
const HABER_GORSEL_GENISLIKLER = [480, 960];

/** Bundan cok pikselli asil gorsel kucultulmuyor (GD bellegi). */
const HABER_GORSEL_EN_COK_PIKSEL = 24_000_000;

/** Kucuk kopyanin dosya yolu (uzantisiz, sunucu klasorunde). */
function haber_gorsel_kopya_tabani(string $url, int $genislik): string
{
    return haber_gorsel_klasoru() . '/' . sha1($url) . '-' . $genislik;
}

/** Hazir kucuk kopyanin site icindeki yolu; yoksa bos. */
function haber_gorsel_kopya_yerel(string $url, int $genislik): string
{
    foreach (['webp', 'jpg'] as $uzanti) {
        if (is_file(haber_gorsel_kopya_tabani($url, $genislik) . '.' . $uzanti)) {
            return HABER_GORSEL_YOLU . '/' . sha1($url) . '-' . $genislik . '.' . $uzanti;
        }
    }

    return '';
}

/**
 * Sablonlar icin kucuk kopya adresi: hazirsa dosya, degilse onu ureten
 * gorsel.php. Sitenin kendi gorseli (/assets/) ve kimliksiz haber icin
 * asil adres.
 */
function haber_gorsel_kucuk(array $haber, int $genislik): string
{
    $asil = haber_gorsel($haber);
    $url  = guvenli_url((string) ($haber['gorsel_url'] ?? ''));
    $id   = (int) ($haber['id'] ?? 0);

    if ($asil === '' || str_starts_with($asil, '/assets/') || $id <= 0
        || !in_array($genislik, HABER_GORSEL_GENISLIKLER, true)) {
        return $asil;
    }

    $kopya = haber_gorsel_kopya_yerel($url, $genislik);

    return $kopya !== '' ? $kopya : '/gorsel.php?id=' . $id . '&g=' . $genislik;
}

/** srcset degeri (480w, 960w); kucuk kopya uretilemiyorsa bos. */
function haber_gorsel_srcset(array $haber): string
{
    $parcalar = [];

    foreach (HABER_GORSEL_GENISLIKLER as $g) {
        $adres = haber_gorsel_kucuk($haber, $g);

        if ($adres === haber_gorsel($haber)) {
            return '';
        }

        $parcalar[] = $adres . ' ' . $g . 'w';
    }

    return implode(', ', $parcalar);
}

/**
 * Yerel asil dosyadan kucuk kopya uretir; yolunu dondurur. Uretilemezse
 * (GD yok, tur desteklenmiyor, cok buyuk) bos: cagiran asil dosyaya doner.
 */
function haber_gorsel_kopya_uret(string $url, string $asilYol, int $genislik): string
{
    $hazir = haber_gorsel_kopya_yerel($url, $genislik);

    if ($hazir !== '') {
        return $hazir;
    }

    $dosya = dirname(__DIR__) . $asilYol;
    $bilgi = @getimagesize($dosya);

    if (!is_array($bilgi) || !function_exists('imagecreatetruecolor')
        || (int) $bilgi[0] * (int) $bilgi[1] > HABER_GORSEL_EN_COK_PIKSEL) {
        return '';
    }

    $okuyucu = match ((string) ($bilgi['mime'] ?? '')) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif'  => 'imagecreatefromgif',
        'image/avif' => 'imagecreatefromavif',
        default      => '',
    };

    if ($okuyucu === '' || !function_exists($okuyucu)) {
        return '';
    }

    $kaynak = @$okuyucu($dosya);

    if ($kaynak === false) {
        return '';
    }

    $en   = imagesx($kaynak);
    $boy  = imagesy($kaynak);
    $yeniEn  = min($genislik, $en);
    $yeniBoy = max(1, (int) round($boy * $yeniEn / $en));

    $hedef = imagecreatetruecolor($yeniEn, $yeniBoy);
    // Saydam PNG/GIF beyaz zeminde (JPEG'e dusulurse siyah cikmasin).
    imagefill($hedef, 0, 0, imagecolorallocate($hedef, 255, 255, 255));
    imagecopyresampled($hedef, $kaynak, 0, 0, 0, 0, $yeniEn, $yeniBoy, $en, $boy);
    imagedestroy($kaynak);

    $taban  = haber_gorsel_kopya_tabani($url, $genislik);
    $webp   = function_exists('imagewebp');
    $yol    = $taban . ($webp ? '.webp' : '.jpg');
    $gecici = $yol . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $tamam  = $webp ? @imagewebp($hedef, $gecici, 72) : @imagejpeg($hedef, $gecici, 78);
    imagedestroy($hedef);

    if (!$tamam || !@rename($gecici, $yol)) {
        @unlink($gecici);

        return '';
    }

    return HABER_GORSEL_YOLU . '/' . basename($yol);
}
