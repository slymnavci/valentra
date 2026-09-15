<?php
declare(strict_types=1);

/**
 * Haber veri katmani.
 *
 * Akis: ajan 'taslak' olarak yazar -> yonetici onaylar -> 'yayinda' olur ve
 * ana sayfada gorunur. Yayinda olmayan hicbir kayit herkese acik sayfalarda
 * sorgulanmaz.
 */

const HABER_TASLAK      = 'taslak';
const HABER_YAYINDA     = 'yayinda';
const HABER_REDDEDILDI  = 'reddedildi';

/**
 * Ayni haberin iki kez girmesini engelleyen parmak izi.
 * Kaynak URL varsa onu, yoksa basligi esas alir.
 */
function haber_parmak_izi(string $kaynakUrl, string $baslik): string
{
    $temel = $kaynakUrl !== ''
        ? mb_strtolower(trim($kaynakUrl), 'UTF-8')
        : 'baslik:' . mb_strtolower(trim(preg_replace('/\s+/u', ' ', $baslik) ?? ''), 'UTF-8');

    return hash('sha256', $temel);
}

/**
 * Slug'i benzersizlestirir: "vergi-duzenlemesi", "vergi-duzenlemesi-2", ...
 */
function haber_benzersiz_slug(string $baslik, ?int $haricId = null): string
{
    $temel = slug_uret($baslik);
    $aday  = $temel;
    $ek    = 1;

    while (true) {
        $sql = 'SELECT id FROM haberler WHERE slug = :slug';
        $par = ['slug' => $aday];

        if ($haricId !== null) {
            $sql .= ' AND id <> :haric';
            $par['haric'] = $haricId;
        }

        $ifade = db()->prepare($sql . ' LIMIT 1');
        $ifade->execute($par);

        if ($ifade->fetchColumn() === false) {
            return $aday;
        }

        $ek++;
        $aday = $temel . '-' . $ek;
    }
}

/**
 * Ajanin buldugu haberi taslak olarak kaydeder.
 *
 * @return array{durum:string,id:?int} durum: 'eklendi' | 'yinelenen'
 */
function haber_taslak_ekle(array $veri): array
{
    $baslik    = trim((string) ($veri['baslik'] ?? ''));
    $icerik    = trim((string) ($veri['icerik'] ?? ''));
    $kaynakUrl = guvenli_url((string) ($veri['kaynak_url'] ?? ''));

    if ($baslik === '' || $icerik === '') {
        throw new InvalidArgumentException('Baslik ve icerik zorunludur.');
    }

    $parmak = haber_parmak_izi($kaynakUrl, $baslik);

    $mevcut = db()->prepare('SELECT id FROM haberler WHERE kaynak_parmak = :parmak LIMIT 1');
    $mevcut->execute(['parmak' => $parmak]);
    $mevcutId = $mevcut->fetchColumn();

    if ($mevcutId !== false) {
        return ['durum' => 'yinelenen', 'id' => (int) $mevcutId];
    }

    $ozet = trim((string) ($veri['ozet'] ?? ''));
    if ($ozet === '') {
        $ozet = kisalt($icerik, 220);
    }

    $ifade = db()->prepare(
        'INSERT INTO haberler
            (baslik, slug, ozet, icerik, gorsel_url, etiketler, durum, kategori_id,
             kaynak_id, kaynak_adi, kaynak_url, kaynak_parmak, guven_skoru, ajan_notu)
         VALUES
            (:baslik, :slug, :ozet, :icerik, :gorsel_url, :etiketler, :durum, :kategori_id,
             :kaynak_id, :kaynak_adi, :kaynak_url, :kaynak_parmak, :guven_skoru, :ajan_notu)'
    );

    $ifade->execute([
        'baslik'        => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'          => haber_benzersiz_slug($baslik),
        'ozet'          => mb_substr($ozet, 0, 600, 'UTF-8'),
        'icerik'        => $icerik,
        'gorsel_url'    => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: null,
        'etiketler'     => mb_substr(trim((string) ($veri['etiketler'] ?? '')), 0, 400, 'UTF-8'),
        'durum'         => HABER_TASLAK,
        'kategori_id'   => kategori_id_cozumle((string) ($veri['kategori'] ?? '')),
        'kaynak_id'     => $veri['kaynak_id'] ?? null,
        'kaynak_adi'    => mb_substr(trim((string) ($veri['kaynak_adi'] ?? '')), 0, 160, 'UTF-8'),
        'kaynak_url'    => mb_substr($kaynakUrl, 0, 500, 'UTF-8'),
        'kaynak_parmak' => $parmak,
        'guven_skoru'   => max(0, min(100, (int) ($veri['guven_skoru'] ?? 0))),
        'ajan_notu'     => mb_substr(trim((string) ($veri['ajan_notu'] ?? '')), 0, 600, 'UTF-8'),
    ]);

    return ['durum' => 'eklendi', 'id' => (int) db()->lastInsertId()];
}

/**
 * Onay bekleyen taslaklar (admin paneli).
 */
function haber_bekleyenler(int $limit = 100): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.guven_skoru DESC, h.olusturuldu DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_TASLAK);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Duruma gore listeleme (admin paneli sekmeler).
 */
function haber_listele(string $durum, int $limit = 100): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY COALESCE(h.yayin_tarihi, h.guncellendi) DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', $durum);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_bul(int $id): ?array
{
    $ifade = db()->prepare('SELECT * FROM haberler WHERE id = :id LIMIT 1');
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Herkese acik sayfalar icin: yalnizca yayinda olan haberi slug ile getirir.
 */
function haber_yayinda_bul(string $slug): ?array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.slug = :slug AND h.durum = :durum LIMIT 1'
    );
    $ifade->execute(['slug' => $slug, 'durum' => HABER_YAYINDA]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Ana sayfa listesi: sadece yayinda olanlar, yeniden eskiye.
 */
function haber_yayindakiler(int $limit = 20, int $atla = 0): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.one_cikan DESC, h.yayin_tarihi DESC
          LIMIT :limit OFFSET :atla'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->bindValue('atla', $atla, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_yayinda_sayisi(): int
{
    $ifade = db()->prepare('SELECT COUNT(*) FROM haberler WHERE durum = :durum');
    $ifade->execute(['durum' => HABER_YAYINDA]);

    return (int) $ifade->fetchColumn();
}

function haber_durum_sayilari(): array
{
    $satirlar = db()->query(
        'SELECT durum, COUNT(*) AS adet FROM haberler GROUP BY durum'
    )->fetchAll();

    $sayilar = [HABER_TASLAK => 0, HABER_YAYINDA => 0, HABER_REDDEDILDI => 0];
    foreach ($satirlar as $satir) {
        $sayilar[$satir['durum']] = (int) $satir['adet'];
    }

    return $sayilar;
}

/**
 * Onay: haberi ana sayfaya alir.
 */
function haber_onayla(int $id, int $yoneticiId): bool
{
    $ifade = db()->prepare(
        'UPDATE haberler
            SET durum = :durum,
                onaylayan_id = :yonetici,
                onay_tarihi = NOW(),
                yayin_tarihi = COALESCE(yayin_tarihi, NOW())
          WHERE id = :id'
    );
    $ifade->execute(['durum' => HABER_YAYINDA, 'yonetici' => $yoneticiId, 'id' => $id]);

    return $ifade->rowCount() > 0;
}

/**
 * Yayindan geri cekme veya reddetme.
 */
function haber_durum_degistir(int $id, string $durum): bool
{
    if (!in_array($durum, [HABER_TASLAK, HABER_YAYINDA, HABER_REDDEDILDI], true)) {
        throw new InvalidArgumentException('Gecersiz durum.');
    }

    $ifade = db()->prepare('UPDATE haberler SET durum = :durum WHERE id = :id');
    $ifade->execute(['durum' => $durum, 'id' => $id]);

    return $ifade->rowCount() > 0;
}

/**
 * Yonetici duzenlemesi.
 */
function haber_guncelle(int $id, array $veri): bool
{
    $baslik = trim((string) ($veri['baslik'] ?? ''));
    $icerik = trim((string) ($veri['icerik'] ?? ''));

    if ($baslik === '' || $icerik === '') {
        throw new InvalidArgumentException('Baslik ve icerik bos birakilamaz.');
    }

    $ifade = db()->prepare(
        'UPDATE haberler
            SET baslik = :baslik,
                slug = :slug,
                ozet = :ozet,
                icerik = :icerik,
                gorsel_url = :gorsel_url,
                etiketler = :etiketler,
                one_cikan = :one_cikan,
                kategori_id = :kategori_id
          WHERE id = :id'
    );

    $ozet = trim((string) ($veri['ozet'] ?? ''));

    return $ifade->execute([
        'baslik'     => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'       => haber_benzersiz_slug($baslik, $id),
        'ozet'       => mb_substr($ozet !== '' ? $ozet : kisalt($icerik, 220), 0, 600, 'UTF-8'),
        'icerik'     => $icerik,
        'gorsel_url' => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: null,
        'etiketler'  => mb_substr(trim((string) ($veri['etiketler'] ?? '')), 0, 400, 'UTF-8'),
        'one_cikan'  => !empty($veri['one_cikan']) ? 1 : 0,
        'kategori_id'=> ($veri['kategori_id'] ?? '') !== '' ? (int) $veri['kategori_id'] : null,
        'id'         => $id,
    ]);
}

function haber_sil(int $id): bool
{
    $ifade = db()->prepare('DELETE FROM haberler WHERE id = :id');
    $ifade->execute(['id' => $id]);

    return $ifade->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Konu gruplari
// ---------------------------------------------------------------------------

/**
 * Ust menude gosterilecek aktif kategoriler.
 */
function kategori_listesi(bool $sadeceAktif = true): array
{
    $sql = 'SELECT * FROM kategoriler';

    if ($sadeceAktif) {
        $sql .= ' WHERE aktif = 1';
    }

    return db()->query($sql . ' ORDER BY sira, ad')->fetchAll();
}

/**
 * Ust menu agaci.
 *
 * Ust basliklar (ust_id'si olmayanlar) menude gorunur; altlarindaki
 * gruplar acilir listede listelenir. Her grubun yaninda yayindaki haber
 * sayisi gosterilir; ust basligin sayisi altlarinin toplamidir.
 *
 * @return list<array{ad:string,slug:string,adet:int,altlar:list<array<string,mixed>>}>
 */
function kategori_menusu(): array
{
    $satirlar = db()->query(
        'SELECT k.id, k.ad, k.slug, k.ust_id, k.sira,
                COUNT(h.id) AS adet
           FROM kategoriler k
           LEFT JOIN haberler h
             ON h.kategori_id = k.id AND h.durum = ' . db()->quote(HABER_YAYINDA) . '
          WHERE k.aktif = 1
          GROUP BY k.id, k.ad, k.slug, k.ust_id, k.sira
          ORDER BY k.sira, k.ad'
    )->fetchAll();

    $altlar = [];

    foreach ($satirlar as $satir) {
        if ($satir['ust_id'] !== null) {
            $altlar[(int) $satir['ust_id']][] = [
                'ad'   => $satir['ad'],
                'slug' => $satir['slug'],
                'adet' => (int) $satir['adet'],
            ];
        }
    }

    $menu = [];

    foreach ($satirlar as $satir) {
        if ($satir['ust_id'] !== null) {
            continue;
        }

        $cocuklar = $altlar[(int) $satir['id']] ?? [];

        $menu[] = [
            'ad'     => $satir['ad'],
            'slug'   => $satir['slug'],
            'adet'   => (int) $satir['adet'] + array_sum(array_column($cocuklar, 'adet')),
            'altlar' => $cocuklar,
        ];
    }

    return $menu;
}

function kategori_slug_bul(string $slug): ?array
{
    $ifade = db()->prepare('SELECT * FROM kategoriler WHERE slug = :slug AND aktif = 1 LIMIT 1');
    $ifade->execute(['slug' => $slug]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Slug'dan kategori id'si; bulunamazsa null.
 */
function kategori_id_cozumle(?string $slug): ?int
{
    if ($slug === null || trim($slug) === '') {
        return null;
    }

    $ifade = db()->prepare('SELECT id FROM kategoriler WHERE slug = :slug LIMIT 1');
    $ifade->execute(['slug' => trim($slug)]);
    $id = $ifade->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Bir kategorideki yayinda olan haberler.
 */
function haber_kategoride(int $kategoriId, int $limit = 20, int $atla = 0): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum AND h.kategori_id = :kategori
          ORDER BY h.yayin_tarihi DESC
          LIMIT :limit OFFSET :atla'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('kategori', $kategoriId, PDO::PARAM_INT);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->bindValue('atla', $atla, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_kategoride_sayi(int $kategoriId): int
{
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM haberler WHERE durum = :durum AND kategori_id = :kategori'
    );
    $ifade->execute(['durum' => HABER_YAYINDA, 'kategori' => $kategoriId]);

    return (int) $ifade->fetchColumn();
}

/**
 * Mansette kayacak haberler (en yeni, one cikanlar once).
 */
function haber_manset(int $limit = 8): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.one_cikan DESC, h.yayin_tarihi DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}
