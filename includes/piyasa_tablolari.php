<?php
declare(strict_types=1);

/**
 * Piyasa tabloları: döviz, pariteler, altın, borsa endeksleri, yükselen ve
 * düşen hisseler, emtia, kripto, dünya borsaları; döviz çevirici.
 *
 * Site sahibinin istegi (24.10.2026): "ekonomi sayfasini Hurriyet
 * BigPara'daki gibi yapalim; menulere bak, verilere bak, buna benzer
 * verileri cekelim". BigPara'nin bolumleri (Borsa, Doviz, Altin, Emtia,
 * Kripto, Dunya borsalari) cok kalemli tablolar halinde.
 *
 * Kaynak Yahoo Finance (sitenin diger piyasa verisiyle ayni). Her grup
 * TEK spark istegiyle (20 sembole kadar) aliniyor, 2 dakika onbellekte
 * (canli_onbellek: bayatsa eskisi gosterilip yanittan sonra tazeleniyor).
 * Kaynakta verisi olmayan sembol tabloda gorunmuyor. Tablolar sayfa
 * acikken api/piyasalar.php?bolum=tablo&tablo=... ile yenileniyor.
 */

require_once __DIR__ . '/piyasalar.php';

/** Tablo tanımları: başlık, semboller (sembol => [ad, kısa ad]), hane. */
function piyasa_tablo_tanimlari(): array
{
    return [
        'doviz' => [
            'baslik' => 'Döviz Kurları',
            'hane'   => 4,
            'birim'  => '₺',
            'semboller' => [
                'USDTRY=X' => ['ABD Doları', 'USD'],
                'EURTRY=X' => ['Euro', 'EUR'],
                'GBPTRY=X' => ['İngiliz Sterlini', 'GBP'],
                'CHFTRY=X' => ['İsviçre Frangı', 'CHF'],
                'JPYTRY=X' => ['Japon Yeni', 'JPY'],
                'SARTRY=X' => ['Suudi Riyali', 'SAR'],
                'AEDTRY=X' => ['BAE Dirhemi', 'AED'],
                'AUDTRY=X' => ['Avustralya Doları', 'AUD'],
                'CADTRY=X' => ['Kanada Doları', 'CAD'],
                'CNYTRY=X' => ['Çin Yuanı', 'CNY'],
                'RUBTRY=X' => ['Rus Rublesi', 'RUB'],
                'DKKTRY=X' => ['Danimarka Kronu', 'DKK'],
                'SEKTRY=X' => ['İsveç Kronu', 'SEK'],
                'NOKTRY=X' => ['Norveç Kronu', 'NOK'],
                'KWDTRY=X' => ['Kuveyt Dinarı', 'KWD'],
                'AZNTRY=X' => ['Azerbaycan Manatı', 'AZN'],
            ],
        ],
        'parite' => [
            'baslik' => 'Pariteler',
            'hane'   => 4,
            'birim'  => '',
            'semboller' => [
                'EURUSD=X' => ['Euro / Dolar', 'EUR/USD'],
                'GBPUSD=X' => ['Sterlin / Dolar', 'GBP/USD'],
                'USDJPY=X' => ['Dolar / Yen', 'USD/JPY'],
                'USDCHF=X' => ['Dolar / Frank', 'USD/CHF'],
                'AUDUSD=X' => ['Avustralya Doları / Dolar', 'AUD/USD'],
                'USDCAD=X' => ['Dolar / Kanada Doları', 'USD/CAD'],
            ],
        ],
        'endeks' => [
            'baslik' => 'Borsa İstanbul Endeksleri',
            'hane'   => 2,
            'birim'  => '',
            'semboller' => [
                'XU100.IS' => ['BIST 100', 'XU100'],
                'XU030.IS' => ['BIST 30', 'XU030'],
                'XU050.IS' => ['BIST 50', 'XU050'],
                'XBANK.IS' => ['BIST Banka', 'XBANK'],
                'XUSIN.IS' => ['BIST Sınai', 'XUSIN'],
                'XHOLD.IS' => ['BIST Holding ve Yatırım', 'XHOLD'],
                'XUMAL.IS' => ['BIST Mali', 'XUMAL'],
                'XUHIZ.IS' => ['BIST Hizmetler', 'XUHIZ'],
                'XUTEK.IS' => ['BIST Teknoloji', 'XUTEK'],
                'XELKT.IS' => ['BIST Elektrik', 'XELKT'],
                'XGIDA.IS' => ['BIST Gıda, İçecek', 'XGIDA'],
                'XULAS.IS' => ['BIST Ulaştırma', 'XULAS'],
            ],
        ],
        'emtia' => [
            'baslik' => 'Emtia',
            'hane'   => 2,
            'birim'  => '$',
            'semboller' => [
                'GC=F' => ['Altın (ons)', 'XAU'],
                'SI=F' => ['Gümüş (ons)', 'XAG'],
                'PL=F' => ['Platin (ons)', 'XPT'],
                'PA=F' => ['Paladyum (ons)', 'XPD'],
                'HG=F' => ['Bakır (libre)', 'HG'],
                'BZ=F' => ['Brent Petrol (varil)', 'BRENT'],
                'CL=F' => ['WTI Petrol (varil)', 'WTI'],
                'NG=F' => ['Doğal Gaz (MMBtu)', 'NG'],
                'ZW=F' => ['Buğday (buşel, sent)', 'BUĞDAY'],
                'ZC=F' => ['Mısır (buşel, sent)', 'MISIR'],
            ],
        ],
        'kripto' => [
            'baslik' => 'Kripto Paralar',
            'hane'   => 2,
            'birim'  => '$',
            'semboller' => [
                'BTC-USD'  => ['Bitcoin', 'BTC'],
                'ETH-USD'  => ['Ethereum', 'ETH'],
                'BNB-USD'  => ['BNB', 'BNB'],
                'SOL-USD'  => ['Solana', 'SOL'],
                'XRP-USD'  => ['XRP', 'XRP'],
                'ADA-USD'  => ['Cardano', 'ADA'],
                'DOGE-USD' => ['Dogecoin', 'DOGE'],
                'AVAX-USD' => ['Avalanche', 'AVAX'],
            ],
        ],
        'dunya' => [
            'baslik' => 'Dünya Borsaları',
            'hane'   => 2,
            'birim'  => '',
            'semboller' => [
                '^GSPC'     => ['S&P 500 (ABD)', 'S&P 500'],
                '^DJI'      => ['Dow Jones (ABD)', 'DOW'],
                '^IXIC'     => ['Nasdaq (ABD)', 'NASDAQ'],
                '^GDAXI'    => ['DAX (Almanya)', 'DAX'],
                '^FTSE'     => ['FTSE 100 (İngiltere)', 'FTSE'],
                '^FCHI'     => ['CAC 40 (Fransa)', 'CAC'],
                '^STOXX50E' => ['Euro Stoxx 50', 'SX5E'],
                '^N225'     => ['Nikkei 225 (Japonya)', 'NIKKEI'],
                '^HSI'      => ['Hang Seng (Hong Kong)', 'HSI'],
                '000001.SS' => ['Şanghay Bileşik (Çin)', 'SSE'],
            ],
        ],
    ];
}

/**
 * Bir tablonun anlık verisi (önbellekli): sembol => [fiyat, degisim].
 *
 * @return array{veri:?array<string,array{fiyat:float,degisim:?float}>,zaman:int}
 */
function piyasa_tablo_verisi(string $tablo): array
{
    $tanim = piyasa_tablo_tanimlari()[$tablo] ?? null;

    if ($tanim === null) {
        return ['veri' => null, 'zaman' => 0];
    }

    return canli_onbellek('tablo_' . $tablo, 120, static function () use ($tanim): ?array {
        $sonuc = [];

        foreach (array_chunk(array_keys($tanim['semboller']), 20) as $parca) {
            $ham = canli_indir(
                'https://query1.finance.yahoo.com/v8/finance/spark?symbols=' . rawurlencode(implode(',', $parca))
                . '&range=1d&interval=1d'
            );

            if ($ham !== null) {
                $sonuc += yahoo_spark_coz($ham, 6, false);
            }
        }

        return $sonuc !== [] ? $sonuc : null;
    });
}

/** Tek tablonun HTML'i; veri yoksa boş. */
function piyasa_tablo_html(string $tablo): string
{
    $tanim = piyasa_tablo_tanimlari()[$tablo] ?? null;
    $kayit = piyasa_tablo_verisi($tablo);
    $veri  = $kayit['veri'];

    if ($tanim === null || $veri === null) {
        return '';
    }

    $satirlar = [];

    foreach ($tanim['semboller'] as $sembol => [$ad, $kisa]) {
        if (isset($veri[$sembol])) {
            $satirlar[] = ['ad' => $ad, 'kisa' => $kisa, 'sembol' => $sembol] + $veri[$sembol];
        }
    }

    if ($satirlar === []) {
        return '';
    }

    // Kripto: TL karsiligi da (dolar kuru doviz tablosundan).
    $usd = $tablo === 'kripto' ? (piyasa_tablo_verisi('doviz')['veri']['USDTRY=X']['fiyat'] ?? null) : null;

    ob_start();
    ?>
    <section class="ptablo" id="tablo-<?= e($tablo) ?>" data-canli-bolum="/api/piyasalar.php?bolum=tablo&amp;tablo=<?= e($tablo) ?>" data-canli-aralik="120">
        <h3><?= e($tanim['baslik']) ?></h3>
        <table>
            <thead>
                <tr>
                    <th scope="col">Ad</th>
                    <th scope="col" class="sayi">Son</th>
                    <?php if ($usd !== null): ?><th scope="col" class="sayi">TL</th><?php endif; ?>
                    <th scope="col" class="sayi">Değişim</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($satirlar as $s): ?>
                    <tr<?= $tablo === 'doviz' ? ' data-kur="' . e($s['kisa']) . '" data-deger="' . e((string) $s['fiyat']) . '"' : '' ?>>
                        <th scope="row"><span class="pt-kisa"><?= e($s['kisa']) ?></span><span class="pt-ad"><?= e($s['ad']) ?></span></th>
                        <td class="sayi"><?= $tanim['birim'] === '$' ? '$' : '' ?><?= e(piyasa_sayi($s['fiyat'], piyasa_tablo_hane($s['fiyat'], $tanim['hane']))) ?></td>
                        <?php if ($usd !== null): ?>
                            <td class="sayi"><?= e(piyasa_sayi($s['fiyat'] * $usd, piyasa_tablo_hane($s['fiyat'] * $usd, 2))) ?> ₺</td>
                        <?php endif; ?>
                        <td class="sayi"><?= piyasa_degisim_html($s['degisim']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="canli-not">Güncelleme <?= e(canli_zaman_yazi($kayit['zaman'])) ?> · Kaynak: Yahoo Finance · gecikmeli olabilir</p>
    </section>
    <?php

    return (string) ob_get_clean();
}

/** Küçük değerlerde daha çok ondalık (ör. JPY 0,2681; DOGE 0,1532). */
function piyasa_tablo_hane(float $deger, int $hane): int
{
    $mutlak = abs($deger);

    if ($mutlak < 1) {
        return max($hane, 4);
    }

    return $mutlak >= 1000 ? min($hane, 2) : $hane;
}

/**
 * Altın ve gümüş fiyatları (TL): ons ve dolardan hesaplanan has değeri.
 *
 * Ceyrek, yarim, tam ve Cumhuriyet altininin icindeki has altin
 * miktarina gore (22 ayar: 916,6/1000). Kuyumcu fiyati iscilik ve kar
 * payi nedeniyle bundan YUKSEKTIR; tablo bunu acikca soyluyor.
 */
function piyasa_altin_tablosu_html(): string
{
    $emtia = piyasa_tablo_verisi('emtia');
    $doviz = piyasa_tablo_verisi('doviz');
    $ons   = $emtia['veri']['GC=F'] ?? null;
    $gumus = $emtia['veri']['SI=F'] ?? null;
    $usd   = $doviz['veri']['USDTRY=X'] ?? null;

    if ($ons === null || $usd === null) {
        return '';
    }

    $gram = $ons['fiyat'] * $usd['fiyat'] / PIYASA_ONS_GRAM;

    // Iki yuzdenin bileskesi: (1+a)(1+b)-1.
    $bileske = static fn (?float $a, ?float $b): ?float => ($a !== null && $b !== null)
        ? round(((1 + $a / 100) * (1 + $b / 100) - 1) * 100, 2) : null;
    $degisim = $bileske($ons['degisim'], $usd['degisim']);

    // Has altin miktari (gram): agirlik x 22/24.
    $kalemler = [
        ['Gram Altın (24 ayar)', 1.0],
        ['Çeyrek Altın', 1.75 * 22 / 24],
        ['Yarım Altın', 3.50 * 22 / 24],
        ['Tam Altın', 7.00 * 22 / 24],
        ['Cumhuriyet Altını', 7.216 * 22 / 24],
        ['22 Ayar Bilezik (gram)', 22 / 24],
        ['14 Ayar Altın (gram)', 14 / 24],
    ];

    ob_start();
    ?>
    <section class="ptablo" id="tablo-altin" data-canli-bolum="/api/piyasalar.php?bolum=tablo&amp;tablo=altin" data-canli-aralik="120">
        <h3>Altın Fiyatları</h3>
        <table>
            <thead>
                <tr><th scope="col">Ad</th><th scope="col" class="sayi">Has değeri</th><th scope="col" class="sayi">Değişim</th></tr>
            </thead>
            <tbody>
                <?php foreach ($kalemler as [$ad, $has]): ?>
                    <tr>
                        <th scope="row"><span class="pt-ad"><?= e($ad) ?></span></th>
                        <td class="sayi"><?= e(piyasa_sayi($gram * $has, 2)) ?> ₺</td>
                        <td class="sayi"><?= piyasa_degisim_html($degisim) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><span class="pt-ad">Ons Altın</span></th>
                    <td class="sayi">$<?= e(piyasa_sayi($ons['fiyat'], 2)) ?></td>
                    <td class="sayi"><?= piyasa_degisim_html($ons['degisim']) ?></td>
                </tr>
                <?php if ($gumus !== null): ?>
                    <tr>
                        <th scope="row"><span class="pt-ad">Gram Gümüş</span></th>
                        <td class="sayi"><?= e(piyasa_sayi($gumus['fiyat'] * $usd['fiyat'] / PIYASA_ONS_GRAM, 2)) ?> ₺</td>
                        <td class="sayi"><?= piyasa_degisim_html($bileske($gumus['degisim'], $usd['degisim'])) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="canli-not">Has altın karşılığı: ons × dolar ÷ 31,1035, işçilik hariç. Kuyumcu satış fiyatı işçilik ve kâr payıyla daha yüksektir.
            Güncelleme <?= e(canli_zaman_yazi(min($emtia['zaman'], $doviz['zaman']))) ?> · Kaynak: Yahoo Finance</p>
    </section>
    <?php

    return (string) ob_get_clean();
}

/** Borsa İstanbul: günün en çok yükselen ve düşen hisseleri (şirket listesinden). */
function piyasa_yukselen_dusen_html(int $adet = 8): string
{
    $kayit = piyasa_sirket_fiyatlari();
    $veri  = $kayit['veri'];

    if ($veri === null) {
        return '';
    }

    $satirlar = [];

    foreach (piyasa_sirketleri() as $kod => $ad) {
        if (isset($veri[$kod]) && $veri[$kod]['degisim'] !== null) {
            $satirlar[] = ['kod' => $kod, 'ad' => $ad] + $veri[$kod];
        }
    }

    if (count($satirlar) < 4) {
        return '';
    }

    usort($satirlar, static fn (array $a, array $b): int => $b['degisim'] <=> $a['degisim']);

    $yukselen = array_slice($satirlar, 0, $adet);
    $dusen    = array_slice(array_reverse($satirlar), 0, $adet);

    ob_start();
    ?>
    <div class="ptablo-cift" data-canli-bolum="/api/piyasalar.php?bolum=tablo&amp;tablo=yukselen" data-canli-aralik="120">
    <?php foreach (['Yükselenler' => $yukselen, 'Düşenler' => $dusen] as $baslik => $liste): ?>
    <section class="ptablo">
        <h3><?= e($baslik) ?></h3>
        <table>
            <thead><tr><th scope="col">Hisse</th><th scope="col" class="sayi">Son</th><th scope="col" class="sayi">Değişim</th></tr></thead>
            <tbody>
                <?php foreach ($liste as $s): ?>
                    <tr>
                        <th scope="row"><a href="<?= e(sirket_yolu($s['kod'])) ?>"><span class="pt-kisa"><?= e($s['kod']) ?></span><span class="pt-ad"><?= e($s['ad']) ?></span></a></th>
                        <td class="sayi"><?= e(piyasa_sayi($s['fiyat'], 2)) ?> ₺</td>
                        <td class="sayi"><?= piyasa_degisim_html($s['degisim']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="canli-not">BIST'in büyük <?= count(piyasa_sirketleri()) ?> şirketi arasından · Güncelleme <?= e(canli_zaman_yazi($kayit['zaman'])) ?></p>
    </section>
    <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

/** Döviz çevirici: kurlar döviz tablosundan, hesap tarayıcıda. */
function piyasa_cevirici_html(): string
{
    $veri = piyasa_tablo_verisi('doviz')['veri'];

    if ($veri === null) {
        return '';
    }

    $kurlar = ['TRY' => 1.0];

    foreach (piyasa_tablo_tanimlari()['doviz']['semboller'] as $sembol => [, $kisa]) {
        if (isset($veri[$sembol])) {
            $kurlar[$kisa] = $veri[$sembol]['fiyat'];
        }
    }

    ob_start();
    ?>
    <section class="ptablo pcevirici" id="cevirici">
        <h3>Döviz Çevirici</h3>
        <div class="cevirici-form" data-kurlar="<?= e((string) json_encode($kurlar)) ?>">
            <label>Tutar <input type="number" inputmode="decimal" min="0" step="any" value="100" data-cevir="tutar"></label>
            <label>Kaynak
                <select data-cevir="kaynak">
                    <?php foreach (array_keys($kurlar) as $kod): ?>
                        <option value="<?= e($kod) ?>"<?= $kod === 'USD' ? ' selected' : '' ?>><?= e($kod) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Hedef
                <select data-cevir="hedef">
                    <?php foreach (array_keys($kurlar) as $kod): ?>
                        <option value="<?= e($kod) ?>"<?= $kod === 'TRY' ? ' selected' : '' ?>><?= e($kod) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <output class="cevirici-sonuc" data-cevir="sonuc">–</output>
        </div>
        <p class="canli-not">Piyasa kuruyla yaklaşık karşılık; banka ve döviz bürosu kurları farklıdır.</p>
        <script>
        (function () {
            var kutu = document.currentScript.parentNode.querySelector('.cevirici-form');
            var kurlar = JSON.parse(kutu.getAttribute('data-kurlar'));
            function al(ad) { return kutu.querySelector('[data-cevir="' + ad + '"]'); }
            function hesapla() {
                var tutar = parseFloat(String(al('tutar').value).replace(',', '.')) || 0;
                var tl = tutar * (kurlar[al('kaynak').value] || 0);
                var sonuc = tl / (kurlar[al('hedef').value] || 1);
                al('sonuc').textContent = sonuc.toLocaleString('tr-TR', { maximumFractionDigits: sonuc < 1 ? 4 : 2 })
                    + ' ' + al('hedef').value;
            }
            ['tutar', 'kaynak', 'hedef'].forEach(function (ad) { al(ad).addEventListener('input', hesapla); });
            hesapla();
        })();
        </script>
    </section>
    <?php

    return (string) ob_get_clean();
}

/**
 * Adla tablo: tanimli tablolar, "altin", "yukselen", "cevirici".
 * Canli yenileme ucu (api/piyasalar.php) de bunu kullaniyor.
 */
function piyasa_tablo_parca_html(string $ad): string
{
    return match ($ad) {
        'altin'    => piyasa_altin_tablosu_html(),
        'yukselen' => piyasa_yukselen_dusen_html(),
        'cevirici' => piyasa_cevirici_html(),
        default    => isset(piyasa_tablo_tanimlari()[$ad]) ? piyasa_tablo_html($ad) : '',
    };
}

/** Birkac tablo yan yana (izgara); hicbirinde veri yoksa bos. */
function piyasa_tablolar_html(array $adlar): string
{
    $parcalar = array_filter(array_map('piyasa_tablo_parca_html', $adlar), static fn (string $h): bool => $h !== '');

    return $parcalar === [] ? '' : '<div class="ptablo-kap"><div class="ptablo-izgara">' . implode('', $parcalar) . '</div></div>';
}
