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
            <?php $gorsel = guvenli_url($haber['gorsel_url'] ?? ''); ?>
            <article class="kaydirak-slayt <?= $gorsel !== '' ? 'gorselli' : '' ?>"
                     aria-roledescription="slide"
                     aria-label="<?= (int) $sira + 1 ?> / <?= count($mansetler) ?>">
                <a href="<?= e(haber_yolu((string) $haber['slug'])) ?>">
                    <?php if ($gorsel !== ''): ?>
                        <img class="ust-gorsel" src="<?= e($gorsel) ?>" alt=""
                             loading="<?= $sira === 0 ? 'eager' : 'lazy' ?>">
                    <?php endif; ?>

                    <div class="kaydirak-govde">
                        <?php if (!empty($haber['kategori_adi'])): ?>
                            <span class="grup"><?= e($haber['kategori_adi']) ?></span>
                        <?php endif; ?>

                        <h2><?= e($haber['baslik']) ?></h2>

                        <?php if ($haber['ozet'] !== ''): ?>
                            <p class="ozet"><?= e(kisalt($haber['ozet'], 170)) ?></p>
                        <?php endif; ?>

                        <div class="kunye">
                            <?= e(tarih_bicimle($haber['yayin_tarihi'])) ?>
                            <?php if ($haber['kaynak_adi'] !== ''): ?>
                                &middot; <?= e($haber['kaynak_adi']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="kaydirak-sayfalar" id="kaydirakSayfalar" role="tablist" aria-label="Haber seç">
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

    function git(sira) {
        aktif = (sira + slaytlar.length) % slaytlar.length;
        pencere.scrollTo({ left: slaytlar[aktif].offsetLeft - pencere.offsetLeft, behavior: 'smooth' });
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
            git(parseInt(dugme.dataset.sira, 10));
        });
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
        if (kullaniciMudahalesi || document.hidden) { return; }
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
