<?php
declare(strict_types=1);

/**
 * RSS beslemesi: /rss (butun haberler) ve /rss/<kategori> (tek kategori).
 *
 * 12.11.2026, site sahibi: "Google gorunurlugunu artiralim; icerige
 * dokunmadan". Sitede besleme yoktu. Google Haberler Yayinci Merkezi,
 * Discover'daki "Takip et" dugmesi (sayfa basindaki rel="alternate"
 * baglantisindan bulur; bkz. includes/sayfa_ust.php) ve haber
 * toplayicilar yeni haberi beslemeden ogreniyor.
 *
 * Icerik: son 50 haberin basligi, OZETI (sayfadaki spot), kategorisi,
 * yayin zamani ve gorseli. Haber metninin tamami konmuyor: besleme
 * haberi tanitir, okur haberi sitede okur.
 *
 * Kapsam haber haritasiyla ayni (yayinda + dizine acik haberler); dizine
 * kapali haber beslemeyle de dagitilmiyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';

const RSS_ADET = 50;

$taban    = site_adresi();
$slug     = trim((string) ($_GET['k'] ?? ''));
$kategori = null;

if ($slug !== '') {
    $kategori = preg_match('/^[a-z0-9-]{1,140}$/', $slug) === 1 ? kategori_slug_bul($slug) : null;

    if ($kategori === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        exit('Besleme bulunamadı.');
    }
}

$sorgu = db()->prepare(
    'SELECT h.id, h.slug, h.baslik, h.ozet, h.gorsel_url, h.yayin_tarihi,
            k.ad AS kategori_adi, k.slug AS kategori_slug
       FROM haberler h
       LEFT JOIN kategoriler k ON k.id = h.kategori_id
      WHERE h.durum = :durum
        AND ' . haber_dizinlenir_kosulu('h') . '
        ' . ($kategori !== null ? 'AND h.kategori_id = :kategori' : '') . '
      ORDER BY h.yayin_tarihi DESC
      LIMIT ' . RSS_ADET
);
$sorgu->bindValue('durum', HABER_YAYINDA);

if ($kategori !== null) {
    $sorgu->bindValue('kategori', (int) $kategori['id'], PDO::PARAM_INT);
}

$sorgu->execute();
$haberler = $sorgu->fetchAll();

$kendiAdresi = $taban . rss_yolu($kategori !== null ? (string) $kategori['slug'] : '');
$sayfaAdresi = $kategori !== null ? $taban . kategori_yolu((string) $kategori['slug']) : $taban . '/';
$baslik      = $kategori !== null ? 'Valentra — ' . (string) $kategori['ad'] : 'Valentra — Güncel Haberler';
$aciklama    = $kategori !== null
    ? 'Valentra ' . (string) $kategori['ad'] . ' haberleri.'
    : 'Türkiye ve dünyadan güncel haberlerin özetleri.';
$sonZaman    = $haberler !== [] ? (int) strtotime((string) $haberler[0]['yayin_tarihi']) : time();

/** XML metni: e() tek tirnagi &#039; yapiyor, XML'de de gecerli. */
$x = static fn (string $metin): string => e($metin);

/** Gorselin turu (uzantidan); bilinmiyorsa JPEG. */
$tur = static function (string $adres): string {
    return match (strtolower(pathinfo((string) parse_url($adres, PHP_URL_PATH), PATHINFO_EXTENSION))) {
        'webp'  => 'image/webp',
        'png'   => 'image/png',
        'gif'   => 'image/gif',
        'avif'  => 'image/avif',
        default => 'image/jpeg',
    };
};

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
// Beslemenin kendisi arama sonucunda cikmasin; icindeki baglantilar izlensin.
header('X-Robots-Tag: noindex, follow');

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
<channel>
    <title><?= $x($baslik) ?></title>
    <link><?= $x($sayfaAdresi) ?></link>
    <description><?= $x($aciklama) ?></description>
    <language>tr</language>
    <lastBuildDate><?= $x(date(DATE_RSS, $sonZaman)) ?></lastBuildDate>
    <ttl>5</ttl>
    <atom:link href="<?= $x($kendiAdresi) ?>" rel="self" type="application/rss+xml"/>
    <image>
        <url><?= $x($taban . '/assets/logo-512.png') ?></url>
        <title><?= $x($baslik) ?></title>
        <link><?= $x($sayfaAdresi) ?></link>
    </image>
<?php foreach ($haberler as $haber): ?>
<?php
    $adres  = $taban . haber_yolu((string) $haber['slug']);
    $gorsel = seo_haber_gorsel_adresi($haber);
?>
    <item>
        <title><?= $x((string) $haber['baslik']) ?></title>
        <link><?= $x($adres) ?></link>
        <guid isPermaLink="true"><?= $x($adres) ?></guid>
        <pubDate><?= $x(date(DATE_RSS, (int) strtotime((string) $haber['yayin_tarihi']))) ?></pubDate>
<?php if (trim((string) $haber['ozet']) !== ''): ?>
        <description><?= $x((string) $haber['ozet']) ?></description>
<?php endif; ?>
<?php if (!empty($haber['kategori_adi'])): ?>
        <category><?= $x((string) $haber['kategori_adi']) ?></category>
<?php endif; ?>
<?php if ($gorsel !== ''): ?>
        <media:content url="<?= $x($gorsel) ?>" medium="image" type="<?= $x($tur($gorsel)) ?>"/>
<?php endif; ?>
    </item>
<?php endforeach; ?>
</channel>
</rss>
