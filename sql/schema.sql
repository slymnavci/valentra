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
    UNIQUE KEY uq_kaynak_besleme (besleme_url(190)),
    -- Ad uzerinde de benzersizlik: RSS'i olmayan kaynaklarin besleme_url
    -- alani NULL kalir, MySQL ise birden fazla NULL'a izin verir. Ad
    -- kisiti olmasa sema her calistiginda bu kaynaklar kopyalanirdi.
    UNIQUE KEY uq_kaynak_ad (ad),
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
    iframe_url      VARCHAR(1000) NULL,
    etiketler       VARCHAR(400)  NOT NULL DEFAULT '',

    durum           ENUM('taslak','yayinda','reddedildi') NOT NULL DEFAULT 'taslak',
    one_cikan       TINYINT(1)    NOT NULL DEFAULT 0,

    kategori_id     INT UNSIGNED  NULL,
    kaynak_id       INT UNSIGNED  NULL,
    kaynak_adi      VARCHAR(160)  NOT NULL DEFAULT '',
    kaynak_url      VARCHAR(500)  NOT NULL DEFAULT '',
    kaynak_parmak   CHAR(64)      NOT NULL,

    -- Kopya denetimi bu iki alanla yapilir. kaynak_parmak ham adresi
    -- oldugu gibi hashliyordu; "?utm_source=..." eklenmis ya da sonuna
    -- egik cizgi gelmis AYNI haber farkli gorunuyor ve her calismada
    -- yeniden toplaniyordu. url_parmak sadelestirilmis adresten,
    -- baslik_parmak ise Turkce katlanmis ve noktalamasi atilmis
    -- baslikten hesaplanir; ikincisi ayni haberi baska bir kaynaktan
    -- ikinci kez almayi da onler.
    url_parmak      CHAR(64)      NULL,
    baslik_parmak   CHAR(64)      NULL,

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
    KEY ix_haber_url_parmak (url_parmak),
    KEY ix_haber_baslik_parmak (baslik_parmak),
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
-- Genel ayarlar (anahtar/deger)
--
-- Panelden girilen, kodda sabit tutulmamasi gereken degerler burada.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ayarlar (
    anahtar     VARCHAR(80)  NOT NULL,
    deger       TEXT         NOT NULL,
    guncellendi DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (anahtar)
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
    ('Vergi Kanunları',      'vergi-kanunlari',     'Kurumlar, gelir, KDV, VUK, ÖTV ve diğer vergi düzenlemeleri', 10),
    ('Muhasebe ve Denetim',  'muhasebe-denetim',    'Raporlama standartları ve denetim', 20);

UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-kanunlari') AS t), sira = 10 WHERE slug = 'kurumlar-vergisi';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-kanunlari') AS t), sira = 20 WHERE slug = 'gelir-vergisi';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-kanunlari') AS t), sira = 30 WHERE slug = 'kdv';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-kanunlari') AS t), sira = 40 WHERE slug = 'otv-ve-diger';

UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-kanunlari') AS t), sira = 50 WHERE slug = 'vergi-usul-kanunu';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-kanunlari') AS t), sira = 60 WHERE slug = 'e-belge';
UPDATE kategoriler SET ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'vergi-kanunlari') AS t), sira = 70 WHERE slug = 'tesvik-yapilandirma';

UPDATE kategoriler SET ad = 'TMS/TFRS', aktif = 1, ust_id = NULL, sira = 40 WHERE slug = 'tms-tfrs';
UPDATE kategoriler SET aktif = 1, ust_id = (SELECT id FROM (SELECT id FROM kategoriler WHERE slug = 'muhasebe-denetim') AS t), sira = 20 WHERE slug = 'denetim';

UPDATE kategoriler SET ad = 'Diğer', aktif = 1, ust_id = NULL, sira = 50 WHERE slug = 'genel';

-- ---------------------------------------------------------------------------
-- Eski kurulumlarin menu yapisini tasima
--
-- Menu su hale getirildi:
--   Ana Sayfa | Vergi Kanunlari | Muhasebe ve Denetim |
--   Ekonomik Gundem | TMS/TFRS | Diger
--
-- Yeni kurulumda yukaridaki tohum zaten bu yapiyi kuruyor. Bu blok
-- yalnizca daha once "Vergi Turleri" ve "Usul ve Mevzuat" basliklariyla
-- kurulmus veritabanlarini tasiyor.
--
-- Slug'i UPDATE ile yeniden adlandirmiyoruz: tohum her calistiginda
-- eski slug'i geri koyar, sonraki UPDATE de benzersizlik kisitina
-- carpar. (Bu hata bir kez yapildi; sema ikinci calistirmada
-- "Duplicate entry 'vergi-kanunlari'" ile dusuyordu.) Bunun yerine
-- eski basliklar pasife aliniyor, cocuklari zaten yukarida yeni
-- basliga baglandi.
--
-- Silinmiyorlar cunku eski haberlerin kategori_id'si bunlara isaret
-- ediyor olabilir; silmek o haberleri gruptan koparirdi.
-- ---------------------------------------------------------------------------
UPDATE kategoriler SET aktif = 0 WHERE slug IN ('vergi-turleri', 'usul-ve-mevzuat');

-- Ekonomi basligi "Ekonomik Gundem" oluyor (slug ayni kaliyor).
UPDATE kategoriler
   SET ad = 'Ekonomik Gündem',
       aciklama = 'Enflasyon, faiz, kur, büyüme ve kamu maliyesi'
 WHERE slug = 'ekonomi';

-- Menu sirasi.
UPDATE kategoriler SET ust_id = NULL, sira = 10 WHERE slug = 'vergi-kanunlari';
UPDATE kategoriler SET ust_id = NULL, sira = 20 WHERE slug = 'muhasebe-denetim';
UPDATE kategoriler SET ad = 'Ekonomik Gündem', aktif = 1, ust_id = NULL, sira = 30 WHERE slug = 'ekonomi';

-- "Genel" -> "Diger", en sonda.
UPDATE kategoriler
   SET ad = 'Diğer', aciklama = 'Diğer vergi ve mali gündem', sira = 900
 WHERE slug = 'genel';

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

-- ---------------------------------------------------------------------------
-- Ekonomi kaynaklari
--
-- Iki katmanli: ustte veriyi ureten resmi kurumlar, altta ekonomi basini.
-- Resmi kurumlar oncelikli, cunku "enflasyon aciklandi", "faiz karari" gibi
-- haberler oradan cikar ve ikinci elden aktarilirken ayrinti kaybediyor.
--
-- Cogu kurum RSS yayinlamiyor; bu kayitlarda besleme_url NULL birakilip
-- yalnizca kazima adresi veriliyor. Ajan RSS bulamazsa liste_url'i kazir.
--
-- Adreslerin hicbiri buradan dogrulanamadi (gelistirme ortamindan dis
-- sitelere cikis kapali). Hangisinin calistigini gormek icin panelden
-- "Kaynaklari sina" dugmesini kullanin; calismayani duzeltin ya da
-- kapatin. Kapali kaynak taranmaz.
-- ---------------------------------------------------------------------------

-- Veriyi ureten kurumlar (kazima)
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('TÜİK',                'https://data.tuik.gov.tr', NULL,
     'https://data.tuik.gov.tr/Bulten/Index', 'resmi', 1),
    ('TCMB',                'https://www.tcmb.gov.tr',  NULL,
     'https://www.tcmb.gov.tr/wps/wcm/connect/TR/TCMB+TR/Main+Menu/Duyurular', 'resmi', 1),
    ('BDDK',                'https://www.bddk.org.tr',  NULL,
     'https://www.bddk.org.tr/Duyuru', 'resmi', 1),
    ('SPK',                 'https://spk.gov.tr',       NULL,
     'https://spk.gov.tr/duyuru-listesi', 'resmi', 1),
    ('Rekabet Kurumu',      'https://www.rekabet.gov.tr', NULL,
     'https://www.rekabet.gov.tr/tr/Guncel/duyurular', 'resmi', 1),
    ('SGK',                 'https://www.sgk.gov.tr',   NULL,
     'https://www.sgk.gov.tr/Duyuru', 'resmi', 1),
    ('Ticaret Bakanlığı',   'https://www.ticaret.gov.tr', NULL,
     'https://www.ticaret.gov.tr/duyurular', 'resmi', 1),
    ('KOSGEB',              'https://www.kosgeb.gov.tr', NULL,
     'https://www.kosgeb.gov.tr/site/tr/genel/duyurular', 'resmi', 1);

-- Ekonomi basini (RSS, kazima yedekli)
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('Dünya Gazetesi',      'https://www.dunya.com',
     'https://www.dunya.com/rss',                    'https://www.dunya.com/ekonomi', 'rss', 1),
    ('BigPara',             'https://bigpara.hurriyet.com.tr',
     'https://bigpara.hurriyet.com.tr/rss/',         'https://bigpara.hurriyet.com.tr/haberler/ekonomi-haberleri/', 'rss', 1),
    ('TRT Haber Ekonomi',   'https://www.trthaber.com',
     'https://www.trthaber.com/ekonomi_articles.rss','https://www.trthaber.com/haber/ekonomi/', 'rss', 1),
    ('CNN Türk Ekonomi',    'https://www.cnnturk.com',
     'https://www.cnnturk.com/feed/rss/ekonomi/news','https://www.cnnturk.com/ekonomi', 'rss', 1),
    ('Sözcü Ekonomi',       'https://www.sozcu.com.tr',
     'https://www.sozcu.com.tr/feeds-rss-category-ekonomi', 'https://www.sozcu.com.tr/kategori/ekonomi/', 'rss', 1),
    ('Cumhuriyet Ekonomi',  'https://www.cumhuriyet.com.tr',
     'https://www.cumhuriyet.com.tr/rss/9',          'https://www.cumhuriyet.com.tr/ekonomi', 'rss', 1),
    ('Para Analiz',         'https://www.paraanaliz.com',
     'https://www.paraanaliz.com/feed/',             'https://www.paraanaliz.com/kategori/ekonomi/', 'rss', 1),
    ('Ekonomi Gazetesi',    'https://www.ekonomigazetesi.com.tr',
     'https://www.ekonomigazetesi.com.tr/rss',       'https://www.ekonomigazetesi.com.tr/ekonomi', 'rss', 1),
    ('Fortune Türkiye',     'https://www.fortuneturkey.com',
     'https://www.fortuneturkey.com/rss',            'https://www.fortuneturkey.com/ekonomi', 'rss', 1),
    ('A Haber Ekonomi',     'https://www.ahaber.com.tr',
     'https://www.ahaber.com.tr/rss/ekonomi.xml',    'https://www.ahaber.com.tr/ekonomi', 'rss', 1),
    ('Star Ekonomi',        'https://www.star.com.tr',
     'https://www.star.com.tr/rss/ekonomi.xml',      'https://www.star.com.tr/ekonomi/', 'rss', 1),
    ('Yeni Şafak Ekonomi',  'https://www.yenisafak.com',
     'https://www.yenisafak.com/rss?xml=ekonomi',    'https://www.yenisafak.com/ekonomi', 'rss', 1);

-- Mevcut ekonomi kaynaklarina kazima yedegi: RSS adresi degisirse ajan
-- sessizce bos donmek yerine duyuru sayfasini kazimayi dener.
UPDATE kaynaklar SET liste_url = 'https://www.ekonomim.com/ekonomi'
 WHERE ad = 'Ekonomim' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.bloomberght.com/ekonomi'
 WHERE ad = 'Bloomberg HT' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.ntv.com.tr/ekonomi'
 WHERE ad = 'NTV Ekonomi' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.hurriyet.com.tr/ekonomi/'
 WHERE ad = 'Hürriyet Ekonomi' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.milliyet.com.tr/ekonomi/'
 WHERE ad = 'Milliyet Ekonomi' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.haberturk.com/ekonomi'
 WHERE ad = 'Habertürk Ekonomi' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.sabah.com.tr/ekonomi'
 WHERE ad = 'Sabah Ekonomi' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.patronlardunyasi.com/ekonomi'
 WHERE ad = 'Patronlar Dünyası' AND liste_url IS NULL;

UPDATE kaynaklar SET liste_url = 'https://www.aa.com.tr/tr/ekonomi'
 WHERE ad = 'Anadolu Ajansı Ekonomi' AND liste_url IS NULL;

-- ---------------------------------------------------------------------------
-- Denetim ve danismanlik sirketleri
--
-- TMS/TFRS ve bagimsiz denetim haberciliginin asil kaynagi bunlar: KGK
-- bir standardi yayimladiginda yorumu ve uygulama ornegini bu
-- sirketlerin bultenlerinde buluyorsun. Vergi sirkulerleri de duzenli.
--
-- Hicbiri RSS yayinlamiyor; yalnizca kazima adresi verildi. Adresler bu
-- ortamdan dogrulanamadi, "Kaynaklari sina" ile kontrol edin.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('Deloitte Türkiye',        'https://www.deloitte.com/tr/tr.html', NULL,
     'https://www.deloitte.com/tr/tr/services/tax/perspectives.html', 'web', 1),
    ('PwC Türkiye',             'https://www.pwc.com.tr', NULL,
     'https://www.pwc.com.tr/tr/hizmetlerimiz/vergi/bultenler.html', 'web', 1),
    ('KPMG Türkiye',            'https://kpmg.com/tr/tr/home.html', NULL,
     'https://kpmg.com/tr/tr/home/insights.html', 'web', 1),
    ('BDO Türkiye',             'https://www.bdo.com.tr', NULL,
     'https://www.bdo.com.tr/tr-tr/yayinlar', 'web', 1),
    ('EY Türkiye',              'https://www.ey.com/tr_tr', NULL,
     'https://www.ey.com/tr_tr/insights/tax', 'web', 1),
    ('Grant Thornton Türkiye',  'https://www.grantthornton.com.tr', NULL,
     'https://www.grantthornton.com.tr/tr/icgorulerimiz/', 'web', 1),
    ('Vergi Dünyası',           'https://www.vergidunyasi.com.tr', NULL,
     'https://www.vergidunyasi.com.tr/makaleler', 'web', 1);

-- ---------------------------------------------------------------------------
-- Yabanci kaynaklar
--
-- Uluslararasi vergi gundemi (OECD asgari kurumlar vergisi, AB KDV
-- reformu, IFRS degisiklikleri) Turkiye'yi dogrudan etkiliyor ama Turkce
-- basina gecikmeli ve eksik yansiyor. Ajan bu kaynaklari okuyup haberi
-- TURKCE yaziyor: ceviri degil, kendi cumleleriyle yeniden yazim.
--
-- On eleyici Ingilizce terimleri de taniyor; yoksa bu basliklar modele
-- hic ulasmadan elenirdi.
--
-- Adresler bu ortamdan dogrulanamadi, "Kaynaklari sina" ile kontrol edin.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    -- Uluslararasi kurumlar: vergi gundeminin kaynagi
    ('OECD Vergi',              'https://www.oecd.org',
     NULL, 'https://www.oecd.org/en/topics/tax-policy.html', 'resmi', 1),
    ('Avrupa Komisyonu Vergi',  'https://taxation-customs.ec.europa.eu',
     NULL, 'https://taxation-customs.ec.europa.eu/news_en', 'resmi', 1),
    ('IFRS Foundation',         'https://www.ifrs.org',
     NULL, 'https://www.ifrs.org/news-and-events/news/', 'resmi', 1),
    ('IAS Plus',                 'https://www.iasplus.com',
     NULL, 'https://www.iasplus.com/en/news', 'web', 1),
    ('EFRAG',                    'https://www.efrag.org',
     NULL, 'https://www.efrag.org/en/financial-reporting/news', 'resmi', 1),
    ('IMF',                     'https://www.imf.org',
     NULL, 'https://www.imf.org/en/News', 'resmi', 1),
    ('IRS',                     'https://www.irs.gov',
     NULL, 'https://www.irs.gov/newsroom', 'resmi', 1),

    -- Uluslararasi mesleki yayinlar
    ('Tax Foundation',          'https://taxfoundation.org',
     'https://taxfoundation.org/feed/',        'https://taxfoundation.org/blog/', 'rss', 1),
    ('Accountancy Age',         'https://www.accountancyage.com',
     'https://www.accountancyage.com/feed/',   'https://www.accountancyage.com/category/tax/', 'rss', 1),
    ('Tax Justice Network',     'https://taxjustice.net',
     'https://taxjustice.net/feed/',           'https://taxjustice.net/blog/', 'rss', 1),
    ('ICAEW',                   'https://www.icaew.com',
     NULL, 'https://www.icaew.com/insights/tax-news', 'web', 1),
    ('IFAC',                    'https://www.ifac.org',
     NULL, 'https://www.ifac.org/knowledge-gateway', 'web', 1),

    -- Uluslararasi ekonomi ajanslari
    ('Reuters Business',        'https://www.reuters.com',
     'https://www.reutersagency.com/feed/?best-topics=business-finance&post_type=best',
     'https://www.reuters.com/business/', 'rss', 1),
    ('Bloomberg Ekonomi',       'https://www.bloomberg.com',
     NULL, 'https://www.bloomberg.com/economics', 'web', 1);

-- ---------------------------------------------------------------------------
-- Dunyanin onde gelen haber kuruluslari
--
-- Genel haber degil, EKONOMI/IS bolumlerinin beslemeleri aliniyor.
-- Ana sayfa beslemesi gunde yuzlerce girdi uretir ve neredeyse tamami
-- on elemeden dusup bosa istek olur.
--
-- Uc katman:
--   1) Turkce yayin yapan yabanci kurumlar — cevirisiz kullanilabilir
--   2) Ingilizce ekonomi/is bolumleri — ajan Turkce yaziyor
--   3) Merkez bankalari ve uluslararasi kurumlar — veriyi ureten yer
--
-- On eleyici Ingilizce terimleri de taniyor, aksi halde bu
-- basliklarin tamami modele hic ulasmadan elenirdi.
--
-- Adresler bu ortamdan dogrulanamadi ("Kaynaklari sina" ile kontrol
-- edin). Calismayanlari panelden kapatin; kapali kaynak taranmaz.
-- ---------------------------------------------------------------------------

-- 1) Turkce yayin yapan yabanci kuruluslar
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('BBC Türkçe',              'https://www.bbc.com/turkce',
     'https://feeds.bbci.co.uk/turkce/rss.xml',
     'https://www.bbc.com/turkce/topics/cn7pd2vlq5jt', 'rss', 1),
    ('DW Türkçe',               'https://www.dw.com/tr',
     'https://rss.dw.com/rdf/rss-tur-all',
     'https://www.dw.com/tr/ekonomi/s-10011', 'rss', 1),
    ('Euronews Türkçe',         'https://tr.euronews.com',
     'https://tr.euronews.com/rss?level=theme&name=business',
     'https://tr.euronews.com/business', 'rss', 1),
    ('VOA Türkçe',              'https://www.amerikaninsesi.com',
     'https://www.amerikaninsesi.com/api/zkvyteumqi',
     'https://www.amerikaninsesi.com/z/1730', 'rss', 1);

-- 2) Ingilizce ekonomi ve is bolumleri
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('BBC Business',            'https://www.bbc.com/news/business',
     'https://feeds.bbci.co.uk/news/business/rss.xml',
     'https://www.bbc.com/business', 'rss', 1),
    ('The Guardian Business',   'https://www.theguardian.com/business',
     'https://www.theguardian.com/business/rss',
     'https://www.theguardian.com/business/economics', 'rss', 1),
    ('The New York Times Business', 'https://www.nytimes.com/section/business',
     'https://rss.nytimes.com/services/xml/rss/nyt/Business.xml',
     'https://www.nytimes.com/section/business/economy', 'rss', 1),
    ('The Economist Finans',    'https://www.economist.com',
     'https://www.economist.com/finance-and-economics/rss.xml',
     'https://www.economist.com/finance-and-economics', 'rss', 1),
    ('Financial Times',         'https://www.ft.com',
     'https://www.ft.com/rss/home',
     'https://www.ft.com/global-economy', 'rss', 1),
    ('CNBC Ekonomi',            'https://www.cnbc.com',
     'https://search.cnbc.com/rs/search/combinedcms/view.xml?partnerId=wrss01&id=20910258',
     'https://www.cnbc.com/economy/', 'rss', 1),
    ('MarketWatch',             'https://www.marketwatch.com',
     'https://feeds.content.dowjones.io/public/rss/mw_topstories',
     'https://www.marketwatch.com/economy-politics', 'rss', 1),
    ('CNN Business',            'https://edition.cnn.com/business',
     'http://rss.cnn.com/rss/money_latest.rss',
     'https://edition.cnn.com/business/economy', 'rss', 1),
    ('Al Jazeera Ekonomi',      'https://www.aljazeera.com',
     'https://www.aljazeera.com/xml/rss/all.xml',
     'https://www.aljazeera.com/economy/', 'rss', 1),
    ('DW Business',             'https://www.dw.com/en/business',
     'https://rss.dw.com/rdf/rss-en-bus',
     'https://www.dw.com/en/business/s-1431', 'rss', 1),
    ('Associated Press İş',     'https://apnews.com',
     NULL, 'https://apnews.com/hub/business', 'web', 1),
    ('Nikkei Asia Ekonomi',     'https://asia.nikkei.com',
     NULL, 'https://asia.nikkei.com/Economy', 'web', 1);

-- 3) Merkez bankalari ve uluslararasi kurumlar
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('Avrupa Merkez Bankası',   'https://www.ecb.europa.eu',
     'https://www.ecb.europa.eu/rss/press.html',
     'https://www.ecb.europa.eu/press/html/index.en.html', 'resmi', 1),
    ('Federal Reserve',         'https://www.federalreserve.gov',
     'https://www.federalreserve.gov/feeds/press_all.xml',
     'https://www.federalreserve.gov/newsevents/pressreleases.htm', 'resmi', 1),
    ('Dünya Bankası',           'https://www.worldbank.org',
     NULL, 'https://www.worldbank.org/en/news/all', 'resmi', 1),
    ('Bank for International Settlements', 'https://www.bis.org',
     'https://www.bis.org/list/press_rlsdate/index.rss',
     'https://www.bis.org/press/index.htm', 'resmi', 1);

-- ---------------------------------------------------------------------------
-- Ekonomi grubu
--
-- Site vergi odakli ama ekonomi gundemi de izleniyor; ajan vergi disi
-- ama mali/ekonomik onemi olan haberleri bu gruba atar.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO kategoriler (ad, slug, aciklama, sira) VALUES
    ('Ekonomi', 'ekonomi', 'Piyasalar, enflasyon, faiz ve makroekonomik gelismeler', 35);

UPDATE kategoriler SET aciklama = 'Piyasalar, enflasyon, faiz ve makroekonomik gelişmeler',
       ust_id = NULL, sira = 35
 WHERE slug = 'ekonomi';


-- Temel menu ve yabanci kaynak adreslerini eski kurulumlarda da duzelt.
UPDATE kategoriler SET ad = 'TMS/TFRS', aktif = 1, ust_id = NULL, sira = 40
 WHERE slug = 'tms-tfrs';
UPDATE kategoriler SET ad = 'Diğer', aktif = 1, ust_id = NULL, sira = 50
 WHERE slug = 'genel';
UPDATE kategoriler SET ad = 'Ekonomik Gündem', aktif = 1, ust_id = NULL, sira = 30
 WHERE slug = 'ekonomi';

UPDATE kaynaklar
   SET liste_url = 'https://www.oecd.org/en/topics/tax-policy.html', aktif = 1
 WHERE ad = 'OECD Vergi';

UPDATE kaynaklar
   SET liste_url = 'https://www.ifrs.org/news-and-events/news/', aktif = 1
 WHERE ad = 'IFRS Foundation';

-- ---------------------------------------------------------------------------
-- Ziyaretler
--
-- Kendi sayacimiz. Ucuncu taraf bir olcumleyici (Analytics vb.)
-- ziyaretcinin verisini disari gonderirdi; bir YMM sitesinde bunu
-- varsayilan yapmak dogru degil. Ustelik reklam engelleyiciler o
-- betikleri siklikla bloke ediyor ve sayilar eksik cikiyor.
--
-- HAM IP SAKLANMIYOR. "ziyaretci" alani, IP + tarayici kimliginin
-- veritabaninda duran rastgele bir tuzla hashlenmis ve 16 karaktere
-- kisaltilmis halidir; geri cevrilemez, yalnizca "ayni tarayici mi"
-- karsilastirmasina yarar.
--
-- BIGINT kullaniliyor: bu tablo diger her seyden hizli buyur ve
-- INT'in siniri (~2,1 milyar) uzun vadede gercek bir tavandir.
-- Kayitlar 180 gun sonra kendiliginden siliniyor (bkz. includes/ziyaret.php).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ziyaretler (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zaman       DATETIME        NOT NULL,
    ziyaretci   CHAR(16)        NOT NULL,
    -- Ham adres. Yonetici panelinde gosterilmek uzere saklaniyor.
    -- KISISEL VERIDIR: sitenin aydinlatma metninde yer almasi gerekir.
    -- Diger alanlarla birlikte 180 gun sonra siliniyor ve hicbir
    -- ucuncu tarafa gonderilmiyor.
    ip          VARCHAR(45)     NOT NULL DEFAULT '',
    yol         VARCHAR(255)    NOT NULL,
    haber_id    INT UNSIGNED    NULL,
    baslik      VARCHAR(255)    NOT NULL DEFAULT '',
    yonlendiren VARCHAR(255)    NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    -- Butun raporlar once tarihe gore suzuyor; bu indeks olmadan
    -- tablo buyudukce panel yavaslar.
    KEY ix_ziyaret_zaman (zaman),
    KEY ix_ziyaret_haber (haber_id, zaman),
    KEY ix_ziyaret_kisi (ziyaretci, zaman)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Pratik bilgiler
--
-- Asgari ucret, gelir vergisi tarifesi, KDV oranlari, SGK taban/tavan
-- gibi gunluk iste kullanilan degerler.
--
-- Bu tablodaki bir hata haberdeki hatadan daha tehlikeli: haber
-- okunup gecilir, buradaki rakam DOGRUDAN hesaplamada kullanilir.
-- O yuzden akis haberlerdekinden daha siki:
--
--   - Her satirin resmi bir kaynak adresi var ve ajan degeri yalnizca
--     o adresten okuyor; genel arama yapmiyor.
--   - Cekilen deger TASLAK olarak geliyor, onaysiz yayimlanmiyor.
--   - Onaylanan deger yaninda kaynagi, gecerlilik donemi ve son
--     guncelleme tarihi gorunuyor; okuyucu neye baktigini biliyor.
--   - Eski deger silinmiyor: yeni deger onaylanana kadar yayindaki
--     deger yerinde kaliyor, sayfa bosalmiyor.
--
-- "deger" metin: kimi bilgi tek sayi (asgari ucret), kimi tabloya
-- benziyor (gelir vergisi tarifesi, KDV oranlari). Sayisal tipe
-- zorlamak tarifeleri disarida birakirdi.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pratik_bilgiler (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    anahtar      VARCHAR(80)  NOT NULL,
    baslik       VARCHAR(200) NOT NULL,
    aciklama     VARCHAR(500) NULL,
    grup         VARCHAR(80)  NOT NULL DEFAULT 'genel',
    sira         INT          NOT NULL DEFAULT 100,

    -- Ajanin degeri okuyacagi resmi sayfa.
    kaynak_url   VARCHAR(500) NULL,
    kaynak_adi   VARCHAR(160) NULL,
    -- Sayfada neye bakilacagini modele anlatan kisa yonerge.
    arama_ipucu  VARCHAR(500) NULL,

    -- Yayindaki (onaylanmis) deger.
    deger        TEXT         NULL,
    donem        VARCHAR(120) NULL,
    onay_tarihi  DATETIME     NULL,

    -- Ajanin getirdigi, onay bekleyen deger.
    aday_deger   TEXT         NULL,
    aday_donem   VARCHAR(120) NULL,
    aday_notu    VARCHAR(500) NULL,
    aday_guven   TINYINT UNSIGNED NULL,
    aday_tarihi  DATETIME     NULL,

    aktif        TINYINT(1)   NOT NULL DEFAULT 1,
    guncellendi  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_pratik_anahtar (anahtar),
    KEY ix_pratik_sira (aktif, grup, sira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Toplanacak bilgiler ve resmi kaynaklari.
--
-- Deger alani BOS birakiliyor; rakamlari ajan getirip onaya sunacak.
-- Buraya elle rakam yazmak, dogrulanmamis bir sayiyi yayimlamak
-- demek olurdu.
INSERT IGNORE INTO pratik_bilgiler (anahtar, baslik, aciklama, grup, sira, kaynak_url, kaynak_adi, arama_ipucu) VALUES
    ('asgari-ucret', 'Asgari Ücret',
     'Brüt ve net asgari ücret ile işverene maliyeti', 'ucret-sgk', 10,
     'https://www.csgb.gov.tr/asgari-ucret/', 'Çalışma ve Sosyal Güvenlik Bakanlığı',
     'Yürürlükteki brüt asgari ücret, net asgari ücret ve işverene toplam maliyeti. Aylık tutarları al.'),

    ('sgk-taban-tavan', 'SGK Prime Esas Kazanç Taban ve Tavanı',
     'Sigorta primine esas günlük ve aylık kazanç sınırları', 'ucret-sgk', 20,
     'https://www.sgk.gov.tr/', 'SGK',
     'Prime esas kazancın günlük ve aylık alt sınırı ile üst sınırı (tavan).'),

    ('kidem-tazminati-tavani', 'Kıdem Tazminatı Tavanı',
     'Bir yıllık hizmet için ödenecek en yüksek kıdem tazminatı', 'ucret-sgk', 30,
     'https://www.hmb.gov.tr/', 'Hazine ve Maliye Bakanlığı',
     'Yürürlükteki kıdem tazminatı tavanı ve geçerli olduğu dönem.'),

    ('gelir-vergisi-tarifesi', 'Gelir Vergisi Tarifesi',
     'Yıllık gelir vergisi dilimleri ve oranları', 'vergi-oranlari', 10,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Cari yıl gelir vergisi tarifesi: dilim tutarları ve her dilimin oranı. Ücret dışı gelirler için olanı al.'),

    ('kurumlar-vergisi-orani', 'Kurumlar Vergisi Oranı',
     'Genel oran ve varsa indirimli oranlar', 'vergi-oranlari', 20,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Genel kurumlar vergisi oranı, varsa ihracat ve üretim kazançlarına uygulanan indirimli oranlar.'),

    ('kdv-oranlari', 'KDV Oranları',
     'Genel oran ve indirimli oran listeleri', 'vergi-oranlari', 30,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Yürürlükteki KDV oranları: genel oran ve indirimli oranlar.'),

    ('yeniden-degerleme-orani', 'Yeniden Değerleme Oranı',
     'VUK mükerrer 298 kapsamında ilan edilen oran', 'vergi-oranlari', 40,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Cari yıl için ilan edilen yeniden değerleme oranı ve dayandığı tebliğ.'),

    ('gecikme-zammi', 'Gecikme Zammı ve Gecikme Faizi Oranı',
     'Amme alacaklarında aylık gecikme zammı oranı', 'vergi-oranlari', 50,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Yürürlükteki aylık gecikme zammı oranı ve gecikme faizi oranı.'),

    ('damga-vergisi-oranlari', 'Damga Vergisi Oranları',
     'Sık kullanılan kâğıtlarda nispet ve azami tutar', 'vergi-oranlari', 60,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Sözleşmelerde uygulanan damga vergisi nispeti ve cari yıl azami tutarı.'),

    ('fatura-duzenleme-siniri', 'Fatura Düzenleme Sınırı',
     'VUK 232 kapsamında fatura düzenleme alt sınırı', 'hadler', 10,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Cari yıl için fatura düzenleme zorunluluğu sınırı (VUK 232).'),

    ('amortisman-siniri', 'Doğrudan Gider Yazılabilecek Sabit Kıymet Sınırı',
     'VUK 313 kapsamında amortisman ayırma alt sınırı', 'hadler', 20,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Cari yıl için doğrudan gider yazılabilecek demirbaş sınırı (VUK 313).'),

    ('beyanname-damga-vergisi', 'Beyanname Damga Vergisi Tutarları',
     'Yıllık, muhtasar ve KDV beyannamelerinde damga vergisi', 'hadler', 30,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Cari yıl beyanname damga vergisi tutarları (yıllık gelir, kurumlar, muhtasar, KDV).'),

    ('harcirah-tutarlari', 'Harcırah (Yurt İçi Gündelik) Tutarları',
     'Gelir vergisinden istisna yurt içi harcırah', 'hadler', 40,
     'https://www.gib.gov.tr/', 'Gelir İdaresi Başkanlığı',
     'Cari yıl gelir vergisinden istisna yurt içi gündelik tutarları.'),

    ('politika-faizi', 'TCMB Politika Faizi',
     'Merkez Bankası bir hafta vadeli repo ihale faiz oranı', 'ekonomi', 10,
     'https://www.tcmb.gov.tr/', 'TCMB',
     'Yürürlükteki politika faizi (bir hafta vadeli repo) ve son değişiklik tarihi.'),

    ('enflasyon-orani', 'Enflasyon (TÜFE)',
     'Aylık ve yıllık tüketici fiyat endeksi değişimi', 'ekonomi', 20,
     'https://data.tuik.gov.tr/Bulten/Index?p=Tuketici-Fiyat-Endeksi', 'TÜİK',
     'En son açıklanan aylık ve yıllık TÜFE değişim oranları ile ait olduğu ay.');

-- ---------------------------------------------------------------------------
-- Pratik bilgi kaynak adreslerini duzelt
--
-- Ilk tohumda adreslerin cogu kurumun ANA SAYFASIYDI (gib.gov.tr/).
-- Iki sorun vardi:
--
--   1. Deger ana sayfada durmuyor. "Fatura duzenleme siniri" GIB ana
--      sayfasinda yazmiyor; model dogru calissa bile bulamazdi.
--   2. Ilk calismada 15 sayfanin 13'u hic okunamadi. Turk kamu
--      siteleri veri merkezi IP'lerini engelliyor ve ajan GitHub'da
--      calisiyor. Bu, sayfalar SITE uzerinden getirilerek cozuldu
--      (api/getir.php); site Turkiye'de barindigi icin ayni adreslere
--      ulasiyor.
--
-- Adresler iki katmanli: mumkun oldugunca resmi kaynak, resmi kaynakta
-- derli toplu bir sayfa yoksa bu degerleri duzenli yayimlayan mesleki
-- siteler. Mesleki site kullanildiginda kaynak adi da oyle yaziliyor;
-- okuyucu degeri nereden aldigimizi gormeli.
--
-- Hicbiri bu gelistirme ortamindan dogrulanamadi (dis cikis kapali).
-- Calisma gunlugu hangi sayfanin okunamadigini tek tek yaziyor.
-- ---------------------------------------------------------------------------

UPDATE pratik_bilgiler SET
    kaynak_url = 'https://www.csgb.gov.tr/asgari-ucret/',
    kaynak_adi = 'Çalışma ve Sosyal Güvenlik Bakanlığı'
 WHERE anahtar = 'asgari-ucret';

UPDATE pratik_bilgiler SET
    kaynak_url = 'https://www.muhasebetr.com/pratikbilgiler/',
    kaynak_adi = 'MuhasebeTR — Pratik Bilgiler'
 WHERE anahtar IN ('sgk-taban-tavan', 'kidem-tazminati-tavani');

UPDATE pratik_bilgiler SET
    kaynak_url = 'https://www.gib.gov.tr/yardim-ve-kaynaklar/yararli-bilgiler',
    kaynak_adi = 'Gelir İdaresi Başkanlığı'
 WHERE anahtar IN ('gelir-vergisi-tarifesi', 'kurumlar-vergisi-orani',
                   'kdv-oranlari', 'yeniden-degerleme-orani',
                   'gecikme-zammi', 'damga-vergisi-oranlari',
                   'fatura-duzenleme-siniri', 'amortisman-siniri',
                   'beyanname-damga-vergisi', 'harcirah-tutarlari');

UPDATE pratik_bilgiler SET
    kaynak_url = 'https://www.tcmb.gov.tr/wps/wcm/connect/TR/TCMB+TR/Main+Menu/Temel+Faaliyetler/Para+Politikasi/Merkez+Bankasi+Faiz+Oranlari/1+Hafta+Repo',
    kaynak_adi = 'TCMB'
 WHERE anahtar = 'politika-faizi';

-- Enflasyon icin TUIK adresi ISE YARAMADI.
--
-- Once aya bagli bir bulten adresi yaziliydi ("...-Agustos-2026") ve
-- her ay kiriliyordu. Bulten listesine ("/Bulten/Index") baglandi,
-- bu da okunamadi: sayfa ne site sunucusundan ne de ajandan metin
-- dondurdu. Adres tahmin etmeyi surdurmek yerine okunabildigi
-- kanitlanmis derleme sayfasina baglaniyor; ajan oradaki TUFE
-- baglantisini izleyebiliyor.
--
-- Deger yine onaysiz yayimlanmiyor: sayfada yoksa "bulunamadi" diyor,
-- uydurma bir rakam gecmiyor.
UPDATE pratik_bilgiler SET
    kaynak_url = 'https://www.alomaliye.com/2025/12/23/2026-pratik-bilgiler/',
    kaynak_adi = 'Alomaliye — 2026 Pratik Bilgiler'
 WHERE anahtar = 'enflasyon-orani';

-- Yedek kaynak: GIB sayfasi okunamazsa ya da deger bulunamazsa, ayni
-- degerleri yayimlayan mesleki siteye dusulebilsin diye arama ipucuna
-- not dusuluyor. (Yedek adres alani yok; bu bilinçli — iki adres iki
-- ayri dogruluk sorumlulugu demek olurdu.)
UPDATE pratik_bilgiler
   SET arama_ipucu = CONCAT(arama_ipucu, ' Sayfada birden fazla yıl varsa EN GÜNCEL yılı al.')
 WHERE arama_ipucu NOT LIKE '%EN GÜNCEL%';

-- ---------------------------------------------------------------------------
-- Pratik bilgi kaynaklarini derli toplu sayfalara cek
--
-- Onceki adresler kurum kurum dagilmisti ve cogu ulasilamiyordu.
-- Alomaliye ve ISMMMO bu degerlerin TAMAMINI tek sayfada, yil yil
-- duzenli yayimliyor. Meslek camiasinin yillardir kullandigi
-- derlemeler; resmi kaynak degiller ama degerin yanina kaynak adi
-- yazildigi icin okuyucu neye baktigini goruyor.
--
-- Tek sayfada toplanmanin ikinci faydasi: ajan ayni sayfayi her satir
-- icin yeniden indirmiyor, bir kez indirip icinden tum degerleri
-- okuyor.
--
-- DIKKAT: Alomaliye adresi yila bagli (".../2026-pratik-bilgiler/").
-- Yil donunce panelden "Kaynak adresi" alanindan guncellenmeli;
-- gunluk sayfa okunamadiginda bunu acikca yaziyor.
-- ---------------------------------------------------------------------------

UPDATE pratik_bilgiler SET
    kaynak_url = 'https://www.alomaliye.com/2025/12/23/2026-pratik-bilgiler/',
    kaynak_adi = 'Alomaliye — 2026 Pratik Bilgiler'
 WHERE anahtar IN ('asgari-ucret', 'sgk-taban-tavan', 'kidem-tazminati-tavani',
                   'gelir-vergisi-tarifesi', 'kurumlar-vergisi-orani',
                   'yeniden-degerleme-orani', 'gecikme-zammi');

-- ISMMMO sayfasi RAKAM TASIMIYOR.
--
-- Uc ayri calismada da ayni sonuc cikti: sayfa bir fihrist, yalnizca
-- konu basliklarini listeliyor. Ajan dogru sekilde alt sayfaya indi
-- ("2026 Yilinda Uygulanacak Vergi Tutarlari, Hadler ve Cezalar") ama
-- o da baslik listesiydi; ikinci adimda da rakam cikmadi. Alti bilginin
-- altisi bu yuzden hic toplanamadi.
--
-- Ayni bilgiler Alomaliye derlemesinde duruyor ve o sayfa okunabildigi
-- kanitlandi: asgari ucret, SGK taban/tavan, gelir vergisi tarifesi,
-- yeniden degerleme orani, gecikme zammi ve kidem tazminati tavani
-- oradan geldi. Kaynak ise yarayan sayfaya cevriliyor.
UPDATE pratik_bilgiler SET
    kaynak_url = 'https://www.alomaliye.com/2025/12/23/2026-pratik-bilgiler/',
    kaynak_adi = 'Alomaliye — 2026 Pratik Bilgiler'
 WHERE anahtar IN ('kdv-oranlari', 'damga-vergisi-oranlari',
                   'fatura-duzenleme-siniri', 'amortisman-siniri',
                   'beyanname-damga-vergisi', 'harcirah-tutarlari');

-- ---------------------------------------------------------------------------
-- Sinanmis kaynak duzeltmeleri (21.09.2026)
--
-- Her adres EKLENMEDEN ONCE ajanin kostugu yerde sinandi
-- (ajan/kaynak_dene.php --aday=...). Sinanmadan eklenen adresler
-- listede otuz kusur olu kaynak biriktirdi; bu blok o sirayi tersine
-- cevirmek icin var. Sinamadan gecmeyenler kasten EKLENMEDI:
--
--   verginet.net           Kok adres ve /rss.aspx birebir ayni 193 KB'lik
--                          sayfayi donduruyor, icinde iki baglanti var.
--                          Icerik tarayicida olusuyor; ajan JavaScript
--                          calistirmadigi icin bu siteden haber alinamaz.
--   dijital.gib.gov.tr     React uygulamasi. Baglanti metni olarak CSS
--                          kodu geliyor, duyurular sayfada yok.
--   mynet.com              Tek ilan edilen besleme ana sayfa beslemesi:
--                          47 girdinin on elemeden gecen ucu magazin ve
--                          asayis haberiydi, uCu de vergi puani sifir.
-- ---------------------------------------------------------------------------

-- Euronews'in vergi etiketi ayri bir kaynak olarak ekleniyor.
-- Mevcut "Euronews Türkçe" kaydi genel is/ekonomi beslemesini okuyor;
-- vergi etiketi sayfasi 436 baglantidan 11 haber verdi ve hepsi
-- /business/ altindaki gercek makalelerdi.
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('Euronews Vergi',          'https://tr.euronews.com',
     NULL, 'https://tr.euronews.com/tag/vergiler', 'web', 1);

-- Bloomberg HT'nin RSS'i okunuyor ama site guncellemiyor: sinamada en
-- taze girdi ALTI GUN oncesine aitti, yani kaynak pratikte olu.
-- Makro sayfasi ayni sinamada 152 baglantidan 52 aday uretti (kazima
-- duz adresli sitelerde de calisir hale getirildikten sonra bunlar
-- kullanilabiliyor). Besleme birakilirsa ajan once onu deneyip bos
-- donecegi icin NULL yapiliyor.
UPDATE kaynaklar
   SET besleme_url = NULL,
       liste_url   = 'https://www.bloomberght.com/ekonomik-veriler-ve-gundem',
       tur         = 'web'
 WHERE ad = 'Bloomberg HT';

-- e-Belge (e-Fatura / e-Arşiv) duyurulari — 22.09.2026
--
-- Sinandi: ebelge.gib.gov.tr/duyurular.html 1072 baglantidan 10 haber
-- veriyor ve en kalabalik kalip /dosyalar/tebligler, yani e-belge
-- tebligleri. Bir YMM sitesi icin birinci sinif kaynak.
--
-- ACIK RISK: tebligler PDF olabilir. Ajan HTML okuyor; oyleyse baslik
-- gelir ama sayfa metni bos kalir ve model yalnizca baslikla haber
-- yazmaya calisir. Ilk calismanin ciktisina bakilip karar verilecek;
-- yuzeysel haber uretirse panelden kapatilacak.
--
-- forum.efatura.gov.tr denendi, HTTP 503 donuyor; eklenmedi.
INSERT IGNORE INTO kaynaklar (ad, site_url, besleme_url, liste_url, tur, aktif) VALUES
    ('GİB e-Belge Duyuruları',  'https://ebelge.gib.gov.tr',
     NULL, 'https://ebelge.gib.gov.tr/duyurular.html', 'resmi', 1);

-- ---------------------------------------------------------------------------
-- Vergi takvimi: yan penceredeki "Yaklasan Tarihler"
--
-- Beyan ve odeme sureleri her ay TEKRAR ettigi icin tek tek tarih
-- yazmak yerine kural saklaniyor: "her ayin 26'si", "Subat/Mayis/
-- Agustos/Kasim aylarinin 17'si". Yaklasan tarihler bu kuraldan
-- hesaplaniyor; her yil listeyi elle yenilemek gerekmiyor.
--
-- DIKKAT — SORUMLULUK: buradaki gunler GIB'in yayimladigi standart
-- vergi takvimine dayaniyor ama RESMI KAYNAK DEGILDIR. Sure sonu hafta
-- sonuna ya da resmi tatile denk geldiginde ilk is gunune kayar; GIB
-- ayrica sik sik sure uzatimi yayimlar. Bu yuzden her kaydin yaninda
-- kaynak baglantisi duruyor ve sitede "resmi takvimden dogrulayin"
-- uyarisi gosteriliyor.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vergi_takvimi (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    baslik      VARCHAR(200)  NOT NULL,
    aciklama    VARCHAR(400)  NOT NULL DEFAULT '',
    -- 'aylik' : her ay, ayin `gun` gunu
    -- 'secili': yalnizca `aylar` listesindeki aylarda
    tekrar      ENUM('aylik','secili') NOT NULL DEFAULT 'aylik',
    gun         TINYINT UNSIGNED NOT NULL,
    -- Virgulle ayrilmis ay numaralari; tekrar='secili' ise kullanilir.
    aylar       VARCHAR(40)   NOT NULL DEFAULT '',
    kaynak_url  VARCHAR(500)  NOT NULL DEFAULT '',
    sira        SMALLINT      NOT NULL DEFAULT 100,
    aktif       TINYINT(1)    NOT NULL DEFAULT 1,
    olusturuldu DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    guncellendi DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_takvim_aktif (aktif, sira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO vergi_takvimi (baslik, aciklama, tekrar, gun, aylar, kaynak_url, sira) VALUES
    ('Muhtasar ve Prim Hizmet Beyannamesi', 'Bir önceki aya ait beyan ve ödeme',
     'aylik', 26, '', 'https://www.gib.gov.tr/vergi-takvimi', 10),
    ('Damga Vergisi Beyannamesi', 'Bir önceki aya ait beyan ve ödeme',
     'aylik', 26, '', 'https://www.gib.gov.tr/vergi-takvimi', 20),
    ('KDV Beyannamesi', 'Bir önceki aya ait beyan ve ödeme',
     'aylik', 28, '', 'https://www.gib.gov.tr/vergi-takvimi', 30),
    ('Form Ba - Form Bs', 'Bir önceki aya ait bildirimler',
     'aylik', 30, '', 'https://www.gib.gov.tr/vergi-takvimi', 40),
    ('Geçici Vergi Beyannamesi', 'Üç aylık dönem beyanı ve ödemesi',
     'secili', 17, '2,5,8,11', 'https://www.gib.gov.tr/vergi-takvimi', 50),
    ('Yıllık Gelir Vergisi Beyannamesi', 'Bir önceki yıla ait beyan',
     'secili', 31, '3', 'https://www.gib.gov.tr/vergi-takvimi', 60),
    ('Kurumlar Vergisi Beyannamesi', 'Bir önceki hesap dönemine ait beyan',
     'secili', 30, '4', 'https://www.gib.gov.tr/vergi-takvimi', 70);

-- ---------------------------------------------------------------------------
-- Valentra Analiz: haberin "bana ne" karsiligi
--
-- Haber ne oldugunu anlatiyor; bu dort alan okuyucunun (mali musavir,
-- muhasebe calisani, isletme yoneticisi) haberi kendi isine
-- uyarlamasini kolaylastiriyor.
--
-- Alanlar BOS OLABILIR ve bos olmasi normaldir. Model bilgiyi
-- kaynakta bulamazsa bos birakiyor; ozellikle analiz_islem, kaynak bir
-- yukumluluk getirmiyorsa (faiz karari, enflasyon verisi) bos kaliyor.
-- Yarim bilgiyle doldurmak, YMM imzasi tasiyan bir sitede yanlis
-- yonlendirme olurdu.
-- ---------------------------------------------------------------------------
ALTER TABLE haberler
    ADD COLUMN IF NOT EXISTS analiz_degisen   VARCHAR(600) NOT NULL DEFAULT '' AFTER ajan_notu,
    ADD COLUMN IF NOT EXISTS analiz_etkilenen VARCHAR(600) NOT NULL DEFAULT '' AFTER analiz_degisen,
    ADD COLUMN IF NOT EXISTS analiz_zaman     VARCHAR(600) NOT NULL DEFAULT '' AFTER analiz_etkilenen,
    ADD COLUMN IF NOT EXISTS analiz_islem     VARCHAR(600) NOT NULL DEFAULT '' AFTER analiz_zaman;
