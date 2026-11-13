<?php
declare(strict_types=1);

/**
 * KIM NE YAZDI: ayni olayi hangi yayin nasil verdi (13.11.2026).
 *
 * Site sahibinin istegi: "Gunun en onemli 5 haberi yerine bu kutuya o gunun
 * onemli haber basliklarini koyacagiz. Buradan secim yapacaklar. Secilen
 * haberlerin cesitli gazetelerdeki yorumlarini gorecegiz: kim ne yazdi."
 * Kararlar (site sahibi, ayni gun): yayinlara siyasi egilim etiketi YOK;
 * yayinlar mevcut 8 + kaynak listesindeki diger yayinlar; ilk surumde yapay
 * zeka karsilastirmasi yok, her yayinin KENDI basligi gosterilir.
 *
 * NASIL CALISIR
 *   1. Ajan her taramada KNY_YAYINLARI'ndaki yayinlarin beslemelerindeki
 *      basliklari, baglantilari ve kisa ozetlerini bildirir (api/manset-
 *      sinyal.php; manset gundem puaniyla ayni istek).
 *   2. Site bunlari gundem_basliklari tablosuna yazar ve ayni olayi anlatan
 *      basliklari kumeler (manset_sinyal_ayni_olay ile ayni olcu). Sonuc
 *      ayarlarda saklanir; sayfalar kumelemeyi yeniden yapmaz.
 *   3. Ana sayfa kutusu en cok yayinin verdigi olaylari listeler; olay
 *      sayfasi (/kim-ne-yazdi/N) her yayinin basligini, kisa ozetini,
 *      saatini ve kendi sitesine baglantisini yan yana gosterir.
 *
 * TELIF: yayinlardan yalnizca baslik, en fazla KNY_OZET_UZUNLUK karakterlik
 * ozet ve baglanti gosterilir; metin ve fotograf alinmaz, okur haberi
 * yayinin kendi sitesinde okur.
 *
 * "YAZMAYANLAR" listesine yalnizca GENEL yayinlar girer ve yalnizca son 24
 * saatte beslemesi okunabilmis olanlar: ekonomi yayininin siyaset haberini
 * vermemesi ya da beslemesi okunamayan yayin "yazmadi" diye gosterilmez.
 */

/** Yayinlar: site alan adi => [ad, genel yayin mi]. Siyasi etiket yok. */
const KNY_YAYINLARI = [
    'trthaber.com'     => ['TRT Haber', true],
    'hurriyet.com.tr'  => ['Hürriyet', true],
    'milliyet.com.tr'  => ['Milliyet', true],
    'ntv.com.tr'       => ['NTV', true],
    'haberturk.com'    => ['Habertürk', true],
    'cnnturk.com'      => ['CNN Türk', true],
    'sozcu.com.tr'     => ['Sözcü', true],
    'sabah.com.tr'     => ['Sabah', true],
    'bbc.com'          => ['BBC Türkçe', true],
    'dw.com'           => ['DW Türkçe', true],
    'cumhuriyet.com.tr' => ['Cumhuriyet', false],
    'yenisafak.com'    => ['Yeni Şafak', false],
    'star.com.tr'      => ['Star', false],
    'tr.euronews.com'  => ['Euronews Türkçe', false],
    'bloomberght.com'  => ['Bloomberg HT', false],
    'dunya.com'        => ['Dünya', false],
    'ekonomim.com'     => ['Ekonomim', false],
];

/** Yayindan gosterilen ozetin en fazla uzunlugu (karakter). */
const KNY_OZET_UZUNLUK = 180;

/** Olaylar bu kadar saatlik basliklardan kumelenir. */
const KNY_PENCERE_SAAT = 24;

/** Basliklar bu kadar gun saklanir (olay sayfasi acik kalsin). */
const KNY_SAKLAMA_GUN = 4;

/** Bir olayin listeye girmesi icin en az bu kadar farkli yayin. */
const KNY_EN_AZ_YAYIN = 3;

/** Kutu ve liste icin saklanan olay sayisi. */
const KNY_OLAY_SAYISI = 30;

const KNY_AYARI = 'kim_ne_yazdi';

function kny_hazir(): bool
{
    static $hazir = null;

    if ($hazir === null) {
        try {
            $hazir = (bool) db()->query("SHOW TABLES LIKE 'gundem_basliklari'")->fetch();
        } catch (Throwable $e) {
            $hazir = false;
        }
    }

    return $hazir;
}

function kny_yolu(int $olay = 0): string
{
    return '/kim-ne-yazdi' . ($olay > 0 ? '/' . $olay : '');
}

/** Besleme ozetini duz, kisa metne cevirir. */
function kny_ozet(string $ham): string
{
    $metin = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($ham), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    if (mb_strlen($metin, 'UTF-8') <= KNY_OZET_UZUNLUK) {
        return $metin;
    }

    $kisa = mb_substr($metin, 0, KNY_OZET_UZUNLUK, 'UTF-8');
    $bosluk = mb_strrpos($kisa, ' ', 0, 'UTF-8');

    return rtrim($bosluk !== false && $bosluk > 80 ? mb_substr($kisa, 0, $bosluk, 'UTF-8') : $kisa, " ,;:.") . '…';
}

/**
 * Ajanin bildirdigi basliklari tabloya yazar (ayni yayin + ayni adres bir kez).
 *
 * @param list<array<string,mixed>> $girdiler baslik, yayin, adres, ozet, manset, zaman
 * @return int yeni yazilan baslik sayisi
 */
function kny_kaydet(array $girdiler): int
{
    if (!kny_hazir()) {
        return 0;
    }

    $simdi = time();
    $ekle  = db()->prepare(
        'INSERT IGNORE INTO gundem_basliklari (yayin, baslik, adres, adres_parmak, ozet, manset, zaman)
         VALUES (:yayin, :baslik, :adres, :parmak, :ozet, :manset, FROM_UNIXTIME(:zaman))'
    );
    $yeni = 0;

    foreach (array_slice($girdiler, 0, 3000) as $g) {
        if (!is_array($g)) {
            continue;
        }

        $yayin  = strtolower(trim((string) ($g['yayin'] ?? '')));
        $baslik = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($g['baslik'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $adres  = guvenli_url(trim((string) ($g['adres'] ?? '')));
        $zaman  = (int) ($g['zaman'] ?? 0);
        $zaman  = $zaman > 0 && $zaman <= $simdi + 300 ? min($zaman, $simdi) : $simdi;

        if (!isset(KNY_YAYINLARI[$yayin]) || $baslik === '' || $adres === '' || !preg_match('#^https?://#i', $adres)
            || $zaman < $simdi - KNY_SAKLAMA_GUN * 86400) {
            continue;
        }

        $ekle->execute([
            'yayin'  => $yayin,
            'baslik' => mb_substr($baslik, 0, 300, 'UTF-8'),
            'adres'  => mb_substr($adres, 0, 700, 'UTF-8'),
            'parmak' => sha1($yayin . '|' . $adres),
            'ozet'   => kny_ozet((string) ($g['ozet'] ?? '')),
            'manset' => !empty($g['manset']) ? 1 : 0,
            'zaman'  => $zaman,
        ]);
        $yeni += $ekle->rowCount();
    }

    db()->exec('DELETE FROM gundem_basliklari WHERE zaman < NOW() - INTERVAL ' . KNY_SAKLAMA_GUN . ' DAY');

    return $yeni;
}

/**
 * Basliklari olaylara kumeler. Veritabanina dokunmaz; testler dogrudan cagirir.
 *
 * Baglanti zinciri YOK (A~B, B~C diye A ile C birlesmez): her baslik, kok
 * kumesi kendisine benzeyen ILK olayin CEKIRDEK basligiyla karsilastirilir.
 * Zincir, gun icinde "Erdogan ... zirve" basliklari uzerinden ilgisiz
 * olaylari tek olayda topluyordu.
 *
 * @param list<array<string,mixed>> $satirlar id, yayin, baslik, zaman (unix), manset
 * @return list<array{kimlik:int,uyeler:list<int>,yayinlar:list<string>,son:int,manset:bool}>
 */
function kny_kumele(array $satirlar): array
{
    require_once __DIR__ . '/manset_sinyal.php';

    // Eskiden yeniye: olayin cekirdegi ilk cikan baslik, kimligi onun satiri.
    usort($satirlar, static fn (array $a, array $b): int => [(int) $a['zaman'], (int) $a['id']] <=> [(int) $b['zaman'], (int) $b['id']]);

    $olaylar = [];
    $dizin   = [];   // kok => [olay sirasi => true]: yalnizca ortak koku olan olaylar denenir

    foreach ($satirlar as $s) {
        $kok = manset_sinyal_kokleri((string) $s['baslik']);
        $yer = null;

        // En az uc ortak kok paylasan olaylar (manset_sinyal_ayni_olay'in alt siniri).
        $sayac = [];

        $duz = array_values(array_filter($kok, 'is_string'));

        foreach ($duz as $k) {
            foreach ($dizin[$k] ?? [] as $i => $_) {
                $sayac[$i] = ($sayac[$i] ?? 0) + 1;
            }
        }

        ksort($sayac);

        foreach ($sayac as $i => $ortak) {
            if ($ortak < 3) {
                continue;
            }

            foreach ($olaylar[$i]['kokler'] as $ok) {
                if (manset_sinyal_ayni_olay($kok, $ok)) {
                    $yer = $i;
                    break 2;
                }
            }
        }

        if ($yer === null) {
            $olaylar[] = ['kimlik' => (int) $s['id'], 'uyeler' => [], 'yayinlar' => [], 'son' => 0, 'manset' => false, 'kokler' => [$kok]];
            $yer = count($olaylar) - 1;
            $cekirdek = true;
        } elseif (count($olaylar[$yer]['kokler']) < 3) {
            // Ilk uc baslik cekirdek: ayni olayin farkli anlatimlari da eslessin.
            $olaylar[$yer]['kokler'][] = $kok;
            $cekirdek = true;
        } else {
            $cekirdek = false;
        }

        if ($cekirdek) {
            foreach ($duz as $k) {
                $dizin[$k][$yer] = true;
            }
        }

        $olaylar[$yer]['uyeler'][] = (int) $s['id'];
        $olaylar[$yer]['yayinlar'][(string) $s['yayin']] = true;
        $olaylar[$yer]['son'] = max($olaylar[$yer]['son'], (int) $s['zaman']);
        $olaylar[$yer]['manset'] = $olaylar[$yer]['manset'] || !empty($s['manset']);
    }

    return array_map(static function (array $o): array {
        unset($o['kokler']);
        $o['yayinlar'] = array_keys($o['yayinlar']);

        return $o;
    }, $olaylar);
}

/**
 * Olaylari yeniden hesaplar ve saklar (ajanin her bildiriminden sonra).
 *
 * @return int listeye giren olay sayisi
 */
function kny_yenile(): int
{
    if (!kny_hazir()) {
        return 0;
    }

    require_once __DIR__ . '/ayarlar.php';

    $satirlar = db()->query(
        'SELECT id, yayin, baslik, manset, UNIX_TIMESTAMP(zaman) AS zaman
           FROM gundem_basliklari
          WHERE zaman >= NOW() - INTERVAL ' . KNY_PENCERE_SAAT . ' HOUR
          ORDER BY zaman DESC LIMIT 2500'
    )->fetchAll();

    $olaylar = array_values(array_filter(
        kny_kumele($satirlar),
        static fn (array $o): bool => count($o['yayinlar']) >= KNY_EN_AZ_YAYIN
    ));

    // Once en cok yayinin verdigi, esitlikte TRT mansetindeki, sonra en yeni.
    usort($olaylar, static fn (array $a, array $b): int =>
        [count($b['yayinlar']), $b['manset'], $b['son']] <=> [count($a['yayinlar']), $a['manset'], $a['son']]);

    $olaylar = array_slice($olaylar, 0, KNY_OLAY_SAYISI);
    $baslik  = array_column($satirlar, 'baslik', 'id');

    $kayit = array_map(static fn (array $o): array => [
        'k' => $o['kimlik'],
        'b' => kny_olay_basligi(array_map(static fn (int $id): string => (string) ($baslik[$id] ?? ''), $o['uyeler'])),
        'y' => $o['yayinlar'],
        'u' => $o['uyeler'],
        's' => $o['son'],
    ], $olaylar);

    ayar_yaz(KNY_AYARI, (string) json_encode(['z' => time(), 'o' => $kayit], JSON_UNESCAPED_UNICODE));

    return count($kayit);
}

/**
 * Olayin gosterilecek basligi: yayinlarin basliklarindan en "ortalama"
 * olani (diger basliklarla en cok kok paylasan); esitlikte kisa olan.
 * Hicbir yayinin vurgusu tek basina secilmis olmaz, baslik da uydurulmaz.
 *
 * @param list<string> $basliklar
 */
function kny_olay_basligi(array $basliklar): string
{
    require_once __DIR__ . '/manset_sinyal.php';

    $basliklar = array_values(array_filter(array_map('trim', $basliklar)));

    if ($basliklar === []) {
        return '';
    }

    $kokler = array_map(static fn (string $b): array => array_values(array_filter(manset_sinyal_kokleri($b), 'is_string')), $basliklar);
    $en     = 0;
    $enPuan = -1.0;

    foreach ($basliklar as $i => $b) {
        $puan = 0;

        foreach ($kokler as $j => $k) {
            $puan += $i === $j ? 0 : count(array_intersect($kokler[$i], $k));
        }

        // Cok uzun ve "son dakika" diye baslayan basliklar geride.
        $puan -= mb_strlen($b, 'UTF-8') / 40;
        $puan -= preg_match('/^(son dakika|flaş|sıcak gelişme)/iu', $b) === 1 ? 3 : 0;

        if ($puan > $enPuan) {
            $enPuan = $puan;
            $en     = $i;
        }
    }

    return $basliklar[$en];
}

/**
 * Saklanan olaylar (kutu ve liste sayfasi).
 *
 * @return list<array{kimlik:int,baslik:string,yayinlar:list<string>,uyeler:list<int>,son:int}>
 */
function kny_olaylar(int $adet = KNY_OLAY_SAYISI): array
{
    require_once __DIR__ . '/ayarlar.php';

    $veri = json_decode(ayar_oku(KNY_AYARI, ''), true);

    if (!is_array($veri) || !is_array($veri['o'] ?? null) || (int) ($veri['z'] ?? 0) < time() - KNY_PENCERE_SAAT * 3600) {
        return [];
    }

    $liste = [];

    foreach (array_slice($veri['o'], 0, $adet) as $o) {
        $liste[] = [
            'kimlik'   => (int) ($o['k'] ?? 0),
            'baslik'   => (string) ($o['b'] ?? ''),
            // Her yerde ayni, sabit sira (KNY_YAYINLARI): yayinlar bir goruse gore dizilmez.
            'yayinlar' => array_values(array_intersect(array_keys(KNY_YAYINLARI), (array) ($o['y'] ?? []))),
            'uyeler'   => array_map('intval', (array) ($o['u'] ?? [])),
            'son'      => (int) ($o['s'] ?? 0),
        ];
    }

    return $liste;
}

/** Son 24 saatte beslemesi okunabilmis GENEL yayinlar ("yazmayanlar" icin payda). */
function kny_etkin_genel_yayinlar(): array
{
    static $liste = null;

    if ($liste !== null) {
        return $liste;
    }

    $liste = [];

    if (kny_hazir()) {
        foreach (db()->query('SELECT DISTINCT yayin FROM gundem_basliklari WHERE zaman >= NOW() - INTERVAL ' . KNY_PENCERE_SAAT . ' HOUR')->fetchAll(PDO::FETCH_COLUMN) as $y) {
            if (isset(KNY_YAYINLARI[$y]) && KNY_YAYINLARI[$y][1]) {
                $liste[] = (string) $y;
            }
        }
    }

    return $liste;
}

/**
 * Bir olayin sayfasi: her yayinin basliklari (yayin basina en yenisi once).
 *
 * @return array{kimlik:int,baslik:string,yayinlar:array<string,list<array<string,mixed>>>,yazmayanlar:list<string>,ilk:int,son:int,haber:?array}|null
 */
function kny_olay(int $kimlik): ?array
{
    if ($kimlik <= 0 || !kny_hazir()) {
        return null;
    }

    $uyeler = null;
    $baslik = '';

    foreach (kny_olaylar() as $o) {
        if ($o['kimlik'] === $kimlik) {
            $uyeler = $o['uyeler'];
            $baslik = $o['baslik'];
            break;
        }
    }

    // Listeden dusmus eski olay: cekirdek basligin cevresindeki basliklardan yeniden kur.
    if ($uyeler === null) {
        $ifade = db()->prepare('SELECT id, yayin, baslik, manset, UNIX_TIMESTAMP(zaman) AS zaman FROM gundem_basliklari WHERE id = :id');
        $ifade->execute(['id' => $kimlik]);
        $cekirdek = $ifade->fetch();

        if ($cekirdek === false) {
            return null;
        }

        $ifade = db()->prepare(
            'SELECT id, yayin, baslik, manset, UNIX_TIMESTAMP(zaman) AS zaman FROM gundem_basliklari
              WHERE zaman BETWEEN FROM_UNIXTIME(:a) AND FROM_UNIXTIME(:b) ORDER BY zaman LIMIT 2500'
        );
        $ifade->execute(['a' => (int) $cekirdek['zaman'] - 60, 'b' => (int) $cekirdek['zaman'] + KNY_PENCERE_SAAT * 3600]);

        foreach (kny_kumele($ifade->fetchAll()) as $o) {
            if ($o['kimlik'] === $kimlik) {
                $uyeler = $o['uyeler'];
                break;
            }
        }

        if ($uyeler === null) {
            return null;
        }
    }

    $yer = implode(',', array_map('intval', $uyeler)) ?: '0';
    $satirlar = db()->query(
        'SELECT id, yayin, baslik, adres, ozet, manset, UNIX_TIMESTAMP(zaman) AS zaman
           FROM gundem_basliklari WHERE id IN (' . $yer . ') ORDER BY zaman DESC'
    )->fetchAll();

    if ($satirlar === []) {
        return null;
    }

    $yayinlar = [];

    foreach ($satirlar as $s) {
        if (isset(KNY_YAYINLARI[$s['yayin']])) {
            $yayinlar[(string) $s['yayin']][] = $s;
        }
    }

    // KNY_YAYINLARI sirasiyla (alfabe ya da egilim degil; sabit, tarafsiz sira).
    $yayinlar = array_replace(array_intersect_key(array_fill_keys(array_keys(KNY_YAYINLARI), []), $yayinlar), $yayinlar);

    if ($baslik === '') {
        $baslik = kny_olay_basligi(array_column($satirlar, 'baslik'));
    }

    $zamanlar = array_map('intval', array_column($satirlar, 'zaman'));

    return [
        'kimlik'      => $kimlik,
        'baslik'      => $baslik,
        'yayinlar'    => $yayinlar,
        'yazmayanlar' => array_values(array_diff(kny_etkin_genel_yayinlar(), array_keys($yayinlar))),
        'ilk'         => min($zamanlar),
        'son'         => max($zamanlar),
        'haber'       => kny_valentra_haberi(array_column($satirlar, 'baslik')),
    ];
}

/**
 * Olayin Valentra'daki haberi (varsa): okur ozeti sitede de okuyabilsin.
 *
 * @param list<string> $basliklar
 */
function kny_valentra_haberi(array $basliklar): ?array
{
    require_once __DIR__ . '/manset_sinyal.php';

    $kokler = array_map('manset_sinyal_kokleri', array_slice($basliklar, 0, 12));

    try {
        $adaylar = db()->query(
            "SELECT h.id, h.baslik, h.slug, h.kaynak_baslik FROM haberler h
              WHERE h.durum = 'yayinda' AND h.olusturuldu >= NOW() - INTERVAL 3 DAY
              ORDER BY h.id DESC LIMIT 400"
        )->fetchAll();
    } catch (Throwable $e) {
        return null;
    }

    foreach ($adaylar as $h) {
        foreach ([(string) $h['baslik'], (string) ($h['kaynak_baslik'] ?? '')] as $b) {
            $hk = $b !== '' ? manset_sinyal_kokleri($b) : [];

            foreach ($kokler as $k) {
                if (manset_sinyal_ayni_olay($hk, $k)) {
                    return ['baslik' => (string) $h['baslik'], 'slug' => (string) $h['slug']];
                }
            }
        }
    }

    return null;
}

/** "3 saat önce" */
function kny_once(int $zaman): string
{
    $fark = max(0, time() - $zaman);

    return match (true) {
        $fark < 3600  => max(1, intdiv($fark, 60)) . ' dk önce',
        $fark < 86400 => intdiv($fark, 3600) . ' saat önce',
        default       => date('d.m.Y H:i', $zaman),
    };
}

/**
 * Ana sayfa kutusu ("Bugun bilmeniz gereken 5 gelisme"nin yerinde).
 * Olay yoksa bos doner; cagiran eski kutuya duser.
 */
function kny_kutu_html(int $adet = 5): string
{
    $olaylar = kny_olaylar($adet);

    if (count($olaylar) < 3) {
        return '';
    }

    $toplam = max(1, count(KNY_YAYINLARI));
    $html   = '';

    foreach ($olaylar as $o) {
        $sayi  = count($o['yayinlar']);
        $adlar = implode(', ', array_map(static fn (string $y): string => KNY_YAYINLARI[$y][0], array_slice($o['yayinlar'], 0, 4)))
            . ($sayi > 4 ? ' ve ' . ($sayi - 4) . ' yayın daha' : '');

        $html .= '<li><span class="bes-no kny-sayi" title="' . e($sayi . ' yayın yazdı') . '">' . $sayi . '</span>'
            . '<a href="' . e(kny_yolu($o['kimlik'])) . '">'
            . '<span class="bes-baslik">' . e($o['baslik']) . '</span>'
            . '<span class="kny-kim">' . e($adlar) . '</span>'
            . '</a></li>';
    }

    return '<section class="pencere bes-gelisme kny-kutu" aria-labelledby="kny-baslik">'
        . '<h2 class="pencere-baslik" id="kny-baslik">Kim ne yazdı?</h2>'
        . '<ol class="bes-liste">' . $html . '</ol>'
        . '<a class="bes-bulten" href="' . e(kny_yolu()) . '">Günün bütün başlıkları: hangi yayın nasıl verdi →</a>'
        . '</section>';
}
