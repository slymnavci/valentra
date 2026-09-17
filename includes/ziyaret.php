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
 * KISISEL VERI: ham IP adresi HICBIR YERDE saklanmiyor. IP ve tarayici
 * imzasi, veritabaninda tutulan rastgele bir tuzla birlikte
 * hashleniyor ve yalnizca ilk 16 karakteri yaziliyor. Bu, "ayni
 * ziyaretci mi" sorusunu cevaplamaya yetiyor ama kimligi geri
 * getirmeye yetmiyor. Tuz degistirilirse eski kayitlar hicbir kisiye
 * baglanamaz hale gelir.
 */

require_once __DIR__ . '/ayarlar.php';

/** Ziyaretci "hala burada" sayilirken kullanilan sure. */
const ZIYARET_ONLINE_DAKIKA = 5;

/** Kayitlarin saklanma suresi. */
const ZIYARET_SAKLAMA_GUN = 180;

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

    try {
        $ifade = db()->prepare(
            'INSERT INTO ziyaretler (zaman, ziyaretci, yol, haber_id, baslik, yonlendiren)
             VALUES (NOW(), :z, :y, :h, :b, :r)'
        );

        $ifade->execute([
            'z' => ziyaretci_imzasi(),
            'y' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? '/'), 0, 255, 'UTF-8'),
            'h' => $haberId,
            'b' => mb_substr($baslik, 0, 255, 'UTF-8'),
            'r' => ziyaret_yonlendiren(),
        ]);

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
 *   - Botlar: arama motoru taramalari insan ziyareti degil. Tam liste
 *     mumkun degil, ama bilinen imzalar trafigin buyuk kismini eler.
 *   - GET disi ve HEAD istekleri: sayfa goruntuleme sayilmaz.
 */
function ziyaret_sayilmali(): bool
{
    $yontem = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($yontem !== 'GET') {
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

/** Bilinen bot imzalari. */
function ziyaret_bot_mu(): bool
{
    $tarayici = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($tarayici === '') {
        // Tarayici kimligi olmayan istekler neredeyse her zaman betik.
        return true;
    }

    foreach (['bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit',
              'preview', 'python-requests', 'curl/', 'wget', 'headless',
              'scrapy', 'httpclient', 'axios', 'go-http', 'java/',
              'monitor', 'uptime', 'pingdom', 'ahrefs', 'semrush'] as $imza) {
        if (str_contains($tarayici, $imza)) {
            return true;
        }
    }

    return false;
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

/**
 * Ziyaretçinin geldiği dış adresin alan adı.
 *
 * Tam adres degil yalnizca alan adi saklaniyor: "google" mi "linkedin"
 * mi sorusuna cevap veriyor, fazlasi gereksiz. Kendi sayfalarimizdan
 * gelen gecisler bos birakiliyor, yoksa liste kendi adimizla dolardi.
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

    $kendi = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $sade  = static fn (string $a): string => preg_replace('/^www\./', '', $a) ?? $a;

    if ($sade($sunucu) === $sade($kendi)) {
        return '';
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
 * Raporlama
 *
 * Asagidaki sorgular yalnizca panelden cagriliyor. "Ziyaretci" her
 * yerde TEKIL imza sayisi, "goruntulenme" ise satir sayisi: ikisini
 * ayirmak onemli, cunku tek kisi on sayfa gezdiginde bunlar cok farkli
 * seyler anlatiyor.
 * ------------------------------------------------------------------ */

/**
 * Üst satırdaki özet sayılar.
 *
 * @return array<string,int>
 */
function ziyaret_ozeti(): array
{
    $tek = static function (string $sorgu): int {
        try {
            return (int) db()->query($sorgu)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };

    $online = (int) ZIYARET_ONLINE_DAKIKA;

    return [
        'online' => $tek(
            'SELECT COUNT(DISTINCT ziyaretci) FROM ziyaretler
              WHERE zaman >= (NOW() - INTERVAL ' . $online . ' MINUTE)'
        ),
        'bugun_ziyaretci' => $tek(
            'SELECT COUNT(DISTINCT ziyaretci) FROM ziyaretler
              WHERE DATE(zaman) = CURDATE()'
        ),
        'bugun_goruntulenme' => $tek(
            'SELECT COUNT(*) FROM ziyaretler WHERE DATE(zaman) = CURDATE()'
        ),
        'dun_ziyaretci' => $tek(
            'SELECT COUNT(DISTINCT ziyaretci) FROM ziyaretler
              WHERE DATE(zaman) = (CURDATE() - INTERVAL 1 DAY)'
        ),
        'hafta_ziyaretci' => $tek(
            'SELECT COUNT(DISTINCT ziyaretci) FROM ziyaretler
              WHERE zaman >= (CURDATE() - INTERVAL 6 DAY)'
        ),
        'hafta_goruntulenme' => $tek(
            'SELECT COUNT(*) FROM ziyaretler
              WHERE zaman >= (CURDATE() - INTERVAL 6 DAY)'
        ),
        'ay_ziyaretci' => $tek(
            'SELECT COUNT(DISTINCT ziyaretci) FROM ziyaretler
              WHERE zaman >= (CURDATE() - INTERVAL 29 DAY)'
        ),
        'ay_goruntulenme' => $tek(
            'SELECT COUNT(*) FROM ziyaretler
              WHERE zaman >= (CURDATE() - INTERVAL 29 DAY)'
        ),
        'toplam_goruntulenme' => $tek('SELECT COUNT(*) FROM ziyaretler'),
    ];
}

/**
 * Son N günün günlük dökümü; kayıt olmayan günler de sıfırla döner.
 *
 * Bos gunleri SQL uretmiyor (tarih tablosu yok); PHP tarafinda takvim
 * kuruluyor. Aksi halde grafikte gunler kayardi ve "dun hic ziyaret
 * yok" ile "dun diye bir gun yok" ayirt edilemezdi.
 *
 * @return list<array{tarih:string,ziyaretci:int,goruntulenme:int}>
 */
function ziyaret_gunluk(int $gun = 14): array
{
    $gun = max(1, min(90, $gun));

    try {
        $satirlar = db()->query(
            'SELECT DATE(zaman) AS tarih,
                    COUNT(DISTINCT ziyaretci) AS ziyaretci,
                    COUNT(*) AS goruntulenme
               FROM ziyaretler
              WHERE zaman >= (CURDATE() - INTERVAL ' . ($gun - 1) . ' DAY)
              GROUP BY DATE(zaman)'
        )->fetchAll();
    } catch (Throwable $e) {
        $satirlar = [];
    }

    $sayilar = [];

    foreach ($satirlar as $satir) {
        $sayilar[(string) $satir['tarih']] = [
            'ziyaretci'    => (int) $satir['ziyaretci'],
            'goruntulenme' => (int) $satir['goruntulenme'],
        ];
    }

    $sonuc = [];

    for ($i = $gun - 1; $i >= 0; $i--) {
        $tarih = date('Y-m-d', strtotime("-{$i} day"));

        $sonuc[] = [
            'tarih'        => $tarih,
            'ziyaretci'    => $sayilar[$tarih]['ziyaretci'] ?? 0,
            'goruntulenme' => $sayilar[$tarih]['goruntulenme'] ?? 0,
        ];
    }

    return $sonuc;
}

/**
 * En çok okunan haberler.
 *
 * Basligi haberler tablosundan aliyoruz; kayit anindaki basligi degil.
 * Haber sonradan duzenlenirse listede guncel hali gorunsun diye.
 *
 * @return list<array<string,mixed>>
 */
function ziyaret_populer_haberler(int $gun = 7, int $adet = 10): array
{
    try {
        $ifade = db()->prepare(
            'SELECT z.haber_id,
                    COALESCE(h.baslik, z.baslik) AS baslik,
                    h.slug,
                    COUNT(*) AS okunma,
                    COUNT(DISTINCT z.ziyaretci) AS ziyaretci
               FROM ziyaretler z
          LEFT JOIN haberler h ON h.id = z.haber_id
              WHERE z.haber_id IS NOT NULL
                AND z.zaman >= (CURDATE() - INTERVAL ' . max(0, $gun - 1) . ' DAY)
              GROUP BY z.haber_id, baslik, h.slug
              ORDER BY okunma DESC
              LIMIT ' . max(1, min(50, $adet))
        );
        $ifade->execute();

        return $ifade->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * En çok görüntülenen sayfalar (haber dışı sayfalar dahil).
 *
 * @return list<array<string,mixed>>
 */
function ziyaret_populer_sayfalar(int $gun = 7, int $adet = 10): array
{
    try {
        return db()->query(
            'SELECT yol, COUNT(*) AS goruntulenme,
                    COUNT(DISTINCT ziyaretci) AS ziyaretci
               FROM ziyaretler
              WHERE zaman >= (CURDATE() - INTERVAL ' . max(0, $gun - 1) . ' DAY)
              GROUP BY yol
              ORDER BY goruntulenme DESC
              LIMIT ' . max(1, min(50, $adet))
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ziyaretçiler nereden geldi.
 *
 * Yonlendireni bos olanlar "dogrudan" sayiliyor: adresi elle yazan,
 * yer imine tiklayan ya da yonlendirenini gizleyen ziyaretciler.
 *
 * @return list<array<string,mixed>>
 */
function ziyaret_yonlendirenler(int $gun = 30, int $adet = 10): array
{
    try {
        return db()->query(
            'SELECT CASE WHEN yonlendiren = "" THEN "(doğrudan)" ELSE yonlendiren END AS kaynak,
                    COUNT(*) AS goruntulenme,
                    COUNT(DISTINCT ziyaretci) AS ziyaretci
               FROM ziyaretler
              WHERE zaman >= (CURDATE() - INTERVAL ' . max(0, $gun - 1) . ' DAY)
              GROUP BY kaynak
              ORDER BY goruntulenme DESC
              LIMIT ' . max(1, min(50, $adet))
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Şu anda sitede olanların EN SON baktığı sayfa; kişi başına bir satır.
 *
 * Kisi basina tek satir olmasi onemli: ilk surumde her ziyaretcinin
 * gezdigi her sayfa ayri satirdi ve liste "3 kisi online" yazarken bes
 * satir gosteriyordu.
 *
 * Son satir MAX(id) ile bulunuyor. id otomatik artan oldugu icin
 * zamanla ayni siradadir; MAX(zaman) ise ayni saniyeye denk gelen iki
 * sayfada hangisinin sonuncu oldugunu soylemez.
 *
 * @return list<array<string,mixed>>
 */
function ziyaret_online_sayfalar(int $adet = 10): array
{
    $dakika = (int) ZIYARET_ONLINE_DAKIKA;

    try {
        return db()->query(
            'SELECT z.yol, z.baslik, z.zaman AS son
               FROM ziyaretler z
               JOIN (SELECT ziyaretci, MAX(id) AS son_id
                       FROM ziyaretler
                      WHERE zaman >= (NOW() - INTERVAL ' . $dakika . ' MINUTE)
                      GROUP BY ziyaretci) sonlar ON sonlar.son_id = z.id
              ORDER BY z.zaman DESC, z.id DESC
              LIMIT ' . max(1, min(50, $adet))
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
