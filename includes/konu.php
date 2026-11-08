<?php
declare(strict_types=1);

/**
 * Kalıcı konu sayfaları ("dosyalar").
 *
 * Site sahibinin istegi (03.11.2026, "baska neler yapabiliriz" onerisi):
 * Turkiye'de her ay yeniden aranan konular ("asgari ucret 2027", "emekli
 * zammi", "memur maasi", "faiz karari") icin tek, surekli guncellenen bir
 * sayfa. Google bu tur sayfalari tek tek haberlerden daha uzun sure ust
 * siralarda tutuyor.
 *
 * Sayfa yalnizca sitenin kendi haberlerinden kuruluyor (yeni bilgi
 * uydurulmuyor): en son gelisme ozetiyle ustte, altinda zaman sirasiyla
 * butun gelismeler. Konuya giren haberin sayfasinda dosyaya baglanti var
 * (ic baglanti). Haber yoksa ya da az ise sayfa Google'a sunulmuyor.
 *
 *   /konu/asgari-ucret       konu sayfasi
 *   /konular                 butun dosyalar
 */

require_once __DIR__ . '/haberler.php';

/** Bu kadar haberi olmayan konu sayfası Google'a sunulmaz (noindex). */
const KONU_DIZIN_ASGARI = 3;

/** Konu sayfasındaki en fazla gelişme. */
const KONU_HABER_TAVANI = 40;

/** Konuya giren haberlerin en fazla yaşı (gün). */
const KONU_GUN = 400;

/*
 * Konular. 'yil': basliga yil yaziliyor (Ekim'den itibaren gelecek yil:
 * o aylarda gelecek yilin rakami araniyor). 'kelimeler': basliginda ya da
 * etiketlerinde bunlardan biri gecen haber konuya giriyor. 'giris':
 * yalnizca degismeyen, genel bilgi (rakam ve tarih yok).
 */
const KONULAR = [
    'asgari-ucret' => [
        'ad'        => 'Asgari Ücret',
        'yil'       => true,
        'kelimeler' => ['asgari ücret'],
        'giris'     => 'Asgari ücret; işçi, işveren ve hükümet temsilcilerinden oluşan Asgari Ücret Tespit Komisyonu\'nda belirlenir. '
            . 'Bu sayfada yeni asgari ücretle ilgili açıklamalar, komisyon toplantıları ve kesinleşen rakamlar zaman sırasıyla yer alıyor.',
    ],
    'emekli-zammi' => [
        'ad'        => 'Emekli Zammı',
        'yil'       => true,
        'kelimeler' => ['emekli zammı', 'emekli maaş', 'emekli aylığ', 'en düşük emekli', 'emekli ikramiye', 'emekli bayram ikramiyesi'],
        'giris'     => 'SSK ve Bağ-Kur emeklilerinin aylıkları yılda iki kez, ocak ve temmuz aylarında, açıklanan enflasyon verisine göre artırılır. '
            . 'Bu sayfada emekli zammı, en düşük emekli aylığı ve ikramiyelerle ilgili gelişmeler bir arada.',
    ],
    'memur-maasi' => [
        'ad'        => 'Memur Maaşı ve Zammı',
        'yil'       => true,
        'kelimeler' => ['memur maaş', 'memur zammı', 'memur ve memur emekli', 'toplu sözleşme', 'memur emeklisi'],
        'giris'     => 'Memur ve memur emeklilerinin maaş artışları toplu sözleşme oranı ile enflasyon farkından oluşur. '
            . 'Bu sayfada memur maaşları, toplu sözleşme ve enflasyon farkıyla ilgili gelişmeler yer alıyor.',
    ],
    'faiz-karari' => [
        'ad'        => 'Merkez Bankası Faiz Kararı',
        'yil'       => false,
        'kelimeler' => ['faiz karar', 'politika faizi', 'Para Politikası Kurulu', 'PPK'],
        'giris'     => 'Türkiye Cumhuriyet Merkez Bankası Para Politikası Kurulu, politika faizini yılda belirli toplantılarda belirler. '
            . 'Bu sayfada faiz kararları, piyasa beklentileri ve kararların ardından yapılan açıklamalar zaman sırasıyla yer alıyor.',
    ],
    'enflasyon' => [
        'ad'        => 'Enflasyon Rakamları',
        'yil'       => false,
        'kelimeler' => ['enflasyon', 'TÜFE', 'ÜFE'],
        'giris'     => 'TÜİK, tüketici ve üretici fiyat endekslerini her ayın ilk iş günlerinde açıklar. Enflasyon; emekli ve memur zamlarından '
            . 'kira artış sınırına kadar pek çok hesabın temelidir. Bu sayfada açıklanan veriler ve beklentiler bir arada.',
    ],
    'akaryakit' => [
        'ad'        => 'Benzin ve Motorin Fiyatları',
        'yil'       => false,
        'kelimeler' => ['benzin', 'motorin', 'akaryakıt', 'LPG', 'mazot'],
        'giris'     => 'Benzin, motorin ve LPG fiyatları petrol fiyatları, döviz kuru ve vergilere göre değişir; zam ve indirimler pompaya genellikle gece yansır. '
            . 'Bu sayfada akaryakıt zam ve indirimleri zaman sırasıyla yer alıyor.',
    ],
    'kira-artisi' => [
        'ad'        => 'Kira Artış Oranı',
        'yil'       => false,
        // "kiracı" / "ev sahibi" CIKARILDI (08.11.2026): kiraci ailenin
        // evindeki yangin haberi dosyaya giriyordu. Yalnizca kira artisi,
        // sozlesme ve tespit davasi gibi dosyanin asil konusu.
        'kelimeler' => ['kira artış', 'kira zam', 'kira tavan', 'kira sınır', 'kira oranı', 'kira sözleşme',
                        'kira bedel', 'kira tespit', 'kira yardım', 'kira desteği'],
        'giris'     => 'Konut kiralarında yıllık artış, Türk Borçlar Kanunu\'na göre bir önceki kira yılının tüketici fiyat endeksindeki on iki aylık ortalama değişimle sınırlıdır. '
            . 'Bu sayfada kira artış oranı ve kiracı-ev sahibi ilişkisiyle ilgili gelişmeler yer alıyor.',
    ],
    'sinavlar' => [
        'ad'        => 'Sınavlar: YKS, KPSS, ALES, LGS',
        'yil'       => true,
        'kelimeler' => ['YKS', 'KPSS', 'ALES', 'LGS', 'ÖSYM', 'DGS', 'YDS'],
        'giris'     => 'ÖSYM ve Milli Eğitim Bakanlığı sınavlarının başvuru, sınav ve sonuç tarihleri her yıl açıklanan takvime göre belirlenir. '
            . 'Bu sayfada sınav takvimi, başvurular ve sonuçlarla ilgili duyurular bir arada.',
    ],
    'okul-tatil' => [
        'ad'        => 'Okul Tatilleri ve Ara Tatil',
        'yil'       => false,
        'kelimeler' => ['ara tatil', 'yarıyıl tatil', 'sömestr', 'okullar tatil', 'karne', 'eğitim öğretim yılı'],
        'giris'     => 'Okulların açılış, ara tatil ve yarıyıl tatili tarihleri Milli Eğitim Bakanlığı\'nın çalışma takvimiyle belirlenir; '
            . 'kar ve olağanüstü durumlarda valilikler ayrıca tatil kararı alabilir. Bu sayfada tatil kararları ve takvim duyuruları yer alıyor.',
    ],
];

/*
 * Basliginda bunlardan biri gecen haber hicbir dosyaya girmiyor: kelime
 * gecse de (kiraci, emekli, benzin istasyonu) adli olay haberi dosyanin
 * konusu degil. Kisa kokler bilincli: "öldür" -> öldürdü/öldürüldü.
 * "kaza" YOK: "kazanç", "kazandı" da eslesirdi; "kazası"/"kazada" var.
 *
 * Genisletildi (08.11.2026, okur denetimi): enflasyon dosyasinda
 * "pompali tüfekle saldiri", akaryakit dosyasinda "benzin bombali
 * protesto" vardi. Kelimeler kelime BASINDA araniyor (bkz.
 * konu_kelime_gecer): "silah" silahlı/silahla, "saldır" saldırı/saldırgan.
 */
const KONU_HARIC = [
    'yangın', 'cinayet', 'öldür', 'bıçak', 'ceset', 'cesed', 'silah', 'kazası', 'kazada',
    'yaralandı', 'yaralı', 'hayatını kaybet', 'gözaltı', 'tutuklan', 'dolandırıc', 'gasp', 'hırsız',
    'tüfek', 'tabanca', 'saldır', 'bomba', 'molotof', 'kurşun', 'vurul', 'rehin', 'darp', 'patlama',
    'kundak', 'terör', 'katliam', 'cinnet',
];

/*
 * KONU_HARIC kelime basinda aradigi icin bunlari da yakaliyordu; haric
 * denetiminden once basliktan siliniyor. "kurşun" -> "kurşunsuz benzin"
 * akaryakit dosyasinin asil haberi.
 */
const KONU_HARIC_ISTISNA = ['kurşunsuz'];

/*
 * Bu kategorilerin haberi dosyaya girmiyor (08.11.2026). Dosyalarin hepsi
 * Turkiye'deki bir rakami ya da takvimi izliyor; Glasgow'daki bir
 * restoranin kira davasi (dunya) ya da bir bilgisayar oyununun
 * ertelenmesi (teknoloji) kelime gecse de konunun haberi degil.
 * Olmayan slug zararsiz.
 */
const KONU_KATEGORI_HARIC = ['dunya', 'spor', 'teknoloji', 'kultur-sanat', 'yemek-tarifleri', 'magazin', 'oyun'];

/** SQL ön süzgeci bir seferde bu kadar aday okur; PHP süzgeci elemeyi yapar. */
const KONU_ADAY_PARCA = 200;

/** Bir dosya için en fazla taranacak aday (konu_ozetleri sayımı da bununla sınırlı). */
const KONU_ADAY_TAVANI = 2000;

/** Konu sayfasının adresi; boş slug: bütün dosyalar. */
function konu_yolu(string $slug = ''): string
{
    if ($slug === '') {
        return temiz_adres_acik() ? '/konular' : '/konu.php';
    }

    return temiz_adres_acik() ? '/konu/' . rawurlencode($slug) : '/konu.php?k=' . rawurlencode($slug);
}

/** Başlıktaki yıl: ekimden itibaren gelecek yıl. */
function konu_yili(): int
{
    $tarih = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

    return (int) $tarih->format('Y') + ((int) $tarih->format('n') >= 10 ? 1 : 0);
}

/** "Asgari Ücret 2027" */
function konu_basligi(string $slug): string
{
    $k = KONULAR[$slug];

    return $k['ad'] . ($k['yil'] ? ' ' . konu_yili() : '');
}

/** Türkçe küçük harf (I -> ı, İ -> i). */
function konu_kucult(string $metin): string
{
    return mb_strtolower(strtr($metin, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
}

/**
 * Kelime metinde KELIME OLARAK geçiyor mu?
 *
 * Eskiden SQL'de "LIKE '%TÜFE%'" ile araniyordu. Veritabaninin
 * karsilastirmasi (utf8mb4_unicode_ci) buyuk/kucuk harf ve aksan
 * ayirmadigi icin "TÜFE" -> "tüfek", "ÜFE" -> "müfettiş", "ALES" ->
 * "Wales" eslesiyordu. Simdi:
 *   - her kelime bir kelimenin BASINDA aranir (Turkce ekler serbest:
 *     "enflasyon" -> "enflasyonu", "kira artış" -> "kira artışı");
 *   - kisaltmalar (TÜFE, ÜFE, PPK, YKS, LPG) TAM kelime olmali;
 *     kesme isaretli ek serbest ("TÜFE'deki").
 */
function konu_kelime_gecer(string $kucukMetin, string $kelime): bool
{
    $kisaltma = mb_strlen($kelime, 'UTF-8') <= 5 && mb_strtoupper($kelime, 'UTF-8') === $kelime;
    $desen    = '/(?<![\p{L}\p{N}])' . preg_quote(konu_kucult($kelime), '/') . ($kisaltma ? '(?![\p{L}\p{N}])' : '') . '/u';

    return preg_match($desen, $kucukMetin) === 1;
}

/** Metinde dosyanın kelimelerinden biri geçiyor mu? */
function konu_kelimesi_var(string $slug, string $metin): bool
{
    $kucuk = konu_kucult($metin);

    foreach (KONULAR[$slug]['kelimeler'] as $kelime) {
        if (konu_kelime_gecer($kucuk, $kelime)) {
            return true;
        }
    }

    return false;
}

/** Başlık adli olay haberi mi (dosyaya girmez)? */
function konu_haric_mi(string $baslik): bool
{
    $kucuk = konu_kucult($baslik);

    foreach (KONU_HARIC_ISTISNA as $istisna) {
        $kucuk = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($istisna, '/') . '\p{L}*/u', ' ', $kucuk);
    }

    foreach (KONU_HARIC as $kelime) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kelime, '/') . '/u', $kucuk) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Editörün dosyadan çıkardığı haberler (panel → Gündem dosyaları).
 *
 * @return array<int,true>
 */
function konu_editor_haric(string $slug): array
{
    static $onbellek = [];

    if (!isset($onbellek[$slug])) {
        $onbellek[$slug] = [];

        foreach (explode(',', ayar_oku('konu_haric_' . $slug)) as $id) {
            if ((int) $id > 0) {
                $onbellek[$slug][(int) $id] = true;
            }
        }
    }

    return $onbellek[$slug];
}

/** Editörün dosyanın başına seçtiği haber; yoksa 0 (otomatik). */
function konu_editor_secimi(string $slug): int
{
    return max(0, (int) ayar_oku('konu_one_' . $slug));
}

/**
 * Haber bu dosyaya giriyor mu? Kelimenin geçmesi yetmiyor; hepsi birlikte:
 *
 *   1. başlık adli olay haberi değil (KONU_HARIC);
 *   2. kategori dosyanın alanında (KONU_KATEGORI_HARIC: dünya, spor,
 *      teknoloji ...);
 *   3. editör haberi bu dosyadan çıkarmamış;
 *   4. haberin ANA KONUSU dosya: kelime başlıkta geçiyor, ya da hem
 *      spotta hem etiketlerde geçiyor. Yalnızca etikette ya da yalnızca
 *      metnin bir köşesinde geçen kelime yetmiyor.
 *
 * @param array<string,mixed> $haber baslik, ozet, etiketler, kategori_slug, id
 */
function konu_eslesir(string $slug, array $haber, bool $editorHaric = true): bool
{
    if (!isset(KONULAR[$slug])) {
        return false;
    }

    $baslik = (string) ($haber['baslik'] ?? '');

    if (konu_haric_mi($baslik)) {
        return false;
    }

    if (in_array((string) ($haber['kategori_slug'] ?? ''), KONU_KATEGORI_HARIC, true)) {
        return false;
    }

    if ($editorHaric && isset(konu_editor_haric($slug)[(int) ($haber['id'] ?? 0)])) {
        return false;
    }

    if (konu_kelimesi_var($slug, $baslik)) {
        return true;
    }

    return konu_kelimesi_var($slug, (string) ($haber['ozet'] ?? ''))
        && konu_kelimesi_var($slug, (string) ($haber['etiketler'] ?? ''));
}

/**
 * Geniş SQL ön süzgeci: başlıkta ya da etiketlerde kelimelerden biri
 * GEÇEBİLİR. Kesin karar konu_eslesir'de; LIKE yalnızca adayları daraltıyor.
 *
 * @return array{0:string,1:array<string,string>}
 */
function konu_kosulu(string $slug, string $takma = 'h'): array
{
    $parca = [];
    $deger = [];

    foreach (KONULAR[$slug]['kelimeler'] as $i => $kelime) {
        $parca[] = "({$takma}.baslik LIKE :k{$i} OR {$takma}.etiketler LIKE :k{$i}e)";
        $deger['k' . $i]       = '%' . $kelime . '%';
        $deger['k' . $i . 'e'] = '%' . $kelime . '%';
    }

    return ['(' . implode(' OR ', $parca) . ')', $deger];
}

/**
 * Dosyaya giren haberler, yeniden eskiye. Ön süzgeçten parça parça
 * okunur, konu_eslesir'den geçenler toplanır.
 *
 * @param string $sutunlar SELECT'e giren haber sütunları (h. önekli)
 * @return list<array<string,mixed>>
 */
function konu_tara(string $slug, int $adet, string $sutunlar, bool $editorHaric = true): array
{
    if (!isset(KONULAR[$slug])) {
        return [];
    }

    [$kosul, $deger] = konu_kosulu($slug);
    $sonuc = [];

    try {
        for ($atla = 0; $atla < KONU_ADAY_TAVANI && count($sonuc) < $adet; $atla += KONU_ADAY_PARCA) {
            $ifade = db()->prepare(
                "SELECT {$sutunlar}, h.etiketler, h.ozet AS eslesme_ozet, k.slug AS kategori_slug
                   FROM haberler h
                   LEFT JOIN kategoriler k ON k.id = h.kategori_id
                  WHERE h.durum = 'yayinda' AND h.yayin_tarihi >= NOW() - INTERVAL " . KONU_GUN . " DAY
                    AND {$kosul}
                  ORDER BY h.yayin_tarihi DESC, h.id DESC
                  LIMIT " . KONU_ADAY_PARCA . ' OFFSET ' . $atla
            );
            $ifade->execute($deger);
            $satirlar = $ifade->fetchAll();

            foreach ($satirlar as $s) {
                // Spot, istenen sutunlarda olmasa da eslesme icin okunuyor.
                $s += ['ozet' => $s['eslesme_ozet']];
                unset($s['eslesme_ozet']);

                if (konu_eslesir($slug, $s, $editorHaric)) {
                    $sonuc[] = $s;

                    if (count($sonuc) >= $adet) {
                        break;
                    }
                }
            }

            if (count($satirlar) < KONU_ADAY_PARCA) {
                break;
            }
        }
    } catch (PDOException $e) {
        error_log('[valentra] konu haberleri: ' . $e->getMessage());
    }

    return $sonuc;
}

/**
 * Konunun haberleri, yeniden eskiye.
 *
 * @return list<array<string,mixed>>
 */
function konu_haberleri(string $slug, int $adet = KONU_HABER_TAVANI): array
{
    return konu_tara(
        $slug,
        max(1, $adet),
        'h.id, h.baslik, h.slug, h.ozet, h.neden_onemli, h.gorsel_url, h.yayin_tarihi, h.kaynak_adi,
         h.kaynak_baslik, h.kaynak_url, h.onem'
    );
}

/**
 * Bütün konuların haber sayısı ve son haber tarihi (liste sayfası, site haritası).
 *
 * @return array<string,array{sayi:int,son:string}>
 */
function konu_ozetleri(): array
{
    $ozet = [];

    foreach (array_keys(KONULAR) as $slug) {
        $haberler = konu_tara($slug, KONU_ADAY_TAVANI, 'h.id, h.baslik, h.yayin_tarihi');

        $ozet[$slug] = ['sayi' => count($haberler), 'son' => (string) ($haberler[0]['yayin_tarihi'] ?? '')];
    }

    return $ozet;
}

/**
 * Haberin girdiği konu (ilk eşleşen); yoksa null.
 *
 * @param array<string,mixed> $haber
 */
function konu_haberin_konusu(array $haber): ?string
{
    foreach (array_keys(KONULAR) as $slug) {
        if (konu_eslesir($slug, $haber)) {
            return $slug;
        }
    }

    return null;
}

/** Haber sayfasındaki "Bu haber ... dosyasında" kutusu. */
function konu_haber_kutusu_html(array $haber): string
{
    $slug = konu_haberin_konusu($haber);

    if ($slug === null) {
        return '';
    }

    return '<aside class="konu-kutu"><span>Dosya</span><a href="' . e(konu_yolu($slug)) . '">'
        . e(konu_basligi($slug)) . ': bütün gelişmeler &rarr;</a></aside>';
}
