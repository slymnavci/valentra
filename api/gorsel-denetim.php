<?php
declare(strict_types=1);

/**
 * Haber görsellerinde haber sitesi adı / logosu denetimi (ajan/gorsel_denetle.php).
 *
 * Site sahibinin karari (08.11.2026): "ana sayfada bir haberdeki resimde
 * kesinlikle bir haber sitesinin adi olmayacak. Ozgun resimler kullanalim;
 * uygun resim yoksa internetten konuya uygun, haber sitesi adi olmayan bir
 * resim bulup yerlestirelim."
 *
 * Ajan kaynaktan gelen her gorseli yapay zekaya bakturiyor. Haber sitesi,
 * TV kanali, gazete ya da ajans adi, logosu, filigrani yoksa temiz
 * isaretleniyor. Varsa ajan ozgur lisansli bir gorsel ariyor (Openverse,
 * Wikimedia Commons; o gorsel de ayni denetimden geciyor) ve buraya
 * gonderiyor; bulamazsa haberin konu gorseli (assets/kategori) konuyor.
 *
 *   GET  ?gun=3&adet=80 -> {"haberler": [{"id","baslik","ozet","kategori","gorsel","yedek"}],
 *                           "kullanilan": [son iki haftada yerine konmus adresler]}
 *   POST {"sonuclar": [{"id": 1, "temiz": true}
 *                     | {"id": 2, "temiz": false, "yeni": "https://...", "atif": "...", "bulunan": "AA logosu"}
 *                     | {"id": 3, "temiz": false, "bulunan": "..."}]}
 *        -> {"temiz": n, "degisen": n, "konu_gorseli": n}
 *
 * Denetlenmemis gorsel ana sayfada gosterilmiyor (bkz.
 * haber_gorsel_denetimli_mi). Degisen gorselin eski adresi gorsel_eski'de.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/seo.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Sitenin kendi görseli mi (konu görseli, özgün veri görselleri)? */
$kendiGorseli = static function (string $url): bool {
    foreach ([rtrim(site_adresi(), '/'), 'https://valentra.com.tr', 'https://www.valentra.com.tr'] as $taban) {
        if (str_starts_with($url, $taban . '/assets/')) {
            return true;
        }
    }

    return str_starts_with($url, '/assets/');
};

if ($yontem === 'GET') {
    $gun  = max(1, min(60, (int) ($_GET['gun'] ?? 3)));
    $adet = max(1, min(400, (int) ($_GET['adet'] ?? 80)));

    // Once ana sayfadakiler: ana sayfa (one_cikan, yayin_tarihi) sirasinda.
    $ifade = db()->prepare(
        "SELECT h.id, h.baslik, h.ozet, h.gorsel_url, h.durum, k.slug AS kategori
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.gorsel_denetim IS NULL
            AND COALESCE(h.gorsel_url, '') <> ''
            AND h.durum IN ('yayinda', 'taslak')
            AND h.olusturuldu >= NOW() - INTERVAL :gun DAY
          ORDER BY h.durum = 'yayinda' DESC, h.one_cikan DESC, COALESCE(h.yayin_tarihi, h.olusturuldu) DESC
          LIMIT 1200"
    );
    $ifade->execute(['gun' => $gun]);

    $liste = [];
    $kendi = [];

    foreach ($ifade->fetchAll() as $s) {
        $url = (string) $s['gorsel_url'];

        // Sitenin kendi gorseli denetim istemiyor; bir daha listelenmesin.
        if ($kendiGorseli($url)) {
            $kendi[] = (int) $s['id'];
            continue;
        }

        if (count($liste) >= $adet) {
            continue;
        }

        $liste[] = [
            'id'       => (int) $s['id'],
            'baslik'   => (string) $s['baslik'],
            'ozet'     => mb_substr((string) $s['ozet'], 0, 300, 'UTF-8'),
            'kategori' => (string) ($s['kategori'] ?? ''),
            'gorsel'   => $url,
            // Kaynak gorseli vermezse sitenin onbellekteki kopyasi.
            'yedek'    => rtrim(site_adresi(), '/') . '/gorsel.php?id=' . (int) $s['id'] . '&g=960',
        ];
    }

    if ($kendi !== []) {
        db()->exec('UPDATE haberler SET gorsel_denetim = 1, guncellendi = guncellendi WHERE id IN (' . implode(',', $kendi) . ')');
    }

    // Son iki haftada logolu gorselin yerine konanlar: ajan ayni gorseli
    // ikinci bir habere koymasin.
    $kullanilan = db()->query(
        "SELECT DISTINCT gorsel_url FROM haberler
          WHERE gorsel_denetim = 2 AND olusturuldu >= NOW() - INTERVAL 14 DAY"
    )->fetchAll(PDO::FETCH_COLUMN);

    ajan_json(200, ['haberler' => $liste, 'kullanilan' => array_map('strval', $kullanilan)]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$veri = json_decode((string) file_get_contents('php://input'), true);

$oku = db()->prepare(
    'SELECT h.gorsel_url, k.slug AS kategori FROM haberler h
       LEFT JOIN kategoriler k ON k.id = h.kategori_id WHERE h.id = :id'
);
$temizle = db()->prepare('UPDATE haberler SET gorsel_denetim = 1, guncellendi = guncellendi WHERE id = :id');
$degistir = db()->prepare(
    'UPDATE haberler
        SET gorsel_eski = :eski, gorsel_url = :yeni, gorsel_atif = :atif, gorsel_denetim = :durum,
            guncellendi = guncellendi
      WHERE id = :id'
);

$sayac = ['temiz' => 0, 'degisen' => 0, 'konu_gorseli' => 0];

foreach ((array) ($veri['sonuclar'] ?? []) as $s) {
    if (!is_array($s) || (int) ($s['id'] ?? 0) <= 0) {
        continue;
    }

    $id = (int) $s['id'];
    $oku->execute(['id' => $id]);
    $satir = $oku->fetch();

    if ($satir === false) {
        continue;
    }

    if (!empty($s['temiz'])) {
        $temizle->execute(['id' => $id]);
        $sayac['temiz']++;
        continue;
    }

    $eski = mb_substr((string) $satir['gorsel_url'], 0, 1000, 'UTF-8');
    $yeni = guvenli_url((string) ($s['yeni'] ?? ''));
    $atif = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($s['atif'] ?? '')))), 0, 300, 'UTF-8');

    if ($yeni !== '' && mb_strlen($yeni, 'UTF-8') <= 1000 && preg_match('#^https://#i', $yeni)) {
        $degistir->execute(['eski' => $eski, 'yeni' => $yeni, 'atif' => $atif, 'durum' => 2, 'id' => $id]);
        $sayac['degisen']++;
    } else {
        $degistir->execute([
            'eski'  => $eski,
            'yeni'  => haber_kategori_gorseli((string) ($satir['kategori'] ?? '')),
            'atif'  => '',
            'durum' => 0,
            'id'    => $id,
        ]);
        $sayac['konu_gorseli']++;
    }
}

ajan_json(200, $sayac);
