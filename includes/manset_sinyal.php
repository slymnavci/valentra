<?php
declare(strict_types=1);

/**
 * MANSET SISTEMATIGI: buyuk yayinlarin ortak gundemi (13.11.2026).
 *
 * Site sahibinin istegi: "Mevcut duzene devam et ama manseti hazirlarken
 * TRT'nin son dakikalarindan faydalan... sadece TRT degil, Hurriyet,
 * Milliyet gibi onemli gazetelere bak. Onlarin haberlerinin karmasini
 * manset yapabiliriz. Manset icin bir sistematik belirlememiz lazim. NTV,
 * Haberturk gibi gazetelere bakilabilir. Bunu bir turlu ayarlayamadik."
 *
 * SISTEMATIK. Bir haberin onemini bugune dek yalnizca yapay zekanin 0-100
 * puani belirliyordu. Artik ikinci, olculebilir bir olcu var: AYNI OLAYI
 * KAC BUYUK YAYIN GUNDEMINE ALDI.
 *
 *   1. Ajan her taramada MANSET_YAYINLARI'ndaki yayinlarin gundem,
 *      politika, ekonomi ve dunya beslemelerindeki basliklari bildirir
 *      (api/manset-sinyal.php). Bu yayinlar zaten kaynak listesinde; yeni
 *      adres eklenmedi, hicbir kaynak cikarilmadi.
 *   2. Site her haberi (hangi kaynaktan yazilirsa yazilsin) bu basliklarla
 *      karsilastirir ve GUNDEM PUANI verir: olayi veren her buyuk yayin 1
 *      puan; TRT Haber'in MANSET listesinde de varsa 1 puan daha (o liste
 *      akis degil, editorun sectigi haberler).
 *   3. Puan onem puanina ek olur (manset_sinyal_eki): tek yayin 0; 2 puan
 *      +6; 3 puan +10; 4 puan +14; 5 puan +18; 6 ve ustu +20.
 *   4. Yayindaki son haberler de her taramada yeniden sayilir: olay
 *      sonradan buyurse (once bir, sonra bes yayin) haber o zaman yukselir.
 *
 * Tek yayinin one cikardigi haber yukselmez; bes yayinin birden verdigi
 * haber yukselir. Ek orantili oldugu icin yapay zekanin dusuk buldugu
 * haber ek puanla da zirveye cikmaz. Oncelik sinifi (kritik / yuksek),
 * dogrulama ve son dakika bandi kurallari DEGISMEZ: sehit haberi, buyuk
 * afet gibi kritik haberler yine en ustte; bu sistematik olagan haberlerin
 * kendi arasindaki sirayi belirler. Spor ve magazin haric (orada manset
 * baska olcuyle belirleniyor).
 *
 * Ayni ajans haberini bes sitenin vermesi de puan getirir: burada olculen
 * dogrulama degil (o ayri; bkz. oncelik_bagimsiz_kaynak_sayisi), yayinlarin
 * olayi gundemine alip almadigidir.
 */

/** Gundemine bakilan yayinlar: site alan adi => gorunen ad. */
const MANSET_YAYINLARI = [
    'trthaber.com'    => 'TRT Haber',
    'hurriyet.com.tr' => 'Hürriyet',
    'milliyet.com.tr' => 'Milliyet',
    'ntv.com.tr'      => 'NTV',
    'haberturk.com'   => 'Habertürk',
    'cnnturk.com'     => 'CNN Türk',
    'sozcu.com.tr'    => 'Sözcü',
    'sabah.com.tr'    => 'Sabah',
];

/** Ekten sonra onem en fazla bu kadar. */
const MANSET_SINYAL_TAVAN = 95;

/** Sinyal bu kadar saat gecerli (yayinin yayim anindan). */
const MANSET_SINYAL_SAAT = 12;

/** Saklanan baslik sayisi (en yeniler). */
const MANSET_SINYAL_EN_FAZLA = 900;

const MANSET_SINYAL_AYARI = 'manset_sinyal';

/** Ek almayan basliklar. */
const MANSET_SINYAL_KATEGORI_HARIC = ['spor', 'magazin', 'yemek-tarifleri'];

/** Sutunlar veritabaninda var mi. */
function manset_sinyal_hazir(): bool
{
    static $hazir = null;

    if ($hazir === null) {
        try {
            $hazir = (bool) db()->query("SHOW COLUMNS FROM haberler LIKE 'manset_yayinlar'")->fetch();
        } catch (Throwable $e) {
            $hazir = false;
        }
    }

    return $hazir;
}

/** Gundem puaninin onem eki: 0, 0, 6, 10, 14, 18, 20. */
function manset_sinyal_eki(int $puan): int
{
    return $puan < 2 ? 0 : min(20, 6 + ($puan - 2) * 4);
}

/**
 * Kayitli basliklar (suresi dolmamis).
 *
 * @return list<array{b:string,y:string,m:int,z:int}> b: baslik, y: yayin alan adi, m: manset listesi mi, z: yayim ani
 */
function manset_sinyalleri(): array
{
    require_once __DIR__ . '/ayarlar.php';

    $liste = json_decode(ayar_oku(MANSET_SINYAL_AYARI, '[]'), true);
    $sinir = time() - MANSET_SINYAL_SAAT * 3600;

    return array_values(array_filter(
        is_array($liste) ? $liste : [],
        static fn ($s): bool => is_array($s) && isset($s['b'], $s['y'], $s['z'], MANSET_YAYINLARI[$s['y']]) && (int) $s['z'] >= $sinir
    ));
}

/**
 * Iki baslik ayni olayi mi anlatiyor. Siki olcu: en az dort ortak kok ve
 * kisa basligin yarisi, ya da uc ortak kok ve kisa basligin dortte ucu.
 * Gercek TRT basliklariyla olculdu (13.11.2026, 204 baslik): daha gevsek
 * olcu "Brent petrol 90 dolarin altina geriledi" ile "Bitcoin 70 bin
 * dolarin altina geriledi"yi, "tavuk eti uretimi eylulde artti" ile "sanayi
 * uretim endeksi eylulde artti"yi ayni olay sayiyordu. Yanlis haberi
 * yukseltmek, dogru haberi kacirmaktan kotu.
 *
 * @param list<string> $a
 * @param list<string> $b
 */
function manset_sinyal_ayni_olay(array $a, array $b): bool
{
    if ($a === [] || $b === []) {
        return false;
    }

    $ortak = count(array_intersect($a, $b));
    $oran  = $ortak / min(count($a), count($b));

    return ($ortak >= 4 && $oran >= 0.5) || ($ortak >= 3 && $oran >= 0.75);
}

/** Basliklarin kokleri (karsilastirma icin; bkz. konu_kokleri). */
function manset_sinyal_kokleri(string $baslik): array
{
    require_once __DIR__ . '/konu_siniri.php';

    return konu_kokleri($baslik);
}

/**
 * Haberin gundem puani (eslesme olcusu: manset_sinyal_ayni_olay).
 *
 * @param list<string> $basliklar haberin kendi basligi ve kaynaktaki basligi
 * @param list<array{b:string,y:string,m:int,z:int,k?:list<string>}>|null $sinyaller
 * @return array{puan:int,yayinlar:list<string>,manset:bool}
 */
function manset_sinyal_puani(array $basliklar, ?array $sinyaller = null): array
{
    $sinyaller = $sinyaller ?? manset_sinyalleri();
    $kokler    = array_values(array_filter(array_map('manset_sinyal_kokleri', array_filter($basliklar, static fn (string $b): bool => trim($b) !== ''))));
    $yayinlar  = [];
    $manset    = false;

    foreach ($sinyaller as $s) {
        if (isset($yayinlar[$s['y']]) && ($manset || empty($s['m']))) {
            continue;
        }

        $sk = $s['k'] ?? manset_sinyal_kokleri((string) $s['b']);

        foreach ($kokler as $k) {
            if (manset_sinyal_ayni_olay($k, $sk)) {
                $yayinlar[$s['y']] = true;
                $manset = $manset || !empty($s['m']);
                break;
            }
        }
    }

    return [
        'puan'     => count($yayinlar) + ($manset ? 1 : 0),
        'yayinlar' => array_values(array_map(static fn (string $y): string => MANSET_YAYINLARI[$y], array_keys($yayinlar))),
        'manset'   => $manset,
    ];
}

/**
 * Puani habere uygular. Onceki puandan dusukse dokunmaz; yukseldiyse
 * yalnizca aradaki ek verilir (ek iki kez sayilmaz).
 *
 * @param array{puan:int,yayinlar:list<string>,manset:bool} $sonuc
 * @return bool haberin onemi degisti mi
 */
function manset_sinyal_uygula(int $id, array $sonuc): bool
{
    if (!manset_sinyal_hazir() || $sonuc['puan'] < 1) {
        return false;
    }

    $ifade = db()->prepare(
        'SELECT h.onem, h.manset_sinyal, h.olay_kimlik, k.slug AS kategori
           FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id WHERE h.id = :id'
    );
    $ifade->execute(['id' => $id]);
    $h = $ifade->fetch();

    if ($h === false || in_array((string) $h['kategori'], MANSET_SINYAL_KATEGORI_HARIC, true) || $sonuc['puan'] <= (int) $h['manset_sinyal']) {
        return false;
    }

    $ek   = manset_sinyal_eki($sonuc['puan']) - manset_sinyal_eki((int) $h['manset_sinyal']);
    $eski = (int) $h['onem'];
    $yeni = max($eski, min(MANSET_SINYAL_TAVAN, $eski + $ek));
    $ad   = implode(', ', $sonuc['yayinlar']) . ($sonuc['manset'] ? ' (TRT manşetinde)' : '');

    db()->prepare('UPDATE haberler SET onem = :o, manset_sinyal = :p, manset_yayinlar = :y, guncellendi = guncellendi WHERE id = :id')
        ->execute(['o' => $yeni, 'p' => min(20, $sonuc['puan']), 'y' => mb_substr($ad, 0, 200, 'UTF-8'), 'id' => $id]);

    if ($yeni === $eski) {
        return false;
    }

    require_once __DIR__ . '/oncelik.php';

    if (oncelik_hazir()) {
        oncelik_gecmis_yaz(
            (string) ($h['olay_kimlik'] ?? '') ?: 'haber-' . $id,
            $id,
            'gundem',
            'Büyük yayınların gündemi: ' . $ad . ' — gündem puanı ' . $sonuc['puan'] . ', önem ' . $eski . ' → ' . $yeni
        );
    }

    return true;
}

/**
 * Ajanin bildirdigi basliklari kaydeder ve yayindaki son haberleri yeniden sayar.
 *
 * @param list<array{baslik?:string,yayin?:string,manset?:bool,zaman?:int}> $girdiler
 * @return array{kayitli:int,yukselen:int}
 */
function manset_sinyal_yaz(array $girdiler): array
{
    require_once __DIR__ . '/ayarlar.php';

    $simdi = time();
    $sinir = $simdi - MANSET_SINYAL_SAAT * 3600;
    $liste = [];

    // Eskiler suresi dolana dek kalir: bir tarama yayinlarin yalnizca bir kismini okumus olabilir.
    foreach (manset_sinyalleri() as $s) {
        $liste[$s['y'] . '|' . mb_strtolower((string) $s['b'], 'UTF-8')] = $s;
    }

    foreach (array_slice($girdiler, 0, 3000) as $g) {
        if (!is_array($g)) {
            continue;
        }

        $baslik = trim((string) ($g['baslik'] ?? ''));
        $yayin  = strtolower(trim((string) ($g['yayin'] ?? '')));
        $zaman  = (int) ($g['zaman'] ?? 0);
        $zaman  = $zaman > 0 && $zaman <= $simdi + 300 ? min($zaman, $simdi) : $simdi;

        if ($baslik === '' || !isset(MANSET_YAYINLARI[$yayin]) || $zaman < $sinir) {
            continue;
        }

        $anahtar = $yayin . '|' . mb_strtolower($baslik, 'UTF-8');
        $manset  = !empty($g['manset']) || !empty($liste[$anahtar]['m']);

        $liste[$anahtar] = ['b' => mb_substr($baslik, 0, 160, 'UTF-8'), 'y' => $yayin, 'm' => $manset ? 1 : 0, 'z' => $zaman];
    }

    $liste = array_values($liste);
    usort($liste, static fn (array $a, array $b): int => $b['z'] <=> $a['z']);
    $liste = array_slice($liste, 0, MANSET_SINYAL_EN_FAZLA);

    ayar_yaz(MANSET_SINYAL_AYARI, (string) json_encode($liste, JSON_UNESCAPED_UNICODE));

    if (!manset_sinyal_hazir() || $liste === []) {
        return ['kayitli' => count($liste), 'yukselen' => 0];
    }

    foreach ($liste as $i => $s) {
        $liste[$i]['k'] = manset_sinyal_kokleri((string) $s['b']);
    }

    $haberler = db()->query(
        "SELECT h.id, h.baslik, h.kaynak_baslik
           FROM haberler h
          WHERE h.durum = 'yayinda' AND h.olusturuldu >= NOW() - INTERVAL " . MANSET_SINYAL_SAAT . ' HOUR
          ORDER BY h.id DESC LIMIT 300'
    )->fetchAll();

    $yukselen = 0;

    foreach ($haberler as $h) {
        $sonuc = manset_sinyal_puani([(string) $h['baslik'], (string) ($h['kaynak_baslik'] ?? '')], $liste);

        if (manset_sinyal_uygula((int) $h['id'], $sonuc)) {
            $yukselen++;
        }
    }

    if ($yukselen > 0) {
        require_once __DIR__ . '/oncelik.php';
        oncelik_onbellek_gecersiz();
    }

    return ['kayitli' => count($liste), 'yukselen' => $yukselen];
}

/* -------------------------------------------------------------------------
 * MANSET GORSELI (13.11.2026)
 *
 * Site sahibinin istegi: "Mansete alinan haberlerde resimli haber olmasi
 * onemli. Cozunurluk yuksek olacak. Hicbir resimde gazete adi yazmayacak."
 *
 * Kayan mansete (ana sayfanin en ustundeki buyuk slaytlar) giren haberin:
 *   1. gorseli olacak;
 *   2. gorsel DENETIMDEN GECMIS olacak: yapay zeka resme bakip uzerinde
 *      gazete, kanal ya da ajans adi/logosu/filigrani olmadigini onaylamis
 *      (gorsel_denetim 1) ya da logolu gorsel ozgur lisansli temiz bir
 *      fotografla degismis (2). Bakilmamis (NULL), logosu kalmis (3) ya da
 *      konu gorseline dusmus (0) haber mansete girmez;
 *   3. gorsel en az MANSET_GORSEL_EN_AZ_GENISLIK piksel genisliginde olacak
 *      (sunucudaki kopyadan olculur; henuz inmemis gorsel olculemez, haber
 *      bir sonraki sayfa uretiminde degerlendirilir).
 * Kosulu tutmayan haber kaybolmaz: ayni sirayla mansetin altindaki haber
 * izgarasinda gorunur, gorseli denetlenince mansete cikar.
 *
 * Istisna: kritik haber (sehit, buyuk afet...) ve editorun mansete aldigi
 * haber gorsel beklemeden mansette kalir.
 * ---------------------------------------------------------------------- */

/** Manset gorselinin en az genisligi (piksel). */
const MANSET_GORSEL_EN_AZ_GENISLIK = 1000;

/** Kosulu tutan haber bu sayinin altindaysa manset siradaki haberlerle tamamlanir. */
const MANSET_EN_AZ_SLAYT = 5;

/**
 * Haber gorseliyle mansete uygun mu.
 *
 * @param array<string,mixed> $h haberler satiri
 * @return array{uygun:bool,neden:string}
 */
function manset_gorsel_durumu(array $h): array
{
    require_once __DIR__ . '/gorsel_onbellek.php';
    require_once __DIR__ . '/oncelik.php';

    $simdi = time();

    if ((int) ($h['editor_manset'] ?? 0) === 1 && (strtotime((string) ($h['editor_bitis'] ?? '')) ?: 0) > $simdi) {
        return ['uygun' => true, 'neden' => 'editör manşete aldı'];
    }

    $gelisme = strtotime((string) ($h['gelisme_zamani'] ?? '')) ?: (strtotime((string) ($h['kaynak_tarihi'] ?? '')) ?: 0);

    if ((int) ($h['oncelik'] ?? 1) === ONCELIK_KRITIK && $gelisme >= $simdi - ONCELIK_KRITIK_SAAT * 3600) {
        return ['uygun' => true, 'neden' => 'kritik haber: görsel beklenmez'];
    }

    $url = guvenli_url((string) ($h['gorsel_url'] ?? ''));

    if ($url === '') {
        return ['uygun' => false, 'neden' => 'görseli yok'];
    }

    $denetim = $h['gorsel_denetim'] ?? null;

    if ($denetim === null || !in_array((int) $denetim, [1, 2], true)) {
        return ['uygun' => false, 'neden' => match (true) {
            $denetim === null     => 'görsel henüz denetlenmedi',
            (int) $denetim === 3  => 'görselde yayın adı/logosu var, temiz görsel bulunamadı',
            default               => 'özgün fotoğraf yok (konu görseli)',
        }];
    }

    $genislik = manset_gorsel_genisligi($url);

    if ($genislik === 0) {
        return ['uygun' => false, 'neden' => 'görsel henüz sunucuya inmedi, çözünürlük ölçülemedi'];
    }

    if ($genislik < MANSET_GORSEL_EN_AZ_GENISLIK) {
        return ['uygun' => false, 'neden' => 'görsel küçük (' . $genislik . ' piksel; en az ' . MANSET_GORSEL_EN_AZ_GENISLIK . ')'];
    }

    return ['uygun' => true, 'neden' => 'görsel temiz, ' . $genislik . ' piksel'];
}

/** Gorselin sunucudaki kopyasinin genisligi (piksel); kopya yoksa 0. */
function manset_gorsel_genisligi(string $url): int
{
    static $onbellek = [];

    if (isset($onbellek[$url])) {
        return $onbellek[$url];
    }

    $yerel = haber_gorsel_yerel($url);

    // Sitenin kendi gorseli (/assets/...): dosya dogrudan orada.
    if ($yerel === '' && preg_match('#^https?://[^/]+(/assets/[^?\#]+)#', $url, $m) === 1 && is_file(dirname(__DIR__) . $m[1])) {
        $yerel = $m[1];
    }

    $bilgi = $yerel !== '' ? @getimagesize(dirname(__DIR__) . $yerel) : false;

    return $onbellek[$url] = $bilgi === false ? 0 : (int) $bilgi[0];
}

/**
 * Ana sayfa havuzunu (siralanmis haberler) manset ve izgara diye ayirir.
 * Sira korunur: manset, havuzun kosulu tutan ilk $mansetAdet haberi;
 * kalanlar ayni sirayla izgaraya.
 *
 * @param list<array<string,mixed>> $havuz
 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
 */
function manset_gorsele_gore_ayir(array $havuz, int $mansetAdet): array
{
    $manset = [];
    $kalan  = [];

    foreach ($havuz as $h) {
        if (count($manset) < $mansetAdet && manset_gorsel_durumu($h)['uygun']) {
            $manset[] = $h;
        } else {
            $kalan[] = $h;
        }
    }

    // Manset bos ya da cok kisa kalmasin: siradaki haberlerle tamamlanir.
    while (count($manset) < min(MANSET_EN_AZ_SLAYT, $mansetAdet) && $kalan !== []) {
        $manset[] = array_shift($kalan);
    }

    return [$manset, $kalan];
}
