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
 * 21 gun: beslemelerin geriye bakis penceresinden (en fazla 36 saat) kat
 * kat uzun, ama liste sinirsiz buyumuyor.
 */
$parmaklar = db()->query(
    'SELECT kaynak_parmak
       FROM haberler
      WHERE kaynak_parmak IS NOT NULL
        AND olusturuldu >= DATE_SUB(NOW(), INTERVAL 21 DAY)'
)->fetchAll(PDO::FETCH_COLUMN);

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
    'bilinen'     => array_values(array_map('strval', $parmaklar)),
]);
