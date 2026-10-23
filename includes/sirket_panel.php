<?php
declare(strict_types=1);

/**
 * Şirket sayfasının ve Ekonomi sayfasının borsa parçaları: şirket
 * seçici, BIST 100 grafiği, seriden grafik.
 */

require_once __DIR__ . '/sirket.php';
require_once __DIR__ . '/grafik.php';

/**
 * Zaman damgalı seriyi grafiğin istediği güne çevirir; aynı güne düşen
 * iki noktadan sonuncusu kalır.
 *
 * @param list<array{0:int,1:float}> $noktalar
 * @return list<array{0:string,1:float}>
 */
function sirket_grafik_serisi(array $noktalar): array
{
    $gunler = [];

    foreach ($noktalar as [$t, $d]) {
        $gunler[date('Y-m-d', (int) $t)] = (float) $d;
    }

    $seri = [];

    foreach ($gunler as $gun => $d) {
        $seri[] = [$gun, $d];
    }

    return $seri;
}

/**
 * Şirket seçici: seçkideki şirketler listesi ve serbest kod alanı.
 * Betiksiz calisiyor (GET formu); liste secilince betik formu gonderiyor.
 * Yazilan kod ("ara") listedekinden onde: sirket.php once ona bakiyor.
 */
function sirket_secici_html(string $secili = ''): string
{
    // Liste 60 sirket: ada gore, Turkce alfabeyle (intl yoksa duz sira).
    $sirketler = piyasa_sirketleri();

    if (class_exists('Collator')) {
        (new Collator('tr_TR'))->asort($sirketler);
    } else {
        asort($sirketler);
    }

    ob_start();
    ?>
    <form class="sirket-secici" action="/sirket.php" method="get" role="search" aria-label="Şirket seç">
        <label for="sirket-sec">Şirket</label>
        <select id="sirket-sec" name="kod" onchange="if (this.value) this.form.submit()">
            <option value="">Seçin…</option>
            <?php foreach ($sirketler as $kod => $ad): ?>
                <option value="<?= e($kod) ?>"<?= $kod === $secili ? ' selected' : '' ?>><?= e($ad) ?> (<?= e($kod) ?>)</option>
            <?php endforeach; ?>
        </select>
        <span class="sirket-secici-ya">ya da kod</span>
        <input type="text" name="ara" placeholder="ör. FROTO" maxlength="6"
               pattern="[A-Za-z0-9]{3,6}" autocapitalize="characters" aria-label="Borsa kodu">
        <button type="submit">Göster</button>
    </form>
    <?php

    return (string) ob_get_clean();
}

/**
 * BIST 100'ün son bir yılı (Ekonomi haberleri sayfası için) ve şirket
 * seçici. Veri yoksa yalnizca secici.
 */
function sirket_bist_bolumu_html(): string
{
    $kayit = piyasa_seri('bist', '1y', 3600);
    $veri  = $kayit['veri'];
    $grafik = '';

    if ($veri !== null && count($veri['noktalar']) >= 2) {
        $grafik = grafik_ciz(sirket_grafik_serisi($veri['noktalar']), [
            'baslik'   => 'BIST 100 — Son 1 Yıl',
            'ad'       => 'BIST 100',
            'kaynak'   => 'Yahoo Finance',
            'sifirdan' => false,
            'hane'     => 2,
        ]);
    }

    ob_start();
    ?>
    <section class="bist-bolumu" aria-label="BIST 100 ve şirketler">
        <?php if ($grafik !== ''): ?>
            <div class="piyasa-grafik"><?= $grafik ?></div>
        <?php endif; ?>
        <div class="bist-secici">
            <p><strong>Bir şirketin geçmişine bakın:</strong> 10 yıllık fiyat grafiği, getiriler,
                risk ölçüleri, çarpanlar, analist önerisi ve temettüler.</p>
            <?= sirket_secici_html() ?>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}
