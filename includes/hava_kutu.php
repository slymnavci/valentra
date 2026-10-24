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
 *
 * Ana sayfada sehir listesi kartta degil, piyasa seridinin yaninda
 * yatay seritte (includes/hava_sehir_seridi.php); cagiran
 * $havaSehirListesi = false veriyor.
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

    <dl class="hava-ayrinti" data-hava="ayrinti">
        <?php foreach (['hissedilen' => ['Hissedilen', '°'], 'nem' => ['Nem', '%'], 'ruzgar' => ['Rüzgâr', ' km/s']] as $alan => [$etiket, $birim]): ?>
            <?php if (($hava[$alan] ?? null) !== null): ?>
                <div><dt><?= e($etiket) ?></dt><dd><?= $alan === 'nem' ? '%' . (int) $hava[$alan] : (int) $hava[$alan] . e($birim) ?></dd></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </dl>

    <ul class="hava-gunler" data-hava="gunler">
        <?php foreach ($hava['gunler'] as $gun): ?>
            <li title="<?= e($gun['durum']) ?>">
                <span class="gun"><?= e($gun['gun']) ?></span>
                <?= hava_simge_svg($gun['simge']) ?>
                <span class="derece"><?= (int) $gun['en_yuksek'] ?>° <small><?= (int) $gun['en_dusuk'] ?>°</small></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($sehirler !== [] && ($havaSehirListesi ?? true)): ?>
        <ul class="hava-sehirler" aria-label="Büyük şehirlerde hava">
            <?php foreach ($sehirler as $sehir): ?>
                <li>
                    <button type="button" data-hava-il="<?= e($sehir['il']) ?>"
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
// Sayfa yuklenince: ana sayfadaki sehir seridi karttan SONRA geliyor.
document.addEventListener('DOMContentLoaded', function () {
    var kutu = document.getElementById('hava');
    if (!kutu) { return; }
    // Sehir dugmeleri kartta ya da ana sayfadaki sehir seridinde olabilir.
    var dugmeler = Array.prototype.slice.call(document.querySelectorAll('[data-hava-il]'));

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
                var ayrinti = [];
                if (v.hissedilen !== null && v.hissedilen !== undefined) { ayrinti.push(['Hissedilen', v.hissedilen + '°']); }
                if (v.nem !== null && v.nem !== undefined) { ayrinti.push(['Nem', '%' + v.nem]); }
                if (v.ruzgar !== null && v.ruzgar !== undefined) { ayrinti.push(['Rüzgâr', v.ruzgar + ' km/s']); }
                alan('ayrinti').innerHTML = ayrinti.map(function (a) {
                    return '<div><dt>' + kacir(a[0]) + '</dt><dd>' + kacir(a[1]) + '</dd></div>';
                }).join('');
                alan('gunler').innerHTML = (v.gunler || []).map(function (g) {
                    return '<li title="' + kacir(g.durum) + '"><span class="gun">' + kacir(g.gun) + '</span>'
                        + g.simge_svg + '<span class="derece">' + kacir(g.en_yuksek) + '° <small>'
                        + kacir(g.en_dusuk) + '°</small></span></li>';
                }).join('');
                kutu.setAttribute('data-il', v.il);
                dugmeler.forEach(function (d) {
                    d.setAttribute('aria-pressed', d.getAttribute('data-hava-il') === v.il ? 'true' : 'false');
                });
            })
            .catch(function () { /* Gecici hata karti bozmasin. */ });
    }

    var kayitli = null;
    try { kayitli = localStorage.getItem('valentra-il'); } catch (e) {}

    if (kayitli && kayitli !== kutu.getAttribute('data-il')
        && document.querySelector('[data-hava-il="' + kayitli + '"]')) {
        getir(kayitli);
    }

    dugmeler.forEach(function (d) {
        d.addEventListener('click', function () {
            var il = d.getAttribute('data-hava-il');
            try { localStorage.setItem('valentra-il', il); } catch (e) {}
            getir(il);
        });
    });
});
</script>
