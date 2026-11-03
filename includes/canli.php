<?php
declare(strict_types=1);

/**
 * Canli anlatim (03.11.2026, site sahibi: "sirayla yapmaya basla"; okuru
 * sitede tutan ozellikler).
 *
 * Secim, deprem, buyuk mac gibi olaylarda dakika dakika guncellenen tek
 * sayfa: /canli/{slug}. Panelden (admin/canli.php) aciliyor, gelismeler
 * eklendikce en ustte gorunuyor. Sayfa acikken betik 30 saniyede bir yeni
 * gelismeleri soruyor (canli.php?c=..&sonra=..&json=1) ve "N yeni
 * gelisme" dugmesiyle ekliyor. Acik anlatim varsa butun sayfalarin
 * ustunde kirmizi "CANLI" bandi (includes/sayfa_ust.php).
 *
 * Onemli isaretli gelisme istenirse tarayici bildirimi olarak da gidiyor
 * (bkz. includes/web_push.php).
 */

require_once __DIR__ . '/adres.php';

const CANLI_YENILEME_SN = 30;

/** Canli anlatim sayfasinin adresi. */
function canli_yolu(string $slug = ''): string
{
    if ($slug === '') {
        return temiz_adres_acik() ? '/canli' : '/canli.php';
    }

    return temiz_adres_acik() ? '/canli/' . rawurlencode($slug) : '/canli.php?c=' . rawurlencode($slug);
}

/** @return array<string,mixed>|null */
function canli_bul(string $slug): ?array
{
    $ifade = db()->prepare('SELECT * FROM canli_anlatimlar WHERE slug = :s');
    $ifade->execute(['s' => $slug]);

    return $ifade->fetch() ?: null;
}

/** @return array<string,mixed>|null */
function canli_bul_id(int $id): ?array
{
    $ifade = db()->prepare('SELECT * FROM canli_anlatimlar WHERE id = :id');
    $ifade->execute(['id' => $id]);

    return $ifade->fetch() ?: null;
}

/**
 * Su an acik anlatimlar (ust bant icin), en son guncellenen ustte.
 * Tablo yoksa (sema henuz kurulmadiysa) bos.
 *
 * @return list<array<string,mixed>>
 */
function canli_aciklar(int $adet = 2): array
{
    static $onbellek = null;

    if ($onbellek !== null) {
        return $onbellek;
    }

    try {
        $onbellek = db()->query(
            "SELECT id, baslik, slug, guncellendi FROM canli_anlatimlar
              WHERE durum = 'canli' AND guncellendi > NOW() - INTERVAL 2 DAY
              ORDER BY guncellendi DESC LIMIT " . max(1, min(5, $adet))
        )->fetchAll();
    } catch (PDOException $e) {
        $onbellek = [];
    }

    return $onbellek;
}

/** @return list<array<string,mixed>> Sayfanin listesi: acik olanlar ustte, sonra bitenler. */
function canli_liste(int $adet = 30): array
{
    return db()->query(
        "SELECT a.*, (SELECT COUNT(*) FROM canli_girisler g WHERE g.anlatim_id = a.id) AS giris_sayisi
           FROM canli_anlatimlar a
          ORDER BY a.durum = 'canli' DESC, a.guncellendi DESC LIMIT " . max(1, min(200, $adet))
    )->fetchAll();
}

/**
 * Gelismeler, en yeni ustte. $sonra verilirse yalnizca ondan yeniler.
 *
 * @return list<array<string,mixed>>
 */
function canli_girisler(int $anlatimId, int $sonra = 0, int $adet = 300): array
{
    $ifade = db()->prepare(
        'SELECT * FROM canli_girisler WHERE anlatim_id = :a AND id > :s ORDER BY id DESC LIMIT ' . max(1, min(500, $adet))
    );
    $ifade->execute(['a' => $anlatimId, 's' => $sonra]);

    return $ifade->fetchAll();
}

/** Yeni anlatim; slug cakisirsa sonuna sayi. */
function canli_olustur(string $baslik, string $ozet = '', string $gorsel = ''): int
{
    $baslik = trim($baslik);

    if (mb_strlen($baslik) < 5) {
        throw new InvalidArgumentException('Başlık en az 5 karakter olmalı.');
    }

    $temel = slug_uret($baslik);
    $slug  = $temel;

    for ($i = 2; canli_bul($slug) !== null; $i++) {
        $slug = $temel . '-' . $i;
    }

    $gorsel = trim($gorsel);

    if ($gorsel !== '' && !preg_match('#^https://#i', $gorsel)) {
        throw new InvalidArgumentException('Görsel adresi https:// ile başlamalı.');
    }

    db()->prepare('INSERT INTO canli_anlatimlar (baslik, slug, ozet, gorsel_url) VALUES (:b, :s, :o, :g)')
        ->execute(['b' => mb_substr($baslik, 0, 200), 's' => $slug, 'o' => mb_substr(trim($ozet), 0, 400), 'g' => mb_substr($gorsel, 0, 500)]);

    return (int) db()->lastInsertId();
}

/**
 * Gelisme ekler. $bildirim: onemli gelismeyi abonelere tarayici bildirimi
 * olarak da gonder.
 */
function canli_giris_ekle(int $anlatimId, string $baslik, string $metin, bool $onemli = false, bool $bildirim = false): int
{
    $metin  = trim(str_replace("\r\n", "\n", $metin));
    $baslik = trim($baslik);

    if ($metin === '' && $baslik === '') {
        throw new InvalidArgumentException('Gelişme metni boş olamaz.');
    }

    $anlatim = canli_bul_id($anlatimId);

    if ($anlatim === null) {
        throw new InvalidArgumentException('Canlı anlatım bulunamadı.');
    }

    db()->prepare('INSERT INTO canli_girisler (anlatim_id, baslik, metin, onemli) VALUES (:a, :b, :m, :o)')
        ->execute(['a' => $anlatimId, 'b' => mb_substr($baslik, 0, 200), 'm' => mb_substr($metin, 0, 5000), 'o' => $onemli ? 1 : 0]);
    $id = (int) db()->lastInsertId();

    db()->prepare("UPDATE canli_anlatimlar SET guncellendi = NOW(), durum = 'canli', bitti = NULL WHERE id = :id")
        ->execute(['id' => $anlatimId]);

    if ($bildirim) {
        require_once __DIR__ . '/web_push.php';

        if (push_abone_sayisi() > 0) {
            $yol = canli_yolu((string) $anlatim['slug']);
            push_gonderim_ac(
                'CANLI · ' . (string) $anlatim['baslik'],
                $baslik !== '' ? $baslik : kisalt($metin, 170),
                $yol . (str_contains($yol, '?') ? '&' : '?') . 'utm_source=bildirim&utm_medium=push#g' . $id,
                (string) $anlatim['gorsel_url'],
                null,
                'elle'
            );
        }
    }

    return $id;
}

function canli_durum(int $anlatimId, bool $acik): void
{
    db()->prepare('UPDATE canli_anlatimlar SET durum = :d, bitti = ' . ($acik ? 'NULL' : 'NOW()') . ' WHERE id = :id')
        ->execute(['d' => $acik ? 'canli' : 'bitti', 'id' => $anlatimId]);
}

/** Gelisme metni: kacisli paragraflar, adresler baglanti. */
function canli_metin_html(string $metin): string
{
    $paragraflar = preg_split('/\n\s*\n/u', trim($metin)) ?: [];
    $html = '';

    foreach ($paragraflar as $p) {
        $p = e(trim($p));
        $p = preg_replace(
            '#\bhttps?://[^\s<]+[^\s<.,;:!?)\]"\']#u',
            '<a href="$0" target="_blank" rel="noopener nofollow ugc">$0</a>',
            $p
        ) ?? $p;
        $html .= '<p>' . nl2br($p, false) . '</p>';
    }

    return $html;
}

/** Tek gelismenin HTML'i (sayfa ve yeni gelisme yaniti ayni bicim). */
function canli_giris_html(array $g): string
{
    $zaman = strtotime((string) $g['olusturuldu']) ?: time();

    return '<article class="canli-giris' . ((int) $g['onemli'] === 1 ? ' onemli' : '') . '" id="g' . (int) $g['id'] . '" data-id="' . (int) $g['id'] . '">'
        . '<time datetime="' . e(date('c', $zaman)) . '"><span class="canli-saat">' . e(date('H:i', $zaman)) . '</span>'
        . '<span class="canli-gun">' . e(date('d.m', $zaman)) . '</span></time>'
        . '<div class="canli-giris-govde">'
        . ((int) $g['onemli'] === 1 ? '<span class="canli-onemli-etiket">Önemli</span>' : '')
        . ((string) $g['baslik'] !== '' ? '<h2>' . e((string) $g['baslik']) . '</h2>' : '')
        . canli_metin_html((string) $g['metin'])
        . '</div></article>';
}

/**
 * LiveBlogPosting yapisal verisi (Google'in "canli" rozeti).
 *
 * @param array<string,mixed>        $a
 * @param list<array<string,mixed>>  $girisler
 */
function canli_semasi(array $a, array $girisler): string
{
    require_once __DIR__ . '/seo.php';

    $adres = site_adresi() . canli_yolu((string) $a['slug']);
    $sema  = [
        '@context'         => 'https://schema.org',
        '@type'            => 'LiveBlogPosting',
        'headline'         => (string) $a['baslik'],
        'description'      => (string) $a['ozet'],
        'url'              => $adres,
        'mainEntityOfPage' => $adres,
        'datePublished'    => date('c', strtotime((string) $a['olusturuldu']) ?: time()),
        'dateModified'     => date('c', strtotime((string) $a['guncellendi']) ?: time()),
        'coverageStartTime' => date('c', strtotime((string) $a['olusturuldu']) ?: time()),
        'image'            => [(string) $a['gorsel_url'] !== '' ? (string) $a['gorsel_url'] : site_adresi() . '/assets/paylasim.jpg'],
        'author'           => ['@type' => 'Organization', 'name' => 'Valentra Yayın Kurulu', 'url' => site_adresi()],
        'publisher'        => ['@type' => 'Organization', 'name' => 'Valentra',
                               'logo' => ['@type' => 'ImageObject', 'url' => site_adresi() . '/assets/logo-512.png', 'width' => 512, 'height' => 512]],
        'liveBlogUpdate'   => array_map(static fn (array $g): array => [
            '@type'         => 'BlogPosting',
            'headline'      => (string) $g['baslik'] !== '' ? (string) $g['baslik'] : kisalt((string) $g['metin'], 110),
            'articleBody'   => (string) $g['metin'],
            'datePublished' => date('c', strtotime((string) $g['olusturuldu']) ?: time()),
            'url'           => $adres . '#g' . (int) $g['id'],
        ], array_slice($girisler, 0, 20)),
    ];

    if ($a['durum'] === 'bitti' && $a['bitti'] !== null) {
        $sema['coverageEndTime'] = date('c', strtotime((string) $a['bitti']) ?: time());
    }

    return (string) json_encode($sema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
}
