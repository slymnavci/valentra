<?php
declare(strict_types=1);

/**
 * Ajanin calisma yapilandirmasi: taranacak kaynaklar ve konu gruplari.
 *
 *   GET /api/kaynaklar.php
 *   Authorization: Bearer <anahtar>
 *
 * Ikisi de yonetim panelinden duzenlenir; ajan her calismada guncel
 * listeyi buradan alir, kod degisikligi gerekmez.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ajan_json(405, ['hata' => 'Yalnızca GET kabul edilir.']);
}

if (ajan_anahtar_dogrula($neden) === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$kaynaklar = db()->query(
    'SELECT id, ad, site_url, besleme_url, liste_url, liste_secici, tur, kategori
       FROM kaynaklar
      WHERE aktif = 1
      ORDER BY ad'
)->fetchAll();

/*
 * Konu gruplari: yalnizca haber akisi basliklari, menu sirasiyla.
 *
 * Ekim 2026'dan once burada vergi alt gruplari (KDV, kurumlar vergisi,
 * TMS/TFRS...) vardi. Site genel haber ozetlerine donunce model artik
 * yalnizca gundem, politika, ekonomi, dunya, spor, teknoloji, saglik ve
 * kultur-sanat arasindan seciyor. Eski kategoriler veritabaninda duruyor
 * (eski haberler onlara bagli) ama modele gosterilmiyor.
 */
$kategoriler = array_map(static fn (array $k): array => [
    'ad'       => $k['ad'],
    'slug'     => $k['slug'],
    'aciklama' => $k['aciklama'],
], haber_akis_kategorileri());

/*
 * Bilinen haberlerin parmak izleri.
 *
 * Kopya engeli yazma aninda calisiyordu: ayni haber her calismada
 * yeniden modele gidip ucretlendiriliyor, sonra "zaten vardi" diye
 * atiliyordu. Ajan gunde bir kez kosarken bu kucuk bir israfti; gunde
 * on kez kosunca maliyetin neredeyse tamami buna gidiyor.
 *
 * Parmak izlerini onden verip ajanin modele hic sormamasini sagliyoruz.
 * Durum farketmez: reddedilmis bir haberi tekrar yazdirmak da istemiyoruz.
 *
 * UC AYRI LISTE gonderiliyor:
 *   bilinen         - eski ham adres parmak izi (geriye donuk uyum)
 *   bilinen_url     - sadelestirilmis adres; izleme parametresi
 *                     eklenmis ayni haberi de yakalar
 *   bilinen_baslik  - katlanmis baslik; ayni haberin BASKA bir
 *                     kaynaktan gelen kopyasini yakalar
 *   son_basliklar   - ajan benzerlik karsilastirmasi yapsin diye
 *                     duz metin basliklar (birebir ayni olmayan ama
 *                     ayni olayi anlatan basliklar icin)
 *
 * 21 gun: beslemelerin geriye bakis penceresinden (en fazla 36 saat) kat
 * kat uzun, ama liste sinirsiz buyumuyor.
 */

// Yeni alanlari bos kalan eski kayitlari doldur. Ajan siteye her
// baglandiginda birkac yuz satir isleniyor; tek seferde hepsini
// denemek buyuk bir arsivde istegi zaman asimina ugratirdi.
haber_parmaklari_tamamla();

$pencere = 'olusturuldu >= DATE_SUB(NOW(), INTERVAL 21 DAY)';

$sutun = static function (string $sorgu): array {
    try {
        return array_values(array_filter(array_map(
            'strval',
            db()->query($sorgu)->fetchAll(PDO::FETCH_COLUMN)
        ), static fn (string $d): bool => $d !== ''));
    } catch (PDOException $e) {
        return [];
    }
};

$parmaklar = $sutun(
    'SELECT kaynak_parmak FROM haberler
      WHERE kaynak_parmak IS NOT NULL AND ' . $pencere
);

$urlParmaklari = $sutun(
    'SELECT url_parmak FROM haberler
      WHERE url_parmak IS NOT NULL AND ' . $pencere
);

$baslikParmaklari = $sutun(
    'SELECT baslik_parmak FROM haberler
      WHERE baslik_parmak IS NOT NULL AND ' . $pencere
);

/*
 * Kaynaklarin ozgun basliklari da "bilinen baslik" (22.10.2026).
 *
 * Sitedeki baslik modelin yeniden yazdigi baslik. Ajan ise beslemedeki
 * OZGUN basligi karsilastiriyor; ayni ajans haberi saatler sonra baska
 * bir sitede ayni basligiyla geldiginde yeniden yazilmis basliga
 * benzemiyor ve haber ikinci kez toplanabiliyordu.
 */
$baslikParmaklari = array_values(array_unique(array_merge($baslikParmaklari, $sutun(
    'SELECT kaynak_baslik_parmak FROM haberler
      WHERE kaynak_baslik_parmak IS NOT NULL AND ' . $pencere
))));

/*
 * Duz basliklar da gidiyor.
 *
 * Parmak izi yalnizca BIREBIR ayni basligi yakalar. Ayni tebligi iki
 * kaynak birkac kelime farkla duyurdugunda okuyucu icin bu ayni haber
 * ama parmak izleri farkli. Ajan bu listeyle kelime benzerligine
 * bakabiliyor; model cagrisi gerekmiyor.
 */
$sonBasliklar = $sutun(
    'SELECT baslik FROM haberler
      WHERE ' . $pencere . '
      ORDER BY id DESC
      LIMIT 600'
);

/*
 * Ozgun kaynak basliklari ayri listede: ajan bunlari yalnizca kelime
 * benzerliginde kullaniyor. Modele gosterilen "daha once yayimlananlar"
 * listesi son_basliklar'dan geliyor ve okuyucunun gordugu basliklar
 * olarak kalmali.
 */
$sonKaynakBasliklari = $sutun(
    "SELECT kaynak_baslik FROM haberler
      WHERE kaynak_baslik <> '' AND " . $pencere . '
      ORDER BY id DESC
      LIMIT 600'
);

ajan_json(200, [
    'kaynaklar' => array_map(static fn (array $k): array => [
        'id'          => (int) $k['id'],
        'ad'          => $k['ad'],
        'site_url'    => $k['site_url'],
        'besleme_url'  => $k['besleme_url'],
        'liste_url'    => $k['liste_url'],
        'liste_secici' => $k['liste_secici'],
        'tur'          => $k['tur'],
        'kategori'     => $k['kategori'],
    ], $kaynaklar),
    'kategoriler' => $kategoriler,
    'bilinen'        => $parmaklar,
    'bilinen_url'    => $urlParmaklari,
    'bilinen_baslik' => $baslikParmaklari,
    'son_basliklar'  => $sonBasliklar,
    'son_kaynak_basliklari' => $sonKaynakBasliklari,
]);
