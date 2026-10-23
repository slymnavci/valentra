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
    // Yemek tarifleri (22.10.2026, site sahibinin istegi): menunun en
    // sonunda. Haber degil; ana sayfanin manset ve haber izgarasina
    // girmiyor (bkz. haber_ana_akis_kosulu), kendi sayfasi ve ana
    // sayfanin alttaki konu bloklarinda.
    'yemek-tarifleri',
];

/** Haber olmayan, ana sayfa akisina girmeyen basliklar. */
const HABER_AKIS_DISI = ['yemek-tarifleri'];

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
function haber_gun_ifadesi(bool $kategoriBilinir = true): string
{
    // Gece yarisindan beri gecen dakika PHP'den: veritabaninin saat dilimi
    // bilinmiyor (paylasimli sunucu), NOW() ile farki ise dilimden bagimsiz.
    $gunDakika = haber_bugun_dakika();
    $pencere   = $kategoriBilinir
        ? "CASE WHEN k.slug = 'spor' THEN " . max($gunDakika, 720) . ' ELSE ' . $gunDakika . ' END'
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
 * GUNCELLIK PUANI (site sahibinin istegi, 23.10.2026: "en son yayinlanan
 * habere ek puan ver; guncel olmasi aslinda en onemli madde; gun icinde
 * saat ve dakika da onemli").
 *
 * Onem puanina ekleniyor: yeni haber +50, saatte 5 puan azaliyor (dakika
 * dakika), 10 saatte sifir. Ajanin onem puani cogunlukla 25-75; bir saat
 * once gelen orta onemli haber (onem 50 + 45), sabah gelen cok onemli
 * haberin (onem 75 + 10) ustune cikabiliyor, ama gercekten buyuk bir
 * haber birkac saat ustte kaliyor.
 *
 * Puan sorgu aninda hesaplaniyor: her sayfa acilisinda eskiler dahil butun
 * haberler o anki saate gore yeniden siralaniyor; ayrica bir yeniden
 * puanlama isi gerekmiyor.
 */
const HABER_GUNCELLIK_TAVANI   = 50;
const HABER_GUNCELLIK_DAKIKASI = 12;   // 12 dakikada 1 puan = saatte 5

function haber_guncellik_ifadesi(): string
{
    return 'GREATEST(0, ' . HABER_GUNCELLIK_TAVANI . ' - '
        . haber_yas_dakika_ifadesi() . ' / ' . HABER_GUNCELLIK_DAKIKASI . ')';
}

function haber_sirasi(bool $anaSayfa = false): string
{
    $temel = haber_sirasi_temel($anaSayfa);

    if (!$anaSayfa) {
        return $temel;
    }

    // Spor kotasi (bkz. haber_ana_sayfa_ilk_set): secilen haberler ilk
    // sirada, kendi aralarinda yine temel sirayla.
    $ilk = haber_ana_sayfa_ilk_set();

    return $ilk === []
        ? $temel
        : '(h.id IN (' . implode(', ', $ilk) . ')) DESC, ' . $temel;
}

function haber_sirasi_temel(bool $anaSayfa): string
{
    $gun = haber_gun_ifadesi();

    /*
     * Ana sayfada puana okunma da giriyor (son 24 saatte haberi acan
     * farkli okuyucu: 1 okuyucu +10, 3 okuyucu +20, 7 ve uzeri +30;
     * haber_okunma_birlesimi, "zo"). Teknoloji ve kultur-sanat gunun
     * sonunda (site sahibinin karari, 22.10.2026: "teknoloji, sanat ve
     * kultur en sonda"). Konu sayfalarinda ikisi de yok: orada hepsi ayni
     * baslikta.
     */
    $guncellik = haber_guncellik_ifadesi();
    $puan = $anaSayfa
        ? '(k.slug IN (\'teknoloji\', \'kultur-sanat\')) ASC,
           h.onem + ' . $guncellik . ' + LEAST(30, 10 * LOG2(1 + COALESCE(zo.okuyucu, 0))) DESC'
        : 'h.onem + ' . $guncellik . ' DESC';

    return '(h.one_cikan = 1 AND ' . $gun . ' = 0) DESC,
        ' . $gun . ' ASC,
        ' . $puan . ',
        h.olusturuldu DESC,
        h.id DESC';
}

/*
 * ANA SAYFA SPOR KOTASI (site sahibinin istegi, 23.10.2026: "ana sayfada
 * spor haberi hic yok, onlari da 20 haber arasina sokalim; halen
 * Galatasaray haberi var, dun baska maclar vardi").
 *
 * Mansetin ilk 20 haberinde en az 3 spor haberi olmali. Eksikse bugunun
 * ya da dunun en onemli spor haberleri (daha eskisi degil: eski mac
 * haberi istenmiyor) alinip 20'nin en altindaki spor disi haberlerin
 * yerine konuyor. Cikan haberler kaybolmuyor, izgaranin basina iniyor.
 *
 * Sira SQL'de (h.id IN (...)) ile kuruluyor, PHP'de karistirilmiyor:
 * manset ve izgara ayni ORDER BY'i kullaniyor ve izgara mansetin
 * tukettigi kadar haberi atliyor; ikisi farkli sira gorurse haber ya
 * iki kez cikar ya hic cikmaz. Pencere islevi (ROW_NUMBER) kullanilmadi:
 * paylasimli sunucunun MySQL surumu bilinmiyor.
 */
const ANA_SAYFA_MANSET_ADEDI = 20;
const ANA_SAYFA_SPOR_KOTASI  = 3;

/**
 * Ana sayfanin ilk 20'si (spor kotasi uygulanmis); kota zaten
 * dolmussa ya da guncel spor haberi yoksa bos (sira degismiyor).
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

    $sporSayisi = count(array_filter($ilk, static fn (array $s): bool => $s['slug'] === 'spor'));
    $eksik      = ANA_SAYFA_SPOR_KOTASI - $sporSayisi;

    if ($eksik <= 0 || count($ilk) < ANA_SAYFA_MANSET_ADEDI) {
        return $onbellek = [];
    }

    $ilkIdler = array_map(static fn (array $s): int => (int) $s['id'], $ilk);

    $sporlar = db()->query(
        'SELECT h.id ' . $kaynak . ' WHERE ' . $kosul
        . " AND k.slug = 'spor'"
        . ' AND ' . haber_gun_ifadesi() . ' <= 1'
        . ' AND h.id NOT IN (' . implode(', ', $ilkIdler) . ')'
        . ' ORDER BY ' . $temel . ' LIMIT ' . $eksik
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($sporlar === []) {
        return $onbellek = [];
    }

    // En alttaki spor disi haberler yer acar.
    $cikacak = count($sporlar);

    for ($i = count($ilk) - 1; $i >= 0 && $cikacak > 0; $i--) {
        if ($ilk[$i]['slug'] !== 'spor') {
            unset($ilkIdler[$i]);
            $cikacak--;
        }
    }

    return $onbellek = array_values(array_merge(
        $ilkIdler,
        array_map('intval', $sporlar)
    ));
}

/**
 * Son 24 saatin okunma sayıları (farklı okuyucu), ana sayfa sırası için.
 *
 * Ziyaret tablosu yoksa ya da bos ise birlesim bos donuyor; sira yalnizca
 * onem puanina gore kurulur.
 */
function haber_okunma_birlesimi(): string
{
    return 'LEFT JOIN (SELECT haber_id, COUNT(DISTINCT ziyaretci) AS okuyucu
                         FROM ziyaretler
                        WHERE haber_id IS NOT NULL
                          AND zaman >= NOW() - INTERVAL 1 DAY
                        GROUP BY haber_id) zo ON zo.haber_id = h.id';
}

/**
 * Ana sayfa akisinin kosulu: Resmi Gazete kaynakli olmayan, haber olan
 * (yemek tarifi gibi akis disi bir baslikta olmayan), GUNUN haberi.
 */
function haber_ana_akis_kosulu(): string
{
    $disarida = implode(', ', array_map(
        static fn (string $slug): string => db()->quote($slug),
        HABER_AKIS_DISI
    ));

    /*
     * YALNIZCA GUNUN HABERLERI (site sahibinin karari, 23.10.2026: "ana
     * sayfada sadece gunluk haberler kalacak"). Onceki karar (22.10:
     * "eski haberler de akista kalsin") boylece kalkti; eski haberler
     * konu sayfalarinda ve kendi adreslerinde duruyor.
     *
     * "Gun" Istanbul gece yarisindan, ama en az son 12 saat: gece
     * yarisindan sonra ana sayfa bosalmasin, aksam haberleri sabaha
     * kadar dursun. Yas, kaynagin yayin ani ile toplanma aninin erken
     * olani (haber_yas_dakika_ifadesi).
     */
    $pencere = max(haber_bugun_dakika(), 720);

    return 'NOT (' . HABER_RG_KOSULU . ')'
        . ' AND (h.kategori_id IS NULL OR h.kategori_id NOT IN'
        . ' (SELECT id FROM kategoriler WHERE slug IN (' . $disarida . ')))'
        . ' AND ' . haber_yas_dakika_ifadesi() . ' <= ' . $pencere;
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
             baslik_parmak, kaynak_baslik, kaynak_baslik_parmak, iframe_url, guven_skoru, onem, ajan_notu,
             analiz_degisen, analiz_etkilenen, analiz_zaman, analiz_islem,
             isletme_etkisi, uygulama_ornegi, resmi_dayanak, kaynak_tarihi)
         VALUES
            (:baslik, :slug, :ozet, :icerik, :gorsel_url, :etiketler, :durum, :kategori_id,
             :kaynak_id, :kaynak_adi, :kaynak_url, :kaynak_parmak, :url_parmak,
             :baslik_parmak, :kaynak_baslik, :kaynak_baslik_parmak, :iframe_url, :guven_skoru, :onem, :ajan_notu,
             :analiz_degisen, :analiz_etkilenen, :analiz_zaman, :analiz_islem,
             :isletme_etkisi, :uygulama_ornegi, :resmi_dayanak,
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
    $kaynakVar     = $kaynakGecikme >= 0 && $kaynakGecikme <= 30 * 1440;

    $ifade->execute([
        'baslik'        => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'          => haber_benzersiz_slug($baslik),
        'ozet'          => mb_substr($ozet, 0, 600, 'UTF-8'),
        'icerik'        => $icerik,
        'gorsel_url'    => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: null,
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

                if ($otomatik) {
                    haber_otomatik_yayinla((int) $sonuc['id']);
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

    return ['eklenen' => $eklenen, 'yinelenen' => $yinelenen, 'hatalar' => $hatalar];
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
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
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
function haber_yayindakiler(int $limit = 20, int $atla = 0, bool $anaAkis = false): array
{
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

    return (int) $ifade->fetchColumn();
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

function haber_otomatik_yayin_acik(): bool
{
    require_once __DIR__ . '/ayarlar.php';

    return ayar_oku(HABER_OTOMATIK_YAYIN_AYARI, '1') === '1';
}

/**
 * Taslagi yonetici olmadan yayina alir (onaylayan bos kalir).
 *
 * Yalnizca guven puani HABER_OTOMATIK_GUVEN_ESIGI ve ustundeyse; degilse
 * taslakta kalir (false) ve onay bekler.
 */
function haber_otomatik_yayinla(int $id): bool
{
    $ifade = db()->prepare(
        'UPDATE haberler
            SET durum = :yayinda,
                onay_tarihi = NOW(),
                yayin_tarihi = COALESCE(yayin_tarihi, NOW())
          WHERE id = :id AND durum = :taslak AND guven_skoru >= :esik'
    );
    $ifade->execute(['yayinda' => HABER_YAYINDA, 'taslak' => HABER_TASLAK, 'id' => $id,
                     'esik' => HABER_OTOMATIK_GUVEN_ESIGI]);

    if ($ifade->rowCount() === 0) {
        return false;
    }

    require_once __DIR__ . '/arama_bildirim.php';
    arama_bildir_kayit('haber', $id);

    return true;
}

/**
 * Bugun (Istanbul gece yarisindan beri) toplanip taslakta kalan haberleri
 * yayina alir; kac haber alindigini dondurur. Otomatik yayin kapaliysa
 * hicbir sey yapmaz. Reddedilen haberlere dokunmaz.
 */
function haber_bugunku_taslaklari_yayinla(): int
{
    if (!haber_otomatik_yayin_acik()) {
        return 0;
    }

    $ifade = db()->prepare(
        'SELECT id FROM haberler
          WHERE durum = :taslak
            AND guven_skoru >= :esik
            AND olusturuldu >= NOW() - INTERVAL ' . haber_bugun_dakika() . ' MINUTE
          ORDER BY id'
    );
    $ifade->execute(['taslak' => HABER_TASLAK, 'esik' => HABER_OTOMATIK_GUVEN_ESIGI]);

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
                resmi_dayanak = :resmi_dayanak
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
 * @return array{isletme_etkisi:string,uygulama_ornegi:?string,resmi_dayanak:string}
 */
function haber_format_alanlari(array $veri): array
{
    $ornek = trim(str_replace(["\r\n", "\r"], "\n", (string) ($veri['uygulama_ornegi'] ?? '')));

    return [
        'isletme_etkisi'  => mb_substr(trim((string) ($veri['isletme_etkisi'] ?? '')), 0, 800, 'UTF-8'),
        'uygulama_ornegi' => $ornek !== '' ? mb_substr($ornek, 0, 6000, 'UTF-8') : null,
        'resmi_dayanak'   => mb_substr(trim((string) ($veri['resmi_dayanak'] ?? '')), 0, 400, 'UTF-8'),
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
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
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
