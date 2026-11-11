<?php
declare(strict_types=1);

/**
 * Günlük analiz masaları (eski adıyla köşe yazarları).
 *
 * Site sahibinin karari (11.11.2026): "yazarlari kaldiralim; bunun yerine
 * ekonomi, spor, dunya gundemi ile ilgili gunluk yazilar yazdiralim."
 * 23.10.2026'dan beri on iki yapay zeka yazar karakteri vardi (adlari ve
 * gercekci fotograflariyla); Google Haberler ve Discover gercek yazar
 * bilgisi istiyor, uydurma kisi imzasi guven kaybettiriyordu. Artik kisi
 * yok: uc masa, her gun kendi alaninda bir analiz; imza Valentra.
 *
 * Kod ve veritabani eski adlari kullanmaya devam ediyor (kose_yazilari
 * tablosu, "yazar" sutunu, /yazar/<anahtar> adresi): sutunda artik masa
 * anahtari duruyor. Eski yazilar KOSE_ESKI_YAZARLAR ile masalara
 * baglaniyor; eski yazar adresleri masanin sayfasina kalici yonleniyor.
 *
 * kategoriler: masanin konusunu sectigi haber basliklari.
 * spor: spor sayfasindaki analiz bolumunde gorunur.
 * arsiv: ajan bu masaya yeni yazi yazmaz (yalnizca eski yazilar).
 * uslup: ajanin yazi istemine eklenen tarif.
 */
const KOSE_YAZARLARI = [
    'ekonomi' => [
        'ad'          => 'Ekonomi Analizi',
        'alan'        => 'Ekonomi ve piyasalar',
        'kategoriler' => ['ekonomi'],
        'tanitim'     => 'Faiz, enflasyon, kur, borsa, vergi ve düzenlemeler: günün ekonomi gündeminin cebimize ve işimize etkisi, her gün tek yazıda.',
        'uslup'       => 'Veriye dayalı, sade ve öğretici bir analiz. Rakamları ve kavramları okurun gündelik hayatından örneklerle açıklar; '
                       . '"bu kararın market fiyatına, krediye, maaşa, faturaya etkisi ne?" sorusunu hep sorar. Kurum adına yazılır: '
                       . 'birinci tekil şahıs yok. Yatırım tavsiyesi vermez.',
        'portre'      => null,
    ],
    'spor' => [
        'ad'          => 'Spor Analizi',
        'alan'        => 'Spor gündemi',
        'kategoriler' => ['spor'],
        'spor'        => true,
        'tanitim'     => 'Günün spor gündemi: maçlar, kadro tercihleri, transferler ve kulüplerdeki gelişmeler, her gün tek yazıda.',
        'uslup'       => 'Oyunu ve rakamları öne çıkaran sakin bir analiz. Maçı oyun planı, kadro tercihleri ve performans üzerinden '
                       . 'değerlendirir; hiçbir kulübün tarafını tutmaz, hakemlere ve taraftarlara saygılıdır, kışkırtıcı dil kullanmaz. '
                       . 'Kurum adına yazılır: birinci tekil şahıs yok.',
        'portre'      => null,
    ],
    'dunya' => [
        'ad'          => 'Dünya Gündemi',
        'alan'        => 'Dünya ve dış politika',
        'kategoriler' => ['dunya'],
        'tanitim'     => 'Dünyadaki günün en önemli gelişmesi: arka planı, bölgesel dengeler ve Türkiye\'ye olası yansımaları, her gün tek yazıda.',
        'uslup'       => 'Geniş perspektifli ve bağlam kuran bir analiz. Gelişmeyi bölgesel ve küresel dengeler içinde okur, '
                       . 'Türkiye\'ye olası yansımalarını ayrıca tartışır; kesin hüküm yerine senaryolar sunar. Taraf tutmaz. '
                       . 'Kurum adına yazılır: birinci tekil şahıs yok.',
        'portre'      => null,
    ],
    'valentra' => [
        'ad'          => 'Valentra Analiz',
        'alan'        => 'Arşiv',
        'kategoriler' => [],
        'arsiv'       => true,
        'tanitim'     => 'Valentra\'nın gündem, politika, toplum ve kültür üzerine daha önce yayımlanmış değerlendirmeleri.',
        'uslup'       => 'Kurumsal, sakin ve çözümleyici: ne oldu, neden önemli, arka planı ne, bundan sonra ne olabilir.',
        'portre'      => null,
    ],
];

/**
 * Kaldırılan yazar karakterleri => yazılarının bağlandığı masa.
 * Eski yazılar silinmiyor; adresleri ve içerikleri aynı, imza masanın adı.
 */
const KOSE_ESKI_YAZARLAR = [
    'ahmet-sami-gumus'       => 'ekonomi',
    'sevket-gurkan-cokyazar' => 'dunya',
    'hasan-yavuz-osmanoglu'  => 'spor',
    'baris-can-yildirimer'   => 'spor',
    'cem-aslan-karakaya'     => 'spor',
    'murat-kaan-sarigul'     => 'spor',
    'kartal-emre-aksoy'      => 'spor',
    'hakan-omer-karadenizli' => 'spor',
    'tekin-alp-sagduyulu'    => 'valentra',
    'gamze-ilkmen-korukoylu' => 'valentra',
    'sirin-ebru-berrakgol'   => 'valentra',
];

/** Kayıttaki "yazar" değerinin bugünkü masa anahtarı (bilinmiyorsa varsayılan). */
function yazar_anahtar_coz(string $anahtar): string
{
    $anahtar = KOSE_ESKI_YAZARLAR[$anahtar] ?? $anahtar;

    return isset(KOSE_YAZARLARI[$anahtar]) ? $anahtar : KOSE_VARSAYILAN_YAZAR;
}

/**
 * Masanın veritabanındaki "yazar" değerleri: kendi anahtarı ve ona
 * bağlanan eski yazar anahtarları.
 *
 * @return list<string>
 */
function yazar_kayit_anahtarlari(string $anahtar): array
{
    return array_merge([$anahtar], array_keys(KOSE_ESKI_YAZARLAR, $anahtar, true));
}

/** Yazar fotoğraflarının klasörü (site kökünden). */
const YAZAR_FOTO_YOLU = '/gorseller/yazar';

/** Site sahibinin verdigi fotograflar (depoda); ajanin urettiginden once gelir. */
const YAZAR_SECILI_FOTO_YOLU = '/assets/yazarlar';

/** Yazarın yüklenmiş fotoğrafı (site içi yol); yoksa null. */
function yazar_foto(string $anahtar): ?string
{
    if (!preg_match('/^[a-z0-9-]+$/', $anahtar)) {
        return null;
    }

    // Once site sahibinin sectigi fotograf (depoda, deploy ile gelir),
    // yoksa ajanin urettigi (sunucuda).
    foreach ([YAZAR_SECILI_FOTO_YOLU, YAZAR_FOTO_YOLU] as $yol) {
        $dosya = dirname(__DIR__) . $yol . '/' . $anahtar . '.jpg';

        // Surum: dosya yenilenince tarayici eskisini gostermesin.
        if (is_file($dosya)) {
            return $yol . '/' . $anahtar . '.jpg?s=' . filemtime($dosya);
        }
    }

    return null;
}

/**
 * Yazar fotoğrafının küçük kopyası (kare WebP); üretilemezse asıl fotoğraf.
 *
 * 08.11.2026, Lighthouse "gorselleri dogru boyutlandirin": ana sayfadaki
 * Yazarlarimiz kutusunda 52 piksel gorunen portreler 480x480 JPEG
 * (her biri ~40 KB) olarak iniyordu. Kopya asil dosyanin yaninda bir kez
 * uretiliyor; klasor yazilamazsa ya da GD yoksa asil dosya.
 */
function yazar_foto_kucuk(string $anahtar, int $boy): ?string
{
    $asil = yazar_foto($anahtar);

    if ($asil === null) {
        return null;
    }

    $yol   = (string) strtok($asil, '?');
    $kopya = preg_replace('/\.jpg$/', '-' . $boy . '.webp', $yol);
    $kok   = dirname(__DIR__);

    if (!is_string($kopya) || $kopya === $yol) {
        return $asil;
    }

    $asilDosya  = $kok . $yol;
    $kopyaDosya = $kok . $kopya;

    $var = is_file($kopyaDosya);

    // Asil foto yenilendiyse kopya da yenileniyor; yazilamiyorsa (deploy
    // sonrasi dosya tarihleri karisik olabilir) mevcut kopya kullaniliyor.
    if (!$var || (filemtime($kopyaDosya) < filemtime($asilDosya) && is_writable(dirname($kopyaDosya)))) {
        if (!function_exists('imagewebp') || !is_writable(dirname($kopyaDosya))) {
            return $var ? $kopya . '?s=' . filemtime($kopyaDosya) : $asil;
        }

        $kaynak = @imagecreatefromjpeg($asilDosya);

        if ($kaynak === false) {
            return $asil;
        }

        $g = imagesx($kaynak);
        $y = imagesy($kaynak);
        $k = min($g, $y);
        $hedef = imagecreatetruecolor($boy, $boy);
        imagecopyresampled($hedef, $kaynak, 0, 0, intdiv($g - $k, 2), intdiv($y - $k, 2), $boy, $boy, $k, $k);
        $tamam = @imagewebp($hedef, $kopyaDosya, 80);
        imagedestroy($kaynak);
        imagedestroy($hedef);

        if (!$tamam) {
            return $asil;
        }
    }

    return $kopya . '?s=' . filemtime($kopyaDosya);
}

/** Varsayılan yazar (eski yazılar, bilinmeyen anahtar). */
const KOSE_VARSAYILAN_YAZAR = 'valentra';

/** @return array<string,mixed>|null */
function yazar_bul(string $anahtar): ?array
{
    return isset(KOSE_YAZARLARI[$anahtar]) ? KOSE_YAZARLARI[$anahtar] + ['anahtar' => $anahtar] : null;
}

/** Yazının yazarı; bilinmiyorsa Valentra Yorumu. */
function yazar_yazinin(array $yazi): array
{
    return yazar_bul(yazar_anahtar_coz((string) ($yazi['yazar'] ?? '')));
}

function yazar_yolu(string $anahtar, int $sayfa = 1): string
{
    $yol = temiz_adres_acik()
        ? '/yazar/' . rawurlencode($anahtar)
        : '/yazar.php?y=' . rawurlencode($anahtar);

    return $sayfa > 1 ? $yol . (str_contains($yol, '?') ? '&' : '?') . 'sayfa=' . $sayfa : $yol;
}

/**
 * Yazarın portresi: fotoğrafı varsa o (<img>), yoksa çizim (SVG, satır içi).
 *
 * Fotograf degil, duz renkli stilize bir bust: zemin dairesi, giysi,
 * boyun, bas, sac, goz, kas, gulumseme; yazara gore gozluk ve sakal.
 * Degerler yazarin tanimindan (KOSE_YAZARLARI[...]['portre']); rastgele
 * degil, her yuklemede ayni. Valentra Yorumu icin sitenin logosu.
 */
function yazar_portre_svg(string $anahtar, int $boy = 64): string
{
    $yazar = yazar_bul($anahtar);
    $p     = $yazar['portre'] ?? null;
    // Kucuk gosterimde (kutular, kunye) ekranin iki kati piksellik kopya;
    // buyuk portrede (yazar sayfasi) asil fotograf.
    $foto  = $yazar === null ? null : ($boy <= 80 ? yazar_foto_kucuk($anahtar, $boy <= 48 ? 96 : 160) : yazar_foto($anahtar));

    if ($foto !== null) {
        return '<img class="yazar-portre foto" src="' . e($foto) . '" alt="' . e((string) $yazar['ad']) . '" width="'
            . $boy . '" height="' . $boy . '" loading="lazy">';
    }

    if (!is_array($p)) {
        return '<img class="yazar-portre logo" src="/assets/logo.svg" alt="" width="' . $boy . '" height="' . $boy . '">';
    }

    $r = static fn (string $k): string => e((string) $p[$k]);

    // Sac: arka katman (uzun sacta omuza dusen kisim) bastan ONCE cizilir.
    $sacArka = $p['sac_bicim'] === 'uzun'
        ? '<path d="M30 46C28 24 40 17 50 17C62 17 72 24 70 46L73 74C66 73 62 68 62 61L62 42 38 42 38 61C38 68 34 73 27 74Z" fill="' . $r('sac') . '"/>'
        : '';

    $sacOn = match ($p['sac_bicim']) {
        'yan'   => '<path d="M33 45C32 30 40 23 50 23C60 23 68 30 67 45C66 39 64 36 62 35L62 31C57 28 43 28 38 31L38 35C36 36 34 39 33 45Z" fill="' . $r('sac') . '"/>',
        'uzun'  => '<path d="M33 44C32 27 41 20 51 20C61 20 69 27 67 44C63 34 57 30 49 30C43 33 38 37 33 44Z" fill="' . $r('sac') . '"/>',
        'topuz' => '<circle cx="50" cy="19" r="8" fill="' . $r('sac') . '"/>'
                 . '<path d="M33 43C32 28 41 22 50 22C60 22 68 28 67 43C63 33 57 29 50 29C43 29 37 33 33 43Z" fill="' . $r('sac') . '"/>',
        default => '<path d="M33 42C32 26 42 20 51 20C61 20 69 27 67 42C64 34 58 30 50 30C42 30 36 34 33 42Z" fill="' . $r('sac') . '"/>',
    };

    $sakal = $p['sakal']
        ? '<path d="M34 46C34 60 41 66 50 66C59 66 66 60 66 46C63 54 58 57 50 57C42 57 37 54 34 46Z" fill="' . $r('sac') . '" opacity=".85"/>'
        : '';

    $gozluk = $p['gozluk']
        ? '<g fill="none" stroke="#1d232b" stroke-width="1.6"><circle cx="43" cy="45" r="5"/><circle cx="57" cy="45" r="5"/><path d="M48 45H52"/></g>'
        : '';

    return '<svg class="yazar-portre" viewBox="0 0 100 100" width="' . $boy . '" height="' . $boy . '" role="img" aria-label="'
        . e((string) $yazar['ad']) . '">'
        . '<circle cx="50" cy="50" r="50" fill="' . $r('zemin') . '"/>'
        . '<clipPath id="yp-' . e($anahtar) . '-' . $boy . '"><circle cx="50" cy="50" r="50"/></clipPath>'
        . '<g clip-path="url(#yp-' . e($anahtar) . '-' . $boy . ')">'
        . $sacArka
        . '<path d="M14 100C17 79 33 71 50 71C67 71 83 79 86 100Z" fill="' . $r('giysi') . '"/>'
        . '<path d="M42 72L50 85 58 72Z" fill="' . $r('gomlek') . '"/>'
        . '<rect x="44" y="57" width="12" height="16" rx="4" fill="' . $r('ten') . '"/>'
        . '<ellipse cx="50" cy="45" rx="17" ry="20" fill="' . $r('ten') . '"/>'
        . $sakal
        . $sacOn
        . '<g fill="#1d232b"><circle cx="43" cy="45" r="1.7"/><circle cx="57" cy="45" r="1.7"/></g>'
        . '<path d="M39 39.5Q43 37.5 47 39.5M53 39.5Q57 37.5 61 39.5" fill="none" stroke="#1d232b" stroke-width="1.3" stroke-linecap="round" opacity=".7"/>'
        . '<path d="M44 54Q50 58 56 54" fill="none" stroke="#7a3b2e" stroke-width="1.6" stroke-linecap="round"/>'
        . $gozluk
        . '</g></svg>';
}
