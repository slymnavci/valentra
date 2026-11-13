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
 *   GET  ?gun=3&adet=80 -> {"haberler": [{"id","baslik","ozet","kategori","gorsel","yedek","ara"?}],
 *                           ara: logo denetimi yok, dogrudan uygun gorsel aranacak (gorselsiz /
 *                           konu gorselli / Infobae haberi); bulunamazsa {"temiz": false} -> konu gorseli
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
require_once __DIR__ . '/../includes/ayarlar.php';

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
    /*
     * Bir kez: son 3 gunun gorselleri yeniden denetlensin (08.11.2026).
     * #300 geri almasi logolu gorselleri ozgunlerine dondurup "denetlendi"
     * (1) isaretledi; logolu A Haber/NTV gorselleri boylece bir daha
     * denetlenmiyordu. Ozgur lisansli gorselle degisenler (2) dokunulmuyor.
     */
    if (ayar_oku('gorsel_yeniden_denetim_v2') === '') {
        ayar_yaz('gorsel_yeniden_denetim_v2', date('Y-m-d H:i:s'));
        db()->exec(
            "UPDATE haberler SET gorsel_denetim = NULL, guncellendi = guncellendi
              WHERE gorsel_denetim IN (0, 1, 3) AND olusturuldu >= NOW() - INTERVAL 3 DAY"
        );
    }

    /*
     * Bir kez (09.11.2026, site sahibi: "Infobae'den gelen haberler resimsiz
     * ya da Infobae resimli geliyor; yerine konuya uygun bir resim bulalim"):
     * son 3 gunun Infobae haberleri ve genel konu gorseli tasiyan haberler
     * yeniden listelensin; ajan bunlar icin dogrudan uygun gorsel ariyor.
     */
    if (ayar_oku('gorsel_ara_infobae_v1') === '') {
        ayar_yaz('gorsel_ara_infobae_v1', date('Y-m-d H:i:s'));
        db()->exec(
            "UPDATE haberler SET gorsel_denetim = NULL, guncellendi = guncellendi
              WHERE gorsel_denetim IN (0, 1, 3) AND olusturuldu >= NOW() - INTERVAL 3 DAY
                AND (kaynak_adi LIKE '%infobae%' OR gorsel_url LIKE '%infobae.com%'
                     OR gorsel_url LIKE '%/assets/kategori/%')"
        );
    }

    /*
     * Bir kez (09.11.2026): haber kanali/ajans kaynakli (ekran goruntusu)
     * ozgur gorselle degismis haberler yeniden aransin (ajan bu adaylari
     * artik almiyor; bkz. HABER_KURULUSU_DESENI). Eski gorseline donup
     * listeye giriyor.
     */
    if (ayar_oku('gorsel_kurulus_atif_v1') === '') {
        ayar_yaz('gorsel_kurulus_atif_v1', date('Y-m-d H:i:s'));
        $izler = ['Todo Noticias', 'entrevista_de_TN', 'CNN', 'BBC', 'Reuters', 'AFP', 'Getty', 'teleSUR', 'Euronews',
                  'Al Jazeera', 'Fox News', 'Sky News', 'Infobae', 'Televisa', 'TV Azteca', 'screenshot', 'captura'];
        $kosul = implode(' OR ', array_map(
            static fn (string $iz): string => '(gorsel_atif LIKE ' . db()->quote('%' . $iz . '%')
                . ' OR gorsel_url LIKE ' . db()->quote('%' . $iz . '%') . ')',
            $izler
        ));
        db()->exec(
            "UPDATE haberler SET gorsel_url = IF(gorsel_eski <> '', gorsel_eski, gorsel_url), gorsel_atif = '',
                    gorsel_denetim = NULL, guncellendi = guncellendi
              WHERE gorsel_denetim = 2 AND olusturuldu >= NOW() - INTERVAL 14 DAY AND (" . $kosul . ')'
        );
    }

    /*
     * Bir kez (13.11.2026, site sahibi: "milli takimin Belcika'ya yenildigi
     * macta neden gokyuzu resmi var?"): son 3 gunun, ozgur lisansli gorselle
     * degismis SPOR haberleri eski gorseline donup yeniden listelensin. Ajan
     * artik mac haberinde stadyum/bina yerine oyuncu ve takim ariyor ve
     * kadrajin cogu gokyuzu olan gorseli almiyor (bkz. GorselDenetci).
     */
    if (ayar_oku('gorsel_spor_kadraj_v1') === '') {
        ayar_yaz('gorsel_spor_kadraj_v1', date('Y-m-d H:i:s'));
        db()->exec(
            "UPDATE haberler SET gorsel_url = IF(gorsel_eski <> '', gorsel_eski, gorsel_url), gorsel_atif = '',
                    gorsel_denetim = NULL, guncellendi = guncellendi
              WHERE gorsel_denetim = 2 AND olusturuldu >= NOW() - INTERVAL 3 DAY
                AND kategori_id IN (SELECT id FROM kategoriler
                                     WHERE slug = 'spor'
                                        OR ust_id IN (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'spor') AS s))"
        );
    }

    $gun  = max(1, min(60, (int) ($_GET['gun'] ?? 3)));
    $adet = max(1, min(400, (int) ($_GET['adet'] ?? 80)));

    // Once ana sayfadakiler: ana sayfa (one_cikan, yayin_tarihi) sirasinda.
    $ifade = db()->prepare(
        "SELECT h.id, h.baslik, h.ozet, LEFT(h.icerik, 1200) AS metin, h.gorsel_url, h.durum, h.kaynak_adi, k.slug AS kategori
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.gorsel_denetim IS NULL
            AND h.durum IN ('yayinda', 'taslak')
            AND h.olusturuldu >= NOW() - INTERVAL :gun DAY
          ORDER BY h.durum = 'yayinda' DESC, h.one_cikan DESC, COALESCE(h.yayin_tarihi, h.olusturuldu) DESC
          LIMIT 1200"
    );
    $ifade->execute(['gun' => $gun]);

    $liste = [];
    $kendi = [];

    $araSayisi = 0;

    foreach ($ifade->fetchAll() as $s) {
        $url = (string) $s['gorsel_url'];

        /*
         * Dogrudan uygun gorsel aranacaklar ("ara"; 09.11.2026):
         *   - gorselsiz haber ya da genel konu gorseli (/assets/kategori/)
         *     tasiyan haber: konuya uygun ozgur lisansli fotograf aransin;
         *   - Infobae haberi: kaynagin gorseli (yoksa logolu yer tutucusu)
         *     kullanilmiyor, yerine uygun fotograf; bulunamazsa konu gorseli.
         */
        $konuGorseli = $url === '' || str_contains($url, '/assets/kategori/');
        $infobae     = stripos((string) $s['kaynak_adi'], 'infobae') !== false || stripos($url, 'infobae.com') !== false;
        $ara         = $konuGorseli || $infobae;

        // Sitenin kendi gorseli (ozgun veri) denetim istemiyor; bir daha listelenmesin.
        if (!$ara && $kendiGorseli($url)) {
            $kendi[] = (int) $s['id'];
            continue;
        }

        // Arama yavas (sorgu + indirme + denetim): bir calismada en fazla 30.
        if (count($liste) >= $adet || ($ara && $araSayisi >= 30)) {
            continue;
        }

        if ($ara) {
            $araSayisi++;
            $liste[] = [
                'id'       => (int) $s['id'],
                'baslik'   => (string) $s['baslik'],
                'ozet'     => mb_substr((string) $s['ozet'], 0, 300, 'UTF-8'),
                // Haberin konusu (11.11.2026, site sahibi: "sadece basliga gore degil
                // konuya gore resim secilsin"): arama sozcukleri metinden cikiyor.
                'metin'    => mb_substr((string) ($s['metin'] ?? ''), 0, 900, 'UTF-8'),
                'kategori' => (string) ($s['kategori'] ?? ''),
                'gorsel'   => $konuGorseli ? '' : $url,
                'yedek'    => '',
                'ara'      => true,
                'neden'    => $infobae ? 'Infobae' : 'görselsiz',
            ];
            continue;
        }

        $liste[] = [
            'id'       => (int) $s['id'],
            'baslik'   => (string) $s['baslik'],
            'ozet'     => mb_substr((string) $s['ozet'], 0, 300, 'UTF-8'),
            'metin'    => mb_substr((string) ($s['metin'] ?? ''), 0, 900, 'UTF-8'),
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

$sayac = ['temiz' => 0, 'degisen' => 0, 'korunan' => 0, 'konu_gorseli' => 0];

/*
 * "koru": logolu ama yerine uygun fotograf bulunamadi; haberin kendi
 * fotografi kaliyor (#300 sonrasi: gercek fotograflar gorunsun). Konu
 * gorseli konmuyor. 3: bir daha listelenmesin.
 */
$koru = db()->prepare('UPDATE haberler SET gorsel_denetim = 3, guncellendi = guncellendi WHERE id = :id');

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

    if (!empty($s['koru']) && trim((string) ($s['yeni'] ?? '')) === '') {
        $koru->execute(['id' => $id]);
        $sayac['korunan']++;
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
