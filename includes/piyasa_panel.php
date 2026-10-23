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

/*
 * Piyasa bolumleri: her biri AYRI sayfa (site sahibinin istegi,
 * 23.10.2026: "BIST 100, sonra doviz, altin gibi bilgileri ve grafikleri
 * ayri sayfalardan kullanicinin analiz edecegi sekilde yayinlayalim").
 * Adres piyasalar.php?bolum=<anahtar>; bolumsuz adres hepsini birden
 * gosteren genel bakis olarak kaliyor.
 *
 * @return array<string,array{ad:string,baslik:string,aciklama:string,araclar:list<string>,sirketler?:bool,gostergeler?:bool}>
 */
function piyasa_bolumleri(): array
{
    return [
        'bist' => [
            'ad'       => 'BIST 100',
            'baslik'   => 'BIST 100 ve Borsa İstanbul Şirketleri',
            'aciklama' => 'BIST 100 endeksinin gün içi ve dönem grafikleri, dönem analizi ve büyük şirketlerin fiyatları.',
            'araclar'  => ['bist'],
            'sirketler' => true,
        ],
        'doviz' => [
            'ad'       => 'Döviz',
            'baslik'   => 'Döviz: Dolar ve Euro',
            'aciklama' => 'Dolar/TL ve Euro/TL: anlık kur, gün içi hareket, 3 ay, 1 yıl ve 5 yıllık grafikler ve dönem analizi.',
            'araclar'  => ['usd', 'eur'],
        ],
        'altin' => [
            'ad'       => 'Altın',
            'baslik'   => 'Altın: Gram ve Ons',
            'aciklama' => 'Gram ve ons altın: anlık fiyat, dönem grafikleri ve dönem analizi.',
            'araclar'  => ['altin', 'ons'],
        ],
        'petrol' => [
            'ad'       => 'Petrol',
            'baslik'   => 'Brent Petrol',
            'aciklama' => 'Brent petrol: anlık fiyat, dönem grafikleri ve dönem analizi.',
            'araclar'  => ['brent'],
        ],
        'gostergeler' => [
            'ad'       => 'Ekonomik Göstergeler',
            'baslik'   => 'Ekonomik Göstergeler',
            'aciklama' => 'Enflasyon, politika faizi, kurlar ve diğer resmî göstergelerin grafikleri (TCMB EVDS ve diğer resmî kaynaklar).',
            'araclar'  => [],
            'gostergeler' => true,
        ],
    ];
}

function piyasa_bolum_yolu(string $bolum, array $ek = []): string
{
    $parametre = array_filter(['bolum' => $bolum] + $ek, static fn ($d): bool => $d !== '');

    return piyasalar_yolu() . ($parametre === [] ? '' : '?' . http_build_query($parametre));
}

/**
 * Canlı kartlar: değer, günlük değişim, gün içi çizgisi.
 *
 * @param list<string> $sadece Yalnizca bu araclar (bos: hepsi). Canli
 *        yenileme ayni listeyle istiyor; yoksa bolum sayfasindaki kartlar
 *        iki dakika sonra butun araclarla yer degistirirdi.
 */
function piyasa_kartlari_html(array $sadece = []): string
{
    $kartlar = [];
    $zaman   = 0;

    foreach (piyasa_araclari() as $anahtar => $tanim) {
        if ($sadece !== [] && !in_array($anahtar, $sadece, true)) {
            continue;
        }

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

        if (isset($kartlar['altin']) && ($serit['altin'] ?? null) !== null) {
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
    <div class="piyasa-kartlar" data-canli-bolum="/api/piyasalar.php?<?= e(http_build_query(array_filter(['bolum' => 'kartlar', 'araclar' => implode(',', $sadece)]))) ?>" data-canli-aralik="120">
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

/**
 * Dönem grafikleri (3 ay / 1 yıl / 5 yıl).
 *
 * @param list<string> $sadece Yalnizca bu araclar (bos: hepsi).
 * @param string       $bolum  Bolum sayfasindaysa donem bagi o bolumde kalsin.
 * @param bool         $analiz Her grafigin altinda donem analizi tablosu.
 */
function piyasa_grafikleri_html(string $donem, array $sadece = [], string $bolum = '', bool $analiz = false): string
{
    $donemler = piyasa_donemleri();
    $donem    = isset($donemler[$donem]) ? $donem : '3a';
    $tanim    = $donemler[$donem];
    $grafikler = '';

    foreach (piyasa_araclari() as $anahtar => $arac) {
        if ($sadece !== [] && !in_array($anahtar, $sadece, true)) {
            continue;
        }

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
        ]) . ($analiz ? piyasa_donem_analizi_html($seri, $arac, (string) $tanim['ad']) : '') . '</div>';
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
                <a href="?<?= e(http_build_query(array_filter(['bolum' => $bolum, 'donem' => $kod]))) ?>#piyasa-grafikleri"<?= $kod === $donem ? ' aria-current="true"' : '' ?>><?= e($d['ad']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php // Analizli (bolum) sayfada her grafik tam genislikte: analiz kutulari tek satira sigsin. ?>
        <div class="piyasa-grafikler<?= $analiz ? ' analizli' : '' ?>" id="piyasa-grafikleri"><?= $grafikler ?></div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Dönem analizi: dönem başı ve sonu, değişim, en yüksek, en düşük,
 * ortalama ve günlük oynaklık. Seri grafikle AYNI (gunluk kapanis).
 *
 * @param list<array{0:string,1:float}> $seri
 */
function piyasa_donem_analizi_html(array $seri, array $arac, string $donemAdi): string
{
    if (count($seri) < 2) {
        return '';
    }

    $degerler = array_map(static fn (array $n): float => (float) $n[1], $seri);
    $ilk      = $degerler[0];
    $son      = $degerler[count($degerler) - 1];
    $enYuksek = max($degerler);
    $enDusuk  = min($degerler);
    $yuksekGun = $seri[array_search($enYuksek, $degerler, true)][0];
    $dusukGun  = $seri[array_search($enDusuk, $degerler, true)][0];

    // Gunluk getirilerin standart sapmasi (%): ne kadar dalgali.
    $getiriler = [];

    for ($i = 1, $n = count($degerler); $i < $n; $i++) {
        if ($degerler[$i - 1] != 0.0) {
            $getiriler[] = ($degerler[$i] / $degerler[$i - 1] - 1) * 100;
        }
    }

    $oynaklik = null;

    if (count($getiriler) >= 2) {
        $ort      = array_sum($getiriler) / count($getiriler);
        $oynaklik = sqrt(array_sum(array_map(static fn (float $g): float => ($g - $ort) ** 2, $getiriler)) / (count($getiriler) - 1));
    }

    $hane  = (int) $arac['hane'];
    $deger = static fn (float $d): string => ($arac['birim'] === '$' ? '$' : '') . piyasa_sayi($d, $hane)
        . ($arac['birim'] === '₺' ? ' ₺' : '');
    $gun   = static fn (string $t): string => date('d.m.Y', (int) strtotime($t));

    $satirlar = [
        ['Dönem başı (' . $gun($seri[0][0]) . ')', $deger($ilk)],
        ['Son (' . $gun($seri[count($seri) - 1][0]) . ')', $deger($son)],
        ['Dönem değişimi', piyasa_degisim_html($ilk != 0.0 ? ($son / $ilk - 1) * 100 : null)],
        ['En yüksek (' . $gun($yuksekGun) . ')', $deger($enYuksek)],
        ['En düşük (' . $gun($dusukGun) . ')', $deger($enDusuk)],
        ['Ortalama', $deger(array_sum($degerler) / count($degerler))],
    ];

    if ($oynaklik !== null) {
        $satirlar[] = ['Günlük oynaklık', '%' . piyasa_sayi($oynaklik, 2)];
    }

    $html = '<section class="piyasa-analiz"><h4>' . e($arac['ad']) . ' — ' . e($donemAdi) . ' analizi</h4><dl class="pa-kutular">';

    foreach ($satirlar as [$ad, $hucre]) {
        // $hucre ya duz sayi metni ya piyasa_degisim_html ciktisi (HTML).
        $html .= '<div class="pa-kutu"><dt>' . e($ad) . '</dt><dd>' . (str_starts_with($hucre, '<') ? $hucre : e($hucre)) . '</dd></div>';
    }

    return $html . '</dl></section>';
}

/**
 * "Ekonomik Gündem": Ekonomi haberleri sayfasında haberlerin ALTINDA,
 * piyasa bolumlerine baglantilar ve her birinin anlik degeri (site
 * sahibinin istegi, 23.10.2026: "en onde guncel haberler, sonra
 * istatistikler; ekonomik gundem olsun, oraya linkler koyarak").
 * Degerler onbellekten (piyasa_seri); veri yoksa yalnizca baglanti.
 */
function ekonomik_gundem_html(): string
{
    $araclar = piyasa_araclari();
    $ozet    = ['bist' => 'bist', 'doviz' => 'usd', 'altin' => 'altin', 'petrol' => 'brent'];
    $kartlar = '';

    foreach (piyasa_bolumleri() as $bolum => $b) {
        $satir = '';

        if (isset($ozet[$bolum])) {
            try {
                $veri = piyasa_seri($ozet[$bolum], '1d', 120)['veri'];
            } catch (Throwable $e) {
                $veri = null;
            }

            if (($veri['fiyat'] ?? null) !== null) {
                $arac  = $araclar[$ozet[$bolum]];
                $satir = '<span class="eg-deger">' . e($arac['ad']) . ': '
                    . ($arac['birim'] === '$' ? '$' : '') . e(piyasa_sayi((float) $veri['fiyat'], (int) $arac['hane']))
                    . ($arac['birim'] === '₺' ? ' ₺' : '') . '</span> '
                    . piyasa_degisim_html($veri['degisim'] ?? null);
            }
        }

        $kartlar .= '<a class="eg-kart" href="' . e(piyasa_bolum_yolu($bolum)) . '">'
            . '<strong>' . e($b['ad']) . '</strong>'
            . ($satir !== '' ? '<span class="eg-satir">' . $satir . '</span>' : '')
            . '<span class="eg-not">Grafikler ve analiz &rarr;</span></a>';
    }

    $kartlar .= '<a class="eg-kart" href="' . e(sirket_yolu()) . '"><strong>Şirket Analizi</strong>'
        . '<span class="eg-not">Bir şirketin 10 yıllık geçmişi, getirileri ve çarpanları &rarr;</span></a>';

    return '<section class="ekonomik-gundem" aria-labelledby="eg-baslik">'
        . '<div class="bolum-basligi"><h2 id="eg-baslik">Ekonomik Gündem</h2><span class="cizgi"></span></div>'
        . '<div class="eg-izgara">' . $kartlar . '</div></section>';
}

/**
 * Piyasa ve şirket sayfalarının altında güncel ekonomi haberleri (site
 * sahibinin istegi, 23.10.2026: "bosluklari gostermeyelim, dolu dolu
 * sayfalar"). 12 kart: haber izgarasinin sutun sayilarinin (4, 3, 2, 1)
 * hepsine bolunuyor, son satir bos kalmiyor.
 */
function ekonomi_haberleri_html(int $adet = 12): string
{
    $kategori = kategori_slug_bul('ekonomi');

    if ($kategori === null) {
        return '';
    }

    $izgaraHaberleri = haber_kategoride((int) $kategori['id'], $adet, 0);

    if ($izgaraHaberleri === []) {
        return '';
    }

    ob_start();
    ?>
    <section class="ekonomi-haberleri" aria-labelledby="eh-baslik">
        <div class="bolum-basligi">
            <h2 id="eh-baslik"><a href="<?= e(kategori_yolu('ekonomi')) ?>">Güncel Ekonomi Haberleri</a></h2>
            <span class="cizgi"></span>
        </div>
        <?php require __DIR__ . '/kart_izgara.php'; ?>
    </section>
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
    <div class="piyasa-sirketler" id="borsa" data-canli-bolum="/api/piyasalar.php?bolum=sirketler" data-canli-aralik="120">
        <h3>Borsa İstanbul — Büyük Şirketler</h3>
        <?php
        /*
         * Sik izgara (site sahibinin istegi, 23.10.2026: "cok genis
         * gorunuyor; kullanici analiz yapacak, hepsini bir arada gormesi
         * daha uygun; sirketler tiklanabilir olmali"). Onceden 30 satirlik
         * tek tabloydu. Her kutu sirket sayfasina gidiyor; en cok yukselen
         * basta.
         */
        ?>
        <ul class="sirket-izgara">
            <?php foreach ($satirlar as $s): ?>
                <li>
                    <a href="<?= e(sirket_yolu($s['kod'])) ?>" title="<?= e($s['ad']) ?> — 10 yıllık geçmiş ve analiz">
                        <span class="si-kod"><?= e($s['kod']) ?></span>
                        <?= piyasa_degisim_html($s['degisim']) ?>
                        <span class="si-fiyat"><?= e(piyasa_sayi($s['fiyat'], 2)) ?> ₺</span>
                        <span class="si-ad"><?= e($s['ad']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="canli-not">
            Şirketin 10 yıllık geçmişi, getirileri ve çarpanları için kutusuna tıklayın.
            Güncelleme <?= e(canli_zaman_yazi($kayit['zaman'])) ?> · Kaynak: Yahoo Finance · 15 dakikaya kadar
            gecikmeli olabilir. Yatırım tavsiyesi değildir.
        </p>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Ekonomik göstergeler: panelden yayına alınan grafikler (TCMB EVDS,
 * Dünya Bankası, IMF: enflasyon, politika faizi, büyüme...).
 *
 * Ana sayfadaki "Ekonomik Gostergeler" bolumu Ekim 2026'da kaldirildi
 * (site sahibinin karari); grafikler panelde duruyordu. Piyasalar
 * sayfasinda YAYINDAKI hepsi gosteriliyor (ana sayfa isaretine
 * bakilmadan). Bayat seriler sayfa gonderildikten sonra tazeleniyor.
 */
function piyasa_gostergeleri_html(): string
{
    require_once __DIR__ . '/grafikler.php';

    try {
        $satirlar = db()->query(
            'SELECT * FROM grafikler
              WHERE yayinda = 1 AND seri IS NOT NULL
              ORDER BY sira, id'
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] gosterge grafikleri okunamadi: ' . $e->getMessage());

        return '';
    }

    grafik_sayfa_sonunda_tazele();

    $kartlar = '';

    foreach ($satirlar as $g) {
        $seri = pratik_seri_oku((string) $g['seri']);

        if (count($seri) < 2) {
            continue;
        }

        $guncellik = grafik_guncellik($g);

        $kartlar .= '<article class="ana-grafik-kart"><h3>' . e((string) $g['baslik']) . '</h3>'
            . grafik_ciz($seri, grafik_ciz_ayari($g))
            . (!$guncellik['guncel'] ? '<p class="grafik-bayat" role="note">' . e($guncellik['mesaj']) . '</p>' : '')
            . '</article>';
    }

    if ($kartlar === '') {
        return '';
    }

    return '<section class="ana-grafikler" id="gostergeler" aria-labelledby="gosterge-baslik">'
        . '<div class="bolum-basligi"><h2 id="gosterge-baslik">Ekonomik Göstergeler</h2><span class="cizgi"></span></div>'
        . '<div class="ana-grafikler-izgara">' . $kartlar . '</div>'
        . '</section>';
}
