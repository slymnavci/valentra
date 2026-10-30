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
 * kaydirakta spor kotasi var (son dakika yalnizca kaynakta bugun
 * yayimlanmis haberlerden).
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
 * Kaynak haberi "son dakika" diye isaretlediyse (kaynagin basligi ya da
 * adresi) +30; saatte 5 azaliyor, 6 saatte sifir. Son
 * dakika haberi taze oldugu surece degerli; aksama kadar ustte kalmamali.
 * Ajan basligi yeniden yaziyor, bu yuzden kaynagin ozgun basligina
 * (kaynak_baslik) da bakiliyor. Sutunlar utf8mb4_unicode_ci: LIKE buyuk
 * kucuk harf ve "SON DAKİKA" yazimini da yakaliyor.
 */
const HABER_SON_DAKIKA_TAVANI = 30;

function haber_son_dakika_ifadesi(): string
{
    return "CASE WHEN h.kaynak_baslik LIKE '%son dakika%' OR h.baslik LIKE '%son dakika%'
                   OR h.kaynak_url LIKE '%son-dakika%' OR h.kaynak_url LIKE '%sondakika%'
              THEN GREATEST(0, " . HABER_SON_DAKIKA_TAVANI . ' - '
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
const ANA_SAYFA_SPOR_ONDE_ESIGI = 88;

function haber_onde_spor_kosulu(): string
{
    return "(k.slug = 'spor' AND h.onem >= " . ANA_SAYFA_SPOR_ONDE_ESIGI . ')';
}

function haber_arkadaki_spor_ifadesi(): string
{
    return "(k.slug = 'spor' AND h.onem < " . ANA_SAYFA_SPOR_ONDE_ESIGI . ')';
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
 * Üstteki kayan "Son Dakika" şeridi: kaynaktaki yayın anına göre en yeni haberler.
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
              LIMIT ' . max(1, min(30, $adet))
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] son dakika seridi: ' . $e->getMessage());
        $satirlar = [];
    }

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
    $onu = $sonDakika !== null ? '(h.id = ' . $sonDakika . ') DESC, ' : '';

    /*
     * Sonra spor kotasi (bkz. haber_ana_sayfa_ilk_set), sonra puan sirasi.
     * "En yeni 5 haber 4-8. slaytta" ve "gunun en onemli 3 haberi" kurallari
     * kaldirildi (site sahibi, 23.10.2026: "once puanlamayi yap, ondan
     * sonra sirayla ana sayfaya gelsin"): tazelik artik puanin icinde.
     */
    $ilk    = haber_ana_sayfa_ilk_set();
    $ilkSet = $ilk !== [] ? '(h.id IN (' . implode(', ', $ilk) . ')) DESC, ' : '';

    // Ilk haber spor olmasin (canli mac yoksa); bkz. haber_ana_sayfa_basi_id.
    $basi = $sonDakika === null ? haber_ana_sayfa_basi_id($ilkSet . $temel) : null;

    return $onu
        . ($basi !== null ? '(h.id = ' . $basi . ') DESC, ' : '')
        . $ilkSet
        . $temel;
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
    return '(h.one_cikan = 1 AND ' . $gun . ' = 0) DESC,
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
 * yapalim"): son dakika yuvasi (haber_son_dakika_id) ve kaydirakta en az 3
 * spor kotasi (haber_ana_sayfa_ilk_set) yalnizca onemi 60 ve ustu
 * haberlerden. Esigi gecen spor haberi yoksa kota bos kalir.
 */
const ANA_SAYFA_ONEM_ESIGI = 60;

function haber_dalga_ifadesi(): string
{
    return 'FLOOR(GREATEST(0, TIMESTAMPDIFF(MINUTE, h.olusturuldu, NOW())) / ' . ANA_SAYFA_DALGA_DAKIKA . ')';
}

/*
 * ANA SAYFA SPOR KOTASI — kaydiragin 20 haberinde en az 1 spor haberi
 * (site sahibinin istegi, 23.10.2026: "ana sayfada spor haberi hic yok,
 * onlari da 20 haber arasina sokalim"): bugunun ya da dunun en
 * onemlileri.
 *
 * Yer, 20'nin en altindaki spor olmayan haberlerden aciliyor; cikanlar
 * kaybolmuyor, izgaranin basina iniyor. Sira SQL'de (h.id IN (...)) ile
 * kuruluyor, PHP'de karistirilmiyor: manset ve izgara ayni ORDER BY'i
 * kullaniyor ve izgara mansetin tukettigi kadar haberi atliyor; ikisi
 * farkli sira gorurse haber ya iki kez cikar ya hic cikmaz. Pencere
 * islevi (ROW_NUMBER) kullanilmadi: paylasimli sunucunun MySQL surumu
 * bilinmiyor.
 */
const ANA_SAYFA_MANSET_ADEDI = 20;

// 3'ten 1'e (site sahibi, 30.10.2026: "futbol, basketbol ve voleybol cok
// arkada"): kaydirakta en altta tek spor haberi; Turkiye'nin buyuk basarisi
// zaten gundem haberleriyle yarisiyor (bkz. ANA_SAYFA_SPOR_ONDE_ESIGI).
const ANA_SAYFA_SPOR_KOTASI  = 1;

/**
 * Ana sayfanin ilk 20'si (kotalar uygulanmis); kotalar zaten doluysa bos
 * (sira degismiyor).
 *
 * @return list<int>
 */
function haber_ana_sayfa_ilk_set(): array
{
    static $onbellek = null;

    if ($onbellek !== null) {
        return $onbellek;
    }

    $temel = haber_sirasi_temel(true);
    $kosul = 'h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . haber_ana_akis_kosulu();
    $kaynak = 'FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
           ' . haber_okunma_birlesimi();

    $ilk = db()->query(
        'SELECT h.id, k.slug ' . $kaynak . ' WHERE ' . $kosul
        . ' ORDER BY ' . $temel . ' LIMIT ' . ANA_SAYFA_MANSET_ADEDI
    )->fetchAll();

    if (count($ilk) < ANA_SAYFA_MANSET_ADEDI) {
        return $onbellek = [];
    }

    $ilkIdler = array_map(static fn (array $s): int => (int) $s['id'], $ilk);

    // Spor kotasi: 20'de kac spor var?
    $sporSayisi = count(array_filter($ilk, static fn (array $s): bool => $s['slug'] === 'spor'));
    $eksikSpor  = ANA_SAYFA_SPOR_KOTASI - $sporSayisi;

    if ($eksikSpor <= 0) {
        return $onbellek = [];
    }

    $yeniSpor = array_map('intval', db()->query(
        'SELECT h.id ' . $kaynak . ' WHERE ' . $kosul
        . " AND k.slug = 'spor'"
        // Kotaya da yalnizca onemli spor haberi (ANA_SAYFA_ONEM_ESIGI).
        . ' AND h.onem >= ' . ANA_SAYFA_ONEM_ESIGI
        . ' AND ' . haber_gun_ifadesi() . ' <= 1'
        . ' AND h.id NOT IN (' . implode(', ', $ilkIdler) . ')'
        . ' ORDER BY ' . $temel . ' LIMIT ' . $eksikSpor
    )->fetchAll(PDO::FETCH_COLUMN));

    if ($yeniSpor === []) {
        return $onbellek = [];
    }

    // En alttan, spor olmayan haberlerden yer ac.
    $cikacak = count($yeniSpor);

    for ($i = count($ilk) - 1; $i >= 0 && $cikacak > 0; $i--) {
        if ($ilk[$i]['slug'] !== 'spor') {
            unset($ilkIdler[$i]);
            $cikacak--;
        }
    }

    return $onbellek = array_values(array_merge($ilkIdler, $yeniSpor));
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
function haber_ana_akis_kosulu(): string
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
        . ' AND h.kaynak_tarihi IS NOT NULL'
        . ' AND h.olusturuldu >= NOW() - INTERVAL ' . HABER_ANA_SAYFA_EN_FAZLA_GUN . ' DAY';
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
        'UPDATE haberler SET url_parmak = :u, baslik_parmak = :b WHERE id = :id'
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
                    CASE WHEN k.slug IN (" . $liste . ") THEN k.slug ELSE 'gundem' END, '.jpg')
          WHERE COALESCE(h.gorsel_url, '') = ''"
    );
    $ifade->execute(['taban' => site_adresi()]);

    return $ifade->rowCount();
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

    $ifade = db()->prepare(
        'INSERT INTO haberler
            (baslik, slug, ozet, icerik, gorsel_url, etiketler, durum, kategori_id,
             kaynak_id, kaynak_adi, kaynak_url, kaynak_parmak, url_parmak,
             baslik_parmak, kaynak_baslik, kaynak_baslik_parmak, iframe_url, guven_skoru, onem, ajan_notu, ajan_modeli,
             analiz_degisen, analiz_etkilenen, analiz_zaman, analiz_islem,
             isletme_etkisi, uygulama_ornegi, resmi_dayanak, neden_onemli, kaynak_tarihi)
         VALUES
            (:baslik, :slug, :ozet, :icerik, :gorsel_url, :etiketler, :durum, :kategori_id,
             :kaynak_id, :kaynak_adi, :kaynak_url, :kaynak_parmak, :url_parmak,
             :baslik_parmak, :kaynak_baslik, :kaynak_baslik_parmak, :iframe_url, :guven_skoru, :onem, :ajan_notu, :ajan_modeli,
             :analiz_degisen, :analiz_etkilenen, :analiz_zaman, :analiz_islem,
             :isletme_etkisi, :uygulama_ornegi, :resmi_dayanak, :neden_onemli,
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
        'kaynak_var'       => $kaynakVar ? 1 : 0,
        'kaynak_gecikme'   => $kaynakVar ? $kaynakGecikme : 0,
    ] + haber_format_alanlari($veri));

    return ['durum' => 'eklendi', 'id' => (int) db()->lastInsertId()];
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

                if ($otomatik && haber_otomatik_yayinla((int) $sonuc['id'])) {
                    $yayinlanan++;
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

    return ['eklenen' => $eklenen, 'yinelenen' => $yinelenen, 'yayinlanan' => $yayinlanan, 'hatalar' => $hatalar];
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
                yayin_tarihi = COALESCE(yayin_tarihi, NOW())
          WHERE id = :id'
    );
    $ifade->execute(['durum' => HABER_YAYINDA, 'yonetici' => $yoneticiId, 'id' => $id]);

    if ($ifade->rowCount() > 0) {
        require_once __DIR__ . '/arama_bildirim.php';
        arama_bildir_kayit('haber', $id);

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
function haber_otomatik_yayinla(int $id): bool
{
    $ifade = db()->prepare(
        'UPDATE haberler
            SET durum = :yayinda,
                onay_tarihi = NOW(),
                yayin_tarihi = COALESCE(yayin_tarihi, olusturuldu, NOW())
          WHERE id = :id AND durum = :taslak AND ' . haber_guven_esigi_kosulu()
    );
    $ifade->execute(['yayinda' => HABER_YAYINDA, 'taslak' => HABER_TASLAK, 'id' => $id]);

    if ($ifade->rowCount() === 0) {
        return false;
    }

    require_once __DIR__ . '/arama_bildirim.php';
    arama_bildir_kayit('haber', $id);

    return true;
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

    $ifade = db()->prepare(
        'SELECT id FROM haberler
          WHERE durum = :taslak
            AND ' . haber_guven_esigi_kosulu() . '
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
}

/**
 * Yayindan geri cekme veya reddetme.
 */
function haber_durum_degistir(int $id, string $durum): bool
{
    if (!in_array($durum, [HABER_TASLAK, HABER_YAYINDA, HABER_REDDEDILDI], true)) {
        throw new InvalidArgumentException('Gecersiz durum.');
    }

    $ifade = db()->prepare('UPDATE haberler SET durum = :durum WHERE id = :id');
    $ifade->execute(['durum' => $durum, 'id' => $id]);

    if ($ifade->rowCount() > 0 && $durum === HABER_YAYINDA) {
        require_once __DIR__ . '/arama_bildirim.php';
        arama_bildir_kayit('haber', $id);
    }

    return $ifade->rowCount() > 0;
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
    db()->prepare('UPDATE haberler SET one_cikan = :o WHERE id = :id')
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
          WHERE h.durum = :durum AND h.kategori_id = :kategori
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
        'SELECT COUNT(*) FROM haberler WHERE durum = :durum AND kategori_id = :kategori'
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
                h.kaynak_adi, k.ad AS kategori_adi, k.slug AS kategori_slug, k.sira
           FROM haberler h
           JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . '
            AND k.aktif = 1
            AND NOT (' . HABER_RG_KOSULU . ')
            AND ' . haber_akis_kategori_kosulu() . '
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

    if (HABER_YALNIZCA_OZGUN_DIZINDE) {
        $kosul .= ' AND (' . ($derleme ? $p . 'derleme = 1 OR ' : '') . $p . "kaynak_url = '' OR " . $p . "ajan_modeli LIKE 'valentra-%')";
    }

    return $kosul;
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

    try {
        $ifade = db()->prepare(
            'SELECT h.id, h.baslik, h.slug, h.ozet, h.etiketler, h.gorsel_url, h.yayin_tarihi, h.kategori_id,
                    h.iframe_url, h.kaynak_adi, k.ad AS kategori_adi, k.slug AS kategori_slug
               FROM haberler h
          LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = :d
                AND h.id <> :id
                AND h.yayin_tarihi >= NOW() - INTERVAL 30 DAY
                AND (h.baslik_parmak IS NULL OR CHAR_LENGTH(:bp) = 0 OR h.baslik_parmak <> :bp2)
              ORDER BY h.yayin_tarihi DESC
              LIMIT 200'
        );
        $bp = (string) ($haber['baslik_parmak'] ?? '');
        $ifade->execute(['d' => HABER_YAYINDA, 'id' => (int) $haber['id'], 'bp' => $bp, 'bp2' => $bp]);
        $adaylar = $ifade->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $puanli = [];

    foreach ($adaylar as $sira => $aday) {
        $ortak = array_intersect(
            $etiketler,
            array_map(static fn (string $e): string => mb_strtolower($e, 'UTF-8'), etiketleri_coz((string) $aday['etiketler']))
        );
        $ayniBaslik = (string) ($aday['kategori_id'] ?? '') === (string) ($haber['kategori_id'] ?? '');
        // Ortak etiket once, sonra ayni baslik; esitse daha yeni olan
        // (sira zaten yeniden eskiye).
        $puanli[] = ['puan' => count($ortak) * 1000 + ($ayniBaslik ? 500 : 0) - $sira, 'haber' => $aday];
    }

    usort($puanli, static fn (array $a, array $b): int => $b['puan'] <=> $a['puan']);

    return array_slice(array_column($puanli, 'haber'), 0, $adet);
}
