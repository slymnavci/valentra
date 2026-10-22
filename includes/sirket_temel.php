<?php
declare(strict_types=1);

/**
 * Şirketin temel verileri: İş Yatırım şirket kartı.
 *
 * Site sahibinin istegi (22.10.2026): sirket sayfasinda "analistlerin
 * ihtiyac duyabilecegi" bilgiler. Fiyat gecmisinden hesaplanamayanlar
 * buradan geliyor:
 *   - kunye (unvan, kurulus, faaliyet alani),
 *   - guncel carpanlar (F/K, FD/FAVOK, PD/DD, FD/Satislar),
 *   - Is Yatirim arastirmasinin onerisi ve hedef fiyati,
 *   - temettu gecmisi,
 *   - piyasa degeri (gunluk veri ucundan).
 *
 * Kaynak: https://www.isyatirim.com.tr (sirket karti ve HisseTekil veri
 * ucu). Yapi kaynak testiyle (kaynak_dene --ham) goruldu: kunye
 * "companyTag" tablosunda; carpanlar iki sutunlu bir tabloda ("F/K" ...);
 * oneri "Oneri Tarihi" basligi tasiyan tabloda; temettu "Temettu Verim"
 * basligi tasiyan tabloda. Sayfada kaynak ve tarih yaziyor; oneri bir
 * yatirim tavsiyesi degil, araci kurumun gorusu olarak sunuluyor.
 *
 * Kart 1 MB'i asan bir sayfa: 12 saatte bir okunuyor.
 */

require_once __DIR__ . '/canli_onbellek.php';

const ISY_KART_URL  = 'https://www.isyatirim.com.tr/tr-tr/analiz/hisse/Sayfalar/sirket-karti.aspx?hisse=';
const ISY_VERI_URL  = 'https://www.isyatirim.com.tr/_layouts/15/Isyatirim.Website/Common/Data.aspx/HisseTekil';

/**
 * @return array{veri:?array,zaman:int}
 */
function sirket_temel(string $kod): array
{
    return canli_onbellek('isy_' . $kod, 12 * 3600, static function () use ($kod): ?array {
        $html = canli_indir(ISY_KART_URL . rawurlencode($kod), 15);

        if ($html === null) {
            return null;
        }

        $veri = isy_kart_coz($html, $kod);
        $pd   = isy_piyasa_degeri($kod);

        if ($pd !== null) {
            $veri['piyasa_degeri'] = $pd;
        }

        // Hicbir bolum okunamadiysa sayfa duzeni degismis ya da kod yanlis.
        return array_filter($veri) !== [] ? $veri : null;
    });
}

/**
 * Şirket kartını çözümler.
 *
 * Temettu sutunlari (kaynak testi): Kod | Dag. Tarihi | Temettu Verim |
 * Hisse Basi TL | Nakit Tem. Brut Oran(%) | Nakit Tem. Net Oran(%) |
 * Toplam Temettu (TL) | Dagitma Orani.
 *
 * @return array{kunye:array<string,string>,finansal:array<string,string>,
 *               carpanlar:array<string,string>,
 *               oneri:?array{tarih:string,oneri:string,hedef:?float},
 *               temettu:list<array<string,string>>}
 */
function isy_kart_coz(string $html, string $kod): array
{
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1254');
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $metin = static fn (DOMNode $n): string
        => trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');

    $sonuc = ['kunye' => [], 'finansal' => [], 'carpanlar' => [], 'oneri' => null, 'temettu' => []];

    foreach ($dom->getElementsByTagName('table') as $tablo) {
        $satirlar = [];

        foreach ($tablo->getElementsByTagName('tr') as $tr) {
            $hucreler = [];

            foreach ($tr->childNodes as $h) {
                if ($h instanceof DOMElement && in_array($h->tagName, ['td', 'th'], true)) {
                    $hucreler[] = $metin($h);
                }
            }

            if ($hucreler !== []) {
                $satirlar[] = $hucreler;
            }
        }

        $sinif = ' ' . $tablo->getAttribute('class') . ' ';

        // Kunye
        if (str_contains($sinif, ' companyTag ') && $sonuc['kunye'] === []) {
            foreach ($satirlar as $s) {
                if (count($s) === 2 && $s[0] !== '' && $s[1] !== '') {
                    $sonuc['kunye'][$s[0]] = mb_substr($s[1], 0, 300);
                }
            }

            continue;
        }

        // Guncel carpanlar: iki sutunlu, "F/K" ve "PD/DD" satirlari olan tablo.
        $anahtarlar = array_map(static fn (array $s): string => $s[0], array_filter($satirlar, static fn (array $s): bool => count($s) === 2));

        // Ozet finansallar (son donem): "Satislar", "FAVOK", "Net Kar"
        // satirlari olan iki sutunlu tablo. Carpan tablosundan ONCE: ikisi
        // ayni bicimde ve bunda da "FD/Satislar" var.
        if ($sonuc['finansal'] === [] && in_array('Satışlar', $anahtarlar, true) && in_array('Net Kar', $anahtarlar, true)) {
            foreach ($satirlar as $s) {
                if (count($s) === 2) {
                    $sonuc['finansal'][$s[0]] = $s[1];
                }
            }

            continue;
        }

        if ($sonuc['carpanlar'] === [] && in_array('F/K', $anahtarlar, true) && in_array('PD/DD', $anahtarlar, true)) {
            foreach ($satirlar as $s) {
                if (count($s) === 2) {
                    $sonuc['carpanlar'][$s[0]] = $s[1];
                }
            }

            continue;
        }

        // Analist onerisi: "Oneri Tarihi" baslikli tablonun sirket satiri.
        $baslikVar = static fn (string $aranan): bool => array_filter(
            $satirlar,
            static fn (array $s): bool => in_array($aranan, $s, true)
        ) !== [];

        if ($sonuc['oneri'] === null && $baslikVar('Öneri Tarihi')) {
            foreach ($satirlar as $s) {
                if (($s[0] ?? '') === $kod && count($s) >= 4) {
                    $sonuc['oneri'] = [
                        'tarih' => $s[1],
                        'oneri' => $s[2],
                        'hedef' => isy_sayi($s[3]),
                    ];
                    break;
                }
            }

            continue;
        }

        // Temettu gecmisi
        if ($sonuc['temettu'] === [] && $baslikVar('Temettü Verim')) {
            foreach ($satirlar as $s) {
                if (($s[0] ?? '') === $kod && count($s) >= 8) {
                    $sonuc['temettu'][] = [
                        'tarih'   => $s[1],
                        'verim'   => $s[2],
                        'hisse'   => $s[3],   // hisse basi brut, TL
                        'brut'    => $s[4],   // nakit brut oran, %
                        'net'     => $s[5],   // nakit net oran, %
                        'toplam'  => $s[6],
                        'dagitma' => $s[7],
                    ];
                }

                if (count($sonuc['temettu']) >= 10) {
                    break;
                }
            }
        }
    }

    return $sonuc;
}

/**
 * Son işlem gününün piyasa değeri (TL ve USD), günlük veri ucundan.
 *
 * @return array{tl:float,usd:?float,halka_acik:?float,tarih:string}|null
 */
function isy_piyasa_degeri(string $kod): ?array
{
    $ham = canli_indir(ISY_VERI_URL . '?hisse=' . rawurlencode($kod)
        . '&startdate=' . date('d-m-Y', strtotime('-10 days'))
        . '&enddate=' . date('d-m-Y'), 10);

    $veri = $ham !== null ? json_decode($ham, true) : null;
    $gunler = is_array($veri['value'] ?? null) ? $veri['value'] : [];
    $son = $gunler !== [] ? $gunler[count($gunler) - 1] : null;

    if (!is_array($son) || !is_numeric($son['PD'] ?? null)) {
        return null;
    }

    return [
        'tl'         => (float) $son['PD'],
        'usd'        => is_numeric($son['PD_USD'] ?? null) ? (float) $son['PD_USD'] : null,
        'halka_acik' => is_numeric($son['HAO_PD'] ?? null) ? (float) $son['HAO_PD'] : null,
        'tarih'      => (string) ($son['HGDG_TARIH'] ?? ''),
    ];
}

/** "1.234,56" -> 1234.56 */
function isy_sayi(string $ham): ?float
{
    $temiz = str_replace(['.', ','], ['', '.'], trim($ham));

    return is_numeric($temiz) ? (float) $temiz : null;
}
