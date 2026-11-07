<?php
declare(strict_types=1);

/**
 * Ayni olayin haberini bir kez yayinlamak (07.11.2026, site sahibi: "ayni
 * haberi birden cok kez yayinlama; ana sayfadaki 20 haberin hicbirisi ayni
 * olmamali; benzer konusu ayni haberi birlestir, tek haber yap").
 *
 * Oncesinde yalnizca birebir kopya yakalaniyordu (haber_kopya_bul: ayni
 * adres, ayni baslik). Ayni olayi farkli kelimelerle veren iki kaynagin
 * haberi, ya da bir derleme ile dayandigi tek kaynakli haberler, ana
 * sayfada yan yana cikiyordu. Uc katman:
 *
 *   1. EKLERKEN (haber_taslak_ekle, ajan yolu): son 36 saatte ayni olayin
 *      haberi varsa yenisi eklenmiyor ("yinelenen").
 *   2. BIRLESTIRME (api/kopya.php, her ajan calismasinda): derlemenin
 *      dayandigi haberler derlemeye, basligi cok benzeyen haberler
 *      birbirine; kelimeleri farkli olanlari ajan modele okutup
 *      (ajan/kopya.php) gonderiyor. Birlesen haber yayindan kalkiyor
 *      (durum = reddedildi, birlesti_id = ana haber); yorumlari, tepkileri
 *      ve okunmasi ana habere geciyor; adresi ana habere 301 yonleniyor.
 *   3. ANA SAYFA (haber_ana_sayfa_tekil): birlestirme gelmeden once de
 *      kaydirak ve izgarada ayni olay ikinci kez gorunmuyor.
 */

require_once __DIR__ . '/haberler.php';

/** Eklerken ve birlestirirken geriye bakilan süre (saat). */
const KOPYA_PENCERE_SAAT = 36;

/**
 * Baslikta olayi ayirt etmeyen kelimeler (ilk bes harfe indirilmis
 * bicimleriyle karsilastiriliyor).
 */
const KOPYA_DOLGU = [
    've', 'ile', 'bir', 'bu', 'su', 'icin', 'gibi', 'kadar', 'daha', 'en', 'cok', 'son', 'dakika',
    'flas', 'haber', 'yeni', 'ilk', 'nasil', 'neden', 'nedir', 'hangi', 'iste', 'ne', 'mi', 'mu',
    'da', 'de', 'ki', 'ya', 'ama', 'sonra', 'once', 'olan', 'oldu', 'olarak', 'olacak', 'etti',
    'edildi', 'yapti', 'yapildi', 'aciklama', 'acikladi', 'dedi', 'soyledi', 'gore', 'tum',
    'buyuk', 'bugun', 'dun', 'yarin', 'yil', 'gun', 'saat', 'turkiye', 'karar', 'geldi', 'var',
    'yok', 'icin', 'uzerine', 'iki', 'uc', 'dort', 'bes', 'ilgili', 'hakkinda', 'devam', 'ediyor',
    'basladi', 'ortaya', 'cikti', 'belli', 'aldi', 'verdi',
];

/**
 * Basligin olay kelimeleri: Turkce harfler sadelestirilmis, ilk bes harfe
 * indirilmis kokler ("faizi", "faizin", "faiz" ayni). Rakamlar ayrica:
 * iki baslik ayri rakamlar veriyorsa (deprem buyuklugu, skor, oran)
 * ayni olay degil.
 *
 * @return array{k:array<string,true>,r:array<string,true>}
 */
function kopya_kokler(string $baslik): array
{
    static $dolgu = null;

    if ($dolgu === null) {
        $dolgu = array_fill_keys(array_map(static fn (string $s): string => substr($s, 0, 5), KOPYA_DOLGU), true);
    }

    $sade = strtr(haber_baslik_sadelestir($baslik), [
        'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
    ]);

    $kokler   = [];
    $rakamlar = [];

    foreach (preg_split('/\s+/u', $sade, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $kelime) {
        if (preg_match('/^\d+$/', $kelime)) {
            // Yil (2026) olayi ayirmiyor; diger rakamlar ayiriyor.
            if (!preg_match('/^(19|20)\d\d$/', $kelime)) {
                $rakamlar[$kelime] = true;
            }

            continue;
        }

        if (mb_strlen($kelime, 'UTF-8') < 3) {
            continue;
        }

        $kok = mb_substr($kelime, 0, 5, 'UTF-8');

        if (!isset($dolgu[$kok])) {
            $kokler[$kok] = true;
        }
    }

    return ['k' => $kokler, 'r' => $rakamlar];
}

/**
 * Iki baslik ayni olayi mi veriyor?
 *
 * $siki: eklerken ve kendiliginden birlestirirken (yanlis birlestirme
 * haberi yayindan kaldirir); gevsek: yalnizca ana sayfada ikincisini
 * gostermemek icin.
 *
 * @param array{k:array<string,true>,r:array<string,true>} $a
 * @param array{k:array<string,true>,r:array<string,true>} $b
 */
function kopya_ayni_mi(array $a, array $b, bool $ayniKategori, bool $siki = true): bool
{
    // Iki baslik da rakam veriyor ve hicbiri ortak degil: farkli olay.
    if ($a['r'] !== [] && $b['r'] !== [] && array_intersect_key($a['r'], $b['r']) === []) {
        return false;
    }

    $kisa = min(count($a['k']), count($b['k']));
    $uzun = max(count($a['k']), count($b['k']));

    if ($kisa < 3) {
        return false;
    }

    /*
     * Iki yonlu oran: kisa basligin kelimelerinin cogu uzunda olmali VE
     * uzun basligin kelimelerinin de onemli bir kismi ortak olmali.
     * "Derbinin hakemi belli oldu" ile "derbinin saati degisti" uc
     * kelimeyi paylasiyor ama ayni haber degil.
     */
    $ortak = count(array_intersect_key($a['k'], $b['k']));
    $kisaOran = $ortak / $kisa;
    $uzunOran = $ortak / $uzun;

    if ($siki) {
        return $ortak >= 4 && $kisaOran >= ($ayniKategori ? 0.7 : 0.8) && $uzunOran >= 0.6;
    }

    return $ayniKategori
        ? $ortak >= 3 && $kisaOran >= 0.75 && $uzunOran >= 0.65
        : $ortak >= 4 && $kisaOran >= 0.8 && $uzunOran >= 0.6;
}

/** birlesti_id sutunu var mi (sema yukseltmesi gelmeden once kod calisiyor olabilir). */
function kopya_hazir(): bool
{
    static $var = false;

    return $var = $var || haber_sutun_var('birlesti_id');
}

/**
 * Eklenecek haberle ayni olayi veren yakin tarihli haber (yayinda ya da
 * taslak). Yalnizca ajanin getirdigi haberlerde: derleme bilerek ayni
 * olayi yaziyor; deprem ve veri haberleri kendi kopya denetimlerini
 * yapiyor.
 */
function kopya_ayni_olay_bul(string $baslik, string $kaynakBaslik, ?int $kategoriId): ?int
{
    $yeni = [kopya_kokler($baslik)];

    if ($kaynakBaslik !== '' && $kaynakBaslik !== $baslik) {
        $yeni[] = kopya_kokler($kaynakBaslik);
    }

    $ifade = db()->prepare(
        'SELECT id, baslik, kaynak_baslik, kategori_id FROM haberler
          WHERE durum IN (:y, :t)' . (kopya_hazir() ? ' AND birlesti_id IS NULL' : '') . '
            AND olusturuldu >= NOW() - INTERVAL ' . KOPYA_PENCERE_SAAT . ' HOUR
          ORDER BY id DESC
          LIMIT 800'
    );
    $ifade->execute(['y' => HABER_YAYINDA, 't' => HABER_TASLAK]);

    foreach ($ifade->fetchAll() as $s) {
        $ayniKategori = $kategoriId !== null && (int) $s['kategori_id'] === $kategoriId;
        $eski = [kopya_kokler((string) $s['baslik'])];

        if ((string) $s['kaynak_baslik'] !== '') {
            $eski[] = kopya_kokler((string) $s['kaynak_baslik']);
        }

        foreach ($yeni as $y) {
            foreach ($eski as $e) {
                if (kopya_ayni_mi($y, $e, $ayniKategori, true)) {
                    return (int) $s['id'];
                }
            }
        }
    }

    return null;
}

/**
 * Derleme haberin dayandigi haber numaralari.
 *
 * @param array<string,mixed> $haber
 * @return array<int,true>
 */
function kopya_derleme_idleri(array $haber): array
{
    if ((int) ($haber['derleme'] ?? 0) !== 1 || trim((string) ($haber['derleme_kaynaklari'] ?? '')) === '') {
        return [];
    }

    require_once __DIR__ . '/derleme.php';

    $idler = [];

    foreach (derleme_kaynaklari_coz((string) $haber['derleme_kaynaklari']) as $k) {
        if ($k['id'] > 0) {
            $idler[$k['id']] = true;
        }
    }

    return $idler;
}

/**
 * Siralanmis haber listesinden ayni olayin ikinci, ucuncu... haberini
 * cikarir; sira korunuyor (ilk gelen kalir). Derlemenin dayandigi haber,
 * derleme listede varsa her durumda cikiyor.
 *
 * @param list<array<string,mixed>> $haberler
 * @return list<array<string,mixed>>
 */
function kopya_listeyi_tekille(array $haberler, int $enFazla = PHP_INT_MAX): array
{
    $derlemede = [];

    foreach ($haberler as $h) {
        $derlemede += kopya_derleme_idleri($h);
    }

    $kalan = [];
    $kokler = [];

    foreach ($haberler as $h) {
        if (count($kalan) >= $enFazla) {
            break;
        }

        if (isset($derlemede[(int) $h['id']])) {
            continue;
        }

        $k = kopya_kokler((string) $h['baslik']);

        foreach ($kokler as $i => $onceki) {
            $ayniKategori = (int) ($h['kategori_id'] ?? 0) === (int) ($kalan[$i]['kategori_id'] ?? -1);

            if (kopya_ayni_mi($k, $onceki, $ayniKategori, false)) {
                continue 2;
            }
        }

        $kalan[]  = $h;
        $kokler[] = $k;
    }

    return $kalan;
}

/**
 * Bir gruptaki haberlerden yayinda kalacak olani secer: derleme, sonra
 * cok kaynakli, sonra onemi yuksek, esitse once yayimlanan (adresi
 * paylasilmis, okunmus olan).
 *
 * @param list<array<string,mixed>> $haberler
 */
function kopya_ana_sec(array $haberler): int
{
    usort($haberler, static function (array $a, array $b): int {
        return [
            (int) ($b['derleme'] ?? 0),
            trim((string) ($b['derleme_kaynaklari'] ?? '')) !== '' ? 1 : 0,
            (int) ($b['onem'] ?? 0),
            -(int) $b['id'],
        ] <=> [
            (int) ($a['derleme'] ?? 0),
            trim((string) ($a['derleme_kaynaklari'] ?? '')) !== '' ? 1 : 0,
            (int) ($a['onem'] ?? 0),
            -(int) $a['id'],
        ];
    });

    return (int) $haberler[0]['id'];
}

/**
 * Kopya haberi ana habere katar: yorumlar, tepkiler ve okunma ana habere
 * geciyor; kopya yayindan kalkiyor ve adresi ana habere yonleniyor.
 * Ana haberin onemi ikisinin buyugu oluyor.
 */
function haber_birlestir(int $anaId, int $kopyaId): bool
{
    if ($anaId <= 0 || $kopyaId <= 0 || $anaId === $kopyaId || !kopya_hazir()) {
        return false;
    }

    $oku = db()->prepare('SELECT id, durum, onem, birlesti_id FROM haberler WHERE id = :id');

    // Ana haber baska bir habere katildiysa zincirin sonuna.
    for ($adim = 0; $adim < 5; $adim++) {
        $oku->execute(['id' => $anaId]);
        $ana = $oku->fetch();

        if (!is_array($ana) || $ana['birlesti_id'] === null) {
            break;
        }

        $anaId = (int) $ana['birlesti_id'];
    }

    $oku->execute(['id' => $kopyaId]);
    $kopya = $oku->fetch();

    if (!is_array($ana) || !is_array($kopya) || $anaId === $kopyaId
        || $ana['durum'] !== HABER_YAYINDA || $kopya['birlesti_id'] !== null
        || $kopya['durum'] === HABER_REDDEDILDI) {
        return false;
    }

    $db = db();
    $db->beginTransaction();

    try {
        $p = ['a' => $anaId, 'k' => $kopyaId];

        $db->prepare('UPDATE yorumlar SET haber_id = :a WHERE haber_id = :k')->execute($p);
        // Uye iki habere de tepki verdiyse ana haberdeki kaliyor.
        $db->prepare("UPDATE IGNORE begeniler SET hedef_id = :a WHERE hedef_tur = 'haber' AND hedef_id = :k")->execute($p);
        $db->prepare("DELETE FROM begeniler WHERE hedef_tur = 'haber' AND hedef_id = :k")->execute(['k' => $kopyaId]);
        $okuyucu = $db->prepare('SELECT okuyucu FROM haber_okuyucu WHERE haber_id = :k');
        $okuyucu->execute(['k' => $kopyaId]);
        $okuyucu = (int) $okuyucu->fetchColumn();

        if ($okuyucu > 0) {
            $db->prepare(
                'INSERT INTO haber_okuyucu (haber_id, okuyucu) VALUES (:a, :o)
                 ON DUPLICATE KEY UPDATE okuyucu = okuyucu + VALUES(okuyucu)'
            )->execute(['a' => $anaId, 'o' => $okuyucu]);
            $db->prepare('DELETE FROM haber_okuyucu WHERE haber_id = :k')->execute(['k' => $kopyaId]);
        }
        $db->prepare('UPDATE ziyaretler SET haber_id = :a WHERE haber_id = :k')->execute($p);
        $db->prepare('UPDATE haberler SET onem = GREATEST(onem, :o) WHERE id = :a')
            ->execute(['o' => (int) $kopya['onem'], 'a' => $anaId]);
        $db->prepare('UPDATE haberler SET durum = :r, birlesti_id = :a, one_cikan = 0 WHERE id = :k')
            ->execute(['r' => HABER_REDDEDILDI] + $p);

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        error_log('[valentra] haber birlestirilemedi (' . $kopyaId . ' -> ' . $anaId . '): ' . $e->getMessage());

        return false;
    }

    return true;
}

/**
 * Bir grup haberi tek habere indirir.
 *
 * @param list<int> $idler
 * @return int Birlesen (yayindan kalkan) haber sayisi
 */
function kopya_grubu_birlestir(array $idler): int
{
    $idler = array_values(array_unique(array_filter(array_map('intval', $idler), static fn (int $i): bool => $i > 0)));

    if (count($idler) < 2 || count($idler) > 8) {
        return 0;
    }

    $yer = implode(',', array_fill(0, count($idler), '?'));
    $ifade = db()->prepare(
        "SELECT id, onem, derleme, derleme_kaynaklari FROM haberler
          WHERE id IN ({$yer}) AND durum = ? AND birlesti_id IS NULL
            AND olusturuldu >= NOW() - INTERVAL 7 DAY"
    );
    $ifade->execute([...$idler, HABER_YAYINDA]);
    $satirlar = $ifade->fetchAll();

    if (count($satirlar) < 2) {
        return 0;
    }

    $anaId = kopya_ana_sec($satirlar);
    $sayi  = 0;

    foreach ($satirlar as $s) {
        if ((int) $s['id'] !== $anaId && haber_birlestir($anaId, (int) $s['id'])) {
            $sayi++;
        }
    }

    return $sayi;
}

/**
 * Yayindaki son haberler (birlestirme ve model icin).
 *
 * @return list<array<string,mixed>>
 */
function kopya_son_haberler(int $saat = KOPYA_PENCERE_SAAT): array
{
    $ifade = db()->prepare(
        'SELECT h.id, h.baslik, h.ozet, h.onem, h.kategori_id, h.derleme, h.derleme_kaynaklari,
                COALESCE(h.yayin_tarihi, h.olusturuldu) AS tarih, k.ad AS kategori
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :y AND h.birlesti_id IS NULL
            AND COALESCE(h.yayin_tarihi, h.olusturuldu) >= NOW() - INTERVAL ' . max(1, min(168, $saat)) . ' HOUR
          ORDER BY COALESCE(h.yayin_tarihi, h.olusturuldu) ASC, h.id ASC
          LIMIT 400'
    );
    $ifade->execute(['y' => HABER_YAYINDA]);

    return $ifade->fetchAll();
}

/**
 * Model gerektirmeyen birlestirmeler: derlemenin dayandigi yayindaki
 * haberler derlemeye; basligi cok benzeyen haberler birbirine.
 *
 * @return int Birlesen haber sayisi
 */
function kopya_kendiliginden_birlestir(int $saat = KOPYA_PENCERE_SAAT): int
{
    if (!kopya_hazir()) {
        return 0;
    }

    $haberler = kopya_son_haberler(max($saat, 72));
    $sayi = 0;

    // 1. Derleme ve dayandigi haberler.
    $durum = db()->prepare('SELECT durum, birlesti_id, derleme FROM haberler WHERE id = :id');

    foreach ($haberler as $h) {
        foreach (array_keys(kopya_derleme_idleri($h)) as $kaynakId) {
            $durum->execute(['id' => $kaynakId]);
            $k = $durum->fetch();

            if (is_array($k) && $k['durum'] === HABER_YAYINDA && $k['birlesti_id'] === null
                && (int) $k['derleme'] === 0 && haber_birlestir((int) $h['id'], $kaynakId)) {
                $sayi++;
            }
        }
    }

    // 2. Basligi cok benzeyenler (son KOPYA_PENCERE_SAAT saat).
    $sinir = date('Y-m-d H:i:s', time() - $saat * 3600);
    $yakin = array_values(array_filter(
        kopya_son_haberler($saat),
        static fn (array $h): bool => (string) $h['tarih'] >= $sinir
    ));
    $kokler = array_map(static fn (array $h): array => kopya_kokler((string) $h['baslik']), $yakin);
    $ust = array_keys($yakin);
    $bul = static function (int $i) use (&$ust): int {
        while ($ust[$i] !== $i) {
            $i = $ust[$i] = $ust[$ust[$i]];
        }

        return $i;
    };

    foreach ($yakin as $i => $a) {
        for ($j = $i + 1, $n = count($yakin); $j < $n; $j++) {
            $ayniKategori = (int) $a['kategori_id'] === (int) $yakin[$j]['kategori_id'];

            if (kopya_ayni_mi($kokler[$i], $kokler[$j], $ayniKategori, true)) {
                $ust[$bul($j)] = $bul($i);
            }
        }
    }

    $gruplar = [];

    foreach (array_keys($yakin) as $i) {
        $gruplar[$bul($i)][] = (int) $yakin[$i]['id'];
    }

    foreach ($gruplar as $grup) {
        if (count($grup) > 1) {
            $sayi += kopya_grubu_birlestir(array_slice($grup, 0, 8));
        }
    }

    return $sayi;
}

/**
 * Birlesmis haberin yonlendirilecegi ana haberin adi (slug).
 */
function kopya_hedef_slug(string $slug = '', int $id = 0): ?string
{
    if (!kopya_hazir() || ($slug === '' && $id <= 0)) {
        return null;
    }

    $ifade = db()->prepare(
        'SELECT birlesti_id FROM haberler WHERE ' . ($slug !== '' ? 'slug = :d' : 'id = :d') . ' LIMIT 1'
    );
    $ifade->execute(['d' => $slug !== '' ? $slug : $id]);
    $hedef = $ifade->fetchColumn();

    // Zincir: ana haber de baska birine katilmis olabilir.
    $oku = db()->prepare('SELECT slug, durum, birlesti_id FROM haberler WHERE id = :id');

    for ($adim = 0; $adim < 5 && $hedef !== false && $hedef !== null; $adim++) {
        $oku->execute(['id' => (int) $hedef]);
        $ana = $oku->fetch();

        if (!is_array($ana)) {
            return null;
        }

        if ($ana['durum'] === HABER_YAYINDA) {
            return (string) $ana['slug'];
        }

        $hedef = $ana['birlesti_id'];
    }

    return null;
}
