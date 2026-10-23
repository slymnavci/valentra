<?php
declare(strict_types=1);

/**
 * Ziyaret kaydı ve okunma istatistikleri.
 *
 * Neden kendi sayacimiz: Google Analytics gibi bir araci kullanmak
 * ziyaretcinin verisini ucuncu bir tarafa gondermek demek. Bir YMM
 * sitesinde bunu varsayilan yapmak dogru degil; ustelik reklam
 * engelleyiciler o betikleri siklikla bloke ediyor ve sayilar eksik
 * cikiyor. Kendi tarafimizda tutunca hem veri bizde kaliyor hem de
 * her istek sayiliyor.
 *
 * IKI ASAMALI SAYIM (23.10.2026, site sahibi: "ziyaretci sayisi dogru
 * hesaplaniyor mu, iyi kontrol et"):
 *
 *   1. Sunucu her sayfa isteginde bir satir yaziyor (ziyaret_kaydet).
 *      Bot imzasi tasiyanlar ve yonetici zaten elenmis oluyor.
 *   2. Sayfa TARAYICIDA GERCEKTEN GORUNDUGUNDE kucuk bir betik
 *      api/gorunum.php'ye haber veriyor ve satir "insan" isaretleniyor;
 *      sayfa kapanirken goruntulenme suresi ve kaydirma derinligi de
 *      yaziliyor.
 *
 *   Neden: tarayici kimligini gizleyen botlar, baglanti onizlemeleri
 *   (WhatsApp, Telegram), tarayicinin onceden yukledigi ama hic
 *   gosterilmeyen sayfalar ve izleme servisleri birinci asamadan geciyor
 *   ama betik calistirmiyor. Raporlar ikinci asamayi gecenleri sayiyor
 *   (ziyaret_insan_kosulu). Betik ilk kez calisana kadar, yani bu
 *   surumden onceki kayitlar icin, eskisi gibi her satir sayiliyor.
 *
 * KISISEL VERI. Uc alan tutuluyor, ucu de ayri ise yariyor:
 *
 *   ziyaretci   - adres + tarayici imzasi + gizli tuz ile uretilen takma
 *                 kimlik. Betik calismadiginda "ayni ziyaretci mi"
 *                 sorusunun tek cevabi.
 *   tarayici_id - betigin tarayicida tuttugu rastgele bir kimlik. Mobil
 *                 agda adres degistikce ayni kisi yeni ziyaretci
 *                 sayiliyordu; bu kimlik adres degisse de ayni kaliyor.
 *                 Kisiye dair hicbir bilgi tasimiyor.
 *   ip          - ham adres. Panelde gosterilmek uzere, sitenin
 *                 yoneticisinin acik talebi uzerine saklaniyor.
 *
 * Ham adres ve tarayici kimligi kisisel veridir; saklanmasi KVKK
 * kapsaminda bir islemedir ve sitenin aydinlatma metninde yer almasi
 * gerekir. Veri hicbir ucuncu tarafa gonderilmiyor ve 180 gun sonra
 * siliniyor.
 */

require_once __DIR__ . '/ayarlar.php';

/** Ziyaretci "hala burada" sayilirken kullanilan sure. */
const ZIYARET_ONLINE_DAKIKA = 5;

/** Kayitlarin saklanma suresi. */
const ZIYARET_SAKLAMA_GUN = 180;

/** Bu cerez varsa o tarayicidan yapilan ziyaretler sayilmiyor (panelden). */
const ZIYARET_SAYMA_CEREZI = 'valentra_sayma';

/** Tek sayfa icin kabul edilen en uzun goruntulenme suresi (saniye). */
const ZIYARET_EN_UZUN_SURE = 3600;

/** Son yazilan satirin numarasi; sayfa alti betigi bunu kullaniyor. */
$GLOBALS['ziyaret_son_id'] = 0;

/**
 * Bu isteği ziyaret olarak kaydeder.
 *
 * Sayfanin cizimini bozmamali: her sey try icinde. Sayac bir siteyi
 * asla dusurmemeli — tablo yoksa ya da yazma basarisizsa sayfa
 * normal sekilde gosterilmeye devam eder.
 */
function ziyaret_kaydet(?int $haberId = null, string $baslik = ''): void
{
    if (!ziyaret_sayilmali()) {
        return;
    }

    $ua    = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $cihaz = ziyaret_ua_coz($ua);

    $temel = [
        'z' => ziyaretci_imzasi(),
        'i' => ziyaret_adresi(),
        'y' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? '/'), 0, 255, 'UTF-8'),
        'h' => $haberId,
        'b' => mb_substr($baslik, 0, 255, 'UTF-8'),
        'r' => ziyaret_yonlendiren(),
    ];

    try {
        try {
            $ifade = db()->prepare(
                'INSERT INTO ziyaretler
                        (zaman, ziyaretci, ip, yol, haber_id, baslik, yonlendiren,
                         cihaz, tarayici, isletim, kampanya)
                 VALUES (NOW(), :z, :i, :y, :h, :b, :r, :c, :t, :o, :k)'
            );
            $ifade->execute($temel + [
                'c' => $cihaz['cihaz'],
                't' => $cihaz['tarayici'],
                'o' => $cihaz['isletim'],
                'k' => ziyaret_kampanya(),
            ]);
        } catch (PDOException $e) {
            // Sema yukseltmesi henuz calismadiysa yeni sutunlar yok;
            // sayim yine de surmeli.
            $ifade = db()->prepare(
                'INSERT INTO ziyaretler (zaman, ziyaretci, ip, yol, haber_id, baslik, yonlendiren)
                 VALUES (NOW(), :z, :i, :y, :h, :b, :r)'
            );
            $ifade->execute($temel);
        }

        $GLOBALS['ziyaret_son_id'] = (int) db()->lastInsertId();

        ziyaret_eskileri_temizle();
    } catch (Throwable $e) {
        // Sessizce gec: sayac ziyaretcinin sayfasini bozmamali.
    }
}

/**
 * Bu istek sayılmalı mı?
 *
 * Elenenler ve sebepleri:
 *   - Yonetici oturumu: kendi ziyaretlerimizi saymak sayilari sisirir
 *     ve "bugun kac kisi geldi" sorusunu anlamsizlastirir.
 *   - "Bu cihazi sayma" cerezi: yonetici paneldeki oturumu kapaliyken de
 *     (telefondan bakarken) kendini saydirmasin.
 *   - Botlar: arama motoru taramalari insan ziyareti degil. Tam liste
 *     mumkun degil, ama bilinen imzalar trafigin buyuk kismini eler;
 *     kacanlari tarayici dogrulamasi (dosya basi) eliyor.
 *   - GET disi ve HEAD istekleri: sayfa goruntuleme sayilmaz.
 */
function ziyaret_sayilmali(): bool
{
    $yontem = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($yontem !== 'GET') {
        return false;
    }

    if (($_COOKIE[ZIYARET_SAYMA_CEREZI] ?? '') === '1') {
        return false;
    }

    /*
     * Oturum YALNIZCA panel cerezi varken aciliyor.
     *
     * Kosulsuz session_start() her ziyaretciye cerez birakir ve sunucuda
     * bos bir oturum dosyasi acar — sayac ugruna odenecek bir bedel
     * degil. Cerez yoksa zaten yonetici olamaz.
     */
    if (isset($_COOKIE['valentra_admin'])) {
        require_once __DIR__ . '/auth.php';
        oturum_baslat();

        if (oturum_acik()) {
            return false;
        }
    }

    return !ziyaret_bot_mu();
}

/**
 * Bilinen bot imzaları.
 *
 * 23.10.2026 denetimi: eski liste "bot" kelimesi gecmeyen yaygin
 * otomatik istemcileri kaciriyordu — WhatsApp baglanti onizlemesi
 * ("WhatsApp/2..."), Meta'nin yapay zeka tarayicisi
 * ("meta-externalagent"), ChatGPT ve Perplexity'nin kullanici adina
 * sayfa okumasi ("ChatGPT-User", "Perplexity-User"), Google'in
 * denetim araclari ("Google-InspectionTool", "GoogleOther"), Lighthouse,
 * programlama dili kutuphaneleri (okhttp, Guzzle, node-fetch...).
 * Tarayici kimliginde web adresi tasiyanlar ("+http://...") neredeyse
 * her zaman tarayicidir; gercek tarayicilar adres yazmaz.
 */
function ziyaret_bot_mu(?string $ua = null): bool
{
    $tarayici = strtolower($ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($tarayici === '' || strlen($tarayici) < 20) {
        // Bos ya da cok kisa tarayici kimligi neredeyse her zaman betik.
        return true;
    }

    if (str_contains($tarayici, 'http://') || str_contains($tarayici, 'https://') || str_contains($tarayici, '+http')) {
        return true;
    }

    foreach (['bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'facebookcatalog',
              'meta-externalagent', 'meta-externalfetcher', 'whatsapp', 'telegram', 'skypeuripreview',
              'preview', 'python', 'curl/', 'wget', 'headless', 'phantomjs', 'puppeteer',
              'playwright', 'selenium', 'lighthouse', 'pagespeed', 'gtmetrix',
              'scrapy', 'httpclient', 'axios', 'go-http', 'java/', 'okhttp', 'guzzle',
              'node-fetch', 'undici', 'libwww', 'ruby', 'php/', 'postman', 'insomnia',
              'monitor', 'uptime', 'pingdom', 'statuscake', 'ahrefs', 'semrush', 'mj12',
              'chatgpt-user', 'perplexity', 'claude-user', 'anthropic', 'cohere',
              'google-inspectiontool', 'googleother', 'google-read-aloud', 'feedfetcher',
              'mediapartners', 'adsbot', 'apis-google', 'google-site-verification',
              'bingpreview', 'yandex', 'baiduspider', 'bytespider', 'petalsearch',
              'feed', 'rss', 'validator', 'checker', 'scanner', 'archive.org'] as $imza) {
        if (str_contains($tarayici, $imza)) {
            return true;
        }
    }

    return false;
}

/**
 * Tarayıcı kimliğinden cihaz türü, tarayıcı ve işletim sistemi.
 *
 * Kaba bir siniflandirma; rapordaki "telefondan mi bilgisayardan mi
 * okunuyor" sorusuna yetiyor. Kimlik dizgesinin kendisi saklanmiyor.
 *
 * @return array{cihaz:string,tarayici:string,isletim:string}
 */
function ziyaret_ua_coz(string $ua): array
{
    $k = strtolower($ua);

    $cihaz = match (true) {
        str_contains($k, 'ipad') || str_contains($k, 'tablet')
            || (str_contains($k, 'android') && !str_contains($k, 'mobile')) => 'tablet',
        str_contains($k, 'mobile') || str_contains($k, 'iphone')
            || str_contains($k, 'android') => 'mobil',
        default => 'masaustu',
    };

    $isletim = match (true) {
        str_contains($k, 'iphone') || str_contains($k, 'ipad') || str_contains($k, 'ipod') => 'iOS',
        str_contains($k, 'android') => 'Android',
        str_contains($k, 'windows') => 'Windows',
        str_contains($k, 'cros') => 'ChromeOS',
        str_contains($k, 'mac os x') || str_contains($k, 'macintosh') => 'macOS',
        str_contains($k, 'linux') => 'Linux',
        default => 'Diğer',
    };

    // Sira onemli: Edge, Opera, Samsung ve Yandex kimliklerinde "chrome"
    // da geciyor; Chrome kimliginde de "safari".
    $tarayici = match (true) {
        str_contains($k, 'fban') || str_contains($k, 'fbav') => 'Facebook',
        str_contains($k, 'instagram') => 'Instagram',
        str_contains($k, 'edg/') || str_contains($k, 'edga/') || str_contains($k, 'edgios/') => 'Edge',
        str_contains($k, 'opr/') || str_contains($k, 'opera') => 'Opera',
        str_contains($k, 'samsungbrowser') => 'Samsung',
        str_contains($k, 'yabrowser') => 'Yandex',
        str_contains($k, 'firefox') || str_contains($k, 'fxios') => 'Firefox',
        str_contains($k, 'chrome') || str_contains($k, 'crios') => 'Chrome',
        str_contains($k, 'safari') => 'Safari',
        default => 'Diğer',
    };

    return ['cihaz' => $cihaz, 'tarayici' => $tarayici, 'isletim' => $isletim];
}

/**
 * Bağlantıdaki kampanya etiketi (utm_source / utm_campaign).
 *
 * Bultende, sosyal medyada ya da reklamda paylasilan baglantiya
 * ?utm_source=bulten eklenirse rapor o ziyaretleri ayri gosteriyor.
 */
function ziyaret_kampanya(): string
{
    $kaynak   = trim((string) ($_GET['utm_source'] ?? ''));
    $kampanya = trim((string) ($_GET['utm_campaign'] ?? ''));

    if ($kaynak === '' && $kampanya === '') {
        return '';
    }

    $metin = $kaynak . ($kampanya !== '' ? ' / ' . $kampanya : '');

    return mb_substr((string) preg_replace('/[^\p{L}\p{N} \/._-]/u', '', $metin), 0, 100, 'UTF-8');
}

/**
 * Ziyaretçinin takma kimliği.
 *
 * IP + tarayici kimligi + gizli tuz -> sha256'nin ilk 16 karakteri.
 * Geri cevrilemez; yalnizca "ayni tarayici mi" karsilastirmasi icin.
 */
function ziyaretci_imzasi(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    return substr(hash('sha256', ziyaret_tuzu() . '|' . $ip . '|' . $ua), 0, 16);
}

/**
 * Ziyaretçinin ağ adresi.
 *
 * Normalde REMOTE_ADDR dogru cevaptir ve istemci tarafindan
 * degistirilemez: TCP baglantisinin gercek karsi tarafidir.
 * X-Forwarded-For basligi ise istemcinin yazabildigi bir metindir,
 * yani tek basina guvenilmez — ona bakan bir kod, adresini istedigi
 * gibi gosteren ziyaretcilere kanar.
 *
 * Tek istisna, sitenin bir vekil sunucunun (CDN, yuk dengeleyici)
 * arkasinda olmasi: o durumda REMOTE_ADDR butun ziyaretciler icin ayni
 * ve ozel araliktan bir adres olur, gercek adres yalnizca baslikta
 * bulunur. Bu yuzden basliga SADECE REMOTE_ADDR ozel/yerel bir adresse
 * bakiliyor.
 *
 * Takma kimligi ureten ziyaretci_imzasi() bilerek degistirilmedi:
 * o REMOTE_ADDR kullanmaya devam ediyor, boylece eskiden beri sayilan
 * ziyaretciler ayni takma kimlikle gorunmeye devam ediyor.
 */
function ziyaret_adresi(): string
{
    $uzak = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    $dis = static fn (string $adres): bool => filter_var(
        $adres,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;

    if ($uzak !== '' && $dis($uzak)) {
        return mb_substr($uzak, 0, 45, 'UTF-8');
    }

    foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $aday) {
        $aday = trim($aday);

        if ($aday !== '' && $dis($aday)) {
            return mb_substr($aday, 0, 45, 'UTF-8');
        }
    }

    return mb_substr($uzak, 0, 45, 'UTF-8');
}

/**
 * Hash tuzu; yoksa bir kez üretilip saklanır.
 *
 * Tuz kodda sabit olsaydi ve kod bir sekilde disari ciksaydi, bilinen
 * bir IP listesiyle kayitlar kisilere geri baglanabilirdi. Rastgele ve
 * yalnizca veritabaninda durmasi bunu engelliyor.
 */
function ziyaret_tuzu(): string
{
    static $tuz = null;

    if ($tuz !== null) {
        return $tuz;
    }

    $tuz = ayar_oku('ziyaret_tuzu');

    if ($tuz === '') {
        $tuz = bin2hex(random_bytes(16));
        ayar_yaz('ziyaret_tuzu', $tuz);
    }

    return $tuz;
}

/** Kendi sitemizden gelen geçişin işareti (bkz. ziyaret_yonlendiren). */
const ZIYARET_IC_GECIS = '@';

/**
 * Ziyaretçinin geldiği dış adresin alan adı.
 *
 * Tam adres degil yalnizca alan adi saklaniyor: "google" mi "linkedin"
 * mi sorusuna cevap veriyor, fazlasi gereksiz.
 *
 * Site ici gecisler "@" ile isaretleniyor (23.10.2026). Onceden bos
 * birakiliyordu ve "dogrudan gelen" ile ayirt edilemiyordu: Google'dan
 * gelip uc haber okuyan bir ziyaretcinin iki sayfasi "dogrudan"
 * sayiliyor, rapor dogrudan trafigi oldugundan cok gosteriyordu.
 */
function ziyaret_yonlendiren(): string
{
    $ham = (string) ($_SERVER['HTTP_REFERER'] ?? '');

    if ($ham === '') {
        return '';
    }

    $sunucu = strtolower((string) parse_url($ham, PHP_URL_HOST));

    if ($sunucu === '') {
        return '';
    }

    // Port numarasi karsilastirmayi bozmasin (HTTP_HOST "alan:8080" olabilir).
    $kendi = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $sade  = static fn (string $a): string => preg_replace('/^www\./', '', $a) ?? $a;

    if ($sade($sunucu) === $sade($kendi)) {
        return ZIYARET_IC_GECIS;
    }

    return mb_substr($sade($sunucu), 0, 255, 'UTF-8');
}

/**
 * Saklama süresini aşan kayıtları siler.
 *
 * Her istekte DELETE calistirmak gereksiz yuk; bunun yerine yaklasik
 * yuzde birlik bir ihtimalle calisiyor. Tablo boylece sinirsiz
 * buyumuyor ama silme maliyeti de her ziyaretciye yansimiyor.
 */
function ziyaret_eskileri_temizle(): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }

    // Sinir sabit bir tam sayi; sorguya dogrudan yaziliyor cunku
    // INTERVAL ifadesinde yer tutucu kullanmak surucuden surucuye
    // degisen bir davranis.
    db()->exec(
        'DELETE FROM ziyaretler
          WHERE zaman < (NOW() - INTERVAL ' . (int) ZIYARET_SAKLAMA_GUN . ' DAY)
          LIMIT 5000'
    );
}

/* ------------------------------------------------------------------ *
 * Tarayici dogrulamasi (ikinci asama, bkz. dosya basi)
 * ------------------------------------------------------------------ */

/** Satır numarası için imza: betik yalnızca kendi satırını güncelleyebilsin. */
function ziyaret_isaret_imzasi(int $id): string
{
    return substr(hash_hmac('sha256', 'gorunum|' . $id, ziyaret_tuzu()), 0, 16);
}

/**
 * Sayfanın sonuna eklenen doğrulama betiği.
 *
 * Satir yazilmadiysa (bot, yonetici, "bu cihazi sayma") bos doner.
 *
 * Betik sayfa gercekten gorundugunde calisiyor: arka planda acilan
 * sekme gorunur olana, tarayicinin onceden hazirladigi (prerender)
 * sayfa acilana kadar bekliyor. Sayfadan cikarken goruntulenme suresini
 * (yalnizca sekme ondeyken gecen sure) ve en derin kaydirma oranini
 * gonderiyor. Hata verirse sessiz: sayfayi etkilemiyor.
 */
function ziyaret_betigi(): string
{
    $id = (int) ($GLOBALS['ziyaret_son_id'] ?? 0);

    if ($id <= 0) {
        return '';
    }

    $veri = json_encode([
        'id'  => $id,
        't'   => ziyaret_isaret_imzasi($id),
        'url' => '/api/gorunum.php',
    ], JSON_UNESCAPED_SLASHES);

    return <<<HTML
<script>
(function (v) {
    var anahtar = '', yeni = 0, sure = 0, bas = null, derin = 0, basladi = false;
    try {
        anahtar = localStorage.getItem('vz_k') || '';
        if (!/^[0-9a-f]{16}$/.test(anahtar)) {
            var b = new Uint8Array(8); crypto.getRandomValues(b);
            anahtar = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
            localStorage.setItem('vz_k', anahtar); yeni = 1;
        }
    } catch (e) { anahtar = ''; }
    function olc() {
        var h = document.documentElement, alt = (window.pageYOffset || h.scrollTop) + window.innerHeight;
        var oran = Math.min(100, Math.round(alt / Math.max(1, h.scrollHeight) * 100));
        if (oran > derin) { derin = oran; }
    }
    function gecen() { return sure + (bas !== null ? Date.now() - bas : 0); }
    function gonder(son) {
        try {
            olc();
            // FormData degil: api/.htaccess multipart istekleri reddediyor.
            var d = new URLSearchParams();
            d.append('id', v.id); d.append('t', v.t); d.append('k', anahtar); d.append('y', yeni);
            d.append('s', Math.round(gecen() / 1000)); d.append('o', derin);
            if (son && navigator.sendBeacon) { navigator.sendBeacon(v.url, d); }
            else { fetch(v.url, { method: 'POST', body: d, keepalive: true, credentials: 'omit' }).catch(function () {}); }
        } catch (e) {}
    }
    function goster() {
        if (document.visibilityState !== 'visible') { if (bas !== null) { sure += Date.now() - bas; bas = null; } gonder(true); return; }
        if (bas === null) { bas = Date.now(); }
        if (!basladi) { basladi = true; olc(); gonder(false); }
    }
    function kur() {
        document.addEventListener('visibilitychange', function () { if (basladi || document.visibilityState === 'visible') { goster(); } });
        window.addEventListener('pagehide', function () { if (basladi) { if (bas !== null) { sure += Date.now() - bas; bas = null; } gonder(true); } });
        window.addEventListener('scroll', olc, { passive: true });
        if (document.visibilityState === 'visible') { goster(); }
    }
    if (document.prerendering) { document.addEventListener('prerenderingchange', kur, { once: true }); } else { kur(); }
})({$veri});
</script>
HTML;
}

/**
 * Raporlarda sayılacak satırların koşulu.
 *
 * Dogrulama betigi ilk kez calistigi andan (olcum_baslangic) itibaren
 * yalnizca tarayicida gorundugu dogrulanan satirlar sayiliyor; o andan
 * onceki satirlar eskisi gibi. Betik hic calismadiysa (ornegin uc nokta
 * hata veriyorsa) kosul her seyi kabul ediyor: sayilar sifira inmesin.
 *
 * $ham true ise (panelde "ham sayim") dogrulama yok sayiliyor.
 */
function ziyaret_insan_kosulu(string $takma = '', bool $ham = false): string
{
    $baslangic = ziyaret_olcum_baslangici();

    if ($ham || $baslangic === '') {
        return '1=1';
    }

    $p = $takma !== '' ? $takma . '.' : '';

    return '(' . $p . 'insan = 1 OR ' . $p . 'zaman < ' . db()->quote($baslangic) . ')';
}

/** Ziyaretçi kimliği ifadesi: tarayıcı kimliği varsa o, yoksa takma imza. */
function ziyaret_kimlik_ifadesi(string $takma = ''): string
{
    $p = $takma !== '' ? $takma . '.' : '';

    return 'COALESCE(NULLIF(' . $p . "tarayici_id, ''), " . $p . 'ziyaretci)';
}

/** Doğrulamanın başladığı an ('' = henüz hiç doğrulama gelmedi). */
function ziyaret_olcum_baslangici(): string
{
    static $deger = null;

    if ($deger === null) {
        try {
            $deger = ayar_oku('olcum_baslangic');
        } catch (Throwable $e) {
            $deger = '';
        }
    }

    return $deger;
}

/**
 * Doğrulama sütunları var mı? (Şema yükseltmesi çalışmadıysa raporlar
 * eski sorgulara dönüyor.)
 */
function ziyaret_yeni_sema(): bool
{
    static $var = null;

    if ($var === null) {
        try {
            db()->query('SELECT insan, tarayici_id, sure, kaydirma, cihaz FROM ziyaretler LIMIT 0');
            $var = true;
        } catch (Throwable $e) {
            $var = false;
        }
    }

    return $var;
}

/* ------------------------------------------------------------------ *
 * Herkese acik sayfalarda kullanilan okunma bilgisi
 * ------------------------------------------------------------------ */

/**
 * Okuyucuya gösterilen "En Çok Okunanlar" (yan pencere).
 *
 * Site sahibinin istegi (22.10.2026): "en cok hangi sayfalara
 * bakildiysa onlari on plana alalim". Panel listesinden farki: yalnizca
 * YAYINDAKI haberler, sayim ayni okuyucunun tekrar acmalarini degil
 * FARKLI okuyuculari sayiyor (bir kisi yenile tusuna basarak listeyi
 * degistiremesin) ve donem kisa (son 2 gun): okuyucu gecen haftanin
 * degil bugunun cok okunanini gormek istiyor. Iki gunde yeterli haber
 * yoksa 7 gune genisliyor. Botlar ve onizlemeler sayilmiyor
 * (ziyaret_insan_kosulu).
 *
 * @return list<array{baslik:string,slug:string,okuyucu:int}>
 */
function haber_en_cok_okunanlar(int $adet = 6): array
{
    $yeni   = ziyaret_yeni_sema();
    $kimlik = $yeni ? ziyaret_kimlik_ifadesi('z') : 'z.ziyaretci';
    $insan  = $yeni ? ziyaret_insan_kosulu('z') : '1=1';
    $liste  = [];

    foreach ([2, 7] as $gun) {
        try {
            $ifade = db()->prepare(
                'SELECT h.baslik, h.slug, COUNT(DISTINCT ' . $kimlik . ') AS okuyucu
                   FROM ziyaretler z
                   JOIN haberler h ON h.id = z.haber_id
                  WHERE z.haber_id IS NOT NULL
                    AND h.durum = :d
                    AND z.zaman >= (NOW() - INTERVAL ' . $gun . ' DAY)
                    AND ' . $insan . '
                  GROUP BY h.id, h.baslik, h.slug
                  ORDER BY okuyucu DESC, MAX(z.zaman) DESC
                  LIMIT ' . max(1, min(20, $adet))
            );
            $ifade->execute(['d' => HABER_YAYINDA]);
            $liste = $ifade->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        if (count($liste) >= min(3, $adet)) {
            break;
        }
    }

    return array_map(static fn (array $s): array => [
        'baslik'  => (string) $s['baslik'],
        'slug'    => (string) $s['slug'],
        'okuyucu' => (int) $s['okuyucu'],
    ], $liste);
}
