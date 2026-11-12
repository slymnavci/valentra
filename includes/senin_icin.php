<?php
declare(strict_types=1);

/**
 * "Senin icin": sehrim ve ilgi alanlarim (03.11.2026, site sahibi: okuru
 * geri getiren ozellikler, "siradaki").
 *
 * Okur ana sayfada sehrini ve sevdigi konulari seciyor; secim tarayicida
 * (localStorage) duruyor, uyelik ya da cerez gerekmiyor. Ana sayfa
 * onbellekten geldigi icin kisisel bolumu betik ayrica istiyor
 * (api/senin-icin.php): sehrin adi gecen son haberler en ustte, sonra
 * secilen konularin en onemli haberleri.
 */

require_once __DIR__ . '/haberler.php';

/** Secilebilen konular (menudeki ana basliklar). */
const SENIN_KONULAR = [
    'gundem' => 'Gündem', 'politika' => 'Politika', 'ekonomi' => 'Ekonomi', 'dunya' => 'Dünya',
    'spor' => 'Spor', 'teknoloji' => 'Teknoloji', 'saglik' => 'Sağlık', 'kultur-sanat' => 'Kültür-Sanat',
    'magazin' => 'Magazin',
];

const SENIN_ADET = 6;

/** 81 il: anahtar => ad (haber metninde aranan bicim). */
function senin_sehirler(): array
{
    $adlar = ['Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Aksaray', 'Amasya', 'Ankara', 'Antalya', 'Ardahan', 'Artvin',
        'Aydın', 'Balıkesir', 'Bartın', 'Batman', 'Bayburt', 'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa',
        'Çanakkale', 'Çankırı', 'Çorum', 'Denizli', 'Diyarbakır', 'Düzce', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum',
        'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari', 'Hatay', 'Iğdır', 'Isparta', 'İstanbul', 'İzmir',
        'Kahramanmaraş', 'Karabük', 'Karaman', 'Kars', 'Kastamonu', 'Kayseri', 'Kilis', 'Kırıkkale', 'Kırklareli',
        'Kırşehir', 'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Mardin', 'Mersin', 'Muğla', 'Muş', 'Nevşehir',
        'Niğde', 'Ordu', 'Osmaniye', 'Rize', 'Sakarya', 'Samsun', 'Şanlıurfa', 'Siirt', 'Sinop', 'Şırnak', 'Sivas',
        'Tekirdağ', 'Tokat', 'Trabzon', 'Tunceli', 'Uşak', 'Van', 'Yalova', 'Yozgat', 'Zonguldak'];
    $liste = [];

    foreach ($adlar as $ad) {
        $liste[slug_uret($ad)] = $ad;
    }

    return $liste;
}

/** Var olan (sitede bulunan) konular. @return array<string,string> */
function senin_konular(): array
{
    static $konular = null;

    if ($konular !== null) {
        return $konular;
    }

    $var = array_column(kategori_listesi(), 'slug');
    $konular = array_filter(SENIN_KONULAR, static fn (string $slug): bool => in_array($slug, $var, true), ARRAY_FILTER_USE_KEY);

    return $konular;
}

/**
 * Secime gore haberler: once sehir, sonra konular. Ayni haber iki kez gelmez.
 *
 * @param list<string> $konular
 * @return array{sehir:list<array<string,mixed>>,konu:list<array<string,mixed>>}
 */
function senin_icin_haberler(array $konular, string $sehir, int $adet = SENIN_ADET): array
{
    $sonuc = ['sehir' => [], 'konu' => []];
    $sehirler = senin_sehirler();
    $sehirAdi = $sehirler[$sehir] ?? '';
    $konular  = array_values(array_intersect($konular, array_keys(senin_konular())));
    $secim    = 'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
                   FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
                  WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND %s
                    AND h.olusturuldu > NOW() - INTERVAL 3 DAY';

    if ($sehirAdi !== '') {
        // Harmanlama (utf8mb4_unicode_ci) buyuk-kucuk harf ve I/İ farkini gozetmiyor;
        // "İzmir'de", "Izmir" de yakalaniyor. O ilin yerel kaynaklarinin
        // haberleri de burada (onemi dusuk olsa da; ana akista yoklar,
        // bkz. haber_yerel_kosulu).
        $ifade = db()->prepare(sprintf($secim, haber_ana_akis_kosulu(true))
            . ' AND (h.sehir = :il OR h.baslik LIKE :s1 OR h.ozet LIKE :s2 OR h.etiketler LIKE :s3)
                                          ORDER BY h.olusturuldu DESC LIMIT ' . max(1, min(12, (int) ceil($adet / 2))));
        $desen = '%' . $sehirAdi . '%';
        $ifade->execute(['il' => $sehir, 's1' => $desen, 's2' => $desen, 's3' => $desen]);
        $sonuc['sehir'] = $ifade->fetchAll();
    }

    $haric = array_map(static fn (array $h): int => (int) $h['id'], $sonuc['sehir']);
    $kalan = max(0, $adet - count($sonuc['sehir']));

    if ($konular !== [] && $kalan > 0) {
        $yer = implode(', ', array_map(static fn (string $k): string => db()->quote($k), $konular));
        // Okur sporu kendi sectiyse siradan spor haberi de gelir
        // (bkz. haber_siradan_spor_disi_kosulu).
        $sql = sprintf($secim, haber_ana_akis_kosulu(false, true, in_array('spor', $konular, true))) . ' AND k.slug IN (' . $yer . ')'
            . ($haric !== [] ? ' AND h.id NOT IN (' . implode(', ', $haric) . ')' : '')
            . ' ORDER BY DATE(h.olusturuldu) DESC, h.onem DESC, h.olusturuldu DESC LIMIT ' . max(1, min(24, $kalan + 2));
        $sonuc['konu'] = array_slice(db()->query($sql)->fetchAll(), 0, $kalan);
    }

    return $sonuc;
}
