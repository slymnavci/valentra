-- Valentra - vergi haberleri portali
-- MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Yoneticiler
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS yoneticiler (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kullanici_adi VARCHAR(190)  NOT NULL,
    ad            VARCHAR(120)  NOT NULL,
    parola_hash   VARCHAR(255)  NOT NULL,
    son_giris     DATETIME      NULL,
    olusturuldu   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_yonetici_kullanici (kullanici_adi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Haber kaynaklari (ajanin taradigi yerler)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kaynaklar (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad            VARCHAR(160)  NOT NULL,
    site_url      VARCHAR(500)  NOT NULL,
    besleme_url   VARCHAR(500)  NULL,
    liste_url     VARCHAR(500)  NULL,
    liste_secici  VARCHAR(200)  NULL,
    tur           ENUM('rss','resmi','web') NOT NULL DEFAULT 'rss',
    aktif         TINYINT(1)    NOT NULL DEFAULT 1,
    son_tarama    DATETIME      NULL,
    olusturuldu   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kaynak_besleme (besleme_url),
    KEY ix_kaynak_aktif (aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Konu gruplari (ust menu)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kategoriler (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad            VARCHAR(120)  NOT NULL,
    slug          VARCHAR(140)  NOT NULL,
    aciklama      VARCHAR(300)  NOT NULL DEFAULT '',
    ust_id        INT UNSIGNED  NULL,
    sira          SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    aktif         TINYINT(1)    NOT NULL DEFAULT 1,
    olusturuldu   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kategori_slug (slug),
    KEY ix_kategori_sira (aktif, sira),
    KEY ix_kategori_ust (ust_id, sira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Haberler
--
-- durum: taslak  -> ajan yazdi, onay bekliyor
--        yayinda -> yonetici onayladi, ana sayfada gorunur
--        reddedildi -> yayina alinmayacak
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS haberler (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    baslik          VARCHAR(300)  NOT NULL,
    slug            VARCHAR(320)  NOT NULL,
    ozet            VARCHAR(600)  NOT NULL DEFAULT '',
    icerik          MEDIUMTEXT    NOT NULL,
    gorsel_url      VARCHAR(500)  NULL,
    etiketler       VARCHAR(400)  NOT NULL DEFAULT '',

    durum           ENUM('taslak','yayinda','reddedildi') NOT NULL DEFAULT 'taslak',
    one_cikan       TINYINT(1)    NOT NULL DEFAULT 0,

    kategori_id     INT UNSIGNED  NULL,
    kaynak_id       INT UNSIGNED  NULL,
    kaynak_adi      VARCHAR(160)  NOT NULL DEFAULT '',
    kaynak_url      VARCHAR(500)  NOT NULL DEFAULT '',
    kaynak_parmak   CHAR(64)      NOT NULL,

    guven_skoru     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ajan_notu       VARCHAR(600)  NOT NULL DEFAULT '',

    onaylayan_id    INT UNSIGNED  NULL,
    onay_tarihi     DATETIME      NULL,
    yayin_tarihi    DATETIME      NULL,

    olusturuldu     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    guncellendi     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_haber_slug (slug),
    UNIQUE KEY uq_haber_parmak (kaynak_parmak),
    KEY ix_haber_durum_tarih (durum, yayin_tarihi),
    KEY ix_haber_bekleyen (durum, olusturuldu),
    KEY ix_haber_kategori (kategori_id, durum, yayin_tarihi),
    CONSTRAINT fk_haber_kategori FOREIGN KEY (kategori_id)  REFERENCES kategoriler (id) ON DELETE SET NULL,
    CONSTRAINT fk_haber_kaynak   FOREIGN KEY (kaynak_id)    REFERENCES kaynaklar (id)   ON DELETE SET NULL,
    CONSTRAINT fk_haber_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES yoneticiler (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Ajan erisim anahtarlari (ingest endpoint icin)
-- Anahtarin kendisi degil, SHA-256 ozeti saklanir.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ajan_anahtarlari (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad            VARCHAR(120)  NOT NULL,
    anahtar_hash  CHAR(64)      NOT NULL,
    aktif         TINYINT(1)    NOT NULL DEFAULT 1,
    son_kullanim  DATETIME      NULL,
    olusturuldu   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_anahtar_hash (anahtar_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Ajan calisma kayitlari
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ajan_kayitlari (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    baslangic     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bitis         DATETIME      NULL,
    durum         ENUM('calisiyor','tamam','hata') NOT NULL DEFAULT 'calisiyor',
    bulunan       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    eklenen       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    yinelenen     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    mesaj         VARCHAR(600)  NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_kayit_baslangic (baslangic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Varsayilan konu gruplari
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO kategoriler (ad, slug, aciklama, sira) VALUES
    ('Kurumlar Vergisi',        'kurumlar-vergisi',    'Kurumlar vergisi oranları, istisnalar ve beyan', 10),
    ('Gelir Vergisi',           'gelir-vergisi',       'Gelir vergisi tarifesi, beyanname ve istisnalar', 20),
    ('KDV',                     'kdv',                 'Katma değer vergisi, tevkifat ve iade', 30),
    ('Vergi Usul Kanunu',       'vergi-usul-kanunu',   'VUK, değerleme, amortisman ve ceza hükümleri', 40),
    ('ÖTV ve Diğer',            'otv-ve-diger',        'ÖTV, damga vergisi, harçlar ve diğer yükümlülükler', 50),
    ('e-Belge',                 'e-belge',             'e-Fatura, e-Arşiv, e-Defter ve dijital vergi', 60),
    ('TMS / TFRS',              'tms-tfrs',            'Türkiye Muhasebe ve Finansal Raporlama Standartları', 70),
    ('Denetim',                 'denetim',             'Bağımsız denetim ve vergi incelemeleri', 80),
    ('Teşvik ve Yapılandırma',  'tesvik-yapilandirma', 'Vergi affı, yapılandırma ve teşvik düzenlemeleri', 90),
    ('Genel',                   'genel',               'Diğer vergi gündemi', 999);

-- Daha once ASCII karakterlerle kurulmus adlari duzeltir; yeni kurulumda
-- degeri zaten dogru oldugu icin bir sey degistirmez.
UPDATE kategoriler SET ad = 'ÖTV ve Diğer',
       aciklama = 'ÖTV, damga vergisi, harçlar ve diğer yükümlülükler'
 WHERE slug = 'otv-ve-diger';

UPDATE kategoriler SET ad = 'Teşvik ve Yapılandırma',
       aciklama = 'Vergi affı, yapılandırma ve teşvik düzenlemeleri'
 WHERE slug = 'tesvik-yapilandirma';

UPDATE kategoriler SET ad = 'TMS / TFRS',
       aciklama = 'Türkiye Muhasebe ve Finansal Raporlama Standartları'
 WHERE slug = 'tms-tfrs';

UPDATE kategoriler SET aciklama = 'Kurumlar vergisi oranları, istisnalar ve beyan' WHERE slug = 'kurumlar-vergisi';
UPDATE kategoriler SET aciklama = 'Gelir vergisi tarifesi, beyanname ve istisnalar' WHERE slug = 'gelir-vergisi';
UPDATE kategoriler SET aciklama = 'Katma değer vergisi, tevkifat ve iade' WHERE slug = 'kdv';
UPDATE kategoriler SET aciklama = 'VUK, değerleme, amortisman ve ceza hükümleri' WHERE slug = 'vergi-usul-kanunu';
UPDATE kategoriler SET aciklama = 'e-Fatura, e-Arşiv, e-Defter ve dijital vergi' WHERE slug = 'e-belge';
UPDATE kategoriler SET aciklama = 'Bağımsız denetim ve vergi incelemeleri' WHERE slug = 'denetim';
UPDATE kategoriler SET aciklama = 'Diğer vergi gündemi' WHERE slug = 'genel';

-- ---------------------------------------------------------------------------
-- Menu ust basliklari ve alt gruplarin baglanmasi
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO kategoriler (ad, slug, aciklama, sira) VALUES
    ('Vergi Türleri',        'vergi-turleri',       'Kurumlar, gelir, KDV ve diğer vergiler', 10),
    ('Usul ve Mevzuat',      'usul-ve-mevzuat',     'VUK, tebliğler, teşvik ve dijital belge düzeni', 20),
    ('Muhasebe ve Denetim',  'muhasebe-denetim',    'Raporlama standartları ve denetim', 30);

UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-turleri') AS t), sira = 10 WHERE slug = 'kurumlar-vergisi';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-turleri') AS t), sira = 20 WHERE slug = 'gelir-vergisi';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-turleri') AS t), sira = 30 WHERE slug = 'kdv';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-turleri') AS t), sira = 40 WHERE slug = 'otv-ve-diger';

UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'usul-ve-mevzuat') AS t), sira = 10 WHERE slug = 'vergi-usul-kanunu';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'usul-ve-mevzuat') AS t), sira = 20 WHERE slug = 'tesvik-yapilandirma';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'usul-ve-mevzuat') AS t), sira = 30 WHERE slug = 'e-belge';

UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'muhasebe-denetim') AS t), sira = 10 WHERE slug = 'tms-tfrs';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'muhasebe-denetim') AS t), sira = 20 WHERE slug = 'denetim';

UPDATE kategoriler SET ust_id = NULL, sira = 40 WHERE slug = 'genel';

-- ---------------------------------------------------------------------------
-- Baslangic kaynak listesi
--
-- Besleme adresleri siteler tarafindan degistirilebilir. Panelde her
-- kaynagin yanindaki "Test et" dugmesi adresin calisip calismadigini
-- soyler; calismayanin adresini duzeltin ya da kaynagi kapatin.
-- Kapali kaynaklar taranmaz.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, tur, aktif) VALUES
    -- Resmi kaynaklar: vergi haberciliginde birincil kaynak
    ('Resmî Gazete',            'https://www.resmigazete.gov.tr',  'https://www.resmigazete.gov.tr/rss/Mukerrer.xml', 'resmi', 1),
    ('Gelir İdaresi Başkanlığı','https://www.gib.gov.tr',          'https://www.gib.gov.tr/rss.xml',                   'resmi', 1),
    ('Hazine ve Maliye Bakanlığı','https://www.hmb.gov.tr',        'https://www.hmb.gov.tr/rss',                       'resmi', 1),
    ('KGK',                     'https://www.kgk.gov.tr',          'https://www.kgk.gov.tr/rss',                       'resmi', 1),
    ('TÜRMOB',                  'https://www.turmob.org.tr',       'https://www.turmob.org.tr/rss',                    'resmi', 1),

    -- Mesleki yayinlar: vergi ve muhasebe odakli, en verimli kaynaklar
    ('Alomaliye',               'https://www.alomaliye.com',       'https://www.alomaliye.com/feed/',                  'rss', 1),
    ('Muhasebe News',           'https://www.muhasebenews.com',    'https://www.muhasebenews.com/feed/',               'rss', 1),
    ('Vergi Algı',              'https://www.vergialgi.net',       'https://www.vergialgi.net/feed',                   'rss', 1),
    ('MuhasebeTR',              'https://www.muhasebetr.com',      'https://www.muhasebetr.com/rss/',                  'rss', 1),
    ('İSMMMO',                  'https://www.ismmmo.org.tr',       'https://www.ismmmo.org.tr/rss',                    'rss', 1),

    -- Ekonomi basini: mevzuat disi gelismeleri yakalamak icin
    ('Ekonomim',                'https://www.ekonomim.com',        'https://www.ekonomim.com/rss',                     'rss', 1),
    ('Anadolu Ajansı Ekonomi',  'https://www.aa.com.tr',           'https://www.aa.com.tr/tr/rss/default?cat=ekonomi', 'rss', 1),
    ('Bloomberg HT',            'https://www.bloomberght.com',     'https://www.bloomberght.com/rss',                  'rss', 1),
    ('NTV Ekonomi',             'https://www.ntv.com.tr',          'https://www.ntv.com.tr/ekonomi.rss',               'rss', 1),
    ('Hürriyet Ekonomi',        'https://www.hurriyet.com.tr',     'https://www.hurriyet.com.tr/rss/ekonomi',          'rss', 1),
    ('Milliyet Ekonomi',        'https://www.milliyet.com.tr',     'https://www.milliyet.com.tr/rss/rssnew/ekonomirss.xml', 'rss', 1),
    ('Habertürk Ekonomi',       'https://www.haberturk.com',       'https://www.haberturk.com/rss/ekonomi.xml',        'rss', 1),
    ('Sabah Ekonomi',           'https://www.sabah.com.tr',        'https://www.sabah.com.tr/rss/ekonomi.xml',         'rss', 1),
    ('Patronlar Dünyası',       'https://www.patronlardunyasi.com','https://www.patronlardunyasi.com/rss',             'rss', 1);

-- ---------------------------------------------------------------------------
-- Resmi kaynaklar icin duyuru sayfasi adresleri (kazima)
--
-- Bu kurumlarin cogu RSS yayinlamiyor; ajan RSS bulamazsa bu sayfayi
-- kazir. Adres yanlissa panelden "Adresleri duzenle" ile degistirin,
-- "Kazimayi dene" ile sonucu gorun.
-- ---------------------------------------------------------------------------
UPDATE kaynaklar SET liste_url = 'https://www.gib.gov.tr/duyurular'
 WHERE ad = 'Gelir İdaresi Başkanlığı' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.hmb.gov.tr/duyurular'
 WHERE ad = 'Hazine ve Maliye Bakanlığı' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.kgk.gov.tr/duyurular'
 WHERE ad = 'KGK' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.turmob.org.tr/haberler'
 WHERE ad = 'TÜRMOB' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.ismmmo.org.tr/Duyurular'
 WHERE ad = 'İSMMMO' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.resmigazete.gov.tr/'
 WHERE ad = 'Resmî Gazete' AND liste_url IS NULL;
