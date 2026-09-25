<?php
declare(strict_types=1);

/**
 * Uygulama rehberleri: kalıcı "nasıl yapılır" içerikleri.
 *
 * Haberden farki: tarihle eskimiyor, ayni adreste guncelleniyor. Arama
 * motorundan surekli trafik getirecek icerik bu; haber birkac gun
 * okunur, "vade farki nasil hesaplanir" yillarca aranir.
 *
 * Yayin YALNIZCA panelden onayla: taslaklar model ya da editor
 * tarafindan yazilsa da bir insan okuyup "Yayınla" demeden sitede
 * gorunmez.
 */

const REHBER_TASLAK  = 'taslak';
const REHBER_YAYINDA = 'yayinda';

/**
 * @return list<array<string,mixed>>
 */
function rehber_yayindakiler(): array
{
    try {
        return db()->query(
            "SELECT * FROM rehberler WHERE durum = 'yayinda' ORDER BY sira, id"
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function rehber_yayinda_sayisi(): int
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM rehberler WHERE durum = 'yayinda'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function rehber_hepsi(): array
{
    return db()->query('SELECT * FROM rehberler ORDER BY durum, sira, id')->fetchAll();
}

/** @return array<string,mixed>|null */
function rehber_bul(int $id): ?array
{
    $ifade = db()->prepare('SELECT * FROM rehberler WHERE id = :id');
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return $satir ?: null;
}

/** @return array<string,mixed>|null */
function rehber_slug_bul(string $slug): ?array
{
    $ifade = db()->prepare('SELECT * FROM rehberler WHERE slug = :s');
    $ifade->execute(['s' => $slug]);
    $satir = $ifade->fetch();

    return $satir ?: null;
}

/**
 * Bir hesaplama aracina bagli yayindaki rehber (araclar sayfasindaki
 * "Nasil hesaplanir?" baglantisi icin).
 *
 * @return array<string,mixed>|null
 */
function rehber_araca_gore(string $arac): ?array
{
    try {
        $ifade = db()->prepare("SELECT * FROM rehberler WHERE durum = 'yayinda' AND arac = :a ORDER BY sira LIMIT 1");
        $ifade->execute(['a' => $arac]);
        $satir = $ifade->fetch();

        return $satir ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Paneldeki formu denetler ve kaydeder. id 0 ise yeni taslak.
 *
 * @param array<string,mixed> $girdi
 * @return array{tamam:bool,id:int,hatalar:list<string>}
 */
function rehber_kaydet(array $girdi, int $id = 0): array
{
    $alan = static fn (string $ad, int $sinir): string
        => mb_substr(trim((string) ($girdi[$ad] ?? '')), 0, $sinir);

    $veri = [
        'baslik'       => $alan('baslik', 200),
        'slug'         => slug_uret($alan('slug', 220) !== '' ? $alan('slug', 220) : $alan('baslik', 200)),
        'ozet'         => $alan('ozet', 400),
        'icerik'       => str_replace("\r\n", "\n", trim((string) ($girdi['icerik'] ?? ''))),
        'konu'         => $alan('konu', 80),
        'arac'         => preg_replace('/[^a-z0-9-]/', '', $alan('arac', 40)) ?? '',
        'hazirlayan'   => $alan('hazirlayan', 160) !== '' ? $alan('hazirlayan', 160) : 'Valentra Yayın Kurulu',
        'kontrol_eden' => $alan('kontrol_eden', 160) !== '' ? $alan('kontrol_eden', 160) : null,
        'sira'         => (int) ($girdi['sira'] ?? 100),
    ];

    $hatalar = [];

    foreach (['baslik' => 'Başlık', 'icerik' => 'İçerik'] as $ad => $etiket) {
        if ($veri[$ad] === '') {
            $hatalar[] = $etiket . ' boş olamaz.';
        }
    }

    if (!mb_check_encoding($veri['icerik'], 'UTF-8')) {
        $hatalar[] = 'İçerik geçersiz karakterler içeriyor; metni yeniden yapıştırın.';
    }

    $ayni = db()->prepare('SELECT id FROM rehberler WHERE slug = :s AND id <> :id');
    $ayni->execute(['s' => $veri['slug'], 'id' => $id]);

    if ($ayni->fetchColumn() !== false) {
        $hatalar[] = 'Bu adres (' . $veri['slug'] . ') başka bir rehberde kullanılıyor.';
    }

    if ($hatalar !== []) {
        return ['tamam' => false, 'id' => $id, 'hatalar' => $hatalar];
    }

    if ($id > 0) {
        db()->prepare(
            'UPDATE rehberler SET baslik = :baslik, slug = :slug, ozet = :ozet, icerik = :icerik,
                    konu = :konu, arac = :arac, hazirlayan = :hazirlayan,
                    kontrol_eden = :kontrol_eden, sira = :sira
              WHERE id = :id'
        )->execute($veri + ['id' => $id]);

        return ['tamam' => true, 'id' => $id, 'hatalar' => []];
    }

    db()->prepare(
        'INSERT INTO rehberler (baslik, slug, ozet, icerik, konu, arac, hazirlayan, kontrol_eden, sira)
         VALUES (:baslik, :slug, :ozet, :icerik, :konu, :arac, :hazirlayan, :kontrol_eden, :sira)'
    )->execute($veri);

    return ['tamam' => true, 'id' => (int) db()->lastInsertId(), 'hatalar' => []];
}

/**
 * Yayina alir ya da taslaga ceker. Ilk yayin tarihi korunuyor;
 * sonraki duzenlemeler "guncellendi" olarak gorunuyor.
 */
function rehber_yayin(int $id, bool $yayinda): void
{
    db()->prepare(
        'UPDATE rehberler SET durum = :d,
                yayin_tarihi = CASE WHEN :y = 1 AND yayin_tarihi IS NULL THEN NOW() ELSE yayin_tarihi END
          WHERE id = :id'
    )->execute(['d' => $yayinda ? REHBER_YAYINDA : REHBER_TASLAK, 'y' => $yayinda ? 1 : 0, 'id' => $id]);
}

function rehber_sil(int $id): void
{
    db()->prepare("DELETE FROM rehberler WHERE id = :id AND durum = 'taslak'")->execute(['id' => $id]);
}

/**
 * Rehber metni -> HTML.
 *
 * Haber metninin bicimine (bicimli_metin_html) ek olarak:
 *   ### alt baslik
 *   1. numarali adim
 *   | tablo | satiri |       (ilk satir baslik; |---| satiri atlanir)
 *   > Dikkat: ...            (vurgulu not kutusu)
 *   [[arac:vade-farki]]      (hesaplama aracina baglanti kutusu)
 *
 * Her sey e() ile kacirilarak basiliyor; metne HTML yazilamaz.
 */
function rehber_icerik_html(string $icerik): string
{
    $html = '';

    foreach (preg_split('/\n\s*\n/u', trim($icerik)) ?: [] as $blok) {
        $blok = trim($blok);

        if ($blok === '') {
            continue;
        }

        $satirlar = preg_split('/\n/u', $blok) ?: [];
        $hepsi    = static fn (string $desen): bool => array_reduce(
            $satirlar,
            static fn (bool $t, string $s): bool => $t && preg_match($desen, $s) === 1,
            true
        );

        if (count($satirlar) === 1 && preg_match('/^(#{2,3})\s+(.+)$/u', $blok, $m)) {
            $etiket = strlen($m[1]) === 2 ? 'h2' : 'h3';
            $html  .= '<' . $etiket . ' id="' . e(slug_uret($m[2])) . '">' . e(trim($m[2])) . '</' . $etiket . ">\n";
            continue;
        }

        if (preg_match('/^\[\[arac:([a-z0-9-]+)\]\]$/', $blok, $m)) {
            $html .= '<p class="rehber-arac"><a class="dugme-arac" href="' . e(araclar_yolu() . '#' . $m[1]) . '">'
                   . 'Hesaplama aracında deneyin &rarr;</a></p>' . "\n";
            continue;
        }

        if ($hepsi('/^\s*\|.*\|\s*$/u')) {
            $html .= rehber_tablo_html($satirlar);
            continue;
        }

        if ($hepsi('/^\s*>\s?/u')) {
            $metin = implode("\n", array_map(static fn (string $s): string => (string) preg_replace('/^\s*>\s?/u', '', $s), $satirlar));
            $html .= '<aside class="rehber-not">' . nl2br(e($metin)) . "</aside>\n";
            continue;
        }

        foreach (['ul' => '/^\s*[-•]\s+/u', 'ol' => '/^\s*\d+[.)]\s+/u'] as $liste => $desen) {
            if ($hepsi($desen)) {
                $html .= '<' . $liste . ">\n";

                foreach ($satirlar as $satir) {
                    $html .= '<li>' . e(trim((string) preg_replace($desen, '', $satir))) . "</li>\n";
                }

                $html .= '</' . $liste . ">\n";
                continue 2;
            }
        }

        $html .= '<p>' . nl2br(e($blok)) . "</p>\n";
    }

    return $html;
}

/**
 * @param list<string> $satirlar
 */
function rehber_tablo_html(array $satirlar): string
{
    $html = "<div class=\"rehber-tablo\"><table>\n";
    $ilk  = true;

    foreach ($satirlar as $satir) {
        $hucreler = array_map('trim', explode('|', trim(trim($satir), '|')));

        // Ayrac satiri: |---|:--:|
        if (preg_match('/^[\s:|-]+$/', $satir) === 1) {
            continue;
        }

        $etiket = $ilk ? 'th' : 'td';
        $html  .= $ilk ? '<thead><tr>' : '<tr>';

        foreach ($hucreler as $sutun => $hucre) {
            // Rakamla baslayan (ya da eksi) hucre saga yasli: tutar sutunlari
            // hizali dursun. Ilk sutun kalem adi ("12 aylik destek"), haric.
            $sayi  = !$ilk && $sutun > 0 && preg_match('/^[−-]?\(?[0-9]/u', $hucre) === 1;
            $html .= '<' . $etiket . ($sayi ? ' class="sayi"' : '') . '>' . e($hucre) . '</' . $etiket . '>';
        }

        $html .= $ilk ? "</tr></thead>\n<tbody>\n" : "</tr>\n";
        $ilk   = false;
    }

    return $html . "</tbody></table></div>\n";
}

/**
 * Metindeki ## basliklardan icindekiler listesi.
 *
 * @return list<array{id:string,baslik:string}>
 */
function rehber_icindekiler(string $icerik): array
{
    preg_match_all('/^##\s+(.+)$/mu', $icerik, $m);

    return array_map(static fn (string $b): array => ['id' => slug_uret($b), 'baslik' => trim($b)], $m[1]);
}

/** Tahmini okuma suresi (dakika), dakikada ~200 kelime. */
function rehber_okuma_suresi(string $icerik): int
{
    return max(1, (int) round(count(preg_split('/\s+/u', trim($icerik)) ?: []) / 200));
}

/**
 * Ilk kurulumda taslaklari ekler (bir kez). Eklenen sayisini dondurur.
 */
function rehber_tohumla(): int
{
    if (ayar_oku('rehber_tohum') === '1') {
        return 0;
    }

    $taslaklar = require __DIR__ . '/rehber_taslaklari.php';
    $ekle = db()->prepare(
        'INSERT IGNORE INTO rehberler (baslik, slug, ozet, icerik, konu, arac, sira)
         VALUES (:baslik, :slug, :ozet, :icerik, :konu, :arac, :sira)'
    );
    $adet = 0;

    foreach ($taslaklar as $sira => $t) {
        $ekle->execute([
            'baslik' => $t['baslik'], 'slug' => $t['slug'], 'ozet' => $t['ozet'],
            'icerik' => trim($t['icerik']), 'konu' => $t['konu'], 'arac' => $t['arac'] ?? '',
            'sira'   => ($sira + 1) * 10,
        ]);
        $adet += $ekle->rowCount();
    }

    ayar_yaz('rehber_tohum', '1');

    return $adet;
}
