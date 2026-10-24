<?php
declare(strict_types=1);

/**
 * Ana sayfa vitrinindeki hava durumu kartı: İstanbul, Ankara, İzmir.
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

try {
    $kartSehirleri = array_values(array_filter(
        hava_buyuk_sehirler(),
        static fn (array $s): bool => in_array($s['il'], HAVA_KART_SEHIRLERI, true)
    ));
} catch (Throwable $e) {
    $kartSehirleri = [];
}

if ($kartSehirleri === []) {
    return;
}
?>
<section class="pencere hava-kart" aria-labelledby="hava-baslik">
    <h2 class="pencere-baslik" id="hava-baslik">Hava Durumu</h2>

    <ul class="hava-kart-sehirler">
        <?php foreach ($kartSehirleri as $sehir): ?>
            <li>
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
</section>
