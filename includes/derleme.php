<?php
declare(strict_types=1);

/**
 * Derleme haberler (site sahibinin karari, 28.10.2026).
 *
 * Tek bir kaynagin haberinin ozeti Google'a gore aslinin kopyasi ve
 * dizine alinmiyor (bkz. HABER_YALNIZCA_OZGUN_DIZINDE). Derleme haber,
 * ayni olayi veren BIRDEN COK kaynagin haberlerinden Valentra'nin yazdigi
 * ozgun haber: butun kaynaklarin bilgisini tek yerde topluyor, arka plan,
 * "ne anlama geliyor" ve sik sorulan sorular ekliyor. Google'a yalnizca
 * bunlar sunuluyor.
 *
 * Derlemeler haberler tablosunda (derleme = 1) duruyor: ana sayfa, konu
 * sayfalari, panel onayi ve site haritalari ayni altyapiyi kullaniyor.
 * Ajan (ajan/derle.php) yaziyor, api/derleme.php uzerinden TASLAK olarak
 * gonderiyor; yayina alma panelde. Guven puani 0 ve kaynak tarihi bos
 * oldugu icin otomatik yayin (haber_otomatik_yayinla) derlemeyi almiyor.
 */

require_once __DIR__ . '/haberler.php';

/** Günde en fazla bu kadar derleme (reddedilen sayılmaz). */
const DERLEME_GUNLUK_TAVAN = 20;

/** Bir derlemenin dayanması gereken en az farklı kaynak. */
const DERLEME_ASGARI_KAYNAK = 2;

/** Kabul edilecek en kısa derleme metni (karakter, ~250 kelime). */
const DERLEME_ASGARI_METIN = 1500;

/**
 * Derlemeye malzeme: son saatlerin haberleri (derlemeler hariç).
 *
 * Son uc gunun derlemelerinde kullanilmis haberler disarida: ayni olay
 * iki kez derlenmesin (yeni gelisme yeni haberle gelir).
 *
 * @return list<array<string,mixed>>
 */
function derleme_malzeme(int $saat = 24, int $limit = 250): array
{
    $kullanilan = derleme_kullanilan_idler(3);

    $ifade = db()->prepare(
        'SELECT h.id, h.baslik, h.ozet, h.icerik, h.kaynak_adi, h.kaynak_url, h.onem,
                h.analiz_degisen, h.analiz_etkilenen, h.analiz_zaman, h.analiz_islem, h.resmi_dayanak,
                COALESCE(h.kaynak_tarihi, h.yayin_tarihi, h.olusturuldu) AS tarih,
                k.ad AS kategori, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum IN (:y, :t)
            AND h.derleme = 0
            AND h.kaynak_url <> \'\'
            AND COALESCE(h.yayin_tarihi, h.olusturuldu) >= :sinir
          ORDER BY h.onem DESC, COALESCE(h.yayin_tarihi, h.olusturuldu) DESC
          LIMIT ' . max(1, $limit)
    );
    $ifade->execute([
        'y'     => HABER_YAYINDA,
        't'     => HABER_TASLAK,
        'sinir' => date('Y-m-d H:i:s', time() - $saat * 3600),
    ]);

    $sonuc = [];

    foreach ($ifade->fetchAll() as $h) {
        if (isset($kullanilan[(int) $h['id']])) {
            continue;
        }

        $h['id']     = (int) $h['id'];
        $h['onem']   = (int) $h['onem'];
        $h['icerik'] = mb_substr((string) $h['icerik'], 0, 5000, 'UTF-8');
        // Yayinin adi ("NTV Spor" -> "NTV"): ajan farkli kaynagi buna gore sayiyor.
        $h['yayin']  = kaynak_gorunen_ad((string) $h['kaynak_adi']);
        $sonuc[]     = $h;
    }

    return $sonuc;
}

/**
 * Son günlerin derlemelerinde kullanılan haber numaraları.
 *
 * @return array<int,true>
 */
function derleme_kullanilan_idler(int $gun = 3): array
{
    $ifade = db()->prepare(
        'SELECT derleme_kaynaklari FROM haberler
          WHERE derleme = 1 AND durum <> :r AND olusturuldu >= NOW() - INTERVAL ' . max(1, $gun) . ' DAY'
    );
    $ifade->execute(['r' => HABER_REDDEDILDI]);

    $idler = [];

    foreach ($ifade->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (derleme_kaynaklari_coz((string) $json) as $k) {
            if ($k['id'] > 0) {
                $idler[$k['id']] = true;
            }
        }
    }

    return $idler;
}

/**
 * Son günlerin derleme başlıkları: ajan aynı olayı yeniden derlemesin.
 *
 * @return list<array{tarih:string,baslik:string}>
 */
function derleme_onceki(int $gun = 3): array
{
    $ifade = db()->prepare(
        'SELECT olusturuldu, baslik FROM haberler
          WHERE derleme = 1 AND durum <> :r AND olusturuldu >= NOW() - INTERVAL ' . max(1, $gun) . ' DAY
          ORDER BY olusturuldu DESC
          LIMIT 80'
    );
    $ifade->execute(['r' => HABER_REDDEDILDI]);

    return array_map(static fn (array $s): array => [
        'tarih'  => substr((string) $s['olusturuldu'], 0, 16),
        'baslik' => (string) $s['baslik'],
    ], $ifade->fetchAll());
}

/** Bugün (İstanbul gece yarısından beri) reddedilmemiş kaç derleme var. */
function derleme_bugun_sayisi(): int
{
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM haberler
          WHERE derleme = 1 AND durum <> :r
            AND olusturuldu >= NOW() - INTERVAL ' . haber_bugun_dakika() . ' MINUTE'
    );
    $ifade->execute(['r' => HABER_REDDEDILDI]);

    return (int) $ifade->fetchColumn();
}

/**
 * derleme_kaynaklari sütununu çözer.
 *
 * @return list<array{id:int,ad:string,url:string,baslik:string}>
 */
function derleme_kaynaklari_coz(string $json): array
{
    $veri = json_decode($json, true);

    if (!is_array($veri)) {
        return [];
    }

    $sonuc = [];

    foreach ($veri as $k) {
        if (!is_array($k) || trim((string) ($k['ad'] ?? '')) === '') {
            continue;
        }

        $sonuc[] = [
            'id'     => (int) ($k['id'] ?? 0),
            'ad'     => (string) $k['ad'],
            'url'    => (string) ($k['url'] ?? ''),
            'baslik' => (string) ($k['baslik'] ?? ''),
        ];
    }

    return $sonuc;
}

/**
 * Ajanın yazdığı derlemeyi TASLAK olarak kaydeder.
 *
 * Kaynaklar ajanin sozune degil veritabanina dayaniyor: ajan yalnizca
 * haber numaralarini gonderiyor, kaynak adi ve adresi buradan aliniyor.
 * En az iki farkli kaynak sart; degilse derleme degil, tek kaynagin
 * ozeti olur.
 *
 * @param array<string,mixed> $veri
 * @return array{durum:string,id:int}
 */
function derleme_taslak_ekle(array $veri): array
{
    $baslik = trim((string) ($veri['baslik'] ?? ''));
    $icerik = trim((string) ($veri['icerik'] ?? ''));

    if (mb_strlen($baslik, 'UTF-8') < 10) {
        throw new InvalidArgumentException('Başlık çok kısa.');
    }

    if (mb_strlen($icerik, 'UTF-8') < DERLEME_ASGARI_METIN) {
        throw new InvalidArgumentException('Metin çok kısa (en az ' . DERLEME_ASGARI_METIN . ' karakter).');
    }

    if (derleme_bugun_sayisi() >= DERLEME_GUNLUK_TAVAN) {
        throw new InvalidArgumentException('Bugünün derleme tavanı (' . DERLEME_GUNLUK_TAVAN . ') dolu.');
    }

    $idler = [];

    foreach ((array) ($veri['haber_idleri'] ?? []) as $id) {
        if (is_numeric($id) && (int) $id > 0) {
            $idler[(int) $id] = (int) $id;
        }
    }

    $idler = array_slice(array_values($idler), 0, 12);

    if ($idler === []) {
        throw new InvalidArgumentException('Kaynak haber numarası yok.');
    }

    $yer   = implode(',', array_fill(0, count($idler), '?'));
    $ifade = db()->prepare(
        'SELECT id, baslik, kaynak_baslik, kaynak_adi, kaynak_url, gorsel_url, kategori_id, onem
           FROM haberler WHERE id IN (' . $yer . ') AND derleme = 0'
    );
    $ifade->execute($idler);
    $kaynakHaberler = $ifade->fetchAll();

    // Ajanin verdigi sirayla (en cok yararlanilan once).
    $sira = array_flip($idler);
    usort($kaynakHaberler, static fn (array $a, array $b): int => $sira[(int) $a['id']] <=> $sira[(int) $b['id']]);

    $kaynaklar = [];
    $adlar     = [];

    foreach ($kaynakHaberler as $k) {
        $ad = trim((string) $k['kaynak_adi']);

        if ($ad === '') {
            continue;
        }

        // "NTV Spor" ile "NTV Ekonomi" ayni yayin: farkli kaynak sayilmaz.
        $adlar[mb_strtolower(kaynak_gorunen_ad($ad), 'UTF-8')] = true;
        $kaynaklar[] = [
            'id'     => (int) $k['id'],
            'ad'     => $ad,
            'url'    => (string) $k['kaynak_url'],
            'baslik' => trim((string) $k['kaynak_baslik']) !== '' ? (string) $k['kaynak_baslik'] : (string) $k['baslik'],
        ];
    }

    if (count($adlar) < DERLEME_ASGARI_KAYNAK) {
        throw new InvalidArgumentException('En az ' . DERLEME_ASGARI_KAYNAK . ' farklı kaynak gerekli (' . count($adlar) . ' var).');
    }

    // Gorsel ve baslik (kategori): en onemli kaynaktan; model kategori verdiyse o.
    $gorsel     = '';
    $kategoriId = null;
    $onem       = 0;

    foreach ($kaynakHaberler as $k) {
        $onem = max($onem, (int) $k['onem']);

        if ($gorsel === '' && trim((string) $k['gorsel_url']) !== '') {
            $gorsel = (string) $k['gorsel_url'];
        }

        if ($kategoriId === null && $k['kategori_id'] !== null) {
            $kategoriId = (int) $k['kategori_id'];
        }
    }

    $sonuc = haber_taslak_ekle([
        'baslik'        => $baslik,
        'icerik'        => $icerik,
        'ozet'          => (string) ($veri['ozet'] ?? ''),
        'gorsel_url'    => $gorsel,
        'etiketler'     => (string) ($veri['etiketler'] ?? ''),
        'kategori'      => (string) ($veri['kategori'] ?? ''),
        'kaynak_url'    => '',
        'kaynak_adi'    => '',
        // Guven 0 ve kaynak zamani yok: otomatik yayin derlemeyi almaz,
        // panelde onay bekler (bkz. haber_guven_esigi_kosulu).
        'guven_skoru'   => 0,
        // Birden cok kaynagin verdigi olay: en onemli kaynaktan biraz yukarida.
        'onem'          => min(100, max((int) ($veri['onem'] ?? 0), $onem + 5)),
        'ajan_notu'     => (string) ($veri['ajan_notu'] ?? ''),
        'model'         => (string) ($veri['model'] ?? ''),
        'neden_onemli'  => (string) ($veri['neden_onemli'] ?? ''),
        'resmi_dayanak' => (string) ($veri['resmi_dayanak'] ?? ''),
    ]);

    if ($sonuc['durum'] !== 'eklendi') {
        return $sonuc;
    }

    db()->prepare(
        'UPDATE haberler
            SET derleme = 1, derleme_kaynaklari = :k, kategori_id = COALESCE(kategori_id, :kat)
          WHERE id = :id'
    )->execute([
        'k'   => json_encode($kaynaklar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'kat' => $kategoriId,
        'id'  => $sonuc['id'],
    ]);

    return $sonuc;
}
