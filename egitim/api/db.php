<?php
/**
 * Pratik sistemi veritabanı bağlantısı ve şema kurulumu.
 *
 * Tüm public/api/*.php uç noktaları bu dosyayı include edip ppBaglan()
 * çağırır. Bağlantı kurulduğunda gerekli tablolar yoksa otomatik
 * oluşturulur (CREATE TABLE IF NOT EXISTS) — elle SQL çalıştırmaya
 * gerek yoktur.
 */

require_once __DIR__ . '/config.php';

function ppBaglan(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Valentra icinde: config.php Valentra'nin baglantisini verdi.
    if (isset($GLOBALS['egitim_pdo']) && $GLOBALS['egitim_pdo'] instanceof PDO) {
        $pdo = $GLOBALS['egitim_pdo'];
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        ppSemaKur($pdo);
        return $pdo;
    }

    $dsn = defined('DB_DSN')
        ? DB_DSN
        : sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);

    $pdo = new PDO($dsn, defined('DB_USER') ? DB_USER : null, defined('DB_PASS') ? DB_PASS : null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    ppSemaKur($pdo);
    return $pdo;
}

/**
 * Var olan bir tabloya, henüz yoksa bir sütun ekler (CREATE TABLE IF NOT
 * EXISTS yeni sütunları geriye dönük eklemediği için). MySQL ve SQLite'ta
 * idempotent şekilde çalışır — elle migrasyon çalıştırmaya gerek kalmaz.
 */
function ppSutunEkle(PDO $pdo, string $tablo, string $sutun, string $tanim): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $q = $pdo->prepare("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $q->execute([$tablo, $sutun]);
        if ((int)$q->fetch()['n'] > 0) return;
    } else {
        $var = false;
        foreach ($pdo->query("PRAGMA table_info($tablo)")->fetchAll() as $c) {
            if ($c['name'] === $sutun) { $var = true; break; }
        }
        if ($var) return;
    }
    $pdo->exec("ALTER TABLE $tablo ADD COLUMN $sutun $tanim");
}

function ppSemaKur(PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $idSutunu = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $jsonTip = $mysql ? 'JSON' : 'TEXT';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS soru_setleri (
        id $idSutunu,
        ders_id VARCHAR(120) NOT NULL,
        konu_id VARCHAR(120) NOT NULL,
        ad VARCHAR(200) NOT NULL,
        olusturma_tarihi DATETIME NOT NULL
    )$motor");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pratik_sorular (
        id $idSutunu,
        set_id INT NOT NULL,
        tip VARCHAR(20) NOT NULL,
        soru TEXT NOT NULL,
        secenekler $jsonTip NULL,
        dogru_cevap TEXT NOT NULL,
        aciklama TEXT NULL,
        sira INT NOT NULL DEFAULT 0
    )$motor");

    $pdo->exec("CREATE TABLE IF NOT EXISTS denemeler (
        id $idSutunu,
        set_id INT NOT NULL,
        kullanici_adi VARCHAR(120) NOT NULL,
        tarih DATETIME NOT NULL,
        dogru INT NOT NULL,
        toplam INT NOT NULL,
        puan INT NOT NULL,
        detay $jsonTip NULL
    )$motor");

    /* --- Kullanıcı ilerlemesi (localStorage'dan taşınan kişisel veri) --- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_ilerleme (
        id $idSutunu,
        kullanici_adi VARCHAR(120) NOT NULL,
        materyal_id VARCHAR(120) NOT NULL,
        sayfa_no INT NOT NULL,
        tarih DATETIME NOT NULL
    )$motor");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_konu_sinav (
        id $idSutunu,
        kullanici_adi VARCHAR(120) NOT NULL,
        konu_id VARCHAR(120) NOT NULL,
        istemci_id VARCHAR(60) NULL,
        tarih DATETIME NOT NULL,
        dogru INT NOT NULL,
        toplam INT NOT NULL,
        puan INT NOT NULL,
        gecti TINYINT(1) NOT NULL
    )$motor");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_ayar (
        kullanici_adi VARCHAR(120) NOT NULL PRIMARY KEY,
        veri $jsonTip NOT NULL,
        guncelleme_tarihi DATETIME NOT NULL
    )$motor");

    /* AI API anahtarları — YALNIZCA burada saklanır, istemciye asla geri
       okunmaz (bkz. sir_durum.php: yalnızca tanımlı/model döner). Tüm AI
       istekleri ai_sohbet.php üzerinden sunucu tarafında yapılır. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_sir (
        kullanici_adi VARCHAR(120) NOT NULL,
        saglayici VARCHAR(20) NOT NULL,
        anahtar TEXT NOT NULL,
        model VARCHAR(120) NULL,
        guncelleme_tarihi DATETIME NOT NULL,
        PRIMARY KEY (kullanici_adi, saglayici)
    )$motor");

    /* Üyelik hesapları — artık sunucuda saklanır (eskiden yalnızca
       tarayıcı localStorage'ındaydı, bu yüzden bir cihazda eklenen
       üye başka bir cihazdan giriş yapamıyordu). Şifreler yalnızca
       bcrypt hash'i olarak tutulur, istemciye asla geri dönmez. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_hesap (
        kullanici_adi VARCHAR(60) NOT NULL PRIMARY KEY,
        ad VARCHAR(120) NOT NULL,
        eposta VARCHAR(160) NULL,
        sifre_hash VARCHAR(255) NOT NULL,
        rol VARCHAR(20) NOT NULL DEFAULT 'uye',
        kayit_tarihi DATETIME NOT NULL,
        son_giris DATETIME NULL
    )$motor");
    /* menu_izin: üyenin erişebileceği menü kimliklerinin JSON dizisi
       (bkz. yetki.php: ppMenuDogrula). NULL = kısıtlama yok (bu özellik
       eklenmeden önce oluşturulmuş hesapların mevcut erişimi aynen
       korunur); bir dizi (boş olsa da) = üye yalnızca o dizideki
       menülere erişebilir. Yönetici rolü bu kontrolü her zaman atlar. */
    ppSutunEkle($pdo, 'kullanici_hesap', 'menu_izin', 'TEXT NULL');

    /* Oturum jetonları — başarılı giriş sonrası üretilir (bkz. giris_dogrula.php,
       yetki.php: ppOturumDogrula). API istekleri artık kullanici_adi alanına
       değil, buradaki geçerli bir jetona bağlı kimliğe göre yetkilendirilir.
       Jeton yalnızca hash'lenmiş hâliyle saklanır. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_oturum (
        token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
        kullanici_adi VARCHAR(60) NOT NULL,
        olusturma_tarihi DATETIME NOT NULL,
        sona_erme DATETIME NOT NULL
    )$motor");

    /* Valentra'da VARSAYILAN YONETICI OLUSTURULMUYOR.
       Eski sitede hesap tablosu bossa sabit sifreli bir 'savci' hesabi
       aciliyordu. Valentra deposu herkese acik: o sifre kodda durursa
       herkes okuyabilirdi. Hesaplar yalnizca aktarimla (Valentra paneli ->
       Egitim) ya da mevcut yoneticinin Yonetim -> Uyeler ekranindan gelir. */

    /* Forum — yönetici kategori (başlık) açar, üyeler bu kategori altında
       konu açıp birbirlerinin konularına cevap yazar. Okuma herkese açık
       (dersler.json gibi); konu/mesaj eklemek geçerli bir kullanici_hesap
       gerektirir (bkz. forum_yardimci.php: ppUyeDogrula). */
    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_kategori (
        id $idSutunu,
        ad VARCHAR(200) NOT NULL,
        aciklama VARCHAR(500) NULL,
        sira INT NOT NULL DEFAULT 0,
        olusturma_tarihi DATETIME NOT NULL
    )$motor");

    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_konu (
        id $idSutunu,
        kategori_id INT NOT NULL,
        baslik VARCHAR(300) NOT NULL,
        kullanici_adi VARCHAR(60) NOT NULL,
        olusturma_tarihi DATETIME NOT NULL,
        son_aktivite DATETIME NOT NULL
    )$motor");

    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_mesaj (
        id $idSutunu,
        konu_id INT NOT NULL,
        kullanici_adi VARCHAR(60) NOT NULL,
        icerik TEXT NOT NULL,
        olusturma_tarihi DATETIME NOT NULL
    )$motor");

    if (!$mysql) {
        // SQLite'ta CREATE INDEX IF NOT EXISTS de desteklenir; MySQL 8'de
        // IF NOT EXISTS index için garanti değil, o yüzden MySQL'de ayrı ele alınır.
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ps_set ON pratik_sorular(set_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dn_set ON denemeler(set_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ki_kullanici ON kullanici_ilerleme(kullanici_adi, materyal_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ks_kullanici ON kullanici_konu_sinav(kullanici_adi, konu_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fk_kategori ON forum_konu(kategori_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fm_konu ON forum_mesaj(konu_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_kotur_kullanici ON kullanici_oturum(kullanici_adi)");
        return;
    }

    ppMysqlIndeksKur($pdo, 'pratik_sorular', 'idx_ps_set', 'set_id');
    ppMysqlIndeksKur($pdo, 'denemeler', 'idx_dn_set', 'set_id');
    ppMysqlIndeksKur($pdo, 'kullanici_ilerleme', 'idx_ki_kullanici', 'kullanici_adi, materyal_id');
    ppMysqlIndeksKur($pdo, 'kullanici_konu_sinav', 'idx_ks_kullanici', 'kullanici_adi, konu_id');
    ppMysqlIndeksKur($pdo, 'forum_konu', 'idx_fk_kategori', 'kategori_id');
    ppMysqlIndeksKur($pdo, 'forum_mesaj', 'idx_fm_konu', 'konu_id');
    ppMysqlIndeksKur($pdo, 'kullanici_oturum', 'idx_kotur_kullanici', 'kullanici_adi');
}

/** MySQL'de indeks zaten varsa hataya düşmeden, yoksa oluşturur. */
function ppMysqlIndeksKur(PDO $pdo, string $tablo, string $indeksAdi, string $sutun): void {
    $sonuc = $pdo->prepare(
        "SELECT COUNT(*) AS n FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
    );
    $sonuc->execute([$tablo, $indeksAdi]);
    if ((int)$sonuc->fetch()['n'] === 0) {
        $pdo->exec("CREATE INDEX $indeksAdi ON $tablo($sutun)");
    }
}

/** İstek gövdesini JSON olarak okur, hatalıysa 400 döner ve sonlandırır. */
/**
 * Herkese açık uçlarda (giriş, kayıt) kaba kuvvet ve toplu kayıt
 * koruması: aynı IP'den belirli sürede en fazla $limit deneme.
 * Aşılırsa 429 döner ve süreci sonlandırır. $kaydet=false yalnızca
 * sınırı denetler (başarılı girişler sayılmasın diye).
 */
function ppDenemeSiniri(PDO $pdo, string $tur, int $limit, int $dakika, bool $kaydet = true): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS giris_deneme (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tur VARCHAR(20) NOT NULL,
        ip VARCHAR(64) NOT NULL,
        zaman DATETIME NOT NULL,
        KEY ix_deneme (tur, ip, zaman)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $sinir = gmdate('Y-m-d H:i:s', time() - $dakika * 60);

    $pdo->prepare('DELETE FROM giris_deneme WHERE zaman < ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);

    $q = $pdo->prepare('SELECT COUNT(*) n FROM giris_deneme WHERE tur = ? AND ip = ? AND zaman > ?');
    $q->execute([$tur, $ip, $sinir]);

    if ((int)$q->fetch()['n'] >= $limit) {
        ppJsonYanit(['ok' => false, 'hata' => 'Çok fazla deneme yapıldı. Lütfen ' . $dakika . ' dakika sonra tekrar deneyin.'], 429);
    }

    if ($kaydet) {
        $pdo->prepare('INSERT INTO giris_deneme (tur, ip, zaman) VALUES (?, ?, ?)')->execute([$tur, $ip, gmdate('Y-m-d H:i:s')]);
    }
}

/**
 * Hesap için oturum jetonu üretir ve istemciye dönecek kullanıcı
 * bilgisini hazırlar (giriş ve kayıt aynı yanıtı verir).
 */
function ppOturumAc(PDO $pdo, array $hesap): array {
    $simdi = gmdate('Y-m-d H:i:s');
    $pdo->prepare('UPDATE kullanici_hesap SET son_giris = ? WHERE kullanici_adi = ?')->execute([$simdi, $hesap['kullanici_adi']]);
    $pdo->prepare('DELETE FROM kullanici_oturum WHERE sona_erme < ?')->execute([$simdi]);

    $token = bin2hex(random_bytes(32));
    $sonaErme = gmdate('Y-m-d H:i:s', time() + 30 * 24 * 60 * 60);
    $pdo->prepare('INSERT INTO kullanici_oturum (token_hash, kullanici_adi, olusturma_tarihi, sona_erme) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $hesap['kullanici_adi'], $simdi, $sonaErme]);

    $menuIzin = null;
    if (($hesap['menu_izin'] ?? null) !== null && $hesap['menu_izin'] !== '') {
        $d = json_decode((string)$hesap['menu_izin'], true);
        $menuIzin = is_array($d) ? $d : null;
    }

    return [
        'kullaniciAdi' => $hesap['kullanici_adi'],
        'ad' => $hesap['ad'],
        'eposta' => $hesap['eposta'],
        'rol' => $hesap['rol'],
        'kayitTarihi' => $hesap['kayit_tarihi'],
        'sonGiris' => $simdi,
        'token' => $token,
        'menuIzin' => $menuIzin,
    ];
}

function ppGovdeOku(): array {
    $ham = file_get_contents('php://input');
    $veri = json_decode($ham, true);
    if (!is_array($veri)) {
        ppJsonYanit(['ok' => false, 'hata' => 'Geçersiz veya boş JSON gövde.'], 400);
    }
    return $veri;
}

function ppJsonYanit($veri, int $kod = 200): void {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}
