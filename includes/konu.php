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
        'kelimeler' => ['kira artış', 'kira zammı', 'kira artışı', 'kiracı', 'ev sahibi'],
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

/**
 * SQL koşulu ve parametreleri (başlık ya da etiketlerde kelimelerden biri).
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
 * Konunun haberleri, yeniden eskiye.
 *
 * @return list<array<string,mixed>>
 */
function konu_haberleri(string $slug, int $adet = KONU_HABER_TAVANI): array
{
    if (!isset(KONULAR[$slug])) {
        return [];
    }

    [$kosul, $deger] = konu_kosulu($slug);

    try {
        $ifade = db()->prepare(
            "SELECT h.id, h.baslik, h.slug, h.ozet, h.neden_onemli, h.gorsel_url, h.yayin_tarihi, h.kaynak_adi
               FROM haberler h
              WHERE h.durum = 'yayinda' AND h.yayin_tarihi >= NOW() - INTERVAL " . KONU_GUN . " DAY
                AND {$kosul}
              ORDER BY h.yayin_tarihi DESC
              LIMIT " . max(1, $adet)
        );
        $ifade->execute($deger);

        return $ifade->fetchAll();
    } catch (PDOException $e) {
        error_log('[valentra] konu haberleri: ' . $e->getMessage());

        return [];
    }
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
        [$kosul, $deger] = konu_kosulu($slug);

        try {
            $ifade = db()->prepare(
                "SELECT COUNT(*) AS sayi, MAX(h.yayin_tarihi) AS son FROM haberler h
                  WHERE h.durum = 'yayinda' AND h.yayin_tarihi >= NOW() - INTERVAL " . KONU_GUN . " DAY AND {$kosul}"
            );
            $ifade->execute($deger);
            $r = $ifade->fetch() ?: [];
        } catch (PDOException $e) {
            $r = [];
        }

        $ozet[$slug] = ['sayi' => (int) ($r['sayi'] ?? 0), 'son' => (string) ($r['son'] ?? '')];
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
    $metin = mb_strtolower((string) ($haber['baslik'] ?? '') . ' | ' . (string) ($haber['etiketler'] ?? ''), 'UTF-8');

    foreach (KONULAR as $slug => $k) {
        foreach ($k['kelimeler'] as $kelime) {
            $aranan = mb_strtolower($kelime, 'UTF-8');

            // Kisaltmalar (TÜFE, PPK, YKS) tam kelime olarak.
            $tam = mb_strlen($kelime, 'UTF-8') <= 4 && mb_strtoupper($kelime, 'UTF-8') === $kelime;

            if ($tam
                ? preg_match('/(^|[^\p{L}])' . preg_quote($aranan, '/') . '([^\p{L}]|$)/u', $metin) === 1
                : str_contains($metin, $aranan)) {
                return $slug;
            }
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
