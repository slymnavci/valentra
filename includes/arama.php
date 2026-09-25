<?php
declare(strict_types=1);

/**
 * Site ici arama.
 *
 * Haberler, "Valentra Diyor ki…" yazilari, pratik bilgiler ve Resmi
 * Gazete maddeleri. Her kelime (en fazla bes) baslikta, ozette ya da
 * metinde gecmeli; basliginda gecenler once. LIKE yeterli: tablolar
 * kucuk ve FULLTEXT paylasimli hostingte iki harfli Turkce kisaltmalari
 * (KDV, GV, VUK degil ama "e-" gibi parcalari) kaciriyor.
 *
 * Harmanlama utf8mb4_unicode_ci oldugu icin buyuk/kucuk harf ve
 * sapkali harf farki onemsiz ("kdv" = "KDV").
 */

const ARAMA_EN_KISA = 2;
const ARAMA_EN_UZUN = 100;
const ARAMA_KELIME  = 5;

/**
 * Sorguyu kelimelere ayirir; cok kisa parcalar atilir.
 *
 * @return list<string>
 */
function arama_kelimeleri(string $sorgu): array
{
    $sorgu = mb_substr(trim($sorgu), 0, ARAMA_EN_UZUN);
    $parcalar = preg_split('/\s+/u', $sorgu, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $kelimeler = array_values(array_unique(array_filter(
        $parcalar,
        static fn (string $p): bool => mb_strlen($p) >= ARAMA_EN_KISA
    )));

    return array_slice($kelimeler, 0, ARAMA_KELIME);
}

/** LIKE icin joker karakterleri kacirir. */
function arama_like(string $kelime): string
{
    return '%' . addcslashes($kelime, '%_\\') . '%';
}

/**
 * Her kelimenin verilen sutunlardan birinde gectigi WHERE parcasi.
 *
 * @param list<string> $kelimeler
 * @param list<string> $sutunlar
 * @return array{0:string,1:array<string,string>}
 */
function arama_kosulu(array $kelimeler, array $sutunlar, string $onek): array
{
    $parcalar = [];
    $degerler = [];

    foreach ($kelimeler as $i => $kelime) {
        $ya = [];

        foreach ($sutunlar as $j => $sutun) {
            $ad = $onek . $i . '_' . $j;
            $ya[] = $sutun . ' LIKE :' . $ad;
            $degerler[$ad] = arama_like($kelime);
        }

        $parcalar[] = '(' . implode(' OR ', $ya) . ')';
    }

    return [implode(' AND ', $parcalar), $degerler];
}

/**
 * Baslikta butun kelimeler geciyorsa 1 (siralamada once).
 *
 * @param list<string> $kelimeler
 * @return array{0:string,1:array<string,string>}
 */
function arama_baslik_puani(array $kelimeler, string $sutun, string $onek): array
{
    [$kosul, $degerler] = arama_kosulu($kelimeler, [$sutun], $onek);

    return ['(' . $kosul . ')', $degerler];
}

/**
 * @param list<string> $kelimeler
 * @return list<array<string,mixed>>
 */
function arama_haberler(array $kelimeler, int $limit = 30): array
{
    if ($kelimeler === []) {
        return [];
    }

    [$kosul, $degerler]  = arama_kosulu($kelimeler, ['h.baslik', 'h.ozet', 'h.icerik'], 'h');
    [$puan, $puanDeger]  = arama_baslik_puani($kelimeler, 'h.baslik', 'hb');

    $ifade = db()->prepare(
        'SELECT h.id, h.baslik, h.slug, h.ozet, h.yayin_tarihi,
                k.ad AS kategori_adi
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum AND ' . $kosul . '
          ORDER BY ' . $puan . ' DESC, h.yayin_tarihi DESC
          LIMIT ' . max(1, $limit)
    );
    $ifade->execute($degerler + $puanDeger + ['durum' => HABER_YAYINDA]);

    return $ifade->fetchAll();
}

/**
 * @param list<string> $kelimeler
 * @return list<array<string,mixed>>
 */
function arama_kose_yazilari(array $kelimeler, int $limit = 10): array
{
    if ($kelimeler === []) {
        return [];
    }

    [$kosul, $degerler] = arama_kosulu($kelimeler, ['baslik', 'ozet', 'gundem', 'icerik'], 'k');
    [$puan, $puanDeger] = arama_baslik_puani($kelimeler, 'baslik', 'kb');

    $ifade = db()->prepare(
        "SELECT baslik, slug, ozet, gundem, gun
           FROM kose_yazilari
          WHERE durum = 'yayinda' AND " . $kosul . '
          ORDER BY ' . $puan . ' DESC, gun DESC, sira
          LIMIT ' . max(1, $limit)
    );
    $ifade->execute($degerler + $puanDeger);

    return $ifade->fetchAll();
}

/**
 * Yalnizca yayinda degeri olan pratik bilgiler.
 *
 * @param list<string> $kelimeler
 * @return list<array<string,mixed>>
 */
function arama_pratik(array $kelimeler, int $limit = 6): array
{
    if ($kelimeler === []) {
        return [];
    }

    [$kosul, $degerler] = arama_kosulu($kelimeler, ['baslik', 'aciklama', 'anahtar'], 'p');

    $ifade = db()->prepare(
        'SELECT anahtar, baslik, deger, donem
           FROM pratik_bilgiler
          WHERE aktif = 1 AND deger IS NOT NULL AND ' . $kosul . '
          ORDER BY sira, baslik
          LIMIT ' . max(1, $limit)
    );
    $ifade->execute($degerler);

    return $ifade->fetchAll();
}

/**
 * @param list<string> $kelimeler
 * @return list<array<string,mixed>>
 */
function arama_resmi_gazete(array $kelimeler, int $limit = 8): array
{
    if ($kelimeler === []) {
        return [];
    }

    [$kosul, $degerler] = arama_kosulu($kelimeler, ['baslik'], 'r');

    $ifade = db()->prepare(
        'SELECT tarih, baslik, url, bolum
           FROM resmi_gazete
          WHERE ' . $kosul . '
          ORDER BY tarih DESC, mukerrer, sira
          LIMIT ' . max(1, $limit)
    );
    $ifade->execute($degerler);

    return $ifade->fetchAll();
}

/**
 * Yayindaki uygulama rehberleri.
 *
 * @param list<string> $kelimeler
 * @return list<array<string,mixed>>
 */
function arama_rehberler(array $kelimeler, int $limit = 8): array
{
    if ($kelimeler === []) {
        return [];
    }

    [$kosul, $degerler] = arama_kosulu($kelimeler, ['baslik', 'ozet', 'konu', 'icerik'], 'g');
    [$puan, $puanDeger] = arama_baslik_puani($kelimeler, 'baslik', 'gb');

    $ifade = db()->prepare(
        "SELECT baslik, slug, ozet, konu FROM rehberler
          WHERE durum = 'yayinda' AND " . $kosul . '
          ORDER BY ' . $puan . ' DESC, sira
          LIMIT ' . max(1, $limit)
    );
    $ifade->execute($degerler + $puanDeger);

    return $ifade->fetchAll();
}
