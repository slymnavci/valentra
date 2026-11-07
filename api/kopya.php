<?php
declare(strict_types=1);

/**
 * Ayni olayin haberlerini tek habere indirme (07.11.2026, site sahibi:
 * "benzer konusu ayni haberi birlestir, tek haber yap"; bkz.
 * includes/kopya.php, ajan/kopya.php).
 *
 *   GET  ?saat=36 -> once modelsiz birlestirmeler (derleme ve dayandigi
 *                    haberler, basligi cok benzeyenler), sonra kalan
 *                    yayindaki haberler:
 *                    {"birlesen": n, "haberler": [{"id","baslik","ozet","kategori","tarih"}]}
 *   POST {"gruplar": [[12, 15], [20, 21, 30]]} -> {"birlesen": n}
 *
 * Her grupta yayinda kalan haberi site seciyor (derleme, cok kaynakli,
 * onemi yuksek, once yayimlanan); digerleri yayindan kalkip ona
 * yonleniyor. Sayfa onbellegi bir dakikalik; ayrica temizlenmiyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/kopya.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

if (!kopya_hazir()) {
    ajan_json(503, ['hata' => 'Veritabanı yükseltmesi bekleniyor (haberler.birlesti_id).']);
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $saat = max(6, min(96, (int) ($_GET['saat'] ?? KOPYA_PENCERE_SAAT)));
    $birlesen = kopya_kendiliginden_birlestir($saat);

    ajan_json(200, [
        'birlesen' => $birlesen,
        'haberler' => array_map(static fn (array $h): array => [
            'id'       => (int) $h['id'],
            'baslik'   => (string) $h['baslik'],
            'ozet'     => mb_substr((string) $h['ozet'], 0, 220, 'UTF-8'),
            'kategori' => (string) ($h['kategori'] ?? ''),
            'tarih'    => substr((string) $h['tarih'], 0, 16),
        ], kopya_son_haberler($saat)),
    ]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri = json_decode((string) file_get_contents('php://input'), true);
$birlesen = 0;

foreach (array_slice((array) ($veri['gruplar'] ?? []), 0, 60) as $grup) {
    if (is_array($grup)) {
        $birlesen += kopya_grubu_birlestir(array_values($grup));
    }
}

ajan_json(200, ['birlesen' => $birlesen]);
