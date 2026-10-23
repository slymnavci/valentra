<?php
declare(strict_types=1);

/**
 * Videolu haberlerin yayin tarihi ucu (ajan; bkz. ajan/video_tarih.php).
 *
 *   GET  -> {"eksik": [{"id","url"}]}   yayin tarihi bilinmeyen YouTube haberleri
 *   POST -> {"tarihler": [{"id": 12, "zaman": 1792500000}]}  (Unix)
 *
 * Site sahibinin istegi (23.10.2026): "sali videosunu arsive tasi, ana
 * sayfadan kaldir". Videolarin tarihi YouTube'un "updated"inden alinmis ve
 * bilinmiyor sayilmisti (sql/schema.sql); ajan videonun sayfasindan gercek
 * yayin tarihini okuyor. Eski video boylece ana sayfadan duser (yalnizca
 * bugun ve dun), konu sayfalarinda ve kendi adresinde durur.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $satirlar = db()->query(
        "SELECT id, kaynak_url AS url FROM haberler
          WHERE kaynak_tarihi IS NULL AND kaynak_url LIKE 'https://www.youtube.com/%'
          ORDER BY id DESC LIMIT 100"
    )->fetchAll();

    ajan_json(200, ['eksik' => array_map(
        static fn (array $s): array => ['id' => (int) $s['id'], 'url' => (string) $s['url']],
        $satirlar
    )]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri = json_decode((string) file_get_contents('php://input'), true);

// Gecikme olarak yaziliyor (bkz. haber_taslak_ekle): veritabaninin saat
// dilimi bilinmiyor. Gelecek ya da bir yildan eski zaman yok sayiliyor.
$ifade = db()->prepare(
    "UPDATE haberler SET kaynak_tarihi = NOW() - INTERVAL :gecikme MINUTE
      WHERE id = :id AND kaynak_tarihi IS NULL AND kaynak_url LIKE 'https://www.youtube.com/%'"
);

$guncellenen = 0;

foreach ((array) ($veri['tarihler'] ?? []) as $t) {
    $gecikme = intdiv(time() - (int) ($t['zaman'] ?? 0), 60);

    if (!is_array($t) || (int) ($t['id'] ?? 0) <= 0 || $gecikme < 0 || $gecikme > 365 * 1440) {
        continue;
    }

    $ifade->execute(['gecikme' => $gecikme, 'id' => (int) $t['id']]);
    $guncellenen += $ifade->rowCount();
}

ajan_json(200, ['guncellenen' => $guncellenen]);
