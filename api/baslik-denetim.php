<?php
declare(strict_types=1);

/**
 * Haber basliklarinin Turkce denetimi (ajan/baslik_denetle.php).
 *
 * Site sahibinin istegi (04.11.2026): "haberlerdeki basliklarin Turkcesini
 * cok iyi yapmamiz lazim; 'Trump uzayi tum insanligin ilan etti' gibi
 * basliklarda ne dedigi anlasilmiyor; benzer hatalar da duzeltilsin".
 * Ajan yayindaki son haberlerin basligini ozetiyle birlikte modele
 * okutuyor; anlasilmayan, ceviri kalibi ya da eksik tumlecli basliklarin
 * dogal Turkce karsiligini buraya gonderiyor.
 *
 *   GET  ?gun=3   -> {"haberler": [{"id", "baslik", "ozet", "giris"}]}  denetlenmemis olanlar
 *   POST {"basliklar": [{"id": 1, "yeni": "..."|null}]} -> {"duzeltilen": n}
 *
 * Adres (slug) degismiyor: paylasilmis baglantilar bozulmasin. Onceki
 * baslik eski_baslik'ta saklaniyor. Her denetlenen haber isaretleniyor,
 * ikinci kez sorulmuyor. Duzeltilen haber arama motorlarina yeniden
 * bildiriliyor (IndexNow).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $gun = max(1, min(30, (int) ($_GET['gun'] ?? 3)));

    $ifade = db()->prepare(
        "SELECT h.id, h.baslik, h.ozet, LEFT(h.icerik, 1500) AS icerik
           FROM haberler h
          WHERE h.durum = 'yayinda'
            AND h.baslik_denetlendi IS NULL
            AND h.olusturuldu >= NOW() - INTERVAL :gun DAY
            AND (h.kategori_id IS NULL OR h.kategori_id NOT IN
                 (SELECT id FROM kategoriler WHERE slug = 'yemek-tarifleri'))
          ORDER BY h.id DESC
          LIMIT 300"
    );
    $ifade->execute(['gun' => $gun]);

    ajan_json(200, ['haberler' => array_map(
        // Ilk paragraf da gidiyor: "Galatasaray'a gol atti" gibi ek hatalari
        // ancak oyuncunun hangi takimda oldugu bilinince yakalaniyor.
        static fn (array $s): array => [
            'id'     => (int) $s['id'],
            'baslik' => (string) $s['baslik'],
            'ozet'   => (string) $s['ozet'],
            'giris'  => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_split('/\n\s*\n|<\/p>/u', (string) $s['icerik'])[0]))), 0, 400),
        ],
        $ifade->fetchAll()
    )]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri = json_decode((string) file_get_contents('php://input'), true);

$oku      = db()->prepare('SELECT baslik FROM haberler WHERE id = :id');
$duzelt   = db()->prepare('UPDATE haberler SET eski_baslik = baslik, baslik = :b, baslik_denetlendi = NOW() WHERE id = :id');
$isaretle = db()->prepare('UPDATE haberler SET baslik_denetlendi = NOW() WHERE id = :id');
$duzeltilen = 0;

require_once __DIR__ . '/../includes/adres.php';
require_once __DIR__ . '/../includes/arama_bildirim.php';

foreach ((array) ($veri['basliklar'] ?? []) as $b) {
    if (!is_array($b) || (int) ($b['id'] ?? 0) <= 0) {
        continue;
    }

    $id   = (int) $b['id'];
    $yeni = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($b['yeni'] ?? ''))) ?? '');
    $oku->execute(['id' => $id]);
    $eski = $oku->fetchColumn();

    if ($eski === false) {
        continue;
    }

    // Yeni baslik gercekten farkliysa ve makul uzunluktaysa yaziliyor.
    $uzunluk = mb_strlen($yeni, 'UTF-8');

    if ($yeni !== '' && $uzunluk >= 15 && $uzunluk <= 110 && $yeni !== (string) $eski) {
        $duzelt->execute(['b' => $yeni, 'id' => $id]);
        $duzeltilen += $duzelt->rowCount();
        arama_bildir_kayit('haber', $id);
    } else {
        $isaretle->execute(['id' => $id]);
    }
}

ajan_json(200, ['duzeltilen' => $duzeltilen]);
