<?php
declare(strict_types=1);

/**
 * YAYIN MASALARI (19.11.2026).
 *
 * Site sahibinin istegi: dis SEO degerlendirmesi uzerine "evet basla".
 * Haberler "Valentra Yayin Kurulu" imzasiyla yayimlaniyordu. Google
 * ekonomi ve saglik gibi "paraniz ya da hayatiniz" (YMYL) konularinda
 * haberi KIMIN hazirladigina bakiyor. Gercek olmayan kisi adi uydurmak
 * yerine haber, konusunun masasi adiyla imzalaniyor: Ekonomi Masasi,
 * Teknoloji Masasi... Her masa /yayin-masalari sayfasinda neyi izledigi,
 * hangi kaynaklara dayandigi ve haberin nasil hazirlandigiyla anlatiliyor;
 * imza o sayfaya bagli. Sema'da (NewsArticle author) ayni ad ve adres.
 */

require_once __DIR__ . '/kurumsal.php';

/**
 * Masa anahtari => [ad, kapsadigi konular, aciklama].
 *
 * @return array<string,array{ad:string,konular:string,aciklama:string}>
 */
function haber_masalari(): array
{
    return [
        'ekonomi' => [
            'ad'       => 'Ekonomi Masası',
            'konular'  => 'Ekonomi, piyasalar, vergi, muhasebe ve denetim',
            'aciklama' => 'Merkez Bankası kararları, enflasyon ve büyüme verileri, kur ve faiz hareketleri, vergi ve muhasebe düzenlemeleri. Resmî kurumların (TCMB, TÜİK, Hazine, Gelir İdaresi, Resmî Gazete) duyurularını ve birden çok yayının haberini birlikte okuyup derler; kararın okur ve işletmeler için ne anlama geldiğini ayrıca açıklar.',
        ],
        'teknoloji' => [
            'ad'       => 'Teknoloji Masası',
            'konular'  => 'Teknoloji, yapay zekâ ve bilim',
            'aciklama' => 'Yapay zekâ gelişmeleri, teknoloji şirketleri, siber güvenlik ve bilim haberleri. Yabancı kaynakları Türkçeye aktarır, gelişmenin Türkiye\'deki okur için anlamını ayrıca yazar.',
        ],
        'dunya' => [
            'ad'       => 'Dış Haberler Masası',
            'konular'  => 'Dünya',
            'aciklama' => 'Uluslararası gelişmeler. Ülkelerin önde gelen yayınlarının ve ajansların haberlerini Türkçeye aktarır, farklı kaynakları karşılaştırır.',
        ],
        'spor' => [
            'ad'       => 'Spor Masası',
            'konular'  => 'Futbol, basketbol ve diğer branşlar',
            'aciklama' => 'Maç sonuçları, transferler ve lig gelişmeleri. Skor ve puan durumu verileri canlı kaynaklardan otomatik güncellenir.',
        ],
        'saglik' => [
            'ad'       => 'Sağlık Masası',
            'konular'  => 'Sağlık',
            'aciklama' => 'Sağlık politikaları, araştırmalar ve resmî sağlık duyuruları. Tıbbi öneri vermez; bilgiyi kaynağına bağlı olarak aktarır.',
        ],
        'yasam' => [
            'ad'       => 'Kültür-Sanat ve Yaşam Masası',
            'konular'  => 'Kültür-sanat, magazin ve yaşam',
            'aciklama' => 'Kültür-sanat etkinlikleri, yaşam ve magazin haberleri, tarifler.',
        ],
        'gundem' => [
            'ad'       => 'Gündem Masası',
            'konular'  => 'Türkiye gündemi ve politika',
            'aciklama' => 'Türkiye\'nin günlük gündemi, politika ve yerel gelişmeler. Aynı olayı veren birden çok yayını bir araya getirir.',
        ],
    ];
}

/** Kategori => masa. Listede olmayan kategori ve kategorisiz haber Gündem Masası'nda. */
const HABER_MASA_KATEGORILERI = [
    'ekonomi' => 'ekonomi', 'vergi-kanunlari' => 'ekonomi', 'kurumlar-vergisi' => 'ekonomi', 'gelir-vergisi' => 'ekonomi',
    'kdv' => 'ekonomi', 'otv-ve-diger' => 'ekonomi', 'vergi-usul-kanunu' => 'ekonomi', 'e-belge' => 'ekonomi',
    'tesvik-yapilandirma' => 'ekonomi', 'muhasebe-denetim' => 'ekonomi', 'denetim' => 'ekonomi', 'tms-tfrs' => 'ekonomi',
    'teknoloji' => 'teknoloji',
    'dunya' => 'dunya',
    'spor' => 'spor',
    'saglik' => 'saglik',
    'kultur-sanat' => 'yasam', 'magazin' => 'yasam', 'yemek-tarifleri' => 'yasam',
];

/** Haberin masa anahtarı (kategori_slug'a göre). */
function haber_masa_anahtari(array $haber): string
{
    return HABER_MASA_KATEGORILERI[(string) ($haber['kategori_slug'] ?? '')] ?? 'gundem';
}

/** @return array{anahtar:string,ad:string,yol:string} */
function haber_masasi(array $haber): array
{
    $anahtar = haber_masa_anahtari($haber);

    return [
        'anahtar' => $anahtar,
        'ad'      => haber_masalari()[$anahtar]['ad'],
        'yol'     => kurumsal_yolu('yayin-masalari') . '#' . $anahtar,
    ];
}
