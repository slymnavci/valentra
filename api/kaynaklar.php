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
    'SELECT id, ad, site_url, besleme_url, liste_url, liste_secici, tur
       FROM kaynaklar
      WHERE aktif = 1
      ORDER BY ad'
)->fetchAll();

$kategoriler = db()->query(
    "SELECT k.ad, k.slug, k.aciklama
       FROM kategoriler k
      WHERE (k.aktif = 1 OR k.slug IN ('tms-tfrs','genel','ekonomi'))
        AND NOT EXISTS (
            SELECT 1
              FROM kategoriler c
             WHERE c.ust_id = k.id
               AND c.aktif = 1
        )
      ORDER BY
        CASE k.slug
            WHEN 'kurumlar-vergisi' THEN 10
            WHEN 'gelir-vergisi' THEN 20
            WHEN 'kdv' THEN 30
            WHEN 'vergi-usul-kanunu' THEN 40
            WHEN 'otv-ve-diger' THEN 50
            WHEN 'e-belge' THEN 60
            WHEN 'tesvik-yapilandirma' THEN 70
            WHEN 'denetim' THEN 80
            WHEN 'tms-tfrs' THEN 90
            WHEN 'ekonomi' THEN 100
            WHEN 'genel' THEN 110
            ELSE 500
        END,
        k.sira, k.ad"
)->fetchAll();

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

ajan_json(200, [
    'kaynaklar' => array_map(static fn (array $k): array => [
        'id'          => (int) $k['id'],
        'ad'          => $k['ad'],
        'site_url'    => $k['site_url'],
        'besleme_url'  => $k['besleme_url'],
        'liste_url'    => $k['liste_url'],
        'liste_secici' => $k['liste_secici'],
        'tur'          => $k['tur'],
    ], $kaynaklar),
    'kategoriler' => $kategoriler,
    'bilinen'        => $parmaklar,
    'bilinen_url'    => $urlParmaklari,
    'bilinen_baslik' => $baslikParmaklari,
    'son_basliklar'  => $sonBasliklar,
]);
