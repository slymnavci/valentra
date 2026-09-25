<?php
declare(strict_types=1);

/**
 * Ust menu.
 *
 * Menu kategori tablosundan DEGIL, okuyucunun aradigi seyden kuruluyor:
 *
 *   Ana Sayfa · Gundem · Vergi ve Mevzuat · Muhasebe ve TFRS ·
 *   Rehberler · Araclar
 *
 * Eskiden her ust kategori bir menu basligiydi; araya Pratik Bilgiler,
 * Vergi Takvimi, Araclar ve Resmi Gazete tek tek eklenmisti ve "Diger"
 * diye icerigi belirsiz bir baslik vardi. Simdi:
 *   - Resmi Gazete ve kanun metinleri vergi basliklariyla ayni yerde
 *     (Vergi ve Mevzuat), cunku okuyucu onlari ayni soru icin aciyor.
 *   - "Diger" (genel) ve Ekonomik Gundem, "Valentra Diyor ki…" ile
 *     birlikte Gundem altinda.
 *   - Pratik bilgiler ve takvim Rehberler altinda; ayrica ana sayfanin
 *     ilk ekraninda kisayol olarak duruyorlar.
 *
 * Kategoriler hala tablodan geliyor (ad, haber sayisi, panelde eklenen
 * yeni alt gruplar). Yerini bilmedigimiz yeni bir UST kategori
 * eklenirse kaybolmasin diye Gundem'in sonuna dusuyor.
 */

/**
 * @param list<array{ad:string,slug:string,adet:int,altlar:list<array<string,mixed>>}> $kategoriMenu
 *        kategori_menusu() ciktisi
 * @return list<array{ad:string,anahtar:string,href:?string,
 *                    altlar:list<array{ad:string,anahtar:string,href:string,adet:int}>}>
 */
function site_menusu(array $kategoriMenu): array
{
    $gruplar = [];

    foreach ($kategoriMenu as $grup) {
        $gruplar[(string) $grup['slug']] = $grup;
    }

    // Kategori ogesi: ad tablodaki, sayi yayindaki haber sayisi.
    $kategori = static fn (array $k, ?string $ad = null): array => [
        'ad'      => $ad ?? (string) $k['ad'],
        'anahtar' => (string) $k['slug'],
        'href'    => kategori_yolu((string) $k['slug']),
        'adet'    => (int) $k['adet'],
    ];

    // Ust kategori ve alt gruplari. Ust kategorinin kendisine dogrudan
    // atanmis haber varsa o da listede; yoksa bos bir sayfaya
    // gondermemek icin yalnizca altlari.
    $agac = static function (string $slug, ?string $ustAd = null) use ($gruplar, $kategori): array {
        if (!isset($gruplar[$slug])) {
            return [];
        }

        $grup  = $gruplar[$slug];
        $ogeler = [];
        $kendi = (int) $grup['adet'] - array_sum(array_column($grup['altlar'], 'adet'));

        if ($grup['altlar'] === [] || $kendi > 0) {
            $ogeler[] = $kategori(['ad' => $grup['ad'], 'slug' => $slug, 'adet' => $kendi], $ustAd);
        }

        foreach ($grup['altlar'] as $alt) {
            $ogeler[] = $kategori($alt);
        }

        return $ogeler;
    };

    $bilinen = ['ekonomi', 'genel', 'vergi-kanunlari', 'muhasebe-denetim', 'tms-tfrs'];

    $gundem = array_merge(
        $agac('ekonomi', 'Ekonomik Gündem'),
        [['ad' => 'Valentra Diyor ki…', 'anahtar' => 'kose', 'href' => kose_liste_yolu(), 'adet' => 0]],
        $agac('genel', 'Genel Gündem'),
    );

    foreach ($gruplar as $slug => $grup) {
        if (!in_array($slug, $bilinen, true)) {
            $gundem = array_merge($gundem, $agac((string) $slug));
        }
    }

    $mevzuat = array_merge(
        [
            ['ad' => 'Resmî Gazete',    'anahtar' => 'resmi-gazete', 'href' => rg_yolu(),       'adet' => 0],
            ['ad' => 'Kanun Metinleri', 'anahtar' => 'kanunlar',     'href' => kanunlar_yolu(), 'adet' => 0],
        ],
        $agac('vergi-kanunlari', 'Vergi Kanunları'),
    );

    $muhasebe = array_merge(
        $agac('tms-tfrs', 'TMS / TFRS'),
        $agac('muhasebe-denetim', 'Muhasebe ve Denetim'),
    );

    $rehberler = [
        ['ad' => 'Pratik Bilgiler', 'anahtar' => 'pratik', 'href' => pratik_yolu(), 'adet' => 0],
        ['ad' => 'Vergi Takvimi',   'anahtar' => 'takvim', 'href' => takvim_yolu(), 'adet' => 0],
    ];

    $menu = [
        ['ad' => 'Gündem',           'anahtar' => 'gundem',   'href' => null, 'altlar' => $gundem],
        ['ad' => 'Vergi ve Mevzuat', 'anahtar' => 'mevzuat',  'href' => null, 'altlar' => $mevzuat],
        ['ad' => 'Muhasebe ve TFRS', 'anahtar' => 'muhasebe', 'href' => null, 'altlar' => $muhasebe],
        ['ad' => 'Rehberler',        'anahtar' => 'rehber',   'href' => null, 'altlar' => $rehberler],
        ['ad' => 'Araçlar',          'anahtar' => 'araclar',  'href' => araclar_yolu(), 'altlar' => []],
    ];

    // Alt ogesi kalmayan acilir baslik gosterilmiyor.
    return array_values(array_filter(
        $menu,
        static fn (array $m): bool => $m['href'] !== null || $m['altlar'] !== []
    ));
}

/** Menu basligi ya da alt ogelerinden biri gecerli sayfa mi? */
function site_menu_aktif(array $oge, string $aktif): bool
{
    if ($aktif === '') {
        return false;
    }

    return $oge['anahtar'] === $aktif
        || in_array($aktif, array_column($oge['altlar'], 'anahtar'), true);
}
