<?php
declare(strict_types=1);

/**
 * Valentra vergi haberleri ajanı.
 *
 * Akış:
 *   1. Siteden taranacak kaynakları ve konu gruplarını çeker
 *   2. Her kaynağın RSS/Atom beslemesini okur
 *   3. Anahtar kelime ön elemesiyle açıkça alakasız girdileri atar
 *   4. Kalan her aday için Gemini'ye sorar: vergiyle ilgili mi, ilgiliyse
 *      özgün haber metnini yazdırır ve bir konu grubuna atatır
 *   5. Kabul edilenleri siteye TASLAK olarak gönderir
 *
 * Hiçbir haber doğrudan yayımlanmaz; yayına alma yetkisi yalnızca
 * yönetim panelindedir.
 *
 * Çalıştırma:
 *   php ajan/topla.php [--kuru] [--saat=36] [--enfazla=25] [--grup=5]
 *                      [--kaynakbasina=4]
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Indirici.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Getirici.php';
require_once __DIR__ . '/src/Depo.php';
require_once __DIR__ . '/src/TohumYapilandirma.php';
require_once __DIR__ . '/src/Besleme.php';
require_once __DIR__ . '/src/Sayfa.php';
require_once __DIR__ . '/src/Kazima.php';
require_once __DIR__ . '/src/Suzgec.php';
require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/src/KotaBittiException.php';
require_once __DIR__ . '/src/SemaliIstemci.php';
require_once __DIR__ . '/src/Yazar.php';

use Valentra\Ajan\{Besleme, Depo, Getirici, Http, Kazima, KotaBittiException, Sayfa, Site, Suzgec, TohumYapilandirma, Yazar};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

/** Komut satırı seçenekleri */
$secenekler = getopt('', ['kuru', 'saat::', 'enfazla::', 'grup::', 'sitetavani::', 'sitearaligi::', 'kaynakbasina::']);
$kuruCalisma = isset($secenekler['kuru']);
$saat        = max(1, (int) ($secenekler['saat'] ?? 36));
$enFazlaAday = max(1, (int) ($secenekler['enfazla'] ?? 25));

function gunluk(string $mesaj): void
{
    echo '[' . date('H:i:s') . '] ' . $mesaj . PHP_EOL;
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

$siteUrl   = ayar('VALENTRA_SITE_URL');
$ajanKey   = ayar('VALENTRA_AGENT_KEY');
$apiKey    = ayar('GEMINI_API_KEY');

gunluk('Valentra ajanı başlıyor' . ($kuruCalisma ? ' (KURU ÇALIŞMA — gönderim yok)' : ''));

$site    = new Site($siteUrl, $ajanKey);
$http    = new Http();

$suzgec  = new Suzgec();
$yazar   = new Yazar($apiKey);

// --- 1. Yapılandırma -------------------------------------------------------

/*
 * SITEYE ULASILAMAMASI CALISMAYI BITIRMEMELI.
 *
 * Ajan yapilandirmayi (kaynak listesi, konu gruplari, bilinen
 * haberler) siteden aliyordu ve bu cagri duserse calisma daha ilk
 * adimda oluyordu: kaynaklar taranmiyor, model cagrilmiyor, hicbir
 * haber yazilmiyor. IHS 443'te saatlerce baglanti kabul etmedigi bir
 * gunde ajan tamamen durdu.
 *
 * Oysa siteye ulasilamamasi haber TOPLAMAYI engellemek zorunda degil.
 * Kaynak listesi nadiren degisiyor; son basarili calismadan kalan
 * kopyayla tarama pekala yapilabilir. Yazilan haberler de gonderim
 * dusunce kaybolmuyor, depoda bekleyip bir sonraki calismada
 * gonderiliyor.
 *
 * Onbellek kopyasi bir haftadan eskiyse kullanilmiyor: cok eski bir
 * kaynak listesiyle calismak, guncel listeyle calistigini sanmaktan
 * kotudur.
 */
$depo = new Depo(__DIR__ . '/onbellek');

$yapilandirma  = null;
$yapilandirmaKaynagi = 'site';

try {
    $yapilandirma = $site->yapilandirma();
    $depo->yaz('yapilandirma', $yapilandirma);
} catch (Throwable $e) {
    gunluk('Siteden yapılandırma alınamadı: ' . $e->getMessage());

    // 2. yol: son basarili calismadan kalan kopya.
    $yedek = $depo->oku('yapilandirma', 7);

    if (is_array($yedek) && ($yedek['kaynaklar'] ?? []) !== []) {
        $yapilandirma        = $yedek;
        $yapilandirmaKaynagi = 'önbellek';

        gunluk(sprintf(
            'Önbellekteki yapılandırmayla devam ediliyor (%s gün önce alınmış).',
            $depo->yas('yapilandirma') ?? '?'
        ));
    } else {
        /*
         * 3. yol — SON CARE: depodaki tohum listesi.
         *
         * Onbellek de bossa (ilk calisma ya da suresi dolmus) ajan
         * eskiden tamamen duruyordu. Oysa kaynak listesi zaten
         * depoda: veritabani sql/schema.sql'den tohumlaniyor. Siteye
         * hic ulasilamasa bile buradan okuyup tarama yapilabilir.
         *
         * Bu liste panelden yapilan degisiklikleri icermez, bu yuzden
         * en sonda; site ve onbellek her zaman oncelikli.
         */
        $tohum = (new TohumYapilandirma(dirname(__DIR__) . '/sql/schema.sql'))->oku();

        if ($tohum === null) {
            fwrite(STDERR, "Yapılandırma hiçbir yoldan alınamadı; çalışma durdu.\n");
            exit(1);
        }

        $yapilandirma        = $tohum;
        $yapilandirmaKaynagi = 'depo tohumu';

        gunluk(count($tohum['kaynaklar']) . ' kaynak depodaki tohum listesinden okundu. '
            . 'Panelden yapılan kaynak değişiklikleri bu listede yoktur.');
    }
}

if ($yapilandirmaKaynagi !== 'site') {
    gunluk('Siteye ulaşılamıyor: haberler yazılacak, siteye erişilebilen '
        . 'ilk çalışmada gönderilecek.');

    /*
     * Bekleyen haberler "bilinen" sayilir.
     *
     * Siteye ulasilamadigi icin sitede hangi haberlerin oldugunu
     * bilmiyoruz. Ama onceki cevrimdisi calismalarda yazip depoda
     * biriktirdiklerimizi biliyoruz; onlari yeniden modele sormak
     * dogrudan para kaybi olurdu. Veri acisindan zaten tehlike yok —
     * gonderimde site tarafindaki kopya engeli calisiyor — mesele
     * bosa giden model cagrisi.
     */
    $bekleyenler = $depo->oku('bekleyen_haberler', 7);

    if (is_array($bekleyenler) && $bekleyenler !== []) {
        foreach ($bekleyenler as $eski) {
            $url    = (string) ($eski['kaynak_url'] ?? '');
            $baslik = (string) ($eski['baslik'] ?? '');

            if ($url !== '') {
                $yapilandirma['bilinen_url'][] = url_parmak($url);
            }

            if ($baslik !== '') {
                $yapilandirma['bilinen_baslik'][] = baslik_parmak($baslik);

                /*
                 * BASA ekleniyor, sona degil.
                 *
                 * Bu basliklar cevrimdisi gecen onceki calismalarda
                 * yazildi; listenin EN TAZE ucu onlar. Modele
                 * gosterilen liste bastan kirpildigi icin sona
                 * eklenseler tam da onlenmeleri gereken durumda —
                 * site kapaliyken ust uste calisan ajanda — sinirin
                 * disinda kalirlardi.
                 */
                array_unshift($yapilandirma['son_basliklar'], $baslik);
            }
        }

        gunluk(count($bekleyenler) . ' bekleyen haber "zaten var" listesine eklendi.');
    }
}

/*
 * Indirmeler site sunucusuna dusebilsin diye Getirici uzerinden.
 *
 * Turk kamu ve meslek siteleri veri merkezi IP'lerini engelliyor,
 * ajan ise GitHub'da calisiyor. Getirici once dogrudan deniyor,
 * olmazsa site sunucusundan istiyor.
 *
 * KURULUM YAPILANDIRMADAN SONRA: site ayakta degilse ikinci yol hic
 * takilmiyor. Eskiden Getirici yapilandirmadan ONCE kuruluyordu ve
 * elinde her halukarda site nesnesi oluyordu; site dustugunde
 * durumu ancak kaynak kaynak, her biri icin dakikalarca bekleyerek
 * ogreniyordu. Oysa bilgi zaten elimizde: yapilandirma siteden
 * gelemediyse site kapalidir.
 */
$siteyeUlasilir = $yapilandirmaKaynagi === 'site';
/*
 * Tavan ve aralik komut satirindan ayarlanabiliyor.
 *
 * Varsayilan degerler paylasimli hosting icin guvenli tarafta secildi;
 * sunucu daha fazlasini kaldiriyorsa --sitetavani ile yukseltilebilir,
 * guvenlik duvari tepki verirse dusurulebilir. Ayari denemeden
 * degistirmek dogru degil: yasaklar bu yolun ilk acilisinda yasandi.
 */
$getirici = new Getirici(
    $http,
    $siteyeUlasilir ? $site : null,
    max(1, (int) ($secenekler['sitetavani'] ?? 20)),
    max(0.2, (float) ($secenekler['sitearaligi'] ?? 2.0))
);
$besleme  = new Besleme($getirici);
$sayfa    = new Sayfa($getirici);
$kazima   = new Kazima($getirici);

if (!$siteyeUlasilir) {
    gunluk('Site sunucusu üzerinden indirme bu çalışmada kapalı; '
        . 'kaynaklar yalnızca doğrudan taranacak.');
}

$kaynaklar   = $yapilandirma['kaynaklar'];
$kategoriler = $yapilandirma['kategoriler'];

/*
 * Sitede zaten bulunan haberler.
 *
 * Kopya engeli yalnizca yazma aninda calisiyordu; ayni haber her
 * calismada yeniden modele gidip ucretlendiriliyor, sonra "zaten vardi"
 * diye atiliyordu. Artik bilinenler modele hic sorulmuyor.
 *
 * Tek olcut yetmiyordu. Ham adresin parmak izi, ayni sayfaya
 * "?utm_source=..." eklenmis ya da sonuna egik cizgi gelmis halini
 * FARKLI sayiyor ve haber yeniden toplaniyordu. Simdi dort katman var:
 *   1. eski ham adres parmak izi (geriye donuk uyum)
 *   2. sadelestirilmis adres parmak izi
 *   3. katlanmis baslik parmak izi — ayni haberi baska bir kaynaktan
 *      ikinci kez almayi onler
 *   4. baslik benzerligi — birebir ayni olmayan ama ayni olayi
 *      anlatan basliklar icin
 */
$bilinen       = array_fill_keys($yapilandirma['bilinen'], true);
$bilinenUrl    = array_fill_keys($yapilandirma['bilinen_url'], true);
$bilinenBaslik = array_fill_keys($yapilandirma['bilinen_baslik'], true);

/** @var list<array<string,bool>> Bilinen basliklarin kelime kumeleri */
$bilinenKumeler = [];

foreach ($yapilandirma['son_basliklar'] as $eskiBaslik) {
    $kume = baslik_kumesi((string) $eskiBaslik);

    if ($kume !== []) {
        $bilinenKumeler[] = $kume;
    }
}

/*
 * Modele gosterilecek "daha once yayimlandi" listesi.
 *
 * Mekanik katmanlar ayni olayi FARKLI kelimelerle anlatan basligi
 * yakalayamiyor ve yapisi geregi yakalayamaz: olculen ornekte ortak
 * kelime orani 0,12 iken esik 0,80. Esigi dusurmek ardarda cikan iki
 * AYRI tebligi de birlestirirdi — gercek haberi kaybetmek, kopya
 * gostermekten kotudur.
 *
 * Olayin ayni olup olmadigina karar verebilen tek katman model. Liste
 * ona veriliyor; karari o veriyor.
 *
 * $bilinenKumeler ile ayni kaynaktan besleniyor ama isi farkli: orada
 * kelime kumesi, burada okunabilir baslik gerekiyor.
 */
$modeleGosterilecek = [];

foreach ($yapilandirma['son_basliklar'] as $eskiBaslik) {
    $temiz = trim((string) $eskiBaslik);

    if ($temiz !== '') {
        $modeleGosterilecek[] = $temiz;
    }
}

if ($kaynaklar === []) {
    gunluk('Aktif kaynak yok. Yönetim panelinden kaynak ekleyin.');
    exit(0);
}

gunluk(
    count($kaynaklar) . ' kaynak, ' . count($kategoriler) . ' konu grubu, '
    . count($bilinen) . ' bilinen haber alındı.'
);

/** Site tarafindaki haber_parmak_izi() ile ayni hesap. */
function parmak_izi(string $kaynakUrl, string $baslik): string
{
    $temel = $kaynakUrl !== ''
        ? mb_strtolower(trim($kaynakUrl), 'UTF-8')
        : 'baslik:' . mb_strtolower(trim(preg_replace('/\s+/u', ' ', $baslik) ?? ''), 'UTF-8');

    return hash('sha256', $temel);
}

/*
 * Asagidaki uc fonksiyon site tarafindaki karsiliklariyla BIREBIR ayni
 * sonucu uretmek zorunda (includes/haberler.php). Ayrilirlarsa suzgec
 * sessizce hicbir seyi yakalamaz — en kotu hata turu, cunku hicbir
 * yerde hata gorunmez, yalnizca kopyalar geri gelir.
 */

/** includes/haberler.php -> haber_url_sadelestir() ile ayni. */
function url_sadelestir(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    $parca = parse_url($url);

    if ($parca === false || !isset($parca['host'])) {
        return mb_strtolower($url, 'UTF-8');
    }

    $sunucu = strtolower($parca['host']);
    $sunucu = preg_replace('/^www\./', '', $sunucu) ?? $sunucu;

    $yol = rtrim((string) ($parca['path'] ?? ''), '/');

    $sorgu = '';

    if (isset($parca['query']) && $parca['query'] !== '') {
        parse_str($parca['query'], $parametreler);

        foreach (array_keys($parametreler) as $ad) {
            $kucuk = strtolower((string) $ad);

            if (str_starts_with($kucuk, 'utm_')
                || in_array($kucuk, ['fbclid', 'gclid', 'yclid', 'mc_cid', 'mc_eid',
                                     'ref', 'referrer', 'amp', 'source', 'src',
                                     'sessionid', 'phpsessid'], true)) {
                unset($parametreler[$ad]);
            }
        }

        ksort($parametreler);
        $sorgu = http_build_query($parametreler);
    }

    return $sunucu . $yol . ($sorgu !== '' ? '?' . $sorgu : '');
}

/** includes/haberler.php -> haber_baslik_sadelestir() ile ayni. */
function baslik_sadelestir(string $baslik): string
{
    $baslik = str_replace(['İ', 'I', 'ı'], 'i', $baslik);
    $baslik = mb_strtolower($baslik, 'UTF-8');
    $baslik = str_replace("\xCC\x87", '', $baslik);
    $baslik = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $baslik) ?? $baslik;

    return trim($baslik);
}

function url_parmak(string $url): string
{
    $sade = url_sadelestir($url);

    return $sade === '' ? '' : hash('sha256', 'url:' . $sade);
}

function baslik_parmak(string $baslik): string
{
    $sade = baslik_sadelestir($baslik);

    return $sade === '' ? '' : hash('sha256', 'baslik:' . $sade);
}

/**
 * Başlığın karşılaştırmaya giren kelime kümesi.
 *
 * Iki kural, ikisi de deneyle bulundu:
 *
 * 1. Uc harften KISA kelimeler atilir ama uc harfliler KALIR. Ilk
 *    surumde esik "uc harften uzun" idi ve tam da ayirt edici
 *    kelimeleri eliyordu: "KDV Genel Tebligi 45 seri no yayimlandi"
 *    ile "OTV Genel Tebligi 12 seri no yayimlandi" ayni kumeye
 *    iniyor ve iki AYRI teblig kopya sayiliyordu. Bu alanda ayirt
 *    eden kelime cogu zaman uc harfli kisaltmadir: KDV, OTV, GVK,
 *    VUK, SGK.
 *
 * 2. Icinde rakam gecen her parca uzunlugu ne olursa olsun kalir.
 *    Teblig sira numarasi ("45", "585") ve yil ("2026") iki haberi
 *    birbirinden ayiran en kesin isarettir.
 *
 * @return array<string,bool>
 */
function baslik_kumesi(string $baslik): array
{
    $kelimeler = preg_split('/\s+/u', baslik_sadelestir($baslik), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $secilen = array_filter(
        $kelimeler,
        static fn (string $k): bool => mb_strlen($k, 'UTF-8') >= 3
                                    || preg_match('/\d/u', $k) === 1
    );

    return array_fill_keys($secilen, true);
}

/**
 * İki başlık aynı haberi mi anlatıyor?
 *
 * Olcut: ortak kelimelerin, KISA olanin kelime sayisina orani. Jaccard
 * yerine bu secildi cunku kaynaklar ayni olayi bazen cok daha uzun bir
 * baslikla duyuruyor ("... hakkinda Genel Teblig Resmi Gazete'de
 * yayimlandi"); uzun basligi paydaya katmak gercek kopyalari kacirirdi.
 *
 * Esik 0.8: uc kelimeden ikisinin tutmasi yetmez, dortte uc tutmali.
 * Dusuk esik ayni konudaki FARKLI haberleri (ornegin ardarda cikan iki
 * KDV tebligini) eler; bu, kopya gostermekten daha kotu.
 *
 * Kelime sayisi 4'un altinda olan basliklarda benzerlige hic
 * bakilmiyor: "KDV orani degisti" gibi kisa bir baslikta tek kelime
 * bile orani sicratiyor.
 *
 * @param array<string,bool> $a
 * @param array<string,bool> $b
 */
function baslik_benzer(array $a, array $b): bool
{
    $kisa = min(count($a), count($b));

    if ($kisa < 4) {
        return false;
    }

    $ortak = count(array_intersect_key($a, $b));

    return $ortak / $kisa >= 0.8;
}

/**
 * Kaynakları tarama sırasına dizer.
 *
 * @param list<array<string,mixed>> $kaynaklar
 * @return list<array<string,mixed>>
 */
function kaynaklari_sirala(array $kaynaklar, int $calismaNo): array
{
    if ($kaynaklar === []) {
        return $kaynaklar;
    }

    /*
     * MEVZUAT KAYNAKLARI EN ONDE.
     *
     * Bu grup Turk kamu siteleri ve mevzuatin birincil kaynagi. Ayri
     * tutulmalarinin sebebi siralama degil, KIT BIR KAYNAGI paylasmak:
     * bu siteler veri merkezi IP'lerini engelledigi icin ajan onlara
     * ancak site sunucusu uzerinden ulasabiliyor ve o yolun bir
     * calismada sinirli sayida istek hakki var (bkz. Getirici). Hak
     * tarama sirasina gore harcandigi icin, listede sonra gelen
     * mevzuat kaynaklari hakkin tukenmesinden sonra siraya giriyor ve
     * HIC okunamiyordu.
     *
     * Hazine ve Maliye, ISMMMO, SGK, SPK, BDDK, Ticaret Bakanligi ve
     * Rekabet Kurumu daha once bu listede degildi; mevzuatin buyuk
     * kismi oralardan cikmasina ragmen genel kaynaklarla ayni siraya
     * konmuslardi.
     */
    $mevzuatAdlari = [
        'Resmî Gazete', 'Gelir İdaresi Başkanlığı', 'Hazine ve Maliye Bakanlığı',
        'KGK', 'TÜRMOB', 'İSMMMO', 'SGK', 'SPK', 'BDDK',
        'Ticaret Bakanlığı', 'Rekabet Kurumu', 'KOSGEB', 'TÜİK',
    ];

    // Ikinci halka: mesleki ve uluslararasi kaynaklar. Bunlar cogunlukla
    // dogrudan erisilebiliyor, yani kit kaynagi tuketmiyorlar.
    $cekirdekAdlar = [
        'OECD Vergi', 'Avrupa Komisyonu Vergi', 'IFRS Foundation',
        'IAS Plus', 'EFRAG', 'Deloitte Türkiye', 'PwC Türkiye',
        'KPMG Türkiye', 'BDO Türkiye', 'EY Türkiye',
        'Grant Thornton Türkiye', 'Tax Foundation', 'Accountancy Age',
        'ICAEW', 'IFAC', 'Avrupa Merkez Bankası', 'Federal Reserve',
    ];

    $mevzuat   = [];
    $cekirdek  = [];
    $digerleri = [];

    foreach ($kaynaklar as $kaynak) {
        $ad = (string) $kaynak['ad'];

        if (in_array($ad, $mevzuatAdlari, true)) {
            $mevzuat[] = $kaynak;
        } elseif (in_array($ad, $cekirdekAdlar, true)) {
            $cekirdek[] = $kaynak;
        } else {
            $digerleri[] = $kaynak;
        }
    }

    
    /**
     * Listeyi calisma numarasina gore kaydirir.
     *
     * Kaydirma rastgele degil saate bagli: ayni calisma iki kez
     * tetiklenirse ayni sirayi izler, davranis ongorulebilir kalir.
     */
    $kaydir = static function (array $liste, int $calismaNo, int $bolen = 3): array {
        if ($liste === []) {
            return $liste;
        }

        $adim  = max(1, intdiv(count($liste), $bolen));
        $kayma = ($calismaNo * $adim) % count($liste);

        return array_merge(array_slice($liste, $kayma), array_slice($liste, 0, $kayma));
    };

    /*
     * Mevzuat grubu da kaydiriliyor — ONCEKI HALINDE KAYDIRILMIYORDU.
     *
     * Site uzerinden istek hakki grubun tamamina yetmedigi icin hep
     * ayni ilk birkac kaynak okunuyor, gerisi her calismada ayni yerde
     * eleniyordu. Kaydirma ile hak sirayla dolasiyor: bir calismada
     * okunamayan kaynak sonrakinde basa geciyor.
     *
     * Bolen 1: her calismada bir adim kayiyor, boylece dolasim yavas
     * ve duzenli oluyor.
     */
    $mevzuat   = $kaydir($mevzuat, $calismaNo, max(1, count($mevzuat)));
    $digerleri = $kaydir($digerleri, $calismaNo);

    return array_merge($mevzuat, $cekirdek, $digerleri);
}

/**
 * Kontenjanı konulara paylaştırarak aday seçer.
 *
 * Puana gore duz siralama bir konu turunu sistematik olarak aciz
 * birakiyordu. Olculdu: "KDV tevkifat oranlarinda degisiklik" 17 puan
 * alirken "7530 sayili Kanun ile bazi kanunlarda degisiklik yapildi"
 * 2, "IASB issues amendments to IFRS 9" 4 puan aliyor. Vergi terimi
 * yogun basliklar dogalari geregi daha cok terim barindirdigi icin
 * listenin tamamini kapliyor; mevzuat ve standart haberleri kontenjana
 * hic giremiyordu. Sitede TMS/TFRS ve kanun degisikligi haberlerinin
 * cikmamasinin sebebi buydu.
 *
 * Cozum siralamayi bozmak degil, kontenjani konulara paylastirmak. Her
 * turdan sirayla aliniyor; bir konuda aday kalmazsa payi otekilere
 * geciyor, yani liste hicbir zaman bos yer birakmiyor.
 *
 * Adaylarin puana gore sirali geldigi varsayiliyor: her kovadan bastan
 * alindigi icin konu icinde en iyi aday once seciliyor.
 *
 * @param list<array<string,mixed>>   $adaylar     Puana gore sirali
 * @param array<string,int>           $paylar      Konu => tur basina pay
 * @return list<array<string,mixed>>
 */
function konu_kontenjani(array $adaylar, array $paylar, int $enFazla, Suzgec $suzgec): array
{
    /** @var array<string,list<array<string,mixed>>> */
    $kovalar = array_fill_keys(array_keys($paylar), []);

    foreach ($adaylar as $aday) {
        $konu = $suzgec->konu(
            (string) $aday['girdi']['baslik'],
            (string) ($aday['girdi']['ozet'] ?? '')
        );

        // Bilinmeyen bir konu gelirse aday kaybolmasin.
        if (!isset($kovalar[$konu])) {
            $konu = array_key_first($paylar);
        }

        $kovalar[$konu][] = $aday;
    }

    $secilen = [];

    while (count($secilen) < $enFazla) {
        $alindi = false;

        foreach ($paylar as $konu => $pay) {
            for ($i = 0; $i < $pay; $i++) {
                if (count($secilen) >= $enFazla || $kovalar[$konu] === []) {
                    break;
                }

                $secilen[] = array_shift($kovalar[$konu]);
                $alindi    = true;
            }
        }

        // Butun kovalar bosaldi: dongu kendini tekrar etmesin.
        if (!$alindi) {
            break;
        }
    }

    return $secilen;
}

/**
 * Aynı olayı anlatan adaylardan yalnızca en iyisini bırakır.
 *
 * Bilinen basliklara benzerlik zaten olculuyor ama o denetim GECMISE
 * bakiyor: sitede olan bir haberin tekrarini yakaliyor. Ayni calismada
 * BES FARKLI kaynagin ayni tebligi duyurmasini yakalamiyordu — besi de
 * listeye giriyor ve kontenjanin bes katini yiyordu.
 *
 * Adaylar puana gore sirali geldigi icin her kumeden elde kalan en
 * yuksek puanli aday tutuluyor.
 *
 * YALNIZCA FARKLI KAYNAKLAR arasinda eleme yapiliyor. Sebebi olculdu:
 * kural kaynak ayrimi gozetmeden uygulandiginda Resmi Gazete'nin ayni
 * gun yayimladigi bes AYRI teblig birbirinin kopyasi sayiliyordu —
 * basliklari dogalari geregi birbirine cok benziyor ("... Genel
 * Tebliginde Degisiklik Yapilmasina Dair Teblig"). Gercek haberi
 * kaybetmek, kopya gostermekten kotudur.
 *
 * Ayni kaynagin birbirine benzeyen adaylari zaten kaynak basina tavana
 * takiliyor, yani o tarafta da sinir var.
 *
 * @param list<array<string,mixed>> $adaylar Puana gore sirali
 * @return array{0:list<array<string,mixed>>,1:int} Kalanlar ve elenen sayisi
 */
function ayni_olayi_ele(array $adaylar): array
{
    /** @var list<array{kume:array<string,bool>,kaynak:string}> */
    $kumeler = [];
    $tekil   = [];
    $elenen  = 0;

    foreach ($adaylar as $aday) {
        $kume   = baslik_kumesi((string) $aday['girdi']['baslik']);
        $kaynak = (string) ($aday['kaynak']['ad'] ?? '');
        $ayni   = false;

        foreach ($kumeler as $onceki) {
            if ($onceki['kaynak'] === $kaynak) {
                continue;
            }

            if (baslik_benzer($kume, $onceki['kume'])) {
                $ayni = true;
                break;
            }
        }

        if ($ayni) {
            $elenen++;
            continue;
        }

        if ($kume !== []) {
            $kumeler[] = ['kume' => $kume, 'kaynak' => $kaynak];
        }

        $tekil[] = $aday;
    }

    return [$tekil, $elenen];
}

// --- 2 ve 3. Beslemeleri oku, ön elemeden geçir ---------------------------

$adaylar = [];

/*
 * Kaynak tarama icin zaman butcesi.
 *
 * Kaynak sayisi 78'e cikti ve hepsi sirayla okunuyor. Cogu iki
 * saniyede cevap veriyor ama olu ya da yavas bir adres zaman asimina
 * kadar bekletiyor; bir avuc olu adres taramayi dakikalarca uzatabilir
 * ve calisma GitHub'in sure sinirina dayanir. O noktada model cagrisi
 * hic yapilamaz ve calisma tamamen bosa gider.
 *
 * Butce dolunca kalan kaynaklar atlanip eldeki adaylarla devam
 * ediliyor: eksik taramayla birkac haber yazmak, hic haber yazmamaktan
 * iyi.
 *
 * Baslangic noktasi her calismada kayiyor. Sabit sirada taransaydi
 * butce hep ayni yerde dolar ve listenin sonundaki kaynaklar HIC
 * taranmazdi; kaydirmayla her kaynak sirayla one geliyor. Kaydirma
 * rastgele degil saate bagli: ayni calisma iki kez tetiklenirse ayni
 * sirayi izler, davranis ongorulebilir kalir.
 */
$taramaBaslangic = time();
$taramaButcesi   = 8 * 60;
$atlanan         = 0;

$kaynaklar = kaynaklari_sirala($kaynaklar, (int) floor(time() / 7200));

foreach ($kaynaklar as $kaynak) {
    if (time() - $taramaBaslangic > $taramaButcesi) {
        $atlanan++;
        continue;
    }

    $beslemeUrl = (string) ($kaynak['besleme_url'] ?? '');
    $listeUrl   = (string) ($kaynak['liste_url'] ?? '');

    $girdiler = [];
    $yontem   = '';

    /*
     * BIR KAYNAK CALISMAYI OLDUREMEZ.
     *
     * Kaynaklar sirayla okunuyordu ve okuma sirasinda cikan her hata
     * butun calismayi bitiriyordu. Gercek bir calismada bozuk bir
     * beslemede olumcul hata cikti ve Haberturk'ten SONRAKI butun
     * kaynaklar hic taranmadi — o ana kadar bulunan adaylar da cope
     * gitti, cunku haber yazma adimina hic gelinemedi.
     *
     * Bir kaynagin bozuk olmasi normaldir: adres degisir, sunucu
     * hata dondurur, besleme bozulur, site beklenmedik bicim uretir.
     * Anormal olan, birinin digerlerini durdurmasi.
     *
     * Throwable yakalaniyor — Exception degil: bu bir "beklenen hata"
     * yonetimi degil, "ne cikarsa ciksin devam et" kalkani. Ayrintisi
     * gunluge yaziliyor ki sorun gizlenmesin, kaynak da atlaniyor.
     */
    try {
        // Once RSS: varsa ve haber veriyorsa en guvenilir yol.
        if ($beslemeUrl !== '') {
            $girdiler = $besleme->oku($beslemeUrl, $saat);
            $yontem   = 'besleme';
        }

        // Besleme yoksa ya da bos dondüyse duyuru sayfasini kaziyoruz.
        if ($girdiler === [] && $listeUrl !== '') {
            $girdiler = $kazima->oku($listeUrl, (string) ($kaynak['liste_secici'] ?? ''));
            $yontem   = 'kazıma';
        }
    } catch (Throwable $e) {
        gunluk(sprintf(
            '  %s: HATA, atlandı — %s (%s:%d)',
            $kaynak['ad'],
            $e->getMessage(),
            basename($e->getFile()),
            $e->getLine()
        ));
        continue;
    }

    if ($girdiler === []) {
        /*
         * SEBEBI YAZ, "okunamadi" deyip gecme.
         *
         * Gercek bir calismada 82 kaynagin 29'u bu satira dusuyordu ve
         * hepsi ayni cumleyi yaziyordu — aralarinda GIB, Hazine ve
         * Maliye, KGK, ISMMMO, SPK, BDDK gibi sitenin cekirdek mevzuat
         * kaynaklari vardi. Tek bir cumleyle hangisinin sertifikadan,
         * hangisinin 403'ten, hangisinin gercekten bos beslemeden
         * dustugu anlasilamiyor ve hicbiri duzeltilemiyordu.
         *
         * Getirici site yolunu denediyse sebebi tutuyor; tutmadiysa
         * dogrudan indirme calismis ama besleme bos gelmis demektir.
         */
        if ($beslemeUrl === '' && $listeUrl === '') {
            $neden = 'adres tanımlı değil';
        } else {
            $sebep = $getirici->sonSebep($beslemeUrl !== '' ? $beslemeUrl : $listeUrl);

            $neden = $sebep !== ''
                ? 'alınamadı — ' . $sebep
                : 'adrese ulaşıldı ama yeni girdi yok (besleme boş ya da '
                  . 'girdiler zaman penceresinin dışında)';
        }

        gunluk("  {$kaynak['ad']}: {$neden}");
        continue;
    }

    $gecen = 0;
    $zatenVar = 0;

    foreach ($girdiler as $girdi) {
        if (!$suzgec->gecer($girdi['baslik'], $girdi['ozet'])) {
            continue;
        }

        // Sitede zaten olan haberi modele sormak bosa para.
        if (isset($bilinen[parmak_izi($girdi['baglanti'], $girdi['baslik'])])
            || isset($bilinenUrl[url_parmak($girdi['baglanti'])])
            || isset($bilinenBaslik[baslik_parmak($girdi['baslik'])])) {
            $zatenVar++;
            continue;
        }

        // Birebir ayni olmayan ama ayni olayi anlatan baslik.
        $kume = baslik_kumesi($girdi['baslik']);
        $benzerVar = false;

        foreach ($bilinenKumeler as $eskiKume) {
            if (baslik_benzer($kume, $eskiKume)) {
                $benzerVar = true;
                break;
            }
        }

        if ($benzerVar) {
            $zatenVar++;
            continue;
        }

        /*
         * Ayni calismanin icindeki kopyalar da eleniyor.
         *
         * Bir tebligi ayni anda bes kaynak duyurabiliyor; hepsi de
         * "yeni" cunku hicbiri henuz sitede yok. Once gelen aliniyor,
         * kaynaklar zaten resmi olanlar once gelecek sekilde sirali.
         */
        $bilinenUrl[url_parmak($girdi['baglanti'])]  = true;
        $bilinenBaslik[baslik_parmak($girdi['baslik'])] = true;

        if ($kume !== []) {
            $bilinenKumeler[] = $kume;
        }

        $adaylar[] = [
            'girdi'  => $girdi,
            'kaynak' => $kaynak,
            'puan'   => $suzgec->puan($girdi['baslik'], $girdi['ozet']),
        ];
        $gecen++;
    }

    $zatenNotu = $zatenVar > 0 ? ", {$zatenVar} zaten var" : '';
    gunluk("  {$kaynak['ad']} ({$yontem}): " . count($girdiler) . " girdi, {$gecen} aday{$zatenNotu}");
}

if ($atlanan > 0) {
    gunluk(
        '  ' . $atlanan . ' kaynak zaman bütçesi dolduğu için atlandı '
        . '(tarama ' . (time() - $taramaBaslangic) . ' sn sürdü). '
        . 'Sıra her çalışmada kaydığı için sonraki çalışmalarda öne geçecekler.'
    );
}

if ($adaylar === []) {
    gunluk('Ön elemeden geçen aday yok. Çalışma tamamlandı.');
    exit(0);
}

// Güçlü sinyal verenler önce; bütçe sınırına takılırsa en iyileri işlensin.
usort($adaylar, static fn (array $a, array $b): int => $b['puan'] <=> $a['puan']);

/*
 * KAYNAK BASINA TAVAN.
 *
 * Tek bir kaynak butun kontenjani yiyebiliyordu. Bir calismada 25
 * adayin 14'u IFRS Foundation'dan geldi ve hepsi haber degil, sitenin
 * menu sayfalariydi ("IFRS Foundation Trustees", "How we set IFRS
 * Standards"). Model 25'inin 25'ini de eledi ve o calismada SIFIR
 * haber yazildi — oysa Dunya Gazetesi, Ekonomim ve CNBC'den gercek
 * haberler de listedeydi, sadece siralamada altta kalmislardi.
 *
 * Puana gore siralama bunu tek basina cozmuyor: kurumsal tanitim
 * sayfalarinin basliklari vergi terimleriyle dolu oldugu icin
 * on elemeden yuksek puanla geciyorlar.
 *
 * Tavan sirlama SONRASI uygulaniyor; her kaynak en iyi birkac adayiyla
 * temsil ediliyor ve kalan yer digerlerine kaliyor.
 */
$kaynakBasinaTavan = max(1, (int) ($secenekler['kaynakbasina'] ?? 4));
$kaynakSayaci      = [];
$secilen           = [];

foreach ($adaylar as $aday) {
    $ad = (string) $aday['kaynak']['ad'];
    $kaynakSayaci[$ad] = ($kaynakSayaci[$ad] ?? 0) + 1;

    if ($kaynakSayaci[$ad] > $kaynakBasinaTavan) {
        continue;
    }

    $secilen[] = $aday;
}

$tavanaTakilan = count($adaylar) - count($secilen);

[$tekil, $kopyaElenen] = ayni_olayi_ele($secilen);

if ($kopyaElenen > 0) {
    gunluk('  ' . $kopyaElenen . ' aday aynı olayın başka kaynaktaki '
         . 'kopyası olduğu için elendi.');
}

/*
 * Paylar esit degil: site vergi odakli. Her turda 3 vergi, 2 mevzuat,
 * 2 standart, 1 ekonomi aliniyor.
 */
$konuPaylari = ['vergi' => 3, 'mevzuat' => 2, 'standart' => 2, 'ekonomi' => 1];
$adaylar     = konu_kontenjani($tekil, $konuPaylari, $enFazlaAday, $suzgec);

$konuOzeti = [];

foreach (array_keys($konuPaylari) as $konu) {
    $adet = count(array_filter(
        $adaylar,
        static fn (array $a): bool => $suzgec->konu(
            (string) $a['girdi']['baslik'],
            (string) ($a['girdi']['ozet'] ?? '')
        ) === $konu
    ));

    $konuOzeti[] = $konu . ': ' . $adet;
}

gunluk('  Konu dağılımı — ' . implode(', ', $konuOzeti) . '.');

if ($tavanaTakilan > 0) {
    gunluk(
        '  ' . $tavanaTakilan . ' aday kaynak başına tavana takıldı '
        . '(kaynak başına en fazla ' . $kaynakBasinaTavan . ').'
    );
}

if ($getirici->siteyleGelenSayisi() > 0) {
    gunluk('  ' . $getirici->siteyleGelenSayisi()
        . ' adres site sunucusu üzerinden alındı (doğrudan erişilemedi).');
}

if ($getirici->siteTavaniDoldu()) {
    gunluk('  Site üzerinden getirme tavanı doldu; kalan adresler yalnızca '
        . 'doğrudan denendi. Sunucuyu yormamak için konulmuş bir sınır.');
}

gunluk(count($adaylar) . ' aday modele gönderilecek.');

// --- 4. Gemini: sınıflandır ve yaz ----------------------------------------

$haberler  = [];
$elenen    = 0;
$yinelenen = 0;
$hatali    = 0;
$kotaBitti = false;

// Adaylar gruplar halinde islenir: tek istekte birden cok haber.
// Ucretsiz katmanda istek sayisi sinirli oldugu icin her haber icin ayri
// cagri yapmak kotayi hemen tuketiyor.
$grupBoyu = max(1, (int) ($secenekler['grup'] ?? 5));
$gruplar  = array_chunk($adaylar, $grupBoyu);

gunluk(count($gruplar) . ' grup halinde işlenecek (grup başına en fazla ' . $grupBoyu . ' aday).');

foreach ($gruplar as $grupNo => $grup) {
    if ($grupNo > 0) {
        sleep(4);
    }

    // Sayfa metinleri once toplanir; model cagrisi tek seferde yapilir.
    $modelAdaylari = [];

    /** @var array<int,string> Aday sirasina gore gorsel adresi */
    $gorseller = [];

    foreach ($grup as $sira => $aday) {
        /*
         * Sayfa genis okunuyor.
         *
         * Varsayilan 6.000 karakterdi; haberin ayrintisi (yururluk
         * tarihi, gecis hukumleri, tutar tablosu) cogu kaynakta metnin
         * asagisinda duruyor ve bu sinirla hic okunmuyordu. Modele
         * giden miktari Yazar ayrica kendi sinirina gore kirpar.
         */
        /*
         * Sayfa okumasi da kalkanli — ama ADAY ATLANMAZ.
         *
         * Hata yakalanip bos metinle devam ediliyor. Adayi listeden
         * duşurmek cazip gorunuyor ama TEHLIKELI: model sonuclari
         * siraya gore eslestiriliyor ve $grup'ta duran bir aday
         * $modelAdaylari'ndan cikarilirsa sonraki butun sonuclar bir
         * kayar. Sonuc sessizce YANLIS olur — haber metni baska bir
         * kaynaga baglanir. Ayni tuzaga pratik bilgilerde dusulmus
         * ve SGK'nin notu TCMB faizine iliştirilmişti.
         *
         * Bos metinle giden aday zararsiz: model baslik ve ozete
         * bakar, yetersiz bulursa "ilgili degil" der.
         */
        try {
            $okunan = $sayfa->oku($aday['girdi']['baglanti'], 30000);
        } catch (Throwable $e) {
            gunluk(sprintf(
                '  sayfa okunamadı, yalnızca başlıkla değerlendirilecek — %s (%s)',
                mb_substr($aday['girdi']['baslik'], 0, 60),
                $e->getMessage()
            ));
            $okunan = ['metin' => '', 'gorsel' => ''];
        }

        // Gorsel once beslemeden, yoksa haber sayfasinin og:image'inden.
        // Besleme gorseli daha guvenilir: siteyi yazan kisi haberin
        // gorselini oraya koyuyor, og:image bazen genel site kapagi.
        $gorseller[$sira] = ($aday['girdi']['gorsel'] ?? '') !== ''
            ? (string) $aday['girdi']['gorsel']
            : $okunan['gorsel'];

        $modelAdaylari[] = [
            'girdi'      => $aday['girdi'],
            'sayfaMetni' => $okunan['metin'],
            'kaynakAdi'  => (string) $aday['kaynak']['ad'],
            'kaynakTuru' => (string) ($aday['kaynak']['tur'] ?? 'rss'),
        ];
    }

    $no = $grupNo + 1;

    try {
        $sonuclar = $yazar->topluIsle($modelAdaylari, $kategoriler, $modeleGosterilecek);
    } catch (KotaBittiException $e) {
        $kalan = count($adaylar) - ($grupNo * $grupBoyu);
        gunluk("  [grup {$no}] günlük model kotası doldu — kalan {$kalan} aday atlanıyor.");
        gunluk('  ' . $e->getMessage());
        $kotaBitti = true;
        break;
    } catch (Throwable $e) {
        $hatali += count($grup);
        gunluk("  [grup {$no}] HATA: " . $e->getMessage());
        continue;
    }

    if ($sonuclar === []) {
        $hatali += count($grup);
        gunluk("  [grup {$no}] yanıt çözümlenemedi.");
        continue;
    }

    foreach ($grup as $sira => $aday) {
        $girdi  = $aday['girdi'];
        $kaynak = $aday['kaynak'];
        $sonuc  = $sonuclar[$sira] ?? null;

        $kisaBaslik = mb_substr($girdi['baslik'], 0, 60, 'UTF-8');

        if ($sonuc === null) {
            $hatali++;
            gunluk("  [grup {$no}] yanıtta yok — {$kisaBaslik}");
            continue;
        }

        if (empty($sonuc['ilgili'])) {
            $elenen++;
            $neden = trim((string) ($sonuc['red_nedeni'] ?? 'belirtilmedi'));

            /*
             * Yinelenen ile "vergi disi" ayri sayiliyor.
             *
             * Ikisi de eleme ama anlamlari bambaska: biri kopya
             * engelinin calistigini, digeri kaynagin alakasiz icerik
             * urettigini gosterir. Ayni satirda toplanirsa kopya
             * engelinin ise yarayip yaramadigi gunlukten anlasilmaz.
             */
            if (stripos($neden, 'yinelenen') === 0) {
                $yinelenen++;
                gunluk("  yinelenen — {$kisaBaslik} ({$neden})");
            } else {
                gunluk("  vergi dışı — {$kisaBaslik} ({$neden})");
            }

            continue;
        }

        $etiketler = $sonuc['etiketler'] ?? [];

        $haberler[] = [
            'baslik'      => (string) $sonuc['baslik'],
            'ozet'        => (string) $sonuc['ozet'],
            'icerik'      => (string) $sonuc['icerik'],
            'etiketler'   => is_array($etiketler) ? implode(', ', $etiketler) : (string) $etiketler,
            'kategori'    => (string) ($sonuc['kategori'] ?? 'genel'),
            'kaynak_id'   => (int) $kaynak['id'],
            'kaynak_adi'  => (string) $kaynak['ad'],
            'kaynak_url'  => $girdi['baglanti'],
            'guven_skoru' => (int) ($sonuc['guven_skoru'] ?? 0),
            'ajan_notu'   => (string) ($sonuc['ajan_notu'] ?? ''),
            'gorsel_url'  => $gorseller[$sira] ?? '',
        ];

        /*
         * Bu calismada kabul edilen baslik sonraki GRUPLARA tasiniyor.
         *
         * Gruplar ayri istekler ve birbirini gormuyor. Gercek bir
         * calismada ayni Fed faiz karari iki ayri grupta iki ayri
         * kaynaktan gelip IKI HABER olarak yazildi; ikisi de modele
         * ayri ayri soruldugu icin ikisi de "yeni" gorundu.
         *
         * Listenin basina ekleniyor: en taze baslik, sinira takilsa
         * bile listede kalmali.
         */
        array_unshift($modeleGosterilecek, (string) $sonuc['baslik']);

        gunluk("  kabul (%{$sonuc['guven_skoru']}, {$sonuc['kategori']}) — {$sonuc['baslik']}");
    }
}

// --- 5. Siteye gönder ------------------------------------------------------

gunluk('---');
gunluk(
    count($haberler) . ' haber yazıldı, ' . $elenen . ' eleme'
    . ($yinelenen > 0 ? ' (' . $yinelenen . ' yinelenen)' : '')
    . ', ' . $hatali . ' hata.'
);

$kullanim = $yazar->kullanim();

if ($kullanim['istek'] > 0) {
    gunluk(sprintf(
        'Model kullanımı: %d istek, %s giriş + %s çıkış token (düşünme dahil).',
        $kullanim['istek'],
        number_format($kullanim['girdi']),
        number_format($kullanim['cikti'])
    ));
}

if ($kotaBitti) {
    gunluk('Günlük model kotası dolduğu için çalışma erken bitti.');
    gunluk('Kota yenilendiğinde ajan kaldığı yerden devam eder; aynı haber');
    gunluk('iki kez eklenmez, kaynak adresi üzerinden kopya engeli var.');
}

if ($kuruCalisma) {
    gunluk('Kuru çalışma: gönderim yapılmadı.');

    foreach ($haberler as $haber) {
        echo PHP_EOL, '--- ', $haber['baslik'], ' ---', PHP_EOL;
        echo 'Grup: ', $haber['kategori'], ' | Güven: %', $haber['guven_skoru'], PHP_EOL;
        echo 'Görsel: ', $haber['gorsel_url'] !== '' ? $haber['gorsel_url'] : '(yok)', PHP_EOL;
        echo 'Not: ', $haber['ajan_notu'], PHP_EOL;
        echo $haber['icerik'], PHP_EOL;
    }

    exit(0);
}

/*
 * Onceki calismalardan bekleyen haberler varsa onlar da gonderilir.
 *
 * Gonderim duserse haberler artik kaybolmuyor, depoda bekliyor. Kopya
 * engeli dort katmanli oldugu icin bekleyenleri yeniden gondermek
 * risksiz: sitede zaten varsa "yinelenen" sayilip atiliyor.
 */
$bekleyen = $depo->oku('bekleyen_haberler', 7);
$bekleyen = is_array($bekleyen) ? $bekleyen : [];

if ($bekleyen !== []) {
    gunluk(count($bekleyen) . ' haber önceki çalışmadan bekliyor, onlar da gönderilecek.');
}

$gonderilecek = array_merge($bekleyen, $haberler);

if ($gonderilecek === []) {
    gunluk('Gönderilecek haber yok.');
    exit(0);
}

try {
    $yanit = $site->gonder($gonderilecek);
} catch (Throwable $e) {
    /*
     * Model cagrilari bu noktada zaten yapildi ve odendi.
     *
     * Eskiden haberler yalnizca gunluge dokuluyor ve calisma hata
     * koduyla bitiyordu; yani emek ve para cope gidiyordu. Artik
     * depoya yaziliyorlar ve siteye ulasilabilen ilk calismada
     * gonderiliyorlar.
     */
    gunluk('Gönderim başarısız: ' . $e->getMessage());

    if ($depo->yaz('bekleyen_haberler', $gonderilecek)) {
        gunluk(count($gonderilecek) . ' haber saklandı; siteye ulaşılabilen '
            . 'ilk çalışmada gönderilecek. Kopya engeli aynı haberi iki kez eklemez.');

        /*
         * Cikis kodu 0: bu bir BASARISIZLIK degil, ertelenmis bir
         * gonderim. Kirmizi bir is, gercekten bozuk bir sey oldugunda
         * fark edilsin diye ayrilmali.
         */
        exit(0);
    }

    // Depoya da yazilamadiysa son care: gunluge dok, elle kurtarilabilsin.
    fwrite(STDERR, "Haberler depoya da yazılamadı; aşağıda:\n\n");
    fwrite(
        STDERR,
        json_encode($gonderilecek, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );

    exit(1);
}

// Gonderim tuttu: bekleyenler artik sitede, depoyu temizle.
$depo->sil('bekleyen_haberler');

gunluk(
    'Siteye gönderildi: ' . ($yanit['eklenen'] ?? 0) . ' yeni taslak, '
    . ($yanit['yinelenen'] ?? 0) . ' zaten vardı.'
);
gunluk('Haberler yönetim panelinde onay bekliyor.');
