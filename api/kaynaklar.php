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

if (ajan_anahtar_dogrula() === null) {
    ajan_json(401, ['hata' => 'Geçersiz veya eksik anahtar.']);
}

$kaynaklar = db()->query(
    'SELECT id, ad, site_url, besleme_url, liste_url, liste_secici, tur
       FROM kaynaklar
      WHERE aktif = 1
      ORDER BY ad'
)->fetchAll();

$kategoriler = db()->query(
    'SELECT ad, slug, aciklama FROM kategoriler WHERE aktif = 1 ORDER BY sira, ad'
)->fetchAll();

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
]);
