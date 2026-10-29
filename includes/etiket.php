<?php
declare(strict_types=1);

/**
 * Etiket sayfaları (site sahibinin istegi, 28.10.2026).
 *
 * Google'dan gelen okuyucu tek sayfaya bakip cikiyordu; haberin
 * etiketleri duz yaziydi ve hicbir yere gitmiyordu. Her etiket artik
 * o etiketteki butun haberleri listeleyen bir sayfaya bagli: okuyucu
 * ayni konudaki diger haberlere geciyor, Google da konu sayfalari
 * buluyor. Ayri tablo yok; haberler.etiketler (virgulle) okunuyor.
 */

/** Bu sayıdan az haberi olan etiket sayfası Google'a sunulmuyor (ince sayfa). */
const ETIKET_DIZIN_ASGARI = 5;

/** Etiket sayfasında taranan en fazla aday haber (en yeniler). */
const ETIKET_ADAY_TAVANI = 600;

/** Etiketin karşılaştırma biçimi: Türkçe küçük harf, tek boşluk. */
function etiket_normal(string $etiket): string
{
    $etiket = str_replace(['I', 'İ'], ['ı', 'i'], trim($etiket));
    $etiket = (string) preg_replace('/\s+/u', ' ', $etiket);

    return mb_strtolower(ltrim($etiket, '#'), 'UTF-8');
}

/**
 * Bir etiketin yayındaki haberleri, yeniden eskiye.
 *
 * LIKE yalnizca on eleme: "faiz" "faiz indirimi"ni de yakaliyor; tam
 * eslesme PHP'de etiketleri_coz ile. Gorunen ad, eslesen ilk haberdeki
 * yazilis ("tcmb" -> "TCMB").
 *
 * @return array{ad:string,toplam:int,haberler:list<array<string,mixed>>}
 */
function etiket_haberleri(string $etiket, int $limit = 24, int $atla = 0): array
{
    $aranan = etiket_normal($etiket);

    if ($aranan === '') {
        return ['ad' => '', 'toplam' => 0, 'haberler' => []];
    }

    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d AND h.etiketler LIKE :l
          ORDER BY h.yayin_tarihi DESC
          LIMIT ' . ETIKET_ADAY_TAVANI
    );
    $ifade->execute([
        'd' => HABER_YAYINDA,
        'l' => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $aranan) . '%',
    ]);

    $ad       = '';
    $eslesen  = [];

    foreach ($ifade->fetchAll() as $h) {
        foreach (etiketleri_coz((string) $h['etiketler']) as $e) {
            if (etiket_normal($e) === $aranan) {
                $ad = $ad !== '' ? $ad : ltrim($e, '#');
                $eslesen[] = $h;
                break;
            }
        }
    }

    return [
        'ad'       => $ad,
        'toplam'   => count($eslesen),
        'haberler' => array_slice($eslesen, max(0, $atla), max(1, $limit)),
    ];
}
