<?php
declare(strict_types=1);

/**
 * Zaman serisi grafiği: SVG çizgi + HTML etiketler.
 *
 * NEDEN BOYLE KURULDU
 *
 * 1) Etiketler SVG'nin ICINDE DEGIL. SVG viewBox ile olceklenir; 1000
 *    birim genisliginde cizilmis bir grafik telefonda 360 piksele
 *    indiginde icindeki 12 piksellik yazi 4 piksele iner. Bu yuzden
 *    SVG yalnizca cizgiyi ve alani tasiyor (preserveAspectRatio="none"
 *    ile kutuya esneyerek), eksen yazilari ve son deger ise yuzdeyle
 *    konumlanan HTML ogeleri. Yazi her genislikte gercek puntosunda.
 *
 * 2) Cizgi kalinligi esnemiyor: vector-effect="non-scaling-stroke".
 *    Yuvarlak isaretler de HTML'de, cunku esneyen SVG'de daire elipse
 *    donusur.
 *
 * 3) X ekseni ZAMAN olcekli, sira olcekli degil. Politika faizi
 *    serisi yalnizca degisim noktalarini tasiyor ve kararlar duzensiz
 *    araliklarla alinmis; noktalari esit aralikla dizmek iki ay ile
 *    sekiz ayi ayni uzunlukta gosterirdi.
 *
 * 4) Grafik JavaScript'siz TAM calisir: cizgi, eksenler, son deger ve
 *    tablo gorunumu sunucuda uretiliyor. Kucuk bir betik yalnizca
 *    uzerine gelince tarihi ve degeri gosteren imleci ekliyor; o
 *    olmadan da her deger tabloda okunabiliyor.
 *
 * 5) Tek seri: gosterge kutusu yok, basligi zaten ne cizildigini
 *    soyluyor. Renk tek; ayri tonlar yalnizca birden fazla seri icin
 *    gerekir.
 */

/** Plot alanının SVG koordinat boyutu (esnediği için oran önemsiz). */
const GRAFIK_EN   = 1000;
const GRAFIK_BOY  = 300;

/**
 * Seriyi çizer; gösterilecek HTML'i döndürür.
 *
 * @param list<array{0:string,1:float}> $seri    tarihe gore sirali ["Y-m-d", deger]
 * @param array{tur?:string,birim?:string,baslik?:string,kaynak?:string,ad?:string} $ayar
 */
function grafik_ciz(array $seri, array $ayar = []): string
{
    if (count($seri) < 2) {
        return '';
    }

    $tur     = ($ayar['tur'] ?? 'cizgi') === 'basamak' ? 'basamak' : 'cizgi';
    $birim   = (string) ($ayar['birim'] ?? '');
    $baslik  = (string) ($ayar['baslik'] ?? '');
    $kaynak  = (string) ($ayar['kaynak'] ?? '');
    $ad      = (string) ($ayar['ad'] ?? 'Değer');
    $gunluk  = $tur === 'basamak';

    // --- olcekler ----------------------------------------------------------

    $noktalar = [];

    foreach ($seri as [$tarih, $deger]) {
        $noktalar[] = ['t' => grafik_zaman((string) $tarih), 'tarih' => (string) $tarih,
                       'd' => (float) $deger];
    }

    $t0 = $noktalar[0]['t'];
    $t1 = $noktalar[count($noktalar) - 1]['t'];

    if ($t1 <= $t0) {
        return '';
    }

    $degerler = array_column($noktalar, 'd');
    $olcek    = grafik_y_olcegi(min($degerler), max($degerler));

    $x = static fn (int $t): float => ($t - $t0) / ($t1 - $t0) * GRAFIK_EN;
    $y = static fn (float $d): float
        => GRAFIK_BOY - ($d - $olcek['alt']) / ($olcek['ust'] - $olcek['alt']) * GRAFIK_BOY;

    $yuzdeX = static fn (float $v): string => grafik_sayi($v / GRAFIK_EN * 100);
    $yuzdeY = static fn (float $v): string => grafik_sayi($v / GRAFIK_BOY * 100);

    // --- yol ---------------------------------------------------------------

    $yol = '';

    foreach ($noktalar as $i => $n) {
        $px = grafik_sayi($x($n['t']));
        $py = grafik_sayi($y($n['d']));

        if ($i === 0) {
            $yol .= 'M' . $px . ' ' . $py;
        } elseif ($gunluk) {
            // Once yatay (onceki deger bu tarihe kadar gecerli), sonra dikey.
            $yol .= ' H' . $px . ' V' . $py;
        } else {
            $yol .= ' L' . $px . ' ' . $py;
        }
    }

    // Alan sifirdan baslar: alanin yuksekligi buyukluk ima eder ve sifir
    // disindaki bir tabandan cizmek farki oldugundan buyuk gosterirdi.
    $taban = grafik_sayi($y(max($olcek['alt'], min(0.0, $olcek['ust']))));
    $dolgu = $yol . ' L' . grafik_sayi($x($t1)) . ' ' . $taban
           . ' L' . grafik_sayi($x($t0)) . ' ' . $taban . ' Z';

    $izgara = '';

    foreach ($olcek['adimlar'] as $adim) {
        $iy = grafik_sayi($y($adim));
        $izgara .= '<line x1="0" x2="' . GRAFIK_EN . '" y1="' . $iy . '" y2="' . $iy
                 . '" vector-effect="non-scaling-stroke"/>';
    }

    // --- son deger etiketi ---------------------------------------------------

    $son    = $noktalar[count($noktalar) - 1];
    $onceki = $noktalar[count($noktalar) - 2];
    $sonY   = $y($son['d']) / GRAFIK_BOY * 100;

    /*
     * Etiket cizginin gelmedigi tarafa: son noktaya soldan gelen cizgi
     * yukaridan iniyorsa etiket altta, asagidan cikiyorsa ustte durur.
     * Kenara cok yakinsa kutudan tasmasin diye ters tarafa alinir.
     */
    $altta = $onceki['d'] > $son['d'];

    if ($sonY < 18) {
        $altta = true;
    } elseif ($sonY > 82) {
        $altta = false;
    }

    // --- tarayici icin veri --------------------------------------------------

    $betikVerisi = [
        'tur'   => $tur,
        'birim' => $birim,
        'alt'   => $olcek['alt'],
        'ust'   => $olcek['ust'],
        't0'    => $t0 * 1000,
        't1'    => $t1 * 1000,
        'n'     => array_map(static fn (array $n): array => [$n['t'] * 1000, $n['d']], $noktalar),
    ];

    $ozet = grafik_ozet($noktalar, $birim, $ad, $gunluk);

    // --- HTML ----------------------------------------------------------------

    ob_start();
    ?>
<figure class="grafik">
    <?php if ($baslik !== '' || $kaynak !== ''): ?>
        <figcaption class="grafik-ust">
            <?php if ($baslik !== ''): ?>
                <span class="grafik-baslik"><?= e($baslik) ?></span>
            <?php endif; ?>
            <span class="grafik-alt">
                <?= $kaynak !== '' ? 'Kaynak: ' . e($kaynak) . ' · ' : '' ?><span
                    class="grafik-tarih">son gözlem <?= e(grafik_tarih_uzun($son['tarih'], $gunluk)) ?></span>
            </span>
        </figcaption>
    <?php endif; ?>

    <div class="grafik-govde">
        <div class="grafik-y" aria-hidden="true">
            <?php foreach ($olcek['adimlar'] as $adim): ?>
                <span style="top:<?= $yuzdeY($y($adim)) ?>%"><?=
                    e(grafik_deger($adim, $birim, $olcek['hane'])) ?></span>
            <?php endforeach; ?>
        </div>

        <div class="grafik-alan" tabindex="0" role="img"
             aria-label="<?= e($ozet) ?>"
             data-grafik="<?= e((string) json_encode($betikVerisi)) ?>">
            <svg class="grafik-svg" viewBox="0 0 <?= GRAFIK_EN ?> <?= GRAFIK_BOY ?>"
                 preserveAspectRatio="none" aria-hidden="true" focusable="false">
                <g class="grafik-izgara"><?= $izgara ?></g>
                <path class="grafik-dolgu" d="<?= $dolgu ?>"/>
                <path class="grafik-cizgi" d="<?= $yol ?>" vector-effect="non-scaling-stroke"/>
            </svg>

            <span class="grafik-nokta" style="left:100%;top:<?= grafik_sayi($sonY) ?>%"></span>
            <span class="grafik-son <?= $altta ? 'altta' : '' ?>"
                  style="left:100%;top:<?= grafik_sayi($sonY) ?>%"><?=
                e(grafik_deger($son['d'], $birim, 2)) ?></span>

            <span class="grafik-imlec" hidden></span>
            <span class="grafik-nokta grafik-imlec-nokta" hidden></span>
            <span class="grafik-ipucu" hidden><strong></strong><span></span></span>
        </div>

        <div class="grafik-x" aria-hidden="true">
            <?php foreach (grafik_x_isaretleri($t0, $t1) as $isaret): ?>
                <span style="left:<?= $yuzdeX($x($isaret['t'])) ?>%"><?= e($isaret['etiket']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <?= grafik_tablo($noktalar, $birim, $ad, $gunluk) ?>
</figure>
    <?php

    return (string) ob_get_clean() . grafik_varliklar();
}

/**
 * Y ekseni: sıfırı içeren, temiz adımlı ölçek.
 *
 * Sifir her zaman olcekte: faiz ve enflasyon oran; ekseni 35'ten
 * baslatmak 37 ile 50 arasindaki farki olduğundan kat kat buyuk
 * gosterirdi. Eksi deger varsa olcek asagi uzuyor.
 *
 * @return array{alt:float,ust:float,adimlar:list<float>,hane:int}
 */
function grafik_y_olcegi(float $enAz, float $enCok): array
{
    $alt = min(0.0, $enAz);
    $ust = max(0.0, $enCok);

    if ($ust - $alt < 1e-9) {
        $ust = $alt + 1.0;
    }

    $adim = grafik_guzel_adim($ust - $alt);
    $alt  = floor($alt / $adim) * $adim;
    $ust  = ceil($ust / $adim) * $adim;

    $adimlar = [];

    for ($v = $alt; $v <= $ust + $adim / 2; $v += $adim) {
        $adimlar[] = round($v, 6);
    }

    // 2,5 gibi kesirli adimda tum etiketler bir ondalik tasir; tutarsiz
    // hane ("%2,5 %5 %7,5") gozu yorar.
    $hane = abs($adim - round($adim)) > 1e-9 ? 1 : 0;

    return ['alt' => $alt, 'ust' => $ust, 'adimlar' => $adimlar, 'hane' => $hane];
}

/** Aralığı yaklaşık dört temiz adıma böler (1, 2, 2,5, 5 × 10ⁿ). */
function grafik_guzel_adim(float $aralik, int $hedef = 4): float
{
    if ($aralik <= 0) {
        return 1.0;
    }

    $kaba = $aralik / $hedef;
    $us   = 10 ** floor(log10($kaba));

    foreach ([1, 2, 2.5, 5, 10] as $katsayi) {
        if ($katsayi * $us >= $kaba - 1e-12) {
            return $katsayi * $us;
        }
    }

    return 10 * $us;
}

/**
 * X ekseni işaretleri: ay başlarında, en fazla altı tane.
 *
 * Adim ayin katlari (1, 2, 3, 4, 6, 12) ve takvime hizali: 6 aylik
 * adimda Ocak ve Temmuz. Hizasiz isaretler ("Mar 2025, Eyl 2025")
 * okuyanin kafasinda yeniden hesap yaptirir.
 *
 * @return list<array{t:int,etiket:string}>
 */
function grafik_x_isaretleri(int $t0, int $t1): array
{
    $aylar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz',
              'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

    [$y0, $a0] = array_map('intval', explode('-', gmdate('Y-n', $t0)));
    [$y1, $a1] = array_map('intval', explode('-', gmdate('Y-n', $t1)));
    $ayFarki   = ($y1 - $y0) * 12 + ($a1 - $a0);

    $adim = 12;

    foreach ([1, 2, 3, 4, 6, 12] as $aday) {
        if ($ayFarki / $aday <= 5) {
            $adim = $aday;
            break;
        }
    }

    $isaretler = [];

    for ($yil = $y0, $ay = $a0; ($yil * 12 + $ay) <= ($y1 * 12 + $a1); ) {
        $t = gmmktime(0, 0, 0, $ay, 1, $yil);

        if ($t >= $t0 && $t <= $t1 && ($ay - 1) % $adim === 0) {
            $isaretler[] = [
                't'      => $t,
                'etiket' => $adim === 12 ? (string) $yil : $aylar[$ay - 1] . ' ' . $yil,
            ];
        }

        if (++$ay > 12) {
            $ay = 1;
            $yil++;
        }
    }

    return $isaretler;
}

/**
 * Tablo görünümü — grafiğin erişilebilir ikizi.
 *
 * Basamak grafiginde tablo yalnizca DEGISIM tarihlerini listeliyor:
 * "su tarihten itibaren su oran". Son gozlem, bir onceki degerle
 * ayniysa listeye girmiyor; yoksa o gun bir karar alinmis gibi okunurdu.
 *
 * @param list<array{t:int,tarih:string,d:float}> $noktalar
 */
function grafik_tablo(array $noktalar, string $birim, string $ad, bool $gunluk): string
{
    $satirlar = [];

    foreach ($noktalar as $i => $n) {
        $sonMu = $i === count($noktalar) - 1;

        if ($gunluk && $sonMu && $i > 0 && abs($n['d'] - $noktalar[$i - 1]['d']) < 1e-9) {
            continue;
        }

        $satirlar[] = $n;
    }

    // En yeni ustte: okuyan once guncel degeri arar.
    $satirlar = array_reverse($satirlar);

    ob_start();
    ?>
    <details class="grafik-tablo">
        <summary>Tablo olarak göster</summary>
        <table>
            <?php if ($gunluk): ?>
                <caption>Değerin değiştiği tarihler; her değer bir sonrakine kadar geçerlidir.</caption>
            <?php endif; ?>
            <thead>
                <tr>
                    <th scope="col"><?= $gunluk ? 'Geçerlilik başlangıcı' : 'Dönem' ?></th>
                    <th scope="col"><?= e($ad) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($satirlar as $n): ?>
                    <tr>
                        <td><?= e(grafik_tarih_uzun($n['tarih'], $gunluk)) ?><?php
                            /*
                             * Basamakta ilk satir bir karar degil, grafigin
                             * basladigi gun. Isaretlenmezse o gun faiz
                             * degismis gibi okunuyordu.
                             */
                            if ($gunluk && $n['t'] === $noktalar[0]['t']): ?>
                                <span class="grafik-not">(dönem başı)</span><?php endif; ?></td>
                        <td><?= e(grafik_deger($n['d'], $birim, 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php

    return (string) ob_get_clean();
}

/**
 * Ekran okuyucu için tek cümlelik özet.
 *
 * @param list<array{t:int,tarih:string,d:float}> $noktalar
 */
function grafik_ozet(array $noktalar, string $birim, string $ad, bool $gunluk): string
{
    $ilk      = $noktalar[0];
    $son      = $noktalar[count($noktalar) - 1];
    $degerler = array_column($noktalar, 'd');

    return $ad . ': ' . grafik_tarih_uzun($ilk['tarih'], $gunluk) . ' tarihinde '
         . grafik_deger($ilk['d'], $birim, 2) . ', '
         . grafik_tarih_uzun($son['tarih'], $gunluk) . ' tarihinde '
         . grafik_deger($son['d'], $birim, 2) . '. En yüksek '
         . grafik_deger(max($degerler), $birim, 2) . ', en düşük '
         . grafik_deger(min($degerler), $birim, 2) . '. Tüm değerler tablo görünümünde.';
}

/** Değeri Türkçe biçimde yazar ("%37,00"). */
function grafik_deger(float $deger, string $birim, int $hane): string
{
    $metin = number_format($deger, $hane, ',', '.');

    return $birim === 'yuzde' ? '%' . $metin : $metin;
}

/** "2026-09-17" -> "17 Eylül 2026" (günlük) ya da "Eylül 2026" (aylık). */
function grafik_tarih_uzun(string $tarih, bool $gunlu): string
{
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
              'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tarih, $e)) {
        return $tarih;
    }

    $ay = $aylar[(int) $e[2]] ?? $e[2];

    return $gunlu ? (int) $e[3] . ' ' . $ay . ' ' . $e[1] : $ay . ' ' . $e[1];
}

/** "Y-m-d" -> UTC gece yarısı zaman damgası. */
function grafik_zaman(string $tarih): int
{
    [$yil, $ay, $gun] = array_map('intval', explode('-', $tarih) + [0, 1, 1]);

    return (int) gmmktime(0, 0, 0, $ay, $gun, $yil);
}

/** Koordinatı kısaltır: SVG yolunda 12 ondalık gereksiz ağırlık. */
function grafik_sayi(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/**
 * Stil dosyası ve imleç betiği — sayfa başına BİR KEZ.
 *
 * Grafik ayni sayfada birden fazla kez cizilebilir; betik butun
 * grafikleri kendisi bulup bagliyor, ikinci kez yazilmasi ayni olaylari
 * iki kez baglardi.
 */
function grafik_varliklar(): string
{
    static $yazildi = false;

    if ($yazildi) {
        return '';
    }

    $yazildi = true;

    return '<link rel="stylesheet" href="' . e(varlik('/assets/grafik.css')) . '">'
         . "\n<script>\n" . GRAFIK_BETIK . "\n</script>\n";
}

/*
 * Imlec betigi.
 *
 * Yalnizca GOSTERIYOR, hicbir degeri gizlemiyor: tablo gorunumu ve son
 * deger etiketi betiksiz de orada. Metin textContent ile yaziliyor.
 *
 * Basamak grafiginde imlec fare nerede ise o GUNU gosterir ve deger o
 * gun gecerli olan orandir; en yakin noktaya ziplamaz, cunku noktalar
 * yalnizca karar gunleri ve aralarindaki her gunun de bir degeri var.
 * Cizgi grafiginde (aylik) en yakin aya kilitlenir.
 */
const GRAFIK_BETIK = <<<'JS'
(function () {
    var GUN = 86400000;
    var ayBicim  = new Intl.DateTimeFormat('tr-TR', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    var gunBicim = new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });

    function bicimle(d, birim) {
        var s = d.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return birim === 'yuzde' ? '%' + s : s;
    }

    document.querySelectorAll('.grafik-alan[data-grafik]').forEach(function (alan) {
        var v;

        try { v = JSON.parse(alan.getAttribute('data-grafik')); } catch (e) { return; }

        var n = v.n, basamak = v.tur === 'basamak', secili = n.length - 1;
        var imlec = alan.querySelector('.grafik-imlec');
        var nokta = alan.querySelector('.grafik-imlec-nokta');
        var ipucu = alan.querySelector('.grafik-ipucu');
        var ipDeger = ipucu.querySelector('strong');
        var ipTarih = ipucu.querySelector('span');

        function x(t) { return (t - v.t0) / (v.t1 - v.t0) * 100; }
        function y(d) { return (v.ust - d) / (v.ust - v.alt) * 100; }

        function goster(t, d) {
            var px = x(t), py = y(d);

            imlec.style.left = px + '%';
            nokta.style.left = px + '%';
            nokta.style.top  = py + '%';
            ipucu.style.left = px + '%';
            ipucu.style.top  = Math.min(85, Math.max(15, py)) + '%';
            ipucu.classList.toggle('sola', px > 60);

            ipDeger.textContent = bicimle(d, v.birim);
            ipTarih.textContent = (basamak ? gunBicim : ayBicim).format(new Date(t));

            imlec.hidden = nokta.hidden = ipucu.hidden = false;
        }

        function gizle() { imlec.hidden = nokta.hidden = ipucu.hidden = true; }

        function noktaya(i) { secili = i; goster(n[i][0], n[i][1]); }

        function izle(olay) {
            var kutu = alan.getBoundingClientRect();
            var oran = Math.min(1, Math.max(0, (olay.clientX - kutu.left) / kutu.width));
            var t    = v.t0 + oran * (v.t1 - v.t0);

            if (basamak) {
                t = Math.round(t / GUN) * GUN;

                var d = n[0][1];

                for (var i = 0; i < n.length && n[i][0] <= t; i++) { d = n[i][1]; secili = i; }

                goster(t, d);
                return;
            }

            var enYakin = 0, fark = Infinity;

            for (var j = 0; j < n.length; j++) {
                var f = Math.abs(n[j][0] - t);
                if (f < fark) { fark = f; enYakin = j; }
            }

            noktaya(enYakin);
        }

        alan.addEventListener('pointermove', izle);
        alan.addEventListener('pointerdown', izle);
        alan.addEventListener('pointerleave', gizle);
        alan.addEventListener('focus', function () { noktaya(secili); });
        alan.addEventListener('blur', gizle);

        // Klavye: oklar noktadan noktaya, Home/End uca.
        alan.addEventListener('keydown', function (olay) {
            var yeni = { ArrowLeft: secili - 1, ArrowRight: secili + 1, Home: 0, End: n.length - 1 }[olay.key];

            if (yeni === undefined) { return; }

            olay.preventDefault();
            noktaya(Math.min(n.length - 1, Math.max(0, yeni)));
        });
    });
})();
JS;
