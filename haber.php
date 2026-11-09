<?php
declare(strict_types=1);

/**
 * Haber detay sayfasi. Sadece yayinda olan haberler acilir;
 * taslak veya reddedilmis kayitlar 404 doner.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

// Ziyaretciye bir dakikalik kopya (bkz. includes/sayfa_onbellek.php).
sayfa_onbellek(['h']);

$slug = trim((string) ($_GET['h'] ?? ''));
$haber = $slug !== '' ? haber_yayinda_bul($slug) : null;

/** Kaydirinca en fazla bu kadar haber arka arkaya gelir (ilki dahil). */
const HABER_AKIS_TAVANI = 10;

// Siradaki haber (kaydirma akisi): yalnizca gövde, sayfa iskeleti yok.
if (($_GET['sonraki'] ?? '') === '1') {
    require __DIR__ . '/includes/haber_sonraki.php';
    exit;
}

// Birlestirilmis haberin eski adresi: kalici olarak yasayan habere
// (08.11.2026; bkz. includes/haber_birlestir.php).
if ($haber === null && $slug !== '') {
    require_once __DIR__ . '/includes/haber_birlestir.php';
    $hedefSlug = haber_yonlendirme_hedefi($slug);

    if ($hedefSlug !== null) {
        header('Location: ' . haber_yolu($hedefSlug), true, 301);
        exit;
    }
}

/*
 * Yayindan kaldirilmis haber (09.11.2026; Search Console "Bulunamadi
 * (404)"): adres bir zamanlar vardi. Tekrar diye bekletilen haberin
 * yayindaki esi varsa ona 301; yoksa 410 (kalici olarak kaldirildi):
 * Google 410'u 404'ten hizli birakiyor.
 */
$kaldirildi = false;

if ($haber === null && $slug !== '') {
    try {
        $eski = db()->prepare(
            "SELECT h.durum, e.slug AS es_slug
               FROM haberler h
               LEFT JOIN haberler e ON e.id = " . (haber_sutun_var('tekrar_id') ? 'h.tekrar_id' : 'NULL') . " AND e.durum = 'yayinda'
              WHERE h.slug = :s LIMIT 1"
        );
        $eski->execute(['s' => $slug]);
        $eskiSatir = $eski->fetch();
    } catch (Throwable $e) {
        $eskiSatir = false;
    }

    if ($eskiSatir !== false && !empty($eskiSatir['es_slug'])) {
        header('Location: ' . haber_yolu((string) $eskiSatir['es_slug']), true, 301);
        exit;
    }

    $kaldirildi = $eskiSatir !== false;
}

if ($haber === null) {
    http_response_code($kaldirildi ? 410 : 404);
    $seoRobots = 'noindex, follow';
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

/*
 * ARAMA SONUCU (site sahibinin istegi, 29.10.2026: "tiklanma sayisi
 * artsin"; Search Console: 3.744 gosterim, 100 tiklama). Google basligi
 * ~60 karakterden sonra kesiyor: uzun baslikta " — Valentra" eki yer
 * kaplamasin, haberin kendisi tam gorunsun. Aciklama 120-155 karakter:
 * ozet kisaysa metnin ilk cumleleriyle tamamlaniyor, uzunsa kelime
 * sinirinda kisaltiliyor.
 */
$sayfaBasligi = mb_strlen((string) $haber['baslik'], 'UTF-8') <= 52
    ? $haber['baslik'] . ' — Valentra'
    : (string) $haber['baslik'];

$sayfaAciklama = trim((string) $haber['ozet']);

if (mb_strlen($sayfaAciklama, 'UTF-8') < 110) {
    $ek = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $haber['icerik'])) ?? '');

    // Metin ozetle ayni cumleyle basliyorsa tekrar etme.
    if ($ek !== '' && !str_starts_with($ek, mb_substr($sayfaAciklama, 0, 40, 'UTF-8'))) {
        $sayfaAciklama = trim($sayfaAciklama . ' ' . $ek);
    }
}

$sayfaAciklama = kisalt(okur_dili($sayfaAciklama), 155);
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

// Esik ustu yeni deprem varsa haber (en fazla uc dakikada bir; bkz. includes/deprem.php).
require_once __DIR__ . '/includes/deprem.php';
deprem_denetle_sayfa_sonunda();

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

// Haber gorseli <head>'de onceden isteniyor (bkz. includes/sayfa_ust.php);
// adresler includes/haber_govde.php'dekiyle ayni.
if ($haberGorseli !== '' && !isset($_GET['sonraki'])) {
    $lcpGorsel = [
        'src'    => haber_gorsel_kucuk($haber, 960),
        'srcset' => haber_gorsel_srcset($haber),
        'sizes'  => '(max-width: 960px) 100vw, 960px',
    ];
}

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
    var ilkMakale = akis.querySelector('article[data-adres]'), gaSayilan = {};
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
                // Gelen haberin reklam alani: innerHTML ile eklenen betik calismadigi
                // icin burada dolduruluyor (bkz. includes/olcum_reklam.php).
                [].forEach.call(akis.querySelectorAll('ins.adsbygoogle:not([data-vz-dolu])'), function (r) {
                    r.setAttribute('data-vz-dolu', '1');
                    try { (window.adsbygoogle = window.adsbygoogle || []).push({}); } catch (e) {}
                });
                izle.observe(sonMakale());
                // Eklenen haber kisaysa son isaret hala gorus alaninda kaliyor ve
                // gozlemci yeniden haber vermiyordu (akis ikinci haberde duruyordu):
                // yeniden gozlemek o anki durumu bir kez daha bildiriyor.
                alt.unobserve(son);
                alt.observe(son);
            })
            .catch(function () { bekliyor = false; bitti = true; });
    }
    /*
     * HER HABER AYRI SAYFA (site sahibinin istegi, 03.11.2026: "ikinci
     * habere gectiginde yeni bir sayfaya tikliyor gibi gorunsun").
     *
     * Yeni gelen haber ilk kez okununca tarayici gecmisine YENI bir kayit
     * ekleniyor (pushState): adres, baslik ve geri tusu normal bir sayfa
     * gecisindeki gibi; geri tusu onceki haberin basina donduruyor. Daha once
     * okunmus habere yukari kaydirmak yalnizca adresi duzeltiyor
     * (replaceState) ve yeniden sayilmiyor.
     *
     * Google Analytics: her yeni haber bir page_view; onceki haber
     * page_referrer (Analytics'te "bir sayfadan otekine gecis" gibi gorunur).
     * gtag('set') ile sonraki olaylar (kaydirma, etkilesim suresi) da bu
     * habere yaziliyor. Adres, tarayicinin kendi gecmis islevleriyle
     * degisiyor (window.vzGecmis, includes/olcum_reklam.php): Analytics'in
     * otomatik gecmis dinleyicisi acik olsa bile ayni haber iki kez sayilmiyor.
     */
    var G = window.vzGecmis || { p: history.pushState, r: history.replaceState };
    var gecmiseEklenen = {}, popBekle = 0;
    gecmiseEklenen[location.pathname + location.search] = 1;
    function adresDegistir(adres, yeni) {
        try { (yeni ? G.p : G.r).call(history, { vzAkis: adres }, '', adres); } catch (e) {}
    }
    try { G.r.call(history, { vzAkis: location.pathname + location.search }, '', location.href); } catch (e) {}
    // Geri/ileri tusu: o haberin basina git (sayfa yeniden yuklenmeden).
    window.addEventListener('popstate', function () {
        var hedef = location.pathname + location.search, l = makaleler();
        for (var i = 0; i < l.length; i++) {
            if (l[i].getAttribute('data-adres') === hedef) {
                popBekle = Date.now() + 700;
                document.title = l[i].getAttribute('data-baslik');
                l[i].scrollIntoView({ block: 'start' });
                return;
            }
        }
    });
    if ('scrollRestoration' in history) { try { history.scrollRestoration = 'manual'; } catch (e) {} }
    var oncekiAdres = location.href;
    // Okunan haber: adres, baslik ve sayim (ekranin ortasindaki serit).
    var izle = new IntersectionObserver(function (gozlem) {
        gozlem.forEach(function (g) {
            if (!g.isIntersecting) { return; }
            var a = g.target, adres = a.getAttribute('data-adres'), z = a.getAttribute('data-zid');
            if (location.pathname + location.search !== adres && Date.now() > popBekle) {
                var yeni = !gecmiseEklenen[adres], baslik = a.getAttribute('data-baslik');
                gecmiseEklenen[adres] = 1;
                document.title = baslik;
                adresDegistir(adres, yeni);
                // Google Analytics: kaydirmayla gelen her haber ayri sayfa goruntulemesi
                // (29.10.2026). Ilk haber sayfa acilisinda sayildi.
                if (window.gtag && a !== ilkMakale && !gaSayilan[adres]) {
                    gaSayilan[adres] = 1;
                    var tam = location.origin + adres;
                    gtag('set', { page_location: tam, page_title: baslik, page_referrer: oncekiAdres });
                    gtag('event', 'page_view', { page_location: tam, page_title: baslik, page_referrer: oncekiAdres });
                    // Akisin olcusu (panel > Google Analytics > Okur davranisi): kaydirmayla acilan haber.
                    gtag('event', 'sonraki_haber', { haber_sirasi: Object.keys(gaSayilan).length + 1 });
                }
                oncekiAdres = location.origin + adres;
            }
            if (z && !gorulen[z]) {
                gorulen[z] = 1;
                // Tarayici kimligi gonderim aninda okunuyor: ilk ziyarette sayfa
                // acilirken henuz yoktu (sayfanin kendi sayim betigi yaziyor) ve
                // ayni okur tekil ziyaretci sayisinda iki kisi sayiliyordu.
                try { vk = localStorage.getItem('vz_k') || vk; } catch (e) {}
                var d = new URLSearchParams();
                d.append('id', z); d.append('t', a.getAttribute('data-zt')); d.append('k', vk);
                d.append('y', '0'); d.append('s', '0'); d.append('o', '0');
                fetch('/api/gorunum.php', { method: 'POST', body: d, keepalive: true, credentials: 'omit' }).catch(function () {});
            }
        });
    }, { rootMargin: '-40% 0px -55% 0px' });
    izle.observe(sonMakale());
    var alt = new IntersectionObserver(function (g) { if (g[0].isIntersecting) { yukle(); } }, { rootMargin: '900px 0px' });
    alt.observe(son);
})();
</script>


</div>

<?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
