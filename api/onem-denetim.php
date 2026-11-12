<?php
declare(strict_types=1);

/**
 * Yayindaki haberlerin onem puanini yeniden verme (ajan/onem_denetle.php).
 *
 * Site sahibinin istegi (12.11.2026): "su anda manseti yeniden bu duzene
 * gore puanlandir". Onem puanini ajan haberi toplarken veriyor (ajan/src/
 * Yazar.php, ONEM PUANI); yonerge degisince (siradan spor ana sayfada yok,
 * baska ulkenin ic meselesi dusuk puan) yayindaki haberler eski puaniyla
 * kaliyordu: bugunku milli mac sonucu 70'in altinda kalip ana sayfadan
 * dustu, ABD'deki kizamik haberi "5 gelisme"de durdu. Bu uc, son saatlerin
 * haberlerini guncel yonergeyle yeniden puanlatmak icin.
 *
 *   GET  ?saat=36  -> {"haberler": [{"id","baslik","ozet","kategori","onem","kaynak_sayisi"}]}
 *   POST {"puanlar": [{"id": 1, "onem": 72}]} -> {"degisen": n}
 *
 * Yalnizca onem degisiyor. guncellendi ilerlemiyor: haber metni degismedi,
 * sayfada "Guncelleme" yazmamali. Resmi Gazete haberleri ve ana akisa
 * girmeyen basliklar (yemek tarifi, magazin) listede yok.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $saat = max(1, min(168, (int) ($_GET['saat'] ?? 36)));

    $disarida = implode(', ', array_map(static fn (string $s): string => db()->quote($s), HABER_AKIS_DISI));

    $ifade = db()->prepare(
        "SELECT h.id, h.baslik, h.ozet, h.onem, h.derleme_kaynaklari, k.slug AS kategori
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = 'yayinda'
            AND NOT (" . HABER_RG_KOSULU . ")
            AND (k.slug IS NULL OR k.slug NOT IN (" . $disarida . "))
            AND COALESCE(h.yayin_tarihi, h.olusturuldu) >= NOW() - INTERVAL :saat HOUR
          ORDER BY COALESCE(h.yayin_tarihi, h.olusturuldu) DESC
          LIMIT 400"
    );
    $ifade->bindValue('saat', $saat, PDO::PARAM_INT);
    $ifade->execute();

    ajan_json(200, ['haberler' => array_map(
        static function (array $s): array {
            $kaynaklar = json_decode((string) ($s['derleme_kaynaklari'] ?? ''), true);

            return [
                'id'            => (int) $s['id'],
                'baslik'        => (string) $s['baslik'],
                'ozet'          => (string) $s['ozet'],
                'kategori'      => (string) ($s['kategori'] ?? ''),
                'onem'          => (int) $s['onem'],
                // Derleme: ayni olayi veren kaynak sayisi (yonergedeki "3 ve
                // uzeri kaynak" kurali icin); tek kaynakli haberde 1.
                'kaynak_sayisi' => is_array($kaynaklar) ? max(1, count($kaynaklar)) : 1,
            ];
        },
        $ifade->fetchAll()
    )]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri = json_decode((string) file_get_contents('php://input'), true);

$yaz = db()->prepare(
    "UPDATE haberler SET onem = :o, guncellendi = guncellendi WHERE id = :id AND durum = 'yayinda' AND onem <> :o2"
);
$degisen = 0;

foreach (array_slice((array) ($veri['puanlar'] ?? []), 0, 500) as $p) {
    if (!is_array($p) || (int) ($p['id'] ?? 0) <= 0 || !is_numeric($p['onem'] ?? null)) {
        continue;
    }

    $onem = max(0, min(100, (int) $p['onem']));
    $yaz->execute(['o' => $onem, 'o2' => $onem, 'id' => (int) $p['id']]);
    $degisen += $yaz->rowCount();
}

ajan_json(200, ['degisen' => $degisen]);
