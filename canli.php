<?php
declare(strict_types=1);

/**
 * Canli anlatim sayfasi (03.11.2026; bkz. includes/canli.php).
 *
 *   /canli            acik ve biten anlatimlar
 *   /canli/{slug}     anlatim: gelismeler en yeni ustte, 30 sn'de bir yenilenir
 *   canli.php?c=..&sonra=ID&json=1   yeni gelismeler (betik icin, onbelleksiz)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/canli.php';

$slug = trim((string) ($_GET['c'] ?? ''));

// Yeni gelismeler: sayfa onbelleginden ONCE, her zaman taze.
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');

    $a = $slug !== '' ? canli_bul($slug) : null;

    if ($a === null) {
        http_response_code(404);
        echo '{"tamam":false}';
        exit;
    }

    $yeni = canli_girisler((int) $a['id'], max(0, (int) ($_GET['sonra'] ?? 0)), 50);
    echo json_encode([
        'tamam' => true,
        'durum' => $a['durum'],
        'sayi'  => count($yeni),
        'son'   => $yeni !== [] ? (int) $yeni[0]['id'] : (int) ($_GET['sonra'] ?? 0),
        'html'  => implode('', array_map('canli_giris_html', $yeni)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/includes/sayfa_onbellek.php';

// Ziyaretciye bir dakikalik kopya; yeni gelismeleri betik getiriyor.
sayfa_onbellek(['c']);

require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/kurumsal.php';

$anlatim = $slug !== '' ? canli_bul($slug) : null;

if ($slug !== '' && $anlatim === null) {
    http_response_code(404);
    $sayfaBasligi = 'Canlı anlatım bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    echo '<div class="bos-durum"><strong>Canlı anlatım bulunamadı.</strong> <p><a href="' . e(canli_yolu()) . '" style="color:var(--vurgu);text-decoration:underline;">Bütün canlı anlatımlar</a></p></div>';
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

if ($anlatim === null) {
    $liste = canli_liste();
    $sayfaBasligi  = 'Canlı anlatım — Valentra';
    $sayfaAciklama = 'Valentra canlı anlatımları: önemli olaylar dakika dakika.';
    $seoAdres      = site_adresi() . canli_yolu();
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <section class="canli-liste-sayfa">
        <h1 class="canli-sayfa-baslik"><span class="canli-rozet">Canlı</span> Canlı anlatım</h1>
        <?php if ($liste === []): ?>
            <div class="bos-durum">Şu an canlı anlatım yok. Büyük gelişmelerde burada dakika dakika takip edebilirsiniz.</div>
        <?php else: ?>
            <ul class="canli-liste">
                <?php foreach ($liste as $a): ?>
                    <li>
                        <a href="<?= e(canli_yolu((string) $a['slug'])) ?>">
                            <?php if ($a['durum'] === 'canli'): ?><span class="canli-rozet">Canlı</span><?php else: ?><span class="canli-bitti-etiket">Sona erdi</span><?php endif; ?>
                            <strong><?= e((string) $a['baslik']) ?></strong>
                            <span class="ipucu"><?= (int) $a['giris_sayisi'] ?> gelişme · son: <?= e(tarih_bicimle((string) $a['guncellendi'])) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$girisler = canli_girisler((int) $anlatim['id']);
$acik     = $anlatim['durum'] === 'canli';

$sayfaBasligi  = ($acik ? 'CANLI | ' : '') . $anlatim['baslik'] . ' — Valentra';
$sayfaAciklama = kisalt((string) ($anlatim['ozet'] !== '' ? $anlatim['ozet'] : ($girisler[0]['metin'] ?? $anlatim['baslik'])), 155);
$seoAdres      = site_adresi() . canli_yolu((string) $anlatim['slug']);
$seoTur        = 'article';
$seoSema       = canli_semasi($anlatim, $girisler);

if ((string) $anlatim['gorsel_url'] !== '') {
    $seoGorsel = (string) $anlatim['gorsel_url'];
}

$govdeSinifi = 'okuma';

require __DIR__ . '/includes/sayfa_ust.php';
require_once __DIR__ . '/includes/paylas.php';
?>

<article class="canli-anlatim" id="canli" data-adres="<?= e(canli_yolu((string) $anlatim['slug'])) ?>" data-slug="<?= e((string) $anlatim['slug']) ?>"
         data-son="<?= $girisler !== [] ? (int) $girisler[0]['id'] : 0 ?>" data-acik="<?= $acik ? '1' : '0' ?>">
    <header class="canli-ust">
        <?php if ($acik): ?>
            <span class="canli-rozet">Canlı</span>
        <?php else: ?>
            <span class="canli-bitti-etiket">Canlı anlatım sona erdi</span>
        <?php endif; ?>
        <h1><?= e((string) $anlatim['baslik']) ?></h1>
        <?php if ((string) $anlatim['ozet'] !== ''): ?>
            <p class="spot"><?= e((string) $anlatim['ozet']) ?></p>
        <?php endif; ?>
        <div class="kunye">
            <a class="hazirlayan" href="<?= e(kurumsal_yolu('yayin-ilkeleri')) ?>">Valentra Yayın Kurulu</a>
            <span>&middot; Başladı: <?= e(tarih_bicimle((string) $anlatim['olusturuldu'])) ?></span>
            <span>&middot; Son güncelleme: <time data-canli-guncel><?= e(tarih_bicimle((string) $anlatim['guncellendi'])) ?></time></span>
        </div>
        <?= paylas_cubugu_html((string) $anlatim['baslik'], canli_yolu((string) $anlatim['slug']), 'ust') ?>
        <?php if ((string) $anlatim['gorsel_url'] !== ''): ?>
            <img src="<?= e((string) $anlatim['gorsel_url']) ?>" alt="<?= e((string) $anlatim['baslik']) ?>" referrerpolicy="no-referrer" fetchpriority="high"
                 onerror="this.remove()" style="border-radius:8px;margin:4px 0 18px;width:100%;">
        <?php endif; ?>
    </header>

    <?php if ($acik): ?>
        <p class="canli-bilgi" aria-live="polite">
            <span class="canli-nokta" aria-hidden="true"></span>
            Sayfa her <?= CANLI_YENILEME_SN ?> saniyede bir kendiliğinden güncellenir.
            <button type="button" class="canli-yeni" data-canli-yeni hidden></button>
        </p>
    <?php endif; ?>

    <div class="canli-akis" data-canli-akis>
        <?php foreach ($girisler as $g): ?>
            <?= canli_giris_html($g) ?>
        <?php endforeach; ?>
        <?php if ($girisler === []): ?>
            <p class="ipucu canli-bos">İlk gelişmeler birazdan burada olacak.</p>
        <?php endif; ?>
    </div>

    <?= paylas_cubugu_html((string) $anlatim['baslik'], canli_yolu((string) $anlatim['slug']), 'alt') ?>
    <?= whatsapp_kanal_html() ?>
</article>

<?php if ($acik): ?>
<script>
(function () {
    'use strict';
    var kutu = document.getElementById('canli');
    if (!kutu || !window.fetch) { return; }
    var akis = kutu.querySelector('[data-canli-akis]');
    var dugme = kutu.querySelector('[data-canli-yeni]');
    var son = Number(kutu.getAttribute('data-son') || 0);
    var bekleyen = '';
    var bekleyenSayi = 0;
    var baslik = document.title;

    function ekle() {
        if (!bekleyen) { return; }
        var bos = akis.querySelector('.canli-bos');
        if (bos) { bos.remove(); }
        var gecici = document.createElement('div');
        gecici.innerHTML = bekleyen;
        [].slice.call(gecici.children).reverse().forEach(function (g) { g.classList.add('yeni'); akis.insertBefore(g, akis.firstChild); });
        bekleyen = ''; bekleyenSayi = 0;
        dugme.hidden = true;
        document.title = baslik;
    }

    // Okur en ustteyse yeni gelisme dogrudan eklenir; asagida okuyorsa
    // sayfa kaymasin diye "N yeni gelisme" dugmesi.
    dugme.addEventListener('click', function () {
        ekle();
        akis.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    function sor() {
        if (document.hidden) { return; }
        var adres = '/canli.php?json=1&c=' + encodeURIComponent(kutu.getAttribute('data-slug')) + '&sonra=' + son;
        fetch(adres, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (v) {
            if (!v || !v.tamam) { return; }
            if (v.sayi > 0) {
                son = v.son;
                bekleyen = v.html + bekleyen;
                bekleyenSayi += v.sayi;
                var ustte = akis.getBoundingClientRect().top > -40;
                if (ustte) {
                    ekle();
                } else {
                    dugme.textContent = '↑ ' + bekleyenSayi + ' yeni gelişme';
                    dugme.hidden = false;
                    document.title = '(' + bekleyenSayi + ') ' + baslik;
                }
                var g = kutu.querySelector('[data-canli-guncel]');
                if (g) { g.textContent = new Date().toLocaleString('tr-TR', { timeZone: 'Europe/Istanbul', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
            }
            if (v.durum !== 'canli') { clearInterval(zamanlayici); }
        }).catch(function () {});
    }

    var zamanlayici = setInterval(sor, <?= CANLI_YENILEME_SN * 1000 ?>);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { sor(); } });
    // Sayfa onbellekten (bir dakikaya kadar eski) geldiyse hemen sor.
    setTimeout(sor, 1500);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
