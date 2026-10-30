<?php
declare(strict_types=1);

/**
 * Köşe yazarları.
 *
 * Site sahibinin istegi (23.10.2026): "her gun 6-7 kose yazisi; her yazi
 * farkli birisi tarafindan; yazilari gazetenin en saginda dursun, gecmis
 * yazilarina da okuyucular ulasabilsin; herkes birer konuda yazsin".
 * Yazarlarin adlari site sahibinin verdigi adlar; yazilari ajan, her
 * yazarin alaninda ve uslubuyla yaziyor (ajan/kose.php). Portreler
 * fotograf degil, asagida koddan uretilen cizim (yazar_portre_svg).
 *
 * Portre: yazarin FOTOGRAFI varsa o (gorseller/yazar/<anahtar>.jpg; ajan
 * bir kez uretip yukluyor, bkz. ajan/portre.php ve api/yazar-portre.php,
 * gorunum alani uretim tarifi), yoksa cizim. Site sahibinin karari
 * (23.10.2026): fotograflar gercekci; yazar sayfasinda tek satir not
 * (yazar.php).
 *
 * Sira: site sahibinin saydigi sira; "Valentra Yorumu" en sonda.
 * kategoriler: yazarin konusunu sectigi haber basliklari (bos: hepsi).
 * anahtar_kelimeler / haric_kelimeler: haberin basliginda ya da ozetinde
 * gecmesi gereken / gecmemesi gereken sozcukler (kulup ve basketbol
 * yazarlari; kucuk harfle). spor: spor sayfasindaki "Spor Yazarlari".
 * uslup: ajanin yazi istemine eklenen kalem tarifi.
 */
const KOSE_YAZARLARI = [
    'ahmet-sami-gumus' => [
        'ad'          => 'Ahmet Sami Gümüş',
        'alan'        => 'Ekonomi ve piyasalar',
        'kategoriler' => ['ekonomi'],
        'tanitim'     => 'Faiz, enflasyon, kur, borsa ve şirketler: ekonomideki gelişmelerin cebimize ve işimize etkisini yazıyor.',
        'uslup'       => 'Veriye dayalı, sade ve öğretici. Rakamları ve kavramları okurun gündelik hayatından örneklerle açıklar; '
                       . '"bu kararın market fiyatına, krediye, maaşa etkisi ne?" sorusunu hep sorar. Yatırım tavsiyesi vermez.',
        'gorunum'     => 'a Turkish man in his early fifties, short neatly side-parted brown hair, rectangular glasses, clean-shaven, wearing a dark navy suit and light blue shirt',
        'portre'      => ['zemin' => '#1f4e79', 'ten' => '#e8b98e', 'sac' => '#4a3426', 'sac_bicim' => 'yan', 'giysi' => '#26303f', 'gomlek' => '#dfe7f1', 'gozluk' => true, 'sakal' => false],
    ],
    'tekin-alp-sagduyulu' => [
        'ad'          => 'Tekin Alp Sağduyulu',
        'alan'        => 'İç politika',
        'kategoriler' => ['politika'],
        'tanitim'     => 'Türkiye siyasetinde günün gelişmelerini ölçülü bir dille, kararların içeriği ve olası etkileri üzerinden değerlendiriyor.',
        'uslup'       => 'Ölçülü, dengeli ve sağduyulu. Tarafların açıklanmış tutumlarını eşit mesafeden aktarır, hiçbir partiyi ya da '
                       . 'kişiyi övmez, yermez; kararın içeriğini, sürecini ve vatandaşa etkisini tartışır. Uzlaşmayı ve sağduyuyu öne çıkarır.',
        'gorunum'     => 'a Turkish man in his early sixties, short silver-grey hair, short neatly trimmed white beard, wearing a charcoal jacket and white shirt',
        'portre'      => ['zemin' => '#7a2e2e', 'ten' => '#d9a47c', 'sac' => '#b8b8b8', 'sac_bicim' => 'kisa', 'giysi' => '#2e3440', 'gomlek' => '#f2f2f2', 'gozluk' => false, 'sakal' => true],
    ],
    'sevket-gurkan-cokyazar' => [
        'ad'          => 'Şevket Gürkan Çokyazar',
        'alan'        => 'Dünya ve dış politika',
        'kategoriler' => ['dunya'],
        'tanitim'     => 'Uluslararası gelişmeleri tarihsel arka planı ve bölgesel dengeleriyle ele alıyor; Türkiye\'ye olası yansımalarını yazıyor.',
        'uslup'       => 'Geniş perspektifli ve tarihsel bağlam kuran. Gelişmeyi bölgesel ve küresel dengeler içinde okur, '
                       . 'Türkiye\'ye olası yansımalarını ayrıca tartışır; kesin hüküm yerine senaryolar sunar.',
        'gorunum'     => 'a Turkish man in his mid forties, short black hair, thin metal-framed glasses, light stubble, wearing a dark grey blazer and beige shirt',
        'portre'      => ['zemin' => '#2f5d50', 'ten' => '#c98f68', 'sac' => '#1e1e1e', 'sac_bicim' => 'kisa', 'giysi' => '#3b3f4a', 'gomlek' => '#e9e4d8', 'gozluk' => true, 'sakal' => false],
    ],
    'gamze-ilkmen-korukoylu' => [
        'ad'          => 'Gamze İlkmen Koruköylü',
        'alan'        => 'Toplum, sağlık ve eğitim',
        'kategoriler' => ['gundem', 'saglik'],
        'tanitim'     => 'Gündemin insana dokunan yüzünü yazıyor: sağlık, eğitim, aile, şehir hayatı ve vatandaşın günlük yaşamı.',
        'uslup'       => 'İnsan odaklı, sıcak ama ölçülü. Gelişmeyi vatandaşın, ailelerin, öğrencilerin, hastaların gözünden anlatır; '
                       . 'pratik sonuçlarını ve dikkat edilmesi gerekenleri açıkça söyler.',
        'gorunum'     => 'a Turkish woman in her mid forties, shoulder-length chestnut brown hair, warm expression, wearing a navy blazer and cream blouse',
        'portre'      => ['zemin' => '#8a5a9e', 'ten' => '#efc39f', 'sac' => '#6b3e26', 'sac_bicim' => 'uzun', 'giysi' => '#3d5a80', 'gomlek' => '#f5efe6', 'gozluk' => false, 'sakal' => false],
    ],
    'sirin-ebru-berrakgol' => [
        'ad'          => 'Şirin Ebru Berrakgöl',
        'alan'        => 'Kültür, sanat ve yaşam',
        'kategoriler' => ['kultur-sanat', 'teknoloji'],
        'tanitim'     => 'Kültür, sanat, teknoloji ve yaşamdaki yenilikleri; kitapları, sergileri ve gündelik hayatı değiştiren gelişmeleri yazıyor.',
        'uslup'       => 'Akıcı, merak uyandıran ve zarif. Gelişmeyi kültürel bağlamı içinde anlatır, okuru düşünmeye davet eder; '
                       . 'eleştiride bile kırıcı değildir.',
        'gorunum'     => 'a Turkish woman in her mid thirties, dark brown hair in a loose bun, round tortoiseshell glasses, wearing a burgundy blouse',
        'portre'      => ['zemin' => '#b5651d', 'ten' => '#f3cfae', 'sac' => '#2b1b14', 'sac_bicim' => 'topuz', 'giysi' => '#5b2a4a', 'gomlek' => '#f0d9c4', 'gozluk' => true, 'sakal' => false],
    ],
    'hasan-yavuz-osmanoglu' => [
        'ad'          => 'Hasan Yavuz Osmanoğlu',
        'alan'        => 'Futbol',
        'kategoriler' => ['spor'],
        'haric_kelimeler' => ['basketbol', 'euroleague', 'eurocup', 'nba', 'potada'],
        'spor'        => true,
        'tanitim'     => 'Günün maçlarını, ligi ve futbolun gündemini taktik ve teknik yönleriyle, hiçbir kulübün tarafını tutmadan yazıyor.',
        'uslup'       => 'Tutkulu ama tarafsız. Maçı ve gelişmeyi taktik, kadro ve istatistik üzerinden çözümler; hiçbir kulübü, '
                       . 'oyuncuyu ya da hakemi hedef göstermez, taraftar diliyle değil yorumcu diliyle yazar.',
        'gorunum'     => 'a Turkish man in his early forties, short black hair, neatly trimmed full black beard, wearing a dark green crew-neck sweater',
        'portre'      => ['zemin' => '#2d6a4f', 'ten' => '#b97a56', 'sac' => '#111111', 'sac_bicim' => 'kisa', 'giysi' => '#1b4332', 'gomlek' => '#e8f1ea', 'gozluk' => false, 'sakal' => true],
    ],
    /*
     * SPOR YORUMCULARI (site sahibinin istegi, 25.10.2026: "spor
     * sayfasinda da yorumcular yapalim; basketbol, futbol alanlarinda
     * gunun maclari hakkinda yorum yapsinlar; Galatasaray, Fenerbahce,
     * Besiktas, Trabzonspor'un haftalik maclarini, hepsinde birer kisi
     * olusturalim; bunlari kose yazari olarak sunalim").
     *
     * Kulup yazarlari yalnizca basliginda ya da ozetinde kulubun adi
     * gecen spor haberlerinden konu seciyor (anahtar_kelimeler); kulubun
     * o gun haberi yoksa yazmiyor. Kulubu yakindan izleyen yorumcu, ama
     * rakiplere, hakemlere ve taraftarlara saygili (kose yayin politikasi
     * aynen gecerli). Adlar ve gorunumler uydurma; site sahibi
     * degistirebilir.
     */
    'baris-can-yildirimer' => [
        'ad'          => 'Barış Can Yıldırımer',
        'alan'        => 'Basketbol',
        'kategoriler' => ['spor'],
        'anahtar_kelimeler' => ['basketbol', 'euroleague', 'eurocup', 'nba', 'potada', 'smaç', 'ribaund'],
        'spor'        => true,
        'tanitim'     => 'Basketbol Süper Ligi, EuroLeague ve milli takım: günün maçlarını oyun planı, rotasyon ve istatistikleriyle yorumluyor.',
        'uslup'       => 'Dinamik ve analitik. Maçı periyot periyot, hücum ve savunma setleri, rotasyon ve istatistikler üzerinden '
                       . 'anlatır; oyuncuları ve antrenörleri hedef göstermez, hakem kararlarını tartışırken ölçülüdür.',
        'gorunum'     => 'a tall Turkish man in his late thirties, short dark brown hair, clean-shaven, wearing a navy quarter-zip sports pullover',
        'portre'      => ['zemin' => '#c2571a', 'ten' => '#d9a47c', 'sac' => '#3b2a1f', 'sac_bicim' => 'kisa', 'giysi' => '#1f3354', 'gomlek' => '#e8edf4', 'gozluk' => false, 'sakal' => false],
    ],
    'cem-aslan-karakaya' => [
        'ad'          => 'Cem Aslan Karakaya',
        'alan'        => 'Galatasaray',
        'kategoriler' => ['spor'],
        'anahtar_kelimeler' => ['galatasaray', 'cimbom'],
        'spor'        => true,
        'tanitim'     => 'Galatasaray\'ın maçlarını, kadrosunu ve haftalık gündemini yakından izliyor.',
        'uslup'       => 'Galatasaray\'ı yakından izleyen bir yorumcu. Maçı takımın oyun planı, kadro tercihleri ve performans '
                       . 'üzerinden değerlendirir; rakip kulüplere, hakemlere ve taraftarlara saygılıdır, kışkırtıcı taraftar dili kullanmaz.',
        'gorunum'     => 'a Turkish man in his mid forties, short wavy dark hair with grey at the temples, light stubble, wearing a dark red knit sweater',
        'portre'      => ['zemin' => '#a90432', 'ten' => '#d8a07a', 'sac' => '#2a221d', 'sac_bicim' => 'kisa', 'giysi' => '#5c0b1e', 'gomlek' => '#f2c94c', 'gozluk' => false, 'sakal' => true],
    ],
    'murat-kaan-sarigul' => [
        'ad'          => 'Murat Kaan Sarıgül',
        'alan'        => 'Fenerbahçe',
        'kategoriler' => ['spor'],
        'anahtar_kelimeler' => ['fenerbahçe', 'fenerbahce', 'kanarya'],
        'spor'        => true,
        'tanitim'     => 'Fenerbahçe\'nin maçlarını, kadrosunu ve haftalık gündemini yakından izliyor.',
        'uslup'       => 'Fenerbahçe\'yi yakından izleyen bir yorumcu. Maçı takımın oyun planı, kadro tercihleri ve performans '
                       . 'üzerinden değerlendirir; rakip kulüplere, hakemlere ve taraftarlara saygılıdır, kışkırtıcı taraftar dili kullanmaz.',
        'gorunum'     => 'a Turkish man in his early fifties, short neatly combed black hair, rectangular dark-framed glasses, clean-shaven, wearing a navy blazer and white shirt',
        'portre'      => ['zemin' => '#0b2a5b', 'ten' => '#e2b08a', 'sac' => '#1e1e1e', 'sac_bicim' => 'yan', 'giysi' => '#13204a', 'gomlek' => '#f5d000', 'gozluk' => true, 'sakal' => false],
    ],
    'kartal-emre-aksoy' => [
        'ad'          => 'Kartal Emre Aksoy',
        'alan'        => 'Beşiktaş',
        'kategoriler' => ['spor'],
        'anahtar_kelimeler' => ['beşiktaş', 'besiktas', 'kara kartal'],
        'spor'        => true,
        'tanitim'     => 'Beşiktaş\'ın maçlarını, kadrosunu ve haftalık gündemini yakından izliyor.',
        'uslup'       => 'Beşiktaş\'ı yakından izleyen bir yorumcu. Maçı takımın oyun planı, kadro tercihleri ve performans '
                       . 'üzerinden değerlendirir; rakip kulüplere, hakemlere ve taraftarlara saygılıdır, kışkırtıcı taraftar dili kullanmaz.',
        'gorunum'     => 'a Turkish man in his late forties, short salt-and-pepper hair, short trimmed beard, wearing a black jacket over a white t-shirt',
        'portre'      => ['zemin' => '#1c1c1c', 'ten' => '#cf9a74', 'sac' => '#6e6e6e', 'sac_bicim' => 'kisa', 'giysi' => '#111111', 'gomlek' => '#f4f4f4', 'gozluk' => false, 'sakal' => true],
    ],
    'hakan-omer-karadenizli' => [
        'ad'          => 'Hakan Ömer Karadenizli',
        'alan'        => 'Trabzonspor',
        'kategoriler' => ['spor'],
        'anahtar_kelimeler' => ['trabzonspor', 'bordo-mavi', 'bordo mavi'],
        'spor'        => true,
        'tanitim'     => 'Trabzonspor\'un maçlarını, kadrosunu ve haftalık gündemini yakından izliyor.',
        'uslup'       => 'Trabzonspor\'u yakından izleyen bir yorumcu. Maçı takımın oyun planı, kadro tercihleri ve performans '
                       . 'üzerinden değerlendirir; rakip kulüplere, hakemlere ve taraftarlara saygılıdır, kışkırtıcı taraftar dili kullanmaz.',
        'gorunum'     => 'a Turkish man in his early forties, short black hair, thick dark moustache and short beard, wearing a burgundy crew-neck sweater',
        'portre'      => ['zemin' => '#6d1a36', 'ten' => '#c98f68', 'sac' => '#151515', 'sac_bicim' => 'kisa', 'giysi' => '#5a1630', 'gomlek' => '#8ecae6', 'gozluk' => false, 'sakal' => true],
    ],
    'valentra' => [
        'ad'          => 'Valentra Yorumu',
        'alan'        => 'Günün en önemli konusu',
        'kategoriler' => [],
        'tanitim'     => 'Valentra yayın kurulunun günün en önemli konusuna dair değerlendirmesi.',
        'uslup'       => 'Kurumsal, sakin ve çözümleyici: ne oldu, neden önemli, arka planı ne, bundan sonra ne olabilir.',
        'portre'      => null,
    ],
];

/** Yazar fotoğraflarının klasörü (site kökünden). */
const YAZAR_FOTO_YOLU = '/gorseller/yazar';

/** Site sahibinin verdigi fotograflar (depoda); ajanin urettiginden once gelir. */
const YAZAR_SECILI_FOTO_YOLU = '/assets/yazarlar';

/** Yazarın yüklenmiş fotoğrafı (site içi yol); yoksa null. */
function yazar_foto(string $anahtar): ?string
{
    if (!preg_match('/^[a-z0-9-]+$/', $anahtar)) {
        return null;
    }

    // Once site sahibinin sectigi fotograf (depoda, deploy ile gelir),
    // yoksa ajanin urettigi (sunucuda).
    foreach ([YAZAR_SECILI_FOTO_YOLU, YAZAR_FOTO_YOLU] as $yol) {
        $dosya = dirname(__DIR__) . $yol . '/' . $anahtar . '.jpg';

        // Surum: dosya yenilenince tarayici eskisini gostermesin.
        if (is_file($dosya)) {
            return $yol . '/' . $anahtar . '.jpg?s=' . filemtime($dosya);
        }
    }

    return null;
}

/** Varsayılan yazar (eski yazılar, bilinmeyen anahtar). */
const KOSE_VARSAYILAN_YAZAR = 'valentra';

/** @return array<string,mixed>|null */
function yazar_bul(string $anahtar): ?array
{
    return isset(KOSE_YAZARLARI[$anahtar]) ? KOSE_YAZARLARI[$anahtar] + ['anahtar' => $anahtar] : null;
}

/** Yazının yazarı; bilinmiyorsa Valentra Yorumu. */
function yazar_yazinin(array $yazi): array
{
    return yazar_bul((string) ($yazi['yazar'] ?? '')) ?? yazar_bul(KOSE_VARSAYILAN_YAZAR);
}

function yazar_yolu(string $anahtar, int $sayfa = 1): string
{
    $yol = temiz_adres_acik()
        ? '/yazar/' . rawurlencode($anahtar)
        : '/yazar.php?y=' . rawurlencode($anahtar);

    return $sayfa > 1 ? $yol . (str_contains($yol, '?') ? '&' : '?') . 'sayfa=' . $sayfa : $yol;
}

/**
 * Yazarın portresi: fotoğrafı varsa o (<img>), yoksa çizim (SVG, satır içi).
 *
 * Fotograf degil, duz renkli stilize bir bust: zemin dairesi, giysi,
 * boyun, bas, sac, goz, kas, gulumseme; yazara gore gozluk ve sakal.
 * Degerler yazarin tanimindan (KOSE_YAZARLARI[...]['portre']); rastgele
 * degil, her yuklemede ayni. Valentra Yorumu icin sitenin logosu.
 */
function yazar_portre_svg(string $anahtar, int $boy = 64): string
{
    $yazar = yazar_bul($anahtar);
    $p     = $yazar['portre'] ?? null;
    $foto  = $yazar !== null ? yazar_foto($anahtar) : null;

    if ($foto !== null) {
        return '<img class="yazar-portre foto" src="' . e($foto) . '" alt="' . e((string) $yazar['ad']) . '" width="'
            . $boy . '" height="' . $boy . '" loading="lazy">';
    }

    if (!is_array($p)) {
        return '<img class="yazar-portre logo" src="/assets/logo.svg" alt="" width="' . $boy . '" height="' . $boy . '">';
    }

    $r = static fn (string $k): string => e((string) $p[$k]);

    // Sac: arka katman (uzun sacta omuza dusen kisim) bastan ONCE cizilir.
    $sacArka = $p['sac_bicim'] === 'uzun'
        ? '<path d="M30 46C28 24 40 17 50 17C62 17 72 24 70 46L73 74C66 73 62 68 62 61L62 42 38 42 38 61C38 68 34 73 27 74Z" fill="' . $r('sac') . '"/>'
        : '';

    $sacOn = match ($p['sac_bicim']) {
        'yan'   => '<path d="M33 45C32 30 40 23 50 23C60 23 68 30 67 45C66 39 64 36 62 35L62 31C57 28 43 28 38 31L38 35C36 36 34 39 33 45Z" fill="' . $r('sac') . '"/>',
        'uzun'  => '<path d="M33 44C32 27 41 20 51 20C61 20 69 27 67 44C63 34 57 30 49 30C43 33 38 37 33 44Z" fill="' . $r('sac') . '"/>',
        'topuz' => '<circle cx="50" cy="19" r="8" fill="' . $r('sac') . '"/>'
                 . '<path d="M33 43C32 28 41 22 50 22C60 22 68 28 67 43C63 33 57 29 50 29C43 29 37 33 33 43Z" fill="' . $r('sac') . '"/>',
        default => '<path d="M33 42C32 26 42 20 51 20C61 20 69 27 67 42C64 34 58 30 50 30C42 30 36 34 33 42Z" fill="' . $r('sac') . '"/>',
    };

    $sakal = $p['sakal']
        ? '<path d="M34 46C34 60 41 66 50 66C59 66 66 60 66 46C63 54 58 57 50 57C42 57 37 54 34 46Z" fill="' . $r('sac') . '" opacity=".85"/>'
        : '';

    $gozluk = $p['gozluk']
        ? '<g fill="none" stroke="#1d232b" stroke-width="1.6"><circle cx="43" cy="45" r="5"/><circle cx="57" cy="45" r="5"/><path d="M48 45H52"/></g>'
        : '';

    return '<svg class="yazar-portre" viewBox="0 0 100 100" width="' . $boy . '" height="' . $boy . '" role="img" aria-label="'
        . e((string) $yazar['ad']) . '">'
        . '<circle cx="50" cy="50" r="50" fill="' . $r('zemin') . '"/>'
        . '<clipPath id="yp-' . e($anahtar) . '-' . $boy . '"><circle cx="50" cy="50" r="50"/></clipPath>'
        . '<g clip-path="url(#yp-' . e($anahtar) . '-' . $boy . ')">'
        . $sacArka
        . '<path d="M14 100C17 79 33 71 50 71C67 71 83 79 86 100Z" fill="' . $r('giysi') . '"/>'
        . '<path d="M42 72L50 85 58 72Z" fill="' . $r('gomlek') . '"/>'
        . '<rect x="44" y="57" width="12" height="16" rx="4" fill="' . $r('ten') . '"/>'
        . '<ellipse cx="50" cy="45" rx="17" ry="20" fill="' . $r('ten') . '"/>'
        . $sakal
        . $sacOn
        . '<g fill="#1d232b"><circle cx="43" cy="45" r="1.7"/><circle cx="57" cy="45" r="1.7"/></g>'
        . '<path d="M39 39.5Q43 37.5 47 39.5M53 39.5Q57 37.5 61 39.5" fill="none" stroke="#1d232b" stroke-width="1.3" stroke-linecap="round" opacity=".7"/>'
        . '<path d="M44 54Q50 58 56 54" fill="none" stroke="#7a3b2e" stroke-width="1.6" stroke-linecap="round"/>'
        . $gozluk
        . '</g></svg>';
}
