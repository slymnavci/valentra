<?php
declare(strict_types=1);

/**
 * Köşe yazıları: "Valentra Diyor ki…"
 *
 * Ajan her gun gundemden 3-4 madde seciyor ve her biri icin ayri bir yazi
 * yaziyor (ajan/kose.php). Yazilar taslak olarak giriyor ve yalnizca
 * panelden yayina aliniyor: yorum iceren bir yazida yanlis bir rakam ya da
 * degerlendirme dogrudan okuyucuya ulasmamali.
 *
 * Durumlar haberlerle ayni (HABER_TASLAK / HABER_YAYINDA /
 * HABER_REDDEDILDI); panelde ayni rozetler ve ayni akis kullaniliyor.
 */

/** Bir günün en fazla kaç yazısı olabilir. */
const KOSE_GUNLUK_TAVAN = 4;

/** Yazının asgari uzunluğu (karakter). Kısa bir yazı köşe yazısı değildir. */
const KOSE_ASGARI_METIN = 800;

/**
 * Ajanın gönderdiği yazıyı taslak olarak ekler.
 *
 * @param array<string,mixed> $veri
 * @return array{durum:string,id:int}
 */
function kose_taslak_ekle(array $veri): array
{
    $baslik = trim((string) ($veri['baslik'] ?? ''));
    $icerik = trim((string) ($veri['icerik'] ?? ''));
    $ozet   = trim((string) ($veri['ozet'] ?? ''));
    $gundem = trim((string) ($veri['gundem'] ?? ''));
    $gun    = (string) ($veri['gun'] ?? '');

    if (mb_strlen($baslik, 'UTF-8') < 10) {
        throw new InvalidArgumentException('Başlık çok kısa.');
    }

    if (mb_strlen($icerik, 'UTF-8') < KOSE_ASGARI_METIN) {
        throw new InvalidArgumentException('Yazı metni çok kısa (en az ' . KOSE_ASGARI_METIN . ' karakter).');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun) || strtotime($gun) === false) {
        $gun = date('Y-m-d');
    }

    $idler = kose_id_listesi($veri['haber_idleri'] ?? []);

    $parmak = hash('sha256', $gun . '|' . mb_strtolower($baslik, 'UTF-8'));

    $var = db()->prepare('SELECT id FROM kose_yazilari WHERE parmak = :p LIMIT 1');
    $var->execute(['p' => $parmak]);
    $mevcut = $var->fetchColumn();

    if ($mevcut !== false) {
        return ['durum' => 'yinelenen', 'id' => (int) $mevcut];
    }

    $ekle = db()->prepare(
        'INSERT INTO kose_yazilari
            (gun, sira, gundem, baslik, slug, ozet, icerik, haber_idleri, ajan_notu, parmak, durum)
         VALUES
            (:gun, :sira, :gundem, :baslik, :slug, :ozet, :icerik, :idler, :not, :parmak, :durum)'
    );
    $ekle->execute([
        'gun'    => $gun,
        'sira'   => max(0, min(255, (int) ($veri['sira'] ?? 0))),
        'gundem' => mb_substr($gundem, 0, 200, 'UTF-8'),
        'baslik' => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'   => kose_benzersiz_slug($baslik),
        'ozet'   => mb_substr($ozet, 0, 600, 'UTF-8'),
        'icerik' => $icerik,
        'idler'  => implode(',', $idler),
        'not'    => mb_substr(trim((string) ($veri['ajan_notu'] ?? '')), 0, 600, 'UTF-8'),
        'parmak' => $parmak,
        'durum'  => HABER_TASLAK,
    ]);

    return ['durum' => 'eklendi', 'id' => (int) db()->lastInsertId()];
}

/**
 * Haber numaralarını temizler: yalnızca pozitif tamsayılar, tekrarsız.
 *
 * @return list<int>
 */
function kose_id_listesi(mixed $girdi): array
{
    if (is_string($girdi)) {
        $girdi = explode(',', $girdi);
    }

    if (!is_array($girdi)) {
        return [];
    }

    $idler = [];

    foreach ($girdi as $id) {
        if (is_numeric($id) && (int) $id > 0) {
            $idler[(int) $id] = (int) $id;
        }
    }

    // Sutun 400 karakter; 40 haber fazlasiyla yetiyor.
    return array_slice(array_values($idler), 0, 40);
}

function kose_benzersiz_slug(string $baslik, ?int $haricId = null): string
{
    $temel = slug_uret($baslik);
    $aday  = $temel;
    $ek    = 1;

    while (true) {
        $sql = 'SELECT id FROM kose_yazilari WHERE slug = :slug';
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

/** @return array<string,mixed>|null */
function kose_bul(int $id): ?array
{
    $ifade = db()->prepare('SELECT * FROM kose_yazilari WHERE id = :id');
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/** @return array<string,mixed>|null */
function kose_yayinda_bul(string $slug): ?array
{
    $ifade = db()->prepare('SELECT * FROM kose_yazilari WHERE slug = :s AND durum = :d');
    $ifade->execute(['s' => $slug, 'd' => HABER_YAYINDA]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Panel listesi: bir durumdaki yazılar, en yeni gün önce.
 *
 * @return list<array<string,mixed>>
 */
function kose_listele(string $durum, int $limit = 60): array
{
    $ifade = db()->prepare(
        'SELECT * FROM kose_yazilari WHERE durum = :d
          ORDER BY gun DESC, sira, id LIMIT ' . max(1, $limit)
    );
    $ifade->execute(['d' => $durum]);

    return $ifade->fetchAll();
}

/** @return array<string,int> */
function kose_durum_sayilari(): array
{
    $sayilar = [HABER_TASLAK => 0, HABER_YAYINDA => 0, HABER_REDDEDILDI => 0];

    foreach (db()->query('SELECT durum, COUNT(*) AS adet FROM kose_yazilari GROUP BY durum') as $satir) {
        $sayilar[(string) $satir['durum']] = (int) $satir['adet'];
    }

    return $sayilar;
}

/** Paneldeki düzeltme: başlık, özet ve metin. Slug yayında değilken güncellenir. */
function kose_guncelle(int $id, string $baslik, string $ozet, string $icerik): void
{
    $yazi = kose_bul($id);

    if ($yazi === null) {
        throw new InvalidArgumentException('Yazı bulunamadı.');
    }

    $baslik = trim($baslik);
    $icerik = trim($icerik);

    if (!mb_check_encoding($baslik . $ozet . $icerik, 'UTF-8')) {
        throw new InvalidArgumentException('Metin geçersiz karakter içeriyor; kopyalanan metni kontrol edin.');
    }

    if (mb_strlen($baslik, 'UTF-8') < 10) {
        throw new InvalidArgumentException('Başlık çok kısa.');
    }

    if ($icerik === '') {
        throw new InvalidArgumentException('Yazı metni boş olamaz.');
    }

    /*
     * Yayindaki yazinin adresi degismiyor: paylasilmis ya da
     * indekslenmis bir bag kirilmasin. Taslakta ise baslik degisince
     * adres de yeni basliga uysun.
     */
    $slug = $yazi['durum'] === HABER_YAYINDA
        ? (string) $yazi['slug']
        : kose_benzersiz_slug($baslik, $id);

    db()->prepare(
        'UPDATE kose_yazilari SET baslik = :b, slug = :s, ozet = :o, icerik = :i WHERE id = :id'
    )->execute([
        'b'  => mb_substr($baslik, 0, 300, 'UTF-8'),
        's'  => $slug,
        'o'  => mb_substr(trim($ozet), 0, 600, 'UTF-8'),
        'i'  => $icerik,
        'id' => $id,
    ]);
}

function kose_yayinla(int $id, ?int $yoneticiId): void
{
    db()->prepare(
        'UPDATE kose_yazilari
            SET durum = :d, onaylayan_id = :y, yayin_tarihi = COALESCE(yayin_tarihi, NOW())
          WHERE id = :id'
    )->execute(['d' => HABER_YAYINDA, 'y' => $yoneticiId, 'id' => $id]);
}

function kose_durum_degistir(int $id, string $durum): void
{
    db()->prepare('UPDATE kose_yazilari SET durum = :d WHERE id = :id')
        ->execute(['d' => $durum, 'id' => $id]);
}

function kose_sil(int $id): void
{
    db()->prepare('DELETE FROM kose_yazilari WHERE id = :id')->execute(['id' => $id]);
}

/**
 * Yan penceredeki "Valentra Diyor ki…": yayındaki en son günün yazıları.
 *
 * @return list<array<string,mixed>>
 */
function kose_son_gun(): array
{
    $gun = db()->prepare('SELECT MAX(gun) FROM kose_yazilari WHERE durum = :d');
    $gun->execute(['d' => HABER_YAYINDA]);
    $sonGun = $gun->fetchColumn();

    if ($sonGun === false || $sonGun === null) {
        return [];
    }

    return array_slice(kose_gun_yazilari((string) $sonGun), 0, KOSE_GUNLUK_TAVAN);
}

/** Yayında yazısı olan gün sayısı (liste sayfalaması için). */
function kose_gun_sayisi(): int
{
    $ifade = db()->prepare('SELECT COUNT(DISTINCT gun) FROM kose_yazilari WHERE durum = :d');
    $ifade->execute(['d' => HABER_YAYINDA]);

    return (int) $ifade->fetchColumn();
}

/**
 * "Tüm yazılar": günlere göre gruplu, en yeni gün önce.
 *
 * Sayfalama GUN uzerinden: bir gunun yazilari iki sayfaya bolunmesin.
 *
 * @return array<string,list<array<string,mixed>>> 'Y-m-d' => yazılar
 */
function kose_gunlere_gore(int $sayfa = 1, int $gunAdedi = 10): array
{
    $gunler = db()->prepare(
        'SELECT DISTINCT gun FROM kose_yazilari WHERE durum = :d
          ORDER BY gun DESC LIMIT ' . max(1, $gunAdedi) . ' OFFSET ' . (max(1, $sayfa) - 1) * max(1, $gunAdedi)
    );
    $gunler->execute(['d' => HABER_YAYINDA]);
    $liste = array_map(static fn ($g): string => substr((string) $g, 0, 10), $gunler->fetchAll(PDO::FETCH_COLUMN));

    if ($liste === []) {
        return [];
    }

    $yer = implode(',', array_fill(0, count($liste), '?'));
    $ifade = db()->prepare(
        'SELECT * FROM kose_yazilari WHERE durum = ? AND gun IN (' . $yer . ')
          ORDER BY gun DESC, sira, id'
    );
    $ifade->execute(array_merge([HABER_YAYINDA], $liste));

    $gruplu = array_fill_keys($liste, []);

    foreach ($ifade->fetchAll() as $yazi) {
        $gruplu[substr((string) $yazi['gun'], 0, 10)][] = $yazi;
    }

    return $gruplu;
}

/**
 * Yazının dayandığı haberler.
 *
 * Yayindaki haber site ici bagla, yayinda olmayan (taslak ya da
 * reddedilmis) haber yalnizca kendi kaynagiyla gosteriliyor: okuyucuya
 * 404 veren bir bag verilmez.
 *
 * @return list<array{baslik:string,adres:string,ic:bool,kaynak:string}>
 */
function kose_dayanaklar(string $idler): array
{
    $liste = kose_id_listesi($idler);

    if ($liste === []) {
        return [];
    }

    $yer = implode(',', array_fill(0, count($liste), '?'));
    $ifade = db()->prepare(
        'SELECT id, baslik, slug, durum, kaynak_adi, kaynak_url FROM haberler WHERE id IN (' . $yer . ')'
    );
    $ifade->execute($liste);

    $sonuc = [];

    foreach ($ifade->fetchAll() as $h) {
        if ($h['durum'] === HABER_YAYINDA) {
            $sonuc[] = ['baslik' => (string) $h['baslik'], 'adres' => haber_yolu((string) $h['slug']),
                        'ic' => true, 'kaynak' => (string) $h['kaynak_adi']];
        } elseif (guvenli_url((string) $h['kaynak_url']) !== '') {
            $sonuc[] = ['baslik' => (string) $h['baslik'], 'adres' => guvenli_url((string) $h['kaynak_url']),
                        'ic' => false, 'kaynak' => (string) $h['kaynak_adi']];
        }
    }

    return $sonuc;
}

/** Yazı metnini HTML'e çevirir (bkz. bicimli_metin_html). */
function kose_icerik_html(string $icerik): string
{
    return bicimli_metin_html($icerik);
}

/**
 * Ajanın yazı malzemesi: son saatlerin haberleri.
 *
 * Yayindaki ve onay bekleyen haberler birlikte: ikisi de ajanin
 * elemesinden gecmis, vergi/ekonomi gundemi. Onay bekleyenler de alindi
 * cunku yazilar ogleden sonra yaziliyor ve gunun haberleri o saatte
 * cogu zaman henuz onaylanmamis oluyor; yazi da ayrica onaydan geciyor.
 *
 * Son uc gunun yazilarinda kullanilmis haberler cikariliyor: ayni haber
 * iki gun ust uste yazi konusu olmasin.
 *
 * @return list<array<string,mixed>>
 */
function kose_malzeme(int $saat = 36, int $limit = 60): array
{
    $kullanilan = [];

    $ifade = db()->prepare(
        'SELECT haber_idleri FROM kose_yazilari
          WHERE gun >= :g AND durum <> :r'
    );
    $ifade->execute(['g' => date('Y-m-d', strtotime('-3 days')), 'r' => HABER_REDDEDILDI]);

    foreach ($ifade->fetchAll(PDO::FETCH_COLUMN) as $idler) {
        foreach (kose_id_listesi((string) $idler) as $id) {
            $kullanilan[$id] = true;
        }
    }

    $haberler = db()->prepare(
        'SELECT h.id, h.baslik, h.ozet, h.icerik, h.durum, h.kaynak_adi, h.kaynak_url,
                h.analiz_degisen, h.analiz_etkilenen, h.analiz_zaman, h.analiz_islem,
                COALESCE(h.yayin_tarihi, h.olusturuldu) AS tarih, k.ad AS kategori
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum IN (:y, :t)
            AND COALESCE(h.yayin_tarihi, h.olusturuldu) >= :sinir
          ORDER BY COALESCE(h.yayin_tarihi, h.olusturuldu) DESC
          LIMIT ' . max(1, $limit)
    );
    $haberler->execute([
        'y'     => HABER_YAYINDA,
        't'     => HABER_TASLAK,
        'sinir' => date('Y-m-d H:i:s', time() - $saat * 3600),
    ]);

    $sonuc = [];

    foreach ($haberler->fetchAll() as $h) {
        if (isset($kullanilan[(int) $h['id']])) {
            continue;
        }

        $h['id']     = (int) $h['id'];
        $h['icerik'] = mb_substr((string) $h['icerik'], 0, 6000, 'UTF-8');
        $sonuc[]     = $h;
    }

    return $sonuc;
}

/**
 * Son günlerin yazı başlıkları ve gündemleri: ajan aynı konuyu aynı
 * açıdan yeniden yazmasın.
 *
 * @return list<array{gun:string,gundem:string,baslik:string}>
 */
function kose_onceki(int $gun = 10): array
{
    $ifade = db()->prepare(
        'SELECT gun, gundem, baslik FROM kose_yazilari
          WHERE gun >= :g AND durum <> :r
          ORDER BY gun DESC, sira'
    );
    $ifade->execute(['g' => date('Y-m-d', strtotime('-' . $gun . ' days')), 'r' => HABER_REDDEDILDI]);

    return array_map(static fn (array $s): array => [
        'gun'    => substr((string) $s['gun'], 0, 10),
        'gundem' => (string) $s['gundem'],
        'baslik' => (string) $s['baslik'],
    ], $ifade->fetchAll());
}

/** Bugün için reddedilmemiş kaç yazı var. */
function kose_bugun_sayisi(?string $gun = null): int
{
    $ifade = db()->prepare('SELECT COUNT(*) FROM kose_yazilari WHERE gun = :g AND durum <> :r');
    $ifade->execute(['g' => $gun ?? date('Y-m-d'), 'r' => HABER_REDDEDILDI]);

    return (int) $ifade->fetchColumn();
}

/**
 * Bir günün yayındaki yazıları, sırasıyla.
 *
 * @return list<array<string,mixed>>
 */
function kose_gun_yazilari(string $gun): array
{
    $ifade = db()->prepare(
        'SELECT * FROM kose_yazilari WHERE durum = :d AND gun = :g ORDER BY sira, id'
    );
    $ifade->execute(['d' => HABER_YAYINDA, 'g' => substr($gun, 0, 10)]);

    return $ifade->fetchAll();
}
