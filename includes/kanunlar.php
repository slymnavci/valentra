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
            'rg'       => '1961-01-10',
            'aciklama' => 'Vergilendirmenin usul kuralları: defter ve belge düzeni, '
                        . 'değerleme, amortisman, ceza hükümleri, süreler.',
        ],
        [
            'ad'       => 'Gelir Vergisi Kanunu',
            'kisa'     => 'GVK',
            'no'       => 193,
            'tertip'   => 4,
            'rg'       => '1961-01-06',
            'aciklama' => 'Gerçek kişilerin gelirlerinin vergilendirilmesi: ticari '
                        . 'kazanç, serbest meslek, kira, ücret, menkul sermaye iradı.',
        ],
        [
            'ad'       => 'Kurumlar Vergisi Kanunu',
            'kisa'     => 'KVK',
            'no'       => 5520,
            'tertip'   => 5,
            'rg'       => '2006-06-21',
            'aciklama' => 'Şirketlerin kazançlarının vergilendirilmesi: istisnalar, '
                        . 'indirimler, transfer fiyatlandırması, örtülü sermaye.',
        ],
        [
            'ad'       => 'Katma Değer Vergisi Kanunu',
            'kisa'     => 'KDVK',
            'no'       => 3065,
            'tertip'   => 5,
            'rg'       => '1984-11-02',
            'aciklama' => 'KDV\'nin konusu, oranları, istisnaları, indirim ve '
                        . 'iade mekanizması.',
        ],
        [
            'ad'       => 'Özel Tüketim Vergisi Kanunu',
            'kisa'     => 'ÖTVK',
            'no'       => 4760,
            'tertip'   => 5,
            'rg'       => '2002-06-12',
            'aciklama' => 'Listelerde sayılan mallarda alınan özel tüketim vergisi.',
        ],
        [
            'ad'       => 'Damga Vergisi Kanunu',
            'kisa'     => 'DVK',
            'no'       => 488,
            'tertip'   => 5,
            'rg'       => '1964-07-11',
            'aciklama' => 'Kâğıtlar üzerinden alınan damga vergisi: nispetler, '
                        . 'istisnalar, sorumluluk.',
        ],
        [
            'ad'       => 'Harçlar Kanunu',
            'kisa'     => 'Harçlar K.',
            'no'       => 492,
            'tertip'   => 5,
            'rg'       => '1964-07-17',
            'aciklama' => 'Yargı, noter, tapu, pasaport ve diğer işlemlerde '
                        . 'alınan harçlar.',
        ],
        [
            'ad'       => 'Amme Alacaklarının Tahsil Usulü Hakkında Kanun',
            'kisa'     => '6183',
            'no'       => 6183,
            'tertip'   => 3,
            'rg'       => '1953-07-28',
            'aciklama' => 'Kamu alacaklarının takip ve tahsili: ödeme emri, haciz, '
                        . 'tecil ve taksitlendirme, gecikme zammı.',
        ],
        [
            'ad'       => 'Emlak Vergisi Kanunu',
            'kisa'     => 'EVK',
            'no'       => 1319,
            'tertip'   => 5,
            'rg'       => '1970-08-11',
            'aciklama' => 'Bina ve arazi üzerinden alınan emlak vergisi.',
        ],
        [
            'ad'       => 'Motorlu Taşıtlar Vergisi Kanunu',
            'kisa'     => 'MTVK',
            'no'       => 197,
            'tertip'   => 5,
            'rg'       => '1963-02-23',
            'aciklama' => 'Motorlu taşıtlardan alınan yıllık vergi ve tarifeleri.',
        ],
        [
            'ad'       => 'Veraset ve İntikal Vergisi Kanunu',
            'kisa'     => 'VİVK',
            'no'       => 7338,
            'tertip'   => 3,
            'rg'       => '1959-06-15',
            'aciklama' => 'Miras ve ivazsız intikaller üzerinden alınan vergi.',
        ],
        [
            'ad'       => 'Gider Vergileri Kanunu',
            'kisa'     => 'BSMV',
            'no'       => 6802,
            'tertip'   => 3,
            'rg'       => '1956-07-23',
            'aciklama' => 'Banka ve sigorta muameleleri vergisi ile özel '
                        . 'iletişim vergisi.',
        ],
    ];
}

/**
 * Yayım tarihine göre olması gereken Düstur tertibi.
 *
 * NEDEN VAR: tertip elle giriliyordu ve yanlis bir deger sessizce
 * BASKA BIR KANUNU aciyor. Gelir Vergisi Kanunu'nda tam bu oldu —
 * tertip 3 yazilmisti ve sayfa, 193 numarayi 3. tertipte tasiyan
 * 1922 tarihli "Bolu hapisanesinde mahpus Bakkal Hasan bin
 * Mustafa'nin bakiyei muddeti cezaiyesinin affina dair kanun"u
 * gosterdi. Kanun numaralari tertipler arasinda tekrar ediyor; adres
 * gecerli, dosya gecerli, PDF imzasi gecerli — yalnizca KANUN yanlis.
 * Bir YMM sitesinde bulunabilecek en sinsi hata turu.
 *
 * Tertip sinirlari Dustur'un yayim donemleridir:
 *   3. Tertip  ...            – 26.05.1960
 *   4. Tertip  27.05.1960     – 24.10.1961
 *   5. Tertip  25.10.1961     – ...
 *
 * Bu fonksiyon tertibi URETMEK icin degil DENETLEMEK icin: elle
 * girilen deger tarihle celisirse test bunu yakaliyor
 * (ajan/kanun_denetle.php). Uretmiyoruz cunku sinir tarihlerine
 * korlemesine guvenmek, duzeltmeye calistigimiz hatanin aynisini
 * baska bicimde yapmak olurdu.
 */
function kanun_tertip_beklenen(string $rgTarihi): int
{
    if ($rgTarihi < '1960-05-27') {
        return 3;
    }

    if ($rgTarihi < '1961-10-25') {
        return 4;
    }

    return 5;
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
 * Tek bir adrese guvenmiyoruz. Ayni metne giden birden cok yol var ve
 * hangisinin acik oldugu sunucudan sunucuya degisiyor: "www" olmayan
 * alan adi bazen farkli bir guvenlik duvarinin arkasinda duruyor,
 * uygulamanin kendi PDF ucu ise duragan dosya kapali olsa bile
 * calisabiliyor. Adaylar sirayla deneniyor, ilk tutan kullaniliyor ve
 * hepsinin sonucu tani ekraninda gorunuyor.
 *
 * @return list<array{ad:string,tur:string,url:string}>
 */
function kanun_metin_adaylari(array $kanun): array
{
    $tertip = (int) $kanun['tertip'];
    $no     = (int) $kanun['no'];
    $dosya  = '/MevzuatMetin/1.' . $tertip . '.' . $no;

    return [
        [
            'ad'  => 'PDF',
            'tur' => 'pdf',
            'url' => 'https://www.mevzuat.gov.tr' . $dosya . '.pdf',
        ],
        [
            // Bazi aglarda yalnizca www'suz ad cozuluyor.
            'ad'  => 'PDF (www yok)',
            'tur' => 'pdf',
            'url' => 'https://mevzuat.gov.tr' . $dosya . '.pdf',
        ],
        [
            // Uygulamanin "PDF indir" dugmesinin arkasindaki uc.
            'ad'  => 'PDF (uygulama ucu)',
            'tur' => 'pdf',
            'url' => 'https://www.mevzuat.gov.tr/File/GeneratePdf?mevzuatNo=' . $no
                   . '&mevzuatTur=KanunTertip&mevzuatTertip=' . $tertip,
        ],
        [
            'ad'  => 'DOC',
            'tur' => 'doc',
            'url' => 'https://www.mevzuat.gov.tr' . $dosya . '.doc',
        ],
        [
            'ad'  => 'Sayfa',
            'tur' => 'sayfa',
            'url' => kanun_adresi($kanun),
        ],
    ];
}

/**
 * Yalnızca PDF adayları.
 *
 * PDF'i aktaran uc (api/kanun-pdf.php) yalnizca bunlari dener; DOC ve
 * sayfa oradan ise yaramaz.
 *
 * @return list<array{ad:string,tur:string,url:string}>
 */
function kanun_pdf_adaylari(array $kanun): array
{
    return array_values(array_filter(
        kanun_metin_adaylari($kanun),
        static fn (array $aday): bool => $aday['tur'] === 'pdf'
    ));
}

/**
 * İstekleri gönderirken kullanılacak Referer.
 *
 * Duragan dosyalara dogrudan gelen istekleri reddeden sunucular, ayni
 * siteden geliyormus gibi gorunen istekleri gecirir.
 */
function kanun_referer(array $kanun): string
{
    return kanun_adresi($kanun);
}
