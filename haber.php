<?php
declare(strict_types=1);

/**
 * Haber detay sayfasi. Sadece yayinda olan haberler acilir;
 * taslak veya reddedilmis kayitlar 404 doner.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim((string) ($_GET['h'] ?? ''));
$haber = $slug !== '' ? haber_yayinda_bul($slug) : null;

/** Kaydirinca en fazla bu kadar haber arka arkaya gelir (ilki dahil). */
const HABER_AKIS_TAVANI = 10;

// Siradaki haber (kaydirma akisi): yalnizca gövde, sayfa iskeleti yok.
if (($_GET['sonraki'] ?? '') === '1') {
    require __DIR__ . '/includes/haber_sonraki.php';
    exit;
}

if ($haber === null) {
    http_response_code(404);
    $sayfaBasligi = 'Haber bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="bos-durum">
        <strong>Haber bulunamadı.</strong>
        Aradığınız haber yayından kaldırılmış veya adres hatalı olabilir.
        <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$sayfaBasligi  = $haber['baslik'] . ' — Valentra';
$sayfaAciklama = $haber['ozet'];
$aktifKategori = (string) ($haber['kategori_slug'] ?? '');

// Panelde "hangi haberler okundu" listesini besler; sayfa_ust.php
// bu degiskeni gorurse ziyareti habere bagliyor.
$ziyaretHaberId = (int) $haber['id'];

// Arama motoruna bunun bir haber oldugunu acikca soyle; yayim tarihi
// ve gorsel de semaya giriyor.
require_once __DIR__ . '/includes/seo.php';

$seoTur  = 'article';
$seoSema = seo_haber_semasi($haber);

// Haberin tek adresi: haber.php?h=... ya da sonda / ile gelse de ayni.
$seoAdres = site_adresi() . haber_yolu((string) $haber['slug']);

/*
 * Cok kisa haber ve tek kaynagin ozeti Google'a sunulmuyor (bkz.
 * haber_google_sunulur_mu): okuyucu icin sayfa acik, "follow" sayesinde
 * icindeki baglantilar yine taraniyor.
 */
if (!haber_google_sunulur_mu($haber)) {
    $seoRobots = 'noindex, follow';
}

require_once __DIR__ . '/includes/kurumsal.php';

$ilgililer = haber_ilgililer($haber, 6);

// Gorsel sitenin kendi sunucusundan (bkz. includes/gorsel_onbellek.php).
$haberGorseli = haber_gorsel($haber);

if ($haberGorseli !== '') {
    $seoGorsel = site_adresi() . $haberGorseli;
}

/*
 * Okuma sayfasi: kap daraliyor.
 *
 * Kap 1880'e cikinca haber govdesindeki duz paragraf satiri 1550
 * pikseli, yani ~190 karakteri buluyordu; goz bu uzunlukta satir
 * basini kaybediyor. "okuma" sinifi kapi 1320'ye indiriyor, yan
 * pencere de sag tarafi bos birakmadan dolduruyor.
 */
$govdeSinifi = 'okuma';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="ana-duzen">
<div class="ana-kolon">

<?php
/*
 * HABER AKISI (site sahibinin istegi, 28.10.2026): "bir haber
 * okudugunda altinda baska bir haber daha sirayla gelsin; sayfayi
 * kaydirdikca baska bir haber sayfasi daha acilsin". Okuyucu haberin
 * sonuna yaklasinca ilgili haberlerden siradaki
 * (haber.php?sonraki=1, includes/haber_sonraki.php) altina ekleniyor;
 * en fazla HABER_AKIS_TAVANI haber. Okunan haber ekrana gelince adres
 * cubugu ve sekme basligi o habere geciyor ve ayri bir sayfa
 * goruntulemesi olarak sayiliyor (ziyaretler, api/gorunum.php).
 * Google ilk haberi goruyor; akis yalnizca tarayicida.
 */
$govdeVeri = [
    'id'     => (int) $haber['id'],
    'slug'   => (string) $haber['slug'],
    'adres'  => haber_yolu((string) $haber['slug']),
    'baslik' => $sayfaBasligi,
];
?>
<div id="haber-akisi">
    <?php require __DIR__ . '/includes/haber_govde.php'; ?>
</div>
<div id="haber-akisi-son" aria-hidden="true"></div>

<?php
/*
 * BUNLAR DA ILGINIZI CEKEBILIR: 6 gorselli kart, akisin en sonunda
 * (betik calismazsa ya da akis bitince okuyucunun onunde). kart_izgara.php
 * $haber ve $etiketler degiskenlerini kullaniyor; haberin kendisi korunuyor.
 */
?>
<?php if ($ilgililer !== []): ?>
    <section class="ilgili-kartlar" aria-label="Bunlar da ilginizi çekebilir">
        <h2>Bunlar da ilginizi çekebilir</h2>
        <?php
        $anaHaber        = $haber;
        $izgaraHaberleri = $ilgililer;
        require __DIR__ . '/includes/kart_izgara.php';
        $haber = $anaHaber;
        ?>
    </section>
<?php endif; ?>

<script>
(function () {
    var akis = document.getElementById('haber-akisi'), son = document.getElementById('haber-akisi-son');
    if (!akis || !son || !('IntersectionObserver' in window) || !window.fetch) { return; }
    var TAVAN = <?= (int) HABER_AKIS_TAVANI ?>, yuklu = 1, bekliyor = false, bitti = false, gorulen = {}, vk = '';
    try { vk = localStorage.getItem('vz_k') || ''; } catch (e) {}
    function makaleler() { return akis.querySelectorAll('article[data-adres]'); }
    function sonMakale() { var l = makaleler(); return l[l.length - 1]; }
    function yukle() {
        if (bekliyor || bitti) { return; }
        if (yuklu >= TAVAN) { bitti = true; return; }
        var m = sonMakale(), haric = [].map.call(makaleler(), function (a) { return a.getAttribute('data-id'); });
        bekliyor = true;
        fetch('/haber.php?sonraki=1&h=' + encodeURIComponent(m.getAttribute('data-slug')) + '&haric=' + haric.join(','), { credentials: 'same-origin' })
            .then(function (r) { return r.status === 200 ? r.text() : ''; })
            .then(function (html) {
                bekliyor = false;
                if (!html) { bitti = true; return; }
                var d = document.createElement('div');
                d.innerHTML = html;
                while (d.firstChild) { akis.appendChild(d.firstChild); }
                yuklu++;
                izle.observe(sonMakale());
            })
            .catch(function () { bekliyor = false; bitti = true; });
    }
    // Okunan haber: adres, baslik ve sayim (ekranin ortasindaki serit).
    var izle = new IntersectionObserver(function (gozlem) {
        gozlem.forEach(function (g) {
            if (!g.isIntersecting) { return; }
            var a = g.target, adres = a.getAttribute('data-adres'), z = a.getAttribute('data-zid');
            if (location.pathname + location.search !== adres) {
                try { history.replaceState(null, '', adres); } catch (e) {}
                document.title = a.getAttribute('data-baslik');
            }
            if (z && !gorulen[z]) {
                gorulen[z] = 1;
                var d = new URLSearchParams();
                d.append('id', z); d.append('t', a.getAttribute('data-zt')); d.append('k', vk);
                d.append('y', '0'); d.append('s', '0'); d.append('o', '0');
                fetch('/api/gorunum.php', { method: 'POST', body: d, keepalive: true, credentials: 'omit' }).catch(function () {});
            }
        });
    }, { rootMargin: '-40% 0px -55% 0px' });
    izle.observe(sonMakale());
    new IntersectionObserver(function (g) { if (g[0].isIntersecting) { yukle(); } }, { rootMargin: '900px 0px' }).observe(son);
})();
</script>


</div>

<?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
