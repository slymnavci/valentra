<?php
declare(strict_types=1);

/**
 * Kalıcı konu sayfaları (03.11.2026; bkz. includes/konu.php).
 *
 *   konu.php?k=asgari-ucret   bir dosya
 *   konu.php                  butun dosyalar
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

sayfa_onbellek(['k']);

require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/konu.php';

$slug = (string) ($_GET['k'] ?? '');
$tr   = new DateTimeZone('Europe/Istanbul');
$ay   = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$tarihYaz = static function (string $t) use ($ay): string {
    $z = strtotime($t);

    return $z ? (int) date('j', $z) . ' ' . $ay[(int) date('n', $z)] . ' ' . date('Y', $z) : '';
};

/* ---------- Butun dosyalar ---------- */
if ($slug === '') {
    $ozetler       = konu_ozetleri();
    $sayfaBasligi  = 'Gündem Dosyaları: Asgari Ücret, Emekli Zammı, Memur Maaşı, Faiz — Valentra';
    $sayfaAciklama = 'Asgari ücret, emekli zammı, memur maaşı, faiz kararı, enflasyon, akaryakıt ve kira artışı: '
        . 'her konudaki bütün gelişmeler tek sayfada, sürekli güncel.';
    $seoAdres      = site_adresi() . konu_yolu();

    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="kategori-basligi">
        <h1>Gündem Dosyaları</h1>
        <p>Her ay yeniden gündeme gelen konular: bütün gelişmeler tek sayfada, yeni haber geldikçe güncelleniyor.</p>
    </div>

    <div class="ana-duzen tam">
        <div class="ana-kolon">
            <ul class="konu-liste">
                <?php foreach (KONULAR as $s => $k): ?>
                    <li>
                        <a href="<?= e(konu_yolu($s)) ?>"><?= e(konu_basligi($s)) ?></a>
                        <span><?= (int) $ozetler[$s]['sayi'] ?> gelişme<?= $ozetler[$s]['son'] !== '' ? ' · son: ' . e($tarihYaz($ozetler[$s]['son'])) : '' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

/* ---------- Tek dosya ---------- */
if (!isset(KONULAR[$slug])) {
    http_response_code(404);
    $sayfaBasligi = 'Dosya bulunamadı — Valentra';
    $seoRobots    = 'noindex, follow';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Bu dosya bulunamadı.</strong>
        <p><a href="<?= e(konu_yolu()) ?>" style="color:var(--vurgu);text-decoration:underline;">Bütün dosyalar</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$konu     = KONULAR[$slug];
$baslik   = konu_basligi($slug);
$haberler = konu_haberleri($slug);
$son      = $haberler[0] ?? null;

$sayfaBasligi  = $baslik . ': Son Durum ve Bütün Gelişmeler — Valentra';
$sayfaAciklama = $son !== null
    ? mb_substr($baslik . ' son durum: ' . (string) $son['ozet'], 0, 300, 'UTF-8')
    : $baslik . ' ile ilgili bütün gelişmeler tek sayfada.';
$seoAdres      = site_adresi() . konu_yolu($slug);

if (count($haberler) < KONU_DIZIN_ASGARI) {
    $seoRobots = 'noindex, follow';
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1><?= e($baslik) ?>: Son Durum</h1>
    <p><?= e($konu['giris']) ?></p>
    <?php if ($son !== null): ?>
        <p class="konu-guncel">Son güncelleme: <?= e($tarihYaz((string) $son['yayin_tarihi'])) ?> · <?= count($haberler) ?> gelişme</p>
    <?php endif; ?>
</div>

<div class="ana-duzen tam">
    <div class="ana-kolon">
        <?php if ($son === null): ?>
            <div class="bos-durum">
                <strong>Bu konuda henüz haber yok.</strong>
                Yeni gelişmeler yayımlandıkça burada görünecek.
            </div>
        <?php else: ?>
            <section class="konu-son">
                <h2>Son gelişme</h2>
                <a class="konu-son-baslik" href="<?= e(haber_yolu((string) $son['slug'])) ?>"><?= e((string) $son['baslik']) ?></a>
                <time><?= e($tarihYaz((string) $son['yayin_tarihi'])) ?></time>
                <p><?= e((string) $son['ozet']) ?></p>
                <?php if (trim((string) $son['neden_onemli']) !== ''): ?>
                    <p class="konu-neden"><strong>Neden önemli?</strong> <?= e((string) $son['neden_onemli']) ?></p>
                <?php endif; ?>
            </section>

            <?php if (count($haberler) > 1): ?>
                <section class="konu-zaman">
                    <h2>Bütün gelişmeler</h2>
                    <ol>
                        <?php foreach (array_slice($haberler, 1) as $h): ?>
                            <li>
                                <time><?= e($tarihYaz((string) $h['yayin_tarihi'])) ?></time>
                                <a href="<?= e(haber_yolu((string) $h['slug'])) ?>"><?= e((string) $h['baslik']) ?></a>
                                <p><?= e((string) $h['ozet']) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <nav class="konu-diger" aria-label="Diğer dosyalar">
            <h2>Diğer dosyalar</h2>
            <?php foreach (array_keys(KONULAR) as $s): ?>
                <?php if ($s !== $slug): ?>
                    <a href="<?= e(konu_yolu($s)) ?>"><?= e(konu_basligi($s)) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
