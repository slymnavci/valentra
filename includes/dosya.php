<?php
declare(strict_types=1);

/**
 * "Valentra Dosya": ajanın modelle yazdığı açıklayıcı yazılar.
 *
 * Site sahibinin istegi (28.10.2026, ozgun haber onerilerinin hepsi):
 * Search Console'daki firsat sorgularina ("cok gosterim, az tiklama")
 * cevap veren "nedir, nasil" yazisi; YALNIZCA sitede o konuda en az iki
 * haber varsa ve yalnizca o haberlerden. Birden cok yayindan olay
 * derlemesi ayri: includes/derleme.php.
 *
 * Ajan (ajan/aciklayici.php) malzemeyi buradan aliyor, yazdigini buraya
 * gonderiyor. Yazilar HER ZAMAN taslak (guven 70, otomatik yayin esiginin
 * altinda): modelin yazdigi metin panelde onaylanmadan yayina girmez.
 * Veri haberleri (includes/ozgun_haber.php) ise sablonla yazildigi icin
 * dogrudan yayinda.
 */

require_once __DIR__ . '/haberler.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/ayarlar.php';

const DOSYA_TURLERI       = ['aciklayici'];
const DOSYA_GUNLUK_TAVAN  = ['aciklayici' => 1];
const DOSYA_MODEL_ONEKI   = 'valentra-dosya';

/**
 * Ajanın malzemesi.
 *
 * @return array<string,mixed>
 */
function dosya_malzeme(): array
{
    return [
        'bugun'     => date('Y-m-d'),
        'sayilar'   => dosya_bugun_sayilari(),
        'tavan'     => DOSYA_GUNLUK_TAVAN,
        'onceki'    => dosya_onceki(14),
        'firsatlar' => dosya_firsatlari(),
    ];
}

/** Bugün yazılmış (reddedilmemiş) dosya haberi sayıları: tur => adet. */
function dosya_bugun_sayilari(): array
{
    $ifade = db()->prepare(
        'SELECT ajan_modeli, COUNT(*) FROM haberler
          WHERE ajan_modeli LIKE :m AND olusturuldu >= CURDATE() AND durum <> :r
          GROUP BY ajan_modeli'
    );
    $ifade->execute(['m' => DOSYA_MODEL_ONEKI . '%', 'r' => HABER_REDDEDILDI]);

    $sayilar = array_fill_keys(DOSYA_TURLERI, 0);

    foreach ($ifade->fetchAll(PDO::FETCH_KEY_PAIR) as $model => $adet) {
        $tur = substr((string) $model, strlen(DOSYA_MODEL_ONEKI) + 1);

        if (isset($sayilar[$tur])) {
            $sayilar[$tur] = (int) $adet;
        }
    }

    return $sayilar;
}

/** Son günlerin dosya başlıkları (aynı konu tekrar yazılmasın). */
function dosya_onceki(int $gun): array
{
    $ifade = db()->prepare(
        'SELECT baslik, ajan_modeli AS tur, DATE(olusturuldu) AS gun FROM haberler
          WHERE ajan_modeli LIKE :m AND olusturuldu >= NOW() - INTERVAL ' . max(1, $gun) . ' DAY
          ORDER BY id DESC LIMIT 60'
    );
    $ifade->execute(['m' => DOSYA_MODEL_ONEKI . '%']);

    return $ifade->fetchAll();
}

/**
 * Fırsat sorguları ve her birine sitede karşılık gelen haberler.
 *
 * Yalnizca en az iki ilgili haberi olan sorgu aday: aciklayici yazi
 * sitenin kendi haberlerinden yaziliyor, modelin genel bilgisinden degil.
 *
 * @return list<array{sorgu:string,gosterim:int,haberler:list<array<string,mixed>>}>
 */
function dosya_firsatlari(int $adet = 8): array
{
    require_once __DIR__ . '/search_console.php';

    $sonuc = [];

    foreach (gsc_firsatlar(gsc_tablo('gsc_sorgular')['satirlar'], 25) as $r) {
        $sorgu = trim((string) ($r['anahtar'][0] ?? ''));

        if ($sorgu === '') {
            continue;
        }

        $kelimeler = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($sorgu, 'UTF-8')) ?: [],
            static fn (string $k): bool => mb_strlen($k, 'UTF-8') >= 3
        ));

        if ($kelimeler === []) {
            continue;
        }

        $kosul = [];
        $par   = ['d' => HABER_YAYINDA];

        foreach (array_slice($kelimeler, 0, 4) as $i => $k) {
            $kosul[] = '(h.baslik LIKE :k' . $i . ' OR h.ozet LIKE :k' . $i . ' OR h.etiketler LIKE :k' . $i . ')';
            $par['k' . $i] = '%' . $k . '%';
        }

        $ifade = db()->prepare(
            'SELECT h.id, h.baslik, h.ozet, LEFT(h.icerik, 2500) AS icerik, h.kaynak_adi, h.kaynak_url,
                    COALESCE(h.kaynak_tarihi, h.yayin_tarihi) AS tarih
               FROM haberler h
              WHERE h.durum = :d AND ' . implode(' AND ', $kosul) . '
              ORDER BY h.yayin_tarihi DESC LIMIT 6'
        );
        $ifade->execute($par);
        $haberler = $ifade->fetchAll();

        if (count($haberler) >= 2) {
            $sonuc[] = ['sorgu' => $sorgu, 'gosterim' => (int) $r['gosterim'], 'haberler' => $haberler];
        }

        if (count($sonuc) >= $adet) {
            break;
        }
    }

    return $sonuc;
}

/**
 * Ajanın yazdığı dosya haberini TASLAK olarak kaydeder.
 *
 * @param array<string,mixed> $v {tur, baslik, ozet, icerik, kategori, haber_idleri, onem, editor_notu}
 * @return array{durum:string,id:?int}
 */
function dosya_kaydet(array $v): array
{
    $tur = (string) ($v['tur'] ?? '');

    if (!in_array($tur, DOSYA_TURLERI, true)) {
        throw new InvalidArgumentException('Bilinmeyen tür: ' . $tur);
    }

    if (dosya_bugun_sayilari()[$tur] >= DOSYA_GUNLUK_TAVAN[$tur]) {
        return ['durum' => 'tavan', 'id' => null];
    }

    $baslik = trim((string) ($v['baslik'] ?? ''));
    $icerik = trim((string) ($v['icerik'] ?? ''));

    if (mb_strlen($baslik, 'UTF-8') < 10 || mb_strlen($icerik, 'UTF-8') < 600) {
        throw new InvalidArgumentException('Başlık ya da metin çok kısa.');
    }

    $idler = array_slice(array_values(array_filter(array_map('intval', (array) ($v['haber_idleri'] ?? [])))), 0, 12);

    // Kaynak adresi: dosyanin kendi kimligi (tur + gun + baslik); kopya
    // denetimi adrese de bakiyor.
    $kimlik = $tur . '-' . date('Y-m-d') . '-' . substr(sha1($baslik), 0, 10);

    // Gorsel: dayandigi haberlerden gorseli olan ilki.
    $gorsel = '';

    if ($idler !== []) {
        $g = db()->query('SELECT gorsel_url FROM haberler WHERE id IN (' . implode(',', $idler) . ') AND COALESCE(gorsel_url, \'\') <> \'\' LIMIT 1')->fetchColumn();
        $gorsel = is_string($g) ? $g : '';
    }

    $kayit = haber_taslak_ekle([
        'gorsel_url'    => $gorsel,
        'baslik'        => $baslik,
        'ozet'          => (string) ($v['ozet'] ?? ''),
        'icerik'        => $icerik,
        'kategori'      => (string) ($v['kategori'] ?? 'gundem'),
        'kaynak_adi'    => 'Valentra Dosya',
        'kaynak_url'    => site_adresi() . '/?dosya=' . rawurlencode($kimlik),
        'kaynak_baslik' => $baslik,
        'kaynak_zamani' => time(),
        // Esigin (80) altinda: otomatik yayina girmez, panelde onay bekler.
        'guven_skoru'   => 70,
        'onem'          => max(40, min(85, (int) ($v['onem'] ?? 65))),
        'model'         => DOSYA_MODEL_ONEKI . '-' . $tur,
        'etiketler'     => (string) ($v['etiketler'] ?? ''),
        'ajan_notu'     => mb_substr('Valentra Dosya (' . $tur . '), dayandığı haberler: ' . implode(', ', $idler) . '. '
                            . trim((string) ($v['editor_notu'] ?? '')), 0, 600, 'UTF-8'),
    ]);

    return $kayit;
}
