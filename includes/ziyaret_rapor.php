<?php
declare(strict_types=1);

/**
 * Ziyaretçi raporu sorguları (panel → Site → Ziyaretçiler).
 *
 * Site sahibinin istegi (23.10.2026): "hangi haberlere en cok
 * bakiliyorsa onlari one cikaracagim; cok iyi bir raporlama araci
 * yapalim". Butun sorgular bir tarih araligi ($a, bkz. zr_aralik) ve
 * "ham" secenegiyle calisiyor:
 *
 *   - Varsayilan: yalnizca tarayicida gorundugu dogrulanan
 *     goruntulemeler (ziyaret_insan_kosulu). Botlar, baglanti
 *     onizlemeleri ve hic acilmayan onyuklemeler sayilmiyor.
 *   - Ham: sunucunun yazdigi her satir (karsilastirma icin).
 *
 * "Ziyaretci" her yerde TEKIL kisi (tarayici kimligi, yoksa takma imza),
 * "goruntulenme" sayfa acilisi. Tek kisi on sayfa gezdiginde bunlar cok
 * farkli seyler anlatiyor; ikisi ayri gosteriliyor.
 *
 * Paylasimli hostingde tablo buyudukce sorgular yavaslamasin diye her
 * sorgu once zaman indeksine dusen bir aralikla suzuyor.
 */

require_once __DIR__ . '/ziyaret.php';

/* ------------------------------------------------------------------ *
 * Aralik
 * ------------------------------------------------------------------ */

/** @return array<string,string> */
function zr_hazir_araliklar(): array
{
    return [
        'bugun'   => 'Bugün',
        'dun'     => 'Dün',
        '7g'      => 'Son 7 gün',
        '30g'     => 'Son 30 gün',
        'buay'    => 'Bu ay',
        'gecenay' => 'Geçen ay',
        '90g'     => 'Son 90 gün',
    ];
}

/**
 * İstekten tarih aralığı ve karşılaştırma için bir önceki eş aralık.
 *
 * @param array<string,mixed> $girdi $_GET
 * @return array{anahtar:string,ad:string,bas:string,bit:string,gun:int,onceki_bas:string,onceki_bit:string}
 */
function zr_aralik(array $girdi): array
{
    $bugun   = new DateTimeImmutable('today');
    $anahtar = (string) ($girdi['aralik'] ?? '7g');

    $tarih = static function (string $deger): ?DateTimeImmutable {
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', $deger);

        return $t !== false ? $t : null;
    };

    if ($anahtar === 'ozel') {
        $bas = $tarih((string) ($girdi['bas'] ?? ''));
        $bit = $tarih((string) ($girdi['bit'] ?? ''));

        if ($bas === null || $bit === null) {
            $anahtar = '7g';
        } else {
            if ($bas > $bit) {
                [$bas, $bit] = [$bit, $bas];
            }

            // Kayitlar 180 gun saklaniyor; daha eskisi bos gelir.
            $enEski = $bugun->modify('-' . ZIYARET_SAKLAMA_GUN . ' days');
            $bas    = max($bas, $enEski);
            $bit    = min($bit, $bugun);
            $ad     = $bas->format('d.m.Y') . ' – ' . $bit->format('d.m.Y');
        }
    }

    if ($anahtar !== 'ozel') {
        if (!isset(zr_hazir_araliklar()[$anahtar])) {
            $anahtar = '7g';
        }

        [$bas, $bit] = match ($anahtar) {
            'bugun'   => [$bugun, $bugun],
            'dun'     => [$bugun->modify('-1 day'), $bugun->modify('-1 day')],
            '30g'     => [$bugun->modify('-29 days'), $bugun],
            '90g'     => [$bugun->modify('-89 days'), $bugun],
            'buay'    => [$bugun->modify('first day of this month'), $bugun],
            'gecenay' => [$bugun->modify('first day of last month'), $bugun->modify('last day of last month')],
            default   => [$bugun->modify('-6 days'), $bugun],
        };
        $ad = zr_hazir_araliklar()[$anahtar];
    }

    $gun = (int) $bas->diff($bit)->days + 1;

    return [
        'anahtar'    => $anahtar,
        'ad'         => $ad,
        'bas'        => $bas->format('Y-m-d'),
        'bit'        => $bit->format('Y-m-d'),
        'gun'        => $gun,
        'onceki_bas' => $bas->modify('-' . $gun . ' days')->format('Y-m-d'),
        'onceki_bit' => $bas->modify('-1 day')->format('Y-m-d'),
    ];
}

/**
 * Aralık + doğrulama koşulu (WHERE parçası).
 *
 * Aralik tarih indeksine dusecek bicimde yaziliyor (DATE(zaman) degil).
 */
function zr_kosul(string $bas, string $bit, bool $ham, string $t = 'z'): string
{
    $sonraki = (new DateTimeImmutable($bit))->modify('+1 day')->format('Y-m-d');

    return $t . '.zaman >= ' . db()->quote($bas . ' 00:00:00')
         . ' AND ' . $t . '.zaman < ' . db()->quote($sonraki . ' 00:00:00')
         . ' AND ' . ziyaret_insan_kosulu($t, $ham);
}

/** Ziyaretçi kimliği (yeni şema yoksa takma imza). */
function zr_kimlik(string $t = 'z'): string
{
    return ziyaret_yeni_sema() ? ziyaret_kimlik_ifadesi($t) : $t . '.ziyaretci';
}

/** @return list<array<string,mixed>> */
function zr_sorgu(string $sql): array
{
    try {
        return db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] ziyaret raporu: ' . $e->getMessage());

        return [];
    }
}

/* ------------------------------------------------------------------ *
 * Ozet
 * ------------------------------------------------------------------ */

/**
 * Bir aralığın özet ölçüleri.
 *
 * @return array<string,float|int|null>
 */
function zr_olculer(string $bas, string $bit, bool $ham): array
{
    $k  = zr_kosul($bas, $bit, $ham);
    $id = zr_kimlik();
    $yeniSema = ziyaret_yeni_sema();

    $temel = zr_sorgu(
        'SELECT COUNT(*) AS goruntulenme,
                COUNT(DISTINCT ' . $id . ') AS ziyaretci,
                SUM(z.haber_id IS NOT NULL) AS haber_goruntulenme'
        . ($yeniSema ? ',
                COUNT(DISTINCT CASE WHEN z.yeni = 1 THEN ' . $id . ' END) AS yeni_ziyaretci,
                AVG(CASE WHEN z.insan = 1 AND z.sure > 0 THEN z.sure END) AS ort_sure,
                AVG(CASE WHEN z.insan = 1 AND z.sure > 0 AND z.haber_id IS NOT NULL THEN z.sure END) AS haber_ort_sure,
                SUM(z.insan = 1 AND z.haber_id IS NOT NULL AND z.kaydirma > 0) AS olculen_haber,
                SUM(z.insan = 1 AND z.haber_id IS NOT NULL AND z.kaydirma >= 75) AS biten_haber' : '')
        . ' FROM ziyaretler z WHERE ' . $k
    )[0] ?? [];

    // Hemen cikma: bir gunde yalnizca tek sayfa acan ziyaretciler.
    $cikma = zr_sorgu(
        'SELECT COUNT(*) AS gun, SUM(adet = 1) AS tek
           FROM (SELECT ' . $id . ' AS kim, DATE(z.zaman) AS g, COUNT(*) AS adet
                   FROM ziyaretler z WHERE ' . $k . '
                  GROUP BY kim, g) t'
    )[0] ?? [];

    $ziyaretci    = (int) ($temel['ziyaretci'] ?? 0);
    $goruntulenme = (int) ($temel['goruntulenme'] ?? 0);
    $olculen      = (int) ($temel['olculen_haber'] ?? 0);

    return [
        'ziyaretci'          => $ziyaretci,
        'goruntulenme'       => $goruntulenme,
        'haber_goruntulenme' => (int) ($temel['haber_goruntulenme'] ?? 0),
        'sayfa_kisi'         => $ziyaretci > 0 ? $goruntulenme / $ziyaretci : 0.0,
        'yeni_ziyaretci'     => isset($temel['yeni_ziyaretci']) ? (int) $temel['yeni_ziyaretci'] : null,
        'ort_sure'           => isset($temel['ort_sure']) ? (float) $temel['ort_sure'] : null,
        'haber_ort_sure'     => isset($temel['haber_ort_sure']) ? (float) $temel['haber_ort_sure'] : null,
        'okuma_orani'        => $olculen > 0 ? (int) ($temel['biten_haber'] ?? 0) / $olculen * 100 : null,
        'hemen_cikma'        => (int) ($cikma['gun'] ?? 0) > 0 ? (int) $cikma['tek'] / (int) $cikma['gun'] * 100 : null,
    ];
}

/** Şu an sitede olanlar (son 5 dakika, tekil). */
function zr_simdi(): int
{
    $satir = zr_sorgu(
        'SELECT COUNT(DISTINCT ' . zr_kimlik() . ') AS n FROM ziyaretler z
          WHERE z.zaman >= (NOW() - INTERVAL ' . (int) ZIYARET_ONLINE_DAKIKA . ' MINUTE)
            AND ' . ziyaret_insan_kosulu('z')
    );

    return (int) ($satir[0]['n'] ?? 0);
}

/* ------------------------------------------------------------------ *
 * Zaman serisi
 * ------------------------------------------------------------------ */

/**
 * Günlük (ya da tek günde saatlik) seri; kaydı olmayan dilimler sıfır.
 *
 * @return list<array{etiket:string,ipucu:string,ziyaretci:int,goruntulenme:int}>
 */
function zr_seri(array $a, bool $ham): array
{
    $k  = zr_kosul($a['bas'], $a['bit'], $ham);
    $id = zr_kimlik();

    if ($a['gun'] === 1) {
        $sayilar = [];

        foreach (zr_sorgu('SELECT HOUR(z.zaman) AS d, COUNT(*) AS g, COUNT(DISTINCT ' . $id . ') AS z
                             FROM ziyaretler z WHERE ' . $k . ' GROUP BY d') as $s) {
            $sayilar[(int) $s['d']] = $s;
        }

        $seri = [];

        for ($h = 0; $h < 24; $h++) {
            $seri[] = [
                'etiket'       => sprintf('%02d', $h),
                'ipucu'        => sprintf('%02d:00–%02d:59', $h, $h),
                'ziyaretci'    => (int) ($sayilar[$h]['z'] ?? 0),
                'goruntulenme' => (int) ($sayilar[$h]['g'] ?? 0),
            ];
        }

        return $seri;
    }

    $sayilar = [];

    foreach (zr_sorgu('SELECT DATE(z.zaman) AS d, COUNT(*) AS g, COUNT(DISTINCT ' . $id . ') AS z
                         FROM ziyaretler z WHERE ' . $k . ' GROUP BY d') as $s) {
        $sayilar[(string) $s['d']] = $s;
    }

    $gunAdi = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
    $seri   = [];
    $gun    = new DateTimeImmutable($a['bas']);
    $son    = new DateTimeImmutable($a['bit']);

    while ($gun <= $son) {
        $d = $gun->format('Y-m-d');
        $seri[] = [
            'etiket'       => $gun->format('d.m'),
            'ipucu'        => $gun->format('d.m.Y') . ' ' . $gunAdi[(int) $gun->format('w')],
            'ziyaretci'    => (int) ($sayilar[$d]['z'] ?? 0),
            'goruntulenme' => (int) ($sayilar[$d]['g'] ?? 0),
        ];
        $gun = $gun->modify('+1 day');
    }

    return $seri;
}

/**
 * Haftanın günü × saat dağılımı (görüntülenme). [gün 0=Pzt][saat] => sayı
 *
 * @return array<int,array<int,int>>
 */
function zr_saat_gun(array $a, bool $ham): array
{
    $tablo = array_fill(0, 7, array_fill(0, 24, 0));

    // WEEKDAY(): 0 = Pazartesi
    foreach (zr_sorgu('SELECT WEEKDAY(z.zaman) AS g, HOUR(z.zaman) AS s, COUNT(*) AS n
                         FROM ziyaretler z WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
                        GROUP BY g, s') as $r) {
        $tablo[(int) $r['g']][(int) $r['s']] = (int) $r['n'];
    }

    return $tablo;
}

/* ------------------------------------------------------------------ *
 * Haberler ve kategoriler
 * ------------------------------------------------------------------ */

/** @return array<string,string> */
function zr_haber_siralari(): array
{
    return [
        'okuyucu'      => 'Okuyucu',
        'goruntulenme' => 'Görüntülenme',
        'sure'         => 'Okuma süresi',
        'oran'         => 'Sonuna kadar okunma',
        'arama'        => 'Aramadan gelen',
    ];
}

/**
 * Haber performansı.
 *
 * Her haber icin: tekil okuyucu, goruntulenme, ortalama okuma suresi,
 * sonuna kadar okunma orani (kaydirma >= %75), arama motorundan ve
 * sosyal medyadan gelen okuyucu. Basligi haberler tablosundan aliyor:
 * haber sonradan duzenlenirse listede guncel hali gorunsun.
 *
 * @return list<array<string,mixed>>
 */
function zr_haberler(array $a, bool $ham, string $sira = 'okuyucu', int $adet = 30): array
{
    $id   = zr_kimlik();
    $yeni = ziyaret_yeni_sema();
    $kanal = zr_kanal_ifadesi('z');

    $siraSql = match ($sira) {
        'goruntulenme' => 'goruntulenme DESC',
        'sure'         => 'ort_sure IS NULL, ort_sure DESC, okuyucu DESC',
        'oran'         => 'okuma_orani IS NULL, okuma_orani DESC, okuyucu DESC',
        'arama'        => 'arama DESC, okuyucu DESC',
        default        => 'okuyucu DESC, goruntulenme DESC',
    };

    return zr_sorgu(
        'SELECT z.haber_id,
                COALESCE(h.baslik, MAX(z.baslik)) AS baslik,
                h.slug, h.durum, h.one_cikan, h.yayin_tarihi,
                k.ad AS kategori,
                COUNT(DISTINCT ' . $id . ') AS okuyucu,
                COUNT(*) AS goruntulenme,'
        . ($yeni ? '
                AVG(CASE WHEN z.insan = 1 AND z.sure > 0 THEN z.sure END) AS ort_sure,
                SUM(z.insan = 1 AND z.kaydirma >= 75) / NULLIF(SUM(z.insan = 1 AND z.kaydirma > 0), 0) * 100 AS okuma_orani,'
                : ' NULL AS ort_sure, NULL AS okuma_orani,') . '
                COUNT(DISTINCT CASE WHEN ' . $kanal . " = 'arama' THEN " . $id . ' END) AS arama,
                COUNT(DISTINCT CASE WHEN ' . $kanal . " = 'sosyal' THEN " . $id . ' END) AS sosyal
           FROM ziyaretler z
      LEFT JOIN haberler h ON h.id = z.haber_id
      LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE z.haber_id IS NOT NULL AND ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
          GROUP BY z.haber_id, h.baslik, h.slug, h.durum, h.one_cikan, h.yayin_tarihi, k.ad
          ORDER BY ' . $siraSql . '
          LIMIT ' . max(1, min(500, $adet))
    );
}

/**
 * Son 60 dakikada en çok okunanlar ("şu an ne okunuyor").
 *
 * @return list<array<string,mixed>>
 */
function zr_simdi_okunanlar(int $adet = 5): array
{
    return zr_sorgu(
        'SELECT z.haber_id, COALESCE(h.baslik, MAX(z.baslik)) AS baslik, h.slug, h.one_cikan, h.durum,
                COUNT(DISTINCT ' . zr_kimlik() . ') AS okuyucu
           FROM ziyaretler z
      LEFT JOIN haberler h ON h.id = z.haber_id
          WHERE z.haber_id IS NOT NULL
            AND z.zaman >= (NOW() - INTERVAL 60 MINUTE)
            AND ' . ziyaret_insan_kosulu('z') . '
          GROUP BY z.haber_id, h.baslik, h.slug, h.one_cikan, h.durum
          ORDER BY okuyucu DESC
          LIMIT ' . max(1, min(20, $adet))
    );
}

/**
 * Kategori (başlık) performansı.
 *
 * @return list<array<string,mixed>>
 */
function zr_kategoriler(array $a, bool $ham): array
{
    return zr_sorgu(
        'SELECT COALESCE(k.ad, "(başlıksız)") AS kategori,
                COUNT(DISTINCT z.haber_id) AS haber,
                COUNT(DISTINCT ' . zr_kimlik() . ') AS okuyucu,
                COUNT(*) AS goruntulenme'
        . (ziyaret_yeni_sema() ? ', AVG(CASE WHEN z.insan = 1 AND z.sure > 0 THEN z.sure END) AS ort_sure' : ', NULL AS ort_sure') . '
           FROM ziyaretler z
      LEFT JOIN haberler h ON h.id = z.haber_id
      LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE z.haber_id IS NOT NULL AND ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
          GROUP BY kategori
          ORDER BY goruntulenme DESC'
    );
}

/* ------------------------------------------------------------------ *
 * Trafik kaynaklari
 * ------------------------------------------------------------------ */

/** Kanal adları (rapordaki görünüş). */
function zr_kanal_adlari(): array
{
    return [
        'arama'    => 'Arama motorları',
        'sosyal'   => 'Sosyal medya',
        'dogrudan' => 'Doğrudan',
        'yz'       => 'Yapay zekâ asistanları',
        'eposta'   => 'E-posta',
        'kampanya' => 'Kampanya bağlantıları',
        'diger'    => 'Diğer siteler',
        'ic'       => 'Site içi',
    ];
}

/**
 * Bir satırın kanalı (SQL CASE ifadesi).
 *
 * Alan adi listeleri tam eslesme ile yaziliyor (^(alt\.)*alan$): "x.com"
 * deseni "netflix.com"u, "t.co" "reddit.com"u yakalamasin. Siralama
 * onemli: mail.google.com e-posta, gemini.google.com yapay zeka; ikisi
 * de "google" deseninden once denetleniyor.
 */
function zr_kanal_ifadesi(string $t = 'z'): string
{
    $alan = static fn (array $adlar): string => db()->quote('^([a-z0-9-]+\\.)*(' . implode('|', $adlar) . ')$');

    $yz     = $alan(['chatgpt\\.com', 'chat\\.openai\\.com', 'openai\\.com', 'perplexity\\.ai', 'gemini\\.google\\.com',
                     'copilot\\.microsoft\\.com', 'claude\\.ai', 'chat\\.deepseek\\.com', 'deepseek\\.com', 'you\\.com']);
    $eposta = $alan(['mail\\.google\\.com', 'outlook\\.live\\.com', 'outlook\\.office\\.com', 'outlook\\.office365\\.com',
                     'mail\\.yahoo\\.com', 'mail\\.yandex\\.com\\.tr', 'mail\\.yandex\\.ru', 'mail\\.ru', 'webmail\\.[a-z.]+']);
    $arama  = $alan(['google\\.[a-z.]+', 'bing\\.com', 'yandex\\.[a-z.]+', 'duckduckgo\\.com', 'yahoo\\.com', 'search\\.yahoo\\.com',
                     'ecosia\\.org', 'search\\.brave\\.com', 'qwant\\.com', 'baidu\\.com', 'yaani\\.com', 'startpage\\.com',
                     'googleapis\\.com', 'msn\\.com', 'search\\.aol\\.com']);
    $sosyal = $alan(['facebook\\.com', 'fb\\.com', 'fb\\.me', 'm\\.facebook\\.com', 'l\\.facebook\\.com', 'instagram\\.com',
                     't\\.co', 'twitter\\.com', 'x\\.com', 'linkedin\\.com', 'lnkd\\.in', 'whatsapp\\.com', 'wa\\.me',
                     'telegram\\.org', 't\\.me', 'youtube\\.com', 'reddit\\.com', 'pinterest\\.com', 'tiktok\\.com',
                     'threads\\.net', 'bsky\\.app', 'eksisozluk\\.com', 'onedio\\.com', 'quora\\.com', 'vk\\.com']);

    $p = $t . '.';
    $kampanya = ziyaret_yeni_sema() ? 'WHEN ' . $p . "kampanya <> '' THEN 'kampanya' " : '';

    return '(CASE ' . $kampanya
        . 'WHEN ' . $p . "yonlendiren = '" . ZIYARET_IC_GECIS . "' THEN 'ic' "
        . 'WHEN ' . $p . "yonlendiren = '' THEN 'dogrudan' "
        . 'WHEN ' . $p . 'yonlendiren REGEXP ' . $yz . " THEN 'yz' "
        . 'WHEN ' . $p . 'yonlendiren REGEXP ' . $eposta . " THEN 'eposta' "
        . 'WHEN ' . $p . 'yonlendiren REGEXP ' . $arama . " THEN 'arama' "
        . 'WHEN ' . $p . 'yonlendiren REGEXP ' . $sosyal . " THEN 'sosyal' "
        . "ELSE 'diger' END)";
}

/**
 * Ziyaretçiler hangi kanaldan geldi.
 *
 * Yalnizca "giris" satirlari sayiliyor (site ici gecis olmayanlar):
 * Google'dan gelip uc haber okuyan ziyaretci Google'a bir giris yazar.
 *
 * @return list<array{kanal:string,ad:string,ziyaretci:int,giris:int}>
 */
function zr_kanallar(array $a, bool $ham): array
{
    $kanal = zr_kanal_ifadesi('z');
    $adlar = zr_kanal_adlari();

    $satirlar = zr_sorgu(
        'SELECT ' . $kanal . ' AS kanal, COUNT(DISTINCT ' . zr_kimlik() . ') AS ziyaretci, COUNT(*) AS giris
           FROM ziyaretler z
          WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
            AND z.yonlendiren <> ' . db()->quote(ZIYARET_IC_GECIS) . '
          GROUP BY kanal
          ORDER BY ziyaretci DESC'
    );

    return array_map(static fn (array $s): array => [
        'kanal'     => (string) $s['kanal'],
        'ad'        => $adlar[(string) $s['kanal']] ?? (string) $s['kanal'],
        'ziyaretci' => (int) $s['ziyaretci'],
        'giris'     => (int) $s['giris'],
    ], $satirlar);
}

/** @return list<array<string,mixed>> */
function zr_yonlendirenler(array $a, bool $ham, int $adet = 15): array
{
    return zr_sorgu(
        'SELECT z.yonlendiren AS kaynak, ' . zr_kanal_ifadesi('z') . ' AS kanal,
                COUNT(DISTINCT ' . zr_kimlik() . ') AS ziyaretci, COUNT(*) AS giris
           FROM ziyaretler z
          WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . "
            AND z.yonlendiren NOT IN ('', " . db()->quote(ZIYARET_IC_GECIS) . ')
          GROUP BY z.yonlendiren
          ORDER BY ziyaretci DESC
          LIMIT ' . max(1, min(100, $adet))
    );
}

/** Kampanya etiketli bağlantılar (?utm_source=...). @return list<array<string,mixed>> */
function zr_kampanyalar(array $a, bool $ham, int $adet = 15): array
{
    if (!ziyaret_yeni_sema()) {
        return [];
    }

    return zr_sorgu(
        'SELECT z.kampanya, COUNT(DISTINCT ' . zr_kimlik() . ') AS ziyaretci, COUNT(*) AS goruntulenme
           FROM ziyaretler z
          WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . " AND z.kampanya <> ''
          GROUP BY z.kampanya
          ORDER BY ziyaretci DESC
          LIMIT " . max(1, min(100, $adet))
    );
}

/**
 * Giriş sayfaları: ziyaretçinin sitede ilk açtığı sayfa.
 *
 * @return list<array<string,mixed>>
 */
function zr_giris_sayfalari(array $a, bool $ham, int $adet = 15): array
{
    return zr_sorgu(
        'SELECT z.yol, MAX(z.baslik) AS baslik, COUNT(DISTINCT ' . zr_kimlik() . ') AS ziyaretci, COUNT(*) AS giris
           FROM ziyaretler z
          WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
            AND z.yonlendiren <> ' . db()->quote(ZIYARET_IC_GECIS) . '
          GROUP BY z.yol
          ORDER BY giris DESC
          LIMIT ' . max(1, min(100, $adet))
    );
}

/** @return list<array<string,mixed>> */
function zr_sayfalar(array $a, bool $ham, int $adet = 20): array
{
    return zr_sorgu(
        'SELECT z.yol, MAX(z.baslik) AS baslik, COUNT(*) AS goruntulenme, COUNT(DISTINCT ' . zr_kimlik() . ') AS ziyaretci
           FROM ziyaretler z
          WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
          GROUP BY z.yol
          ORDER BY goruntulenme DESC
          LIMIT ' . max(1, min(200, $adet))
    );
}

/* ------------------------------------------------------------------ *
 * Cihaz, arama, ziyaretciler
 * ------------------------------------------------------------------ */

/**
 * Cihaz türü / işletim sistemi / tarayıcı dağılımı (tekil ziyaretçi).
 *
 * @return array{cihaz:list<array<string,mixed>>,isletim:list<array<string,mixed>>,tarayici:list<array<string,mixed>>}
 */
function zr_cihazlar(array $a, bool $ham): array
{
    $sonuc = ['cihaz' => [], 'isletim' => [], 'tarayici' => []];

    if (!ziyaret_yeni_sema()) {
        return $sonuc;
    }

    foreach (array_keys($sonuc) as $alan) {
        $sonuc[$alan] = zr_sorgu(
            'SELECT z.' . $alan . ' AS ad, COUNT(DISTINCT ' . zr_kimlik() . ') AS ziyaretci
               FROM ziyaretler z
              WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . " AND z.cihaz <> ''
              GROUP BY z." . $alan . '
              ORDER BY ziyaretci DESC
              LIMIT 10'
        );
    }

    return $sonuc;
}

/**
 * Site içi aramalar: okuyucunun aradığı ama belki bulamadığı konular.
 *
 * @return list<array{sorgu:string,arama:int,ziyaretci:int}>
 */
function zr_aramalar(array $a, bool $ham, int $adet = 20): array
{
    $satirlar = zr_sorgu(
        'SELECT z.yol, COUNT(*) AS n, COUNT(DISTINCT ' . zr_kimlik() . ') AS kisi
           FROM ziyaretler z
          WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . "
            AND (z.yol LIKE '/ara?%' OR z.yol LIKE '/ara.php?%')
          GROUP BY z.yol
          LIMIT 2000"
    );

    $toplam = [];

    foreach ($satirlar as $s) {
        parse_str((string) parse_url((string) $s['yol'], PHP_URL_QUERY), $q);
        $sorgu = mb_strtolower(trim((string) ($q['q'] ?? '')), 'UTF-8');

        if ($sorgu === '') {
            continue;
        }

        $toplam[$sorgu] ??= ['sorgu' => $sorgu, 'arama' => 0, 'ziyaretci' => 0];
        $toplam[$sorgu]['arama']     += (int) $s['n'];
        $toplam[$sorgu]['ziyaretci'] += (int) $s['kisi'];
    }

    usort($toplam, static fn (array $x, array $y): int => $y['arama'] <=> $x['arama']);

    return array_slice(array_values($toplam), 0, $adet);
}

/**
 * Aralıktaki ziyaretçiler ve gezinti özeti.
 *
 * @return list<array<string,mixed>>
 */
function zr_ziyaretciler(array $a, bool $ham, int $adet = 100): array
{
    $id = zr_kimlik();

    return zr_sorgu(
        'SELECT o.kimlik, o.ilk, o.son, o.sayfa, o.yonlendiren, o.ip, o.cihaz,
                s.yol AS son_yol, s.baslik AS son_baslik
           FROM (SELECT ' . $id . ' AS kimlik,
                        MIN(z.zaman) AS ilk,
                        MAX(z.zaman) AS son,
                        COUNT(*)     AS sayfa,
                        MAX(z.id)    AS son_id,
                        MAX(CASE WHEN z.yonlendiren NOT IN (\'\', ' . db()->quote(ZIYARET_IC_GECIS) . ') THEN z.yonlendiren END) AS yonlendiren,
                        MAX(CASE WHEN z.ip <> \'\' THEN z.ip END) AS ip,
                        ' . (ziyaret_yeni_sema() ? "MAX(z.cihaz)" : "''") . ' AS cihaz
                   FROM ziyaretler z
                  WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
                  GROUP BY kimlik) o
           JOIN ziyaretler s ON s.id = o.son_id
          ORDER BY o.son DESC
          LIMIT ' . max(1, min(500, $adet))
    );
}

/**
 * Bir ziyaretçinin gezdiği sayfalar, sırasıyla.
 *
 * Kimlik dogrulanmadan sorguya girmiyor: 16 haneli onaltilik disinda bir
 * sey gelirse bos donuyor. Deger yine de hazir ifadeyle gonderiliyor.
 *
 * @return list<array<string,mixed>>
 */
function zr_gezinti(string $kimlik, int $adet = 300): array
{
    if (preg_match('/^[0-9a-f]{16}$/', $kimlik) !== 1) {
        return [];
    }

    $kosul = ziyaret_yeni_sema() ? '(z.tarayici_id = :a OR z.ziyaretci = :b)' : 'z.ziyaretci = :a';

    try {
        $ifade = db()->prepare(
            'SELECT z.zaman, z.yol, z.baslik, z.haber_id, z.yonlendiren, z.ip'
            . (ziyaret_yeni_sema() ? ', z.insan, z.sure, z.kaydirma' : '') . '
               FROM ziyaretler z
              WHERE ' . $kosul . '
              ORDER BY z.id
              LIMIT ' . max(1, min(1000, $adet))
        );
        $ifade->execute(ziyaret_yeni_sema() ? ['a' => $kimlik, 'b' => $kimlik] : ['a' => $kimlik]);

        return $ifade->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------------------------ *
 * Sayim dogrulugu
 * ------------------------------------------------------------------ */

/**
 * Panelde "sayılar doğru mu" kutusu için tanı bilgileri.
 *
 * @return array<string,mixed>
 */
function zr_dogruluk(): array
{
    $tani = [
        'baslangic'   => ziyaret_olcum_baslangici(),
        'saat_farki'  => ayar_oku('db_saat_farki'),
        'db_saat'     => '',
        'php_saat'    => date('Y-m-d H:i:s'),
        'toplam'      => 0,
        'dogrulanan'  => 0,
        'yeni_sema'   => ziyaret_yeni_sema(),
        'en_eski'     => '',
    ];

    $satir = zr_sorgu('SELECT NOW() AS simdi, MIN(zaman) AS en_eski FROM ziyaretler')[0] ?? [];
    $tani['db_saat'] = (string) ($satir['simdi'] ?? '');
    $tani['en_eski'] = (string) ($satir['en_eski'] ?? '');

    if ($tani['yeni_sema'] && $tani['baslangic'] !== '') {
        $s = zr_sorgu(
            'SELECT COUNT(*) AS toplam, SUM(insan = 1) AS dogrulanan
               FROM ziyaretler
              WHERE zaman >= GREATEST(' . db()->quote($tani['baslangic']) . ', NOW() - INTERVAL 7 DAY)
                AND zaman < NOW() - INTERVAL 2 MINUTE'
        )[0] ?? [];
        $tani['toplam']     = (int) ($s['toplam'] ?? 0);
        $tani['dogrulanan'] = (int) ($s['dogrulanan'] ?? 0);
    }

    return $tani;
}

/* ------------------------------------------------------------------ *
 * Gosterge paneli (29.10.2026)
 *
 * Site sahibinin istegi: "ziyaretci sayfasini daha detayli bir
 * raporlama alanina cevir; grafikler koyalim: kullanici nereden geldi,
 * son bir haftalik trendler, Google'dan gelen okuyucu, ortalama tiklama,
 * haberlerin okunma sayisi ve adedi; diger bilgiler altta kalsin".
 * ------------------------------------------------------------------ */

/** Gösterge grafiklerindeki kaynak grupları, renk sırasıyla. */
function zr_kaynak_gruplari(): array
{
    return [
        'google'   => 'Google',
        'arama'    => 'Diğer arama motorları',
        'sosyal'   => 'Sosyal medya',
        'dogrudan' => 'Doğrudan',
        'diger'    => 'Diğer (site, e-posta, yapay zekâ)',
    ];
}

/** Google'dan gelen satır mı (SQL; kanal ifadesi "arama" iken). */
function zr_google_kosulu(string $t = 'z'): string
{
    return $t . '.yonlendiren REGEXP ' . db()->quote('^([a-z0-9-]+\\.)*google\\.[a-z.]+$');
}

/** Satırın gösterge kaynak grubu (SQL CASE). */
function zr_kaynak_grubu_ifadesi(string $t = 'z'): string
{
    $kanal = zr_kanal_ifadesi($t);

    return "(CASE WHEN " . $kanal . " = 'arama' AND " . zr_google_kosulu($t) . " THEN 'google'"
        . " WHEN " . $kanal . " IN ('arama', 'sosyal', 'dogrudan') THEN " . $kanal
        . " ELSE 'diger' END)";
}

/**
 * Trend grafiklerinin aralığı: seçili aralık, kısaysa (bugün, dün) son
 * 7 gün; site sahibi "son bir haftalik trendler" istedi.
 *
 * @return array{bas:string,bit:string,gun:int}
 */
function zr_trend_araligi(array $a): array
{
    if ($a['gun'] >= 7) {
        return ['bas' => $a['bas'], 'bit' => $a['bit'], 'gun' => $a['gun']];
    }

    return [
        'bas' => (new DateTimeImmutable($a['bit']))->modify('-6 days')->format('Y-m-d'),
        'bit' => $a['bit'],
        'gun' => 7,
    ];
}

/**
 * Gün gün gösterge serisi: ziyaretçi, görüntülenme, haber okunma, okunan
 * haber adedi, Google'dan gelen okuyucu ve kaynak gruplarına göre gelen
 * ziyaretçi. Kaydı olmayan gün sıfır.
 *
 * @param array{bas:string,bit:string} $t
 * @return list<array<string,mixed>>
 */
function zr_gosterge_serisi(array $t, bool $ham): array
{
    $k  = zr_kosul($t['bas'], $t['bit'], $ham);
    $id = zr_kimlik();

    $temel = [];

    foreach (zr_sorgu('SELECT DATE(z.zaman) AS d, COUNT(*) AS g, COUNT(DISTINCT ' . $id . ') AS z,
                              SUM(z.haber_id IS NOT NULL) AS ho, COUNT(DISTINCT z.haber_id) AS oh
                         FROM ziyaretler z WHERE ' . $k . ' GROUP BY d') as $r) {
        $temel[(string) $r['d']] = $r;
    }

    // Gelis: site ici gecisler disarida (her kaynak kisinin o gunku girisi).
    $kaynak = [];

    foreach (zr_sorgu('SELECT DATE(z.zaman) AS d, ' . zr_kaynak_grubu_ifadesi('z') . ' AS grup,
                              COUNT(DISTINCT ' . $id . ') AS n
                         FROM ziyaretler z
                        WHERE ' . $k . ' AND z.yonlendiren <> ' . db()->quote(ZIYARET_IC_GECIS) . '
                        GROUP BY d, grup') as $r) {
        $kaynak[(string) $r['d']][(string) $r['grup']] = (int) $r['n'];
    }

    $gunAdi = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
    $seri   = [];
    $gun    = new DateTimeImmutable($t['bas']);
    $son    = new DateTimeImmutable($t['bit']);

    while ($gun <= $son) {
        $d  = $gun->format('Y-m-d');
        $tm = $temel[$d] ?? [];
        $z  = (int) ($tm['z'] ?? 0);
        $g  = (int) ($tm['g'] ?? 0);
        $gruplar = [];

        foreach (array_keys(zr_kaynak_gruplari()) as $grup) {
            $gruplar[$grup] = (int) ($kaynak[$d][$grup] ?? 0);
        }

        $seri[] = [
            'gun'          => $d,
            'etiket'       => $gun->format('d.m'),
            'ipucu'        => $gun->format('d.m.Y') . ' ' . $gunAdi[(int) $gun->format('w')],
            'ziyaretci'    => $z,
            'goruntulenme' => $g,
            'sayfa_kisi'   => $z > 0 ? round($g / $z, 2) : 0.0,
            'haber_okunma' => (int) ($tm['ho'] ?? 0),
            'okunan_haber' => (int) ($tm['oh'] ?? 0),
            'google'       => $gruplar['google'],
            'kaynaklar'    => $gruplar,
        ];
        $gun = $gun->modify('+1 day');
    }

    return $seri;
}

/**
 * Bir aralıkta Google'dan gelen tekil okuyucu ve okunan farklı haber
 * sayısı (gösterge kutuları ve önceki dönemle karşılaştırma için).
 *
 * @return array{google:int,okunan_haber:int}
 */
function zr_gosterge_ozet(string $bas, string $bit, bool $ham): array
{
    $k = zr_kosul($bas, $bit, $ham);
    $s = zr_sorgu(
        'SELECT COUNT(DISTINCT CASE WHEN ' . zr_kaynak_grubu_ifadesi('z') . " = 'google' THEN " . zr_kimlik() . ' END) AS google,
                COUNT(DISTINCT z.haber_id) AS okunan_haber
           FROM ziyaretler z WHERE ' . $k
    )[0] ?? [];

    return ['google' => (int) ($s['google'] ?? 0), 'okunan_haber' => (int) ($s['okunan_haber'] ?? 0)];
}

/**
 * Kaynak gruplarına göre tekil ziyaretçi (aralığın toplamı).
 *
 * @return array<string,int>
 */
function zr_kaynak_paylari(array $a, bool $ham): array
{
    $sonuc = array_fill_keys(array_keys(zr_kaynak_gruplari()), 0);

    foreach (zr_sorgu('SELECT ' . zr_kaynak_grubu_ifadesi('z') . ' AS grup, COUNT(DISTINCT ' . zr_kimlik() . ') AS n
                         FROM ziyaretler z
                        WHERE ' . zr_kosul($a['bas'], $a['bit'], $ham) . '
                          AND z.yonlendiren <> ' . db()->quote(ZIYARET_IC_GECIS) . '
                        GROUP BY grup') as $r) {
        $sonuc[(string) $r['grup']] = (int) $r['n'];
    }

    return $sonuc;
}

/* ------------------------------------------------------------------ *
 * Bicimleme
 * ------------------------------------------------------------------ */

/** 125 -> "2 dk 5 sn" */
function zr_sure(?float $saniye): string
{
    if ($saniye === null || $saniye <= 0) {
        return '—';
    }

    $s = (int) round($saniye);

    return $s >= 60 ? intdiv($s, 60) . ' dk ' . ($s % 60) . ' sn' : $s . ' sn';
}

/** Önceki döneme göre değişim yüzdesi; önceki sıfırsa null. */
function zr_degisim(float|int|null $simdi, float|int|null $once): ?float
{
    if ($simdi === null || $once === null || (float) $once == 0.0) {
        return null;
    }

    return ((float) $simdi - (float) $once) / (float) $once * 100;
}

/**
 * CSV indirme (Excel Türkçe karakterleri doğru açsın diye BOM'lu,
 * ayırıcı noktalı virgül).
 *
 * @param list<string> $basliklar
 * @param list<list<scalar|null>> $satirlar
 */
function zr_csv_gonder(string $dosya, array $basliklar, array $satirlar): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_.-]/i', '_', $dosya) . '"');

    $cikti = fopen('php://output', 'w');
    fwrite($cikti, "\xEF\xBB\xBF");
    fputcsv($cikti, $basliklar, ';');

    foreach ($satirlar as $satir) {
        fputcsv($cikti, array_map(static fn ($d): string => (string) $d, $satir), ';');
    }

    fclose($cikti);
    exit;
}
