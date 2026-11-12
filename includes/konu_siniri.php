<?php
declare(strict_types=1);

/**
 * Ana sayfada bir olaydan tek haber (09.11.2026; önceden 2, çok önemliyse 3).
 *
 * Site sahibinin istegi (08.11.2026): "ayni konuyla ilgili ana sayfada 2
 * veya cok onemliyse 3 haber yayinlayabilirsin; daha fazlasi olmasin.
 * Haberin toplam onem derecesine de bakalim. Baska onemli haberler varsa
 * bunlari toplayip bir ozgun haber haline getirelim."
 *
 * Nasil:
 *   - Ana sayfa adaylari (haber_ana_akis_kosulu, ana sayfa sirasiyla ilk
 *     KONU_ADAY_SAYISI haber) BASLIKLARINA gore konulara ayriliyor: iki
 *     baslik en az iki anlamli kok paylasiyor ve paylasilan kok kisa
 *     basligin kok sayisinin en az %40'i ise ayni konu ("Turkiye ile
 *     Kanada Arasinda Stratejik Ortaklik..." / "...Kanada Arasinda Savunma
 *     Ortakligi Mutabakati..."). Ajan ayni olayin kopyasini zaten almiyor;
 *     bu, ayni konunun FARKLI acilarini sayiyor.
 *   - Her konudan KONU_TAVANI haber kaliyor; konunun en onemli haberi
 *     KONU_COK_ONEMLI ve ustu ya da konudaki toplam onem KONU_TOPLAM_ONEM
 *     ve ustuyse KONU_TAVANI_ONEMLI. Kalanlar ana sayfadan cikiyor (konu
 *     sayfalarinda duruyor). Once derleme (ozgun) haber, sonra ana sayfa
 *     sirasi.
 *   - Tavani asan ve henuz derlemesi olmayan konular derleme ajanina
 *     ONCELIKLI olay olarak gidiyor (derleme_oncelikli_konular, api/derleme.php):
 *     ajan bunlari butun kaynaklardan tek bir ozgun haber olarak yaziyor.
 */

require_once __DIR__ . '/haberler.php';

/*
 * BIR OLAY, ANA SAYFADA TEK HABER (site sahibi, 09.11.2026: "Izmir Habas'ta
 * patlama haberi ana sayfada 2 kez art arda verilmis; bunun olmamasi lazim,
 * kesin cozum istiyorum"). Onceden 2, cok onemliyse 3 haberdi; buyuk olay
 * (en cok tekrar edilen haber) boylece ana sayfada en cok yer kapliyordu.
 * Artik her olaydan bir haber: varsa derleme, yoksa ana sayfa sirasindaki
 * ilk haber. Digerleri konu sayfalarinda ve haberin "ilgili haberler"inde;
 * olay derleme ajanina oncelikli gidiyor (derleme_oncelikli_konular).
 */

/** Bir olaydan ana sayfada en fazla bu kadar haber. */
const KONU_TAVANI = 1;

/** Çok önemli olayda da (tekrar en çok onlarda oluyor). */
const KONU_TAVANI_ONEMLI = 1;

/** Konunun en önemli haberi bu puan ve üstündeyse "çok önemli". */
const KONU_COK_ONEMLI = 75;

/** Ya da konudaki haberlerin toplam önemi bu ve üstündeyse. */
const KONU_TOPLAM_ONEM = 200;

/** Ana sayfa sırasıyla bakılan aday sayısı (ana sayfa 56 haber gösteriyor). */
const KONU_ADAY_SAYISI = 150;

/** Başlıkta konu belirtmeyen sözcükler. */
const KONU_DOLGU = [
    've', 'ile', 'için', 'icin', 'bir', 'bu', 'şu', 'da', 'de', 'mi', 'mı', 'ne', 'ya', 'ki', 'gibi', 'daha', 'en', 'çok',
    'olarak', 'olan', 'oldu', 'oldu', 'oluyor', 'var', 'yok', 'kadar', 'sonra', 'önce', 'karşı', 'göre', 'üzere',
    'arasında', 'üzerine', 'hakkında', 'yeni', 'son', 'dakika', 'türkiye', 'türkiye\'de', 'türk', 'açıkladı',
    'açıklama', 'açıklaması', 'dedi', 'yaptı', 'etti', 'verdi', 'geldi', 'başladı', 'sürüyor', 'devam', 'ediyor',
    'yüzde', 'milyon', 'milyar', 'bin', 'lira', 'liralık', 'dolar', 'yıl', 'yılın', 'gün', 'günü', 'hafta', 'ilk',
    'iki', 'üç', 'büyük', 'önemli', 'ilişkin', 'yönelik', 'tarafından', 'nasıl', 'neden', 'nedir', 'kim', 'hangi',
];

/** Aynı kurumun iki adı: başlıklarda ikisi de geçiyor. */
const KONU_ESANLAMLI = [
    'merkez bankası' => 'tcmb', 'merkez bankasi' => 'tcmb',
    'milli eğitim bakanlığı' => 'meb', 'millî eğitim bakanlığı' => 'meb',
    'türkiye büyük millet meclisi' => 'tbmm', 'meclis' => 'tbmm',
    'avrupa birliği' => 'ab', 'birleşmiş milletler' => 'bm',
    'cumhurbaşkanı erdoğan' => 'erdoğan',
];

/**
 * Başlığın anlamlı kökleri (Türkçe küçük harf, dolgu sözcükler atılmış,
 * ilk 4 harf: "Faizini" / "faiz", "Kanada'nın" / "Kanada" aynı kök).
 *
 * @return list<string>
 */
function konu_kokleri(string $baslik): array
{
    $baslik = strtr($baslik, ['I' => 'ı', 'İ' => 'i']);
    $baslik = mb_strtolower($baslik, 'UTF-8');
    $baslik = strtr($baslik, KONU_ESANLAMLI);
    $parcalar = preg_split('/[^\p{L}\p{N}\']+/u', $baslik, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $kokler = [];

    foreach ($parcalar as $p) {
        $p = preg_replace("/['’].*$/u", '', $p) ?? $p;

        if (in_array($p, KONU_DOLGU, true)) {
            continue;
        }

        if (preg_match('/^\d+$/', $p)) {
            // Tek basina sayi (230, 2027) yalnizca uzunsa konu belirtir.
            if (strlen($p) >= 3) {
                $kokler[$p] = true;
            }

            continue;
        }

        if (mb_strlen($p, 'UTF-8') >= 3 || in_array($p, ['ab', 'bm'], true)) {
            $kokler[mb_substr($p, 0, 4, 'UTF-8')] = true;
        }
    }

    return array_keys($kokler);
}

/**
 * Başlıktaki özel adların kökleri (büyük harfle başlayan ya da tamamı
 * büyük kelimeler: "Galatasaray", "HABAŞ", "Aliağa'da"). Eşanlamlı
 * dönüşümü geçirmiş başlık kökleriyle kesişiyor ("Merkez Bankası" -> tcmb).
 *
 * @return list<string>
 */
function konu_ozel_kokler(string $baslik): array
{
    $tum = array_flip(konu_kokleri($baslik));
    $ozel = [];

    foreach (preg_split('/[^\p{L}\p{N}\'’]+/u', $baslik, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $kelime) {
        $ilk = mb_substr($kelime, 0, 1, 'UTF-8');

        if ($ilk === mb_strtolower($ilk, 'UTF-8')) {
            continue;
        }

        foreach (konu_kokleri($kelime) as $kok) {
            if (isset($tum[$kok])) {
                $ozel[$kok] = true;
            }
        }
    }

    return array_keys($ozel);
}

/**
 * Haberin olay imzası: başlık kökleri, başlık + özet kökleri, özel adlar.
 *
 * @param array<string,mixed> $h baslik, ozet
 * @return array{t:list<string>,g:list<string>,o:list<string>}
 */
function konu_olay_imzasi(array $h): array
{
    $baslik = (string) ($h['baslik'] ?? '');
    $t = konu_kokleri($baslik);

    return [
        't' => $t,
        'g' => array_values(array_unique(array_merge($t, konu_kokleri(mb_substr((string) ($h['ozet'] ?? ''), 0, 220, 'UTF-8'))))),
        'o' => konu_ozel_kokler($baslik),
    ];
}

/**
 * İki haber aynı olayı mı veriyor?
 *
 * Ayni: basliklar ayni konuyu anlatiyor (konu_ayni_mi) YA DA baslik +
 * ozetleri en az 4 kok ve kisa olanin %35'ini paylasiyor ("Aliaga'daki
 * fabrika patlamasinda olu sayisi 3'e yukseldi" / "HABAS'taki patlamada 3
 * isci hayatini kaybetti"). AMA iki tarafta da digerinde hic gecmeyen bir
 * ozel ad varsa farkli olay ("Galatasaray ... Kasimpasa" / "Fenerbahce ...
 * Kasimpasa"; "Ardahan'da kar" / "Artvin'de kar").
 *
 * @param array{t:list<string>,g:list<string>,o:list<string>} $a
 * @param array{t:list<string>,g:list<string>,o:list<string>} $b
 */
function konu_ayni_olay_mi(array $a, array $b): bool
{
    if ($a['t'] === [] || $b['t'] === []) {
        return false;
    }

    $ortak = count(array_intersect($a['g'], $b['g']));
    $genis = $ortak >= 4 && $ortak / max(1, min(count($a['g']), count($b['g']))) >= 0.35;

    if (!$genis && !konu_ayni_mi($a['t'], $b['t'])) {
        return false;
    }

    $yalnizA = array_diff($a['o'], $b['g']);
    $yalnizB = array_diff($b['o'], $a['g']);

    return $yalnizA === [] || $yalnizB === [];
}

/** İki başlık aynı konuyu mu anlatıyor? */
function konu_ayni_mi(array $a, array $b): bool
{
    if ($a === [] || $b === []) {
        return false;
    }

    $ortak = count(array_intersect($a, $b));

    return $ortak >= 2 && $ortak / min(count($a), count($b)) >= 0.4;
}

/**
 * Haberleri konulara ayırır: iki haber aynı konudaysa konuları birleşir
 * (A~B ve B~C ise üçü bir konu). Konular ve içleri verilen sırada.
 *
 * @param list<array<string,mixed>> $haberler baslik alanı olan satırlar
 * @return list<list<int>> konu başına haberlerin dizideki sırası
 */
function konu_kumele(array $haberler): array
{
    $kokler = array_map(static fn (array $h): array => konu_olay_imzasi($h), $haberler);
    $ata    = array_keys($kokler);
    $bul    = static function (int $i) use (&$ata): int {
        while ($ata[$i] !== $i) {
            $i = $ata[$i] = $ata[$ata[$i]];
        }

        return $i;
    };

    $n = count($kokler);

    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (konu_ayni_olay_mi($kokler[$i], $kokler[$j])) {
                $a = $bul($i);
                $b = $bul($j);

                // Kucuk sira kok kalsin: konu ilk haberinin yerinde.
                if ($a !== $b) {
                    $ata[max($a, $b)] = min($a, $b);
                }
            }
        }
    }

    $kumeler = [];

    for ($i = 0; $i < $n; $i++) {
        $kumeler[$bul($i)][] = $i;
    }

    return array_values($kumeler);
}

/** Olayın ana sayfa tavanı (09.11.2026'dan beri her olayda 1). */
function konu_tavani(array $uyeler): int
{
    $onemler = array_map(static fn (array $h): int => (int) ($h['onem'] ?? 0), $uyeler);

    return (max($onemler) >= KONU_COK_ONEMLI || array_sum($onemler) >= KONU_TOPLAM_ONEM)
        ? KONU_TAVANI_ONEMLI
        : KONU_TAVANI;
}

/**
 * Ana sayfa adaylarının konuları (istek başına bir kez).
 *
 * @return list<array{haberler:list<array<string,mixed>>,kalan:list<int>,fazla:list<int>}>
 */
function konu_ana_sayfa_konulari(): array
{
    static $sonuc = null;

    if ($sonuc !== null) {
        return $sonuc;
    }

    $sonuc = [];

    try {
        $ifade = db()->prepare(
            'SELECT h.id, h.baslik, h.ozet, h.onem, h.derleme, h.kaynak_adi, k.slug AS kategori_slug
               FROM haberler h
               LEFT JOIN kategoriler k ON k.id = h.kategori_id
               ' . haber_okunma_birlesimi() . '
              WHERE h.durum = :durum AND ' . haber_ana_akis_kosulu(false, false) . '
              ORDER BY ' . haber_sirasi_temel(true) . '
              LIMIT ' . KONU_ADAY_SAYISI
        );
        $ifade->execute(['durum' => HABER_YAYINDA]);
        $adaylar = $ifade->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] konu siniri: ' . $e->getMessage());

        return $sonuc;
    }

    foreach (konu_kumele($adaylar) as $sira) {
        $uyeler = array_map(static fn (int $i): array => $adaylar[$i], $sira);

        // Once derleme (ozgun) haber, sonra ana sayfa sirasi (usort kararli).
        usort($uyeler, static fn (array $a, array $b): int => (int) $b['derleme'] <=> (int) $a['derleme']);

        $tavan = konu_tavani($uyeler);
        $idler = array_map(static fn (array $h): int => (int) $h['id'], $uyeler);

        $sonuc[] = [
            'haberler' => $uyeler,
            'kalan'    => array_slice($idler, 0, $tavan),
            'fazla'    => array_slice($idler, $tavan),
        ];
    }

    return $sonuc;
}

/**
 * Ana sayfada gösterilmeyecek haberler (konusunun tavanını aşanlar).
 *
 * @return list<int>
 */
function konu_fazlasi_idler(): array
{
    static $idler = null;
    static $hesaplaniyor = false;

    if ($idler !== null) {
        return $idler;
    }

    // Aday sorgusu da haber_ana_akis_kosulu'nu kullaniyor: dongu olmasin.
    if ($hesaplaniyor) {
        return [];
    }

    $hesaplaniyor = true;
    $idler = [];

    foreach (konu_ana_sayfa_konulari() as $konu) {
        array_push($idler, ...$konu['fazla']);
    }

    $hesaplaniyor = false;

    return $idler;
}

/**
 * Derleme ajanına öncelikli konular: tavanı aşan ve henüz derlemesi
 * olmayan konular, bütün haberleriyle.
 *
 * @return list<array{baslik:string,kategori:string,haber_idleri:list<int>}>
 */
function derleme_oncelikli_konular(): array
{
    $liste = [];

    foreach (konu_ana_sayfa_konulari() as $konu) {
        if ($konu['fazla'] === []) {
            continue;
        }

        $derlemeVar = array_filter($konu['haberler'], static fn (array $h): bool => (int) $h['derleme'] === 1) !== [];

        if ($derlemeVar) {
            continue;
        }

        $liste[] = [
            'baslik'       => (string) $konu['haberler'][0]['baslik'],
            'kategori'     => (string) ($konu['haberler'][0]['kategori_slug'] ?? ''),
            'haber_idleri' => array_map(static fn (array $h): int => (int) $h['id'], $konu['haberler']),
        ];
    }

    return $liste;
}

/* -------------------------------------------------------------------------
 * Tek konu, tek haber (site sahibinin istegi, 08.11.2026): "bir konu ile
 * ilgili birden cok haber varsa birlestir ve tek haber haline getir;
 * digerlerini yayinlama. Ya da bir haber var ve yeterliyse onu tekrar
 * yayinlama."
 *
 *   1. Yayimlanmis bir derlemenin kaynak haberleri ana sayfadan cikiyor
 *      (derleme hepsini anlatiyor). Haber sayfalari ve konu sayfalari
 *      yerinde.
 *   2. Yeni gelen haber, son TEKRAR_SAAT saatte yayimlanmis bir haberin
 *      neredeyse aynisiysa ya da konusu dolmussa (KONU_TAVANI kadar
 *      haber ya da derlemesi var) OTOMATIK YAYINLANMIYOR: taslakta
 *      kaliyor (tekrar_id), derleme ajani onu malzeme olarak goruyor
 *      (derleme_malzeme taslaklari da aliyor). Panelden elle
 *      yayimlanabilir. Konunun yayindakilerinden daha onemli ve "cok
 *      onemli" (KONU_COK_ONEMLI) bir gelisme bekletilmiyor.
 * ---------------------------------------------------------------------- */

/** Tekrar denetiminde geriye bakılan süre (saat). */
const TEKRAR_SAAT = 24;

/**
 * Başlıktaki sayılar ("%36,5" -> "36,5"; "1-0" -> "1", "0").
 *
 * @return list<string>
 */
function konu_sayilari(string $baslik): array
{
    preg_match_all('/\d+(?:[.,]\d+)?/u', $baslik, $m);

    return array_values(array_unique($m[0]));
}

/**
 * Başlıktaki sayılar, sıra sayıları hariç ("ligde 18. sırada", "11.
 * haftada" yeni bilgi değil; "4 asker", "%36,5", "2-1" öyle).
 *
 * @return list<string>
 */
function konu_sayilari_sirasiz(string $baslik): array
{
    return konu_sayilari(preg_replace('/\d+\.(?=\s+\p{Ll})/u', '', $baslik) ?? $baslik);
}

/**
 * Başlıktaki maç skoru, sırasız ("Belçika 2-1 Türkiye" ile "Türkiye 1-2
 * Belçika" aynı: "1-2"); skor yoksa null. Yıl aralığı ("2026-2027") ve
 * ondalıklı sayı skor değil.
 */
function konu_skoru(string $baslik): ?string
{
    if (preg_match('/(?<![\d.,])(\d{1,3})[-–](\d{1,3})(?![\d.,]|[-–]\d)/u', $baslik, $m) !== 1) {
        return null;
    }

    $s = [(int) $m[1], (int) $m[2]];
    sort($s);

    return $s[0] . '-' . $s[1];
}

/**
 * İki başlık aynı maçın sonucunu mu veriyor? (12.11.2026)
 *
 * Site sahibi: "futbol maci pes pese iki kez verilmis; haber ayniysa
 * birlestirelim demistik ama olmadi". "Türkiye, Belçika'ya 2-1 yenildi;
 * Uluslar A Ligi'nde henüz puanı yok" ile "Belçika 2-1 Türkiye: Arda
 * Güler'in frikiği yetmedi, milliler hâlâ puansız" yalnizca iki kok
 * paylasiyor ("Türkiye" dolgu sozcuk; bkz. KONU_DOLGU), kelime olcusu
 * (konu_ayni_mi_siki: en az 3) ayni haber oldugunu goremiyordu.
 *
 * Olcu: ayni skor VE en az bir ortak ozel ad (takim). Bir takim ayni gun
 * iki mac oynamadigi icin TEKRAR_SAAT penceresinde bu ayni mactir. Devre
 * skoru ("1-0") ile mac sonucu ("2-1") farkli skor: ayni sayilmaz.
 */
function konu_ayni_mac_mi(string $a, string $b): bool
{
    $skor = konu_skoru($a);

    return $skor !== null && $skor === konu_skoru($b)
        && array_intersect(konu_ozel_kokler($a), konu_ozel_kokler($b)) !== [];
}

/** Bekletme için konu ölçüsü: en az 3 ortak kök ve kısa başlığın yarısı. */
function konu_ayni_mi_siki(array $a, array $b): bool
{
    if ($a === [] || $b === []) {
        return false;
    }

    $ortak = count(array_intersect($a, $b));

    return $ortak >= 3 && $ortak / min(count($a), count($b)) >= 0.5;
}

/** İki başlık neredeyse aynı haberi mi veriyor? (konu_ayni_mi'den sıkı) */
function konu_cok_benzer_mi(array $a, array $b): bool
{
    if ($a === [] || $b === []) {
        return false;
    }

    $ortak = count(array_intersect($a, $b));

    return $ortak >= 3 && $ortak / min(count($a), count($b)) >= 0.6;
}

/**
 * Yayımlanmış derlemelerin kaynak haberleri (son 3 gün).
 *
 * @return list<int>
 */
function derleme_yayindaki_kaynak_idler(): array
{
    static $idler = null;

    if ($idler !== null) {
        return $idler;
    }

    $idler = [];

    if (!haber_sutun_var('derleme_kaynaklari')) {
        return $idler;
    }

    try {
        $ifade = db()->prepare(
            "SELECT derleme_kaynaklari FROM haberler
              WHERE derleme = 1 AND durum = :y AND olusturuldu >= NOW() - INTERVAL 3 DAY
                AND derleme_kaynaklari IS NOT NULL AND derleme_kaynaklari <> ''"
        );
        $ifade->execute(['y' => HABER_YAYINDA]);

        foreach ($ifade->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach ((array) json_decode((string) $json, true) as $k) {
                if (is_array($k) && (int) ($k['id'] ?? 0) > 0) {
                    $idler[(int) $k['id']] = (int) $k['id'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[valentra] derleme kaynaklari: ' . $e->getMessage());
    }

    $idler = array_values($idler);

    return $idler;
}

/**
 * Son TEKRAR_SAAT saatin yayımlanmış haberleri ve başlık kökleri (istek
 * başına bir kez). $ekle: bu istekte yayına alınan haber listeye eklenir
 * (aynı teslimdeki iki kopya ikisi birden yayımlanmasın).
 *
 * @param array<string,mixed>|null $ekle
 * @return list<array{id:int,baslik:string,onem:int,derleme:int,kokler:list<string>}>
 */
function konu_son_yayindakiler(?array $ekle = null): array
{
    static $liste = null;

    if ($liste !== null) {
        if ($ekle !== null) {
            array_unshift($liste, [
                'id'      => (int) $ekle['id'],
                'baslik'  => (string) $ekle['baslik'],
                'onem'    => (int) $ekle['onem'],
                'derleme' => (int) $ekle['derleme'],
                'kokler'  => konu_kokleri((string) $ekle['baslik']),
            ]);
        }

        return $liste;
    }

    // Liste henuz yuklenmediyse yeni haber zaten sorguda gelecek.
    $liste = [];

    try {
        $ifade = db()->prepare(
            'SELECT id, baslik, onem, derleme FROM haberler
              WHERE durum = :y AND yayin_tarihi >= NOW() - INTERVAL ' . TEKRAR_SAAT . ' HOUR
              ORDER BY yayin_tarihi DESC
              LIMIT 600'
        );
        $ifade->execute(['y' => HABER_YAYINDA]);

        foreach ($ifade->fetchAll() as $h) {
            $liste[] = [
                'id'      => (int) $h['id'],
                'baslik'  => (string) $h['baslik'],
                'onem'    => (int) $h['onem'],
                'derleme' => (int) $h['derleme'],
                'kokler'  => konu_kokleri((string) $h['baslik']),
            ];
        }
    } catch (Throwable $e) {
        error_log('[valentra] tekrar denetimi: ' . $e->getMessage());
    }

    return $liste;
}

/**
 * Haber yayımlanmadan bekletilmeli mi? Bekletilecekse yayındaki eşini ve
 * nedenini döndürür.
 *
 * @param array{id?:int,baslik:string,onem?:int} $haber
 * @return array{id:int,baslik:string,neden:string}|null
 */
function konu_tekrar_bul(array $haber): ?array
{
    $kokler = konu_kokleri((string) $haber['baslik']);
    $onem   = (int) ($haber['onem'] ?? 0);
    $kendi  = (int) ($haber['id'] ?? 0);

    if ($kokler === []) {
        return null;
    }

    $benzer  = null;
    $uyeler  = [];
    $sayilar = konu_sayilari((string) $haber['baslik']);

    foreach (konu_son_yayindakiler() as $y) {
        // Ayni macin sonucu (ayni skor, ortak takim): kelimeler farkli olsa
        // da ayni haber. Onem istisnasi yok: skor ayni, yeni gelisme degil.
        if ($y['id'] !== $kendi && konu_ayni_mac_mi((string) $haber['baslik'], $y['baslik'])) {
            return ['id' => $y['id'], 'baslik' => $y['baslik'], 'neden' => 'aynı maçın sonucu son ' . TEKRAR_SAAT . ' saatte yayımlandı'];
        }

        // Yayindakinde olmayan bir sayi (faiz %36 -> %40, skor 1-0 -> 2-0)
        // yeni bilgi: tekrar sayilmiyor.
        if ($sayilar !== [] && array_diff($sayilar, konu_sayilari($y['baslik'])) !== []) {
            continue;
        }

        // Bekletmek yayimlamamak demek: ana sayfa sinirindan (konu_ayni_mi)
        // siki olcu. "Galatasaray Kasimpasa deplasmaninda..." ile
        // "Fenerbahce deplasmanda Kasimpasa..." ayni konu sayilmasin.
        if ($y['id'] === $kendi || !konu_ayni_mi_siki($kokler, $y['kokler'])) {
            continue;
        }

        $uyeler[] = $y;

        if ($benzer === null && konu_cok_benzer_mi($kokler, $y['kokler'])) {
            $benzer = $y;
        }
    }

    if ($uyeler === []) {
        return null;
    }

    $derleme = null;

    foreach ($uyeler as $y) {
        if ($y['derleme'] === 1) {
            $derleme = $y;
            break;
        }
    }

    $neden = match (true) {
        $benzer !== null                => ['es' => $benzer, 'neden' => 'aynı haber son ' . TEKRAR_SAAT . ' saatte yayımlandı'],
        $derleme !== null               => ['es' => $derleme, 'neden' => 'konunun derlemesi yayında'],
        count($uyeler) >= KONU_TAVANI   => ['es' => $uyeler[0], 'neden' => 'konudan son ' . TEKRAR_SAAT . ' saatte ' . count($uyeler) . ' haber yayımlandı'],
        default                         => null,
    };

    if ($neden === null) {
        return null;
    }

    // Konunun yayindakilerinden onemli, cok onemli yeni gelisme bekletilmiyor.
    $enYuksek = max(array_map(static fn (array $y): int => $y['onem'], $uyeler));

    if ($onem >= KONU_COK_ONEMLI && $onem > $enYuksek) {
        return null;
    }

    return ['id' => $neden['es']['id'], 'baslik' => $neden['es']['baslik'], 'neden' => $neden['neden']];
}
