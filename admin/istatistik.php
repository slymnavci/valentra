<?php
declare(strict_types=1);

/**
 * Ziyaretçi raporu.
 *
 * Site sahibinin istegi (23.10.2026): "hangi haberlere en cok
 * bakiliyorsa onlari one cikaracagim; biraz istatistik tutmam lazim,
 * cok iyi bir raporlama araci yapalim".
 *
 * Gosterge paneli (29.10.2026, site sahibi: "daha detayli bir raporlama
 * alani; grafikler: kullanici nereden geldi, son bir haftalik trendler,
 * Google'dan gelen okuyucu, ortalama tiklama, haberlerin okunma sayisi
 * ve adedi; diger bilgiler altta kalsin"). Ustte ozet kutulari ve
 * grafikler; eski bolumlerin hepsi altta acilir "Ayrintili raporlar".
 *
 * Grafik icin bir kutuphane YOK: grafikleri assets/rapor-grafik.js
 * satir ici SVG olarak ciziyor (veri sayfada JSON); her grafigin tablo
 * gorunumu de var. Disaridan betik cekmek paylasimli hostingde gereksiz
 * bir bagimlilik olurdu.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ziyaret_rapor.php';

giris_zorunlu();

/* --- "Bu cihazi sayma" --------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $ac = ($_POST['islem'] ?? '') === 'sayma_ac';
    setcookie(ZIYARET_SAYMA_CEREZI, $ac ? '1' : '', [
        'expires'  => $ac ? time() + 5 * 365 * 86400 : time() - 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    yonlendir('istatistik.php?bildirim=' . ($ac ? 'sayma_acik' : 'sayma_kapali') . '#dogruluk');
}

/* --- Secimler -------------------------------------------------------- */

$a     = zr_aralik($_GET);
$ham   = ($_GET['ham'] ?? '') === '1';
$sira  = (string) ($_GET['sira'] ?? 'okuyucu');
$sira  = isset(zr_haber_siralari()[$sira]) ? $sira : 'okuyucu';
$hepsi = ($_GET['hepsi'] ?? '') === '1';

/** Bu sayfanın sorgu parametreleri; bağlantılar seçimi korusun. */
$temel = ['aralik' => $a['anahtar']];

if ($a['anahtar'] === 'ozel') {
    $temel['bas'] = $a['bas'];
    $temel['bit'] = $a['bit'];
}

if ($ham) {
    $temel['ham'] = '1';
}

$adres = static function (array $degisen = []) use ($temel, $sira): string {
    $p = array_merge($temel, $sira !== 'okuyucu' ? ['sira' => $sira] : [], $degisen);

    return 'istatistik.php?' . http_build_query(array_filter($p, static fn ($d) => $d !== null && $d !== ''));
};

/* --- CSV ------------------------------------------------------------- */

$csv = (string) ($_GET['csv'] ?? '');

if ($csv !== '') {
    $dosya = 'valentra-' . $csv . '-' . $a['bas'] . '_' . $a['bit'] . '.csv';

    match ($csv) {
        'haberler' => zr_csv_gonder($dosya,
            ['Haber', 'Başlık (kategori)', 'Yayın', 'Okuyucu', 'Görüntülenme', 'Ort. okuma (sn)', 'Sonuna kadar okunma %', 'Aramadan', 'Sosyalden'],
            array_map(static fn (array $r): array => [
                $r['baslik'], $r['kategori'], $r['yayin_tarihi'], $r['okuyucu'], $r['goruntulenme'],
                $r['ort_sure'] !== null ? round((float) $r['ort_sure']) : '',
                $r['okuma_orani'] !== null ? round((float) $r['okuma_orani']) : '',
                $r['arama'], $r['sosyal'],
            ], zr_haberler($a, $ham, $sira, 500))),
        'gunluk' => zr_csv_gonder($dosya, ['Dönem', 'Ziyaretçi', 'Görüntülenme'],
            array_map(static fn (array $r): array => [$r['ipucu'], $r['ziyaretci'], $r['goruntulenme']], zr_seri($a, $ham))),
        'sayfalar' => zr_csv_gonder($dosya, ['Adres', 'Başlık', 'Görüntülenme', 'Ziyaretçi'],
            array_map(static fn (array $r): array => [$r['yol'], $r['baslik'], $r['goruntulenme'], $r['ziyaretci']], zr_sayfalar($a, $ham, 200))),
        'kaynaklar' => zr_csv_gonder($dosya, ['Site', 'Kanal', 'Ziyaretçi', 'Giriş'],
            array_map(static fn (array $r): array => [$r['kaynak'], zr_kanal_adlari()[$r['kanal']] ?? $r['kanal'], $r['ziyaretci'], $r['giris']], zr_yonlendirenler($a, $ham, 100))),
        'aramalar' => zr_csv_gonder($dosya, ['Aranan', 'Arama', 'Ziyaretçi'],
            array_map(static fn (array $r): array => [$r['sorgu'], $r['arama'], $r['ziyaretci']], zr_aramalar($a, $ham, 500))),
        default => null,
    };
}

/* --- Veri ------------------------------------------------------------ */

$olcu      = zr_olculer($a['bas'], $a['bit'], $ham);
$onceki    = zr_olculer($a['onceki_bas'], $a['onceki_bit'], $ham);
$simdi     = zr_simdi();
$simdiHab  = zr_simdi_okunanlar(5);
$seri      = zr_seri($a, $ham);
$haberler  = zr_haberler($a, $ham, $sira, $hepsi ? 300 : 30);
$kategori  = zr_kategoriler($a, $ham);
$kanallar  = zr_kanallar($a, $ham);
$siteler   = zr_yonlendirenler($a, $ham, 15);
$girisler  = zr_giris_sayfalari($a, $ham, 15);
$cihazlar  = zr_cihazlar($a, $ham);
$isi       = zr_saat_gun($a, $ham);
$aramalar  = zr_aramalar($a, $ham, 20);
$kampanya  = zr_kampanyalar($a, $ham, 10);
$sayfalar  = zr_sayfalar($a, $ham, 20);
$dogruluk  = zr_dogruluk();

// Gosterge paneli (29.10.2026)
$gsTrend    = zr_trend_araligi($a);
$gsSeri     = zr_gosterge_serisi($gsTrend, $ham);
$gsOzet     = zr_gosterge_ozet($a['bas'], $a['bit'], $ham);
$gsOnceki   = zr_gosterge_ozet($a['onceki_bas'], $a['onceki_bit'], $ham);
$gsPaylar   = zr_kaynak_paylari($a, $ham);
$gsHaberler = array_slice($sira === 'okuyucu' ? $haberler : zr_haberler($a, $ham, 'okuyucu', 10), 0, 10);
$saymaAcik = ($_COOKIE[ZIYARET_SAYMA_CEREZI] ?? '') === '1';

$kisi     = trim((string) ($_GET['kisi'] ?? ''));
$gezinti  = $kisi !== '' ? zr_gezinti($kisi) : [];
$kisiler  = zr_ziyaretciler($a, $ham, 100);

$bildirimler = [
    'one_cikti'    => 'Haber öne çıkarıldı: ana sayfada ve listelerde önce gelir.',
    'one_kalkti'   => 'Haber artık öne çıkan değil.',
    'sayma_acik'   => 'Bu tarayıcıdan yapılan ziyaretler artık sayılmayacak.',
    'sayma_kapali' => 'Bu tarayıcıdan yapılan ziyaretler yeniden sayılacak (panel oturumu açıkken yine sayılmaz).',
];
$bildirim = $bildirimler[(string) ($_GET['bildirim'] ?? '')] ?? '';

$sayi = static fn (int|float|null $n, int $ondalik = 0): string =>
    $n === null ? '—' : number_format((float) $n, $ondalik, ',', '.');

/** Değişim rozeti (önceki döneme göre). $ters: azalması iyi olan ölçü. */
$degisim = static function (float|int|null $simdi, float|int|null $once, bool $ters = false): string {
    $d = zr_degisim($simdi, $once);

    if ($d === null) {
        return '<span class="degisim">önceki dönemde veri yok</span>';
    }

    $iyi = $ters ? $d < 0 : $d > 0;
    $sinif = abs($d) < 0.5 ? '' : ($iyi ? ' degisim-iyi' : ' degisim-kotu');

    return '<span class="degisim' . $sinif . '">' . ($d > 0 ? '▲' : ($d < 0 ? '▼' : '•')) . ' %'
         . number_format(abs($d), 0, ',', '.') . ' <small>önceki döneme göre</small></span>';
};

$haberAdresi = static fn (?string $slug): string => $slug !== null && $slug !== '' ? haber_yolu($slug) : '';

$panelBasligi = 'Ziyaretçi raporu';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<div class="sayfa-basligi" style="margin-top:20px;">
    <h1>Ziyaretçi raporu</h1>
    <p class="ipucu">
        Kendi sayacımız — veri dışarıya gitmiyor. Botlar, bağlantı önizlemeleri, kendi ziyaretleriniz ve
        tarayıcıda hiç açılmayan sayfalar sayılmaz (ayrıntı en altta, “Sayım nasıl yapılıyor”).
    </p>
</div>

<!-- Aralik -->
<div class="kutu rapor-secim">
    <nav class="sekmeler" style="margin:0;">
        <?php foreach (zr_hazir_araliklar() as $anahtar => $ad): ?>
            <a href="<?= e('istatistik.php?' . http_build_query(['aralik' => $anahtar] + ($ham ? ['ham' => '1'] : []))) ?>"
               class="<?= $a['anahtar'] === $anahtar ? 'aktif' : '' ?>"><?= e($ad) ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="get" action="istatistik.php" class="rapor-ozel">
        <input type="hidden" name="aralik" value="ozel">
        <?php if ($ham): ?><input type="hidden" name="ham" value="1"><?php endif; ?>
        <input type="date" name="bas" value="<?= e($a['bas']) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="Başlangıç">
        <span>–</span>
        <input type="date" name="bit" value="<?= e($a['bit']) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="Bitiş">
        <button type="submit" class="dugme">Göster</button>
    </form>
    <div class="rapor-secim-alt">
        <span><strong><?= e($a['ad']) ?></strong>
            (<?= e(date('d.m.Y', strtotime($a['bas']))) ?><?= $a['bas'] !== $a['bit'] ? ' – ' . e(date('d.m.Y', strtotime($a['bit']))) : '' ?>);
            karşılaştırma: <?= e(date('d.m.Y', strtotime($a['onceki_bas']))) ?><?= $a['onceki_bas'] !== $a['onceki_bit'] ? ' – ' . e(date('d.m.Y', strtotime($a['onceki_bit']))) : '' ?></span>
        <?php if ($ham): ?>
            <a href="<?= e($adres(['ham' => null])) ?>" class="rozet rozet-reddedildi">Ham sayım gösteriliyor — doğrulanmış sayıma dön</a>
        <?php else: ?>
            <a href="<?= e($adres(['ham' => '1'])) ?>" class="ipucu">Ham sayımı göster (bot şüphelileri dahil)</a>
        <?php endif; ?>
    </div>
</div>

<!-- Gosterge paneli (29.10.2026): ozet kutulari ve grafikler; ayrintilar asagida -->
<?php
$gsKutular = [
    ['ad' => 'Ziyaretçi', 'alt' => 'tekil kişi', 'deger' => $sayi($olcu['ziyaretci']),
     'degisim' => $degisim($olcu['ziyaretci'], $onceki['ziyaretci']), 'seri' => array_column($gsSeri, 'ziyaretci')],
    ['ad' => 'Google’dan gelen', 'alt' => 'tekil okuyucu', 'deger' => $sayi($gsOzet['google']),
     'degisim' => $degisim($gsOzet['google'], $gsOnceki['google']), 'seri' => array_column($gsSeri, 'google')],
    ['ad' => 'Kişi başı sayfa', 'alt' => 'ortalama tıklama', 'deger' => $sayi($olcu['sayfa_kisi'], 1),
     'degisim' => $degisim($olcu['sayfa_kisi'], $onceki['sayfa_kisi']), 'seri' => array_column($gsSeri, 'sayfa_kisi')],
    ['ad' => 'Haber okunma', 'alt' => 'haber sayfası açılışı', 'deger' => $sayi($olcu['haber_goruntulenme']),
     'degisim' => $degisim($olcu['haber_goruntulenme'], $onceki['haber_goruntulenme']), 'seri' => array_column($gsSeri, 'haber_okunma')],
    ['ad' => 'Okunan haber', 'alt' => 'farklı haber adedi', 'deger' => $sayi($gsOzet['okunan_haber']),
     'degisim' => $degisim($gsOzet['okunan_haber'], $gsOnceki['okunan_haber']), 'seri' => array_column($gsSeri, 'okunan_haber')],
];

/** Kucuk egilim cizgisi: son nokta vurgulu. */
$kivilcim = static function (array $degerler): string {
    $n = count($degerler);

    if ($n < 2) {
        return '';
    }

    $enCok = max(1, ...array_map('floatval', $degerler));
    $nokta = [];

    foreach (array_values($degerler) as $i => $d) {
        $nokta[] = round($i / ($n - 1) * 96 + 2, 1) . ',' . round(26 - (float) $d / $enCok * 22, 1);
    }

    [$sx, $sy] = explode(',', end($nokta));

    return '<svg class="gs-kivilcim" viewBox="0 0 100 30" aria-hidden="true"><polyline points="' . implode(' ', $nokta)
         . '"/><circle cx="' . $sx . '" cy="' . $sy . '" r="3"/></svg>';
};

$gsEtiketler = array_column($gsSeri, 'etiket');
$gsIpuclari  = array_column($gsSeri, 'ipucu');
$gsGrafikler = [
    'kaynak' => [
        'tur' => 'yigin', 'etiketler' => $gsEtiketler, 'ipuclari' => $gsIpuclari, 'birim' => 'kişi',
        'seriler' => array_map(static fn (string $k, string $ad): array => [
            'ad' => $ad, 'renk' => 'var(--gs-' . $k . ')',
            'degerler' => array_map(static fn (array $g): int => $g['kaynaklar'][$k], $gsSeri),
        ], array_keys(zr_kaynak_gruplari()), zr_kaynak_gruplari()),
    ],
    'google' => [
        'tur' => 'cizgi', 'etiketler' => $gsEtiketler, 'ipuclari' => $gsIpuclari, 'birim' => 'kişi',
        'seriler' => [['ad' => 'Google’dan gelen', 'renk' => 'var(--gs-google)', 'degerler' => array_column($gsSeri, 'google')]],
    ],
    'ziyaret' => [
        'tur' => 'cizgi', 'etiketler' => $gsEtiketler, 'ipuclari' => $gsIpuclari, 'birim' => '',
        'seriler' => [
            ['ad' => 'Sayfa görüntülenme', 'renk' => 'var(--gs-1)', 'degerler' => array_column($gsSeri, 'goruntulenme')],
            ['ad' => 'Ziyaretçi', 'renk' => 'var(--gs-2)', 'degerler' => array_column($gsSeri, 'ziyaretci')],
        ],
    ],
    'tiklama' => [
        'tur' => 'cizgi', 'etiketler' => $gsEtiketler, 'ipuclari' => $gsIpuclari, 'birim' => 'sayfa', 'ondalik' => 1,
        'seriler' => [['ad' => 'Kişi başı sayfa', 'renk' => 'var(--gs-1)', 'degerler' => array_column($gsSeri, 'sayfa_kisi')]],
    ],
    'okunma' => [
        'tur' => 'sutun', 'etiketler' => $gsEtiketler, 'ipuclari' => $gsIpuclari, 'birim' => 'okunma',
        'seriler' => [['ad' => 'Haber okunma', 'renk' => 'var(--gs-1)', 'degerler' => array_column($gsSeri, 'haber_okunma')]],
    ],
    'adet' => [
        'tur' => 'sutun', 'etiketler' => $gsEtiketler, 'ipuclari' => $gsIpuclari, 'birim' => 'haber',
        'seriler' => [['ad' => 'Okunan farklı haber', 'renk' => 'var(--gs-1)', 'degerler' => array_column($gsSeri, 'okunan_haber')]],
    ],
];

/** Grafigin tablo gorunumu (renk tek basina anlam tasimasin; deger hep okunabilsin). */
$gsTablo = static function (array $g) use ($sayi): string {
    $h = '<details class="gs-tablo"><summary>Tablo olarak göster</summary><div class="tablo-kaydir"><table class="liste-tablo"><thead><tr><th>Gün</th>';

    foreach ($g['seriler'] as $s) {
        $h .= '<th class="sag">' . e($s['ad']) . '</th>';
    }

    $h .= '</tr></thead><tbody>';

    foreach ($g['ipuclari'] as $i => $ipucu) {
        $h .= '<tr><td>' . e($ipucu) . '</td>';

        foreach ($g['seriler'] as $s) {
            $h .= '<td class="sag">' . $sayi($s['degerler'][$i], (int) ($g['ondalik'] ?? 0)) . '</td>';
        }

        $h .= '</tr>';
    }

    return $h . '</tbody></table></div></details>';
};

$gsAralikNotu = $gsTrend['bas'] === $a['bas'] && $gsTrend['bit'] === $a['bit']
    ? e($a['ad'])
    : 'son 7 gün (' . e(date('d.m', strtotime($gsTrend['bas']))) . ' – ' . e(date('d.m', strtotime($gsTrend['bit']))) . ')';
$gsPayToplam = max(1, array_sum($gsPaylar));
$gsHaberEnCok = max(1, (int) ($gsHaberler[0]['okuyucu'] ?? 1));
?>

<section class="gs" aria-label="Gösterge paneli">
    <div class="gs-kutular">
        <div class="gs-kutu gs-kutu-simdi">
            <span class="gs-kutu-ad">Şu an sitede</span>
            <span class="gs-kutu-deger"><?= $sayi($simdi) ?></span>
            <span class="gs-kutu-alt">son <?= (int) ZIYARET_ONLINE_DAKIKA ?> dakika</span>
        </div>
        <?php foreach ($gsKutular as $k): ?>
            <div class="gs-kutu">
                <span class="gs-kutu-ad"><?= e($k['ad']) ?></span>
                <span class="gs-kutu-deger"><?= $k['deger'] ?></span>
                <span class="gs-kutu-alt"><?= e($k['alt']) ?></span>
                <?= $k['degisim'] ?>
                <?= $kivilcim($k['seri']) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="ipucu gs-not">Kutulardaki sayılar seçili aralığın (<?= e($a['ad']) ?>) toplamı; küçük çizgiler ve grafikler <?= $gsAralikNotu ?> gün gün.</p>

    <div class="gs-izgara">
        <div class="kutu gs-kart gs-genis">
            <div class="rapor-baslik"><h2>Okuyucular nereden geldi</h2><span class="ipucu">gün gün, tekil kişi</span></div>
            <ul class="gs-lejant">
                <?php foreach (zr_kaynak_gruplari() as $k => $ad): ?>
                    <li><span class="gs-anahtar" style="background:var(--gs-<?= e($k) ?>)"></span><?= e($ad) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="gs-grafik" data-grafik="<?= e((string) json_encode($gsGrafikler['kaynak'], JSON_UNESCAPED_UNICODE)) ?>"></div>
            <?= $gsTablo($gsGrafikler['kaynak']) ?>
        </div>

        <div class="kutu gs-kart">
            <div class="rapor-baslik"><h2>Google’dan gelen okuyucu</h2><span class="ipucu">gün gün</span></div>
            <div class="gs-grafik" data-grafik="<?= e((string) json_encode($gsGrafikler['google'], JSON_UNESCAPED_UNICODE)) ?>"></div>
            <?= $gsTablo($gsGrafikler['google']) ?>
        </div>

        <div class="kutu gs-kart">
            <div class="rapor-baslik"><h2>Kaynak payları</h2><span class="ipucu"><?= e($a['ad']) ?></span></div>
            <ul class="gs-cubuklar">
                <?php foreach (zr_kaynak_gruplari() as $k => $ad): ?>
                    <li>
                        <span class="gs-cubuk-ad"><span class="gs-anahtar" style="background:var(--gs-<?= e($k) ?>)"></span><?= e($ad) ?></span>
                        <span class="gs-cubuk-iz"><span class="gs-cubuk" style="width:<?= round($gsPaylar[$k] / $gsPayToplam * 100, 1) ?>%;background:var(--gs-<?= e($k) ?>)"></span></span>
                        <span class="gs-cubuk-deger"><?= $sayi($gsPaylar[$k]) ?> <small>%<?= $sayi($gsPaylar[$k] / $gsPayToplam * 100) ?></small></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="kutu gs-kart">
            <div class="rapor-baslik"><h2>Ziyaretçi ve görüntülenme</h2><a class="ipucu" href="<?= e($adres(['csv' => 'gunluk'])) ?>">CSV</a></div>
            <ul class="gs-lejant">
                <li><span class="gs-anahtar gs-anahtar-cizgi" style="background:var(--gs-1)"></span>Sayfa görüntülenme</li>
                <li><span class="gs-anahtar gs-anahtar-cizgi" style="background:var(--gs-2)"></span>Ziyaretçi</li>
            </ul>
            <div class="gs-grafik" data-grafik="<?= e((string) json_encode($gsGrafikler['ziyaret'], JSON_UNESCAPED_UNICODE)) ?>"></div>
            <?= $gsTablo($gsGrafikler['ziyaret']) ?>
        </div>

        <div class="kutu gs-kart">
            <div class="rapor-baslik"><h2>Kişi başı sayfa</h2><span class="ipucu">ortalama tıklama, gün gün</span></div>
            <div class="gs-grafik" data-grafik="<?= e((string) json_encode($gsGrafikler['tiklama'], JSON_UNESCAPED_UNICODE)) ?>"></div>
            <?= $gsTablo($gsGrafikler['tiklama']) ?>
        </div>

        <div class="kutu gs-kart">
            <div class="rapor-baslik"><h2>Haber okunma sayısı</h2><span class="ipucu">haber sayfası açılışı, gün gün</span></div>
            <div class="gs-grafik" data-grafik="<?= e((string) json_encode($gsGrafikler['okunma'], JSON_UNESCAPED_UNICODE)) ?>"></div>
            <?= $gsTablo($gsGrafikler['okunma']) ?>
        </div>

        <div class="kutu gs-kart">
            <div class="rapor-baslik"><h2>Okunan haber adedi</h2><span class="ipucu">en az bir kez okunan farklı haber, gün gün</span></div>
            <div class="gs-grafik" data-grafik="<?= e((string) json_encode($gsGrafikler['adet'], JSON_UNESCAPED_UNICODE)) ?>"></div>
            <?= $gsTablo($gsGrafikler['adet']) ?>
        </div>

        <div class="kutu gs-kart gs-genis">
            <div class="rapor-baslik"><h2>En çok okunan 10 haber</h2><a class="ipucu" href="#haberler">tam liste ve öne çıkarma ↓</a></div>
            <?php if ($gsHaberler === []): ?>
                <p class="ipucu">Bu aralıkta okunan haber yok.</p>
            <?php else: ?>
                <ol class="gs-cubuklar gs-haberler">
                    <?php foreach ($gsHaberler as $h): ?>
                        <li>
                            <span class="gs-cubuk-ad">
                                <?php if ($haberAdresi($h['slug']) !== ''): ?>
                                    <a href="<?= e($haberAdresi($h['slug'])) ?>" target="_blank" rel="noopener"><?= e((string) $h['baslik']) ?></a>
                                <?php else: ?>
                                    <?= e((string) $h['baslik']) ?>
                                <?php endif; ?>
                            </span>
                            <span class="gs-cubuk-iz"><span class="gs-cubuk" style="width:<?= round((int) $h['okuyucu'] / $gsHaberEnCok * 100, 1) ?>%"></span></span>
                            <span class="gs-cubuk-deger"><?= $sayi((int) $h['okuyucu']) ?> <small>kişi · <?= $sayi((int) $h['goruntulenme']) ?> okunma</small></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>
</section>
<script src="<?= e(varlik('/assets/rapor-grafik.js')) ?>" defer></script>

<!-- Su an -->
<?php if ($simdiHab !== []): ?>
    <div class="kutu" style="margin-top:18px;">
        <h2 style="margin:0 0 8px;font-size:1.05rem;">Şu an en çok okunanlar <span class="ipucu" style="font-weight:400;">(son 60 dakika)</span></h2>
        <ol class="rapor-simdi">
            <?php foreach ($simdiHab as $h): ?>
                <li>
                    <?php if ($haberAdresi($h['slug']) !== ''): ?>
                        <a href="<?= e($haberAdresi($h['slug'])) ?>" target="_blank" rel="noopener"><?= e((string) $h['baslik']) ?></a>
                    <?php else: ?>
                        <?= e((string) $h['baslik']) ?>
                    <?php endif; ?>
                    <span class="ipucu"><?= $sayi((int) $h['okuyucu']) ?> kişi</span>
                    <?php if ((int) ($h['one_cikan'] ?? 0) === 1): ?><span class="rozet rozet-skor">öne çıkan</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
<?php endif; ?>

<!-- Ayrintilar: istenince acilir (site sahibi: "diger bilgiler altta kalsin, detaylara istedigimiz zaman ulasalim") -->
<h2 class="detay-ana-baslik">Ayrıntılı raporlar</h2>

<details class="detay" id="olcumler">
    <summary>Tüm ölçüler (okuma süresi, sonuna kadar okuma, tek sayfada ayrılan…)</summary>
<div class="olcum-izgara">
    <div class="olcum olcum-vurgu">
        <span class="olcum-sayi"><?= $sayi($simdi) ?></span>
        <span class="olcum-ad">şu an sitede</span>
        <span class="olcum-alt">son <?= (int) ZIYARET_ONLINE_DAKIKA ?> dakika</span>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= $sayi($olcu['ziyaretci']) ?></span>
        <span class="olcum-ad">ziyaretçi (tekil kişi)</span>
        <?= $degisim($olcu['ziyaretci'], $onceki['ziyaretci']) ?>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= $sayi($olcu['goruntulenme']) ?></span>
        <span class="olcum-ad">sayfa görüntülenme</span>
        <?= $degisim($olcu['goruntulenme'], $onceki['goruntulenme']) ?>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= $sayi($olcu['haber_goruntulenme']) ?></span>
        <span class="olcum-ad">haber okunma</span>
        <?= $degisim($olcu['haber_goruntulenme'], $onceki['haber_goruntulenme']) ?>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= $sayi($olcu['sayfa_kisi'], 1) ?></span>
        <span class="olcum-ad">kişi başına sayfa</span>
        <?= $degisim($olcu['sayfa_kisi'], $onceki['sayfa_kisi']) ?>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= e(zr_sure($olcu['haber_ort_sure'])) ?></span>
        <span class="olcum-ad">ort. haber okuma süresi</span>
        <?= $degisim($olcu['haber_ort_sure'], $onceki['haber_ort_sure']) ?>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= $olcu['okuma_orani'] === null ? '—' : '%' . $sayi($olcu['okuma_orani']) ?></span>
        <span class="olcum-ad">haberi sonuna kadar okuyan</span>
        <span class="olcum-alt">sayfanın %75'ine kadar inenler</span>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= $olcu['hemen_cikma'] === null ? '—' : '%' . $sayi($olcu['hemen_cikma']) ?></span>
        <span class="olcum-ad">tek sayfada ayrılan</span>
        <?= $degisim($olcu['hemen_cikma'], $onceki['hemen_cikma'], true) ?>
    </div>
    <div class="olcum">
        <span class="olcum-sayi"><?= $olcu['yeni_ziyaretci'] === null || $olcu['ziyaretci'] === 0 ? '—' : '%' . $sayi($olcu['yeni_ziyaretci'] / $olcu['ziyaretci'] * 100) ?></span>
        <span class="olcum-ad">ilk kez gelen</span>
        <span class="olcum-alt">gerisi geri dönen okuyucu</span>
    </div>
</div>
</details>

<details class="detay" id="haberler"<?= isset($_GET['sira']) || $hepsi || isset($_GET['bildirim']) && str_starts_with((string) $_GET['bildirim'], 'one_') ? ' open' : '' ?>>
    <summary>Haberler — tam liste, sıralama ve öne çıkarma</summary>
<div class="kutu" style="margin-top:18px;">
    <div class="rapor-baslik">
        <h2>Haberler</h2>
        <a class="ipucu" href="<?= e($adres(['csv' => 'haberler'])) ?>">CSV indir</a>
    </div>
    <p class="ipucu" style="margin-top:0;">
        Hangi haberlerin gerçekten okunduğunu gösterir. <strong>Okuyucu</strong> farklı kişi sayısıdır (aynı kişinin
        tekrar açması sayılmaz); <strong>okuma süresi</strong> sayfanın ekranda açık kaldığı süre;
        <strong>sonuna kadar</strong> sayfanın %75'ine inen okuyucuların oranı. “Öne çıkar” haberi ana sayfada ve
        listelerde öne alır.
    </p>
    <nav class="sekmeler sekmeler-kucuk">
        <span class="ipucu">Sırala:</span>
        <?php foreach (zr_haber_siralari() as $anahtar => $ad): ?>
            <a href="<?= e($adres(['sira' => $anahtar === 'okuyucu' ? null : $anahtar])) ?>#haberler"
               class="<?= $sira === $anahtar ? 'aktif' : '' ?>"><?= e($ad) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($haberler === []): ?>
        <p class="ipucu">Bu aralıkta okunan haber yok.</p>
    <?php else: ?>
        <div class="tablo-kaydir">
            <table class="liste-tablo rapor-tablo">
                <thead>
                    <tr>
                        <th>#</th><th>Haber</th>
                        <th class="sag">Okuyucu</th><th class="sag">Görüntülenme</th>
                        <th class="sag">Okuma süresi</th><th class="sag">Sonuna kadar</th>
                        <th class="sag" title="Arama motorundan gelen okuyucu">Aramadan</th>
                        <th class="sag" title="Sosyal medyadan gelen okuyucu">Sosyalden</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php $enCokOkuyucu = max(1, (int) $haberler[0]['okuyucu']); ?>
                <?php foreach ($haberler as $i => $h): ?>
                    <tr>
                        <td class="ipucu"><?= $i + 1 ?></td>
                        <td>
                            <?php if ($haberAdresi($h['slug']) !== ''): ?>
                                <a href="<?= e($haberAdresi($h['slug'])) ?>" target="_blank" rel="noopener"><?= e((string) $h['baslik']) ?></a>
                            <?php else: ?>
                                <?= e((string) $h['baslik']) ?> <span class="ipucu">(silinmiş)</span>
                            <?php endif; ?>
                            <div class="rapor-alt">
                                <?php if (!empty($h['kategori'])): ?><span><?= e((string) $h['kategori']) ?></span><?php endif; ?>
                                <?php if (!empty($h['yayin_tarihi'])): ?><span><?= e(tarih_bicimle((string) $h['yayin_tarihi'])) ?></span><?php endif; ?>
                                <?php if (($h['durum'] ?? '') !== '' && $h['durum'] !== HABER_YAYINDA): ?><span class="rozet rozet-<?= e((string) $h['durum']) ?>"><?= e((string) $h['durum']) ?></span><?php endif; ?>
                                <?php if ((int) ($h['one_cikan'] ?? 0) === 1): ?><span class="rozet rozet-skor">öne çıkan</span><?php endif; ?>
                            </div>
                            <div class="rapor-cubuk"><span style="width:<?= round((int) $h['okuyucu'] / $enCokOkuyucu * 100, 1) ?>%"></span></div>
                        </td>
                        <td class="sag"><strong><?= $sayi((int) $h['okuyucu']) ?></strong></td>
                        <td class="sag"><?= $sayi((int) $h['goruntulenme']) ?></td>
                        <td class="sag"><?= e(zr_sure($h['ort_sure'] !== null ? (float) $h['ort_sure'] : null)) ?></td>
                        <td class="sag"><?= $h['okuma_orani'] !== null ? '%' . $sayi((float) $h['okuma_orani']) : '—' ?></td>
                        <td class="sag"><?= $sayi((int) $h['arama']) ?></td>
                        <td class="sag"><?= $sayi((int) $h['sosyal']) ?></td>
                        <td>
                            <?php if (!empty($h['slug']) && ($h['durum'] ?? '') === HABER_YAYINDA): ?>
                                <form method="post" action="islem.php" style="margin:0;">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $h['haber_id'] ?>">
                                    <input type="hidden" name="donus" value="istatistik">
                                    <input type="hidden" name="donus_sorgu" value="<?= e(substr($adres(), strlen('istatistik.php?'))) ?>">
                                    <?php if ((int) $h['one_cikan'] === 1): ?>
                                        <button type="submit" name="islem" value="one_cikarma" class="dugme dugme-kucuk">Öne çıkarmayı kaldır</button>
                                    <?php else: ?>
                                        <button type="submit" name="islem" value="one_cikar" class="dugme dugme-kucuk dugme-ana">Öne çıkar</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$hepsi && count($haberler) >= 30): ?>
            <p style="margin-bottom:0;"><a href="<?= e($adres(['hepsi' => '1'])) ?>#haberler">Tüm haberleri göster</a></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
</details>

<details class="detay">
    <summary>Başlıklara göre okunma ve kanallar</summary>
<div class="istatistik-ikili">
    <!-- Kategoriler -->
    <div class="kutu">
        <h2 style="margin-top:0;">Başlıklara göre</h2>
        <?php if ($kategori === []): ?>
            <p class="ipucu">Bu aralıkta okunan haber yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Başlık</th><th class="sag">Okunma</th><th class="sag">Okuyucu</th><th class="sag" title="Okunan haber başına ortalama okunma">Haber başına</th><th class="sag">Süre</th></tr></thead>
                <tbody>
                <?php foreach ($kategori as $k): ?>
                    <tr>
                        <td><?= e((string) $k['kategori']) ?> <span class="ipucu">(<?= (int) $k['haber'] ?> haber)</span></td>
                        <td class="sag"><strong><?= $sayi((int) $k['goruntulenme']) ?></strong></td>
                        <td class="sag"><?= $sayi((int) $k['okuyucu']) ?></td>
                        <td class="sag"><?= $sayi((int) $k['goruntulenme'] / max(1, (int) $k['haber']), 1) ?></td>
                        <td class="sag"><?= e(zr_sure($k['ort_sure'] !== null ? (float) $k['ort_sure'] : null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Kanallar -->
    <div class="kutu">
        <h2 style="margin-top:0;">Nereden geliyorlar</h2>
        <?php $kanalToplam = max(1, array_sum(array_column($kanallar, 'ziyaretci'))); ?>
        <?php if ($kanallar === []): ?>
            <p class="ipucu">Bu aralıkta kayıt yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Kanal</th><th class="sag">Ziyaretçi</th><th class="sag">Pay</th></tr></thead>
                <tbody>
                <?php foreach ($kanallar as $k): ?>
                    <tr>
                        <td><?= e($k['ad']) ?>
                            <div class="rapor-cubuk"><span style="width:<?= round($k['ziyaretci'] / $kanalToplam * 100, 1) ?>%"></span></div></td>
                        <td class="sag"><strong><?= $sayi($k['ziyaretci']) ?></strong></td>
                        <td class="sag">%<?= $sayi($k['ziyaretci'] / $kanalToplam * 100) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="ipucu" style="margin-bottom:0;">
                Ziyaretçinin siteye girdiği sayfa sayılır; site içinde gezinmesi kanalı değiştirmez. “Doğrudan”: adresi
                yazan, yer imine ya da uygulama içi bir bağlantıya tıklayan okuyucular.
                <?php if ($dogruluk['baslangic'] === '' || $a['bas'] < substr($dogruluk['baslangic'], 0, 10)): ?>
                    23 Ekim 2026 öncesi kayıtlarda site içi geçişler de “doğrudan” görünür.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>
</details>

<details class="detay">
    <summary>Gönderen siteler, kampanyalar ve giriş sayfaları</summary>
<div class="istatistik-ikili">
    <!-- Yonlendiren siteler -->
    <div class="kutu">
        <div class="rapor-baslik"><h2>Gönderen siteler</h2><a class="ipucu" href="<?= e($adres(['csv' => 'kaynaklar'])) ?>">CSV</a></div>
        <?php if ($siteler === []): ?>
            <p class="ipucu">Bu aralıkta başka siteden gelen yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Site</th><th class="sag">Ziyaretçi</th><th class="sag">Giriş</th></tr></thead>
                <tbody>
                <?php foreach ($siteler as $s): ?>
                    <tr>
                        <td><?= e((string) $s['kaynak']) ?> <span class="ipucu"><?= e(zr_kanal_adlari()[$s['kanal']] ?? '') ?></span></td>
                        <td class="sag"><strong><?= $sayi((int) $s['ziyaretci']) ?></strong></td>
                        <td class="sag"><?= $sayi((int) $s['giris']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ($kampanya !== []): ?>
            <h3 style="margin:16px 0 6px;font-size:.98rem;">Kampanya bağlantıları</h3>
            <table class="liste-tablo">
                <tbody>
                <?php foreach ($kampanya as $k): ?>
                    <tr><td><?= e((string) $k['kampanya']) ?></td><td class="sag"><strong><?= $sayi((int) $k['ziyaretci']) ?></strong> kişi</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p class="ipucu" style="margin-bottom:0;">
            Bir paylaşımın kaç kişi getirdiğini görmek için bağlantının sonuna
            <code>?utm_source=bulten</code> (ya da <code>instagram</code>, <code>whatsapp</code>…) ekleyin;
            “Kampanya bağlantıları”nda ayrıca görünür.
        </p>
    </div>

    <!-- Giris sayfalari -->
    <div class="kutu">
        <h2 style="margin-top:0;">Giriş sayfaları</h2>
        <p class="ipucu" style="margin-top:0;">Okuyucunun siteye ilk girdiği sayfa — paylaşılan ve aramada bulunan sayfalar.</p>
        <?php if ($girisler === []): ?>
            <p class="ipucu">Bu aralıkta kayıt yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Sayfa</th><th class="sag">Giriş</th></tr></thead>
                <tbody>
                <?php foreach ($girisler as $g): ?>
                    <tr>
                        <td class="kirp"><a href="<?= e((string) $g['yol']) ?>" target="_blank" rel="noopener" title="<?= e((string) $g['yol']) ?>"><?= e((string) ($g['baslik'] !== '' ? $g['baslik'] : $g['yol'])) ?></a></td>
                        <td class="sag"><strong><?= $sayi((int) $g['giris']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</details>

<details class="detay">
    <summary>Ne zaman okunuyor ve cihazlar</summary>
<div class="istatistik-ikili">
    <!-- Saat x gun -->
    <div class="kutu">
        <h2 style="margin-top:0;">Ne zaman okunuyor</h2>
        <p class="ipucu" style="margin-top:0;">Haftanın günü ve saatine göre görüntülenme; koyu hücreler yoğun saatler. Haberi bu saatlerden önce yayımlamak en çok okuyucuya ulaştırır.</p>
        <?php $isiEnCok = max(1, ...array_map('max', $isi)); $gunler = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']; ?>
        <div class="tablo-kaydir">
            <table class="isi-haritasi">
                <thead><tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?= $h % 3 === 0 ? sprintf('%02d', $h) : '' ?></th><?php endfor; ?></tr></thead>
                <tbody>
                <?php foreach ($isi as $g => $saatler): ?>
                    <tr>
                        <th><?= $gunler[$g] ?></th>
                        <?php foreach ($saatler as $h => $n): ?>
                            <td<?= $n > 0 ? ' style="background:rgba(27,79,143,' . round(0.08 + $n / $isiEnCok * 0.92, 2) . ')"' : '' ?>
                                title="<?= $gunler[$g] ?> <?= sprintf('%02d:00', $h) ?> — <?= (int) $n ?> görüntülenme"></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Cihazlar -->
    <div class="kutu">
        <h2 style="margin-top:0;">Cihazlar</h2>
        <?php if ($cihazlar['cihaz'] === []): ?>
            <p class="ipucu">Cihaz bilgisi bu güncellemeden sonraki ziyaretlerde toplanıyor.</p>
        <?php else: ?>
            <?php
            $cihazAd = ['mobil' => 'Telefon', 'masaustu' => 'Bilgisayar', 'tablet' => 'Tablet'];
            $grup = ['cihaz' => 'Cihaz', 'isletim' => 'İşletim sistemi', 'tarayici' => 'Tarayıcı'];
            ?>
            <div class="cihaz-izgara">
                <?php foreach ($grup as $alan => $baslik): ?>
                    <?php $top = max(1, array_sum(array_map(static fn ($r) => (int) $r['ziyaretci'], $cihazlar[$alan]))); ?>
                    <div>
                        <h3><?= e($baslik) ?></h3>
                        <?php foreach ($cihazlar[$alan] as $r): ?>
                            <div class="cihaz-satir">
                                <span><?= e($alan === 'cihaz' ? ($cihazAd[$r['ad']] ?? (string) $r['ad']) : (string) $r['ad']) ?></span>
                                <strong>%<?= $sayi((int) $r['ziyaretci'] / $top * 100) ?></strong>
                                <div class="rapor-cubuk"><span style="width:<?= round((int) $r['ziyaretci'] / $top * 100, 1) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</details>

<details class="detay">
    <summary>Sitede aranan ve en çok görüntülenen sayfalar</summary>
<div class="istatistik-ikili">
    <!-- Site ici aramalar -->
    <div class="kutu">
        <div class="rapor-baslik"><h2>Sitede aranan</h2><a class="ipucu" href="<?= e($adres(['csv' => 'aramalar'])) ?>">CSV</a></div>
        <p class="ipucu" style="margin-top:0;">Okuyucuların arama kutusuna yazdıkları — hangi konuda haber beklendiğinin en doğrudan işareti.</p>
        <?php if ($aramalar === []): ?>
            <p class="ipucu">Bu aralıkta arama yapılmamış.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Aranan</th><th class="sag">Arama</th><th class="sag">Kişi</th></tr></thead>
                <tbody>
                <?php foreach ($aramalar as $r): ?>
                    <tr>
                        <td><a href="<?= e(arama_yolu($r['sorgu'])) ?>" target="_blank" rel="noopener"><?= e($r['sorgu']) ?></a></td>
                        <td class="sag"><strong><?= $sayi($r['arama']) ?></strong></td>
                        <td class="sag"><?= $sayi($r['ziyaretci']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Sayfalar -->
    <div class="kutu">
        <div class="rapor-baslik"><h2>En çok görüntülenen sayfalar</h2><a class="ipucu" href="<?= e($adres(['csv' => 'sayfalar'])) ?>">CSV</a></div>
        <?php if ($sayfalar === []): ?>
            <p class="ipucu">Bu aralıkta kayıt yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Sayfa</th><th class="sag">Görüntülenme</th><th class="sag">Kişi</th></tr></thead>
                <tbody>
                <?php foreach ($sayfalar as $s): ?>
                    <tr>
                        <td class="kirp"><a href="<?= e((string) $s['yol']) ?>" target="_blank" rel="noopener" title="<?= e((string) $s['yol']) ?>"><?= e((string) ($s['baslik'] !== '' ? $s['baslik'] : $s['yol'])) ?></a></td>
                        <td class="sag"><strong><?= $sayi((int) $s['goruntulenme']) ?></strong></td>
                        <td class="sag"><?= $sayi((int) $s['ziyaretci']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</details>

<details class="detay" id="ziyaretciler"<?= $kisi !== '' ? ' open' : '' ?>>
    <summary>Ziyaretçiler tek tek (son 100) ve gezintileri</summary>
<div class="kutu" style="margin-top:18px;">
    <h2 style="margin-top:0;">Ziyaretçiler <span class="ipucu" style="font-weight:400;">(son 100, en yeni önce)</span></h2>
    <?php if ($kisi !== ''): ?>
        <div class="kutu" style="background:#f7f9fc;margin-bottom:14px;">
            <div class="rapor-baslik">
                <h3 style="margin:0;">Gezinti: <code><?= e($kisi) ?></code></h3>
                <a href="<?= e($adres()) ?>#ziyaretciler">kapat</a>
            </div>
            <?php if ($gezinti === []): ?>
                <p class="ipucu">Kayıt bulunamadı.</p>
            <?php else: ?>
                <table class="liste-tablo">
                    <thead><tr><th>Zaman</th><th>Sayfa</th><th>Geldiği yer</th><th class="sag">Süre</th><th class="sag">Kaydırma</th></tr></thead>
                    <tbody>
                    <?php foreach ($gezinti as $g): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= e(tarih_bicimle((string) $g['zaman'])) ?></td>
                            <td class="kirp"><a href="<?= e((string) $g['yol']) ?>" target="_blank" rel="noopener"><?= e((string) ($g['baslik'] !== '' ? $g['baslik'] : $g['yol'])) ?></a>
                                <?php if (isset($g['insan']) && (int) $g['insan'] === 0): ?><span class="rozet rozet-reddedildi" title="Tarayıcıda görüntülendiği doğrulanmadı">doğrulanmadı</span><?php endif; ?></td>
                            <td><?= e(match ((string) $g['yonlendiren']) { '' => 'doğrudan', ZIYARET_IC_GECIS => 'site içi', default => (string) $g['yonlendiren'] }) ?></td>
                            <td class="sag"><?= isset($g['sure']) ? e(zr_sure((float) $g['sure'])) : '—' ?></td>
                            <td class="sag"><?= isset($g['kaydirma']) && (int) $g['kaydirma'] > 0 ? '%' . (int) $g['kaydirma'] : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($kisiler === []): ?>
        <p class="ipucu">Bu aralıkta ziyaretçi yok.</p>
    <?php else: ?>
        <div class="tablo-kaydir">
            <table class="liste-tablo">
                <thead><tr><th>Son görülme</th><th class="sag">Sayfa</th><th>Son baktığı</th><th>Geldiği yer</th><th>Cihaz</th><th>IP</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($kisiler as $z): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= e(tarih_bicimle((string) $z['son'])) ?></td>
                        <td class="sag"><strong><?= (int) $z['sayfa'] ?></strong></td>
                        <td class="kirp" title="<?= e((string) $z['son_yol']) ?>"><?= e((string) ($z['son_baslik'] !== '' ? $z['son_baslik'] : $z['son_yol'])) ?></td>
                        <td><?= e((string) ($z['yonlendiren'] ?? '') !== '' ? (string) $z['yonlendiren'] : 'doğrudan') ?></td>
                        <td><?= e(['mobil' => 'Telefon', 'masaustu' => 'Bilgisayar', 'tablet' => 'Tablet'][(string) $z['cihaz']] ?? '') ?></td>
                        <td><code><?= e((string) ($z['ip'] ?? '')) ?></code></td>
                        <td><a href="<?= e($adres(['kisi' => (string) $z['kimlik']])) ?>#ziyaretciler">gezinti</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</details>

<details class="detay" id="dogruluk"<?= isset($_GET['bildirim']) && str_starts_with((string) $_GET['bildirim'], 'sayma_') ? ' open' : '' ?>>
    <summary>Sayım nasıl yapılıyor — sayılar doğru mu? / Bu cihazı sayma</summary>
<div class="kutu" style="margin-top:18px;">
    <h2 style="margin-top:0;">Sayım nasıl yapılıyor — sayılar doğru mu?</h2>
    <ul class="tani-liste">
        <li>
            <strong>Tarayıcı doğrulaması:</strong>
            <?php if (!$dogruluk['yeni_sema']): ?>
                veritabanı henüz güncellenmedi; şimdilik her sayfa isteği sayılıyor.
            <?php elseif ($dogruluk['baslangic'] === ''): ?>
                henüz ilk doğrulama gelmedi. Bir okuyucu sitede bir sayfa açtığında başlar; o zamana kadar her sayfa isteği sayılıyor.
            <?php else: ?>
                <?= e(tarih_bicimle($dogruluk['baslangic'])) ?> tarihinden beri bir sayfa, ancak okuyucunun
                tarayıcısında gerçekten açıldığında sayılıyor.
                <?php if ($dogruluk['toplam'] > 0): ?>
                    Son 7 günde sunucuya gelen <?= $sayi($dogruluk['toplam']) ?> sayfa isteğinin
                    <strong>%<?= $sayi($dogruluk['dogrulanan'] / $dogruluk['toplam'] * 100) ?></strong>'i doğrulandı;
                    gerisi (gizli botlar, bağlantı önizlemeleri, açılmadan kapatılan sekmeler) rapora girmiyor.
                <?php endif; ?>
            <?php endif; ?>
        </li>
        <li><strong>Elenenler:</strong> arama motoru ve yapay zekâ botları, WhatsApp/Telegram önizlemeleri,
            izleme servisleri, panel oturumunuz açıkken yaptığınız ziyaretler ve “bu cihazı sayma” işaretli tarayıcılar.</li>
        <li><strong>Tekil ziyaretçi:</strong> tarayıcıya bırakılan rastgele bir kimlikle sayılıyor; telefon ağ
            değiştirse de aynı kişi tek sayılır. Kimlik bırakılamayan tarayıcılarda adres + tarayıcı imzası kullanılır.</li>
        <li><strong>Saat:</strong>
            sunucu <?= e($dogruluk['db_saat'] !== '' ? date('H:i', strtotime($dogruluk['db_saat'])) : '—') ?>,
            Türkiye <?= e(date('H:i')) ?>
            <?php $saatTamam = $dogruluk['db_saat'] !== '' && abs(strtotime($dogruluk['db_saat']) - time()) < 120; ?>
            — <?= $saatTamam ? '<span style="color:var(--yesil);">doğru</span>' : '<span style="color:var(--kirmizi);">farklı, günlük sınırlar kayabilir</span>' ?>.
            <?php if ($dogruluk['saat_farki'] !== '' && (int) $dogruluk['saat_farki'] !== 180): ?>
                Veritabanı sunucusu UTC<?= (int) $dogruluk['saat_farki'] >= 0 ? '+' : '' ?><?= round((int) $dogruluk['saat_farki'] / 60, 1) ?> saatinde çalışıyordu; eski kayıtlar Türkiye saatine çevrildi.
            <?php endif; ?>
        </li>
        <li><strong>Saklama:</strong> kayıtlar <?= (int) ZIYARET_SAKLAMA_GUN ?> gün tutulur<?= $dogruluk['en_eski'] !== '' ? ' (en eski kayıt ' . e(tarih_bicimle($dogruluk['en_eski'], false)) . ')' : '' ?>; veri hiçbir üçüncü tarafa gönderilmez.</li>
    </ul>

    <div class="rapor-baslik" style="margin-top:12px;">
        <div>
            <strong>Bu cihaz:</strong>
            <?= $saymaAcik
                ? 'bu tarayıcıdan yaptığınız ziyaretler <strong>sayılmıyor</strong>.'
                : 'panel oturumunuz kapalıyken bu tarayıcıdan yaptığınız ziyaretler sayılıyor.' ?>
            <div class="ipucu">Siteye telefonunuzdan da bakıyorsanız paneli bir kez o telefondan açıp bu düğmeye basın.</div>
        </div>
        <form method="post" action="istatistik.php" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <?php if ($saymaAcik): ?>
                <button type="submit" name="islem" value="sayma_kapat" class="dugme">Bu cihazı yeniden say</button>
            <?php else: ?>
                <button type="submit" name="islem" value="sayma_ac" class="dugme dugme-ana">Bu cihazı sayma</button>
            <?php endif; ?>
        </form>
    </div>
</div>
</details>

<script>
// Adresteki #bolum (or. #haberler) kapali bir ayrintinin icindeyse ac ve oraya git.
(function () { function ac() { var h = location.hash.slice(1); var el = h && document.getElementById(h); if (el) { var d = el.closest('details') || (el.tagName === 'DETAILS' ? el : null); if (d) { d.open = true; el.scrollIntoView(); } } } ac(); window.addEventListener('hashchange', ac); })();
</script>


<?php require __DIR__ . '/alt.php'; ?>
