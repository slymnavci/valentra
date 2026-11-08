<?php
declare(strict_types=1);

/**
 * Ana sayfa: "Hesaplamalar ve Rehberler" seridi (vitrinin hemen altinda).
 *
 * Site sahibinin istegi (08.11.2026): okurun asil aradigi bilgiler
 * (kira artis orani, emekli zammi, akaryakit, enflasyon, vergi ve harclar)
 * sayfanin altindaki "Gundem Dosyalari" satirindaydi; "bunlarin
 * baglantilarini sayfanin altindan cikarip ustte 'Hesaplamalar ve
 * Rehberler' bolumune tasiyalim". Ana sayfanin alt bandinda ayni satir
 * artik yok ($hesapRehberUstte; diger sayfalarda alt bantta duruyor).
 */

require_once __DIR__ . '/konu.php';

/**
 * Kalemler: [baslik, adres, okura faydasi].
 *
 * @return list<array{0:string,1:string,2:string}>
 */
function hesap_rehber_kalemleri(): array
{
    return [
        [konu_basligi('kira-artisi'),  konu_yolu('kira-artisi'),  'Güncel oran ve hesaplama'],
        [konu_basligi('emekli-zammi'), konu_yolu('emekli-zammi'), 'Kesinleşen ve beklenen'],
        [konu_basligi('enflasyon'),    konu_yolu('enflasyon'),    'Aylık, yıllık, 12 ay ortalama'],
        [konu_basligi('akaryakit'),    konu_yolu('akaryakit'),    'Zam ve indirimler'],
        [konu_basligi('asgari-ucret'), konu_yolu('asgari-ucret'), 'Son durum'],
        [konu_basligi('memur-maasi'),  konu_yolu('memur-maasi'),  'Zam ve toplu sözleşme'],
        ['Vergi ve Harçlar',           pratik_yolu(),             'Resmî tutarlar'],
        ['Vergi Takvimi',              takvim_yolu(),             'Beyan ve ödeme tarihleri'],
        ['Hesaplama Araçları',         araclar_yolu(),            'KDV, vade farkı'],
    ];
}

function hesap_rehber_html(): string
{
    $html = '';

    foreach (hesap_rehber_kalemleri() as [$ad, $yol, $fayda]) {
        $html .= '<li><a href="' . e($yol) . '"><strong>' . e($ad) . '</strong><span>' . e($fayda) . '</span></a></li>';
    }

    return '<nav class="hesap-rehber" aria-labelledby="hesap-rehber-baslik">'
        . '<h2 id="hesap-rehber-baslik">Hesaplamalar ve Rehberler</h2>'
        . '<ul>' . $html . '</ul>'
        . '</nav>';
}
