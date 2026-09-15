<?php
declare(strict_types=1);

/**
 * Sema kurulum yardimcilari.
 *
 * Tablolari phpMyAdmin'e girmeden, kurulum sayfasindan olusturabilmek icin
 * sql/schema.sql dosyasini okuyup calistirir. Semadaki her ifade
 * "CREATE TABLE IF NOT EXISTS" oldugu icin tekrar calistirmak zararsizdir.
 */

const VALENTRA_TABLOLAR = [
    'yoneticiler',
    'ayarlar',
    'kategoriler',
    'kaynaklar',
    'haberler',
    'ajan_anahtarlari',
    'ajan_kayitlari',
];

/**
 * Veritabaninda hangi tablolarin var oldugunu dondurur.
 *
 * @return array<string,bool>
 */
function sema_durumu(): array
{
    $durum = [];

    foreach (VALENTRA_TABLOLAR as $tablo) {
        try {
            db()->query('SELECT 1 FROM `' . $tablo . '` LIMIT 1');
            $durum[$tablo] = true;
        } catch (PDOException $e) {
            $durum[$tablo] = false;
        }
    }

    return $durum;
}

function sema_hazir(): bool
{
    return !in_array(false, sema_durumu(), true);
}

/**
 * schema.sql icindeki ifadeleri ayirir.
 *
 * Semada saklı yordam veya tetikleyici yok, bu yuzden satir yorumlarini
 * atip noktali virgulden bolmek yeterli.
 *
 * @return list<string>
 */
function sema_ifadeleri(string $sql): array
{
    $satirlar = [];

    foreach (preg_split('/\R/', $sql) ?: [] as $satir) {
        $kirpilmis = ltrim($satir);

        if ($kirpilmis === '' || str_starts_with($kirpilmis, '--')) {
            continue;
        }

        $satirlar[] = $satir;
    }

    $temiz = implode("\n", $satirlar);
    $parcalar = [];

    foreach (explode(';', $temiz) as $parca) {
        $parca = trim($parca);

        if ($parca !== '') {
            $parcalar[] = $parca;
        }
    }

    return $parcalar;
}

/**
 * Semayi calistirir.
 *
 * @return array{tamam:bool,calisan:int,mesaj:string}
 */
function sema_kur(string $dosya): array
{
    if (!is_file($dosya) || !is_readable($dosya)) {
        return ['tamam' => false, 'calisan' => 0, 'mesaj' => 'sql/schema.sql okunamadı.'];
    }

    $sql = file_get_contents($dosya);

    if ($sql === false) {
        return ['tamam' => false, 'calisan' => 0, 'mesaj' => 'sql/schema.sql okunamadı.'];
    }

    // Once tablolar, sonra eksik sutunlar, en son veri.
    //
    // Sema dosyasindaki INSERT/UPDATE ifadeleri yeni eklenen sutunlara
    // deger yazabilir. Daha once kurulmus bir veritabaninda o sutun
    // "CREATE TABLE IF NOT EXISTS" ile gelmeyecegi icin, veri yazan
    // ifadelerden ONCE sema_yukselt() calismali.
    $tabloIfadeleri = [];
    $veriIfadeleri  = [];

    foreach (sema_ifadeleri($sql) as $ifade) {
        if (preg_match('/^\s*CREATE\s/i', $ifade) === 1) {
            $tabloIfadeleri[] = $ifade;
        } else {
            $veriIfadeleri[] = $ifade;
        }
    }

    $calisan = 0;

    foreach ($tabloIfadeleri as $ifade) {
        $sonuc = sema_ifade_calistir($ifade, $calisan);

        if ($sonuc !== null) {
            return $sonuc;
        }
    }

    if (!sema_hazir()) {
        return ['tamam' => false, 'calisan' => $calisan, 'mesaj' => 'Tablolar oluşturulamadı.'];
    }

    $yukseltmeHatalari = [];
    $calisan += count(sema_yukselt($yukseltmeHatalari));

    foreach ($veriIfadeleri as $ifade) {
        $sonuc = sema_ifade_calistir($ifade, $calisan);

        if ($sonuc !== null) {
            return $sonuc;
        }
    }

    return [
        'tamam'   => sema_hazir() && sema_guncel_mi(),
        'calisan' => $calisan,
        'mesaj'   => $yukseltmeHatalari === []
            ? ''
            : 'Bazı adımlar uygulanamadı: ' . implode(' | ', $yukseltmeHatalari),
    ];
}

/**
 * Tek ifadeyi calistirir. Basarili ise null, hatali ise sema_kur'un
 * dondurecegi diziyi verir.
 *
 * @return array{tamam:bool,calisan:int,mesaj:string}|null
 */
function sema_ifade_calistir(string $ifade, int &$calisan): ?array
{
    try {
        db()->exec($ifade);
        $calisan++;

        return null;
    } catch (PDOException $e) {
        return [
            'tamam'   => false,
            'calisan' => $calisan,
            'mesaj'   => 'Şema uygulanamadı: ' . $e->getMessage(),
        ];
    }
}

/**
 * Var olan kurulumlari gunceller.
 *
 * "CREATE TABLE IF NOT EXISTS" yeni bir sutunu eklemez; daha once
 * kurulmus bir veritabani icin eksik sutunlari burada tamamliyoruz.
 * Her adim once varligi kontrol eder, bu yuzden tekrar calistirmak
 * zararsizdir.
 *
 * @return list<string> Uygulanan degisikliklerin aciklamalari
 */
function sema_yukselt(array &$hatalar = []): array
{
    $yapilanlar = [];

    /**
     * Tek bir yukseltme adimini calistirir.
     *
     * Bir adimin basarisiz olmasi digerlerini engellememeli: ornegin
     * indeks kurulamadi diye eksik bir sutunun eklenmemesi, sitenin hic
     * calismamasi demek olur. Hatalar toplanip panelde raporlanir.
     */
    $adim = static function (string $aciklama, callable $is) use (&$yapilanlar, &$hatalar): void {
        try {
            if ($is() !== false) {
                $yapilanlar[] = $aciklama;
            }
        } catch (PDOException $e) {
            $hatalar[] = $aciklama . ': ' . $e->getMessage();
            error_log('[valentra] sema adimi basarisiz — ' . $aciklama . ': ' . $e->getMessage());
        }
    };

    $sutunEkle = static function (string $tablo, string $sutun, string $tanim) use ($adim): void {
        $adim($tablo . '.' . $sutun . ' sutunu', static function () use ($tablo, $sutun, $tanim): bool {
            if (sema_sutun_var($tablo, $sutun)) {
                return false;
            }

            db()->exec('ALTER TABLE ' . $tablo . ' ADD COLUMN ' . $sutun . ' ' . $tanim);

            return true;
        });
    };

    $sutunEkle('haberler', 'kategori_id', 'INT UNSIGNED NULL AFTER one_cikan');
    $sutunEkle('haberler', 'iframe_url', 'VARCHAR(1000) NULL AFTER gorsel_url');
    $sutunEkle('kategoriler', 'ust_id', 'INT UNSIGNED NULL AFTER aciklama');
    $sutunEkle('kaynaklar', 'liste_url', 'VARCHAR(500) NULL AFTER besleme_url');
    $sutunEkle('kaynaklar', 'liste_secici', 'VARCHAR(200) NULL AFTER besleme_url');

    // kaynaklar.besleme_url benzersiz olmali; yoksa sema her
    // calistirildiginda INSERT IGNORE kopya kayit uretir.
    $adim('kaynaklar benzersizlik kisiti', static function (): bool {
        if (sema_indeks_var('kaynaklar', 'uq_kaynak_besleme')) {
            return false;
        }

        // Kisiti ekleyebilmek icin once mevcut kopyalari temizle
        // (her besleme adresinden en eskisini birak).
        db()->exec(
            'DELETE k FROM kaynaklar k
               JOIN kaynaklar digeri
                 ON digeri.besleme_url = k.besleme_url AND digeri.id < k.id'
        );

        // utf8mb4 + eski InnoDB satir bicimlerinde 500 karakterlik
        // indeks 767 bayt sinirini asabilir. 190 karakterlik on ek
        // 760 baytta kalir ve paylasimli hostinglerle uyumludur.
        db()->exec('ALTER TABLE kaynaklar ADD UNIQUE KEY uq_kaynak_besleme (besleme_url(190))');

        return true;
    });

    // RSS'i olmayan kaynaklarin besleme_url alani NULL kalir ve MySQL
    // birden fazla NULL'a izin verir; ad kisiti olmadan bu kaynaklar
    // sema her calistiginda yeniden eklenirdi.
    $adim('kaynak adi benzersizlik kisiti', static function (): bool {
        if (sema_indeks_var('kaynaklar', 'uq_kaynak_ad')) {
            return false;
        }

        // Ayni adli kopyalardan en eskisini birak.
        db()->exec(
            'DELETE k FROM kaynaklar k
               JOIN kaynaklar digeri
                 ON digeri.ad = k.ad AND digeri.id < k.id'
        );

        // ad VARCHAR(160): utf8mb4'te 640 bayt, 767 bayt sinirinin
        // altinda kaldigi icin on ek gerekmiyor.
        db()->exec('ALTER TABLE kaynaklar ADD UNIQUE KEY uq_kaynak_ad (ad)');

        return true;
    });

    $adim('kategori indeksi', static function (): bool {
        if (sema_indeks_var('haberler', 'ix_haber_kategori')) {
            return false;
        }

        db()->exec('ALTER TABLE haberler ADD INDEX ix_haber_kategori (kategori_id, durum, yayin_tarihi)');

        return true;
    });

    $adim('kategori yabanci anahtari', static function (): bool {
        // Yabanci anahtar STATISTICS'te degil TABLE_CONSTRAINTS'te durur.
        // Indeks tablosuna bakmak kisiti goremeyip her calismada yeniden
        // olusturmayi denemeye ve kalici hata mesajina yol aciyordu.
        if (sema_kisit_var('haberler', 'fk_haber_kategori')) {
            return false;
        }

        db()->exec(
            'ALTER TABLE haberler ADD CONSTRAINT fk_haber_kategori
             FOREIGN KEY (kategori_id) REFERENCES kategoriler (id) ON DELETE SET NULL'
        );

        return true;
    });

    return $yapilanlar;
}

/** Yabanci anahtar gibi tablo kisitlari icin. */
function sema_kisit_var(string $tablo, string $kisit): bool
{
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = :tablo
            AND CONSTRAINT_NAME = :kisit'
    );
    $ifade->execute(['tablo' => $tablo, 'kisit' => $kisit]);

    return (int) $ifade->fetchColumn() > 0;
}

function sema_sutun_var(string $tablo, string $sutun): bool
{
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tablo AND COLUMN_NAME = :sutun'
    );
    $ifade->execute(['tablo' => $tablo, 'sutun' => $sutun]);

    return (int) $ifade->fetchColumn() > 0;
}

function sema_indeks_var(string $tablo, string $indeks): bool
{
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tablo AND INDEX_NAME = :indeks'
    );
    $ifade->execute(['tablo' => $tablo, 'indeks' => $indeks]);

    return (int) $ifade->fetchColumn() > 0;
}

/**
 * Sema guncel mi? (panelde uyari gostermek icin)
 *
 * Tablolarin varligini degil, sonradan eklenen sutunlari kontrol eder;
 * eksik sutun tipik olarak "veritabani guncellenmemis" demektir.
 */
function sema_guncel_mi(): bool
{
    try {
        $beklenenSutunlar = [
            ['haberler', 'kategori_id'],
            ['haberler', 'iframe_url'],
            ['kategoriler', 'ust_id'],
            ['kaynaklar', 'liste_url'],
            ['kaynaklar', 'liste_secici'],
        ];

        foreach ($beklenenSutunlar as [$tablo, $sutun]) {
            if (!sema_sutun_var($tablo, $sutun)) {
                return false;
            }
        }
    } catch (PDOException $e) {
        return false;
    }

    return true;
}

/**
 * Semanin ayrintili durumu: hangi sutun ve indeks var, hangisi yok.
 *
 * "Guncelleme gerekiyor" uyarisi surekli donuyorsa neyin takildigini
 * tahmin etmek yerine panelde gormek icin.
 *
 * @return array{tablolar:array<string,bool>,sutunlar:array<string,bool>,indeksler:array<string,bool>}
 */
function sema_ayrintili_durum(): array
{
    $sutunlar  = [];
    $indeksler = [];

    $beklenenSutunlar = [
        'haberler.kategori_id'   => ['haberler', 'kategori_id'],
        'haberler.iframe_url'    => ['haberler', 'iframe_url'],
        'kategoriler.ust_id'     => ['kategoriler', 'ust_id'],
        'kaynaklar.liste_url'    => ['kaynaklar', 'liste_url'],
        'kaynaklar.liste_secici' => ['kaynaklar', 'liste_secici'],
    ];

    foreach ($beklenenSutunlar as $ad => [$tablo, $sutun]) {
        try {
            $sutunlar[$ad] = sema_sutun_var($tablo, $sutun);
        } catch (PDOException $e) {
            $sutunlar[$ad] = false;
        }
    }

    foreach ([
        'kaynaklar.uq_kaynak_besleme' => ['kaynaklar', 'uq_kaynak_besleme'],
        'kaynaklar.uq_kaynak_ad'      => ['kaynaklar', 'uq_kaynak_ad'],
        'haberler.ix_haber_kategori'  => ['haberler', 'ix_haber_kategori'],
    ] as $ad => [$tablo, $indeks]) {
        try {
            $indeksler[$ad] = sema_indeks_var($tablo, $indeks);
        } catch (PDOException $e) {
            $indeksler[$ad] = false;
        }
    }

    return [
        'tablolar'  => sema_durumu(),
        'sutunlar'  => $sutunlar,
        'indeksler' => $indeksler,
    ];
}
