<?php
declare(strict_types=1);

/**
 * Arama motoru ve paylaşım etiketleri.
 *
 * Arama motorlari sayfayi metninden anlamaya calisir; structured data
 * ise "bu bir haber, su tarihte yayimlandi, basligi bu" diye acikca
 * soyler. Haber siteleri icin fark yaratan sey bu: Google haberi haber
 * olarak tanimazsa Haberler sekmesine ve zengin sonuclara hic girmez.
 */

/**
 * Google Search Console doğrulama kodu (varsayılan).
 *
 * Panelden girilen deger bunun onune geciyor; burasi yalnizca
 * varsayilan. Kodda durmasinin sebebi pratik: dogrulama etiketinin
 * sitede olmasi, panele girilmesini beklemeden kanitlanabilir olsun.
 *
 * Gizli bir deger degil — her sayfanin kaynaginda herkese acik duruyor
 * ve tek isi Search Console'a bu siteyi ekleyen kisinin sunucuya
 * erisebildigini gostermek.
 */
const SEO_GOOGLE_DOGRULAMA = 'vKzjIi4Q2RMg9zdzN8djF6vX-f3Rtc9WjlwRiA6by2A';

/** Sitenin kendi adresi (protokol dahil, sonda / yok). */
function site_adresi(): string
{
    $sema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'valentra.com.tr');

    // Host basligi istemciden gelir; beklenmedik karakterleri ayikla.
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host) ?? 'valentra.com.tr';

    return $sema . '://' . $host;
}

/** Geçerli sayfanın tam adresi (sorgu dizesiyle birlikte). */
function gecerli_adres(): string
{
    return site_adresi() . (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

/**
 * Haber için NewsArticle şeması.
 *
 * Yalnizca elimizde gercekten olan alanlar yaziliyor. Olmayan bir alani
 * uydurmak yapisal veri ihlali sayilir.
 *
 * YAZAR eklendi: haberlerin kunyesinde "Valentra Yayın Kurulu" imzasi
 * duruyor, yani uydurma degil sitede gorunen gercek imzanin karsiligi.
 * Person degil Organization: yayin kurulu bir kisi degil.
 *
 * @param array<string,mixed> $haber
 */
function seo_haber_semasi(array $haber): string
{
    $sema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'headline'         => (string) $haber['baslik'],
        'description'      => (string) $haber['ozet'],
        'datePublished'    => date('c', (int) strtotime((string) $haber['yayin_tarihi'])),
        'inLanguage'       => 'tr-TR',
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => site_adresi() . haber_yolu((string) $haber['slug']),
        ],
        'author' => [
            '@type' => 'Organization',
            'name'  => 'Valentra Yayın Kurulu',
            'url'   => site_adresi(),
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name'  => 'Valentra',
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => site_adresi() . '/assets/logo.svg',
            ],
        ],
    ];

    /*
     * dateModified yalnizca GERCEK bir revizyon varsa.
     *
     * guncellendi sutunu ON UPDATE CURRENT_TIMESTAMP tasiyor; onay
     * isleminin kendisi bile onu ileri atiyor. Kosulsuz basmak, hicbir
     * seyin degismedigi haberde de "guncellendi" bildirmek olurdu ve
     * sayfada gosterdigimiz kunyeyle celisirdi. Ayni bir dakikalik pay
     * kullaniliyor.
     */
    $yayinZamani  = (int) strtotime((string) $haber['yayin_tarihi']);
    $guncelZamani = (int) strtotime((string) ($haber['guncellendi'] ?? ''));

    if ($guncelZamani > 0 && ($guncelZamani - $yayinZamani) > 60) {
        $sema['dateModified'] = date('c', $guncelZamani);
    }

    $gorsel = guvenli_url((string) ($haber['gorsel_url'] ?? ''));

    if ($gorsel !== '') {
        $sema['image'] = [$gorsel];
    }

    if (!empty($haber['kategori_adi'])) {
        $sema['articleSection'] = (string) $haber['kategori_adi'];
    }

    $etiketler = etiketleri_coz((string) ($haber['etiketler'] ?? ''));

    if ($etiketler !== []) {
        $sema['keywords'] = implode(', ', $etiketler);
    }

    return json_encode($sema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Sitenin kendisi için Organization + WebSite şeması. */
function seo_site_semasi(): string
{
    return json_encode([
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'       => 'Organization',
                '@id'         => site_adresi() . '/#kurum',
                'name'        => 'Valentra Yeminli Mali Müşavirlik',
                'url'         => site_adresi() . '/',
                'logo'        => site_adresi() . '/assets/logo.svg',
            ],
            [
                '@type'       => 'WebSite',
                '@id'         => site_adresi() . '/#site',
                'name'        => 'Valentra',
                'url'         => site_adresi() . '/',
                'publisher'   => ['@id' => site_adresi() . '/#kurum'],
                'inLanguage'  => 'tr-TR',
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
