<?php
declare(strict_types=1);

/**
 * Kaynak tanı aracı.
 *
 * Her kaynak icin iki yolu da dener ve neden calismadigini soyler:
 *   RSS   -> HTTP kodu, icerik turu, kac girdi, kaci son N saatte
 *   Kazima -> sayfadaki baglanti sayisi, elemeden gecen aday sayisi,
 *             en kalabalik adres kaliplari, ornek baglantilar
 *
 * Neden ayri bir arac: paylasimli hostingin CA deposu eksik oldugu icin
 * panelden yapilan test bazi kaynaklarda SSL hatasi veriyor, ama ajan
 * GitHub uzerinde calistigi icin ayni kaynagi okuyabiliyor. Gercegi
 * gormek icin testin ajanin kostugu yerde kosmasi gerekiyor.
 *
 * Model cagrisi yapmaz, siteye hicbir sey yazmaz; yalnizca okur.
 *
 * Calistirma:
 *   php ajan/kaynak_dene.php [--saat=36] [--kaynak=ad-parcasi]
 */

require_once __DIR__ . '/src/Indirici.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Getirici.php';
require_once __DIR__ . '/src/Besleme.php';
require_once __DIR__ . '/src/Kazima.php';
require_once __DIR__ . '/src/Suzgec.php';
require_once __DIR__ . '/src/TohumYapilandirma.php';
require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/kazima.php';

use Valentra\Ajan\{Besleme, Getirici, Http, Site, Suzgec, TohumYapilandirma};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['saat::', 'kaynak::', 'kanunlar', 'aday::']);
$saat       = max(1, (int) ($secenekler['saat'] ?? 36));
$suzgu      = trim((string) ($secenekler['kaynak'] ?? ''));
$kanunModu  = isset($secenekler['kanunlar']);
$adayGirdi  = trim((string) ($secenekler['aday'] ?? ''));

function yaz(string $mesaj = ''): void
{
    echo $mesaj . PHP_EOL;
}

/**
 * Sutun hizalamasi icin dolgu.
 *
 * sprintf'in %-20s dolgusu bayt sayar; "Kazıma" gibi Turkce karakterli
 * bir metin oldugundan kisa gorunup sutunlari kaydiriyor.
 */
function dolgu(string $metin, int $genislik): string
{
    $eksik = $genislik - mb_strlen($metin, 'UTF-8');

    return $eksik > 0 ? $metin . str_repeat(' ', $eksik) : $metin;
}

function ayar(string $ad): string
{
    $deger = getenv($ad);

    if (!is_string($deger) || trim($deger) === '') {
        fwrite(STDERR, "HATA: {$ad} ortam değişkeni tanımlı değil.\n");
        exit(1);
    }

    return trim($deger);
}

/*
 * Kanun baglantilari kontrolu.
 *
 * kanunlar.php mevzuat.gov.tr adreslerini tertip numarasiyla uretiyor
 * ve tertip elle giriliyor; yanlissa bag sessizce kirilir. Kontrol
 * burada cunku gelistirme ortamindan dis sitelere cikis kapali, ajan
 * ise GitHub uzerinde calisiyor. Siteye baglanmadigi icin oturum ya da
 * anahtar da gerekmiyor.
 */
if ($kanunModu) {
    require_once __DIR__ . '/../includes/kanunlar.php';

    /*
     * Site tarafiyla AYNI istemci ve AYNI kabul olcutu kullaniliyor.
     *
     * Ajanin kendi Http sinifi burada ise yaramiyordu: indirmeyi
     * ilerleme geri cagrisiyla kesiyor, kesilince de curl_exec false
     * donduruyor ve elde hic govde kalmiyor. Yani birkac kilobayttan
     * buyuk her PDF — normal olanlar dahil — imza kontrolune takilip
     * "kirik" gorunurdu.
     *
     * Olcutun de ayni olmasi sart: "2000 bayttan buyuk" demek, metni
     * tarayicida dolan bos uygulama kabugunu ya da okunamayan ikili
     * bir Word belgesini saglam saymak olurdu. Tani, sayfanin
     * gosterebilecegi seyi sinamali; o yuzden metin gercekten
     * ayiklanabiliyor mu diye bakiliyor.
     */
    require_once __DIR__ . '/../includes/kanun_metni.php';

    $kanunlar = kanun_listesi();
    $kirik    = 0;

    yaz(count($kanunlar) . ' kanun için metin adresleri sınanacak.');
    yaz();

    foreach ($kanunlar as $kanun) {
        yaz(mb_substr($kanun['ad'], 0, 60));

        $referer = kanun_referer($kanun);
        $tutan   = '';

        foreach (kanun_metin_adaylari($kanun) as $aday) {
            if ($aday['tur'] === 'pdf') {
                $yanit = http_bas_getir($aday['url'], 1024, 12, $referer);
                $iyi   = $yanit['tamam'] && str_starts_with($yanit['govde'], '%PDF');
                $not   = $yanit['tamam'] && !$iyi ? 'PDF değil' : $yanit['neden'];
            } else {
                $yanit = http_getir($aday['url'], 25, $referer);
                $metin = '';

                if ($yanit['tamam']) {
                    $metin = $aday['tur'] === 'doc'
                        ? kanun_word_html_ayikla($yanit['govde'])
                        : kanun_govdeyi_ayikla($yanit['govde']);
                }

                $iyi = $metin !== '';
                $not = $yanit['tamam'] && !$iyi ? 'metin ayıklanamadı' : $yanit['neden'];
            }

            if ($iyi && $tutan === '') {
                $tutan = $aday['ad'];
            }

            yaz(sprintf(
                '  %-3s %s HTTP %d, %d bayt%s',
                $iyi ? 'OK' : 'X',
                dolgu($aday['ad'], 20),
                $yanit['kod'],
                $yanit['boyut'],
                $not !== '' ? ' (' . $not . ')' : ''
            ));
            yaz('      ' . $aday['url']);
        }

        if ($tutan === '') {
            $kirik++;
            yaz('      Hiçbir adres tutmadı. Tertip numarası yanlış olabilir;');
            yaz('      mevzuat.gov.tr\'de kanunu arayıp adresteki MevzuatTertip');
            yaz('      değerine bakın. Tüm kanunlarda aynı sonuç çıkıyorsa sorun');
            yaz('      adreslerde değil, bu ortamdan kaynağa çıkış olmamasındadır.');
        }

        yaz();
    }

    yaz($kirik === 0
        ? 'Tüm kanunlarda en az bir adres çalışıyor.'
        : $kirik . ' kanunda hiçbir adres tutmadı.');

    exit($kirik === 0 ? 0 : 1);
}

$site    = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));
$http    = new Http();

/*
 * Besleme okumasi ajanin GERCEKTE kullandigi yoldan gecmeli.
 *
 * Ajan artik dogrudan erisemedigi adresleri site sunucusundan
 * istiyor. Test bunu yapmasaydi "basarisiz" derken ajan ayni kaynagi
 * sorunsuz okuyor olabilirdi — yani tani araci yaniltirdi.
 */
/*
 * Iki ayri getirici — ve bu ayrim onemli.
 *
 * $getirici: kaynagin BESLEME adresini okur. Dogrudan erisilemeyen
 * adreslerde site sunucusuna dusebilir, ama tavani dar (10) ve
 * istekler arasinda 1,5 saniye bosluk var.
 *
 * $dogrudan: kesif cagrilari icin; site yolu YOK. Kesif kaynagin
 * kendi ana sayfasini cekiyor ve bu adresler zaten dogrudan
 * acilabiliyor; siteyi araya sokmanin faydasi yok, maliyeti buyuk.
 *
 * NEDEN: bu ayrim yokken test, basarisiz her kaynak icin hem RSS hem
 * kazima adresini, ustune iki kesif cagrisini de siteden istiyordu.
 * 32 basarisiz kaynakta uc dakikada 128 istek eder ve paylasimli
 * hostingin guvenlik duvari IP'yi gecici olarak yasaklar. Site
 * gercekten de her test kosturmasindan sonra erisilemez oluyordu.
 */
$getirici = new Getirici($http, $site, 10, 1.5);
$dogrudan = new Getirici($http, null);
$besleme = new Besleme($getirici);

/**
 * Sitenin ana sayfasında ilan ettiği beslemeleri bulur.
 *
 * NEDEN: kaynaklarin onda biri "HTTP 404" veriyor, yani adres
 * degismis. Dogru adresi tahmin etmek tehlikeli — daha once
 * mevzuat.gov.tr'de tam bunu yapip yanlis yerde arandi. Oysa siteler
 * beslemelerini <link rel="alternate" type="application/rss+xml">
 * etiketiyle kendileri ilan ediyor. Tahmin yerine SORMAK.
 *
 * Ana sayfa da alinamazsa bos doner; o zaman adres elle bulunacak.
 *
 * @return list<array{ad:string,url:string}>
 */
function beslemeleriKesfet(Getirici $getirici, string $siteUrl): array
{
    if ($siteUrl === '') {
        return [];
    }

    $html = $getirici->indir($siteUrl);

    if ($html === null) {
        return [];
    }

    // Yalnizca <head> yetmez: bazi siteler besleme bagini govdeye koyuyor.
    $desen = '#<link[^>]+type=["\']application/(?:rss|atom)\+xml["\'][^>]*>#i';

    if (preg_match_all($desen, $html, $eslesmeler) === 0) {
        return [];
    }

    $bulunan = [];
    $gorulen = [];

    foreach ($eslesmeler[0] as $etiket) {
        if (preg_match('#href=["\']([^"\']+)["\']#i', $etiket, $h) !== 1) {
            continue;
        }

        $adres = besleme_url_birlestir($siteUrl, html_entity_decode(
            trim($h[1]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));

        if (guvenli_url($adres) === '' || isset($gorulen[$adres])) {
            continue;
        }

        $baslik = preg_match('#title=["\']([^"\']*)["\']#i', $etiket, $t) === 1
            ? trim($t[1])
            : '';

        $gorulen[$adres] = true;
        $bulunan[]       = ['ad' => $baslik, 'url' => $adres];
    }

    return $bulunan;
}

/**
 * Sitenin ana sayfasında duyuru/haber listesi olabilecek adresleri bulur.
 *
 * NEDEN: kaynaklarin ucte biri calismiyor ve cogunda sebep yanlis
 * adres. Dogru adresi hafizadan yazmak tehlikeli — mevzuat.gov.tr'de
 * tam bunu yapip haftalarca yanlis yerde arandi. Oysa adres sitenin
 * ana sayfasinda duruyor: "Duyurular", "Haberler", "Basin Bultenleri"
 * baglantilari.
 *
 * Yol adinda su parcalari arayan baglantilar aday sayiliyor. Yazidan
 * degil YOLDAN bakiliyor; yazi dile gore degisiyor ama yol kaliplari
 * sitelerde sasmiyor.
 *
 * @return list<array{yazi:string,url:string}>
 */
function adresOner(Getirici $getirici, string $siteUrl): array
{
    if ($siteUrl === '') {
        return [];
    }

    $html = $getirici->indir($siteUrl);

    if ($html === null) {
        return [];
    }

    $kaliplar = ['duyuru', 'haber', 'news', 'press', 'basin', 'bulten',
                 'bulletin', 'yayin', 'insight', 'publication', 'sirküler',
                 'sirkuler', 'announc', 'media', 'guncel', 'gundem'];

    $bulunan = [];
    $gorulen = [];

    if (preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $eslesmeler, PREG_SET_ORDER) === 0) {
        return [];
    }

    foreach ($eslesmeler as $eslesme) {
        $adres = besleme_url_birlestir($siteUrl, html_entity_decode(
            trim($eslesme[1]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));

        if (guvenli_url($adres) === '' || isset($gorulen[$adres])) {
            continue;
        }

        // Yalnizca ayni site; disari giden baglantilar kaynak olamaz.
        if (parse_url($adres, PHP_URL_HOST) !== parse_url($siteUrl, PHP_URL_HOST)) {
            continue;
        }

        $yol = strtolower((string) parse_url($adres, PHP_URL_PATH));

        if ($yol === '' || $yol === '/') {
            continue;
        }

        foreach ($kaliplar as $kalip) {
            if (str_contains($yol, $kalip)) {
                $gorulen[$adres] = true;
                $bulunan[] = [
                    'yazi' => trim((string) preg_replace('/\s+/u', ' ', strip_tags($eslesme[2]))),
                    'url'  => $adres,
                ];
                break;
            }
        }

        if (count($bulunan) >= 12) {
            break;
        }
    }

    return $bulunan;
}

/**
 * Doğrudan erişilemeyen adresi site sunucusundan dener.
 *
 * @return array{tamam:bool,boyut:int}
 */
function siteYolunuDene(Site $site, string $url): array
{
    $ham = $site->hamGetir($url);

    return ['tamam' => $ham !== null, 'boyut' => $ham !== null ? strlen($ham) : 0];
}
$suzgec  = new Suzgec();

/*
 * ADAY MODU: henuz veritabaninda olmayan adresleri sinar.
 *
 * Bir kaynagi once EKLEYIP sonra calisip calismadigina bakmak yanlis
 * sira: listede 32 bozuk adresin birikme sebebi tam olarak buydu.
 * Bu modda aday once sinaniyor, schema.sql'e yalnizca calistigi
 * gorulen yaziliyor.
 *
 * Bicim:  --aday='Ad=https://adres, Baska Ad=https://baska'
 * Ad verilmezse sunucu adi kullanilir.
 */
$kaynaklar = [];

if ($adayGirdi !== '') {
    foreach (explode(',', $adayGirdi) as $parca) {
        $parca = trim($parca);

        if ($parca === '') {
            continue;
        }

        $esit = strpos($parca, '=');
        $ad   = '';
        $url  = $parca;

        if ($esit !== false) {
            $ad  = trim(substr($parca, 0, $esit));
            $url = trim(substr($parca, $esit + 1));
        }

        if ($ad === '') {
            $ad = (string) parse_url($url, PHP_URL_HOST);
        }

        /*
         * Aday hem besleme hem liste olarak deneniyor.
         *
         * Verilen adresin RSS mi yoksa haber listesi mi oldugunu
         * onceden bilmiyoruz; ikisini de denemek bir tur fazladan
         * istek demek ama dogru cevabi veriyor. Site adresi de
         * doldurulyor ki besleme kesfi calissin.
         */
        $kaynaklar[] = [
            'ad'           => $ad,
            'site_url'     => (string) (parse_url($url, PHP_URL_SCHEME) ?: 'https')
                              . '://' . (string) parse_url($url, PHP_URL_HOST),
            'besleme_url'  => $url,
            'liste_url'    => $url,
            'liste_secici' => '',
            'tur'          => 'aday',
        ];
    }

    yaz('ADAY MODU — veritabanına hiçbir şey yazılmaz, yalnızca sınanır.');
    yaz();
} else {
    $yapilandirma = null;

    try {
        $yapilandirma = $site->yapilandirma();
    } catch (Throwable $e) {
        yaz('Siteden yapılandırma alınamadı: ' . $e->getMessage());

        /*
         * Tohum listesine dusuluyor.
         *
         * Eskiden burada exit(1) vardi ve site ulasilamaz oldugunda
         * tani araci hic calismiyordu — yani tam da tesihse en cok
         * ihtiyac duyulan anda. Kaynak listesi zaten schema.sql'de
         * duruyor; panelden yapilan degisiklikleri icermez ama
         * adreslerin calisip calismadigini sinamaya yeter.
         */
        $tohum = (new TohumYapilandirma(dirname(__DIR__) . '/sql/schema.sql'))->oku();

        if ($tohum === null) {
            fwrite(STDERR, "Kaynak listesi hiçbir yoldan alınamadı.\n");
            exit(1);
        }

        $yapilandirma = $tohum;
        yaz(count($tohum['kaynaklar']) . ' kaynak schema.sql tohum listesinden okundu. '
            . 'Panelden yapılan değişiklikler bu listede yoktur.');
        yaz();
    }

    $kaynaklar = $yapilandirma['kaynaklar'] ?? [];
}

if ($suzgu !== '') {
    $kaynaklar = array_values(array_filter(
        $kaynaklar,
        static fn (array $k): bool => mb_stripos((string) $k['ad'], $suzgu) !== false
    ));
}

yaz(count($kaynaklar) . ' kaynak sınanacak (son ' . $saat . ' saat).');
yaz();

/** @var list<array{ad:string,yol:string,not:string}> */
$ozet = [];

foreach ($kaynaklar as $kaynak) {
    $ad           = (string) $kaynak['ad'];
    $kaynakSitesi = trim((string) ($kaynak['site_url'] ?? ''));
    $beslemeUrl = trim((string) ($kaynak['besleme_url'] ?? ''));
    $listeUrl   = trim((string) ($kaynak['liste_url'] ?? ''));
    $secici     = trim((string) ($kaynak['liste_secici'] ?? ''));

    yaz('=== ' . $ad . ' ===');

    $calisanYol = '';
    $not        = '';

    // --- RSS ---------------------------------------------------------------
    if ($beslemeUrl === '') {
        yaz('  RSS: adres tanımlı değil');
    } else {
        $yanit = $http->dene($beslemeUrl);
        $tur   = strtok($yanit['tur'], ';') ?: '?';

        if ($yanit['govde'] === null || $yanit['kod'] < 200 || $yanit['kod'] >= 300) {
            yaz(sprintf(
                '  RSS: doğrudan BAŞARISIZ — HTTP %d %s',
                $yanit['kod'],
                $yanit['hata'] !== '' ? '(' . $yanit['hata'] . ')' : ''
            ));

            // Ajanin ikinci yolu: site sunucusu.
            $siteden = siteYolunuDene($site, $beslemeUrl);

            if ($siteden['tamam']) {
                $girdiler = $besleme->oku($beslemeUrl, $saat);

                yaz(sprintf(
                    '       ANCAK site sunucusu üzerinden alındı (%d bayt, %d girdi) — ajan bu kaynağı okuyabilir.',
                    $siteden['boyut'],
                    count($girdiler)
                ));

                $calisanYol = 'RSS (site üzerinden)';
                $not        = 'site üzerinden ' . count($girdiler) . ' girdi';
            } else {
                yaz('       Site sunucusu üzerinden de alınamadı.');
                $not = 'RSS: HTTP ' . $yanit['kod'] . ', site yolu da düştü';

                /*
                 * Adres yanlissa dogrusunu SITEYE SORALIM.
                 *
                 * 404 aliniyorsa besleme tasinmis demektir ve siteler
                 * yeni adresi ana sayfalarinda ilan ediyor. Tahmin
                 * etmek yerine okuyoruz.
                 */
                foreach (beslemeleriKesfet($dogrudan, $kaynakSitesi) as $aday) {
                    yaz('       ÖNERİ — sitede ilan edilen besleme: ' . $aday['url']
                        . ($aday['ad'] !== '' ? '  (' . $aday['ad'] . ')' : ''));
                }
            }
        } else {
            $girdiler = $besleme->oku($beslemeUrl, $saat);
            $tumu     = $besleme->oku($beslemeUrl, 24 * 365);

            yaz(sprintf(
                '  RSS: HTTP %d, %s, %d bayt — toplam %d girdi, son %d saatte %d',
                $yanit['kod'],
                $tur,
                $yanit['boyut'],
                count($tumu),
                $saat,
                count($girdiler)
            ));

            if ($tumu === []) {
                // 200 dondu ama girdi yok: genelde XML degil HTML gelmistir.
                yaz('       XML olarak ayrıştırılamadı ya da girdi yok. İlk 120 karakter:');
                yaz('       ' . mb_substr(trim(preg_replace('/\s+/u', ' ', $yanit['govde']) ?? ''), 0, 120));
                $not = 'RSS: 200 ama girdi yok';
            } else {
                $calisanYol = 'RSS';
                $not = count($girdiler) . ' girdi';

                /*
                 * "Girdi var ama penceredeki sifir" durumunda SEBEBI yaz.
                 *
                 * Bu ozet tek basina yaniltici: Bloomberg HT "20 girdi,
                 * son 72 saatte 0" dondurdu ve bu iki bambaska seyin
                 * ikisine de uyuyor — besleme gercekten guncellenmiyor
                 * olabilir, ya da tarihler okunamadigi icin girdiler
                 * pencerenin disinda sayiliyordur. Biri kaynagi
                 * atmamizi gerektirir, digeri bizim hatamizdir.
                 *
                 * En taze girdinin tarihi ikisini ayirir: tarih
                 * yoksa/okunamiyorsa ayristirma sorunu, tarih eskiyse
                 * besleme gercekten durgun.
                 */
                if ($girdiler === []) {
                    $enTaze = null;

                    foreach ($tumu as $girdi) {
                        if ($girdi['tarih'] !== null
                            && ($enTaze === null || $girdi['tarih'] > $enTaze)) {
                            $enTaze = $girdi['tarih'];
                        }
                    }

                    if ($enTaze === null) {
                        yaz('       Girdilerin HİÇBİRİNDE okunabilir tarih yok — '
                            . 'besleme durgun değil, tarih ayrıştırması başarısız. '
                            . 'Bu kaynak pencere ne olursa olsun hep boş döner.');
                        $not = 'tarihler okunamıyor';
                    } else {
                        $yas = (int) floor((time() - strtotime($enTaze)) / 86400);
                        yaz(sprintf(
                            '       En taze girdi: %s (%d gün önce) — besleme okunuyor '
                            . 'ama güncellenmiyor.',
                            $enTaze,
                            $yas
                        ));
                        $not = 'en taze girdi ' . $yas . ' gün önce';
                    }
                }
            }

            $gecen = 0;

            foreach ($girdiler as $girdi) {
                if ($suzgec->gecer($girdi['baslik'], $girdi['ozet'])) {
                    $gecen++;
                }
            }

            if ($girdiler !== []) {
                yaz('       Ön elemeden geçen: ' . $gecen);

                foreach (array_slice($girdiler, 0, 3) as $girdi) {
                    $p = $suzgec->puanlar($girdi['baslik'], $girdi['ozet']);
                    yaz(sprintf(
                        '       [v:%d e:%d] %s',
                        $p['vergi'],
                        $p['ekonomi'],
                        mb_substr($girdi['baslik'], 0, 90)
                    ));
                }
            }
        }
    }

    // --- Kazima ------------------------------------------------------------
    if ($listeUrl === '') {
        yaz('  Kazıma: adres tanımlı değil');
    } else {
        $yanit = $http->dene($listeUrl);

        if ($yanit['govde'] === null || $yanit['kod'] < 200 || $yanit['kod'] >= 300) {
            yaz(sprintf(
                '  Kazıma: doğrudan BAŞARISIZ — HTTP %d %s',
                $yanit['kod'],
                $yanit['hata'] !== '' ? '(' . $yanit['hata'] . ')' : ''
            ));

            $siteden = siteYolunuDene($site, $listeUrl);

            if ($siteden['tamam']) {
                yaz(sprintf(
                    '       ANCAK site sunucusu üzerinden alındı (%d bayt) — ajan bu kaynağı okuyabilir.',
                    $siteden['boyut']
                ));

                if ($calisanYol === '') {
                    $calisanYol = 'Kazıma (site üzerinden)';
                }
            } else {
                yaz('       Site sunucusu üzerinden de alınamadı.');

                if ($not === '') {
                    $not = 'Kazıma: HTTP ' . $yanit['kod'] . ', site yolu da düştü';
                }
            }
        } else {
            $tani = kazima_tani($yanit['govde'], $listeUrl, $secici);

            yaz(sprintf(
                '  Kazıma: HTTP %d, %d bayt — sayfada %d bağlantı, %d aday, sonuç %d',
                $yanit['kod'],
                $yanit['boyut'],
                $tani['toplam_a'],
                $tani['aday'],
                $tani['sonuc']
            ));

            if ($tani['kapsam_var']) {
                yaz('       Seçici kullanıldı: ' . $secici);
            }

            if ($tani['kaliplar'] !== []) {
                $parcalar = [];

                foreach ($tani['kaliplar'] as $kalip => $adet) {
                    $parcalar[] = $kalip . ' (' . $adet . ')';
                }

                yaz('       En kalabalık kalıplar: ' . implode(', ', $parcalar));
            }

            if ($tani['sonuc'] === 0) {
                if ($tani['aday'] === 0) {
                    yaz('       Hiçbir bağlantı elemeden geçmedi: başlıklar 20 karakterden');
                    yaz('       kısa olabilir ya da bağlantılar başka alan adına gidiyor.');
                } elseif ($tani['en_iyi_adet'] < 3) {
                    yaz('       En kalabalık kalıpta 3\'ten az bağlantı var; haber listesi');
                    yaz('       sayılmadı. Sayfa doğru duyuru sayfası mı?');
                }

                foreach ($tani['ornekler'] as $ornek) {
                    yaz('       örnek: ' . mb_substr($ornek, 0, 120));
                }

                if ($not === '') {
                    $not = 'Kazıma: sonuç yok';
                }
            } elseif ($calisanYol === '') {
                $calisanYol = 'Kazıma';
                $not = $tani['sonuc'] . ' bağlantı';

                /*
                 * KABUL EDILEN baglantilar yaziliyor, aday ornekleri degil.
                 *
                 * kazima_tani'nin "ornekler" listesi elemeden GECMEMIS
                 * adaylari da iceriyor ve gezinme baglantilarini one
                 * cikariyor. GIB e-Belge sinamasinda tam bu oldu: sonuc
                 * 10 baglantiydi ama ekranda "Mevzuat ve Teknik
                 * Mimari", "Yararlanma Yontemleri" gibi menu adresleri
                 * gorundu. Yani kaynagin gercekte ne getirdigi
                 * anlasilamadi ve PDF olup olmadigi sorusu acikta
                 * kaldi.
                 *
                 * Bu satirlar kaynagin ajana verecegi ILK uc haberi
                 * oldugu gibi gosteriyor; uzantisindan dosya mi sayfa
                 * mi oldugu da buradan okunuyor.
                 */
                $kabuller = kazima_haberleri_bul($yanit['govde'], $listeUrl, $secici, 3);

                foreach ($kabuller as $kabul) {
                    yaz(sprintf(
                        '       alınan: %s -> %s',
                        mb_substr($kabul['baslik'], 0, 70),
                        $kabul['baglanti']
                    ));
                }

                if ($kabuller === []) {
                    foreach ($tani['ornekler'] as $ornek) {
                        yaz('       örnek: ' . mb_substr($ornek, 0, 120));
                    }
                }
            }
        }
    }

    /*
     * Hicbir yol tutmadiysa son bir sans: sitenin kendi ilan ettigi
     * besleme. "Kazima: sonuc yok" diyen kaynaklarin bir kismi
     * aslinda RSS yayinliyor, yalnizca panelde tanimli degil.
     *
     * Besleme adresi zaten tanimliysa ve 404 aldiysa kesif yukarida
     * yapildi; burada tekrarlanmiyor.
     */
    if ($calisanYol === '') {
        foreach (beslemeleriKesfet($dogrudan, $kaynakSitesi) as $aday) {
            yaz('  ÖNERİ (besleme) — ' . $aday['url']
                . ($aday['ad'] !== '' ? '  [' . $aday['ad'] . ']' : ''));
        }

        foreach (adresOner($dogrudan, $kaynakSitesi) as $aday) {
            yaz('  ÖNERİ (liste) — ' . $aday['url']
                . ($aday['yazi'] !== '' ? '  [' . mb_substr($aday['yazi'], 0, 40) . ']' : ''));
        }
    }

    $ozet[] = ['ad' => $ad, 'yol' => $calisanYol, 'not' => $not];
    yaz();
}

// --- Ozet ------------------------------------------------------------------

yaz('================ ÖZET ================');

$calisan = array_values(array_filter($ozet, static fn (array $o): bool => $o['yol'] !== ''));
$bozuk   = array_values(array_filter($ozet, static fn (array $o): bool => $o['yol'] === ''));

yaz();
yaz('ÇALIŞAN (' . count($calisan) . ')');

foreach ($calisan as $o) {
    yaz('  ' . dolgu($o['ad'], 26) . ' ' . dolgu($o['yol'], 7) . ' ' . $o['not']);
}

yaz();
yaz('ÇALIŞMAYAN (' . count($bozuk) . ')');

foreach ($bozuk as $o) {
    yaz('  ' . dolgu($o['ad'], 26) . ' ' . ($o['not'] !== '' ? $o['not'] : 'adres tanımlı değil'));
}

yaz();
yaz('Çalışmayanları panelden düzeltin ya da kapatın; kapalı kaynak taranmaz.');
