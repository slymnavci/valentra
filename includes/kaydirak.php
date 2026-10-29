<?php
declare(strict_types=1);

/**
 * Kayan manşet. $mansetler dizisini alır.
 *
 * Kaydırma CSS scroll-snap ile yapılır; JavaScript yalnızca numaraları ve
 * okları bağlar. JavaScript çalışmasa da slaytlar yatay kaydırılabilir
 * kalır, haberler erişilebilir olmaya devam eder.
 */

if (($mansetler ?? []) === []) {
    return;
}
?>
<section class="kaydirak" id="manset" aria-roledescription="carousel" aria-label="Öne çıkan haberler">
    <button class="kaydirak-ok sol" type="button" data-yon="-1" aria-label="Önceki haber">&#8249;</button>
    <button class="kaydirak-ok sag" type="button" data-yon="1" aria-label="Sonraki haber">&#8250;</button>

    <div class="kaydirak-pencere" id="kaydirakPencere">
        <?php foreach ($mansetler as $sira => $haber): ?>
            <?php $gorsel = haber_gorsel($haber); ?>
            <article class="kaydirak-slayt <?= $gorsel !== '' ? 'gorselli' : '' ?>"
                     aria-roledescription="slide"
                     aria-label="<?= (int) $sira + 1 ?> / <?= count($mansetler) ?>">
                <a href="<?= e(haber_yolu((string) $haber['slug'])) ?>">
                    <?php if ($gorsel !== ''): ?>
                        <?php // Ayni gorselin bulanik kopyasi: tam gorunen gorselin arkasini doldurur. ?>
                        <?php // Bulanik arka plan: kucuk kopya yeter. ?>
                        <img class="arka-gorsel" src="<?= e(haber_gorsel_kucuk($haber, 480)) ?>" alt="" aria-hidden="true"
                             referrerpolicy="no-referrer" onerror="this.remove()"
                             loading="<?= $sira === 0 ? 'eager' : 'lazy' ?>">
                        <?php $slaytSet = haber_gorsel_srcset($haber); ?>
                        <img class="ust-gorsel" src="<?= e(haber_gorsel_kucuk($haber, 960)) ?>" alt="" referrerpolicy="no-referrer"
                             <?php if ($slaytSet !== ''): ?>srcset="<?= e($slaytSet) ?>" sizes="(max-width: 960px) 100vw, 960px"<?php endif; ?>
                             <?= $sira === 0 ? 'fetchpriority="high"' : '' ?>
                             onerror="this.closest('.kaydirak-slayt').classList.remove('gorselli');this.remove()"
                             loading="<?= $sira === 0 ? 'eager' : 'lazy' ?>">
                    <?php endif; ?>

                    <div class="kaydirak-govde">
                        <?php if ((int) $haber['id'] === haber_son_dakika_id()): ?>
                            <span class="son-dakika-rozet">Son Dakika</span>
                        <?php endif; ?>
                        <?php if (!empty($haber['kategori_adi'])): ?>
                            <span class="grup"><?= e($haber['kategori_adi']) ?></span>
                        <?php endif; ?>
                        <?php if (haber_video_adresi((string) ($haber['iframe_url'] ?? '')) !== ''): ?>
                            <span class="video-rozet" title="Videolu haber">&#9654; Video</span>
                        <?php endif; ?>

                        <h2><?= e($haber['baslik']) ?></h2>

                        <?php if ($haber['ozet'] !== ''): ?>
                            <p class="ozet"><?= e(kisalt($haber['ozet'], 170)) ?></p>
                        <?php endif; ?>

                        <div class="kunye">
                            <?= e(tarih_bicimle($haber['yayin_tarihi'])) ?>
                        </div>
                    </div>
                </a>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="kaydirak-sayfalar" id="kaydirakSayfalar" role="tablist" aria-label="Haber seç"
     style="--adet: <?= count($mansetler) ?>">
    <?php foreach ($mansetler as $sira => $haber): ?>
        <button type="button" role="tab"
                data-sira="<?= (int) $sira ?>"
                aria-current="<?= $sira === 0 ? 'true' : 'false' ?>"
                aria-label="<?= (int) $sira + 1 ?>. haber"><?= (int) $sira + 1 ?></button>
    <?php endforeach; ?>
</div>

<script>
(function () {
    var pencere  = document.getElementById('kaydirakPencere');
    var sayfalar = document.getElementById('kaydirakSayfalar');
    if (!pencere || !sayfalar) { return; }

    var dugmeler = Array.prototype.slice.call(sayfalar.querySelectorAll('button'));
    var slaytlar = Array.prototype.slice.call(pencere.children);
    if (slaytlar.length < 2) { sayfalar.hidden = true; return; }

    var aktif = 0;
    var kullaniciMudahalesi = false;
    var uzerinde = false;   // fare manset ya da numaralarin uzerindeyken otomatik ilerleme durur

    // aninda: fareyle numara uzerine gelindiginde kaydirma animasyonu yok,
    // haber hemen degisiyor (site sahibinin istegi, 26.10.2026).
    function git(sira, aninda) {
        aktif = (sira + slaytlar.length) % slaytlar.length;
        pencere.scrollTo({ left: slaytlar[aktif].offsetLeft - pencere.offsetLeft, behavior: aninda ? 'instant' : 'smooth' });
        isaretle();
    }

    function isaretle() {
        dugmeler.forEach(function (d, i) {
            d.setAttribute('aria-current', i === aktif ? 'true' : 'false');
        });
    }

    dugmeler.forEach(function (dugme) {
        dugme.addEventListener('click', function () {
            kullaniciMudahalesi = true;
            git(parseInt(dugme.dataset.sira, 10), true);
        });

        // Fare numaranin uzerine gelir gelmez o haber. Dokunmatik ekranda
        // "uzerine gelme" yok; orada dokunma (click) calisiyor.
        dugme.addEventListener('pointerenter', function (olay) {
            if (olay.pointerType !== 'mouse') { return; }
            var sira = parseInt(dugme.dataset.sira, 10);
            if (sira !== aktif) { git(sira, true); }
        });
    });

    // Fare manset ya da numaralar uzerindeyken otomatik ilerleme beklesin:
    // okunan haber elden kaymasin.
    [pencere.parentNode, sayfalar].forEach(function (alan) {
        alan.addEventListener('mouseenter', function () { uzerinde = true; });
        alan.addEventListener('mouseleave', function () { uzerinde = false; });
    });

    document.querySelectorAll('.kaydirak-ok').forEach(function (ok) {
        ok.addEventListener('click', function () {
            kullaniciMudahalesi = true;
            git(aktif + parseInt(ok.dataset.yon, 10));
        });
    });

    // Elle kaydırıldığında numarayı da güncelle.
    var zamanlayici = null;
    pencere.addEventListener('scroll', function () {
        clearTimeout(zamanlayici);
        zamanlayici = setTimeout(function () {
            var en = pencere.clientWidth || 1;
            var yeni = Math.round(pencere.scrollLeft / en);
            if (yeni !== aktif && yeni >= 0 && yeni < slaytlar.length) {
                aktif = yeni;
                isaretle();
            }
        }, 90);
    }, { passive: true });

    // Kendiliğinden ilerler; kullanıcı dokununca durur.
    var otomatik = setInterval(function () {
        if (kullaniciMudahalesi || uzerinde || document.hidden) { return; }
        git(aktif + 1);
    }, 6000);

    ['pointerdown', 'keydown'].forEach(function (olay) {
        pencere.addEventListener(olay, function () {
            kullaniciMudahalesi = true;
            clearInterval(otomatik);
        }, { once: true, passive: true });
    });
})();
</script>
