<?php
declare(strict_types=1);

/**
 * Valentra'nin uc gazetesi: ValPapel (ekonomi), ValSpor (spor), ValMagazin
 * (magazin, sanat, burclar, yemek).
 *
 * Site sahibinin istegi (29.10.2026): "ekonomi sayfasini ValPapel yapalim;
 * girdigimizde dunyadan ekonomi haberleri, Turkiye haberleri, doviz, altin,
 * kripto paralar, borsa, dunya borsalari gibi basliklar olsun. ValSpor ve
 * ValMagazin de ayri birer gazete gibi: futbol, basketbol, voleybol, puan
 * durumu, dunya futbolu, Super Lig; ValMagazin'de burclar, sanat haberleri,
 * sanatcilarin yorumlari, yemek tarifleri".
 *
 * Her gazetenin bir on sayfasi (gazete.php?g=...) ve basliklari (bolumler)
 * var. Haber bolumleri kategoriye ve baslik/etiket kelimelerine gore
 * suzuluyor: haberin ayri bir "alt kategori" alani yok, kelime suzgusu
 * eski haberlere de geriye donuk isliyor. Baglanti bolumleri (puan durumu,
 * piyasa sayfalari, burclar) var olan sayfalara gidiyor; o sayfalar da
 * gazetenin kunyesini ve sekmelerini gosteriyor (gazete_ust_html).
 *
 * Kelime eslesmesi: baslik ve etiketlerin basina bosluk konup
 * "% kelime%" araniyor, yani kelime basi (Turkce ekler: "dolarin",
 * "Bitcoin'de" de eslesir). Sonunda bosluk olan kelime tam kelime
 * ("fed " Federasyon'u almasin). Karsilastirma veritabaninin
 * harmanlamasiyla: buyuk/kucuk harf ve aksan duyarsiz.
 */

/*
 * Kelime listeleri. Birden cok bolumde kullanilanlar burada.
 */
const GAZETE_DUNYA_KELIMELERI = [
    'abd', 'amerika', 'fed ', "fed'", 'avrupa', 'ecb', 'avro bölgesi', 'euro bölgesi',
    'çin', 'japonya', 'almanya', 'ingiltere', 'fransa', 'italya', 'rusya', 'hindistan',
    'brezilya', 'arjantin', 'kanada', 'imf', 'dünya bankası', 'küresel', 'wall street',
    'opec', 'trump', 'g20', 'g7', 'beyaz saray',
];

const GAZETE_EKONOMI_KELIMELERI = [
    'ekonomi', 'enflasyon', 'faiz', 'merkez bankası', 'borsa', 'piyasa', 'gümrük',
    'tarife', 'ticaret', 'ihracat', 'ithalat', 'büyüme', 'resesyon', 'petrol', 'opec',
    'dolar', 'bütçe', 'işsizlik', 'hisse', 'yatırım', 'şirket', 'imf', 'dünya bankası',
];

const GAZETE_DUNYA_BORSA_KELIMELERI = [
    'wall street', 'nasdaq', 'dow jones', 's&p', 'dax', 'ftse', 'nikkei', 'hang seng',
    'cac 40', 'avrupa borsa', 'asya borsa', 'abd borsa', 'new york borsa', 'küresel piyasa',
    'küresel borsa', 'dünya borsa',
];

const GAZETE_BASKETBOL_KELIMELERI = [
    'basketbol', 'nba', 'wnba', 'euroleague', 'eurocup', 'potada', 'pota', 'bsl ',
    '12 dev adam', 'fenerbahçe beko', 'anadolu efes',
];

const GAZETE_VOLEYBOL_KELIMELERI = [
    'voleybol', 'filenin', 'sultanlar ligi', 'efeler ligi', 'vnl ',
];

const GAZETE_DIGER_SPOR_KELIMELERI = [
    'tenis', 'formula', 'f1 ', 'motogp', 'atletizm', 'güreş', 'yüzme', 'boks', 'halter',
    'judo', 'taekwondo', 'okçuluk', 'olimpiyat', 'paralimpik', 'golf', 'ralli',
    'bisiklet', 'satranç', 'hentbol', 'buz pateni', 'kayak', 'e-spor', 'wimbledon',
];

/**
 * Gazeteler ve basliklari.
 *
 * Bolum turleri:
 *   haber    kosullara uyan haberler (kosullar VEYA'li); 'piyasa' verilmisse
 *            haberlerin ustunde o piyasa bolumunun tablolari
 *   baglanti var olan bir sayfa ('yol' => cagrilacak islev adi ve argumanlari)
 *
 * Kosul: ['kategoriler' => [...], 'kelimeler' => [...], 'haric' => [...]].
 * kelimeler bossa kategorinin butun haberleri.
 *
 * @return array<string,array{ad:string,ust:string,alt:string,aciklama:string,
 *               bolumler:array<string,array<string,mixed>>}>
 */
function gazeteler(): array
{
    static $liste = null;

    if ($liste !== null) {
        return $liste;
    }

    $sporDisi = array_merge(GAZETE_BASKETBOL_KELIMELERI, GAZETE_VOLEYBOL_KELIMELERI, GAZETE_DIGER_SPOR_KELIMELERI);

    $liste = [
        'valpapel' => [
            'ad'       => 'ValPapel',
            'ust'      => 'Val',
            'alt'      => 'Papel',
            'slogan'   => 'Valentra Ekonomi Gazetesi',
            'aciklama' => 'Türkiye ve dünya ekonomisi, döviz, altın, kripto paralar, Borsa İstanbul ve dünya borsaları: güncel haberler ve canlı piyasa verileri.',
            'bolumler' => [
                'turkiye' => [
                    'ad'       => 'Türkiye Ekonomisi',
                    'aciklama' => 'Türkiye ekonomisinden haberler: enflasyon, faiz, büyüme, bütçe, şirketler ve sektörler.',
                    'kosullar' => [['kategoriler' => ['ekonomi'], 'haric' => GAZETE_DUNYA_KELIMELERI]],
                ],
                'dunya' => [
                    'ad'       => 'Dünya Ekonomisi',
                    'aciklama' => 'Dünyadan ekonomi haberleri: ABD, Avrupa, Çin; merkez bankaları, ticaret ve emtia.',
                    'kosullar' => [
                        ['kategoriler' => ['ekonomi'], 'kelimeler' => GAZETE_DUNYA_KELIMELERI],
                        ['kategoriler' => ['dunya'], 'kelimeler' => GAZETE_EKONOMI_KELIMELERI],
                    ],
                ],
                'doviz' => [
                    'ad'       => 'Döviz',
                    'aciklama' => 'Dolar, euro ve diğer döviz kurlarındaki gelişmeler; canlı kurlar ve pariteler.',
                    'kosullar' => [['kategoriler' => ['ekonomi'], 'kelimeler' => [
                        'dolar', 'euro ', "euro'", 'avro', 'sterlin', 'döviz', 'kur ', 'kurlar', 'kurda',
                        'kuru ', 'yen ', 'yuan', 'parite',
                    ], 'haric' => ['bitcoin', 'kripto', 'ethereum']]],
                    'piyasa'   => 'doviz',
                ],
                'altin' => [
                    'ad'       => 'Altın',
                    'aciklama' => 'Gram, çeyrek ve ons altın, gümüş: haberler ve canlı fiyatlar.',
                    'kosullar' => [['kategoriler' => ['ekonomi'], 'kelimeler' => ['altın', 'ons ', 'gümüş', 'değerli maden']]],
                    'piyasa'   => 'altin',
                ],
                'kripto' => [
                    'ad'       => 'Kripto Paralar',
                    'aciklama' => 'Bitcoin, Ethereum ve diğer kripto paralar: haberler ve canlı fiyatlar.',
                    'kosullar' => [['kategoriler' => ['ekonomi', 'teknoloji', 'dunya'], 'kelimeler' => [
                        'kripto', 'bitcoin', 'ethereum', 'btc ', 'blokzincir', 'blok zincir', 'blockchain',
                        'stablecoin', 'binance', 'coin',
                    ]]],
                    'piyasa'   => 'kripto',
                ],
                'borsa' => [
                    'ad'       => 'Borsa',
                    'aciklama' => 'Borsa İstanbul: BIST 100, hisseler, halka arzlar, temettü ve şirket haberleri.',
                    'kosullar' => [['kategoriler' => ['ekonomi'], 'kelimeler' => [
                        'borsa', 'bist', 'hisse', 'endeks', 'halka arz', 'spk ', 'temettü', 'bedelsiz',
                    ], 'haric' => GAZETE_DUNYA_BORSA_KELIMELERI]],
                    'piyasa'   => 'bist',
                ],
                'dunya-borsalari' => [
                    'ad'       => 'Dünya Borsaları',
                    'aciklama' => 'Wall Street, Avrupa ve Asya borsalarından haberler; S&P 500, Nasdaq, DAX, Nikkei.',
                    'kosullar' => [['kategoriler' => ['ekonomi', 'dunya'], 'kelimeler' => GAZETE_DUNYA_BORSA_KELIMELERI]],
                    'piyasa'   => 'dunya',
                ],
                'emtia'       => ['ad' => 'Emtia',                'yol' => ['piyasa_bolum_yolu', 'petrol']],
                'gostergeler' => ['ad' => 'Ekonomik Göstergeler', 'yol' => ['piyasa_bolum_yolu', 'gostergeler']],
                'sirket'      => ['ad' => 'Şirket Analizi',       'yol' => ['sirket_yolu']],
                'piyasalar'   => ['ad' => 'Tüm Piyasalar',        'yol' => ['piyasalar_yolu']],
            ],
        ],
        'valspor' => [
            'ad'       => 'ValSpor',
            'ust'      => 'Val',
            'alt'      => 'Spor',
            'slogan'   => 'Valentra Spor Gazetesi',
            'aciklama' => 'Süper Lig, dünya futbolu, basketbol, voleybol ve diğer branşlar: güncel spor haberleri, puan durumu ve fikstür.',
            'bolumler' => [
                'futbol' => [
                    'ad'       => 'Futbol',
                    'aciklama' => 'Futbol haberleri: maçlar, transferler, milli takım.',
                    'kosullar' => [['kategoriler' => ['spor'], 'haric' => $sporDisi]],
                ],
                'super-lig' => [
                    'ad'       => 'Süper Lig',
                    'aciklama' => 'Trendyol Süper Lig: maçlar, kulüpler, transferler.',
                    'kosullar' => [['kategoriler' => ['spor'], 'kelimeler' => [
                        'süper lig', 'galatasaray', 'fenerbahçe', 'beşiktaş', 'trabzonspor', 'başakşehir',
                        'samsunspor', 'göztepe', 'kasımpaşa', 'eyüpspor', 'antalyaspor', 'alanyaspor',
                        'konyaspor', 'kayserispor', 'rizespor', 'gaziantep', 'kocaelispor',
                        'gençlerbirliği', 'karagümrük', 'tff',
                    ], 'haric' => $sporDisi]],
                ],
                'dunya-futbolu' => [
                    'ad'       => 'Dünya Futbolu',
                    'aciklama' => 'Avrupa ligleri ve kupaları, dünya futbolundan haberler.',
                    'kosullar' => [['kategoriler' => ['spor'], 'kelimeler' => [
                        'premier lig', 'premier league', 'la liga', 'serie a', 'bundesliga', 'ligue 1',
                        'şampiyonlar ligi', 'avrupa ligi', 'konferans ligi', 'uefa', 'fifa', 'real madrid',
                        'barcelona', 'manchester', 'liverpool', 'arsenal', 'chelsea', 'tottenham', 'bayern',
                        'dortmund', 'psg', 'juventus', 'milan ', 'inter ', 'napoli', 'atletico',
                        'dünya kupası', 'mls ', 'messi', 'ronaldo', 'mbappe', 'haaland',
                    ], 'haric' => $sporDisi]],
                ],
                'basketbol' => [
                    'ad'       => 'Basketbol',
                    'aciklama' => 'NBA, EuroLeague, Basketbol Süper Ligi ve 12 Dev Adam.',
                    'kosullar' => [['kategoriler' => ['spor'], 'kelimeler' => GAZETE_BASKETBOL_KELIMELERI]],
                ],
                'voleybol' => [
                    'ad'       => 'Voleybol',
                    'aciklama' => 'Filenin Sultanları, Sultanlar Ligi, Efeler Ligi ve dünya voleybolu.',
                    'kosullar' => [['kategoriler' => ['spor'], 'kelimeler' => GAZETE_VOLEYBOL_KELIMELERI]],
                ],
                'diger-sporlar' => [
                    'ad'       => 'Diğer Sporlar',
                    'aciklama' => 'Tenis, Formula 1, atletizm, güreş ve diğer branşlar.',
                    'kosullar' => [['kategoriler' => ['spor'], 'kelimeler' => GAZETE_DIGER_SPOR_KELIMELERI]],
                ],
                'puan-durumu'  => ['ad' => 'Puan Durumu',  'yol' => ['spor_bolum_yolu', 'puan-durumu'],  'anahtar' => 'spor-puan'],
                'fikstur'      => ['ad' => 'Fikstür',      'yol' => ['spor_bolum_yolu', 'fikstur'],      'anahtar' => 'spor-fikstur'],
                'gol-kralligi' => ['ad' => 'Gol Krallığı', 'yol' => ['spor_bolum_yolu', 'gol-kralligi'], 'anahtar' => 'spor-gol'],
            ],
        ],
        'valmagazin' => [
            'ad'       => 'ValMagazin',
            'ust'      => 'Val',
            'alt'      => 'Magazin',
            'slogan'   => 'Valentra Magazin Gazetesi',
            'aciklama' => 'Sanatçılardan haberler ve açıklamalar, sinema, dizi, müzik, kültür-sanat, günlük burç yorumları ve yemek tarifleri.',
            'bolumler' => [
                'sanatcilar' => [
                    'ad'       => 'Sanatçılar',
                    'aciklama' => 'Sanatçıların açıklamaları ve röportajları; yeni albüm, dizi, film ve konserleri.',
                    'kosullar' => [
                        ['kategoriler' => ['magazin']],
                        ['kategoriler' => ['kultur-sanat'], 'kelimeler' => [
                            'şarkıcı', 'oyuncu', 'sanatçı', 'röportaj', 'açıklama', 'konser', 'sahne',
                        ]],
                    ],
                ],
                'sanat' => [
                    'ad'       => 'Kültür-Sanat',
                    'aciklama' => 'Sergiler, tiyatro, edebiyat, kültürel miras ve sanat dünyasından haberler.',
                    'kosullar' => [['kategoriler' => ['kultur-sanat']]],
                ],
                'sinema-dizi' => [
                    'ad'       => 'Sinema ve Dizi',
                    'aciklama' => 'Vizyondaki filmler, diziler, platformlar ve festivaller.',
                    'kosullar' => [['kategoriler' => ['kultur-sanat', 'magazin'], 'kelimeler' => [
                        'film', 'dizi', 'sinema', 'netflix', 'vizyon', 'festival', 'oscar', 'altın portakal',
                        'gişe', 'disney', 'hbo', 'yönetmen',
                    ]]],
                ],
                'muzik' => [
                    'ad'       => 'Müzik',
                    'aciklama' => 'Yeni albümler, şarkılar, konserler ve müzik ödülleri.',
                    'kosullar' => [['kategoriler' => ['kultur-sanat', 'magazin'], 'kelimeler' => [
                        'müzik', 'albüm', 'şarkı', 'konser', 'klip', 'grammy', 'eurovision', 'turne',
                    ]]],
                ],
                'burclar'         => ['ad' => 'Burçlar', 'yol' => ['burclar_yolu'], 'anahtar' => 'burclar'],
                'yemek-tarifleri' => [
                    'ad'       => 'Yemek Tarifleri',
                    'aciklama' => 'Pratik ve geleneksel yemek tarifleri.',
                    'kosullar' => [['kategoriler' => ['yemek-tarifleri']]],
                ],
            ],
        ],
    ];

    // Gol kralligi yalnizca spor veri servisiyle (TFF'den yok).
    require_once __DIR__ . '/futbol.php';

    if (!futbol_api_acik()) {
        unset($liste['valspor']['bolumler']['gol-kralligi']);
    }

    return $liste;
}

function gazete_bul(string $anahtar): ?array
{
    return gazeteler()[$anahtar] ?? null;
}

/** Bolumun adresi: haber bolumu gazete sayfasinda, baglanti bolumu kendi sayfasinda. */
function gazete_bolum_yolu(string $gazete, string $bolum, int $sayfa = 1): string
{
    $b = gazeteler()[$gazete]['bolumler'][$bolum] ?? null;

    if ($b !== null && isset($b['yol'])) {
        [$islev, $arguman] = $b['yol'] + [1 => null];

        if (in_array($islev, ['piyasa_bolum_yolu'], true)) {
            require_once __DIR__ . '/piyasa_panel.php';
        } elseif ($islev === 'spor_bolum_yolu') {
            require_once __DIR__ . '/spor_panel.php';
        } elseif ($islev === 'burclar_yolu') {
            require_once __DIR__ . '/burclar.php';
        }

        return $arguman !== null ? $islev($arguman) : $islev();
    }

    return gazete_yolu($gazete, $bolum, $sayfa);
}

/** Bolumun menu anahtari ($aktifKategori ile karsilastirilan). */
function gazete_bolum_anahtari(string $gazete, string $bolum): string
{
    $b = gazeteler()[$gazete]['bolumler'][$bolum] ?? [];

    return (string) ($b['anahtar'] ?? ($bolum === '' ? $gazete : $gazete . '-' . $bolum));
}

/**
 * Haber bolumunun SQL kosulu (h: haberler, k: kategoriler). Bolum bos ya
 * da 'haberler' ise gazetenin butun haber bolumlerinin birlesimi.
 */
function gazete_kosulu(string $gazete, string $bolum): string
{
    $g = gazete_bul($gazete);

    if ($g === null) {
        return '0';
    }

    $kosullar = [];

    if ($bolum === '' || $bolum === 'haberler') {
        foreach ($g['bolumler'] as $b) {
            foreach ($b['kosullar'] ?? [] as $k) {
                $kosullar[] = $k;
            }
        }
    } else {
        $kosullar = $g['bolumler'][$bolum]['kosullar'] ?? [];
    }

    if ($kosullar === []) {
        return '0';
    }

    $metin = "CONCAT(' ', h.baslik, ' ', REPLACE(h.etiketler, ',', ' '), ' ')";
    $benzer = static fn (array $kelimeler): string => implode(' OR ', array_map(
        static fn (string $k): string => $metin . ' LIKE ' . db()->quote('% ' . addcslashes($k, '%_\\') . '%'),
        $kelimeler
    ));

    $parcalar = [];

    foreach ($kosullar as $k) {
        $parca = 'k.slug IN (' . implode(', ', array_map(
            static fn (string $s): string => db()->quote($s),
            $k['kategoriler']
        )) . ')';

        if (!empty($k['kelimeler'])) {
            $parca .= ' AND (' . $benzer($k['kelimeler']) . ')';
        }

        if (!empty($k['haric'])) {
            $parca .= ' AND NOT (' . $benzer($k['haric']) . ')';
        }

        $parcalar[] = '(' . $parca . ')';
    }

    return '(' . implode(' OR ', array_unique($parcalar)) . ')';
}

/**
 * Bolumun yayindaki haberleri, sitenin genel haber sirasiyla.
 *
 * @param list<int> $haric On sayfada ust bloklarda gosterilen haberler:
 *        ayni haber iki blokta cikmasin.
 * @return list<array<string,mixed>>
 */
function gazete_haberleri(string $gazete, string $bolum, int $limit, int $atla = 0, array $haric = []): array
{
    try {
        $haricKosul = $haric !== []
            ? ' AND h.id NOT IN (' . implode(', ', array_map('intval', $haric)) . ')'
            : '';

        $ifade = db()->prepare(
            'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
               FROM haberler h
               JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = :durum AND ' . gazete_kosulu($gazete, $bolum) . $haricKosul . '
              ORDER BY ' . haber_sirasi() . '
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $atla)
        );
        $ifade->execute(['durum' => HABER_YAYINDA]);

        return $ifade->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] gazete haberleri (' . $gazete . '/' . $bolum . '): ' . $e->getMessage());

        return [];
    }
}

function gazete_haber_sayisi(string $gazete, string $bolum): int
{
    try {
        $ifade = db()->prepare(
            'SELECT COUNT(*)
               FROM haberler h
               JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = :durum AND ' . gazete_kosulu($gazete, $bolum)
        );
        $ifade->execute(['durum' => HABER_YAYINDA]);

        return (int) $ifade->fetchColumn();
    } catch (Throwable $e) {
        error_log('[valentra] gazete haber sayisi (' . $gazete . '/' . $bolum . '): ' . $e->getMessage());

        return 0;
    }
}

/**
 * Gazetenin kunyesi ve bolum sekmeleri. Gazete sayfasi, spor bolumleri,
 * piyasa sayfalari ve burclar ayni ustu gosteriyor: okuyucu hangi
 * gazetede oldugunu ve oteki basliklari gorsun.
 *
 * @param string $aktif Bolum anahtari ('' on sayfa).
 */
function gazete_ust_html(string $gazete, string $aktif = ''): string
{
    $g = gazete_bul($gazete);

    if ($g === null) {
        return '';
    }

    $html = '<header class="gazete-kunye gazete-' . e($gazete) . '">'
          . '<a class="gazete-ad" href="' . e(gazete_yolu($gazete)) . '">'
          . '<span class="ust">' . e($g['ust']) . '</span><span class="alt">' . e($g['alt']) . '</span></a>'
          . '<span class="gazete-slogan">' . e($g['slogan']) . ' · ' . e(tarih_uzun_bugun()) . '</span>'
          . '</header>';

    $html .= '<nav class="spor-sekmeler gazete-sekmeler" aria-label="' . e($g['ad']) . ' başlıkları">'
           . '<a href="' . e(gazete_yolu($gazete)) . '"' . ($aktif === '' ? ' class="aktif" aria-current="page"' : '') . '>Ön Sayfa</a>';

    foreach ($g['bolumler'] as $anahtar => $b) {
        $html .= '<a href="' . e(gazete_bolum_yolu($gazete, $anahtar)) . '"'
               . ($anahtar === $aktif ? ' class="aktif" aria-current="page"' : '') . '>' . e($b['ad']) . '</a>';
    }

    return $html . '</nav>';
}

/** "29 Ekim 2026 Perşembe" */
function tarih_uzun_bugun(): string
{
    $aylar   = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $gunler  = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
    $simdi   = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

    return $simdi->format('j') . ' ' . $aylar[(int) $simdi->format('n')] . ' ' . $simdi->format('Y')
         . ' ' . $gunler[(int) $simdi->format('w')];
}
