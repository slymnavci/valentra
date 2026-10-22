<?php
declare(strict_types=1);

/**
 * Ekonomi sayfasının üstündeki piyasa bölümü: canlı kartlar, dönem
 * grafikleri ve Borsa İstanbul şirketleri.
 *
 * Kartlar ve sirket tablosu 2 dakikada bir yenileniyor (api/piyasalar.php
 * ayni islevlerden HTML donduruyor). Buyuk grafikler yenilenmiyor:
 * gunluk kapanis serisi, gun icinde degismesi gereken bir sey yok ve
 * grafik betigi yeniden cizilen ogeye baglanmazdi.
 */

require_once __DIR__ . '/piyasalar.php';
require_once __DIR__ . '/piyasa.php';
require_once __DIR__ . '/grafik.php';

/** Canlı kartlar: değer, günlük değişim, gün içi çizgisi. */
function piyasa_kartlari_html(): string
{
    $kartlar = [];
    $zaman   = 0;

    foreach (piyasa_araclari() as $anahtar => $tanim) {
        $kayit = piyasa_seri($anahtar, '1d', 120);
        $veri  = $kayit['veri'];
        $zaman = max($zaman, $kayit['zaman']);

        $kartlar[$anahtar] = [
            'tanim'    => $tanim,
            'fiyat'    => $veri['fiyat'] ?? null,
            'degisim'  => $veri['degisim'] ?? null,
            'noktalar' => $veri['noktalar'] ?? [],
        ];
    }

    /*
     * Gram altin degeri piyasa seridiyle AYNI kaynaktan (BigPara /
     * Doviz.com, bkz. piyasa_altin): iki yerde iki farkli gram fiyati
     * gormek okuru sasirtir. Gun ici cizgisi ons x dolardan.
     */
    try {
        $serit = piyasa_verisi();

        if (($serit['altin'] ?? null) !== null) {
            $kartlar['altin']['fiyat']   = (float) $serit['altin'];
            $kartlar['altin']['degisim'] = $serit['altin_degisim'] ?? $kartlar['altin']['degisim'];
        }
    } catch (Throwable $e) {
        error_log('[valentra] piyasa seridi okunamadi: ' . $e->getMessage());
    }

    if (array_filter($kartlar, static fn (array $k): bool => $k['fiyat'] !== null) === []) {
        return '';
    }

    ob_start();
    ?>
    <div class="piyasa-kartlar" data-canli-bolum="/api/piyasalar.php?bolum=kartlar" data-canli-aralik="120">
        <?php foreach ($kartlar as $anahtar => $k): ?>
            <div class="piyasa-kart">
                <span class="pk-ad"><?= e($k['tanim']['ad']) ?></span>
                <span class="pk-deger"><?= $k['tanim']['birim'] === '$' ? '$' : '' ?><?=
                    e(piyasa_sayi($k['fiyat'], $k['tanim']['hane'])) ?><?= $k['tanim']['birim'] === '₺' ? ' ₺' : '' ?></span>
                <?= piyasa_degisim_html($k['degisim']) ?>
                <?= piyasa_kivrim($k['noktalar'], $k['degisim']) ?>
            </div>
        <?php endforeach; ?>
        <p class="canli-not piyasa-kart-not">
            Gün içi · güncelleme <?= e(canli_zaman_yazi($zaman)) ?> · Kaynak: Yahoo Finance
            (borsa verisi 15 dakikaya kadar gecikmeli olabilir)
        </p>
    </div>
    <?php

    return (string) ob_get_clean();
}

/** Dönem grafikleri (3 ay / 1 yıl / 5 yıl). */
function piyasa_grafikleri_html(string $donem): string
{
    $donemler = piyasa_donemleri();
    $donem    = isset($donemler[$donem]) ? $donem : '3a';
    $tanim    = $donemler[$donem];
    $grafikler = '';

    foreach (piyasa_araclari() as $anahtar => $arac) {
        $veri = piyasa_seri($anahtar, $tanim['aralik'], $tanim['sure'])['veri'];

        if ($veri === null || count($veri['noktalar']) < 2) {
            continue;
        }

        // Grafik gunluk calisiyor: zaman damgasi -> Istanbul tarihi. Ayni
        // gune dusen iki nokta olursa sonuncusu kalir.
        $gunler = [];

        foreach ($veri['noktalar'] as [$t, $d]) {
            $gunler[date('Y-m-d', $t)] = $d;
        }

        $seri = [];

        foreach ($gunler as $gun => $d) {
            $seri[] = [$gun, $d];
        }

        $grafikler .= '<div class="piyasa-grafik">' . grafik_ciz($seri, [
            'baslik'   => $arac['ad'],
            'ad'       => $arac['ad'],
            'birim'    => $arac['birim'] === '₺' ? 'tl' : ($arac['birim'] === '$' ? 'usd' : ''),
            'kaynak'   => 'Yahoo Finance',
            'sifirdan' => false,
            'hane'     => $arac['hane'],
        ]) . '</div>';
    }

    // Varsayilan donemde de veri yoksa bolum hic basilmiyor; baska bir
    // donem seciliyken veri yoksa secici kalsin ki geri donulebilsin.
    if ($grafikler === '' && $donem === '3a') {
        return '';
    }

    if ($grafikler === '') {
        $grafikler = '<p class="canli-not">Bu dönemin verisi henüz alınamadı; birkaç dakika sonra yeniden deneyin.</p>';
    }

    ob_start();
    ?>
    <div class="piyasa-donem">
        <nav class="donem-secici" aria-label="Grafik dönemi">
            <?php foreach ($donemler as $kod => $d): ?>
                <a href="?donem=<?= e($kod) ?>#piyasa-grafikleri"<?= $kod === $donem ? ' aria-current="true"' : '' ?>><?= e($d['ad']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="piyasa-grafikler" id="piyasa-grafikleri"><?= $grafikler ?></div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/** Borsa İstanbul'un büyük şirketleri: fiyat ve günlük değişim. */
function piyasa_sirketler_html(): string
{
    $kayit   = piyasa_sirket_fiyatlari();
    $fiyatlar = $kayit['veri'];

    if ($fiyatlar === null) {
        return '';
    }

    $satirlar = [];

    foreach (piyasa_sirketleri() as $kod => $ad) {
        if (isset($fiyatlar[$kod])) {
            $satirlar[] = ['kod' => $kod, 'ad' => $ad] + $fiyatlar[$kod];
        }
    }

    // En cok yukselen ustte: "bugun ne oldu" sorusunun cevabi.
    usort($satirlar, static fn (array $a, array $b): int => ($b['degisim'] ?? 0) <=> ($a['degisim'] ?? 0));

    ob_start();
    ?>
    <div class="piyasa-sirketler" data-canli-bolum="/api/piyasalar.php?bolum=sirketler" data-canli-aralik="120">
        <h3>Borsa İstanbul — Büyük Şirketler</h3>
        <div class="tablo-kaydir">
            <table class="sirket-tablosu">
                <thead>
                    <tr>
                        <th scope="col">Şirket</th>
                        <th scope="col" class="kod">Kod</th>
                        <th scope="col" class="sayi">Son Fiyat</th>
                        <th scope="col" class="sayi">Günlük</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($satirlar as $s): ?>
                        <tr>
                            <td><?= e($s['ad']) ?></td>
                            <td class="kod"><?= e($s['kod']) ?></td>
                            <td class="sayi"><?= e(piyasa_sayi($s['fiyat'], 2)) ?> ₺</td>
                            <td class="sayi"><?= piyasa_degisim_html($s['degisim']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="canli-not">
            Güncelleme <?= e(canli_zaman_yazi($kayit['zaman'])) ?> · Kaynak: Yahoo Finance · 15 dakikaya kadar
            gecikmeli olabilir. Yatırım tavsiyesi değildir.
        </p>
    </div>
    <?php

    return (string) ob_get_clean();
}

function piyasa_paneli_html(string $donem): string
{
    $kartlar   = piyasa_kartlari_html();
    $grafikler = piyasa_grafikleri_html($donem);
    $sirketler = piyasa_sirketler_html();

    if ($kartlar === '' && $grafikler === '' && $sirketler === '') {
        return '';
    }

    return '<section class="piyasa-panel" aria-label="Piyasalar">'
        . '<div class="canli-panel-ust"><h2>Piyasalar</h2></div>'
        . $kartlar . $grafikler . $sirketler
        . '</section>';
}
