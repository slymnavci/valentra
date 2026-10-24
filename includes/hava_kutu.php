<?php
declare(strict_types=1);

/**
 * Yan penceredeki hava durumu kartı.
 *
 * Site sahibinin istegi (24.10.2026): "sagdaki bos kalan yere hava
 * durumunu yerlestirelim; buyuk sehirlerin gunluk hatta anlik hava
 * tahmin bilgileri gosterilsin". Yazarlarimiz kutusunun altinda.
 *
 *   - Ustte secili sehir: anlik durum, gunun en yuksek/en dusuk
 *     sicakligi, sonraki uc gun.
 *   - Altta buyuk sehirler: anlik sicaklik ve gunun en yuksek/en dusuk
 *     sicakligi; satira tiklayinca ustteki ayrinti o sehre gecer
 *     (api/hava.php), secim tarayicida kalir.
 * Kaynaga ulasilamazsa kart basilmiyor.
 */

require_once __DIR__ . '/hava.php';

try {
    $hava     = hava_durumu();
    $sehirler = hava_buyuk_sehirler();
} catch (Throwable $e) {
    $hava     = null;
    $sehirler = [];
}

if ($hava === null) {
    return;
}
?>
<section class="pencere hava-kart" id="hava" data-il="<?= e($hava['il']) ?>" aria-labelledby="hava-baslik">
    <div class="hava-kart-ust">
        <h2 class="pencere-baslik" id="hava-baslik">Hava Durumu</h2>
        <span class="hava-sehir" data-hava="ad"><?= e($hava['ad']) ?></span>
    </div>

    <div class="hava-simdi">
        <span class="hava-ikon" data-hava="simge"><?= hava_simge_svg($hava['simge']) ?></span>
        <span class="hava-derece" data-hava="sicaklik"><?= (int) $hava['sicaklik'] ?>°</span>
        <span class="hava-bilgi">
            <span class="hava-durum" data-hava="durum"><?= e($hava['durum']) ?></span>
            <span class="hava-aralik" data-hava="aralik"><?php if ($hava['en_yuksek'] !== null && $hava['en_dusuk'] !== null): ?>En yüksek <?= (int) $hava['en_yuksek'] ?>° · En düşük <?= (int) $hava['en_dusuk'] ?>°<?php endif; ?></span>
        </span>
    </div>

    <ul class="hava-gunler" data-hava="gunler">
        <?php foreach ($hava['gunler'] as $gun): ?>
            <li title="<?= e($gun['durum']) ?>">
                <span class="gun"><?= e($gun['gun']) ?></span>
                <?= hava_simge_svg($gun['simge']) ?>
                <span class="derece"><?= (int) $gun['en_yuksek'] ?>° <small><?= (int) $gun['en_dusuk'] ?>°</small></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($sehirler !== []): ?>
        <ul class="hava-sehirler" aria-label="Büyük şehirlerde hava">
            <?php foreach ($sehirler as $sehir): ?>
                <li>
                    <button type="button" data-il="<?= e($sehir['il']) ?>"
                            aria-pressed="<?= $sehir['il'] === $hava['il'] ? 'true' : 'false' ?>"
                            title="<?= e($sehir['durum']) ?>">
                        <?= hava_simge_svg($sehir['simge']) ?>
                        <span class="ad"><?= e($sehir['ad']) ?></span>
                        <span class="simdi"><?= (int) $sehir['sicaklik'] ?>°</span>
                        <span class="aralik">
                            <?php if ($sehir['en_yuksek'] !== null && $sehir['en_dusuk'] !== null): ?>
                                <?= (int) $sehir['en_yuksek'] ?>° / <?= (int) $sehir['en_dusuk'] ?>°
                            <?php endif; ?>
                        </span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<script>
(function () {
    var kutu = document.getElementById('hava');
    if (!kutu) { return; }
    var dugmeler = Array.prototype.slice.call(kutu.querySelectorAll('.hava-sehirler button'));

    function alan(ad) { return kutu.querySelector('[data-hava="' + ad + '"]'); }

    function kacir(metin) {
        var d = document.createElement('div');
        d.textContent = String(metin);
        return d.innerHTML;
    }

    function getir(il) {
        fetch('/api/hava.php?il=' + encodeURIComponent(il))
            .then(function (y) { return y.ok ? y.json() : null; })
            .then(function (v) {
                if (!v) { return; }
                // simge_svg sunucunun sabit SVG'si; diger alanlar metin olarak.
                alan('ad').textContent = v.ad;
                alan('simge').innerHTML = v.simge_svg;
                alan('sicaklik').textContent = v.sicaklik + '°';
                alan('durum').textContent = v.durum;
                alan('aralik').textContent = (v.en_yuksek !== null && v.en_dusuk !== null)
                    ? 'En yüksek ' + v.en_yuksek + '° · En düşük ' + v.en_dusuk + '°' : '';
                alan('gunler').innerHTML = (v.gunler || []).map(function (g) {
                    return '<li title="' + kacir(g.durum) + '"><span class="gun">' + kacir(g.gun) + '</span>'
                        + g.simge_svg + '<span class="derece">' + kacir(g.en_yuksek) + '° <small>'
                        + kacir(g.en_dusuk) + '°</small></span></li>';
                }).join('');
                kutu.setAttribute('data-il', v.il);
                dugmeler.forEach(function (d) {
                    d.setAttribute('aria-pressed', d.getAttribute('data-il') === v.il ? 'true' : 'false');
                });
            })
            .catch(function () { /* Gecici hata karti bozmasin. */ });
    }

    var kayitli = null;
    try { kayitli = localStorage.getItem('valentra-il'); } catch (e) {}

    if (kayitli && kayitli !== kutu.getAttribute('data-il')
        && kutu.querySelector('.hava-sehirler button[data-il="' + kayitli + '"]')) {
        getir(kayitli);
    }

    dugmeler.forEach(function (d) {
        d.addEventListener('click', function () {
            var il = d.getAttribute('data-il');
            try { localStorage.setItem('valentra-il', il); } catch (e) {}
            getir(il);
        });
    });
})();
</script>
