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

if (!in_array($bolum, ['piyasa', 'spor', 'gostergeler', 'resmi-gazete'], true)) {
    ajan_json(400, ['hata' => 'bolum: piyasa, spor, gostergeler ya da resmi-gazete olmalı.']);
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
