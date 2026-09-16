<?php
declare(strict_types=1);

/**
 * Temel vergi kanunları ve resmî metin adresleri.
 *
 * Neden metin siteye cekilmiyor: VUK gibi bir kanun yuzlerce madde ve
 * surekli degisiyor. Kopyayi guncel tutmak duzenli emek ister, guncel
 * tutulmayan metin ise bir YMM sitesinde ciddi risk — okuyucu yururlukten
 * kalkmis bir hukme gore islem yapabilir. Resmi kaynaga baglanmak metnin
 * her zaman dogru olmasini garanti ediyor.
 *
 * Adresler mevzuat.gov.tr'nin kalici bicimini kullaniyor:
 *   MevzuatNo    - kanun numarasi
 *   MevzuatTur=1 - kanun (tuzuk, yonetmelik icin baska deger)
 *   MevzuatTertip- kanunun yayimlandigi tertip (kulliyat cildi)
 *
 * Tertip numarasi elle giriliyor ve yanlissa bag kirilir. Adresleri
 * dogrulamak icin: php ajan/kaynak_dene.php --kanunlar
 * (GitHub uzerinde calisir, buradan dis sitelere cikis kapali.)
 *
 * @return list<array{ad:string,kisa:string,no:int,tertip:int,aciklama:string}>
 */
function kanun_listesi(): array
{
    return [
        [
            'ad'       => 'Vergi Usul Kanunu',
            'kisa'     => 'VUK',
            'no'       => 213,
            'tertip'   => 4,
            'aciklama' => 'Vergilendirmenin usul kuralları: defter ve belge düzeni, '
                        . 'değerleme, amortisman, ceza hükümleri, süreler.',
        ],
        [
            'ad'       => 'Gelir Vergisi Kanunu',
            'kisa'     => 'GVK',
            'no'       => 193,
            'tertip'   => 3,
            'aciklama' => 'Gerçek kişilerin gelirlerinin vergilendirilmesi: ticari '
                        . 'kazanç, serbest meslek, kira, ücret, menkul sermaye iradı.',
        ],
        [
            'ad'       => 'Kurumlar Vergisi Kanunu',
            'kisa'     => 'KVK',
            'no'       => 5520,
            'tertip'   => 5,
            'aciklama' => 'Şirketlerin kazançlarının vergilendirilmesi: istisnalar, '
                        . 'indirimler, transfer fiyatlandırması, örtülü sermaye.',
        ],
        [
            'ad'       => 'Katma Değer Vergisi Kanunu',
            'kisa'     => 'KDVK',
            'no'       => 3065,
            'tertip'   => 5,
            'aciklama' => 'KDV\'nin konusu, oranları, istisnaları, indirim ve '
                        . 'iade mekanizması.',
        ],
        [
            'ad'       => 'Özel Tüketim Vergisi Kanunu',
            'kisa'     => 'ÖTVK',
            'no'       => 4760,
            'tertip'   => 5,
            'aciklama' => 'Listelerde sayılan mallarda alınan özel tüketim vergisi.',
        ],
        [
            'ad'       => 'Damga Vergisi Kanunu',
            'kisa'     => 'DVK',
            'no'       => 488,
            'tertip'   => 5,
            'aciklama' => 'Kâğıtlar üzerinden alınan damga vergisi: nispetler, '
                        . 'istisnalar, sorumluluk.',
        ],
        [
            'ad'       => 'Harçlar Kanunu',
            'kisa'     => 'Harçlar K.',
            'no'       => 492,
            'tertip'   => 5,
            'aciklama' => 'Yargı, noter, tapu, pasaport ve diğer işlemlerde '
                        . 'alınan harçlar.',
        ],
        [
            'ad'       => 'Amme Alacaklarının Tahsil Usulü Hakkında Kanun',
            'kisa'     => '6183',
            'no'       => 6183,
            'tertip'   => 3,
            'aciklama' => 'Kamu alacaklarının takip ve tahsili: ödeme emri, haciz, '
                        . 'tecil ve taksitlendirme, gecikme zammı.',
        ],
        [
            'ad'       => 'Emlak Vergisi Kanunu',
            'kisa'     => 'EVK',
            'no'       => 1319,
            'tertip'   => 5,
            'aciklama' => 'Bina ve arazi üzerinden alınan emlak vergisi.',
        ],
        [
            'ad'       => 'Motorlu Taşıtlar Vergisi Kanunu',
            'kisa'     => 'MTVK',
            'no'       => 197,
            'tertip'   => 5,
            'aciklama' => 'Motorlu taşıtlardan alınan yıllık vergi ve tarifeleri.',
        ],
        [
            'ad'       => 'Veraset ve İntikal Vergisi Kanunu',
            'kisa'     => 'VİVK',
            'no'       => 7338,
            'tertip'   => 3,
            'aciklama' => 'Miras ve ivazsız intikaller üzerinden alınan vergi.',
        ],
        [
            'ad'       => 'Gider Vergileri Kanunu',
            'kisa'     => 'BSMV',
            'no'       => 6802,
            'tertip'   => 3,
            'aciklama' => 'Banka ve sigorta muameleleri vergisi ile özel '
                        . 'iletişim vergisi.',
        ],
    ];
}

/** Kısa ada göre kanunu bulur. */
function kanun_bul(string $anahtar): ?array
{
    foreach (kanun_listesi() as $kanun) {
        if (kanun_anahtari($kanun) === $anahtar) {
            return $kanun;
        }
    }

    return null;
}

/** Adres icin kullanilan sade anahtar (kanun numarasi). */
function kanun_anahtari(array $kanun): string
{
    return (string) (int) $kanun['no'];
}

/** Kanunun mevzuat.gov.tr adresini üretir. */
function kanun_adresi(array $kanun): string
{
    return 'https://www.mevzuat.gov.tr/mevzuat?MevzuatNo=' . (int) $kanun['no']
         . '&MevzuatTur=1&MevzuatTertip=' . (int) $kanun['tertip'];
}

/**
 * Kanun metninin durabileceği adresleri sırayla verir.
 *
 * mevzuat.gov.tr'nin "/mevzuat?MevzuatNo=..." adresi bir JavaScript
 * uygulamasi: sunucudan gelen HTML bos bir kabuk, metni tarayicida
 * sonradan dolduruyor. Bu yuzden sayfayi curl ile indirmek ise
 * yaramiyor — indirme BASARILI olsa bile icinde kanun metni yok.
 * Ilk denemede tam olarak bu oldu ve sebep "ayiklanamadi" diye
 * gorunuyordu; asil sebep sayfanin bos gelmesiydi.
 *
 * Asil metin ayni sitede duragan dosya olarak duruyor ve adresi
 * kanunun kendi numaralarindan uretilebiliyor:
 *   MevzuatMetin/{tur}.{tertip}.{no}.pdf
 * Tur ve tertip zaten elimizde oldugu icin tahmine gerek yok.
 *
 * @return array{pdf:string,doc:string,sayfa:string}
 */
function kanun_metin_adresleri(array $kanun): array
{
    $kok = 'https://www.mevzuat.gov.tr/MevzuatMetin/1.'
         . (int) $kanun['tertip'] . '.' . (int) $kanun['no'];

    return [
        'pdf'   => $kok . '.pdf',
        'doc'   => $kok . '.doc',
        'sayfa' => kanun_adresi($kanun),
    ];
}
