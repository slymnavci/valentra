<?php
declare(strict_types=1);

/**
 * Ana sayfada aynı konudan en fazla 2 (çok önemliyse 3) haber.
 *
 * Site sahibinin istegi (08.11.2026): "ayni konuyla ilgili ana sayfada 2
 * veya cok onemliyse 3 haber yayinlayabilirsin; daha fazlasi olmasin.
 * Haberin toplam onem derecesine de bakalim. Baska onemli haberler varsa
 * bunlari toplayip bir ozgun haber haline getirelim."
 *
 * Nasil:
 *   - Ana sayfa adaylari (haber_ana_akis_kosulu, ana sayfa sirasiyla ilk
 *     KONU_ADAY_SAYISI haber) BASLIKLARINA gore konulara ayriliyor: iki
 *     baslik en az iki anlamli kok paylasiyor ve paylasilan kok kisa
 *     basligin kok sayisinin en az %40'i ise ayni konu ("Turkiye ile
 *     Kanada Arasinda Stratejik Ortaklik..." / "...Kanada Arasinda Savunma
 *     Ortakligi Mutabakati..."). Ajan ayni olayin kopyasini zaten almiyor;
 *     bu, ayni konunun FARKLI acilarini sayiyor.
 *   - Her konudan KONU_TAVANI haber kaliyor; konunun en onemli haberi
 *     KONU_COK_ONEMLI ve ustu ya da konudaki toplam onem KONU_TOPLAM_ONEM
 *     ve ustuyse KONU_TAVANI_ONEMLI. Kalanlar ana sayfadan cikiyor (konu
 *     sayfalarinda duruyor). Once derleme (ozgun) haber, sonra ana sayfa
 *     sirasi.
 *   - Tavani asan ve henuz derlemesi olmayan konular derleme ajanina
 *     ONCELIKLI olay olarak gidiyor (derleme_oncelikli_konular, api/derleme.php):
 *     ajan bunlari butun kaynaklardan tek bir ozgun haber olarak yaziyor.
 */

require_once __DIR__ . '/haberler.php';

/** Bir konudan ana sayfada en fazla bu kadar haber. */
const KONU_TAVANI = 2;

/** Çok önemli konuda en fazla bu kadar haber. */
const KONU_TAVANI_ONEMLI = 3;

/** Konunun en önemli haberi bu puan ve üstündeyse "çok önemli". */
const KONU_COK_ONEMLI = 75;

/** Ya da konudaki haberlerin toplam önemi bu ve üstündeyse. */
const KONU_TOPLAM_ONEM = 200;

/** Ana sayfa sırasıyla bakılan aday sayısı (ana sayfa 56 haber gösteriyor). */
const KONU_ADAY_SAYISI = 150;

/** Başlıkta konu belirtmeyen sözcükler. */
const KONU_DOLGU = [
    've', 'ile', 'için', 'icin', 'bir', 'bu', 'şu', 'da', 'de', 'mi', 'mı', 'ne', 'ya', 'ki', 'gibi', 'daha', 'en', 'çok',
    'olarak', 'olan', 'oldu', 'oldu', 'oluyor', 'var', 'yok', 'kadar', 'sonra', 'önce', 'karşı', 'göre', 'üzere',
    'arasında', 'üzerine', 'hakkında', 'yeni', 'son', 'dakika', 'türkiye', 'türkiye\'de', 'türk', 'açıkladı',
    'açıklama', 'açıklaması', 'dedi', 'yaptı', 'etti', 'verdi', 'geldi', 'başladı', 'sürüyor', 'devam', 'ediyor',
    'yüzde', 'milyon', 'milyar', 'bin', 'lira', 'liralık', 'dolar', 'yıl', 'yılın', 'gün', 'günü', 'hafta', 'ilk',
    'iki', 'üç', 'büyük', 'önemli', 'ilişkin', 'yönelik', 'tarafından', 'nasıl', 'neden', 'nedir', 'kim', 'hangi',
];

/** Aynı kurumun iki adı: başlıklarda ikisi de geçiyor. */
const KONU_ESANLAMLI = [
    'merkez bankası' => 'tcmb', 'merkez bankasi' => 'tcmb',
    'milli eğitim bakanlığı' => 'meb', 'millî eğitim bakanlığı' => 'meb',
    'türkiye büyük millet meclisi' => 'tbmm', 'meclis' => 'tbmm',
    'avrupa birliği' => 'ab', 'birleşmiş milletler' => 'bm',
    'cumhurbaşkanı erdoğan' => 'erdoğan',
];

/**
 * Başlığın anlamlı kökleri (Türkçe küçük harf, dolgu sözcükler atılmış,
 * ilk 4 harf: "Faizini" / "faiz", "Kanada'nın" / "Kanada" aynı kök).
 *
 * @return list<string>
 */
function konu_kokleri(string $baslik): array
{
    $baslik = strtr($baslik, ['I' => 'ı', 'İ' => 'i']);
    $baslik = mb_strtolower($baslik, 'UTF-8');
    $baslik = strtr($baslik, KONU_ESANLAMLI);
    $parcalar = preg_split('/[^\p{L}\p{N}\']+/u', $baslik, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $kokler = [];

    foreach ($parcalar as $p) {
        $p = preg_replace("/['’].*$/u", '', $p) ?? $p;

        if (in_array($p, KONU_DOLGU, true)) {
            continue;
        }

        if (preg_match('/^\d+$/', $p)) {
            // Tek basina sayi (230, 2027) yalnizca uzunsa konu belirtir.
            if (strlen($p) >= 3) {
                $kokler[$p] = true;
            }

            continue;
        }

        if (mb_strlen($p, 'UTF-8') >= 3 || in_array($p, ['ab', 'bm'], true)) {
            $kokler[mb_substr($p, 0, 4, 'UTF-8')] = true;
        }
    }

    return array_keys($kokler);
}

/** İki başlık aynı konuyu mu anlatıyor? */
function konu_ayni_mi(array $a, array $b): bool
{
    if ($a === [] || $b === []) {
        return false;
    }

    $ortak = count(array_intersect($a, $b));

    return $ortak >= 2 && $ortak / min(count($a), count($b)) >= 0.4;
}

/**
 * Haberleri konulara ayırır: iki haber aynı konudaysa konuları birleşir
 * (A~B ve B~C ise üçü bir konu). Konular ve içleri verilen sırada.
 *
 * @param list<array<string,mixed>> $haberler baslik alanı olan satırlar
 * @return list<list<int>> konu başına haberlerin dizideki sırası
 */
function konu_kumele(array $haberler): array
{
    $kokler = array_map(static fn (array $h): array => konu_kokleri((string) ($h['baslik'] ?? '')), $haberler);
    $ata    = array_keys($kokler);
    $bul    = static function (int $i) use (&$ata): int {
        while ($ata[$i] !== $i) {
            $i = $ata[$i] = $ata[$ata[$i]];
        }

        return $i;
    };

    $n = count($kokler);

    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (konu_ayni_mi($kokler[$i], $kokler[$j])) {
                $a = $bul($i);
                $b = $bul($j);

                // Kucuk sira kok kalsin: konu ilk haberinin yerinde.
                if ($a !== $b) {
                    $ata[max($a, $b)] = min($a, $b);
                }
            }
        }
    }

    $kumeler = [];

    for ($i = 0; $i < $n; $i++) {
        $kumeler[$bul($i)][] = $i;
    }

    return array_values($kumeler);
}

/** Konunun tavanı: çok önemliyse 3, değilse 2. */
function konu_tavani(array $uyeler): int
{
    $onemler = array_map(static fn (array $h): int => (int) ($h['onem'] ?? 0), $uyeler);

    return (max($onemler) >= KONU_COK_ONEMLI || array_sum($onemler) >= KONU_TOPLAM_ONEM)
        ? KONU_TAVANI_ONEMLI
        : KONU_TAVANI;
}

/**
 * Ana sayfa adaylarının konuları (istek başına bir kez).
 *
 * @return list<array{haberler:list<array<string,mixed>>,kalan:list<int>,fazla:list<int>}>
 */
function konu_ana_sayfa_konulari(): array
{
    static $sonuc = null;

    if ($sonuc !== null) {
        return $sonuc;
    }

    $sonuc = [];

    try {
        $ifade = db()->prepare(
            'SELECT h.id, h.baslik, h.onem, h.derleme, h.kaynak_adi, k.slug AS kategori_slug
               FROM haberler h
               LEFT JOIN kategoriler k ON k.id = h.kategori_id
               ' . haber_okunma_birlesimi() . '
              WHERE h.durum = :durum AND ' . haber_ana_akis_kosulu(false, false) . '
              ORDER BY ' . haber_sirasi_temel(true) . '
              LIMIT ' . KONU_ADAY_SAYISI
        );
        $ifade->execute(['durum' => HABER_YAYINDA]);
        $adaylar = $ifade->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] konu siniri: ' . $e->getMessage());

        return $sonuc;
    }

    foreach (konu_kumele($adaylar) as $sira) {
        $uyeler = array_map(static fn (int $i): array => $adaylar[$i], $sira);

        // Once derleme (ozgun) haber, sonra ana sayfa sirasi (usort kararli).
        usort($uyeler, static fn (array $a, array $b): int => (int) $b['derleme'] <=> (int) $a['derleme']);

        $tavan = konu_tavani($uyeler);
        $idler = array_map(static fn (array $h): int => (int) $h['id'], $uyeler);

        $sonuc[] = [
            'haberler' => $uyeler,
            'kalan'    => array_slice($idler, 0, $tavan),
            'fazla'    => array_slice($idler, $tavan),
        ];
    }

    return $sonuc;
}

/**
 * Ana sayfada gösterilmeyecek haberler (konusunun tavanını aşanlar).
 *
 * @return list<int>
 */
function konu_fazlasi_idler(): array
{
    static $idler = null;
    static $hesaplaniyor = false;

    if ($idler !== null) {
        return $idler;
    }

    // Aday sorgusu da haber_ana_akis_kosulu'nu kullaniyor: dongu olmasin.
    if ($hesaplaniyor) {
        return [];
    }

    $hesaplaniyor = true;
    $idler = [];

    foreach (konu_ana_sayfa_konulari() as $konu) {
        array_push($idler, ...$konu['fazla']);
    }

    $hesaplaniyor = false;

    return $idler;
}

/**
 * Derleme ajanına öncelikli konular: tavanı aşan ve henüz derlemesi
 * olmayan konular, bütün haberleriyle.
 *
 * @return list<array{baslik:string,kategori:string,haber_idleri:list<int>}>
 */
function derleme_oncelikli_konular(): array
{
    $liste = [];

    foreach (konu_ana_sayfa_konulari() as $konu) {
        if ($konu['fazla'] === []) {
            continue;
        }

        $derlemeVar = array_filter($konu['haberler'], static fn (array $h): bool => (int) $h['derleme'] === 1) !== [];

        if ($derlemeVar) {
            continue;
        }

        $liste[] = [
            'baslik'       => (string) $konu['haberler'][0]['baslik'],
            'kategori'     => (string) ($konu['haberler'][0]['kategori_slug'] ?? ''),
            'haber_idleri' => array_map(static fn (array $h): int => (int) $h['id'], $konu['haberler']),
        ];
    }

    return $liste;
}
