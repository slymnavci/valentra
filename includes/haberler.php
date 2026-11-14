<?php
declare(strict_types=1);

/**
 * Haber veri katmani.
 *
 * Akis: ajan 'taslak' olarak yazar -> yonetici onaylar -> 'yayinda' olur ve
 * ana sayfada gorunur. Yayinda olmayan hicbir kayit herkese acik sayfalarda
 * sorgulanmaz.
 */

const HABER_TASLAK      = 'taslak';
const HABER_YAYINDA     = 'yayinda';
const HABER_REDDEDILDI  = 'reddedildi';

/*
 * Resmi Gazete kaynakli haber: ajan haberi Resmi Gazete'nin KENDI
 * metninden yazmis (kaynak adresi resmigazete.gov.tr).
 *
 * Bunlar ana sayfa akisina girmiyor; sitenin "Resmi Gazete" sayfasinda
 * gunun fihristiyle birlikte listeleniyor (yonetici karari: vergi
 * haberleri ana sayfada, Resmi Gazete haberleri kendi bagi altinda).
 * Baska bir sitenin ayni teblig hakkindaki haberi bu kosula girmez.
 */
const HABER_RG_KOSULU = "COALESCE(h.kaynak_url, '') LIKE '%resmigazete.gov.tr%'";

/*
 * Haber akisinin konu basliklari, menu sirasiyla.
 *
 * Ekim 2026'da site vergi/muhasebe yayinindan genel haber ozetlerine
 * dondu (site sahibinin karari). Ust menu, konu bloklari ve ajanin konu
 * listesi YALNIZCA bu basliklari kullaniyor. Eski vergi ve muhasebe
 * kategorileri silinmedi: sayfalari acik, eski haberleri ana sayfa
 * akisinda duruyor; yalnizca menude yoklar.
 *
 * Ajan tarafinda ayni liste ajan/src/HaberAkisi.php'de; biri
 * degisirse oteki de degismeli.
 */
const HABER_AKIS_KATEGORILERI = [
    'gundem', 'politika', 'ekonomi', 'dunya',
    'spor', 'teknoloji', 'saglik', 'kultur-sanat',
    // Magazin (29.10.2026, site sahibinin istegi: ValMagazin'de "sanatcilarin
    // yorumlari"). Olculu: sanatcilarin aciklamalari, roportajlari, yeni
    // isleri; dedikodu ve ozel hayat yok (bkz. ajan/src/Yazar.php).
    'magazin',
    // Yemek tarifleri (22.10.2026, site sahibinin istegi): menunun en
    // sonunda. Haber degil; ana sayfanin manset ve haber izgarasina
    // girmiyor (bkz. haber_ana_akis_kosulu), kendi sayfasi ve ana
    // sayfanin alttaki konu bloklarinda.
    'yemek-tarifleri',
];

/**
 * Ana sayfa akisina girmeyen basliklar: yemek tarifleri haber degil;
 * magazin haberleri yalnizca ValMagazin'de (ana sayfa gundemi ciddi kalsin).
 */
const HABER_AKIS_DISI = ['yemek-tarifleri', 'magazin'];

/*
 * Yerel kaynaklar (04.11.2026, site sahibi: "yerel haber ajanslarini da
 * ekleyelim; Trabzon, Kayseri, Kocaeli gazetelerine de bakip degeri dusuk
 * haberleri ana sayfaya almayacak sekilde arka planda tutalim, gunluk
 * bunlardan da haber gelsin").
 *
 * Yerel kaynagin haberi (haberler.sehir dolu) onemi bu esigin altindaysa
 * ana sayfaya, kategori sayfalarina ve bultene cikmiyor; yayinda kaliyor:
 * haber sayfasi, arama, site haritasi ve ana sayfadaki "Senin Icin"
 * bolumunde o ili secen okura. Esigi gecen yerel haber (buyuk kaza,
 * afet, ulusal yanki) ulusal haber gibi her yerde.
 */
const YEREL_ANA_SAYFA_ESIGI = 75;

/** Dusuk onemli yerel haberleri disarida birakan kosul ("h" takma adiyla). */
function haber_yerel_kosulu(): string
{
    return '(h.sehir IS NULL OR h.onem >= ' . YEREL_ANA_SAYFA_ESIGI . ')';
}

/*
 * Haber sirasi: ONCE BUGUN TOPLANANLAR, kendi icinde EN ONEMLIDEN
 * ONEMSIZE (site sahibinin istegi, 23.10.2026: "halen eski haberler
 * duruyor; bugun toplananlari on plana getir, oncelik en onemli
 * haberden onemsize").
 *
 *   1. Panelden "one cikarilan" haberler — yalnizca bugun toplananlar.
 *      Onceden suresizdi; bir kez one cikarilan haber gunlerce en
 *      ustte kaliyordu.
 *   2. Gun gun: bugun toplananlar, sonra dun, sonra evvelki gun...
 *      "Gun" Istanbul saatiyle gece yarisindan; haberin yasi TOPLANDIGI
 *      andan (olusturuldu). Yayin tarihi onay ani: dun toplanip bu sabah
 *      onaylanan haber "bugunku" sayilmamali.
 *   3. Ayni gunun icinde onem puanina gore (ajan veriyor, panelden
 *      degisir; ayni olayi veren kaynak sayisi icinde). Ana sayfada
 *      okunma da ekleniyor (bkz. haber_sirasi).
 *   4. Esitlerde yeni olan once.
 *
 * Ana sayfada ayrica 1. yerde son dakika (haber_son_dakika_id) ve
 * kaydirakta buyuk mac yuvasi var (haber_ana_sayfa_mac_yuvasi; son dakika
 * yalnizca kaynakta bugun yayimlanmis haberlerden).
 *
 * Onceki "son 24 saat" penceresi sabah 08'de dun 08'den sonraki her
 * haberi yeni sayiyordu; dunun gun boyu okunma toplamis haberleri bu
 * sabahin haberlerinin ustunde kaliyordu.
 *
 * Manset ve izgara AYNI sirayi kullanmali: izgara mansetin tukettigi
 * kadar haberi atliyor.
 */
/** Istanbul saatiyle gece yarisindan beri gecen dakika. */
function haber_bugun_dakika(): int
{
    $simdi = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

    return (int) $simdi->format('G') * 60 + (int) $simdi->format('i');
}

/**
 * Haberin gunu: 0 bugun, 1 dun, 2 evvelki gun... (Istanbul gece yarisindan).
 *
 * Zaman, kaynagin yayin zamani ile toplanma aninin ERKEN olani: ajan son
 * 36 saati tariyor, carsamba yayimlanmis haber cuma sabahi toplanabiliyor.
 * Eski kayitlarda (ve tarihsiz kaynaklarda) kaynak_tarihi yok; toplanma
 * ani kullaniliyor.
 *
 * SPOR icin "bugun" en az son 12 saat (site sahibinin istegi, 23.10.2026:
 * "onemli olan dunku maclar"). Maclar aksam oynaniyor; sabah okuyucusu
 * icin dun aksamki mac bugunun haberi. Diger basliklarda gece yarisi.
 * $kategoriBilinir: sorguda "k" (kategoriler) birlesimi var mi.
 */
function haber_gun_ifadesi(bool $kategoriBilinir = true, bool $anaSayfa = false): string
{
    // Gece yarisindan beri gecen dakika PHP'den: veritabaninin saat dilimi
    // bilinmiyor (paylasimli sunucu), NOW() ile farki ise dilimden bagimsiz.
    $gunDakika = haber_bugun_dakika();

    /*
     * Ana sayfada 12 saatlik spor gunu yalnizca Turkiye'nin buyuk basarisi
     * olan spor haberinde (haber_onde_spor_kosulu; 30.10.2026, site sahibi:
     * "ana sayfada en cok spor haberi var"). Gece ve sabah dunku butun spor
     * haberleri "bugun", dunku gundem haberleri "dun" sayiliyordu; spor ilk
     * sayfayi dolduruyordu. ValSpor ve spor sayfalarinda butun spor
     * haberleri eskisi gibi.
     */
    $spor = $anaSayfa
        ? haber_onde_spor_kosulu()
        : "k.slug = 'spor'";
    $pencere   = $kategoriBilinir
        ? 'CASE WHEN ' . $spor . ' THEN ' . max($gunDakika, 720) . ' ELSE ' . $gunDakika . ' END'
        : (string) $gunDakika;
    $yas = haber_yas_dakika_ifadesi();

    return 'GREATEST(0, CEIL((' . $yas . ' - ' . $pencere . ') / 1440))';
}

/**
 * Haberin yasi (dakika): kaynagin yayin ani ile toplanma aninin erken olani
 * (bkz. haber_gun_ifadesi).
 */
function haber_yas_dakika_ifadesi(): string
{
    return 'TIMESTAMPDIFF(MINUTE, LEAST(h.olusturuldu, COALESCE(h.kaynak_tarihi, h.olusturuldu)), NOW())';
}

/*
 * ETKIN ONEM: onem puani haber eskidikce azaliyor (site sahibinin
 * karari, 23.10.2026: "haberin onem derecesi yayin tarihine de bakarak
 * karar verilmeli; eski haberlerin onem degeri gitgide azalsin; 4 saat
 * gectiyse %10 azalsin; dunun haberiyse 50 altinda olsun"). Dunku haber ana
 * sayfada bugunun haberlerinin arkasinda (gun basamagi, haber_sirasi_temel).
 *
 *   - Bugunun haberi: saatte %2,5 (4 saatte %10, 8 saatte %20, 12 saatte
 *     %30). Onem 75, 4 saatlik haber 67,5; yeni gelen onem 70 haber onun
 *     ustunde.
 *   - Dunun ve daha eski haberler (Istanbul gece yarisindan once
 *     yayimlanmis): ayni azalma, ustelik en fazla 49.
 *
 * Yas, kaynagin yayin ani ile toplanma aninin erken olani: dun 14:30'da
 * yayimlanip bugun toplanan haber dunun haberi. Kazinan kaynaklarda
 * yayin ani haber sayfasindan okunuyor (ajan, Sayfa::yayinZamani).
 *
 * Onceki "guncellik puani" (+50, saatte 5 azalan ek puan) bunun yerine
 * kaldirildi: eski haber yuksek onemiyle yeni haberin onunde
 * kalabiliyordu. Puan sorgu aninda hesaplaniyor; her sayfa acilisinda
 * butun haberler o anki saate gore yeniden siralaniyor.
 */
const HABER_ESKIME_YUZDE_SAATLIK = 2.5;
const HABER_DUNKU_ONEM_TAVANI    = 49;
const HABER_GUNCELLIK_DAKIKASI   = 12;   // son dakika puani: 12 dakikada 1 puan = saatte 5

function haber_etkin_onem_ifadesi(): string
{
    $yas   = haber_yas_dakika_ifadesi();
    $etkin = 'h.onem * GREATEST(0, 1 - ' . HABER_ESKIME_YUZDE_SAATLIK . ' * ' . $yas . ' / 6000)';

    return 'CASE WHEN ' . $yas . ' > ' . haber_bugun_dakika()
        . ' THEN LEAST(' . HABER_DUNKU_ONEM_TAVANI . ', ' . $etkin . ') ELSE ' . $etkin . ' END';
}

/*
 * SON DAKIKA PUANI (site sahibinin istegi, 23.10.2026: "son dakika
 * haberleri daha yuksek derece alsin").
 *
 * Yapay zeka haberi okuyup son dakika dediyse (h.son_dakika) +30; saatte
 * 5 azaliyor, 6 saatte sifir. Son dakika haberi taze oldugu surece
 * degerli; aksama kadar ustte kalmamali. 04.11.2026'ya kadar kaynagin
 * basliginda ya da adresinde "son dakika" gecmesi yetiyordu; bazi
 * siteler eski haberi de boyle isaretledigi icin birkac gunluk haber son
 * dakika oldu (site sahibi: "once haberi okusun, ona gore karar versin").
 */
const HABER_SON_DAKIKA_TAVANI = 30;

function haber_son_dakika_ifadesi(): string
{
    return 'CASE WHEN h.son_dakika = 1
              THEN GREATEST(0, ' . HABER_SON_DAKIKA_TAVANI . ' - '
        . haber_yas_dakika_ifadesi() . ' / ' . HABER_GUNCELLIK_DAKIKASI . ') ELSE 0 END';
}

/*
 * DORT BUYUK KULUP (site sahibinin istegi, 25.10.2026: "spor haberlerinde
 * Galatasaray, Fenerbahce, Besiktas, Trabzonspor'un maclari daha agirlikli
 * olsun; en cok okunma buradan geliyor; ama dunyadan spor haberleri de
 * olsun"). Basliginda bu kuluplerden biri gecen spor haberine siralamada
 * +15 puan: ayni gunun diger spor haberlerinin onune geciyor, ama gun
 * basamagi ve dalga degismiyor; dunya sporu sayfadan cikmiyor, arkaya
 * diziliyor. Ajan da bu haberlere yuksek onem veriyor (ajan/src/Yazar.php).
 * Sutunlar utf8mb4_unicode_ci: "BEŞİKTAŞ", "Besiktas" ayni.
 */
const HABER_BUYUK_KULUP_PUANI = 15;
const HABER_BUYUK_KULUPLER    = ['galatasaray', 'fenerbahçe', 'beşiktaş', 'trabzonspor'];

function haber_buyuk_kulup_ifadesi(): string
{
    return "CASE WHEN k.slug = 'spor' AND " . haber_baslikta_gecen_kosulu(HABER_BUYUK_KULUPLER) . ' THEN '
        . HABER_BUYUK_KULUP_PUANI . ' ELSE 0 END';
}

/** Basliginda kelimelerden biri gecen haber (SQL kosulu). */
function haber_baslikta_gecen_kosulu(array $kelimeler): string
{
    return '(' . implode(' OR ', array_map(
        static fn (string $k): string => 'h.baslik LIKE ' . db()->quote('%' . $k . '%'),
        $kelimeler
    )) . ')';
}

/*
 * ANA SAYFADA SPOR ARKADA (site sahibinin istegi, 30.10.2026: "ana sayfada
 * en cok spor haberi var; buyuk takimlarin maclari yoksa spor haberleri
 * arka plana alinabilir; ilk sayfalarda gundemdeki onemli haberler olmali").
 *
 * Ayni gun daraltildi (site sahibi, 30.10.2026: "Turkiye'de ilgi odagina
 * baktigimizda en onemli haberler politika, gundem, kazalar, olum,
 * yaralanma, teror haberleri; futbol, basketbol ve voleybol cok arkada;
 * mesela Turkiye bir kupa kazandiysa ana haberde olabilir"). Onceden dort
 * buyuk kulup ve milli takimlar onde sayiliyordu; artik butun spor haberleri
 * gunun onemli (ANA_SAYFA_ONEM_ESIGI) haberlerinin arkasinda. Tek istisna
 * Turkiye'nin buyuk basarisi: ajan kupa, sampiyonluk ve madalyaya 88-100
 * veriyor (ajan/src/Yazar.php, ONEM PUANI); onemi ANA_SAYFA_SPOR_ONDE_ESIGI
 * ve ustu spor haberi gundem haberleriyle ayni sirada yarisiyor. Eski
 * yonergede buyuk kulup maclari en fazla 85 aliyordu, yani onceden toplanmis
 * haberler de arkada. Toplama dalgasindan once uygulaniyor: yeni toplanan
 * spor haberi daha once toplanmis gundem haberini de one gecmiyor. Spor
 * haberlerinin hepsi ValSpor'da.
 */
/*
 * 88'den 70'e (12.11.2026, site sahibi: "burada en onemli haber milli mac
 * skoru ve yorumlar"). Siradan spor haberi artik ana sayfaya hic girmiyor
 * (SPOR_ANA_SAYFA_ESIGI = 70; asagida), yani "arkaya alinacak" spor
 * kalmadi: esigi gecen spor haberi (milli mac sonucu, sampiyonlugu
 * belirleyen mac) zaten onemli diye girdi. 88'de kalinca 74 puanli
 * Turkiye-Belcika mac sonucu gunun onemi 60 ve ustu butun haberlerinin
 * arkasina, 56 haberlik ana sayfa sinirinin disina dustu; ana sayfada hic
 * gorunmedi. Iki esik ayni olmali. "Ilk haber spor olmasin" kurali
 * (haber_ana_sayfa_basi_id) yerinde.
 */
const ANA_SAYFA_SPOR_ONDE_ESIGI = 70;

function haber_onde_spor_kosulu(): string
{
    return "(k.slug = 'spor' AND h.onem >= " . ANA_SAYFA_SPOR_ONDE_ESIGI . ')';
}

function haber_arkadaki_spor_ifadesi(): string
{
    return "(k.slug = 'spor' AND h.onem < " . ANA_SAYFA_SPOR_ONDE_ESIGI . ')';
}

/*
 * SIRADAN SPOR HABERI ANA SAYFADA YOK (site sahibi, 12.11.2026: "Ozan
 * Tufan'in milli takim kadrosunda yer bulamamasi cok onemli bir haber
 * degil; bu haberleri ana sayfaya koyma, spor haberlerinde olabilir. Burada
 * en onemli haber milli mac skoru ve yorumlar. Voleybol, basketbolda dunya
 * sampiyonu olmadiysa burada yeri olmamali; onemli sampiyonluk maci,
 * Turkiye basket maci varsa bunlar yayinlanabilir").
 *
 * Yukaridaki kural sporu yalnizca ARKAYA aliyordu; gundem haberi azalinca
 * kadro, transfer, aciklama haberleri yine "Bugun bilmeniz gereken 5
 * gelisme"ye ve mansete cikiyordu. Artik onemi SPOR_ANA_SAYFA_ESIGI'nin
 * altindaki spor haberi ana sayfa akisina (manset, 5 gelisme, izgara,
 * bulten) hic girmiyor; yayinda kaliyor ve ValSpor'da gorunuyor. Esigi
 * gecenler: milli takim mac sonuclari ve degerlendirmeleri, sampiyonlugu
 * belirleyen maclar, kupa/madalya (ajan bunlara 70+ veriyor; bkz.
 * ajan/src/Yazar.php, ONEM PUANI). Bu karardan ONCE toplanmis spor
 * haberleri eski yonergeyle en fazla 60 almisti: onlar da ana sayfadan
 * cikar, yeni toplananlar yeni puanla gelir.
 */
const SPOR_ANA_SAYFA_ESIGI = 70; // ANA_SAYFA_SPOR_ONDE_ESIGI ile ayni olmali

/** Onemi dusuk spor haberlerini disarida birakan kosul ("h" takma adiyla). */
function haber_siradan_spor_disi_kosulu(): string
{
    return '(h.onem >= ' . SPOR_ANA_SAYFA_ESIGI . ' OR h.kategori_id IS NULL OR h.kategori_id NOT IN'
        . " (SELECT id FROM kategoriler WHERE slug = 'spor'))";
}

/*
 * SON DAKIKA YUVASI (site sahibinin karari, 23.10.2026: "en basa en yeni
 * haberleri koyup son dakika yapalim; bir sonrakinde onem derecesine
 * gore geri gitsin, oradaki haber son dakika olsun; her seferinde en
 * onemli haber son dakika olarak en one gelsin").
 *
 * Kaydiragin 1. slayti: SON TOPLAMADA gelen haberlerin en onemlisi,
 * "SON DAKIKA" etiketiyle. Ajan iki saatte bir calisiyor; bir sonraki
 * toplamada yeni gelenlerin en onemlisi bu yere geciyor, onceki son
 * dakika etiketini kaybedip etkin onemine gore sirasina donuyor.
 *
 * "Son toplama": en son toplanan haberden geriye, aralarinda 10
 * dakikadan uzun bosluk olmayan haberler. Bir calismanin haberleri
 * birkac dakika icinde geliyor; panelden 15 dakika sonra yeniden
 * toplanirsa o da ayri bir toplama sayiliyor. Bunlar ayni anda
 * toplandigi icin etkin degil HAM onem karsilastiriliyor; esitlikte en
 * yenisi. Teknoloji ve kultur-sanat bu yere girmiyor (ana sayfada en
 * sonda, bkz. haber_sirasi_temel).
 */
const HABER_TOPLAMA_BOSLUGU_SANIYE = 600;

/*
 * Yapay zeka haberi okuyup son dakika demis olmali (h.son_dakika;
 * 04.11.2026, site sahibi: "son dakika diye cikan haber birkac gunluk;
 * once haberi okusun, ona gore karar versin"). Demediyse yuva bos kalir.
 *
 * Son dakika olabilmek icin haberin KAYNAKTA en fazla 3 saat once ve
 * bugun yayimlanmis olmasi gerekiyor (site sahibi, 23.10.2026: Euronews'un
 * dun 22:57'de yayimladigi deprem haberi ertesi sabah toplanip son dakika
 * oldu; "bizde son dakika bu olur mu?"). Yas, kaynagin yayin ani ile
 * toplanma aninin erken olani (haber_yas_dakika_ifadesi). Son toplamada
 * bu kosulu tutan haber yoksa son dakika yeri bos kalir; sayfa puan
 * sirasiyla baslar.
 */
const HABER_SON_DAKIKA_EN_FAZLA_DAKIKA = 180;

/**
 * SON DAKIKA BANDI (13.11.2026; bkz. includes/oncelik.php, oncelik_bant):
 * yalnizca acil, onemli, dogrulanmis ve Turkiye'yi dogrudan ilgilendiren
 * haberler. Bant ayari 'yayin' degilse (onizleme) bos doner: sitede eski
 * duzen surer, yeni bant panelde mevcut seritle karsilastirilir.
 *
 * @return list<array{baslik:string,slug:string,zaman:string}>
 */
function haber_son_dakika_bandi(int $adet = 6): array
{
    static $onbellek = null;

    if ($onbellek !== null) {
        return $onbellek;
    }

    require_once __DIR__ . '/oncelik.php';

    if (!oncelik_bant_yayinda()) {
        return $onbellek = [];
    }

    return $onbellek = array_map(static fn (array $s): array => [
        'baslik' => (string) $s['baslik'],
        'slug'   => (string) $s['slug'],
        'zaman'  => (string) $s['zaman'],
    ], oncelik_bant($adet));
}

/**
 * Üstteki kayan şerit: kaynaktaki yayın anına göre en yeni haberler
 * (kronolojik "son haberler"; bant yayındayken adı "Son Haberler").
 *
 * Site sahibinin istegi (24.10.2026): "en ustte de kayan son dakika
 * haberleri gecsin". Sira kaynaktaki yayin anina gore (en yeni once);
 * ana sayfanin kosulu gecerli (tarihi bilinen, Resmi Gazete ve tarif
 * disinda). Onceden yalnizca bugunun haberleriydi; gece yarisi serit
 * bosaliyordu (25.10.2026). Artik en yeni 12 haber, gun ayrimi yok.
 *
 * @return list<array{baslik:string,slug:string,zaman:string}>
 */
function haber_son_dakika_seridi(int $adet = 12): array
{
    static $onbellek = null;

    if ($onbellek !== null) {
        return $onbellek;
    }

    try {
        $satirlar = db()->query(
            'SELECT h.baslik, h.slug,
                    DATE_FORMAT(LEAST(h.olusturuldu, COALESCE(h.kaynak_tarihi, h.olusturuldu)), \'%H:%i\') AS zaman
               FROM haberler h
              WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . haber_ana_akis_kosulu() . '
              ORDER BY LEAST(h.olusturuldu, COALESCE(h.kaynak_tarihi, h.olusturuldu)) DESC, h.id DESC
              LIMIT ' . max(1, min(30, $adet)) * 3
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] son dakika seridi: ' . $e->getMessage());
        $satirlar = [];
    }

    /*
     * Ayni konu seritte bir kez (site sahibinin istegi, 08.11.2026: "cok
     * sayida gundem basligi yerine birbirini tekrar etmeyen gundem"). En
     * yeni haber kaliyor; ayni konunun eski acilari atlaniyor.
     */
    require_once __DIR__ . '/konu_siniri.php';

    $kokler = [];
    $tekil  = [];

    foreach ($satirlar as $s) {
        $k = konu_kokleri((string) $s['baslik']);

        foreach ($kokler as $onceki) {
            if (konu_ayni_mi($k, $onceki)) {
                continue 2;
            }
        }

        $kokler[] = $k;
        $tekil[]  = $s;

        if (count($tekil) >= $adet) {
            break;
        }
    }

    $satirlar = $tekil;

    return $onbellek = array_map(static fn (array $s): array => [
        'baslik' => (string) $s['baslik'],
        'slug'   => (string) $s['slug'],
        'zaman'  => (string) $s['zaman'],
    ], $satirlar);
}

function haber_son_dakika_id(): ?int
{
    static $onbellek = false;

    if ($onbellek !== false) {
        return $onbellek;
    }

    $onbellek = null;

    try {
        $satirlar = db()->query(
            'SELECT h.id, h.onem, UNIX_TIMESTAMP(h.olusturuldu) AS zaman,
                    ' . haber_yas_dakika_ifadesi() . ' AS yas
               FROM haberler h
              WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . haber_ana_akis_kosulu() . "
                AND h.kaynak_tarihi IS NOT NULL
                AND h.son_dakika = 1
                AND h.onem >= " . ANA_SAYFA_ONEM_ESIGI . "
                AND (h.kategori_id IS NULL OR h.kategori_id NOT IN
                     (SELECT id FROM kategoriler WHERE slug IN ('teknoloji', 'kultur-sanat'"
                     // Canli mac yoksa spor son dakika olmuyor (30.10.2026).
                     . (haber_ana_sayfa_spor_basta_olabilir() ? '' : ", 'spor'") . ")))
              ORDER BY h.olusturuldu DESC, h.id DESC
              LIMIT 100"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] son dakika secilemedi: ' . $e->getMessage());

        return $onbellek;
    }

    $enIyiOnem = -1;
    $onceki    = null;
    $enFazlaYas = min(HABER_SON_DAKIKA_EN_FAZLA_DAKIKA, haber_bugun_dakika());

    foreach ($satirlar as $satir) {
        $zaman = (int) $satir['zaman'];

        if ($onceki !== null && $onceki - $zaman > HABER_TOPLAMA_BOSLUGU_SANIYE) {
            break;
        }

        // Toplama sinirini eski haberler de belirliyor; aday olamiyorlar.
        $taze = (int) $satir['yas'] <= $enFazlaYas;

        // Yeniden eskiye gidiliyor: esitlikte ilk gorulen (en yeni) kalir.
        if ($taze && (int) $satir['onem'] > $enIyiOnem) {
            $enIyiOnem = (int) $satir['onem'];
            $onbellek  = (int) $satir['id'];
        }

        $onceki = $zaman;
    }

    return $onbellek;
}

function haber_sirasi(bool $anaSayfa = false): string
{
    $temel = haber_sirasi_temel($anaSayfa);

    if (!$anaSayfa) {
        return $temel;
    }

    // Son dakika her seyin onunde (bkz. haber_son_dakika_id). Manset ve
    // izgara ayni ORDER BY'i kullandigi icin haber iki kez cikmiyor.
    $sonDakika = haber_son_dakika_id();

    /*
     * ONCELIK BASI (12.11.2026; bkz. includes/oncelik.php): editorun
     * mansete aldiklari, sonra kritik ve yuksek oncelikli haberler. Son
     * dakika yuvasi bunlarin ARKASINDA: son toplamanin en onemli olagan
     * haberi, uc saat once dogrulanmis kritik haberin onune gecmesin.
     */
    require_once __DIR__ . '/oncelik.php';

    $onu = oncelik_sira_ust() . ($sonDakika !== null ? '(h.id = ' . $sonDakika . ') DESC, ' : '');

    /*
     * Sonra puan sirasi. "En yeni 5 haber 4-8. slaytta" ve "gunun en onemli
     * 3 haberi" kurallari kaldirildi (site sahibi, 23.10.2026: "once
     * puanlamayi yap, ondan sonra sirayla ana sayfaya gelsin"): tazelik
     * artik puanin icinde.
     */

    // Ilk haber spor olmasin (canli mac yoksa); bkz. haber_ana_sayfa_basi_id.
    $basi = $sonDakika === null ? haber_ana_sayfa_basi_id($temel) : null;
    $sira = $onu . ($basi !== null ? '(h.id = ' . $basi . ') DESC, ' : '') . $temel;

    // Buyuk kulubun maci kaydirakta ANA_SAYFA_MAC_YERI. sirada; bkz.
    // haber_ana_sayfa_mac_yuvasi.
    $mac = haber_ana_sayfa_mac_yuvasi($sira);

    if ($mac === null) {
        return $sira;
    }

    [$macId, $onundekiler] = $mac;

    return ($onundekiler !== [] ? '(h.id IN (' . implode(', ', $onundekiler) . ')) DESC, ' : '')
        . '(h.id = ' . $macId . ') DESC, '
        . $sira;
}

/*
 * ILK HABER SPOR OLMAZ (site sahibinin istegi, 30.10.2026: "baktigimda
 * yine ilk haberler spor haberi; canli bir mac yoksa spor en iyi 2. veya
 * 3. siradan baslayabilir; ilk haber olarak spor haberi koymayalim").
 * Canli mac yoksa (futbol_canli_mac_var) son dakika yuvasina spor girmiyor
 * ve sira spor haberiyle basliyorsa en iyi spor disi haber basa aliniyor;
 * sporun geri kalani yerinde, yani en iyi ikinci sirada.
 */
function haber_ana_sayfa_spor_basta_olabilir(): bool
{
    require_once __DIR__ . '/futbol.php';

    return futbol_canli_mac_var();
}

/** Siranin basi spor haberiyse yerine gececek spor disi haber; degilse null. */
function haber_ana_sayfa_basi_id(string $sira): ?int
{
    static $onbellek = [];

    if (array_key_exists($sira, $onbellek)) {
        return $onbellek[$sira];
    }

    $onbellek[$sira] = null;

    if (haber_ana_sayfa_spor_basta_olabilir()) {
        return null;
    }

    try {
        $satirlar = db()->query(
            'SELECT h.id, k.slug
               FROM haberler h
               LEFT JOIN kategoriler k ON k.id = h.kategori_id
               ' . haber_okunma_birlesimi() . '
              WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . haber_ana_akis_kosulu() . '
              ORDER BY ' . $sira . '
              LIMIT 20'
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] ana sayfa basi: ' . $e->getMessage());

        return null;
    }

    if ($satirlar === [] || ($satirlar[0]['slug'] ?? '') !== 'spor') {
        return null;
    }

    foreach ($satirlar as $s) {
        if (($s['slug'] ?? '') !== 'spor') {
            return $onbellek[$sira] = (int) $s['id'];
        }
    }

    return null;
}

function haber_sirasi_temel(bool $anaSayfa): string
{
    $gun = haber_gun_ifadesi(true, $anaSayfa);

    /*
     * Ana sayfada puana okunma da giriyor (son 24 saatte haberi acan
     * farkli okuyucu: 1 okuyucu +10, 3 okuyucu +20, 7 ve uzeri +30;
     * haber_okunma_birlesimi, "zo"). Teknoloji ve kultur-sanat gunun
     * sonunda (site sahibinin karari, 22.10.2026: "teknoloji, sanat ve
     * kultur en sonda"). Konu sayfalarinda ikisi de yok: orada hepsi ayni
     * baslikta.
     */
    $onem = haber_etkin_onem_ifadesi() . ' + ' . haber_son_dakika_ifadesi() . ' + ' . haber_buyuk_kulup_ifadesi();
    $puan = $anaSayfa
        ? '(k.slug IN (\'teknoloji\', \'kultur-sanat\')) ASC,
           ' . $onem . ' + LEAST(30, 10 * LOG2(1 + COALESCE(zo.okuyucu, 0))) DESC'
        : $onem . ' DESC';

    /*
     * Gun basamagi once, kendi icinde puan sirasi: bugunun haberleri
     * dunkulerin (ana sayfada) ve eskilerin (konu sayfalarinda) onunde. Spor icin dun aksamki mac
     * konu sayfasinda "bugun" (bkz. haber_gun_ifadesi).
     *
     * Ana sayfada gunun icinde TOPLAMA DALGASI (bkz. ANA_SAYFA_DALGA_DAKIKA).
     */
    /*
     * Ana sayfada ONCE oncelik sinifi (12.11.2026; bkz. oncelik_sira_ust):
     * gun basamagi ve toplama dalgasi kritik/yuksek haberin onune gecmez.
     * 12.11.2026 aksami onemi 92 olan askeri helikopter kazasi, iki saat
     * sonraki toplamanin onemi 60 ve ustu butun haberlerinin arkasina
     * dusmustu: dalga onemden once geliyordu.
     */
    if ($anaSayfa) {
        require_once __DIR__ . '/oncelik.php';
    }

    return ($anaSayfa ? oncelik_sira_ust() : '') . '(h.one_cikan = 1 AND ' . $gun . ' = 0) DESC,
        ' . $gun . ' ASC,
        ' . ($anaSayfa ? '(h.onem >= ' . ANA_SAYFA_ONEM_ESIGI . ') DESC,
        ' . haber_arkadaki_spor_ifadesi() . ' ASC,
        ' . haber_dalga_ifadesi() . ' ASC,
        ' : '') . $puan . ',
        h.olusturuldu DESC,
        h.id DESC';
}

/*
 * TOPLAMA DALGASI (site sahibinin karari, 23.10.2026: "yeni gelen
 * haberleri her zaman son 2 saatlik haberler olarak arayalim; buradan
 * onemli olanlari ana sayfaya koyalim; eski haberler yavas yavas sirayla
 * arkaya dogru gitsin; boylece yeni haberleri hep one alalim").
 *
 * Ajan iki saatte bir son 2 saatin haberlerini topluyor. Ana sayfa
 * once siteye en son 2 saatte GELEN haberleri (dalga 0) kendi
 * icinde onem sirasiyla diziyor, sonra 2-4 saat once gelenleri (dalga
 * 1), sonra 4-6... Eski haber, onemi ne olursa olsun yeni toplamanin
 * arkasina geciyor; her yeni toplamada bir dalga geriye kayiyor.
 * Dalga toplanma anina gore (olusturuldu): ayni calismanin haberleri
 * ayni dalgada kaliyor. Gun basamagi dalgadan once: dunku haber, dalgasi
 * ne olursa olsun bugunun haberlerinin arkasinda.
 */
const ANA_SAYFA_DALGA_DAKIKA = 120;

/*
 * ONEM ESIGI (site sahibinin karari, 23.10.2026: "onem %60 ve uzerinde
 * olanlari ana sayfada basa al, digerlerini geriye atalim; yeni haber
 * geldikce eskiler slayttan ciksin"). Ana sayfa once onemi 60 ve ustu
 * haberleri diziyor (en yeni toplama once, her toplamanin icinde onem
 * sirasi), onemi 60'in altindakiler hepsinin arkasinda ayni duzenle.
 * Kaydirak ilk 20: yeni toplamanin onemli haberleri geldikce eski
 * toplamalarinkiler 20'nin disina, izgaraya kayiyor.
 *
 * Ayni esik ana sayfanin butun kurallarinda ("hepsini bu sekilde
 * yapalim"): son dakika yuvasi (haber_son_dakika_id) yalnizca onemi 60 ve
 * ustu haberlerden. Buyuk mac yuvasinda esik yok (30.10.2026; bkz.
 * haber_ana_sayfa_mac_yuvasi).
 */
const ANA_SAYFA_ONEM_ESIGI = 60;

function haber_dalga_ifadesi(): string
{
    return 'FLOOR(GREATEST(0, TIMESTAMPDIFF(MINUTE, h.olusturuldu, NOW())) / ' . ANA_SAYFA_DALGA_DAKIKA . ')';
}

/*
 * BUYUK MAC YUVASI (site sahibi, 30.10.2026: "guncel haberler gelmiyor;
 * mesela Galatasaray-Konyaspor maci neden yok; onu vermek onemli; 3. veya
 * 4. sirada olabilir, ana sayfada kayar blokta").
 *
 * Ayni gun spor gundemin arkasina alinmisti (bkz. ANA_SAYFA_SPOR_ONDE_ESIGI)
 * ve kaydiraktaki spor kotasi yalnizca onemi 60 ve ustu haberi aliyordu; ajan
 * ise artik buyuk kulup maclarina 45-60 veriyor. Galatasaray'in maci boylece
 * ne kotaya ne kaydiraga girdi. Kota (20'nin en altinda bir spor haberi)
 * kaldirildi; yerine:
 *
 * Dort buyuk kulubun ya da milli takimlarin bugunku (spor icin dun aksamki
 * mac da bugun, bkz. haber_gun_ifadesi) en iyi haberi kaydirakta
 * ANA_SAYFA_MAC_YERI. sirada. Onem esigi yok: buyuk kulubun maci okurun
 * bekledigi haber. Haber zaten o siranin onundeyse (canli mac, Turkiye'nin
 * kupasi) dokunulmuyor. Diger spor haberleri gundemin arkasinda kaliyor.
 *
 * Sira SQL'de kuruluyor (onundeki haberler, sonra mac, sonra asil sira):
 * manset ve izgara ayni ORDER BY'i kullaniyor ve izgara mansetin
 * tukettigi kadar haberi atliyor; ikisi farkli sira gorurse haber ya iki
 * kez cikar ya hic cikmaz.
 */
const ANA_SAYFA_MANSET_ADEDI = 20;
const ANA_SAYFA_MAC_YERI     = 3;

const HABER_MAC_KELIMELERI = [
    'galatasaray', 'fenerbahçe', 'beşiktaş', 'trabzonspor',
    'milli takım', 'a milli', 'filenin sultanları', 'filenin efeleri', '12 dev adam',
];

/**
 * Maç yuvası: [maç haberinin id'si, önünde kalacak haberlerin id'leri];
 * yerleştirilecek haber yoksa ya da zaten öndeyse null.
 *
 * @return array{0:int,1:list<int>}|null
 */
function haber_ana_sayfa_mac_yuvasi(string $sira): ?array
{
    static $onbellek = [];

    if (array_key_exists($sira, $onbellek)) {
        return $onbellek[$sira];
    }

    $onbellek[$sira] = null;

    $kaynak = 'FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
           ' . haber_okunma_birlesimi() . '
          WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . haber_ana_akis_kosulu();

    try {
        $mac = db()->query(
            'SELECT h.id ' . $kaynak
            . " AND k.slug = 'spor'"
            . ' AND ' . haber_baslikta_gecen_kosulu(HABER_MAC_KELIMELERI)
            . ' AND ' . haber_gun_ifadesi() . ' = 0'
            . ' ORDER BY ' . haber_sirasi_temel(true) . ' LIMIT 1'
        )->fetchColumn();

        if ($mac === false) {
            return null;
        }

        $ilk = array_map('intval', db()->query(
            'SELECT h.id ' . $kaynak . ' ORDER BY ' . $sira . ' LIMIT ' . (ANA_SAYFA_MAC_YERI - 1)
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[valentra] ana sayfa mac yuvasi: ' . $e->getMessage());

        return null;
    }

    if (in_array((int) $mac, $ilk, true)) {
        return null;
    }

    return $onbellek[$sira] = [(int) $mac, $ilk];
}

/**
 * Son 24 saatin okunma sayıları (farklı okuyucu), ana sayfa sırası için.
 *
 * Sayim haber_okuyucu tablosunda, 5 dakikada bir tazeleniyor (sayfa
 * gonderildikten sonra; bkz. haber_okuyucu_tazele). Onceden her sorguda
 * ziyaretler tablosunun son gunu yeniden sayiliyordu: ana sayfa bunu
 * dort bes kez yapiyordu ve buyuk tabloda sayfa saniyelerce bekliyordu
 * (site sahibi, 25.10.2026: "site acilinca once ust, biraz sonra alt
 * aciliyor"). Tablo henuz hic dolmadiysa (ilk kurulum) eski yol.
 */
function haber_okunma_birlesimi(): string
{
    require_once __DIR__ . '/canli_onbellek.php';

    static $hazir = null;

    if ($hazir === null) {
        $hazir = canli_onbellek('haber_okuyucu', 300, 'haber_okuyucu_tazele')['veri'] !== null;
    }

    if ($hazir) {
        return 'LEFT JOIN haber_okuyucu zo ON zo.haber_id = h.id';
    }

    return 'LEFT JOIN (' . haber_okuyucu_sayim_sorgusu() . ') zo ON zo.haber_id = h.id';
}

/** Son 24 saatte haberi acan farkli okuyucu sayisi (haber_id, okuyucu). */
function haber_okuyucu_sayim_sorgusu(): string
{
    // Botlar ve hic gosterilmeyen onyuklemeler sayilmasin; ayni kisi
    // adres degistirdiginde iki okuyucu sayilmasin (bkz. includes/ziyaret.php).
    require_once __DIR__ . '/ziyaret.php';

    $yeni   = ziyaret_yeni_sema();
    $kimlik = $yeni ? ziyaret_kimlik_ifadesi() : 'ziyaretci';
    $insan  = $yeni ? ziyaret_insan_kosulu() : '1=1';

    return 'SELECT haber_id, COUNT(DISTINCT ' . $kimlik . ') AS okuyucu
              FROM ziyaretler
             WHERE haber_id IS NOT NULL
               AND zaman >= NOW() - INTERVAL 1 DAY
               AND ' . $insan . '
             GROUP BY haber_id';
}

/**
 * haber_okuyucu tablosunu yeniden doldurur (canli_onbellek uretici).
 *
 * @return array{adet:int}|null tablo yoksa (sema henuz guncellenmediyse) null
 */
function haber_okuyucu_tazele(): ?array
{
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM haber_okuyucu');
        $adet = $pdo->exec('INSERT INTO haber_okuyucu (haber_id, okuyucu) ' . haber_okuyucu_sayim_sorgusu());
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[valentra] haber okuyucu sayimi: ' . $e->getMessage());

        return null;
    }

    return ['adet' => (int) $adet];
}

/** Ana sayfa sorgusunun bakacagi en eski haber (gun); bkz. haber_ana_akis_kosulu. */
const HABER_ANA_SAYFA_EN_FAZLA_GUN = 14;

/**
 * Ana sayfa akisinin kosulu: Resmi Gazete kaynakli olmayan, haber olan
 * (yemek tarifi gibi akis disi bir baslikta olmayan), kaynak tarihi
 * bilinen haber. Tarih penceresi yok; siniri haber sayisi koyuyor.
 */
function haber_ana_akis_kosulu(bool $yerelDahil = false, bool $konuSiniri = true, bool $sporDahil = false): string
{
    $disarida = implode(', ', array_map(
        static fn (string $slug): string => db()->quote($slug),
        HABER_AKIS_DISI
    ));

    /*
     * KAYAN ANA SAYFA (site sahibi, 23.10.2026: "yeni gune gecince tum
     * haberler gitti; eski haberler, mesela dunkuler kalsin; yeni haber
     * eklendikce eskiler yavas yavas ana sayfadan kalksin"; 25.10.2026:
     * "gece saat 12 oldugunda eski haberler kayboluyor; eski haberler her
     * zaman dursun, yeni haber geldikce kaybolsun").
     *
     * Onceki kosul "kaynakta bugun ya da dun" idi: takvim gunune bagliydi,
     * gece yarisi pencere bir gun kayinca dunku haberlerin hepsi BIRDEN
     * dusuyordu. Artik tarih penceresi yok: sira bugunu once aliyor
     * (haber_sirasi_temel, gun basamagi) ve ana sayfa en fazla
     * ANA_SAYFA_HABER_SINIRI haber gosteriyor. Eski haberi ana sayfadan
     * cikaran tek sey, onun yerine gelen yeni haber. Ajan bir sure haber
     * getiremese de ana sayfa bosalmiyor; en son haberler duruyor.
     *
     * HABER_ANA_SAYFA_EN_FAZLA_GUN yalnizca sorguyu sinirli tutmak icin
     * (binlerce eski haberi her acilista siralamamak); 56 haberi her gun
     * fazlasiyla geciyor.
     *
     * Kaynakta yayin tarihi bilinmeyen haber (kaynak_tarihi bos) ana
     * sayfaya girmiyor ("haberlerin tarihleri cok onemli"): yasi toplandigi
     * andan sayilinca dunku haber "bugunun" gorunuyordu (bir gunluk faiz
     * karari haberi 2. siradaydi). Yas, kaynagin yayin ani ile toplanma
     * aninin erken olani (haber_yas_dakika_ifadesi).
     */
    return 'NOT (' . HABER_RG_KOSULU . ')'
        . ' AND (h.kategori_id IS NULL OR h.kategori_id NOT IN'
        . ' (SELECT id FROM kategoriler WHERE slug IN (' . $disarida . ')))'
        /*
         * DERLEMELER TARIHSIZ SAYILMAZ (12.11.2026). Derlemenin kaynak
         * tarihi yok (kaynak_url bos, tarih denetimi de ona bakmiyor), yani
         * yukaridaki kural yuzunden HICBIR derleme ana sayfa akisina
         * giremiyordu; kaynak haberleri ise derlemesi yayinda diye akistan
         * cikiyor (derleme_yayindaki_kaynak_idler). Sonuc: derlenen olay ana
         * sayfadan tamamen kayboluyordu. 12.11.2026 aksami canlida manset ve
         * izgaradaki 36 haberin hicbiri derleme degildi; Gundem sayfasindaki
         * 16 haberin 3'u derlemeydi (95 puanli HABAS patlamasi dahil) ve
         * 74 puanli Turkiye-Belcika mac sonucu ana sayfada hic gorunmedi.
         * Derleme Valentra'nin o an yazdigi haber: yasi toplanma anindan
         * sayiliyor (haber_yas_dakika_ifadesi zaten boyle).
         */
        . ' AND (h.kaynak_tarihi IS NOT NULL' . (haber_sutun_var('derleme') ? ' OR h.derleme = 1' : '') . ')'
        . ' AND h.olusturuldu >= NOW() - INTERVAL ' . HABER_ANA_SAYFA_EN_FAZLA_GUN . ' DAY'
        // Dusuk onemli yerel haber ana akista yok ($yerelDahil: "Senin Icin" sehir bolumu).
        . ($yerelDahil ? '' : ' AND ' . haber_yerel_kosulu())
        // Siradan spor haberi ana akista yok ($sporDahil: "Senin Icin"de sporu secen okur).
        . ($sporDahil ? '' : ' AND ' . haber_siradan_spor_disi_kosulu())
        . ($konuSiniri ? haber_konu_siniri_kosulu() : '');
}

/**
 * Ayni konudan en fazla 2 (cok onemliyse 3) haber (site sahibinin
 * istegi, 08.11.2026; bkz. includes/konu_siniri.php). Konusunun tavanini
 * asan haberler ana sayfa akisindan cikiyor.
 */
function haber_konu_siniri_kosulu(): string
{
    require_once __DIR__ . '/konu_siniri.php';
    require_once __DIR__ . '/mac_oncesi.php';

    // Konusunun tavanini asanlar, yayindaki bir derlemenin kaynaklari
    // (derleme hepsini anlatiyor; bkz. derleme_yayindaki_kaynak_idler) ve
    // maci bitmis mac oncesi haberleri ("ilk 11 belli oldu"; bkz. mac_oncesi_bayat_idler).
    $fazla = array_values(array_unique(array_merge(
        konu_fazlasi_idler(),
        derleme_yayindaki_kaynak_idler(),
        mac_oncesi_bayat_idler()
    )));

    return $fazla === [] ? '' : ' AND h.id NOT IN (' . implode(', ', array_map('intval', $fazla)) . ')';
}

/**
 * Haber akisi basliklarindan birinde olma kosulu (konu bloklari icin).
 */
function haber_akis_kategori_kosulu(): string
{
    $sluglar = implode(', ', array_map(
        static fn (string $slug): string => db()->quote($slug),
        HABER_AKIS_KATEGORILERI
    ));

    return 'h.kategori_id IN (SELECT id FROM kategoriler WHERE slug IN (' . $sluglar . '))';
}

/**
 * Ayni haberin iki kez girmesini engelleyen parmak izi.
 * Kaynak URL varsa onu, yoksa basligi esas alir.
 *
 * ESKI ALAN. Yeni kayitlarda hala dolduruluyor ki once eklenmis
 * haberlerin parmak izleriyle karsilastirma bozulmasin, ama kopya
 * denetimi artik haber_url_parmak() ve haber_baslik_parmak()
 * uzerinden yapiliyor. Bu hesap ham adresi oldugu gibi kullandigi
 * icin "?utm_source=..." eklenmis ya da sonuna egik cizgi gelmis
 * ayni haberi FARKLI sayiyordu.
 */
function haber_parmak_izi(string $kaynakUrl, string $baslik): string
{
    $temel = $kaynakUrl !== ''
        ? mb_strtolower(trim($kaynakUrl), 'UTF-8')
        : 'baslik:' . mb_strtolower(trim(preg_replace('/\s+/u', ' ', $baslik) ?? ''), 'UTF-8');

    return hash('sha256', $temel);
}

/**
 * Adresi, aynı haberin farklı yazımlarını tek biçime indirger.
 *
 * Ayni haber her calismada yeniden geliyordu cunku adres her seferinde
 * birazcik farkliydi. Gorulen farklar:
 *   - izleme parametreleri (utm_*, fbclid, gclid, ref, amp)
 *   - http/https ve www olan/olmayan yazim
 *   - sonda egik cizgi olan/olmayan yazim
 *   - "#icerik" gibi capalar
 *
 * Hicbiri farkli bir haber demek degil; hepsi ayni sayfa. Bu yuzden
 * parmak izi ham adresten degil, sadelestirilmis adresten hesaplaniyor.
 *
 * Kalan parametreler siralaniyor: "?a=1&b=2" ile "?b=2&a=1" ayni sayfa.
 */
function haber_url_sadelestir(string $url): string
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

            /*
             * Izleme parametreleri atiliyor. Bunlar sayfayi degil,
             * ziyaretcinin nereden geldigini anlatir; iceride ayni
             * haber durur.
             */
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

    // Semayi atiyoruz: http ve https ayni sayfayi gosterir.
    return $sunucu . $yol . ($sorgu !== '' ? '?' . $sorgu : '');
}

/**
 * Başlığı karşılaştırmaya uygun biçime indirger.
 *
 * Turkce katlama sart: mb_strtolower("İ") "i" + birlesen nokta
 * uretiyor ve ayni baslik iki farkli dizgeye donusebiliyor. Noktalama
 * ve fazla bosluk da atiliyor; kaynaklar ayni basligi tirnak, tire ve
 * bosluk farklariyla yaziyor.
 */
function haber_baslik_sadelestir(string $baslik): string
{
    $baslik = str_replace(['İ', 'I', 'ı'], 'i', $baslik);
    $baslik = mb_strtolower($baslik, 'UTF-8');
    $baslik = str_replace("\xCC\x87", '', $baslik);

    // Harf ve rakam disinda ne varsa tek bosluga indir.
    $baslik = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $baslik) ?? $baslik;

    return trim($baslik);
}

/** Sadelestirilmis adresin parmak izi; adres yoksa bos. */
function haber_url_parmak(string $kaynakUrl): string
{
    $sade = haber_url_sadelestir($kaynakUrl);

    return $sade === '' ? '' : hash('sha256', 'url:' . $sade);
}

/** Sadelestirilmis basligin parmak izi. */
function haber_baslik_parmak(string $baslik): string
{
    $sade = haber_baslik_sadelestir($baslik);

    return $sade === '' ? '' : hash('sha256', 'baslik:' . $sade);
}

/**
 * Verilen parmak izlerinden herhangi biriyle eşleşen haberin id'si.
 *
 * Durum farketmez: reddedilmis bir haberi yeniden yazdirmak da
 * istemiyoruz, yoksa ayni haber her calismada geri gelirdi.
 *
 * Basliklar CAPRAZ karsilastiriliyor: yeni haberin yeniden yazilmis ve
 * ozgun basligi, kayitli haberlerin hem yeniden yazilmis hem ozgun
 * basligiyla. Ayni ajans haberi baska bir sitede ozgun basligiyla
 * geldiginde ancak kayitli haberin ozgun basligina benziyor.
 */
function haber_kopya_bul(
    string $parmak,
    string $urlParmak,
    string $baslikParmak,
    string $kaynakBaslikParmak = ''
): ?int {
    $kosullar = ['kaynak_parmak = :p'];
    $degerler = ['p' => $parmak];

    if ($urlParmak !== '') {
        $kosullar[] = 'url_parmak = :u';
        $degerler['u'] = $urlParmak;
    }

    $basliklar = array_values(array_unique(array_filter(
        [$baslikParmak, $kaynakBaslikParmak],
        static fn (string $b): bool => $b !== ''
    )));

    foreach ($basliklar as $i => $b) {
        $kosullar[] = 'baslik_parmak = :b' . $i;
        $kosullar[] = 'kaynak_baslik_parmak = :kb' . $i;
        $degerler['b' . $i]  = $b;
        $degerler['kb' . $i] = $b;
    }

    $ifade = db()->prepare(
        'SELECT id FROM haberler WHERE ' . implode(' OR ', $kosullar) . ' LIMIT 1'
    );
    $ifade->execute($degerler);

    $id = $ifade->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Yeni parmak izi alanları boş kalan eski kayıtları doldurur.
 *
 * Alanlar sonradan eklendi; eski satirlarda NULL duruyorlar. Doldurmayi
 * SQL'de yapamiyoruz (adres sadelestirme ve Turkce katlama PHP'de),
 * bu yuzden ajan siteye her bagland8ginda birkac yuz satir isleniyor.
 * Tek seferde hepsini yapmaya kalkmak buyuk bir arsivde istegi
 * zaman asimina ugratirdi.
 */
function haber_parmaklari_tamamla(int $adet = 400): int
{
    $satirlar = db()->query(
        'SELECT id, baslik, kaynak_url
           FROM haberler
          WHERE baslik_parmak IS NULL
          ORDER BY id DESC
          LIMIT ' . max(1, min(2000, $adet))
    )->fetchAll();

    if ($satirlar === []) {
        return 0;
    }

    $guncelle = db()->prepare(
        'UPDATE haberler SET url_parmak = :u, baslik_parmak = :b, guncellendi = guncellendi WHERE id = :id'
    );

    foreach ($satirlar as $satir) {
        $guncelle->execute([
            'u'  => haber_url_parmak((string) ($satir['kaynak_url'] ?? '')) ?: null,
            'b'  => haber_baslik_parmak((string) $satir['baslik']),
            'id' => (int) $satir['id'],
        ]);
    }

    return count($satirlar);
}

/**
 * Slug'i benzersizlestirir: "vergi-duzenlemesi", "vergi-duzenlemesi-2", ...
 */
function haber_benzersiz_slug(string $baslik, ?int $haricId = null): string
{
    $temel = slug_uret($baslik);
    $aday  = $temel;
    $ek    = 1;

    while (true) {
        $sql = 'SELECT id FROM haberler WHERE slug = :slug';
        $par = ['slug' => $aday];

        if ($haricId !== null) {
            $sql .= ' AND id <> :haric';
            $par['haric'] = $haricId;
        }

        $ifade = db()->prepare($sql . ' LIMIT 1');
        $ifade->execute($par);

        if ($ifade->fetchColumn() === false) {
            return $aday;
        }

        $ek++;
        $aday = $temel . '-' . $ek;
    }
}

/**
 * Ajanın gönderdiği video adresi, izinli bir oynatıcıysa; değilse boş.
 *
 * Ajan haber sayfasinin gomdugu videoyu gonderiyor (ajan/src/Sayfa.php,
 * videoBul). Sayfada iframe olarak gosterilecegi icin serbest adres
 * kabul edilmiyor: yalnizca YouTube (gizlilik modu), Vimeo ve
 * Dailymotion'in resmi gomme adresleri. Panelden elle girilen adres bu
 * kisittan muaf (yonetici ne gomdugunu biliyor).
 */
function haber_video_adresi(string $adres): string
{
    $adres = trim($adres);

    $izinli = preg_match('#^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}$#', $adres)
        || preg_match('#^https://player\.vimeo\.com/video/\d{6,12}$#', $adres)
        || preg_match('#^https://www\.dailymotion\.com/embed/video/[A-Za-z0-9]{5,12}$#', $adres);

    return $izinli ? $adres : '';
}

/*
 * VIDEOLU HABERLER (30.10.2026, site sahibinin istegi; 22.10'daki ilk
 * surum menu ve ana sayfa degistigi icin yeniden yazildi): /video sayfasi
 * ve ana sayfadaki video seridi. Videolu haber = iframe_url'si
 * haber_video_adresi'nin kabul ettigi resmi gomme adresi; panelden
 * girilen video olmayan iframe sayilmiyor.
 */
const HABER_VIDEO_KOSULU = "(h.iframe_url LIKE 'https://www.youtube-nocookie.com/embed/%'
     OR h.iframe_url LIKE 'https://player.vimeo.com/video/%'
     OR h.iframe_url LIKE 'https://www.dailymotion.com/embed/video/%')";

/**
 * Yayindaki videolu haberler, en yeni once; $kategori verilirse o baslik.
 *
 * @return list<array<string,mixed>>
 */
function haber_videolular(int $limit, int $atla = 0, string $kategori = ''): array
{
    try {
        $ifade = db()->prepare(
            'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
               FROM haberler h
               LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = :d AND ' . HABER_VIDEO_KOSULU
                . ($kategori !== '' ? ' AND k.slug = :k' : '') . '
              ORDER BY COALESCE(h.yayin_tarihi, h.olusturuldu) DESC, h.id DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $atla)
        );
        $ifade->execute(['d' => HABER_YAYINDA] + ($kategori !== '' ? ['k' => $kategori] : []));

        // Adres kalibi SQL'de kaba; iframe'e girecek adres burada kesinlesiyor.
        return array_values(array_filter(
            $ifade->fetchAll(),
            static fn (array $h): bool => haber_video_adresi((string) $h['iframe_url']) !== ''
        ));
    } catch (Throwable $e) {
        error_log('[valentra] videolu haberler: ' . $e->getMessage());

        return [];
    }
}

/**
 * Videolu haber sayilari, basliga gore (sekmeler icin); '' toplam.
 *
 * @return array<string,int>
 */
function haber_videolu_sayilari(): array
{
    try {
        $satirlar = db()->query(
            'SELECT k.slug, COUNT(*) AS adet
               FROM haberler h
               LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . HABER_VIDEO_KOSULU . '
              GROUP BY k.slug'
        )->fetchAll();
    } catch (Throwable $e) {
        return ['' => 0];
    }

    $sayilar = ['' => 0];

    foreach ($satirlar as $s) {
        $sayilar[(string) $s['slug']] = (int) $s['adet'];
        $sayilar[''] += (int) $s['adet'];
    }

    return $sayilar;
}

/**
 * Kaynak zamani tam gece yarisi mi (Istanbul ya da GMT 00:00:00)?
 *
 * Bazi kaynaklar saati vermiyor, yalnizca gunu yaziyor ve haber
 * "00:00"da yayimlanmis gorunuyor. Ekonomim'in dun aksamki PPK haberi
 * ertesi gun "23 Ekim 00:00" tarihiyle geldi ve ana sayfada 2. oldu
 * (site sahibi, 23.10.2026). Saati bilinmeyen zaman guvenilmez:
 * tarihsiz sayiliyor (ana sayfaya ve son dakikaya cikmaz, onay bekler).
 */
function haber_yalnizca_gun_mu(int $zaman): bool
{
    // Gun bazi beslemelerde GMT gece yarisi olarak yaziliyor (03:00 TSI).
    foreach (['Europe/Istanbul', 'UTC'] as $dilim) {
        if ((new DateTimeImmutable('@' . $zaman))->setTimezone(new DateTimeZone($dilim))->format('H:i:s') === '00:00:00') {
            return true;
        }
    }

    return false;
}

/*
 * GORSELSIZ HABERE KATEGORI GORSELI (site sahibinin istegi, 29.10.2026;
 * Google denetimi: "135 gorselsiz haber"). Google Discover ve Haberler
 * gorselsiz haberi neredeyse hic gostermiyor. Kaynagin gorseli yoksa
 * haberin konusuna gore hazir gorsel (assets/kategori/<slug>.jpg; ozgun
 * veri haberlerindeki gibi sitenin kendi dosyasi, indirilmiyor). Ajan
 * sonradan gercek gorseli bulursa ustune yaziliyor.
 */
const HABER_KATEGORI_GORSELLERI = [
    'gundem', 'genel', 'politika', 'ekonomi', 'dunya', 'spor',
    'teknoloji', 'saglik', 'kultur-sanat', 'yemek-tarifleri',
];

/** Konunun hazır görseli (tam adres); tanınmayan konu Gündem. */
function haber_kategori_gorseli(?string $slug): string
{
    require_once __DIR__ . '/seo.php';

    $slug = in_array((string) $slug, HABER_KATEGORI_GORSELLERI, true) ? (string) $slug : 'gundem';

    return site_adresi() . '/assets/kategori/' . $slug . '.jpg';
}

/**
 * Görseli olmayan haberlere konu görselini yazar; kaç haberin dolduğunu döndürür.
 * Iki saatte bir (api/canli-tazele.php ?bolum=ozgun) calisiyor: hangi yoldan
 * gelirse gelsin (ajan, derleme, panel) gorselsiz haber kalmasin.
 */
function haber_kategori_gorsellerini_doldur(int $limit = 1000): int
{
    require_once __DIR__ . '/seo.php';

    $liste = implode(', ', array_map(static fn (string $s): string => db()->quote($s), HABER_KATEGORI_GORSELLERI));
    $ifade = db()->prepare(
        "UPDATE haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
            SET h.gorsel_url = CONCAT(:taban, '/assets/kategori/',
                    CASE WHEN k.slug IN (" . $liste . ") THEN k.slug ELSE 'gundem' END, '.jpg'),
                h.guncellendi = h.guncellendi
          WHERE COALESCE(h.gorsel_url, '') = ''"
    );
    $ifade->execute(['taban' => site_adresi()]);

    return $ifade->rowCount();
}

/*
 * DEVLET BUYUKLERINE SAYGI (site sahibinin karari, 04.11.2026: "DW'den
 * aldigin ve yayindan kaldirdigin Cumhurbaskani ile alay eden videolar
 * kesin olmasin; devlet buyuklerine saygisiz haberler olmayacak").
 *
 * Model zaten yayin politikasiyla eliyor (ajan/src/Yazar.php, madde j);
 * bu ikinci, modelden bagimsiz kilit: baslikta, ozette, kaynagin
 * basliginda ya da metnin basinda bir devlet makami ile alay/taklit/
 * parodi gibi bir ifade birlikte geciyorsa haber siteye hic girmiyor.
 * Kasten temkinli: "X ile alay ettiler" diye aktarilan bir aciklama da
 * eleniyor; boyle haber elle yazilabilir (panel bu denetime takilmaz).
 */
const HABER_DEVLET_MAKAMLARI = '/(erdo[gğ]an|cumhurba[sş]kan|ba[sş]bakan|tbmm ba[sş]kan|meclis ba[sş]kan|\bbakan\p{L}*|\bvali(si|yi|ye|nin|\b)|genelkurmay|t[uü]rk silahl[ıi] kuvvet|president erdo|turkish president)/iu';
const HABER_ALAY_IFADELERI = '/(alay\s*(et|ed)|alayc|dalga ge[cç]|taklit|parodi|hiciv|skeç|skec|karikat[uü]r|mizah|g[uü]l[uü]n[cç]|komik duruma|maskara|rezil|mock|ridicul|satir|parod|lampoon|caricatur|laugh(ed|ing)? at|joke[sd]? about|imitat)/iu';

/** @param array<string,mixed> $veri */
function haber_devlet_buyuklerine_saygisiz_mi(array $veri): bool
{
    $metin = implode(' ', [
        (string) ($veri['baslik'] ?? ''),
        (string) ($veri['ozet'] ?? ''),
        (string) ($veri['kaynak_baslik'] ?? ''),
        mb_substr((string) ($veri['icerik'] ?? ''), 0, 600, 'UTF-8'),
    ]);

    return preg_match(HABER_DEVLET_MAKAMLARI, $metin) === 1
        && preg_match(HABER_ALAY_IFADELERI, $metin) === 1;
}

/**
 * Ajanin buldugu haberi taslak olarak kaydeder.
 *
 * @return array{durum:string,id:?int} durum: 'eklendi' | 'yinelenen'
 */
function haber_taslak_ekle(array $veri): array
{
    $baslik    = trim((string) ($veri['baslik'] ?? ''));
    $icerik    = trim((string) ($veri['icerik'] ?? ''));
    $kaynakUrl = guvenli_url((string) ($veri['kaynak_url'] ?? ''));

    if ($baslik === '' || $icerik === '') {
        throw new InvalidArgumentException('Baslik ve icerik zorunludur.');
    }

    if (haber_devlet_buyuklerine_saygisiz_mi($veri)) {
        throw new InvalidArgumentException('Yayın politikası: devlet büyüklerine saygı (alay, taklit, parodi içeren haber alınmaz).');
    }

    $parmak       = haber_parmak_izi($kaynakUrl, $baslik);
    $urlParmak    = haber_url_parmak($kaynakUrl);
    $baslikParmak = haber_baslik_parmak($baslik);

    // Kaynagin ozgun basligi (eski ajan surumu gondermiyor olabilir).
    $kaynakBaslik       = mb_substr(trim((string) ($veri['kaynak_baslik'] ?? '')), 0, 300, 'UTF-8');
    $kaynakBaslikParmak = $kaynakBaslik !== '' ? haber_baslik_parmak($kaynakBaslik) : '';

    /*
     * Uc olcut de deneniyor ve biri tutarsa haber eklenmiyor.
     *
     * Tek olcut (ham adres) yetmiyordu: ayni haber izleme
     * parametresi eklenmis bir adresle ya da baska bir kaynaktan
     * geldiginde yeniden yaziliyordu. Basliga da bakmak ayni haberin
     * iki kaynaktan gelen kopyasini da yakaliyor.
     */
    $mevcutId = haber_kopya_bul($parmak, $urlParmak, $baslikParmak, $kaynakBaslikParmak);

    if ($mevcutId !== null) {
        return ['durum' => 'yinelenen', 'id' => $mevcutId];
    }

    $ozet = trim((string) ($veri['ozet'] ?? ''));
    if ($ozet === '') {
        $ozet = kisalt($icerik, 220);
    }

    /*
     * Yapay zeka calisma dili kayit aninda temizleniyor (08.11.2026, okur
     * denetimi): haber sayfasi temizlenmisti ama ayni ozet dosya
     * kartlarinda, bultende ve Telegram'da eski haliyle duruyordu. Govde,
     * spot ve "neden onemli" birlikte (bkz. okur_dili). Tariflerde degil:
     * orada "malzeme" gercek kelime.
     */
    if (trim((string) ($veri['kategori'] ?? '')) !== 'yemek-tarifleri') {
        $icerik = okur_dili($icerik);
        $ozet   = okur_dili($ozet);
        $veri['neden_onemli'] = okur_dili((string) ($veri['neden_onemli'] ?? ''));
    }

    $ifade = db()->prepare(
        'INSERT INTO haberler
            (baslik, slug, ozet, icerik, gorsel_url, etiketler, durum, kategori_id,
             kaynak_id, kaynak_adi, kaynak_url, kaynak_parmak, url_parmak,
             baslik_parmak, kaynak_baslik, kaynak_baslik_parmak, iframe_url, guven_skoru, onem, ajan_notu, ajan_modeli,
             analiz_degisen, analiz_etkilenen, analiz_zaman, analiz_islem,
             isletme_etkisi, uygulama_ornegi, resmi_dayanak, neden_onemli, son_dakika, kaynak_tarihi)
         VALUES
            (:baslik, :slug, :ozet, :icerik, :gorsel_url, :etiketler, :durum, :kategori_id,
             :kaynak_id, :kaynak_adi, :kaynak_url, :kaynak_parmak, :url_parmak,
             :baslik_parmak, :kaynak_baslik, :kaynak_baslik_parmak, :iframe_url, :guven_skoru, :onem, :ajan_notu, :ajan_modeli,
             :analiz_degisen, :analiz_etkilenen, :analiz_zaman, :analiz_islem,
             :isletme_etkisi, :uygulama_ornegi, :resmi_dayanak, :neden_onemli, :son_dakika,
             IF(:kaynak_var = 1, NOW() - INTERVAL :kaynak_gecikme MINUTE, NULL))'
    );

    /*
     * Kaynagin yayin zamani gecikme olarak yaziliyor (NOW() - N dakika):
     * ajan Unix zamani gonderiyor, veritabaninin saat dilimi ise bilinmiyor
     * (paylasimli sunucu). Gelecekteki ya da 30 gunden eski zaman yok
     * sayiliyor: bozuk besleme tarihi haberi gomup ya da one cikarmasin.
     */
    $kaynakZamani  = (int) ($veri['kaynak_zamani'] ?? 0);
    $kaynakGecikme = $kaynakZamani > 0 ? intdiv(time() - $kaynakZamani, 60) : -1;
    $kaynakVar     = $kaynakGecikme >= 0 && $kaynakGecikme <= 30 * 1440
        && !haber_yalnizca_gun_mu($kaynakZamani);

    $ifade->execute([
        'baslik'        => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'          => haber_benzersiz_slug($baslik),
        'ozet'          => mb_substr($ozet, 0, 600, 'UTF-8'),
        'icerik'        => $icerik,
        'gorsel_url'    => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: haber_kategori_gorseli((string) ($veri['kategori'] ?? '')),
        'etiketler'     => mb_substr(trim((string) ($veri['etiketler'] ?? '')), 0, 400, 'UTF-8'),
        'durum'         => HABER_TASLAK,
        'kategori_id'   => kategori_id_cozumle((string) ($veri['kategori'] ?? '')),
        'kaynak_id'     => $veri['kaynak_id'] ?? null,
        'kaynak_adi'    => mb_substr(trim((string) ($veri['kaynak_adi'] ?? '')), 0, 160, 'UTF-8'),
        'kaynak_url'    => mb_substr($kaynakUrl, 0, 500, 'UTF-8'),
        'kaynak_parmak' => $parmak,
        'url_parmak'    => $urlParmak !== '' ? $urlParmak : null,
        'baslik_parmak' => $baslikParmak !== '' ? $baslikParmak : null,
        'kaynak_baslik'        => $kaynakBaslik,
        'kaynak_baslik_parmak' => $kaynakBaslikParmak !== '' ? $kaynakBaslikParmak : null,
        // Ajanin buldugu video: yalnizca izinli oynaticilar (bkz.
        // haber_video_adresi); baska her sey bos.
        'iframe_url'           => haber_video_adresi((string) ($veri['iframe_url'] ?? '')) ?: null,
        'guven_skoru'   => max(0, min(100, (int) ($veri['guven_skoru'] ?? 0))),
        // Eski ajan surumu puan gondermezse orta deger.
        'onem'          => max(0, min(100, (int) ($veri['onem'] ?? 50))),
        'ajan_notu'     => mb_substr(trim((string) ($veri['ajan_notu'] ?? '')), 0, 600, 'UTF-8'),
        'ajan_modeli'   => mb_substr(trim((string) ($veri['model'] ?? '')), 0, 80, 'UTF-8'),

        /*
         * Valentra Analiz alanlari. Sutunlar 600 karakter; model kisa
         * yazmasi soylenmis olsa da uzun bir cevap gelirse kirpilmali,
         * yoksa INSERT tamamen duserdi.
         */
        'analiz_degisen'   => mb_substr(trim((string) ($veri['analiz_degisen'] ?? '')), 0, 600, 'UTF-8'),
        'analiz_etkilenen' => mb_substr(trim((string) ($veri['analiz_etkilenen'] ?? '')), 0, 600, 'UTF-8'),
        'analiz_zaman'     => mb_substr(trim((string) ($veri['analiz_zaman'] ?? '')), 0, 600, 'UTF-8'),
        'analiz_islem'     => mb_substr(trim((string) ($veri['analiz_islem'] ?? '')), 0, 600, 'UTF-8'),
        /*
         * Son dakika: modelin karari (haberi okuyarak) VE kaynaktaki yayin
         * zamani bilinen, en fazla HABER_SON_DAKIKA_EN_FAZLA_DAKIKA once.
         * Ikisinden biri yoksa son dakika degil (04.11.2026: birkac gunluk
         * haber "son dakika" gorseliyle cikiyordu).
         */
        'son_dakika'       => !empty($veri['son_dakika']) && $kaynakVar
            && $kaynakGecikme <= HABER_SON_DAKIKA_EN_FAZLA_DAKIKA ? 1 : 0,
        'kaynak_var'       => $kaynakVar ? 1 : 0,
        'kaynak_gecikme'   => $kaynakVar ? $kaynakGecikme : 0,
    ] + haber_format_alanlari($veri));

    $id = (int) db()->lastInsertId();
    haber_kaynak_listesi_yaz($id, $veri, $kaynakUrl);

    // Yerel kaynagin haberi: ili kaynaktan (bkz. haber_yerel_kosulu).
    try {
        db()->prepare(
            'UPDATE haberler h JOIN kaynaklar k ON (k.id = h.kaynak_id OR (h.kaynak_id IS NULL AND k.ad = h.kaynak_adi))
                SET h.sehir = k.sehir, h.guncellendi = h.guncellendi
              WHERE h.id = :id AND k.sehir IS NOT NULL AND k.sehir <> \'\''
        )->execute(['id' => $id]);
    } catch (PDOException $e) {
        error_log('[valentra] haber sehri yazilamadi: ' . $e->getMessage());
    }

    // Oncelik sinifi, dogrulama, olay kimligi ve karar kaydi (12.11.2026).
    require_once __DIR__ . '/oncelik.php';
    $karar = oncelik_kaydet($id, $veri);

    /*
     * Gundem puani (13.11.2026; bkz. includes/manset_sinyal.php): olayi kac
     * buyuk yayin gundemine aldiysa onem puanina o kadar ek.
     */
    try {
        require_once __DIR__ . '/manset_sinyal.php';
        manset_sinyal_uygula($id, manset_sinyal_puani([(string) ($veri['baslik'] ?? ''), (string) ($veri['kaynak_baslik'] ?? '')]));
    } catch (Throwable $e) {
        error_log('[valentra] gundem puani uygulanamadi: ' . $e->getMessage());
    }

    return ['durum' => 'eklendi', 'id' => $id, 'karar' => $karar];
}

/**
 * Birden cok kaynaktan yazilan haberin kaynak listesi (30.10.2026, site
 * sahibi: "birkac kaynak varsa yapay zeka hepsini okusun ve kendi ozgun
 * haberini yapsin; tek kaynaksa tek kaynagi verelim").
 *
 * Ajan ayni olayi veren diger kaynaklari da okuyup haberi hepsinden
 * yaziyor ve onlari ek_kaynaklar olarak gonderiyor. Ana kaynakla birlikte
 * derleme_kaynaklari sutununa yaziliyor; sayfada "Kaynaklar: A, B, C".
 * Tek kaynakli haberde liste yazilmiyor, sayfada o kaynak gorunuyor.
 * derleme bayragi DEGISMIYOR: derleme tavani ve derleme malzemesi
 * (includes/derleme.php) yalnizca derleme ajaninin yazilarini sayiyor.
 */
function haber_kaynak_listesi_yaz(int $id, array $veri, string $kaynakUrl): void
{
    $ekler = $veri['ek_kaynaklar'] ?? [];

    if (!is_array($ekler) || $ekler === [] || !haber_sutun_var('derleme_kaynaklari')) {
        return;
    }

    $liste = [['ad' => trim((string) ($veri['kaynak_adi'] ?? '')), 'url' => $kaynakUrl, 'baslik' => '']];

    foreach (array_slice($ekler, 0, 5) as $ek) {
        if (!is_array($ek)) {
            continue;
        }

        $ad  = mb_substr(trim((string) ($ek['ad'] ?? '')), 0, 160, 'UTF-8');
        $url = mb_substr(guvenli_url((string) ($ek['url'] ?? '')), 0, 500, 'UTF-8');

        if ($ad === '' || in_array($ad, array_column($liste, 'ad'), true)) {
            continue;
        }

        $liste[] = ['ad' => $ad, 'url' => $url, 'baslik' => ''];
    }

    if (count($liste) < 2 || $liste[0]['ad'] === '') {
        return;
    }

    db()->prepare('UPDATE haberler SET derleme_kaynaklari = :k, guncellendi = guncellendi WHERE id = :id')
        ->execute(['k' => json_encode($liste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $id]);
}

/**
 * Bir toplu haber teslimini taslak olarak kaydeder ve ajan kaydı tutar.
 *
 * İki yoldan çağrılıyor: ajanın POST ettiği /api/ingest.php ve ajanın
 * siteye ulaşamadığında GitHub'a bıraktığı teslimi sitenin kendisinin
 * alması (includes/ajan_teslim.php). İkisi aynı kaydı ve kopya
 * denetimini kullanıyor. Otomatik yayın açıksa (varsayılan; bkz.
 * haber_otomatik_yayin_acik) eklenen haber hemen yayına alınıyor.
 *
 * @param list<mixed> $haberler
 * @return array{eklenen:int,yinelenen:int,hatalar:list<array{sira:int|string,mesaj:string}>}
 */
function haber_toplu_taslak_ekle(array $haberler): array
{
    $kayit = db()->prepare(
        'INSERT INTO ajan_kayitlari (durum, bulunan) VALUES (:durum, :bulunan)'
    );
    $kayit->execute(['durum' => 'calisiyor', 'bulunan' => count($haberler)]);
    $kayitId = (int) db()->lastInsertId();

    $eklenen = 0;
    $yinelenen = 0;
    $yayinlanan = 0;
    $incelemede = 0;
    $kritik = 0;
    $acil = [];
    $hatalar = [];
    $otomatik = haber_otomatik_yayin_acik();

    foreach ($haberler as $sira => $haber) {
        if (!is_array($haber)) {
            $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kayıt nesne değil.'];
            continue;
        }

        try {
            $sonuc = haber_taslak_ekle($haber);

            if ($sonuc['durum'] === 'eklendi') {
                $eklenen++;
                $karar = $sonuc['karar'] ?? null;
                $yol   = (string) ($karar['yayin'] ?? 'kuyruk');

                /*
                 * YAYIN YOLU (12.11.2026; bkz. oncelik_karar). Editor
                 * incelemesine dusen haber (dogrulanmamis kritik, iddia)
                 * yayimlanmaz. Dogrulanmis kritik haber konu/tekrar
                 * bekletmesine takilmaz (olayin yayinda ayni bilgiyi veren
                 * kritik haberi yoksa) ve sayfa kopyalari hemen gecersiz
                 * kilinir.
                 */
                if ($yol === 'editor_acil' || $yol === 'editor') {
                    $incelemede++;

                    if ($yol === 'editor_acil') {
                        $acil[] = (string) ($haber['baslik'] ?? '');
                    }
                } elseif ($otomatik && haber_otomatik_yayinla(
                    (int) $sonuc['id'],
                    $yol === 'hemen' && empty($karar['ayni_olay_yayinda'])
                )) {
                    $yayinlanan++;

                    if ($yol === 'hemen') {
                        $kritik++;
                    }
                }
            } else {
                $yinelenen++;
            }
        } catch (InvalidArgumentException $e) {
            $hatalar[] = ['sira' => $sira, 'mesaj' => $e->getMessage()];
        } catch (PDOException $e) {
            $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kaydedilemedi.'];
        }
    }

    db()->prepare(
        'UPDATE ajan_kayitlari
            SET bitis = NOW(), durum = :durum, eklenen = :eklenen,
                yinelenen = :yinelenen, mesaj = :mesaj
          WHERE id = :id'
    )->execute([
        'durum'     => $hatalar === [] ? 'tamam' : 'hata',
        'eklenen'   => $eklenen,
        'yinelenen' => $yinelenen,
        'mesaj'     => $hatalar === [] ? '' : mb_substr(count($hatalar) . ' kayıt alınamadı.', 0, 600, 'UTF-8'),
        'id'        => $kayitId,
    ]);

    return [
        'eklenen' => $eklenen, 'yinelenen' => $yinelenen, 'yayinlanan' => $yayinlanan, 'hatalar' => $hatalar,
        // Editor incelemesine dusenler ve hizli yoldan yayimlanan kritik haberler.
        'incelemede' => $incelemede, 'acil_inceleme' => $acil, 'kritik_yayinlanan' => $kritik,
    ];
}

/**
 * Ana sayfanın durumu (ajana, GitHub özetinde gösterilsin diye): ana
 * sayfa akışında kaç haber var, en yenisi ne zaman toplandı, bugün kaç
 * haber onay bekliyor. "Yeni haberler ana sayfada yok" sorusunun cevabı
 * GitHub'dan da okunabilsin (23.10.2026).
 *
 * @return array{ana_sayfada:int,en_yeni_dakika:?int,onay_bekleyen_bugun:int}
 */
function haber_ana_sayfa_durumu(): array
{
    $ana = db()->query(
        'SELECT COUNT(*) AS adet, MIN(' . haber_yas_dakika_ifadesi() . ') AS en_yeni
           FROM haberler h
          WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . haber_ana_akis_kosulu()
    )->fetch();

    $bekleyen = db()->prepare(
        'SELECT COUNT(*) FROM haberler
          WHERE durum = :taslak AND olusturuldu >= NOW() - INTERVAL ' . haber_bugun_dakika() . ' MINUTE'
    );
    $bekleyen->execute(['taslak' => HABER_TASLAK]);

    return [
        'ana_sayfada'         => (int) ($ana['adet'] ?? 0),
        'en_yeni_dakika'      => isset($ana['en_yeni']) ? (int) $ana['en_yeni'] : null,
        'onay_bekleyen_bugun' => (int) $bekleyen->fetchColumn(),
    ];
}

/**
 * Onay bekleyen taslaklar (admin paneli).
 */
function haber_bekleyenler(int $limit = 100): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.guven_skoru DESC, h.olusturuldu DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_TASLAK);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Duruma gore listeleme (admin paneli sekmeler).
 */
/**
 * Paneldeki haber listesi; $kategori verilirse yalnızca o başlık (slug).
 */
function haber_listele(string $durum, int $limit = 100, string $kategori = ''): array
{
    $ifade = db()->prepare(
        // etkin_onem: panelde "onem 85 -> simdi 41" (bkz. haber_etkin_onem_ifadesi).
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug,
                ROUND(' . haber_etkin_onem_ifadesi() . ') AS etkin_onem
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum'
        . ($kategori !== '' ? ' AND k.slug = :kategori' : '')
        . ' ORDER BY COALESCE(h.yayin_tarihi, h.guncellendi) DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', $durum);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);

    if ($kategori !== '') {
        $ifade->bindValue('kategori', $kategori);
    }

    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Bir durumdaki haberlerin başlığa göre sayısı (slug => adet).
 *
 * Panelde baslik suzgecinin yanindaki sayilar icin: "Yemek Tarifleri"
 * toplamasi bittiginde tarifler onay listesinde diger haberlerin
 * arasinda kayboluyordu (22.10.2026).
 *
 * @return array<string,int>
 */
function haber_kategori_sayilari(string $durum): array
{
    $ifade = db()->prepare(
        'SELECT k.slug, COUNT(*) AS adet
           FROM haberler h
           JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          GROUP BY k.slug'
    );
    $ifade->execute(['durum' => $durum]);

    $sayilar = [];

    foreach ($ifade->fetchAll() as $satir) {
        $sayilar[(string) $satir['slug']] = (int) $satir['adet'];
    }

    return $sayilar;
}

function haber_bul(int $id): ?array
{
    $ifade = db()->prepare('SELECT * FROM haberler WHERE id = :id LIMIT 1');
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Herkese acik sayfalar icin: yalnizca yayinda olan haberi slug ile getirir.
 */
function haber_yayinda_bul(string $slug): ?array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.slug = :slug AND h.durum = :durum LIMIT 1'
    );
    $ifade->execute(['slug' => $slug, 'durum' => HABER_YAYINDA]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Ana sayfa listesi: sadece yayinda olanlar, yeniden eskiye.
 */
/*
 * Ana sayfada en fazla bu kadar haber: kaydirakta 20, izgarada 3 sayfa
 * (3 x 12). Sira bugunu once aldigi icin sinirin disina dusen hep en eski
 * (dunun en dusuk puanli) haberler (bkz. haber_ana_akis_kosulu).
 */
const ANA_SAYFA_HABER_SINIRI = 56;

function haber_yayindakiler(int $limit = 20, int $atla = 0, bool $anaAkis = false): array
{
    if ($anaAkis) {
        $limit = min($limit, ANA_SAYFA_HABER_SINIRI - $atla);

        if ($limit <= 0) {
            return [];
        }
    }

    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
           ' . ($anaAkis ? haber_okunma_birlesimi() : '') . '
          WHERE h.durum = :durum' . ($anaAkis ? ' AND ' . haber_ana_akis_kosulu() : '') . '
          ORDER BY ' . haber_sirasi($anaAkis) . '
          LIMIT :limit OFFSET :atla'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->bindValue('atla', $atla, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_yayinda_sayisi(bool $anaAkis = false): int
{
    // Sayfalama ana sayfa listesiyle AYNI kosulu kullanmali; yoksa son
    // sayfalar bos cikar.
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM haberler h WHERE h.durum = :durum'
        . ($anaAkis ? ' AND ' . haber_ana_akis_kosulu() : '')
    );
    $ifade->execute(['durum' => HABER_YAYINDA]);

    $sayi = (int) $ifade->fetchColumn();

    return $anaAkis ? min($sayi, ANA_SAYFA_HABER_SINIRI) : $sayi;
}

function haber_durum_sayilari(): array
{
    $satirlar = db()->query(
        'SELECT durum, COUNT(*) AS adet FROM haberler GROUP BY durum'
    )->fetchAll();

    $sayilar = [HABER_TASLAK => 0, HABER_YAYINDA => 0, HABER_REDDEDILDI => 0];
    foreach ($satirlar as $satir) {
        $sayilar[$satir['durum']] = (int) $satir['adet'];
    }

    return $sayilar;
}

/**
 * Onay: haberi ana sayfaya alir.
 */
function haber_onayla(int $id, int $yoneticiId): bool
{
    $ifade = db()->prepare(
        'UPDATE haberler
            SET durum = :durum,
                onaylayan_id = :yonetici,
                onay_tarihi = NOW(),
                yayin_tarihi = COALESCE(yayin_tarihi, NOW()),' . (haber_sutun_var('inceleme') ? "
                inceleme = ''," : '') . '
                guncellendi = guncellendi
          WHERE id = :id'
    );
    $ifade->execute(['durum' => HABER_YAYINDA, 'yonetici' => $yoneticiId, 'id' => $id]);

    if ($ifade->rowCount() > 0) {
        // Editor onayladi: sayfa kopyalari yenilensin (kritik haber beklemesin).
        require_once __DIR__ . '/oncelik.php';
        oncelik_yayin_olcumu($id);
        oncelik_onbellek_gecersiz();

        require_once __DIR__ . '/arama_bildirim.php';
        arama_bildir_kayit('haber', $id);
        // Son dakika ya da onemli haber: tarayici bildirimi (03.11.2026; bkz. includes/web_push.php).
        require_once __DIR__ . '/web_push.php';
        push_haber_yayinlandi($id);
        // Ayni olcutle Telegram kanalina (03.11.2026; bkz. includes/telegram.php).
        require_once __DIR__ . '/telegram.php';
        telegram_haber_yayinlandi($id);

        return true;
    }

    return false;
}

/*
 * OTOMATIK YAYIN (site sahibinin karari, 23.10.2026: "bugunkuleri
 * otomatik onayla"). Onceden ajanin her haberi taslak olarak bekliyordu
 * ve panelden onaylanmadikca ana sayfaya cikmiyordu; sabah toplanan
 * haberler onaysiz kalinca ana sayfada dunkuler duruyordu.
 *
 * Aciksa ajanin gonderdigi GUVENLI haber (bkz. HABER_OTOMATIK_GUVEN_ESIGI)
 * geldigi anda yayina giriyor; guveni dusuk olan onay bekliyor. Bugun
 * toplanip taslakta kalmis haberler de ana sayfa acildiginda topluca
 * yayina aliniyor (index.php). Panelden (Ajan sayfasi) kapatilabilir;
 * kapaliyken her sey eskisi gibi onay bekler. Kopya denetimi ve gorselsiz
 * haber engeli ayni: yalnizca onay adimi kalkiyor.
 */
const HABER_OTOMATIK_YAYIN_AYARI = 'haber_otomatik_yayin';

/*
 * GUVEN ESIGI (site sahibinin karari, 23.10.2026: "guvenli haberleri
 * onaylamadan yayinla, guvensiz haberleri onaya dusur"). Otomatik yayin
 * yalnizca ajanin guven puani bu esikte ya da ustundeki haberleri
 * aliyor; altindakiler taslakta kaliyor ve panelde onay bekliyor.
 * Ajanin kabul ettigi haberlerde guven cogunlukla 85-95; 70'ler
 * kaynagi zayif ya da ayrintisi eksik haberler.
 */
const HABER_OTOMATIK_GUVEN_ESIGI = 80;

/*
 * OpenAI (GPT-6 Luna) icin ayri esik (site sahibinin karari, 23.10.2026).
 * Luna guveni Gemini'den temkinli puanliyor: ayni gunun benzer
 * haberlerine Gemini %85-95, Luna %65-76 verdi; tek esikle Luna'nin
 * yazdigi neredeyse her haber onaya dusuyordu. Hangi modelin yazdigi
 * haberler.ajan_modeli sutununda; bos (eski kayit) Gemini sayiliyor.
 */
const HABER_OTOMATIK_GUVEN_ESIGI_OPENAI = 70;

/*
 * TARIHSIZ HABER GUVENSIZ (site sahibi, 23.10.2026: "tarih yoksa
 * guvensiz; bunu son dakika yapamayiz"). Kaynakta yayin zamani
 * bulunamayan haber (kaynak_tarihi bos) guven puani ne olursa olsun
 * otomatik yayina girmiyor, onayda bekliyor; son dakika da olamiyor
 * (haber_son_dakika_id). Haberin ne zaman yayimlandigi bilinmiyorsa
 * dunku bir mac "bugun" diye one cikabiliyor.
 */

/*
 * ONAY YOK (site sahibinin karari, 28.10.2026: "onay beklemeden butun
 * haberleri gonderelim"). Otomatik yayin acikken guven puani ve kaynak
 * tarihi artik yayini engellemiyor: derleme, Valentra Dosya ve dusuk
 * guvenli haberler de geldigi anda yayinda. Tarihsiz haber yine son
 * dakika olamiyor (haber_son_dakika_id). Esikler eski davranisa donmek
 * icin duruyor; kosulu yeniden kurmak yeterli.
 */

/** Otomatik yayin kosulu (SQL); haberler tablosu takma adsiz. */
function haber_guven_esigi_kosulu(): string
{
    return '1 = 1';
}

function haber_otomatik_yayin_acik(): bool
{
    require_once __DIR__ . '/ayarlar.php';

    return ayar_oku(HABER_OTOMATIK_YAYIN_AYARI, '1') === '1';
}

/**
 * Taslagi yonetici olmadan yayina alir (onaylayan bos kalir).
 *
 * Yalnizca guven puani esikte ve ustundeyse (bkz. haber_guven_esigi_kosulu); degilse
 * taslakta kalir (false) ve onay bekler.
 */
function haber_otomatik_yayinla(int $id, bool $hizli = false): bool
{
    require_once __DIR__ . '/oncelik.php';

    // $hizli: dogrulanmis kritik haber konu/tekrar bekletmesine takilmaz.
    if (!$hizli && haber_tekrar_bekletilir($id)) {
        return false;
    }

    // Editor incelemesindeki (dogrulanmamis kritik, iddia, yayini
    // durdurulmus) haber otomatik yayina girmez.
    $incelemeYok = oncelik_hazir() ? " AND inceleme = ''" : '';

    $ifade = db()->prepare(
        'UPDATE haberler
            SET durum = :yayinda,
                onay_tarihi = NOW(),
                yayin_tarihi = COALESCE(yayin_tarihi, olusturuldu, NOW()),
                guncellendi = guncellendi
          WHERE id = :id AND durum = :taslak AND ' . haber_guven_esigi_kosulu() . $incelemeYok
    );
    $ifade->execute(['yayinda' => HABER_YAYINDA, 'taslak' => HABER_TASLAK, 'id' => $id]);

    if ($ifade->rowCount() === 0) {
        return false;
    }

    oncelik_yayin_olcumu($id);

    // Kritik haber: ana sayfa ve haber sayfasi kopyalari hemen yenilensin.
    if ($hizli) {
        oncelik_onbellek_gecersiz();
    }

    // Ayni teslimdeki sonraki haberler bunu da "yayinda" gorsun.
    if (function_exists('konu_son_yayindakiler')) {
        $yeni = db()->prepare('SELECT id, baslik, onem, derleme FROM haberler WHERE id = :id');
        $yeni->execute(['id' => $id]);

        if (($satir = $yeni->fetch()) !== false) {
            konu_son_yayindakiler($satir);
        }
    }

    require_once __DIR__ . '/arama_bildirim.php';
    arama_bildir_kayit('haber', $id);
    // Son dakika ya da onemli haber: tarayici bildirimi (03.11.2026; bkz. includes/web_push.php).
    require_once __DIR__ . '/web_push.php';
    push_haber_yayinlandi($id);
    // Ayni olcutle Telegram kanalina (03.11.2026; bkz. includes/telegram.php).
    require_once __DIR__ . '/telegram.php';
    telegram_haber_yayinlandi($id);

    return true;
}

/**
 * Tek konu, tek haber (08.11.2026; bkz. konu_tekrar_bul): haber yayindaki
 * bir haberin tekrariysa ya da konusu dolmussa taslakta birakilir ve
 * isaretlenir (tekrar_id); sonraki otomatik yayin turlarinda yeniden
 * denenmez. Ozgun veri haberleri (valentra-*) ve derlemeler bekletilmez.
 */
function haber_tekrar_bekletilir(int $id): bool
{
    if (!haber_sutun_var('tekrar_id')) {
        return false;
    }

    try {
        $ifade = db()->prepare(
            'SELECT id, baslik, onem, derleme, tekrar_id, ajan_modeli, durum FROM haberler WHERE id = :id'
        );
        $ifade->execute(['id' => $id]);
        $h = $ifade->fetch();

        if ($h === false || $h['durum'] !== HABER_TASLAK) {
            return false;
        }

        if ($h['tekrar_id'] !== null) {
            return true;
        }

        if ((int) $h['derleme'] === 1 || str_starts_with((string) $h['ajan_modeli'], 'valentra-')) {
            return false;
        }

        require_once __DIR__ . '/konu_siniri.php';

        $es = konu_tekrar_bul(['id' => (int) $h['id'], 'baslik' => (string) $h['baslik'], 'onem' => (int) $h['onem']]);

        if ($es === null) {
            return false;
        }

        db()->prepare(
            "UPDATE haberler
                SET tekrar_id = :es,
                    ajan_notu = LEFT(TRIM(CONCAT(:not, ' ', COALESCE(ajan_notu, ''))), 600),
                    guncellendi = guncellendi
              WHERE id = :id"
        )->execute([
            'es'  => $es['id'],
            'not' => mb_substr('[Yayımlanmadı: ' . $es['neden'] . ' — #' . $es['id'] . ' ' . $es['baslik']
                . '. Derlemeye malzeme; gerekirse elle yayımlayın.]', 0, 400, 'UTF-8'),
            'id'  => $id,
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('[valentra] tekrar bekletme: ' . $e->getMessage());

        return false;
    }
}

/**
 * Taslakta kalan BUTUN haberleri yayina alir (28.10.2026'ya kadar yalnizca
 * bugunkuleri); kac haber alindigini dondurur. Eski taslak kendi toplanma
 * zamaniyla yayina giriyor (yayin_tarihi = olusturuldu): ana sayfanin
 * basina "yeni" diye cikmiyor. Otomatik yayin kapaliysa hicbir sey
 * yapmaz. Reddedilen haberlere dokunmaz.
 */
function haber_bugunku_taslaklari_yayinla(): int
{
    if (!haber_otomatik_yayin_acik()) {
        return 0;
    }

    /*
     * Onay bekleme kalkti (14.11.2026; bkz. oncelik_karar): 12-14.11 arasinda
     * incelemeye dusmus haberler de serbest. Kritik olanlar yuksek sinifa
     * iner (dogrulanmadan manset adayi olmaz). Editorun elle durdurduklari
     * ("durduruldu") yerinde kalir.
     */
    if (haber_sutun_var('inceleme')) {
        db()->prepare(
            "UPDATE haberler
                SET oncelik = LEAST(oncelik, 2), aciliyet = LEAST(aciliyet, 1), inceleme = '', guncellendi = guncellendi
              WHERE durum = :t AND inceleme IN ('acil', 'bekliyor')"
        )->execute(['t' => HABER_TASLAK]);
    }

    $ifade = db()->prepare(
        'SELECT id FROM haberler
          WHERE durum = :taslak
            AND ' . haber_guven_esigi_kosulu() . '
            ' . (haber_sutun_var('tekrar_id') ? 'AND tekrar_id IS NULL' : '') . '
            ' . (haber_sutun_var('inceleme') ? "AND inceleme = ''" : '') . '
          ORDER BY id'
    );
    $ifade->execute(['taslak' => HABER_TASLAK]);

    $adet = 0;

    foreach ($ifade->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (haber_otomatik_yayinla((int) $id)) {
            $adet++;
        }
    }

    return $adet;
}

/**
 * Ana sayfa acilisinda cagriliyor: en fazla 5 dakikada bir
 * haber_bugunku_taslaklari_yayinla. Her istekte tek kucuk SELECT.
 */
function haber_bugunku_taslaklari_yayinla_arada(): void
{
    require_once __DIR__ . '/ayarlar.php';

    $son = (int) ayar_oku('haber_otomatik_yayin_son', '0');

    if (time() - $son < 300) {
        return;
    }

    try {
        ayar_yaz('haber_otomatik_yayin_son', (string) time());
        haber_bugunku_taslaklari_yayinla();
    } catch (Throwable $e) {
        // Ana sayfa bu yuzden dusmemeli; bir sonraki acilista yeniden.
        error_log('[valentra] otomatik yayin: ' . $e->getMessage());
    }

    // Kucuk onizleme olarak inmis gorseller buyuk boyuyla yenilenir (tek seferlik).
    try {
        require_once __DIR__ . '/gorsel_onbellek.php';
        haber_gorsel_onizlemeleri_yenile();
    } catch (Throwable $e) {
        error_log('[valentra] gorsel yenileme: ' . $e->getMessage());
    }

    // Google'a sunulan haberlerde %70 ozgun (bkz. haber_ozgun_kotasini_dagit).
    try {
        haber_ozgun_kotasini_dagit();
    } catch (Throwable $e) {
        error_log('[valentra] ozgun kota: ' . $e->getMessage());
    }
}

/**
 * Yayindan geri cekme veya reddetme.
 */
function haber_durum_degistir(int $id, string $durum): bool
{
    if (!in_array($durum, [HABER_TASLAK, HABER_YAYINDA, HABER_REDDEDILDI], true)) {
        throw new InvalidArgumentException('Gecersiz durum.');
    }

    $ifade = db()->prepare('UPDATE haberler SET durum = :durum, guncellendi = guncellendi WHERE id = :id');
    $ifade->execute(['durum' => $durum, 'id' => $id]);

    if ($ifade->rowCount() > 0 && $durum === HABER_YAYINDA) {
        require_once __DIR__ . '/arama_bildirim.php';
        arama_bildir_kayit('haber', $id);
        // Son dakika ya da onemli haber: tarayici bildirimi (03.11.2026; bkz. includes/web_push.php).
        require_once __DIR__ . '/web_push.php';
        push_haber_yayinlandi($id);
        // Ayni olcutle Telegram kanalina (03.11.2026; bkz. includes/telegram.php).
        require_once __DIR__ . '/telegram.php';
        telegram_haber_yayinlandi($id);
    }

    return $ifade->rowCount() > 0;
}

/*
 * AYNI BASLIKLI HABERLER (07.11.2026; Google denetimi: "3 baslik birden
 * fazla haberde"). Ayni baslik Google'da kopya sayfa gibi gorunuyor.
 * Ajan ayni basligi ikinci kez eklemiyor (haber_kopya_bul), ama baslik
 * sonradan kisaltilinca (api/baslik-denetim.php) iki farkli gunun haberi
 * ayni basliga dusebiliyor ("Altin fiyatlari yukseliste" gibi). Sonraki
 * habere gunu ekleniyor: "... (7 Kasım)"; o da varsa saat. Adres (slug)
 * degismiyor.
 */
const HABER_AYLAR = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

/** Başlık başka bir haberde (reddedilenler hariç) kullanılıyor mu. */
function haber_baslik_kullaniliyor(string $baslik, int $haricId): bool
{
    $ifade = db()->prepare(
        'SELECT 1 FROM haberler WHERE baslik = :b AND id <> :id AND durum <> :r LIMIT 1'
    );
    $ifade->execute(['b' => $baslik, 'id' => $haricId, 'r' => HABER_REDDEDILDI]);

    return $ifade->fetchColumn() !== false;
}

/** Başlık başka haberde varsa gününü (gerekirse saatini) ekleyerek ayırır. */
function haber_baslik_ayirt(string $baslik, int $haricId, ?string $tarih): string
{
    if ($baslik === '' || !haber_baslik_kullaniliyor($baslik, $haricId)) {
        return $baslik;
    }

    $an  = strtotime((string) $tarih) ?: time();
    $gun = (int) date('j', $an) . ' ' . HABER_AYLAR[(int) date('n', $an)];

    foreach ([$baslik . ' (' . $gun . ')', $baslik . ' (' . $gun . ', ' . date('H.i', $an) . ')'] as $aday) {
        if (!haber_baslik_kullaniliyor($aday, $haricId)) {
            return $aday;
        }
    }

    return $baslik . ' (' . $haricId . ')';
}

/**
 * Google'a sunulan yayındaki haberlerde aynı başlıklıları ayırır: en eski
 * başlığını korur, sonrakilere gün eklenir. Google denetimi çağırıyor.
 *
 * @return list<array{id:int,eski:string,yeni:string}>
 */
function haber_ayni_basliklari_ayir(int $enFazla = 50): array
{
    $satirlar = db()->query(
        "SELECT h.id, h.baslik, k.baslik AS grup, COALESCE(h.yayin_tarihi, h.olusturuldu) AS tarih
           FROM haberler h
           JOIN (SELECT baslik FROM haberler
                  WHERE durum = 'yayinda' AND " . haber_dizinlenir_kosulu() . "
                  GROUP BY baslik HAVING COUNT(*) > 1) k ON k.baslik = h.baslik
          WHERE h.durum = 'yayinda'
          ORDER BY k.baslik, COALESCE(h.yayin_tarihi, h.olusturuldu), h.id
          LIMIT " . max(1, min(500, $enFazla * 3))
    )->fetchAll();

    $duzelt = db()->prepare('UPDATE haberler SET baslik = :b WHERE id = :id');
    $gorulen = [];
    $yapilan = [];

    foreach ($satirlar as $s) {
        $baslik = (string) $s['baslik'];

        /*
         * Grup veritabaninin karsilastirmasiyla (utf8mb4_unicode_ci): buyuk
         * kucuk harf ve sondaki bosluk farki ayni baslik sayiliyor. PHP'de
         * tam esitlige bakmak boyle bir grubu ayirmadan birakiyordu.
         * Her grupta ilk (en eski) haber oldugu gibi kaliyor.
         */
        $grup = (string) $s['grup'];

        if (!isset($gorulen[$grup])) {
            $gorulen[$grup] = true;
            continue;
        }

        $yeni = haber_baslik_ayirt($baslik, (int) $s['id'], (string) $s['tarih']);

        if ($yeni !== $baslik && count($yapilan) < $enFazla) {
            $duzelt->execute(['b' => mb_substr($yeni, 0, 300, 'UTF-8'), 'id' => (int) $s['id']]);
            $yapilan[] = ['id' => (int) $s['id'], 'eski' => $baslik, 'yeni' => $yeni];
        }
    }

    return $yapilan;
}

/**
 * Yonetici duzenlemesi.
 */
function haber_guncelle(int $id, array $veri): bool
{
    $baslik = trim((string) ($veri['baslik'] ?? ''));
    $icerik = trim((string) ($veri['icerik'] ?? ''));

    if ($baslik === '' || $icerik === '') {
        throw new InvalidArgumentException('Baslik ve icerik bos birakilamaz.');
    }

    $ifade = db()->prepare(
        'UPDATE haberler
            SET baslik = :baslik,
                slug = :slug,
                ozet = :ozet,
                icerik = :icerik,
                gorsel_denetim = IF(gorsel_url <=> :gorsel_onceki, gorsel_denetim, NULL),
                gorsel_url = :gorsel_url,
                iframe_url = :iframe_url,
                etiketler = :etiketler,
                one_cikan = :one_cikan,
                kategori_id = :kategori_id,
                onem = :onem,
                analiz_degisen = :analiz_degisen,
                analiz_etkilenen = :analiz_etkilenen,
                analiz_zaman = :analiz_zaman,
                analiz_islem = :analiz_islem,
                isletme_etkisi = :isletme_etkisi,
                uygulama_ornegi = :uygulama_ornegi,
                resmi_dayanak = :resmi_dayanak,
                neden_onemli = :neden_onemli
          WHERE id = :id'
    );

    $ozet = trim((string) ($veri['ozet'] ?? ''));

    return $ifade->execute([
        'baslik'     => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'       => haber_benzersiz_slug($baslik, $id),
        'ozet'       => mb_substr($ozet !== '' ? $ozet : kisalt($icerik, 220), 0, 600, 'UTF-8'),
        'icerik'     => $icerik,
        'gorsel_url' => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: null,
        // Gorsel degistiyse yeniden denetlensin (bkz. haber_gorsel_denetimli_mi).
        'gorsel_onceki' => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: null,
        'iframe_url' => ($iframeUrl = guvenli_url((string) ($veri['iframe_url'] ?? ''))) !== ''
            ? mb_substr($iframeUrl, 0, 1000, 'UTF-8')
            : null,
        'etiketler'  => mb_substr(trim((string) ($veri['etiketler'] ?? '')), 0, 400, 'UTF-8'),
        'one_cikan'  => !empty($veri['one_cikan']) ? 1 : 0,
        'kategori_id'=> ($veri['kategori_id'] ?? '') !== '' ? (int) $veri['kategori_id'] : null,
        'onem'       => max(0, min(100, (int) (($veri['onem'] ?? '') !== '' ? $veri['onem'] : 50))),

        /*
         * Valentra Analiz alanlari editorun denetiminde.
         *
         * "Hangi islem yapilmali" cevabi modelden geliyor ve YMM
         * imzasi tasiyan bir sitede yayimlaniyor. Editorun onu
         * duzeltebilmesi ya da tamamen silebilmesi sart; duzenleme
         * formunda bos birakilan alan bos kaydediliyor ve sayfada hic
         * gorunmuyor.
         */
        'analiz_degisen'   => mb_substr(trim((string) ($veri['analiz_degisen'] ?? '')), 0, 600, 'UTF-8'),
        'analiz_etkilenen' => mb_substr(trim((string) ($veri['analiz_etkilenen'] ?? '')), 0, 600, 'UTF-8'),
        'analiz_zaman'     => mb_substr(trim((string) ($veri['analiz_zaman'] ?? '')), 0, 600, 'UTF-8'),
        'analiz_islem'     => mb_substr(trim((string) ($veri['analiz_islem'] ?? '')), 0, 600, 'UTF-8'),
        'id'         => $id,
    ] + haber_format_alanlari($veri));
}

/**
 * Haber formatinin ek alanlari: isletmeye etkisi, uygulama ornegi, resmi
 * dayanak. Ajandan da panelden de ayni kirpma ile yaziliyor; uzun bir
 * model cevabi INSERT'i dusurmesin. Bos birakilan alan sayfada gorunmez.
 *
 * @param array<string,mixed> $veri
 * "Neden onemli?" da burada (26.10.2026): ajandan ve panelden ayni
 * kirpma; editor bos birakirsa sayfada gorunmez.
 *
 * @return array{isletme_etkisi:string,uygulama_ornegi:?string,resmi_dayanak:string,neden_onemli:string}
 */
function haber_format_alanlari(array $veri): array
{
    $ornek = trim(str_replace(["\r\n", "\r"], "\n", (string) ($veri['uygulama_ornegi'] ?? '')));

    return [
        'isletme_etkisi'  => mb_substr(trim((string) ($veri['isletme_etkisi'] ?? '')), 0, 800, 'UTF-8'),
        'uygulama_ornegi' => $ornek !== '' ? mb_substr($ornek, 0, 6000, 'UTF-8') : null,
        'resmi_dayanak'   => mb_substr(trim((string) ($veri['resmi_dayanak'] ?? '')), 0, 400, 'UTF-8'),
        'neden_onemli'    => mb_substr(trim((string) ($veri['neden_onemli'] ?? '')), 0, 800, 'UTF-8'),
    ];
}

/**
 * Öne çıkarma: manşette ve listelerde önce gelir.
 *
 * Gunde 3-5 secilmis haber icin panelde tek tikla. Duzenleme formundaki
 * kutuyla ayni sutun.
 */
function haber_one_cikar(int $id, bool $one): void
{
    db()->prepare('UPDATE haberler SET one_cikan = :o, guncellendi = guncellendi WHERE id = :id')
        ->execute(['o' => $one ? 1 : 0, 'id' => $id]);
}

function haber_sil(int $id): bool
{
    $ifade = db()->prepare('DELETE FROM haberler WHERE id = :id');
    $ifade->execute(['id' => $id]);

    return $ifade->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Konu gruplari
// ---------------------------------------------------------------------------

/**
 * Ust menude gosterilecek aktif kategoriler.
 */
function kategori_listesi(bool $sadeceAktif = true): array
{
    $sql = 'SELECT * FROM kategoriler';

    if ($sadeceAktif) {
        $sql .= ' WHERE aktif = 1';
    }

    return db()->query($sql . ' ORDER BY sira, ad')->fetchAll();
}

/**
 * Kaynagin okuyucuya gosterilen adi: sondaki bolum adi atilmis hali.
 *
 * Ayni yayinin birden cok beslemesi ayri kaynak olarak kayitli ve adlari
 * benzersiz olmak zorunda: "Anadolu Ajansi Politika", "NTV Spor", "TRT
 * Haber Turkiye". Okuyucu icin kaynak yayinin kendisi: "Haberin tamamini
 * Anadolu Ajansi sitesinde okuyun". Yalnizca bilinen bolum adlari
 * atiliyor; "Dunya Gazetesi", "Patronlar Dunyasi", "BBC Turkce" oldugu
 * gibi kaliyor.
 */
function kaynak_gorunen_ad(string $ad): string
{
    $ad = trim($ad);

    $temiz = preg_replace(
        '/\s+(Gündem|Politika|Ekonomi|Dünya|Spor|Teknoloji|Bilim-Teknoloji|Sağlık|Kültür|Kültür-Sanat|Sanat|Türkiye)$/u',
        '',
        $ad
    );

    return is_string($temiz) && $temiz !== '' ? $temiz : $ad;
}

/**
 * Haber akisinin konu basliklari, HABER_AKIS_KATEGORILERI sirasiyla.
 * Veritabaninda henuz olmayan baslik (sema yukseltmesi calismadiysa)
 * atlaniyor.
 *
 * @return list<array{ad:string,slug:string,aciklama:string,adet:int}>
 */
function haber_akis_kategorileri(): array
{
    $sluglar = implode(', ', array_map(
        static fn (string $slug): string => db()->quote($slug),
        HABER_AKIS_KATEGORILERI
    ));

    $satirlar = db()->query(
        "SELECT k.ad, k.slug, k.aciklama, COUNT(h.id) AS adet
           FROM kategoriler k
           LEFT JOIN haberler h
             ON h.kategori_id = k.id AND h.durum = " . db()->quote(HABER_YAYINDA) . "
          WHERE k.slug IN (" . $sluglar . ")
          GROUP BY k.id, k.ad, k.slug, k.aciklama"
    )->fetchAll();

    $sira = array_flip(HABER_AKIS_KATEGORILERI);

    usort($satirlar, static fn (array $a, array $b): int =>
        $sira[(string) $a['slug']] <=> $sira[(string) $b['slug']]);

    return array_map(static fn (array $k): array => [
        'ad'       => (string) $k['ad'],
        'slug'     => (string) $k['slug'],
        'aciklama' => (string) $k['aciklama'],
        'adet'     => (int) $k['adet'],
    ], $satirlar);
}

/**
 * Ust menu agaci.
 *
 * Ust basliklar (ust_id'si olmayanlar) menude gorunur; altlarindaki
 * gruplar acilir listede listelenir. Her grubun yaninda yayindaki haber
 * sayisi gosterilir; ust basligin sayisi altlarinin toplamidir.
 *
 * @return list<array{ad:string,slug:string,adet:int,altlar:list<array<string,mixed>>}>
 */
function kategori_menusu(): array
{
    $satirlar = db()->query(
        "SELECT k.id, k.ad, k.slug, k.ust_id, k.sira,
                COUNT(h.id) AS adet
           FROM kategoriler k
           LEFT JOIN haberler h
             ON h.kategori_id = k.id AND h.durum = " . db()->quote(HABER_YAYINDA) . "
          WHERE k.aktif = 1
             OR k.slug IN ('vergi-kanunlari','muhasebe-denetim','ekonomi','tms-tfrs','genel')
          GROUP BY k.id, k.ad, k.slug, k.ust_id, k.sira
          ORDER BY k.sira, k.ad"
    )->fetchAll();

    $altlar = [];

    foreach ($satirlar as $satir) {
        if ($satir['ust_id'] !== null) {
            $altlar[(int) $satir['ust_id']][] = [
                'ad'   => $satir['ad'],
                'slug' => $satir['slug'],
                'adet' => (int) $satir['adet'],
            ];
        }
    }

    $menu = [];

    foreach ($satirlar as $satir) {
        if ($satir['ust_id'] !== null) {
            continue;
        }

        $cocuklar = $altlar[(int) $satir['id']] ?? [];

        $gorunum = [
            'vergi-kanunlari'   => ['ad' => 'Vergi Kanunları',     'sira' => 10],
            'muhasebe-denetim'  => ['ad' => 'Muhasebe ve Denetim', 'sira' => 20],
            'ekonomi'            => ['ad' => 'Ekonomi',             'sira' => 30],
            'tms-tfrs'           => ['ad' => 'TMS/TFRS',            'sira' => 40],
            'genel'              => ['ad' => 'Genel Gündem',        'sira' => 50],
        ];

        $slug = (string) $satir['slug'];
        $ad   = $gorunum[$slug]['ad'] ?? (string) $satir['ad'];

        $menu[] = [
            'ad'     => $ad,
            'slug'   => $slug,
            'adet'   => (int) $satir['adet'] + array_sum(array_column($cocuklar, 'adet')),
            'altlar' => $cocuklar,
            '_sira'  => $gorunum[$slug]['sira'] ?? (100 + (int) $satir['sira']),
        ];
    }

    usort($menu, static fn (array $a, array $b): int => $a['_sira'] <=> $b['_sira']);

    foreach ($menu as &$oge) {
        unset($oge['_sira']);
    }
    unset($oge);

    return $menu;
}

function kategori_slug_bul(string $slug): ?array
{
    $takmaAdlar = [
        'diger' => 'genel',
        'diğer' => 'genel',
        'tms' => 'tms-tfrs',
        'tfrs' => 'tms-tfrs',
    ];

    $slug = $takmaAdlar[$slug] ?? $slug;

    $ifade = db()->prepare(
        "SELECT * FROM kategoriler
          WHERE slug = :slug
            AND (aktif = 1 OR slug IN ('ekonomi','tms-tfrs','genel'))
          LIMIT 1"
    );
    $ifade->execute(['slug' => $slug]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Slug'dan kategori id'si; bulunamazsa null.
 */
function kategori_id_cozumle(?string $slug): ?int
{
    if ($slug === null || trim($slug) === '') {
        return null;
    }

    $ifade = db()->prepare('SELECT id FROM kategoriler WHERE slug = :slug LIMIT 1');
    $ifade->execute(['slug' => trim($slug)]);
    $id = $ifade->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Bir kategorideki yayinda olan haberler.
 */
function haber_kategoride(int $kategoriId, int $limit = 20, int $atla = 0): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum AND h.kategori_id = :kategori AND ' . haber_yerel_kosulu() . '
          ORDER BY ' . haber_sirasi() . '
          LIMIT :limit OFFSET :atla'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('kategori', $kategoriId, PDO::PARAM_INT);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->bindValue('atla', $atla, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_kategoride_sayi(int $kategoriId): int
{
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM haberler h WHERE h.durum = :durum AND h.kategori_id = :kategori AND ' . haber_yerel_kosulu()
    );
    $ifade->execute(['durum' => HABER_YAYINDA, 'kategori' => $kategoriId]);

    return (int) $ifade->fetchColumn();
}

/**
 * Mansette kayacak haberler (en yeni, one cikanlar once).
 */
function haber_manset(int $limit = 8, bool $anaAkis = false): array
{
    // Ana sayfada manset ve izgara AYNI sirayi kullaniyor: izgara
    // mansetin tukettigi kadar haberi atliyor.
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
           ' . ($anaAkis ? haber_okunma_birlesimi() : '') . '
          WHERE h.durum = :durum' . ($anaAkis ? ' AND ' . haber_ana_akis_kosulu() : '') . '
          ORDER BY ' . haber_sirasi($anaAkis) . '
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $anaAkis ? min($limit, ANA_SAYFA_HABER_SINIRI) : $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Yan kolondaki "son eklenen" listesi.
 *
 * Mansetten farkli siralama: one_cikan dikkate alinmaz, yalnizca en
 * yeniler. Boylece yan kolon mansetin kopyasi olmaz.
 */
/**
 * Yan pencere için "Önemli Düzenlemeler".
 *
 * NEDEN AYRI BIR SORGU: "Son Eklenen" listesi yalnizca tarihe
 * bakiyordu ve ana koloncun altindaki izgarayla buyuk olcude ayni
 * haberleri gosteriyordu — yani sag sutun kendi basina yeni bir sey
 * sunmuyordu. Okuyucunun sag sutundan bekledigi sey "en yenisi" degil,
 * "kacirmamam gereken" olmali.
 *
 * OLCUT: mevzuat kategorileri. Ekonomi gundemi ve genel haberler
 * disarida kaliyor — kur, enflasyon ve piyasa haberleri onemli olabilir
 * ama bir DUZENLEME degil. Icerideki gruplar: vergi kanunlari
 * (kurumlar, gelir, KDV, OTV, VUK, e-belge, tesvik) ve muhasebe/denetim
 * (TMS-TFRS, denetim).
 *
 * Siralama once one_cikan, sonra tarih: yonetici panelden bir haberi
 * one cikardiginda listenin basina geciyor.
 *
 * @return list<array<string,mixed>>
 */
function haber_onemli_duzenlemeler(int $limit = 5): array
{
    $gruplar = [
        'kurumlar-vergisi', 'gelir-vergisi', 'kdv', 'vergi-usul-kanunu',
        'otv-ve-diger', 'e-belge', 'tesvik-yapilandirma',
        'tms-tfrs', 'denetim',
    ];

    $yer = implode(',', array_fill(0, count($gruplar), '?'));

    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = ?
            AND k.slug IN (' . $yer . ')
          ORDER BY h.one_cikan DESC, h.yayin_tarihi DESC
          LIMIT ' . (int) $limit
    );

    $ifade->execute(array_merge([HABER_YAYINDA], $gruplar));

    return $ifade->fetchAll();
}

/**
 * Ana sayfadaki "Valentra Analiz" bölümü.
 *
 * Yalnizca degerlendirmesi DOLU haberler. Olcut olarak "ne degisti"
 * ile "kimleri etkiliyor" birlikte araniyor: tek bir alani dolu olan
 * haber analiz sayilmaz, kutu yarim gorunur.
 *
 * Bu bolum tarihe gore degil DEGERE gore secim yapiyor; ana kolondaki
 * izgara zaten kronolojik akisi veriyor.
 *
 * @return list<array<string,mixed>>
 */
function haber_analizliler(int $limit = 3, bool $anaAkis = false): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
            AND h.analiz_degisen   <> \'\'
            AND h.analiz_etkilenen <> \'\'' . ($anaAkis ? ' AND ' . haber_ana_akis_kosulu() : '') . '
          ORDER BY h.yayin_tarihi DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_son_eklenenler(int $limit = 8): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.yayin_tarihi DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Ana sayfanin alt bolumu: haberi olan her konu grubu ve o gruptaki son
 * haberler.
 *
 * Tek sorguda cekip PHP tarafinda gruplamak, grup basina ayri sorgu
 * acmaktan hizli; grup sayisi buyudukce fark artar.
 *
 * @return list<array{ad:string,slug:string,haberler:list<array<string,mixed>>}>
 */
function kategori_bloklari(int $grupBasiHaber = 4, int $enFazlaGrup = 6): array
{
    $satirlar = db()->query(
        'SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.yayin_tarihi,
                h.kaynak_adi, h.kaynak_baslik, h.kaynak_url, k.ad AS kategori_adi, k.slug AS kategori_slug, k.sira
           FROM haberler h
           JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . '
            AND k.aktif = 1
            AND NOT (' . HABER_RG_KOSULU . ')
            AND ' . haber_akis_kategori_kosulu()
            // Ana sayfanin "Konu Basliklari" kutulari: konu tavani burada da (08.11.2026).
            . haber_konu_siniri_kosulu() . '
          ORDER BY k.sira, k.ad, ' . haber_sirasi()
    )->fetchAll();

    $bloklar = [];

    foreach ($satirlar as $satir) {
        $slug = (string) $satir['kategori_slug'];

        if (!isset($bloklar[$slug])) {
            $bloklar[$slug] = [
                'ad'       => (string) $satir['kategori_adi'],
                'slug'     => $slug,
                'haberler' => [],
            ];
        }

        if (count($bloklar[$slug]['haberler']) < $grupBasiHaber) {
            $bloklar[$slug]['haberler'][] = $satir;
        }
    }

    // Tek haberi olan grup blok olarak anlamli gorunmuyor; en az iki
    // haberi olanlari gosteriyoruz.
    $bloklar = array_filter(
        $bloklar,
        static fn (array $b): bool => count($b['haberler']) >= 2
    );

    return array_slice(array_values($bloklar), 0, $enFazlaGrup);
}

/**
 * En cok kullanilan etiketler (yan kolon icin).
 *
 * @return list<array{etiket:string,adet:int}>
 */
function haber_etiket_bulutu(int $limit = 12): array
{
    $satirlar = db()->query(
        'SELECT etiketler FROM haberler WHERE durum = ' . db()->quote(HABER_YAYINDA)
    )->fetchAll(PDO::FETCH_COLUMN);

    $sayimlar = [];

    foreach ($satirlar as $ham) {
        foreach (etiketleri_coz((string) $ham) as $etiket) {
            $anahtar = mb_strtolower($etiket, 'UTF-8');

            if (!isset($sayimlar[$anahtar])) {
                $sayimlar[$anahtar] = ['etiket' => $etiket, 'adet' => 0];
            }

            $sayimlar[$anahtar]['adet']++;
        }
    }

    usort($sayimlar, static fn (array $a, array $b): int => $b['adet'] <=> $a['adet']);

    return array_slice($sayimlar, 0, $limit);
}

/**
 * Haberin dayandigi belgenin turu: "Tebliğ", "Özelge", "Kanun" ...
 *
 * Onemli Duzenlemeler listesinde rozet olarak gorunuyor; okuyucu bir
 * tebligi bir kanun teklifinden ya da GIB duyurusundan ilk bakista
 * ayirabilsin. Once resmi dayanak alani (editorun/ajanin yazdigi
 * belge adi), bos ise baslik okunuyor.
 *
 * Sira onemli: "Vergi Usul Kanunu Genel Tebliği" bir TEBLIG; kanun
 * kontrolu sona birakiliyor. "Kanun teklifi" ayri: henuz yururlukte
 * olmayan bir metni "Kanun" diye gostermek okuyucuyu yaniltir.
 *
 * Tanimlanamayan haber icin null; cagiran "Haber" gibi notr bir etiket
 * kullanir ya da rozet basmaz.
 *
 * @param array<string,mixed> $haber
 */
function haber_belge_turu(array $haber): ?string
{
    $kucuk = static fn (string $s): string => mb_strtolower(strtr($s, ['I' => 'ı', 'İ' => 'i']));

    $kurallar = [
        'Özelge'      => '/özelge/u',
        'Sirküler'    => '/sirküler/u',
        'Genelge'     => '/genelge/u',
        'Tebliğ'      => '/tebliğ/u',
        'Yönetmelik'  => '/yönetmeli[kğ]/u',
        'CB Kararı'   => '/cumhurbaşkanı kararı|cumhurbaşkanlığı kararı|bakanlar kurulu kararı/u',
        'Yargı Kararı'=> '/danıştay|yargıtay|anayasa mahkemesi|bölge idare mahkemesi|vergi mahkemesi/u',
        'Kanun Teklifi' => '/kanun teklifi|kanun tasarısı/u',
        'Kanun'       => '/kanun|yasa\b/u',
        'Duyuru'      => '/duyuru/u',
    ];

    // Baslikta kanun ADI cok gecer ("KDV Kanunu kapsaminda iade"); orada
    // yalnizca yeni bir kanunun kendisini anlatan kaliplar sayiliyor.
    $baslikKanun = '/sayılı kanun|torba (yasa|kanun)|kanun(u)? (resmî |resmi )?(gazete|kabul edildi|yayımlandı|yürürlüğe girdi)|kanunu? değişti/u';

    foreach (['resmi_dayanak', 'baslik'] as $alan) {
        $metin = trim((string) ($haber[$alan] ?? ''));

        if ($metin === '') {
            continue;
        }

        $metin = $kucuk($metin);

        foreach ($kurallar as $tur => $desen) {
            if ($tur === 'Kanun' && $alan === 'baslik') {
                $desen = $baslikKanun;
            }

            if (preg_match($desen, $metin) === 1) {
                return $tur;
            }
        }
    }

    // Belge adi yoksa GIB'in kendi sitesinden gelen haber bir duyurudur.
    if (str_contains((string) ($haber['kaynak_url'] ?? ''), 'gib.gov.tr')) {
        return 'Duyuru';
    }

    return null;
}

/* ------------------------------------------------------------------ *
 * Google'a sunulan haberler ve ilgili haberler (26.10.2026)
 *
 * Search Console: 166 sayfa "Tarandi - dizine eklenmemis", 254 sayfa
 * "Kesfedildi". Google bir haber ozetini, aslinin zaten dizininde olmasi
 * nedeniyle cogu zaman almiyor; cok sayida kisa sayfa da butun sitenin
 * degerlendirmesini dusuruyor. Iki onlem:
 *   - Cok kisa haberler (metin + "Neden onemli?" toplami bu sinirin
 *     altinda) "noindex, follow" ile isaretleniyor ve site
 *     haritalarina konmuyor. Okuyucu icin sitede kalmaya devam ediyor.
 *   - Her haberin altinda ayni baslik ve etiketlerden ilgili haberler:
 *     ic baglanti hem okuru sitede tutuyor hem Google'in sayfalari
 *     kesfetmesini hizlandiriyor.
 * ------------------------------------------------------------------ */

/** Bu uzunluğun (karakter) altındaki haber Google'a sunulmuyor (~90 kelime). */
const HABER_INCE_KARAKTER = 600;

/*
 * KISA HABERLER DE GOOGLE'A (site sahibinin karari, 28.10.2026 aksami:
 * "kisa haberleri de ac"). true iken uzunluk siniri dizine almayi
 * engellemiyor; HABER_INCE_KARAKTER yalnizca ozgun haber uretiminde
 * (includes/ozgun_haber.php) kullaniliyor. false: 600 alti yine noindex.
 */
const HABER_KISA_DA_DIZINDE = true;

/** @param array<string,mixed> $haber */
function haber_ince_mi(array $haber): bool
{
    if (HABER_KISA_DA_DIZINDE) {
        return false;
    }

    $uzunluk = mb_strlen(trim((string) ($haber['icerik'] ?? '')), 'UTF-8')
             + mb_strlen(trim((string) ($haber['neden_onemli'] ?? '')), 'UTF-8');

    return $uzunluk < HABER_INCE_KARAKTER;
}

/*
 * YALNIZCA DERLEME VE KENDI HABERLERIMIZ GOOGLE'A (site sahibinin karari,
 * 28.10.2026). Tek bir kaynagin haberinin ozeti, ne kadar uzun olursa
 * olsun, Google'a gore aslinin kopyasi: 26.10'da 166 sayfa "Tarandi -
 * dizine eklenmemis", 254 sayfa "Kesfedildi". Bu sayfalar sitede okuyucu
 * icin kaliyor ama "noindex, follow" ve site haritalarinin disinda.
 * Google'a sunulanlar:
 *   - derleme haberler: ayni olayi veren birden cok kaynaktan Valentra'nin
 *     yazdigi ozgun haber (bkz. includes/derleme.php),
 *   - kaynagi olmayan, panelde yazilmis haberler,
 *   - ozgun haberler (28.10.2026): Valentra Veri (sitenin kendi verisinden;
 *     includes/ozgun_haber.php) ve Valentra Dosya aciklayici yazilari
 *     (includes/dosya.php); ajan_modeli "valentra-" ile basliyor.
 * false: eskisi gibi yalnizca kisa haberler disarida.
 *
 * KAPATILDI (site sahibinin karari, 28.10.2026 aksami: "tum haberler
 * gonderilsin"): yayindaki butun haberler yine Google'a sunuluyor;
 * yalnizca cok kisa (HABER_INCE_KARAKTER alti) haberler disarida.
 */
const HABER_YALNIZCA_OZGUN_DIZINDE = false;   // 28.10.2026 aksami: site sahibi "tum haberler gonderilsin" dedi

/** @param array<string,mixed> $haber */
function haber_derleme_mi(array $haber): bool
{
    return (int) ($haber['derleme'] ?? 0) === 1;
}

/**
 * Haber Google'a sunuluyor mu (sayfada "index", site haritalarinda)?
 *
 * @param array<string,mixed> $haber
 */
function haber_google_sunulur_mu(array $haber): bool
{
    if (haber_ince_mi($haber)) {
        return false;
    }

    // Turkiye'yle ilgisi zayif dis haber (bkz. HABER_DUSUK_ILGI); Search
    // Console'da trafik alan sayfa korunuyor.
    if (haber_dusuk_ilgi_mi($haber) && !isset(haber_kota_korunanlar()[(string) ($haber['slug'] ?? '')])) {
        return false;
    }

    // %70 ozgun kurali (bkz. HABER_OZGUN_HEDEF_YUZDE): ozgun haber her
    // zaman, tek kaynakli haber yalnizca kotaya girdiyse.
    if (HABER_OZGUN_KOTASI_ACIK && array_key_exists('google_kota', $haber)) {
        return haber_ozgun_mu($haber) || (int) $haber['google_kota'] === 1;
    }

    if (!HABER_YALNIZCA_OZGUN_DIZINDE) {
        return true;
    }

    return haber_derleme_mi($haber) || trim((string) ($haber['kaynak_url'] ?? '')) === ''
        || str_starts_with((string) ($haber['ajan_modeli'] ?? ''), 'valentra-');
}

/**
 * Site haritalarında kullanılan SQL koşulu (haber_google_sunulur_mu'nun
 * karşılığı).
 *
 * "neden_onemli" ya da "derleme" sutunu yoksa (sema yukseltmesi dustuyse)
 * olmayan sutun kosula girmiyor: site haritasi hata verip bos kalmasin.
 */
function haber_dizinlenir_kosulu(string $takma = ''): string
{
    static $neden = null;
    static $derleme = null;

    if ($neden === null) {
        $neden   = haber_sutun_var('neden_onemli');
        $derleme = haber_sutun_var('derleme');
    }

    $p = $takma !== '' ? $takma . '.' : '';

    $kosul = HABER_KISA_DA_DIZINDE ? '(1 = 1)'
           : '(CHAR_LENGTH(' . $p . 'icerik)' . ($neden ? ' + CHAR_LENGTH(' . $p . 'neden_onemli)' : '')
             . ' >= ' . HABER_INCE_KARAKTER . ')';

    $kosul .= ' AND ' . haber_dusuk_ilgi_disi_kosulu($takma);

    if (HABER_OZGUN_KOTASI_ACIK && haber_sutun_var('google_kota')) {
        $kosul .= ' AND (' . haber_ozgun_kosulu($takma) . ' OR ' . $p . 'google_kota = 1)';
    } elseif (HABER_YALNIZCA_OZGUN_DIZINDE) {
        $kosul .= ' AND (' . ($derleme ? $p . 'derleme = 1 OR ' : '') . $p . "kaynak_url = '' OR " . $p . "ajan_modeli LIKE 'valentra-%')";
    }

    return $kosul;
}

/* ------------------------------------------------------------------ *
 * TURKIYE'YLE ILGISI ZAYIF DIS HABERLER GOOGLE'A SUNULMUYOR (site
 * sahibinin karari, 04.11.2026).
 *
 * Search Console'da yuzlerce haber "Bulundu, henuz taranmadi": Google
 * yeni sitenin sinirli tarama butcesini Florida'da ucak kazasi, Alman alt
 * lig teknik direktoru, Brezilya'da ciftlik dezenfeksiyonu gibi Turk
 * okurun aramadigi sayfalara harciyor; onemli haberler sirada bekliyor.
 * Bu kategorilerde onemi esigin altindaki haberler okur icin sitede kaliyor
 * ama "noindex, follow" ve site haritalarinin disinda. Onem puanini ajan
 * Turkiye'ye etkisine gore veriyor (Turkiye'yi dogrudan etkileyen dunya
 * olayi 85+, Turk kulupleri 45-60, yabanci ligler 30-45).
 * ------------------------------------------------------------------ */

/** Kategori (ve alt kategorileri) => Google'a sunulmak için gereken en az önem. */
const HABER_DUSUK_ILGI = ['dunya' => 60, 'spor' => 45];

/** @return array<int,int> kategori id => eşik */
function haber_dusuk_ilgi_kategorileri(): array
{
    static $esik = null;

    if ($esik !== null) {
        return $esik;
    }

    $esik = [];

    try {
        $satirlar = db()->query('SELECT id, slug, ust_id FROM kategoriler')->fetchAll();
    } catch (Throwable $e) {
        return $esik;
    }

    $slugId = array_column($satirlar, 'id', 'slug');

    foreach ($satirlar as $k) {
        $ana = (string) $k['slug'];

        if (!isset(HABER_DUSUK_ILGI[$ana]) && !empty($k['ust_id'])) {
            $ana = (string) (array_search((int) $k['ust_id'], array_map('intval', $slugId), true) ?: '');
        }

        if (isset(HABER_DUSUK_ILGI[$ana])) {
            $esik[(int) $k['id']] = HABER_DUSUK_ILGI[$ana];
        }
    }

    return $esik;
}

/** @param array<string,mixed> $haber */
function haber_dusuk_ilgi_mi(array $haber): bool
{
    $esik = haber_dusuk_ilgi_kategorileri()[(int) ($haber['kategori_id'] ?? 0)] ?? null;

    return $esik !== null && (int) ($haber['onem'] ?? 50) < $esik;
}

/** haber_dusuk_ilgi_mi'nin tersinin SQL karşılığı ("bu haber düşük ilgili değil"). */
function haber_dusuk_ilgi_disi_kosulu(string $takma = ''): string
{
    $p = $takma !== '' ? $takma . '.' : '';
    $parca = [];

    foreach (haber_dusuk_ilgi_kategorileri() as $id => $esik) {
        $parca[] = '(COALESCE(' . $p . 'kategori_id, 0) = ' . (int) $id . ' AND COALESCE(' . $p . 'onem, 50) < ' . (int) $esik . ')';
    }

    return $parca === [] ? '(1 = 1)' : 'NOT (' . implode(' OR ', $parca) . ')';
}

/* ------------------------------------------------------------------ *
 * GOOGLE'A SUNULANLARIN %70'I OZGUN (site sahibinin karari, 03.11.2026:
 * "ozgun icerik payini artir, %70 ozgun icerik olsun; haberleri
 * Google'in kabul edecegi olcude ozgunlestirelim").
 *
 * Search Console'da yuzlerce haber "Tarandi - dizine eklenmemis":
 * Google tek bir yayinin haberinin ozetini, aslinin kopyasi sayip
 * almiyor; bu sayfalarin cokluguyla butun sitenin kalitesini dusuk
 * degerlendiriyor. Bu yuzden Google'a sunulan haberlerin en az
 * HABER_OZGUN_HEDEF_YUZDE'si ozgun:
 *
 *   OZGUN (haber_ozgun_mu): derleme haber; birden cok yayinin metninden
 *   yazilan haber (derleme_kaynaklari dolu); sitenin kendi verisinden ya
 *   da panelde yazilan haber (Valentra Veri, Dosya; kaynak adresi yok).
 *
 *   TEK KAYNAKLI haberler kalan paya (ozgun sayisinin 30/70'i) onem ve
 *   tazelige gore giriyor (google_kota = 1). Search Console'da tiklama ya
 *   da en az HABER_KOTA_KORUMA_GOSTERIM gosterim alan sayfa her durumda
 *   korunuyor: trafik getiren sayfa dizinden cikmiyor.
 *
 * Kotaya girmeyen haber okuyucu icin sitede yayinda kaliyor; yalnizca
 * "noindex, follow" ve site haritalarinin disinda. Kota bes dakikada bir
 * (ana sayfa) ve site haritasi istendiginde yeniden hesaplaniyor.
 * ------------------------------------------------------------------ */

/** false: eski davranis (bkz. HABER_YALNIZCA_OZGUN_DIZINDE). */
const HABER_OZGUN_KOTASI_ACIK = true;

/** Google'a sunulan haberlerde özgün payı (yüzde). */
const HABER_OZGUN_HEDEF_YUZDE = 70;

/** Bu kadar gösterim alan tek kaynaklı sayfa kotadan bağımsız dizinde kalır. */
const HABER_KOTA_KORUMA_GOSTERIM = 20;

/** Tek kaynaklı haberin tazelik cezası: her gün için önem puanından düşülen. */
const HABER_KOTA_GUNLUK_CEZA = 6;

/** @param array<string,mixed> $haber */
function haber_ozgun_mu(array $haber): bool
{
    return haber_derleme_mi($haber)
        || trim((string) ($haber['kaynak_url'] ?? '')) === ''
        || str_starts_with((string) ($haber['ajan_modeli'] ?? ''), 'valentra-')
        || trim((string) ($haber['derleme_kaynaklari'] ?? '')) !== '';
}

/** haber_ozgun_mu'nun SQL karşılığı (sütunu olmayan koşul girmiyor). */
function haber_ozgun_kosulu(string $takma = ''): string
{
    $p = $takma !== '' ? $takma . '.' : '';
    $parca = [$p . "kaynak_url = ''", $p . "ajan_modeli LIKE 'valentra-%'"];

    if (haber_sutun_var('derleme')) {
        $parca[] = $p . 'derleme = 1';
    }

    if (haber_sutun_var('derleme_kaynaklari')) {
        $parca[] = '(' . $p . 'derleme_kaynaklari IS NOT NULL AND ' . $p . "derleme_kaynaklari <> '')";
    }

    return '(' . implode(' OR ', $parca) . ')';
}

/**
 * Search Console'da trafik alan sayfaların haber adları (slug).
 *
 * @return array<string,bool>
 */
function haber_kota_korunanlar(): array
{
    require_once __DIR__ . '/ayarlar.php';

    $veri = json_decode(ayar_oku('gsc_sayfalar'), true);
    $korunan = [];

    foreach (is_array($veri) ? ($veri['satirlar'] ?? []) : [] as $r) {
        if (!is_array($r) || ((int) ($r['tiklama'] ?? 0) < 1 && (int) ($r['gosterim'] ?? 0) < HABER_KOTA_KORUMA_GOSTERIM)) {
            continue;
        }

        $adres = (string) ($r['anahtar'][0] ?? '');
        $yol   = (string) parse_url($adres, PHP_URL_PATH);

        if (preg_match('~/haber/([^/]+)/?$~', $yol, $m) === 1) {
            $korunan[rawurldecode($m[1])] = true;
        }

        parse_str((string) parse_url($adres, PHP_URL_QUERY), $q);

        if (is_string($q['h'] ?? null) && $q['h'] !== '') {
            $korunan[$q['h']] = true;
        }
    }

    /*
     * Google'in DIZINE ALDIGI sayfalar da korunuyor (09.11.2026; Search
     * Console "noindex etiketi tarafindan haric tutuldu" 412 ve artiyordu):
     * URL denetiminde "PASS" cikan haber noindex'e cevrilmiyor.
     */
    try {
        foreach (db()->query("SELECT url FROM gsc_denetim WHERE sonuc = 'PASS'")->fetchAll(PDO::FETCH_COLUMN) as $adres) {
            if (preg_match('~/haber/([^/?#]+)/?$~', (string) parse_url((string) $adres, PHP_URL_PATH), $m) === 1) {
                $korunan[rawurldecode($m[1])] = true;
            }
        }
    } catch (Throwable $e) {
        // gsc_denetim tablosu yoksa yalnizca trafik alanlar korunur.
    }

    return $korunan;
}

/**
 * Tek kaynaklı haberlerin Google kotasını yeniden dağıtır.
 *
 * @return array{ozgun:int,tek:int,kota:int,secilen:int,korunan:int,oran:float}|null
 *         null: sütun yok ya da süresi dolmadı
 */
function haber_ozgun_kotasini_dagit(bool $zorla = false): ?array
{
    if (!HABER_OZGUN_KOTASI_ACIK || !haber_sutun_var('google_kota')) {
        return null;
    }

    require_once __DIR__ . '/ayarlar.php';

    if (!$zorla && time() - (int) ayar_oku('haber_kota_son', '0') < 240) {
        return null;
    }

    ayar_yaz('haber_kota_son', (string) time());

    $ince   = (HABER_KISA_DA_DIZINDE ? '1 = 1' : 'CHAR_LENGTH(icerik) + CHAR_LENGTH(neden_onemli) >= ' . HABER_INCE_KARAKTER)
            . ' AND ' . haber_dusuk_ilgi_disi_kosulu();
    $ozgunK = haber_ozgun_kosulu();

    $ozgun = (int) db()->query(
        "SELECT COUNT(*) FROM haberler WHERE durum = 'yayinda' AND " . $ince . ' AND ' . $ozgunK
    )->fetchColumn();

    $tekler = db()->query(
        "SELECT id, slug, onem, google_kota, TIMESTAMPDIFF(HOUR, yayin_tarihi, NOW()) AS saat
           FROM haberler
          WHERE durum = 'yayinda' AND " . $ince . ' AND NOT ' . $ozgunK
    )->fetchAll();

    $korunanAd = haber_kota_korunanlar();
    $kota      = intdiv($ozgun * (100 - HABER_OZGUN_HEDEF_YUZDE), HABER_OZGUN_HEDEF_YUZDE);

    // Once trafik alan sayfalar, sonra onem eksi tazelik cezasi.
    usort($tekler, static function (array $a, array $b) use ($korunanAd): int {
        $ka = isset($korunanAd[$a['slug']]) || (int) $a['google_kota'] === 1 ? 1 : 0;
        $kb = isset($korunanAd[$b['slug']]) || (int) $b['google_kota'] === 1 ? 1 : 0;

        if ($ka !== $kb) {
            return $kb <=> $ka;
        }

        $pa = (int) $a['onem'] - max(0, (int) $a['saat']) / 24 * HABER_KOTA_GUNLUK_CEZA;
        $pb = (int) $b['onem'] - max(0, (int) $b['saat']) / 24 * HABER_KOTA_GUNLUK_CEZA;

        return $pb <=> $pa ?: (int) $b['id'] <=> (int) $a['id'];
    });

    $ac = $kapat = [];
    $secilen = $korunan = 0;

    /*
     * KOTA KALICI (09.11.2026). Onceden kota her dagitimda bastan
     * siralaniyordu: haber eskidikce tazelik cezasiyla kotadan dusuyor,
     * dun Google'a sunulan sayfa bugun "noindex" olup site haritasindan
     * cikiyordu. Google bu sayfalari tarayip dizinden atiyordu (Search
     * Console: "noindex etiketi tarafindan haric tutuldu" 412, artan) ve
     * tarama butcesi gidip gelen sayfalara harcaniyordu. Artik bir kez
     * Google'a sunulan haber kotada kaliyor ve orana sayiliyor; yeni haber
     * yalnizca kota buyudukce (yeni derleme/ozgun haberle) giriyor.
     */
    foreach ($tekler as $t) {
        $koru = isset($korunanAd[$t['slug']]) || (int) $t['google_kota'] === 1;
        $sec  = $koru || $secilen < $kota;

        if ($sec) {
            $secilen++;
            $korunan += $koru ? 1 : 0;
        }

        if ($sec && (int) $t['google_kota'] !== 1) {
            $ac[] = (int) $t['id'];
        } elseif (!$sec && (int) $t['google_kota'] !== 0) {
            $kapat[] = (int) $t['id'];
        }
    }

    foreach ([1 => $ac, 0 => $kapat] as $deger => $idler) {
        foreach (array_chunk($idler, 500) as $parca) {
            db()->exec('UPDATE haberler SET google_kota = ' . $deger . ', guncellendi = guncellendi WHERE id IN (' . implode(',', $parca) . ')');
        }
    }

    $toplam = $ozgun + $secilen;
    $ozet = [
        'ozgun'   => $ozgun,
        'tek'     => count($tekler),
        'kota'    => $kota,
        'secilen' => $secilen,
        'korunan' => $korunan,
        'oran'    => $toplam > 0 ? round($ozgun * 100 / $toplam, 1) : 0.0,
    ];
    ayar_yaz('haber_kota_ozet', (string) json_encode($ozet + ['zaman' => time()]));

    return $ozet;
}

function haber_sutun_var(string $sutun): bool
{
    try {
        db()->query('SELECT ' . $sutun . ' FROM haberler LIMIT 0');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Bir haberin ilgili haberleri.
 *
 * Son 30 gunun haberleri puanlaniyor: once ortak etiket sayisi, sonra
 * ayni baslik (kategori), esitse yeniden eskiye. Kendisi ve ayni olayin
 * kopyalari (ayni baslik parmagi) disarida.
 *
 * @param array<string,mixed> $haber
 * @return list<array<string,mixed>>
 */
function haber_ilgililer(array $haber, int $adet = 4): array
{
    $etiketler = array_map(
        static fn (string $e): string => mb_strtolower($e, 'UTF-8'),
        etiketleri_coz((string) ($haber['etiketler'] ?? ''))
    );
    $kokler = haber_baslik_kokleri((string) ($haber['baslik'] ?? ''));

    try {
        $ifade = db()->prepare(
            'SELECT h.id, h.baslik, h.slug, h.ozet, h.etiketler, h.gorsel_url, h.yayin_tarihi, h.kategori_id,
                    h.iframe_url, h.kaynak_adi, h.kaynak_baslik, h.kaynak_url, k.ad AS kategori_adi, k.slug AS kategori_slug
               FROM haberler h
          LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = :d
                AND h.id <> :id
                AND h.yayin_tarihi >= NOW() - INTERVAL 14 DAY
                AND (h.baslik_parmak IS NULL OR CHAR_LENGTH(:bp) = 0 OR h.baslik_parmak <> :bp2)
              ORDER BY h.yayin_tarihi DESC
              LIMIT 500'
        );
        $bp = (string) ($haber['baslik_parmak'] ?? '');
        $ifade->execute(['d' => HABER_YAYINDA, 'id' => (int) $haber['id'], 'bp' => $bp, 'bp2' => $bp]);
        $adaylar = $ifade->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    /*
     * ALAKA ESIGI (08.11.2026, dis denetim raporu: motorin indirimi
     * haberinin "Bunu da okuyun"unda AKOM soguk hava haberi). Eskiden
     * puani en yuksek aday ne kadar alakasiz olursa olsun seciliyordu.
     * Simdi aday ya ortak etiket ya basliklarinda ortak iki kelime ya da
     * ayni baslik (kategori) + ortak bir kelime tasimali; yoksa hic
     * onerilmiyor. Baslik kelimeleri koklerinden (ilk 5 harf: "motorin",
     * "motorinde" ayni) karsilastiriliyor.
     */
    $puanli = [];

    foreach ($adaylar as $sira => $aday) {
        $ortak = count(array_intersect(
            $etiketler,
            array_map(static fn (string $e): string => mb_strtolower($e, 'UTF-8'), etiketleri_coz((string) $aday['etiketler']))
        ));
        $ortakKelime = count(array_intersect($kokler, haber_baslik_kokleri((string) $aday['baslik'])));
        $ayniBaslik  = (string) ($aday['kategori_id'] ?? '') === (string) ($haber['kategori_id'] ?? '');

        if ($ortak === 0 && $ortakKelime < 2 && !($ayniBaslik && $ortakKelime >= 1)) {
            continue;
        }

        // Ortak etiket ve kelime once, sonra ayni baslik; esitse daha yeni olan
        // (sira zaten yeniden eskiye).
        $puanli[] = ['puan' => $ortak * 1000 + $ortakKelime * 400 + ($ayniBaslik ? 150 : 0) - $sira * 0.5, 'haber' => $aday];
    }

    usort($puanli, static fn (array $a, array $b): int => $b['puan'] <=> $a['puan']);

    return array_slice(array_column($puanli, 'haber'), 0, $adet);
}

/**
 * Basligin anlamli kelimelerinin kokleri (ilk 5 harf; 4 harften kisa ve
 * cok genel kelimeler disarida). haber_ilgililer'in alaka olcusu.
 *
 * @return list<string>
 */
function haber_baslik_kokleri(string $baslik): array
{
    static $genel = [
        'yüzd', 'mily', 'türk', 'sonr', 'olar', 'için', 'açık', 'dedi', 'yeni', 'büyü', 'artt',
        'düşt', 'geld', 'oldu', 'olan', 'olac', 'kişi', 'gün', 'bugü', 'yarı', 'karş', 'hakk',
        'üzer', 'ilk', 'son', 'daha', 'kada', 'göre', 'sond', 'flaş', 'iddi', 'açıkl',
        'yüks', 'geri', 'başl', 'bell', 'etti', 'yapt', 'yapı', 'ilgi', 'nası', 'nede',
    ];

    // Turkce buyuk harf: "İ" -> "i", "I" -> "ı" (mb_strtolower "İ"yi noktali iki karaktere boluyor).
    $baslik = strtr($baslik, ['İ' => 'i', 'I' => 'ı']);
    $kelimeler = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($baslik, 'UTF-8')) ?: [];
    $kokler = [];

    foreach ($kelimeler as $k) {
        if (mb_strlen($k, 'UTF-8') < 4 || ctype_digit($k) || in_array($k, ['indi', 'çıktı', 'kaldı', 'verdi', 'aldı'], true)) {
            continue;
        }

        $kok = mb_substr($k, 0, 5, 'UTF-8');

        if (!in_array(mb_substr($kok, 0, 4, 'UTF-8'), $genel, true)) {
            $kokler[] = $kok;
        }
    }

    return array_values(array_unique($kokler));
}
