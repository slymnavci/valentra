<?php
declare(strict_types=1);

/**
 * Sayfalardaki canlı verileri tazeleme ucu.
 *
 *   POST ?bolum=piyasa        ana sayfa seridi + Piyasalar (kartlar, sirket
 *                             tablosu, 3 ay / 1 yil / 5 yil grafikleri)
 *   POST ?bolum=spor          one cikan liglerin puan durumu, bu ve gecen
 *                             turun maclari (sonuclar), gol kralligi
 *   POST ?bolum=gostergeler   Piyasalar sayfasindaki ekonomik gosterge
 *                             grafikleri (panelden tanimlanan EVDS serileri)
 *   POST ?bolum=resmi-gazete  bugunun ve dunun fihristi
 *   POST ?bolum=ozgun         ozgun veri haberleri (piyasa kapanisi, Super
 *                             Lig, Resmi Gazete ozeti, hava, vergi takvimi,
 *                             ekonomik veri; bkz. includes/ozgun_haber.php)
 *
 * NEDEN (23.10.2026, site sahibi: "haberleri ceken robot gunluk olarak
 * borsa grafikleri, mac sonuclarini da ceksin; eski bilgi kalmasin"):
 * bu veriler yalnizca biri sayfayi acinca tazeleniyordu, ziyaretci
 * gelmeyen saatlerde eskiyordu. Ajan (ve saatlik "Canli veri" is akisi)
 * burayi durtuyor; veriyi SITE cekiyor, sayfalar ayni onbellekten okuyor.
 *
 * Yalnizca sayfalarda GOSTERILEN veri tazeleniyor. Ana sayfanin eski
 * "Ekonomik Gostergeler" bolumu ve onun ucu (api/grafik-tazele.php)
 * kaldirildi; gosterge grafikleri artik Piyasalar sayfasinda.
 *
 * Bolumler ayri istek: her biri paylasimli hostingin zaman asimina
 * sigsin, biri dusse otekiler etkilenmesin. Cekim basarisizsa son iyi
 * veri korunur (canli_onbellek).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/canli_onbellek.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

$bolum = (string) ($_GET['bolum'] ?? '');

if (!in_array($bolum, ['piyasa', 'spor', 'gostergeler', 'resmi-gazete', 'ozgun', 'sirket'], true)) {
    ajan_json(400, ['hata' => 'bolum: piyasa, spor, gostergeler, resmi-gazete, ozgun ya da sirket olmalı.']);
}

@set_time_limit(110);
ignore_user_abort(true);

$baslangic = microtime(true);
$ozet      = ['bolum' => $bolum];

try {
    canli_zorla(true);

    if ($bolum === 'piyasa') {
        require_once __DIR__ . '/../includes/piyasa.php';
        require_once __DIR__ . '/../includes/piyasalar.php';

        // Ana sayfa seridi kendi onbellegini kullaniyor.
        $serit = piyasa_verisi(true);
        $ozet['serit'] = $serit['tazelendi'] ? 'tazelendi' : 'eski veri korundu';

        foreach (piyasa_araclari() as $arac => $_) {
            piyasa_seri($arac, '1d', 120);

            foreach (piyasa_donemleri() as $donem) {
                piyasa_seri($arac, $donem['aralik'], $donem['sure']);
            }
        }

        piyasa_sirket_fiyatlari();
    }

    if ($bolum === 'spor') {
        require_once __DIR__ . '/../includes/futbol.php';
        require_once __DIR__ . '/../includes/spor.php';

        if (!futbol_api_acik()) {
            // ESPN panelden kapaliyken spor sayfalari TFF'den Super Lig.
            spor_super_lig();
            $ozet['kaynak'] = 'TFF';
        } else {
            $ozet['kaynak'] = 'ESPN';
            $ozet['ligler'] = [];

            foreach (FUTBOL_ONE_CIKAN_LIGLER as $kod => $ad) {
                // Sure butcesi: ligler sirayla, kalanlar bir sonraki turda.
                if (microtime(true) - $baslangic > 80) {
                    $ozet['ligler'][$kod] = 'süre doldu, atlandı';
                    continue;
                }

                $lig = futbol_lig($kod);

                if ($lig === null) {
                    $ozet['ligler'][$kod] = 'alınamadı';
                    continue;
                }

                futbol_puan_durumu($kod);
                futbol_gol_kralligi($kod);

                // Bu turun maclari (canli/yaklasan) ve onceki turun
                // sonuclari; fikstur sayfasi ikisini de gosteriyor.
                $turlar = futbol_turlar_takvimden($lig['takvim']);
                $guncel = futbol_guncel_tur($turlar);
                $sira   = array_search($guncel, $turlar, true);

                if ($guncel !== '') {
                    futbol_tarih_maclari($kod, $guncel);
                }

                if ($sira !== false && $sira > 0) {
                    futbol_tarih_maclari($kod, $turlar[$sira - 1]);
                }

                $ozet['ligler'][$kod] = 'tamam';
            }
        }
    }

    /*
     * SIRKET SAYFALARI (30.10.2026, Google denetimi: /sirket/TKFEN 6.48 sn,
     * TTRAK 5.74 sn, TSKB 6.39 sn, "yanit cok yavas"). Sayfa, onbellekte
     * hic verisi olmayan sirkette Yahoo'dan dort seri ve Is Yatirim'dan
     * sirket kartini O ANDA cekiyordu; Googlebot da az okunan sirketlere
     * geliyor. Bayat veri aninda gosterilip yanittan sonra tazelendigi icin
     * (canli_onbellek) yalnizca HIC verisi olmayan sirket yavas: burada once
     * onlar, sonra verisi en eski olanlar, sure butcesi icinde dolduruluyor.
     * Sayfanin ilk acilista istedigi seriler: 1d, 1y, 10y ve BIST 100 10y.
     */
    if ($bolum === 'sirket') {
        require_once __DIR__ . '/../includes/piyasalar.php';
        require_once __DIR__ . '/../includes/sirket.php';
        require_once __DIR__ . '/../includes/sirket_temel.php';

        // Zorla kip acik kaliyor: yalnizca asagida secilen (eksik ya da bir
        // gunden eski) sirketlere dokunuluyor, onlarin verisi hemen cekilsin.
        $yas = static function (string $kod): int {
            $seri  = canli_onbellek_oku('sirket_' . $kod . '_10y');
            $kart  = canli_onbellek_oku('isy2_' . $kod);

            // Eksik olan en basa (yas sonsuz), sonra en eski.
            return $seri === null || $kart === null
                ? PHP_INT_MAX
                : time() - min((int) $seri['zaman'], (int) $kart['zaman']);
        };

        $kodlar = array_keys(piyasa_sirketleri());
        $yaslar = array_combine($kodlar, array_map($yas, $kodlar));
        arsort($yaslar);

        sirket_serileri_hazirla([['XU100', '10y']]);

        $hazirlanan = [];
        $eksik      = count(array_filter($yaslar, static fn (int $y): bool => $y === PHP_INT_MAX));

        foreach ($yaslar as $kod => $y) {
            if (microtime(true) - $baslangic > 75) {
                break;
            }

            // Bir gunden taze olan dokunulmuyor: sayfa zaten aninda aciliyor.
            if ($y < 86400) {
                break;
            }

            sirket_serileri_hazirla([[$kod, '1d'], [$kod, '1y'], [$kod, '10y']]);
            sirket_temel($kod);
            $hazirlanan[] = $kod;
        }

        $ozet += [
            'eksikti'    => $eksik,
            'hazirlanan' => count($hazirlanan),
            'kalan'      => count(array_filter(array_map($yas, $kodlar), static fn (int $y): bool => $y === PHP_INT_MAX)),
        ];
    }

    if ($bolum === 'gostergeler') {
        require_once __DIR__ . '/../includes/grafikler.php';

        // EVDS serileri aylik/gunluk; bir saatten eskiyse tazeleniyor.
        // Basarisiz cekimde son iyi seri korunuyor.
        $g = grafik_bayatlari_tazele(1, 20, 80);
        $ozet += ['grafik_tazelenen' => $g['tazelenen'], 'grafik_basarisiz' => $g['basarisiz']];

        if ($g['hatalar'] !== []) {
            $ozet['hatalar'] = array_slice($g['hatalar'], 0, 5);
        }
    }

    if ($bolum === 'resmi-gazete') {
        require_once __DIR__ . '/../includes/resmi_gazete.php';

        $rg = rg_tazele(2, 10);
        $ozet['gunler'] = $rg['gunler'];
        $ozet['madde']  = $rg['madde'];

        if ($rg['gunler'] === 0) {
            $ozet['hatalar'] = $rg['hatalar'];
        }
    }

    if ($bolum === 'ozgun') {
        // Veriler zorla kipte (taze) cekiliyor; her uretici kendi saat
        // penceresine ve donem kaydina bakiyor.
        require_once __DIR__ . '/../includes/ozgun_haber.php';

        $ozet['uretim'] = ozgun_haberleri_uret();

        // Gorselsiz habere konu gorseli (bkz. haber_kategori_gorsellerini_doldur).
        $ozet['kategori_gorseli'] = haber_kategori_gorsellerini_doldur();
    }
} catch (Throwable $e) {
    canli_zorla(false);
    error_log('[valentra] canli tazeleme (' . $bolum . ') düştü: ' . $e->getMessage());
    ajan_json(500, ['hata' => 'Tazeleme düştü: ' . $e->getMessage()] + $ozet);
}

canli_zorla(false);

$sayac = canli_sayac();

ajan_json(200, ['durum' => 'tamam'] + $ozet + [
    'tazelenen' => $sayac['tamam'],
    'alinamayan' => $sayac['hata'],
    'sure_sn'   => round(microtime(true) - $baslangic, 1),
]);
