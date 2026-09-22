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
require_once __DIR__ . '/http_ortak.php';

const PIYASA_ONBELLEK_ANAHTAR = 'piyasa_onbellek';

/*
 * Gunluk degisim referansi ayri anahtarda.
 *
 * Onbellege yazilamaz: onbellek her cekimde bastan yaziliyor ve suresi
 * dolunca gecersiz sayiliyor; referansin ise gun boyu yasamasi gerek.
 */
const PIYASA_REFERANS_ANAHTAR = 'piyasa_gun_referansi';
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
    //
    // Ama eski veri de denetimden gecmeli: bir donem yanlis deger
    // (ornegin EUR/USD paritesi) onbellege yazilmis olabilir ve o
    // deger duzeltmeden sonra da ekranda kalirdi.
    if ($veri['usd'] === null && $veri['bist'] === null) {
        $eski = piyasa_onbellekten(true);

        if ($eski !== null && piyasa_kur_makul($eski['usd'], $eski['eur'])) {
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
        'usd_degisim'  => isset($veri['usd_degisim']) ? (float) $veri['usd_degisim'] : null,
        'eur_degisim'  => isset($veri['eur_degisim']) ? (float) $veri['eur_degisim'] : null,
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

    return piyasa_degisimi_ekle($sonuc);
}

/**
 * Dolar ve euroya günlük değişim yüzdesi ekler.
 *
 * NEDEN HESAPLANIYOR: kur saglayicilari yalnizca ANLIK degeri
 * veriyor, bir onceki kapanisi vermiyor. BIST'te degisim kaynaktan
 * geliyor; kurda gelmiyor. Oysa "48,8197" tek basina okuyucuya bir
 * sey soylemiyor — artti mi azaldi mi bilinmeden sayi olu bir veri.
 *
 * YONTEM: gunun ilk olcumu REFERANS olarak saklaniyor, gun icindeki
 * her olcum ona gore yuzde hesapliyor. Tarih degisince referans, bir
 * onceki gunun SON degeriyle yenileniyor — yani sabah acilista
 * gosterilen sey "dune gore" oluyor, finans sitelerindeki gunluk
 * degisimin karsiligi.
 *
 * Ilk calismada referans yok; o zaman degisim null donuyor ve serit
 * yalnizca degeri gosteriyor. Sifir gostermek yanlis olurdu: "hic
 * degismedi" ile "bilmiyoruz" ayni sey degil.
 *
 * @param array<string,mixed> $sonuc
 * @return array<string,mixed>
 */
function piyasa_degisimi_ekle(array $sonuc): array
{
    $sonuc['usd_degisim'] = null;
    $sonuc['eur_degisim'] = null;

    $bugun   = date('Y-m-d');
    $referans = piyasa_referans_oku();

    /*
     * Referans gunu gecmisse yenileniyor.
     *
     * Yeni referans, onbellekte duran SON degerdir: yani dun gunun
     * sonunda olculen kur. Bugunku ilk olcumu referans yapmak yanlis
     * olurdu — o zaman sabah degisim hep sifir cikar ve gun icinde
     * "dune gore" degil "sabaha gore" degisim gosterilirdi.
     */
    if (($referans['tarih'] ?? '') !== $bugun) {
        $eski = piyasa_onbellekten(true);

        $referans = [
            'tarih' => $bugun,
            'usd'   => $eski['usd'] ?? null,
            'eur'   => $eski['eur'] ?? null,
        ];

        piyasa_referans_yaz($referans);
    }

    foreach (['usd', 'eur'] as $ad) {
        $simdi = $sonuc[$ad] ?? null;
        $temel = $referans[$ad] ?? null;

        // Sifir bolme ve anlamsiz taban korumasi.
        if ($simdi === null || $temel === null || $temel <= 0.0) {
            continue;
        }

        $sonuc[$ad . '_degisim'] = round((($simdi - $temel) / $temel) * 100, 2);
    }

    return $sonuc;
}

/** @return array{tarih:string,usd:?float,eur:?float} */
function piyasa_referans_oku(): array
{
    $ham  = ayar_oku(PIYASA_REFERANS_ANAHTAR);
    $veri = $ham !== '' ? json_decode($ham, true) : null;

    if (!is_array($veri)) {
        return ['tarih' => '', 'usd' => null, 'eur' => null];
    }

    return [
        'tarih' => (string) ($veri['tarih'] ?? ''),
        'usd'   => isset($veri['usd']) ? (float) $veri['usd'] : null,
        'eur'   => isset($veri['eur']) ? (float) $veri['eur'] : null,
    ];
}

/** @param array{tarih:string,usd:?float,eur:?float} $referans */
function piyasa_referans_yaz(array $referans): void
{
    ayar_yaz(PIYASA_REFERANS_ANAHTAR, (string) json_encode($referans, JSON_UNESCAPED_UNICODE));
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

    // Once TL'yi acikca soyleyen etiketler denenir. Duz "dolar" ya da
    // "euro" araması sayfadaki EUR/USD paritesini yakalayabiliyor;
    // ilk surumde ikisi de 1,1540 cikmisti (parite, TL kuru degil).
    $usd = piyasa_etiketli_sayi($html, [
        'usd/try', 'usdtry', 'dolar/tl', 'dolar kuru', 'amerikan dolari',
        'dolar', 'usd',
    ]);
    $eur = piyasa_etiketli_sayi($html, [
        'eur/try', 'eurtry', 'euro/tl', 'euro kuru', 'euro', 'eur',
    ]);

    if (!piyasa_kur_makul($usd, $eur)) {
        return ['usd' => null, 'eur' => null];
    }

    return ['usd' => $usd, 'eur' => $eur];
}

/**
 * Çekilen kur çifti mantıklı mı?
 *
 * Kazima kor bir yontem; yanlis sayiyi sessizce gostermektense hic
 * gostermemek dogru. Uc denetim var ve ucu de gercek bir hatayi
 * yakaliyor:
 *
 * - Ikisi birebir ayni olamaz. Ilk surumde ikisi de 1,1540 cikti:
 *   sayfadan EUR/USD paritesi alinmisti.
 * - Euro her zaman dolardan pahali. Tersse siralar karismis demektir.
 * - Parite (1 civari) ve endeks (binler) TL kuru olamaz.
 */
function piyasa_kur_makul(?float $usd, ?float $eur): bool
{
    if ($usd === null) {
        return false;
    }

    // TL kuru bu araligin disina cikarsa aldigimiz sayi kur degildir.
    if ($usd < 5 || $usd > 500) {
        return false;
    }

    if ($eur === null) {
        // Dolar makulse tek basina da kullanilabilir.
        return true;
    }

    if ($eur < 5 || $eur > 500) {
        return false;
    }

    // Ayni sayi iki kez: parite ya da yanlis eslesme.
    if (abs($eur - $usd) < 0.0001) {
        return false;
    }

    // Euro dolardan ucuz cikiyorsa eslesme yanlis.
    return $eur > $usd;
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

                // Alt sinir 5: parite degerleri (1,15 gibi) TL kuru
                // olamaz ve ilk surumde tam bu hataya dusmustuk.
                if ($sayi !== null && $sayi > 5 && $sayi < 500) {
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

/**
 * Kısa zaman aşımıyla indirir; sayfa yüklemesini bekletmemeli.
 *
 * Ortak ayarlari kullaniyor: guncel kok sertifika listesi ve tarayici
 * gibi tanitan basliklar. Paylasimli hostingin eski sertifika listesi
 * yuzunden bazi kaynaklar dogrulanamiyordu.
 */
function piyasa_indir(string $url): ?string
{
    // Bu istek ziyaretcinin sayfasini bekletiyor; kisa tutuluyor.
    $sonuc = http_getir($url, 8);

    return $sonuc['tamam'] ? $sonuc['govde'] : null;
}
