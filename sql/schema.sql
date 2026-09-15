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
    tur           ENUM('rss','resmi','web') NOT NULL DEFAULT 'rss',
    aktif         TINYINT(1)    NOT NULL DEFAULT 1,
    son_tarama    DATETIME      NULL,
    olusturuldu   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
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
    sira          SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    aktif         TINYINT(1)    NOT NULL DEFAULT 1,
    olusturuldu   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kategori_slug (slug),
    KEY ix_kategori_sira (aktif, sira)
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
