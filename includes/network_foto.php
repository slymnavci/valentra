<?php
declare(strict_types=1);

/**
 * V Network fotograflari: profil resmi ve paylasim fotograflari
 * (31.10.2026, site sahibi: "kullanicilarin sayfada fotograflari olsun,
 * profil resmi girebilsinler, fotograf ekleyebilsinler; video
 * linklenebilir ama ekleme olmasin; fotograflarda da sinir koyalim").
 *
 * YUKLEME YOLU: sitede butun multipart (dosya yuklemeli) istekler
 * .htaccess'te kapali (PHP2Shell onlemi, kokteki .htaccess). Bu yuzden
 * fotograf tarayicida kucultulup (en uzun kenar 1600px) data: adresi
 * olarak siradan form alaninda geliyor; PHP'nin multipart ayristiricisi
 * hic calismiyor. Betik calismazsa fotograf eklenemez, yazi paylasilir.
 *
 * GUVENLIK: gelen veri getimagesizefromstring ile sinaniyor, GD ile
 * cozulup YENIDEN KODLANIYOR (WebP): resim olmayan icerik gecemez, EXIF
 * (konum bilgisi dahil) ve diger meta veriler atiliyor. Dosya adi
 * rastgele; klasorde PHP calismiyor (gorseller/uye/.htaccess).
 *
 * SINIRLAR: paylasim basina NW_FOTO_PAYLASIM_EN_FAZLA fotograf; gelen
 * her fotograf en fazla NW_FOTO_BAYT_EN_FAZLA; uye basina 24 saatte
 * NW_FOTO_GUNLUK_EN_FAZLA fotograf. Tarayici 8 MB'tan buyuk dosyayi
 * kucultmeye hic almiyor.
 */

const NW_FOTO_DIZIN            = __DIR__ . '/../gorseller/uye';
const NW_FOTO_YOL              = '/gorseller/uye';
const NW_FOTO_PAYLASIM_EN_FAZLA = 4;
const NW_FOTO_GUNLUK_EN_FAZLA  = 20;
const NW_FOTO_BAYT_EN_FAZLA    = 3 * 1024 * 1024;
const NW_FOTO_KENAR            = 1600;
const NW_PROFIL_KENAR          = 400;
/** Tarayicinin kucultmeye alacagi en buyuk asil dosya (bilgi icin; betikte de ayni). */
const NW_FOTO_ASIL_EN_FAZLA_MB = 8;

/**
 * data:image/...;base64 adresini cozer, sinar ve WebP olarak kaydeder.
 *
 * @param bool $kare profil resmi: ortadan kare kesilip NW_PROFIL_KENAR'a
 * @return array{dosya:string, genislik:int, yukseklik:int}
 */
function nw_foto_kaydet(string $veri, bool $kare = false): array
{
    if (!preg_match('#^data:image/(jpeg|png|webp|gif);base64,([A-Za-z0-9+/=\s]+)$#', $veri, $e)) {
        throw new InvalidArgumentException('Fotoğraf okunamadı; JPG, PNG ya da WebP seçin.');
    }

    $ham = base64_decode(preg_replace('/\s+/', '', $e[2]) ?? '', true);

    if ($ham === false || $ham === '') {
        throw new InvalidArgumentException('Fotoğraf okunamadı.');
    }

    if (strlen($ham) > NW_FOTO_BAYT_EN_FAZLA) {
        throw new InvalidArgumentException('Fotoğraf çok büyük; en fazla ' . NW_FOTO_ASIL_EN_FAZLA_MB . ' MB olabilir.');
    }

    $bilgi = @getimagesizefromstring($ham);

    if (!is_array($bilgi) || !in_array($bilgi[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)
        || (int) $bilgi[0] < 32 || (int) $bilgi[1] < 32 || (int) $bilgi[0] * (int) $bilgi[1] > 24_000_000) {
        throw new InvalidArgumentException('Bu dosya geçerli bir fotoğraf değil.');
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        throw new InvalidArgumentException('Fotoğraf yükleme şu anda kullanılamıyor.');
    }

    $kaynak = @imagecreatefromstring($ham);

    if ($kaynak === false) {
        throw new InvalidArgumentException('Bu dosya geçerli bir fotoğraf değil.');
    }

    $g = imagesx($kaynak);
    $y = imagesy($kaynak);

    if ($kare) {
        $kenar = min($g, $y);
        $hedefG = $hedefY = min(NW_PROFIL_KENAR, $kenar);
        $kx = intdiv($g - $kenar, 2);
        $ky = intdiv($y - $kenar, 2);
        $kg = $ky2 = $kenar;
    } else {
        $oran   = min(1, NW_FOTO_KENAR / max($g, $y));
        $hedefG = max(1, (int) round($g * $oran));
        $hedefY = max(1, (int) round($y * $oran));
        $kx = $ky = 0;
        $kg = $g;
        $ky2 = $y;
    }

    $hedef = imagecreatetruecolor($hedefG, $hedefY);
    // Seffaf PNG beyaz zemine.
    imagefill($hedef, 0, 0, imagecolorallocate($hedef, 255, 255, 255));
    imagecopyresampled($hedef, $kaynak, 0, 0, $kx, $ky, $hedefG, $hedefY, $kg, $ky2);
    imagedestroy($kaynak);

    if (!is_dir(NW_FOTO_DIZIN)) {
        @mkdir(NW_FOTO_DIZIN, 0755, true);
    }

    $dosya = bin2hex(random_bytes(12)) . '.webp';
    $tamam = @imagewebp($hedef, NW_FOTO_DIZIN . '/' . $dosya, 82);
    imagedestroy($hedef);

    if (!$tamam) {
        throw new InvalidArgumentException('Fotoğraf kaydedilemedi; lütfen yeniden deneyin.');
    }

    return ['dosya' => $dosya, 'genislik' => $hedefG, 'yukseklik' => $hedefY];
}

function nw_foto_sil(string $dosya): void
{
    if (preg_match('/^[0-9a-f]{24}\.webp$/', $dosya)) {
        @unlink(NW_FOTO_DIZIN . '/' . $dosya);
    }
}

function nw_foto_adresi(string $dosya): string
{
    return preg_match('/^[0-9a-f]{24}\.webp$/', $dosya) ? NW_FOTO_YOL . '/' . $dosya : '';
}

/** Uyenin son 24 saatte yukledigi fotograf sayisi. */
function nw_foto_gunluk(int $uyeId): int
{
    $ifade = db()->prepare('SELECT COUNT(*) FROM gonderi_fotolari WHERE uye_id = :u AND olusturuldu > NOW() - INTERVAL 1 DAY');
    $ifade->execute(['u' => $uyeId]);

    return (int) $ifade->fetchColumn();
}

/**
 * Formdan gelen fotograf listesi (JSON dizi, data: adresleri). Sinirlari
 * uygular, hicbiri kaydedilmeden once hepsini sinar.
 *
 * @return list<string>
 */
function nw_foto_listesi(string $json, int $uyeId): array
{
    if (trim($json) === '') {
        return [];
    }

    $liste = json_decode($json, true);

    if (!is_array($liste)) {
        throw new InvalidArgumentException('Fotoğraflar okunamadı.');
    }

    $liste = array_values(array_filter($liste, 'is_string'));

    if (count($liste) > NW_FOTO_PAYLASIM_EN_FAZLA) {
        throw new InvalidArgumentException('Bir paylaşıma en fazla ' . NW_FOTO_PAYLASIM_EN_FAZLA . ' fotoğraf ekleyebilirsiniz.');
    }

    if ($liste !== [] && nw_foto_gunluk($uyeId) + count($liste) > NW_FOTO_GUNLUK_EN_FAZLA) {
        throw new InvalidArgumentException('Günlük fotoğraf sınırına ulaştınız (24 saatte ' . NW_FOTO_GUNLUK_EN_FAZLA . ' fotoğraf).');
    }

    return $liste;
}

/**
 * Video BAGLANTISI -> gomme adresi (haber_video_adresi'nin kabul ettigi
 * bicim). YouTube (watch, youtu.be, shorts), Vimeo, Dailymotion. Taninmayan
 * baglantida InvalidArgumentException; bos baglanti bos doner.
 */
function nw_video_gomme(string $baglanti): string
{
    $baglanti = trim($baglanti);

    if ($baglanti === '') {
        return '';
    }

    if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $baglanti, $e)) {
        return 'https://www.youtube-nocookie.com/embed/' . $e[1];
    }

    if (preg_match('#^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d{6,12})#', $baglanti, $e)
        || preg_match('#^https?://player\.vimeo\.com/video/(\d{6,12})#', $baglanti, $e)) {
        return 'https://player.vimeo.com/video/' . $e[1];
    }

    if (preg_match('#^https?://(?:www\.)?(?:dailymotion\.com/(?:embed/)?video/|dai\.ly/)([A-Za-z0-9]{5,12})#', $baglanti, $e)) {
        return 'https://www.dailymotion.com/embed/video/' . $e[1];
    }

    throw new InvalidArgumentException('Yalnızca YouTube, Vimeo ya da Dailymotion video bağlantısı paylaşabilirsiniz.');
}
