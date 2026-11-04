<?php
declare(strict_types=1);

/**
 * Mac ozetleri (04.11.2026, site sahibi: "maclar bittikten sonra
 * YouTube'a dusen ozetleri alalim, ana sayfaya koymadan yayinlayalim,
 * Google'a da ekletelim; en cok aranan haberler bunlar; spor sayfasinda
 * bir alan, YouTube linki ile seyrettirelim").
 *
 * Kaynak: yayinci kanallarinin YouTube beslemesi (panelden duzenlenen
 * liste, MAC_OZETI_VARSAYILAN_KANALLAR). Ajan (ajan/mac_ozetleri.php)
 * basliginda "ozet" gecen videolari api/mac-ozeti.php'ye gonderiyor.
 *
 * Haber degil: haberler tablosuna girmiyor, ana sayfa ve haber akisi
 * gormuyor. ValSpor > Mac Ozetleri (/mac-ozetleri) ve her ozetin kendi
 * sayfasi (/mac-ozeti/{slug}): YouTube oynaticisi (youtube-nocookie),
 * VideoObject yapisal verisi, site haritasi. Video YouTube'dan oynuyor;
 * dosya kopyalanmiyor.
 */

require_once __DIR__ . '/adres.php';
require_once __DIR__ . '/ayarlar.php';

/** Varsayilan kanallar (panelden degistirilebilir; bkz. admin/mac-ozetleri.php). */
const MAC_OZETI_VARSAYILAN_KANALLAR = [
    'https://www.youtube.com/@beINSPORTSTurkiye',
    'https://www.youtube.com/@trtspor',
    'https://www.youtube.com/@ASpor',
    // Duzeltilmis adresler (04.11.2026): eski @adlar okunamiyordu. Exxen Spor
    // cikti (son ozetleri iki yil once).
    'https://www.youtube.com/ssport',
    'https://www.youtube.com/channel/UCzzylIZKbeIDhohxAaQFy1Q',
    // Ingiltere ve Ispanya (04.11.2026, site sahibi: "Ingiltere ve Ispanya liglerini de ekleyebilirsin").
    'https://www.youtube.com/@premierleague',
    'https://www.youtube.com/@LaLiga',
    // Dunya (04.11.2026, site sahibi: "dunyadaki tum kanallara da bak; anlatimi
    // yabanci dilde olabilir; onemli olan Galatasaray, Fenerbahce ve diger onemli
    // liglerin ozetleri"). Ligler, Avrupa kupalari ve buyuk kuluplerin resmi kanallari.
    'https://www.youtube.com/@SerieA',
    'https://www.youtube.com/@bundesliga',
    'https://www.youtube.com/@Ligue1',
    // Eredivisie ozetleri ESPN NL kanalinda (maclardan hemen sonra).
    'https://www.youtube.com/user/EredivisieLive',
    'https://www.youtube.com/@CBSSportsGolazo',
    'https://www.youtube.com/@UEFA',
    'https://www.youtube.com/@Galatasaray',
    'https://www.youtube.com/@Fenerbahce',
    'https://www.youtube.com/@Besiktas',
    'https://www.youtube.com/@Trabzonspor',
];

/** One cikan takimlar: ozet seridinde ve listede once (son 3 gun). */
const MAC_OZETI_ONE_CIKAN = ['galatasaray', 'fenerbahçe', 'fenerbahce', 'beşiktaş', 'besiktas', 'trabzonspor', 'türkiye', 'turkey', 'başakşehir', 'basaksehir'];

const MAC_OZETI_SAYFA = 24;

function mac_ozetleri_yolu(int $sayfa = 1): string
{
    $yol = temiz_adres_acik() ? '/mac-ozetleri' : '/mac-ozetleri.php';

    return $sayfa > 1 ? $yol . '?sayfa=' . $sayfa : $yol;
}

function mac_ozeti_yolu(string $slug): string
{
    return temiz_adres_acik() ? '/mac-ozeti/' . rawurlencode($slug) : '/mac-ozetleri.php?o=' . rawurlencode($slug);
}

/** @return list<string> */
function mac_ozeti_kanallari(): array
{
    $kayitli = trim(ayar_oku('mac_ozeti_kanallari'));

    if ($kayitli === '') {
        return MAC_OZETI_VARSAYILAN_KANALLAR;
    }

    /*
     * Panelde kayitli listede okunamayan eski adresler varsa dogrusuyla
     * degistir (04.11.2026: S Sport, Tivibu Spor, Eredivisie okunamiyordu;
     * Exxen Spor iki yildir ozet yuklemiyor).
     */
    $duzeltme = [
        'https://www.youtube.com/@ssporturkiye' => 'https://www.youtube.com/ssport',
        'https://www.youtube.com/@tivibuspor'   => 'https://www.youtube.com/channel/UCzzylIZKbeIDhohxAaQFy1Q',
        'https://www.youtube.com/@eredivisie'   => 'https://www.youtube.com/user/EredivisieLive',
        'https://www.youtube.com/@ExxenSpor'    => '',
    ];

    $liste = array_map(static fn (string $s): string => $duzeltme[trim($s)] ?? trim($s), preg_split('/\R/u', $kayitli) ?: []);

    return array_values(array_unique(array_filter($liste, static fn (string $s): bool => $s !== '')));
}

/** Basliktan mac ozeti mi? (Kisa videolar ve canli yayinlar disarida.) */
function mac_ozeti_mi(string $baslik): bool
{
    $b = mb_strtolower($baslik, 'UTF-8');

    if (preg_match('/#shorts|canlı yayın|canli yayin|basın toplantısı|röportaj|antrenman|tahmin|press conference|interview|reaction|preview|rueda de prensa|entrevista|previa'
        . '|pressekonferenz|conférence de presse|conferenza stampa|persconferentie|conferência de imprensa|training|behind the scenes|tunnel cam/u', $b)) {
        return false;
    }

    // Dillere gore "ozet": Ingilizce highlights, Ispanyolca resumen, Italyanca sintesi,
    // Almanca Zusammenfassung, Fransizca resume, Portekizce resumo, Hollandaca samenvatting.
    return preg_match('/(?:^|[\s|\-–—:(\[])(?:maç\s+özeti|geniş\s+özet|özet|özeti|highlights|resumen|sintesi|zusammenfassung|résumé|resume|resumo|samenvatting)(?:$|[\s|\-–—:)\].!,])/u', $b) === 1;
}

/**
 * Basliktan takimlar ve skor: "Galatasaray 3-1 Fenerbahçe | Özet", "ÖZET |
 * Beşiktaş 2 - 0 Konyaspor". Bulunamazsa bos.
 *
 * @return array{ev:string,deplasman:string,skor:string}
 */
function mac_ozeti_coz(string $baslik): array
{
    $temiz = preg_replace('/\s*[|│]\s*/u', ' | ', $baslik) ?? $baslik;

    foreach (explode(' | ', $temiz) as $parca) {
        if (preg_match('/^\s*(?:(?:maç\s+)?özet[i]?\s*[:\-–]\s*)?([\p{L}\p{M}.\'’ ]{2,40}?)\s+(\d{1,2})\s*[-–:]\s*(\d{1,2})\s+([\p{L}\p{M}.\'’ ]{2,40}?)\s*(?:[-–(].*)?$/iu', $parca, $e)) {
            $ev  = trim($e[1]);
            $dep = trim(preg_replace('/\s+(?:(?:maç\s+)?(?:geniş\s+)?özet[i]?|(?:extended\s+)?highlights|resumen(?:\s+y\s+goles)?)$/iu', '', $e[4]) ?? $e[4]);

            return ['ev' => mb_substr($ev, 0, 80), 'deplasman' => mb_substr($dep, 0, 80), 'skor' => $e[2] . '-' . $e[3]];
        }
    }

    // Italyan, Alman, Fransiz bicimi: "Juventus-Inter 2-1", "Bayern München - Borussia Dortmund 3:1", "PSG - OM (2-0)".
    foreach (explode(' | ', $temiz) as $parca) {
        if (preg_match('/^\s*([\p{L}\p{M}.\'’ ]{2,30}?)\s*-\s*([\p{L}\p{M}.\'’ ]{2,30}?)\s+\(?(\d{1,2})\s*[-:]\s*(\d{1,2})\)?\s*$/u', $parca, $e)) {
            return ['ev' => mb_substr(trim($e[1]), 0, 80), 'deplasman' => mb_substr(trim($e[2]), 0, 80), 'skor' => $e[3] . '-' . $e[4]];
        }
    }

    // LaLiga bicimi: "Resumen de Atlético de Madrid vs Sevilla FC (3-0)".
    if (preg_match('/^\s*resumen\s+(?:y\s+goles\s+)?(?:de|del)\s+(.{2,40}?)\s+(?:vs\.?|v|-)\s+(.{2,40}?)\s*\((\d{1,2})\s*[-–]\s*(\d{1,2})\)/iu', $baslik, $e)) {
        return ['ev' => mb_substr(trim($e[1]), 0, 80), 'deplasman' => mb_substr(trim($e[2]), 0, 80), 'skor' => $e[3] . '-' . $e[4]];
    }

    // Skorsuz bicim: "Arsenal v Chelsea | Premier League Highlights", "Real Madrid vs Barcelona".
    foreach (explode(' | ', $temiz) as $parca) {
        if (preg_match('/^\s*([\p{L}\p{M}.\'’& ]{2,40}?)\s+(?:v|vs\.?|-)\s+([\p{L}\p{M}.\'’& ]{2,40}?)\s*(?:[(\[].*)?$/iu', $parca, $e)
            && !preg_match('/özet|highlights|resumen|league|liga|lig/iu', $e[1] . ' ' . $e[2])) {
            return ['ev' => mb_substr(trim($e[1]), 0, 80), 'deplasman' => mb_substr(trim($e[2]), 0, 80), 'skor' => ''];
        }
    }

    return ['ev' => '', 'deplasman' => '', 'skor' => ''];
}

/**
 * Ajanin getirdigi ozetleri kaydeder; olan video atlaniyor.
 *
 * @param list<array<string,mixed>> $ozetler video_id, baslik, aciklama, kanal, yayin (unix)
 * @return int eklenen
 */
function mac_ozeti_ekle(array $ozetler): int
{
    $ekle = db()->prepare(
        'INSERT IGNORE INTO mac_ozetleri (video_id, slug, baslik, aciklama, kanal, ev, deplasman, skor, yayin)
         VALUES (:v, :s, :b, :a, :k, :ev, :dep, :skor, :y)'
    );
    $var = db()->prepare('SELECT 1 FROM mac_ozetleri WHERE slug = :s');
    $eklenen = 0;

    foreach ($ozetler as $o) {
        $video  = (string) ($o['video_id'] ?? '');
        $baslik = trim(html_entity_decode((string) ($o['baslik'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $yayin  = (int) ($o['yayin'] ?? 0);

        if (!preg_match('/^[\w-]{11}$/', $video) || $baslik === '' || !mac_ozeti_mi($baslik)) {
            continue;
        }

        // Gelecek ya da bir aydan eski tarih: simdi.
        if ($yayin <= 0 || $yayin > time() + 3600 || $yayin < time() - 30 * 86400) {
            $yayin = time();
        }

        $c    = mac_ozeti_coz($baslik);
        $temel = slug_uret(($c['ev'] !== '' ? $c['ev'] . ' ' . $c['deplasman'] . ' mac ozeti' : $baslik) . ' ' . date('Y-m-d', $yayin));
        $slug = $temel;

        for ($i = 2; $i < 20; $i++) {
            $var->execute(['s' => $slug]);

            if ($var->fetchColumn() === false) {
                break;
            }

            $slug = $temel . '-' . $i;
        }

        $ekle->execute([
            'v' => $video, 's' => mb_substr($slug, 0, 220), 'b' => mb_substr($baslik, 0, 300),
            'a' => mb_substr(trim(strip_tags((string) ($o['aciklama'] ?? ''))), 0, 1000),
            'k' => mb_substr(trim((string) ($o['kanal'] ?? '')), 0, 120),
            'ev' => $c['ev'], 'dep' => $c['deplasman'], 'skor' => $c['skor'],
            'y' => date('Y-m-d H:i:s', $yayin),
        ]);
        $eklenen += $ekle->rowCount();
    }

    return $eklenen;
}

/** @return list<array<string,mixed>> */
function mac_ozeti_liste(int $adet = MAC_OZETI_SAYFA, int $atla = 0, string $ara = ''): array
{
    $kosul = "durum = 'yayinda'";
    $param = [];

    if ($ara !== '') {
        $kosul .= ' AND (baslik LIKE :a1 OR ev LIKE :a2 OR deplasman LIKE :a3)';
        $desen = '%' . addcslashes($ara, '%_\\') . '%';
        $param = ['a1' => $desen, 'a2' => $desen, 'a3' => $desen];
    }

    // Son uc gunun Turk buyukleri ve milli takim ozetleri once (site sahibi: "onemli olan
    // Galatasaray, Fenerbahce"); sonra yeniden eskiye.
    $one = implode(' OR ', array_map(static fn (string $k): string => 'baslik LIKE ' . db()->quote('%' . $k . '%'), MAC_OZETI_ONE_CIKAN));
    $ifade = db()->prepare('SELECT * FROM mac_ozetleri WHERE ' . $kosul
        . ' ORDER BY (yayin > NOW() - INTERVAL 3 DAY AND (' . $one . ')) DESC, yayin DESC, id DESC LIMIT ' . max(1, min(100, $adet)) . ' OFFSET ' . max(0, $atla));
    $ifade->execute($param);

    return $ifade->fetchAll();
}

function mac_ozeti_sayisi(string $ara = ''): int
{
    $kosul = "durum = 'yayinda'";
    $param = [];

    if ($ara !== '') {
        $kosul .= ' AND (baslik LIKE :a1 OR ev LIKE :a2 OR deplasman LIKE :a3)';
        $desen = '%' . addcslashes($ara, '%_\\') . '%';
        $param = ['a1' => $desen, 'a2' => $desen, 'a3' => $desen];
    }

    $ifade = db()->prepare('SELECT COUNT(*) FROM mac_ozetleri WHERE ' . $kosul);
    $ifade->execute($param);

    return (int) $ifade->fetchColumn();
}

/** @return array<string,mixed>|null */
function mac_ozeti_bul(string $slug): ?array
{
    $ifade = db()->prepare("SELECT * FROM mac_ozetleri WHERE slug = :s AND durum = 'yayinda'");
    $ifade->execute(['s' => $slug]);

    return $ifade->fetch() ?: null;
}

/** Ozetin YouTube kapak gorseli (her videoda var). */
function mac_ozeti_gorsel(array $o, string $boy = 'hqdefault'): string
{
    return 'https://i.ytimg.com/vi/' . rawurlencode((string) $o['video_id']) . '/' . $boy . '.jpg';
}

/** Kart (liste ve seritte ortak). */
function mac_ozeti_kart_html(array $o): string
{
    $zaman = strtotime((string) $o['yayin']) ?: time();

    return '<article class="mozet-kart"><a href="' . e(mac_ozeti_yolu((string) $o['slug'])) . '">'
        . '<span class="mozet-kapak"><img src="' . e(mac_ozeti_gorsel($o)) . '" alt="" loading="lazy" width="480" height="360">'
        . '<span class="mozet-oynat" aria-hidden="true">▶</span>'
        . ((string) $o['skor'] !== '' ? '<span class="mozet-skor">' . e((string) $o['skor']) . '</span>' : '') . '</span>'
        . '<span class="mozet-govde"><span class="mozet-baslik">' . e((string) $o['baslik']) . '</span>'
        . '<span class="mozet-alt">' . e((string) $o['kanal']) . ((string) $o['kanal'] !== '' ? ' · ' : '') . e(tarih_bicimle(date('Y-m-d H:i:s', $zaman))) . '</span></span>'
        . '</a></article>';
}

/** ValSpor on sayfasi icin son ozetler seridi; ozet yoksa bos. */
function mac_ozeti_serit_html(int $adet = 4): string
{
    try {
        $liste = mac_ozeti_liste($adet);
    } catch (PDOException $e) {
        return '';
    }

    if ($liste === []) {
        return '';
    }

    return '<section class="mozet-serit" aria-label="Maç özetleri"><div class="bolum-basligi"><h2>Maç Özetleri</h2><span class="cizgi"></span>'
        . '<a class="mozet-tumu" href="' . e(mac_ozetleri_yolu()) . '">Tümü →</a></div>'
        . '<div class="mozet-izgara">' . implode('', array_map('mac_ozeti_kart_html', $liste)) . '</div></section>';
}

/** VideoObject yapisal verisi (Google video sonuclari). */
function mac_ozeti_semasi(array $o): string
{
    require_once __DIR__ . '/seo.php';

    $adres = site_adresi() . mac_ozeti_yolu((string) $o['slug']);
    $sema  = [
        '@context'     => 'https://schema.org',
        '@type'        => 'VideoObject',
        'name'         => (string) $o['baslik'],
        'description'  => (string) ($o['aciklama'] !== '' ? kisalt((string) $o['aciklama'], 300) : $o['baslik'] . ' — maç özeti videosu.'),
        'thumbnailUrl' => [mac_ozeti_gorsel($o, 'maxresdefault'), mac_ozeti_gorsel($o)],
        'uploadDate'   => date('c', strtotime((string) $o['yayin']) ?: time()),
        'embedUrl'     => 'https://www.youtube.com/embed/' . $o['video_id'],
        'contentUrl'   => 'https://www.youtube.com/watch?v=' . $o['video_id'],
        'url'          => $adres,
        'inLanguage'   => 'tr',
        'publisher'    => ['@type' => 'Organization', 'name' => 'Valentra',
                           'logo' => ['@type' => 'ImageObject', 'url' => site_adresi() . '/assets/logo-512.png']],
    ];

    if ((string) $o['ev'] !== '' && (string) $o['deplasman'] !== '') {
        $sema['about'] = ['@type' => 'SportsEvent', 'name' => $o['ev'] . ' - ' . $o['deplasman'],
                          'homeTeam' => ['@type' => 'SportsTeam', 'name' => (string) $o['ev']],
                          'awayTeam' => ['@type' => 'SportsTeam', 'name' => (string) $o['deplasman']]];
    }

    return (string) json_encode($sema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
}
