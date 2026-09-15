<?php
declare(strict_types=1);

/**
 * Piyasa verisi: dolar, euro ve BIST 100.
 *
 * Tasarim notlari:
 *
 * 1) Veri TARAYICIDAN DEGIL sunucudan cekilir. Tarayicidan cekmek CORS'a
 *    takilir, ziyaretci sayisi kadar istek uretir ve kaynak siteyi
 *    yorar. Sunucu bir kez ceker, onbellege koyar, herkes ondan okur.
 *
 * 2) Onbellek 2 dakika. Sayfa 2 dakikada bir yenilendigi icin daha kisa
 *    tutmanin anlami yok; yuz ziyaretci ayni anda baksa bile kaynak
 *    siteye 2 dakikada bir tek istek gider.
 *
 * 3) Saglayicilar SIRAYLA denenir. Kazima kirilgan bir yontem: sayfa
 *    duzeni degisince desen tutmaz. Tek saglayiciya baglanirsak kutu
 *    sessizce boş kalir. Zincirin sonunda TCMB'nin kendi XML servisi
 *    duruyor; resmi, ucretsiz ve duzeni yillardir ayni. Gun ici
 *    degismez ama kutuyu asla bos birakmaz.
 */

require_once __DIR__ . '/ayarlar.php';

const PIYASA_ONBELLEK_ANAHTAR = 'piyasa_onbellek';
const PIYASA_ONBELLEK_SURE    = 120;

/**
 * Piyasa verisini döndürür (önbellekten ya da kaynaktan).
 *
 * @return array{
 *     usd:?float, eur:?float, bist:?float, bist_degisim:?float,
 *     zaman:string, kaynak:string, tazelendi:bool
 * }
 */
function piyasa_verisi(bool $zorla = false): array
{
    if (!$zorla) {
        $onbellek = piyasa_onbellekten();

        if ($onbellek !== null) {
            return $onbellek + ['tazelendi' => false];
        }
    }

    $veri = piyasa_cek();

    // Cekim basarisizsa eski veriyi gostermek bos kutudan iyidir;
    // yaninda zaman damgasi zaten duruyor.
    if ($veri['usd'] === null && $veri['bist'] === null) {
        $eski = piyasa_onbellekten(true);

        if ($eski !== null) {
            return $eski + ['tazelendi' => false];
        }
    }

    ayar_yaz(PIYASA_ONBELLEK_ANAHTAR, json_encode($veri, JSON_UNESCAPED_UNICODE));

    return $veri + ['tazelendi' => true];
}

/**
 * @return array{usd:?float,eur:?float,bist:?float,bist_degisim:?float,zaman:string,kaynak:string}|null
 */
function piyasa_onbellekten(bool $sureyiYoksay = false): ?array
{
    $ham = ayar_oku(PIYASA_ONBELLEK_ANAHTAR);

    if ($ham === '') {
        return null;
    }

    $veri = json_decode($ham, true);

    if (!is_array($veri) || !isset($veri['zaman'])) {
        return null;
    }

    if (!$sureyiYoksay) {
        $yas = time() - (int) strtotime((string) $veri['zaman']);

        if ($yas > PIYASA_ONBELLEK_SURE) {
            return null;
        }
    }

    return [
        'usd'          => isset($veri['usd']) ? (float) $veri['usd'] : null,
        'eur'          => isset($veri['eur']) ? (float) $veri['eur'] : null,
        'bist'         => isset($veri['bist']) ? (float) $veri['bist'] : null,
        'bist_degisim' => isset($veri['bist_degisim']) ? (float) $veri['bist_degisim'] : null,
        'zaman'        => (string) $veri['zaman'],
        'kaynak'       => (string) ($veri['kaynak'] ?? ''),
    ];
}

/**
 * Kaynaklardan taze veri çeker.
 *
 * @return array{usd:?float,eur:?float,bist:?float,bist_degisim:?float,zaman:string,kaynak:string}
 */
function piyasa_cek(): array
{
    $sonuc = [
        'usd'          => null,
        'eur'          => null,
        'bist'         => null,
        'bist_degisim' => null,
        'zaman'        => date('Y-m-d H:i:s'),
        'kaynak'       => '',
    ];

    $kaynaklar = [];

    foreach (piyasa_kur_saglayicilari() as $ad => $saglayici) {
        $kur = $saglayici();

        if ($kur['usd'] !== null) {
            $sonuc['usd'] = $kur['usd'];
            $sonuc['eur'] = $kur['eur'];
            $kaynaklar[]  = $ad;
            break;
        }
    }

    $endeks = piyasa_bist();

    if ($endeks['deger'] !== null) {
        $sonuc['bist']         = $endeks['deger'];
        $sonuc['bist_degisim'] = $endeks['degisim'];
        $kaynaklar[]           = $endeks['kaynak'];
    }

    $sonuc['kaynak'] = implode(' + ', $kaynaklar);

    return $sonuc;
}

/**
 * Kur sağlayıcıları, denenme sırasıyla.
 *
 * @return array<string,callable():array{usd:?float,eur:?float}>
 */
function piyasa_kur_saglayicilari(): array
{
    return [
        'BigPara'  => static fn (): array => piyasa_kur_kazi(
            'https://bigpara.hurriyet.com.tr/doviz/',
            'bigpara'
        ),
        'Döviz.com' => static fn (): array => piyasa_kur_kazi(
            'https://www.doviz.com/',
            'doviz'
        ),
        'TCMB'     => 'piyasa_kur_tcmb',
    ];
}

/**
 * Haber/piyasa sayfasından kur kazır.
 *
 * Sayfa duzenine bagli olmayan bir yontem seciliyor: sayfadaki tum
 * "1.234,56" bicimli sayilar toplanip makul araliktaki ilk degerler
 * aliniyor. Tek bir CSS secicisine baglanmak sayfa her degistiginde
 * kirilirdi; bu yontem duzen degisse de ayakta kaliyor.
 *
 * @return array{usd:?float,eur:?float}
 */
function piyasa_kur_kazi(string $url, string $bicim): array
{
    $html = piyasa_indir($url);

    if ($html === null) {
        return ['usd' => null, 'eur' => null];
    }

    $usd = piyasa_etiketli_sayi($html, ['dolar', 'usd', 'usdtry', 'usd/try']);
    $eur = piyasa_etiketli_sayi($html, ['euro', 'eur', 'eurtry', 'eur/try']);

    return ['usd' => $usd, 'eur' => $eur];
}

/**
 * Etiketin yakınındaki ilk makul kuru bulur.
 *
 * "Dolar" kelimesinden sonraki 400 karakter icinde gecen ilk
 * "sayi,sayi" bicimli deger aliniyor. Aralik sinirli: TL kuru 1'in
 * altinda ya da 1000'in ustunde olamaz, boylece sayfadaki alakasiz
 * sayilar (tarih, ziyaretci sayisi, reklam) eleniyor.
 */
function piyasa_etiketli_sayi(string $html, array $etiketler): ?float
{
    $duz = strip_tags($html);
    $duz = html_entity_decode($duz, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $duz = mb_strtolower((string) preg_replace('/\s+/u', ' ', $duz), 'UTF-8');

    foreach ($etiketler as $etiket) {
        $konum = mb_strpos($duz, $etiket, 0, 'UTF-8');

        if ($konum === false) {
            continue;
        }

        $pencere = mb_substr($duz, $konum, 400, 'UTF-8');

        if (preg_match_all('/\d{1,3}(?:\.\d{3})*,\d{2,4}/u', $pencere, $eslesmeler) !== false) {
            foreach ($eslesmeler[0] as $ham) {
                $sayi = piyasa_sayiya_cevir($ham);

                if ($sayi !== null && $sayi > 1 && $sayi < 1000) {
                    return $sayi;
                }
            }
        }
    }

    return null;
}

/**
 * TCMB'nin resmî günlük kuru.
 *
 * Zincirin son halkasi: gun ici degismez (is gunu 15:30'da aciklanir)
 * ama servisi resmi, ucretsiz ve duzeni yillardir sabit. Kazima
 * saglayicilarinin hepsi duserse kutu bununla ayakta kalir.
 *
 * @return array{usd:?float,eur:?float}
 */
function piyasa_kur_tcmb(): array
{
    $xml = piyasa_indir('https://www.tcmb.gov.tr/kurlar/today.xml');

    if ($xml === null) {
        return ['usd' => null, 'eur' => null];
    }

    $onceki = libxml_use_internal_errors(true);
    $belge  = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);

    if ($belge === false) {
        return ['usd' => null, 'eur' => null];
    }

    $bul = static function (\SimpleXMLElement $belge, string $kod): ?float {
        foreach ($belge->Currency as $birim) {
            if ((string) ($birim['CurrencyCode'] ?? '') !== $kod) {
                continue;
            }

            // Efektif satis yoksa doviz satisa dus.
            foreach (['ForexSelling', 'BanknoteSelling'] as $alan) {
                $deger = trim((string) $birim->{$alan});

                if ($deger !== '' && is_numeric($deger)) {
                    return (float) $deger;
                }
            }
        }

        return null;
    };

    return ['usd' => $bul($belge, 'USD'), 'eur' => $bul($belge, 'EUR')];
}

/**
 * BIST 100 endeksi.
 *
 * Borsa Istanbul verisini lisansli dagiticilar satiyor; anahtarsiz
 * resmi bir uc yok. Buradaki uc resmi degil ve habersiz kapanabilir,
 * o yuzden deger gelmezse kutuda endeks satiri hic gosterilmiyor.
 * Veri gecikmeli olabilir; arayuzde bu not duruyor.
 *
 * @return array{deger:?float,degisim:?float,kaynak:string}
 */
function piyasa_bist(): array
{
    $bos = ['deger' => null, 'degisim' => null, 'kaynak' => ''];

    $ham = piyasa_indir(
        'https://query1.finance.yahoo.com/v8/finance/chart/XU100.IS?interval=1d&range=1d'
    );

    if ($ham === null) {
        return $bos;
    }

    $veri = json_decode($ham, true);
    $ozet = $veri['chart']['result'][0]['meta'] ?? null;

    if (!is_array($ozet) || !isset($ozet['regularMarketPrice'])) {
        return $bos;
    }

    $deger   = (float) $ozet['regularMarketPrice'];
    $onceki  = isset($ozet['chartPreviousClose']) ? (float) $ozet['chartPreviousClose'] : null;
    $degisim = ($onceki !== null && $onceki > 0)
        ? round((($deger - $onceki) / $onceki) * 100, 2)
        : null;

    return ['deger' => $deger, 'degisim' => $degisim, 'kaynak' => 'Yahoo Finance'];
}

/** "1.234,56" -> 1234.56 */
function piyasa_sayiya_cevir(string $ham): ?float
{
    $temiz = str_replace(['.', ','], ['', '.'], trim($ham));

    return is_numeric($temiz) ? (float) $temiz : null;
}

/** Kısa zaman aşımıyla indirir; sayfa yüklemesini bekletmemeli. */
function piyasa_indir(string $url): ?string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        // Kisa tutuldu: bu istek ziyaretcinin sayfasini bekletiyor.
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ValentraBot/1.0; +https://valentra.com.tr)',
    ]);

    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($govde) || $kod < 200 || $kod >= 300) {
        return null;
    }

    return $govde;
}
