<?php
declare(strict_types=1);

/**
 * Haber tarihlerinin denetimi (ajan/tarih_denetle.php).
 *
 * Site sahibinin istegi (23.10.2026): "haberlerin tarihleri cok onemli,
 * onlari mutlaka kontrol edelim". Bir gunluk faiz karari haberi ana
 * sayfada 2. siradaydi: beslemeden gelen tarih yeni, haberin kendisi
 * eskiydi. Ajan son iki gunde toplanan her haberin sayfasini bir kez
 * aciyor, sayfanin kendi yayin zamanini okuyup buraya gonderiyor.
 *
 *   GET  -> {"haberler": [{"id": 1, "url": "..."}]}  denetlenmemis olanlar
 *   POST {"tarihler": [{"id": 1, "zaman": 1792700000|null}]}
 *        -> {"duzeltilen": n}
 *
 * Sayfanin zamani kayitli zamandan ERKENSE kaynak_tarihi ona cekiliyor
 * (haber daha eski); hic yoksa yaziliyor. Daha gec bir zaman kayitli
 * zamani degistirmiyor: guncelleme tarihi haberi yeni yapmaz. Her
 * denetlenen haber isaretleniyor, ikinci kez sorulmuyor.
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
        "SELECT h.id, h.kaynak_url AS url
           FROM haberler h
          WHERE h.tarih_denetlendi IS NULL
            AND h.kaynak_url <> ''
            AND h.olusturuldu >= NOW() - INTERVAL 2 DAY
            AND (h.kategori_id IS NULL OR h.kategori_id NOT IN
                 (SELECT id FROM kategoriler WHERE slug = 'yemek-tarifleri'))
          ORDER BY h.id DESC
          LIMIT 80"
    )->fetchAll();

    ajan_json(200, ['haberler' => array_map(
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
$duzelt = db()->prepare(
    'UPDATE haberler
        SET kaynak_tarihi = NOW() - INTERVAL :gecikme MINUTE
      WHERE id = :id
        AND (kaynak_tarihi IS NULL OR kaynak_tarihi > NOW() - INTERVAL :gecikme2 MINUTE)'
);
$isaretle = db()->prepare('UPDATE haberler SET tarih_denetlendi = NOW() WHERE id = :id');

$duzeltilen = 0;

foreach ((array) ($veri['tarihler'] ?? []) as $t) {
    if (!is_array($t) || (int) ($t['id'] ?? 0) <= 0) {
        continue;
    }

    $id    = (int) $t['id'];
    $zaman = (int) ($t['zaman'] ?? 0);

    if ($zaman > 0) {
        $gecikme = intdiv(time() - $zaman, 60);

        // 10 dakikadan az fark saat yuvarlamasi; dokunulmuyor.
        if ($gecikme >= 0 && $gecikme <= 365 * 1440) {
            $duzelt->execute(['gecikme' => $gecikme, 'gecikme2' => $gecikme + 10, 'id' => $id]);
            $duzeltilen += $duzelt->rowCount();
        }
    }

    $isaretle->execute(['id' => $id]);
}

ajan_json(200, ['duzeltilen' => $duzeltilen]);
