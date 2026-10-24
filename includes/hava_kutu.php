<?php
declare(strict_types=1);

/**
 * Yan penceredeki hava durumu kartı.
 *
 * Site sahibinin istegi (24.10.2026): "sagdaki bos kalan yere hava
 * durumunu yerlestirelim". Anlik durum, gunun en yuksek/en dusuk
 * sicakligi ve sonraki uc gun. Il secimi tarayicida kaliyor; secilen
 * ilin verisi api/hava.php'den. Kaynaga ulasilamazsa kart basilmiyor.
 */

require_once __DIR__ . '/hava.php';

try {
    $hava = hava_durumu();
} catch (Throwable $e) {
    $hava = null;
}

if ($hava === null) {
    return;
}
?>
<section class="pencere hava-kart" id="hava" data-il="<?= e($hava['il']) ?>" aria-labelledby="hava-baslik">
    <div class="hava-kart-ust">
        <h2 class="pencere-baslik" id="hava-baslik">Hava Durumu</h2>
        <label class="gizli-etiket" for="hava-il">Hava durumu ili</label>
        <select id="hava-il" class="hava-il">
            <?php foreach (HAVA_ILLERI as $anahtar => $il): ?>
                <option value="<?= e($anahtar) ?>" <?= $anahtar === $hava['il'] ? 'selected' : '' ?>><?= e($il[0]) ?></option>
            <?php endforeach; ?>
        </select>
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
</section>

<script>
(function () {
    var kutu = document.getElementById('hava');
    var secim = document.getElementById('hava-il');
    if (!kutu || !secim) { return; }

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
            })
            .catch(function () { /* Gecici hata karti bozmasin. */ });
    }

    var kayitli = null;
    try { kayitli = localStorage.getItem('valentra-il'); } catch (e) {}

    if (kayitli && kayitli !== kutu.getAttribute('data-il')
        && secim.querySelector('option[value="' + kayitli + '"]')) {
        secim.value = kayitli;
        getir(kayitli);
    }

    secim.addEventListener('change', function () {
        try { localStorage.setItem('valentra-il', secim.value); } catch (e) {}
        getir(secim.value);
    });
})();
</script>
