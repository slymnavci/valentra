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

/**
 * Sitenin kendi adresi (protokol dahil, sonda / yok).
 *
 * KANONIK ADRES (26.10.2026, Google'dan neredeyse hic ziyaretci gelmiyor):
 * canonical etiketi, site haritalari ve yapisal veri bu adresi kullaniyor;
 * istegin geldigi adresi aynen yansitirsa ayni haber Google'a birden cok
 * adresle bildiriliyor. Iki duzeltme:
 *   - www. atiliyor: Search Console'da kayitli adres https://valentra.com.tr.
 *   - HTTPS, barindiricinin vekil sunucusu basliklariyla da taniniyor
 *     (includes/auth.php ile ayni olcut); vekil arkasinda $_SERVER['HTTPS']
 *     bos geldiginde canonical "http://" yaziyordu.
 * Yerel gelistirmede (localhost, IP) istek nasil geldiyse o.
 */
function site_adresi(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'valentra.com.tr'));

    // Host basligi istemciden gelir; beklenmedik karakterleri ayikla.
    $host = preg_replace('/[^a-z0-9.\-:]/', '', $host) ?? 'valentra.com.tr';

    if (preg_match('/(^|\.)valentra\.com\.tr(:\d+)?$/', $host) === 1) {
        return 'https://valentra.com.tr';
    }

    $guvenli = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($guvenli ? 'https' : 'http') . '://' . $host;
}

/**
 * Geçerli sayfanın kanonik adresi.
 *
 * Izleme parametreleri (utm_*, fbclid, gclid...) atiliyor: paylasilan
 * bir baglanti "?utm_source=whatsapp" ile geldiginde sayfa kendini o
 * adresle canonical ilan ediyordu. Sayfanin icerigini degistiren
 * parametreler (sayfa, k, h...) kaliyor.
 */
function gecerli_adres(): string
{
    $istek = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $yol   = (string) (parse_url($istek, PHP_URL_PATH) ?? '/');
    $sorgu = (string) (parse_url($istek, PHP_URL_QUERY) ?? '');

    if ($sorgu !== '') {
        parse_str($sorgu, $parametreler);

        foreach (array_keys($parametreler) as $ad) {
            $kucuk = strtolower((string) $ad);

            if (str_starts_with($kucuk, 'utm_')
                || in_array($kucuk, ['fbclid', 'gclid', 'yclid', 'msclkid', 'mc_cid', 'mc_eid',
                                     'ref', 'amp', 'source', 'src', 'igshid'], true)) {
                unset($parametreler[$ad]);
            }
        }

        $sorgu = http_build_query($parametreler);
    }

    return site_adresi() . ($yol !== '' ? $yol : '/') . ($sorgu !== '' ? '?' . $sorgu : '');
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
                'url'   => site_adresi() . '/assets/logo-512.png',
                'width' => 512,
                'height' => 512,
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

    require_once __DIR__ . '/gorsel_onbellek.php';

    $gorsel = haber_gorsel($haber);

    // Gorselsiz haberde marka gorseli: "image" alani bos kalmasin.
    $sema['image'] = [$gorsel !== '' ? (str_starts_with($gorsel, '/') ? site_adresi() . $gorsel : $gorsel)
                                     : site_adresi() . '/assets/paylasim.jpg'];

    if (!empty($haber['kategori_adi'])) {
        $sema['articleSection'] = (string) $haber['kategori_adi'];
    }

    $etiketler = etiketleri_coz((string) ($haber['etiketler'] ?? ''));

    if ($etiketler !== []) {
        $sema['keywords'] = implode(', ', $etiketler);
    }

    /*
     * Kaynaklar ve sik sorulan sorular (08.11.2026, dis denetim raporu).
     * Derleme haberin dayandigi yayinlar "citation" olarak; metnin
     * "## Sıkça sorulan sorular" bolumu ayrica FAQPage olarak. Google
     * SSS zengin sonucunu 2023'ten beri yalnizca resmi/saglik sitelerine
     * gosteriyor; isaretleme yine de soru-cevabi arama ve yapay zeka
     * ozetleri icin acikca tanimliyor.
     */
    if (!empty($haber['derleme_kaynaklari'])) {
        require_once __DIR__ . '/derleme.php';
        $atif = [];

        foreach (derleme_kaynaklari_coz((string) $haber['derleme_kaynaklari']) as $k) {
            $atif[] = array_filter([
                '@type'     => 'CreativeWork',
                'name'      => $k['baslik'] !== '' ? $k['baslik'] : $k['ad'],
                'url'       => $k['url'] !== '' ? $k['url'] : null,
                'publisher' => ['@type' => 'Organization', 'name' => $k['ad']],
            ]);
        }

        if ($atif !== []) {
            $sema['citation'] = $atif;
        }
    }

    /*
     * Ek dugumler: icerik haritasi (12.11.2026) ve varsa SSS.
     *
     * BreadcrumbList: arama sonucunda ciplak adres yerine
     * "Valentra > Gundem" gorunsun. Sayfadaki kategori etiketinin
     * karsiligi (ayni ad, ayni adres); kategorisiz haberde basilmiyor.
     * Son ogede "item" yok: Google sayfanin kendi adresini kullaniyor.
     */
    $ekler = [];

    if (!empty($haber['kategori_slug']) && !empty($haber['kategori_adi'])) {
        $ekler[] = [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana Sayfa', 'item' => site_adresi() . haber_ana_yolu()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string) $haber['kategori_adi'],
                 'item' => site_adresi() . kategori_yolu((string) $haber['kategori_slug'])],
                ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $haber['baslik']],
            ],
        ];
    }

    $sss = seo_sss_cikar(okur_dili((string) ($haber['icerik'] ?? '')));

    if ($sss !== []) {
        $ekler[] = [
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(static fn (array $s): array => [
                '@type'          => 'Question',
                'name'           => $s['soru'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $s['cevap']],
            ], $sss),
        ];
    }

    if ($ekler === []) {
        return json_encode($sema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    unset($sema['@context']);

    return json_encode([
        '@context' => 'https://schema.org',
        '@graph'   => [$sema, ...$ekler],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Haberin görselinin tam adresi (site haritaları ve RSS için); yoksa ''.
 *
 * Haber sayfasinin ve NewsArticle semasinin kullandigi gorselle ayni
 * (haber_gorsel). Gorselsiz haberde bos: marka gorseli haritaya ve
 * beslemeye haberin gorseli diye yazilmiyor.
 *
 * $yalnizHabereOzgu (site haritalari): yalnizca sunucuda duran, o
 * habere ait dosya. Disarida kalanlar:
 *   - /gorsel.php?id=...: gorsel henuz indirilmemis; adres noindex
 *     basliyor ve Googlebot her birini istese site binlerce gorseli
 *     kaynaklardan bir anda indirmeye kalkardi;
 *   - son dakika ve konu gorselleri (/assets/...): onlarca haberde ayni
 *     dosya, gorsel aramasina bir sey katmiyor.
 *
 * @param array<string,mixed> $haber id ve gorsel_url gerekli
 */
function seo_haber_gorsel_adresi(array $haber, bool $yalnizHabereOzgu = false): string
{
    require_once __DIR__ . '/gorsel_onbellek.php';

    $gorsel = haber_gorsel($haber);

    if ($gorsel === '') {
        return '';
    }

    if ($yalnizHabereOzgu && !str_starts_with($gorsel, HABER_GORSEL_YOLU . '/')) {
        return '';
    }

    return str_starts_with($gorsel, '/') ? site_adresi() . $gorsel : $gorsel;
}

/**
 * Metnin "## Sıkça sorulan sorular" bölümünden soru-cevaplar ("- Soru? Cevap").
 *
 * @return list<array{soru:string,cevap:string}>
 */
function seo_sss_cikar(string $icerik): array
{
    if (!preg_match('/^#{2,3}\s*S[ıi]k[çc]a sorulan sorular\s*$(.*?)(?=^#{2,3}\s|\z)/umsi', $icerik, $m)) {
        return [];
    }

    $sonuc = [];

    foreach (preg_split('/\n/u', $m[1]) ?: [] as $satir) {
        if (preg_match('/^\s*[-•]\s*(.+?\?)\s+(.+)$/u', trim($satir), $p)) {
            $sonuc[] = ['soru' => trim(str_replace('**', '', $p[1])), 'cevap' => trim(str_replace('**', '', $p[2]))];
        }
    }

    return count($sonuc) >= 2 ? $sonuc : [];
}

/**
 * Rehber sayfası: Article + BreadcrumbList.
 *
 * Yazar ve kontrol eden sayfadaki kunyeyle AYNI: panelde bir kisi adi
 * girildiyse Person, kurul adi kaldiysa Organization. Kontrol eden
 * WebPage.reviewedBy olarak veriliyor.
 *
 * @param array<string,mixed> $rehber
 */
function seo_rehber_semasi(array $rehber): string
{
    $adres = site_adresi() . rehber_yolu((string) $rehber['slug']);
    $kisi  = static fn (string $ad): array => [
        '@type' => str_contains(mb_strtolower($ad), 'kurul') || str_contains(mb_strtolower($ad), 'valentra')
            ? 'Organization' : 'Person',
        'name'  => $ad,
    ];

    $yayin  = (string) ($rehber['yayin_tarihi'] ?: $rehber['olusturuldu']);
    $makale = [
        '@type'            => 'Article',
        'headline'         => (string) $rehber['baslik'],
        'description'      => (string) $rehber['ozet'],
        'inLanguage'       => 'tr-TR',
        'datePublished'    => date('c', (int) strtotime($yayin)),
        'dateModified'     => date('c', (int) strtotime((string) $rehber['guncellendi'])),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $adres],
        'author'           => $kisi((string) $rehber['hazirlayan']),
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => 'Valentra',
            'logo'  => ['@type' => 'ImageObject', 'url' => site_adresi() . '/assets/logo-512.png', 'width' => 512, 'height' => 512],
        ],
    ];

    if ((string) $rehber['konu'] !== '') {
        $makale['articleSection'] = (string) $rehber['konu'];
    }

    $sayfa = ['@type' => 'WebPage', '@id' => $adres, 'url' => $adres, 'name' => (string) $rehber['baslik']];

    if ((string) ($rehber['kontrol_eden'] ?? '') !== '') {
        $sayfa['reviewedBy']   = $kisi((string) $rehber['kontrol_eden']);
        $sayfa['lastReviewed'] = date('Y-m-d', (int) strtotime((string) $rehber['guncellendi']));
    }

    return json_encode([
        '@context' => 'https://schema.org',
        '@graph'   => [
            $makale,
            $sayfa,
            [
                '@type'           => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana Sayfa', 'item' => site_adresi() . haber_ana_yolu()],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Rehberler', 'item' => site_adresi() . rehberler_yolu()],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $rehber['baslik'], 'item' => $adres],
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Sitenin kendisi için Organization + WebSite şeması. */
function seo_site_semasi(): string
{
    require_once __DIR__ . '/kurumsal.php';

    /*
     * NewsMediaOrganization: Google'in haber yayincisinda aradigi
     * seffaflik baglantilari (yayin ilkeleri, duzeltme politikasi, kunye)
     * kurumsal sayfalara gidiyor (26.10.2026). E-posta yalnizca panelde
     * girilmisse.
     */
    $kurum = [
        '@type'                => 'NewsMediaOrganization',
        '@id'                  => site_adresi() . '/#kurum',
        'name'                 => 'Valentra',
        'description'          => 'Türkiye ve dünyadan güncel haber özetleri',
        'url'                  => site_adresi() . '/',
        'logo'                 => ['@type' => 'ImageObject', 'url' => site_adresi() . '/assets/logo-512.png', 'width' => 512, 'height' => 512],
        'publishingPrinciples' => site_adresi() . kurumsal_yolu('yayin-ilkeleri'),
        'correctionsPolicy'    => site_adresi() . kurumsal_yolu('yayin-ilkeleri') . '#duzeltme',
        'masthead'             => site_adresi() . kurumsal_yolu('kunye'),
    ];

    $eposta = trim(ayar_oku('kunye_eposta'));

    if ($eposta !== '') {
        $kurum['email'] = $eposta;
    }

    return json_encode([
        '@context' => 'https://schema.org',
        '@graph'   => [
            $kurum,
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
