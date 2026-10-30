<?php
declare(strict_types=1);

/**
 * Ana sayfa vitrinindeki hava durumu kartı: İstanbul, Ankara, İzmir;
 * başlığın yanındaki listeden 30 büyükşehirden biri seçilebilir.
 *
 * Site sahibinin istegi (24.10.2026): "ana sayfada hava durumu olarak
 * Trabzon'u veriyor; birkac buyuk sehri ver sigacagi kadar; Istanbul,
 * Ankara, Izmir olsun". Onceden tek bir secili sehir gosteriliyordu ve
 * secim tarayicida kaliyordu (sehir seridinde Trabzon'a tiklanmisti);
 * artik secim yok, uc sehir her zaman alt alta: anlik sicaklik, durum,
 * gunun en yuksek/en dusuk sicakligi. Veri buyuk sehirler isteginden
 * (tek cagri, 30 dakika onbellek; bkz. hava_buyuk_sehirler). Diger
 * sehirler piyasa seridinin yanindaki seritte. Kaynaga ulasilamazsa
 * kart basilmiyor.
 */

require_once __DIR__ . '/hava.php';

const HAVA_KART_SEHIRLERI = ['istanbul', 'ankara', 'izmir'];

/*
 * SEHIR LISTESI (site sahibi, 24.10.2026: "liste yap ve sadece buyuk
 * sehirleri listede goster; kutularin boyutlariyla oynama"). Baslik
 * satirinin sagindaki listede 30 buyuksehir. Secilen sehir kartin ilk
 * satirina geciyor, altinda Istanbul, Ankara, Izmir'den ikisi; kart yine
 * uc satir, boyu degismiyor. Secim tarayicida kaliyor (localStorage).
 * 30 sehrin verisi tek istekle (hava_sehirler, 30 dakika onbellek);
 * satirlar sayfada hazir, liste yalnizca hangilerinin gorunecegini
 * degistiriyor.
 */
try {
    $tumSehirler = hava_sehirler(HAVA_BUYUKSEHIRLER, 'hava_buyuksehirler');
} catch (Throwable $e) {
    $tumSehirler = [];
}

// 30 sehirlik istek dusmusse eski uc sehirlik kart (secim listesiz).
if ($tumSehirler === []) {
    try {
        $tumSehirler = array_values(array_filter(
            hava_buyuk_sehirler(),
            static fn (array $s): bool => in_array($s['il'], HAVA_KART_SEHIRLERI, true)
        ));
    } catch (Throwable $e) {
        $tumSehirler = [];
    }
}

if ($tumSehirler === []) {
    return;
}

$secilebilir = count($tumSehirler) > count(HAVA_KART_SEHIRLERI);
// HAVA_BUYUKSEHIRLER zaten Turkce alfabetik; sunucunun dil ayarina
// (strcoll) guvenilmiyor, Istanbul en sona dusuyordu.
$sirali = $tumSehirler;
?>
<section class="pencere hava-kart" aria-labelledby="hava-baslik">
    <h2 class="pencere-baslik" id="hava-baslik">Hava Durumu</h2>

    <?php if ($secilebilir): ?>
        <label class="hava-sec">
            <span class="gizli-etiket">Şehir seçin</span>
            <select id="hava-sec">
                <?php foreach ($sirali as $sehir): ?>
                    <option value="<?= e($sehir['il']) ?>"<?= $sehir['il'] === HAVA_KART_SEHIRLERI[0] ? ' selected' : '' ?>><?= e($sehir['ad']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>

    <ul class="hava-kart-sehirler" id="hava-kart-sehirler">
        <?php foreach ($tumSehirler as $sehir): ?>
            <?php $sira = array_search($sehir['il'], HAVA_KART_SEHIRLERI, true); ?>
            <li data-il="<?= e($sehir['il']) ?>"<?= $sira === false ? ' hidden' : ' style="order:' . (int) $sira . '"' ?>>
                <span class="hava-ikon"><?= hava_simge_svg($sehir['simge']) ?></span>
                <span class="hava-bilgi">
                    <span class="hava-ad"><?= e($sehir['ad']) ?></span>
                    <span class="hava-durum"><?= e($sehir['durum']) ?></span>
                    <?php if ($sehir['en_yuksek'] !== null && $sehir['en_dusuk'] !== null): ?>
                        <span class="hava-aralik" title="Bugün en yüksek / en düşük">↑ <?= (int) $sehir['en_yuksek'] ?>°&nbsp;&nbsp;↓ <?= (int) $sehir['en_dusuk'] ?>°</span>
                    <?php endif; ?>
                </span>
                <span class="hava-derece"><?= (int) $sehir['sicaklik'] ?>°</span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($secilebilir): ?>
        <script>
        (function () {
            var sec = document.getElementById('hava-sec');
            var liste = document.getElementById('hava-kart-sehirler');
            var temel = <?= json_encode(HAVA_KART_SEHIRLERI) ?>;

            if (!sec || !liste) { return; }

            // Secilen sehir ilk satirda, altinda temel sehirlerden ikisi.
            function goster(il) {
                var gorunen = [il].concat(temel.filter(function (t) { return t !== il; })).slice(0, 3);

                liste.querySelectorAll('li').forEach(function (li) {
                    var sira = gorunen.indexOf(li.getAttribute('data-il'));
                    li.hidden = sira < 0;
                    li.style.order = sira < 0 ? '' : String(sira);
                });
            }

            var kayitli = null;
            try { kayitli = localStorage.getItem('hava_il'); } catch (e) {}

            if (kayitli && liste.querySelector('li[data-il="' + kayitli + '"]')) {
                sec.value = kayitli;
                goster(kayitli);
            }

            sec.addEventListener('change', function () {
                goster(sec.value);
                try { localStorage.setItem('hava_il', sec.value); } catch (e) {}
            });
        })();
        </script>
    <?php endif; ?>
</section>
