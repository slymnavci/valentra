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

    $calisan = 0;

    foreach (sema_ifadeleri($sql) as $ifade) {
        try {
            db()->exec($ifade);
            $calisan++;
        } catch (PDOException $e) {
            return [
                'tamam'   => false,
                'calisan' => $calisan,
                'mesaj'   => 'Tablo oluşturulamadı: ' . $e->getMessage(),
            ];
        }
    }

    // Daha once kurulmus bir veritabaninda eksik sutunlari tamamla.
    if (sema_hazir()) {
        $calisan += count(sema_yukselt());
    }

    return ['tamam' => sema_hazir(), 'calisan' => $calisan, 'mesaj' => ''];
}

/**
 * Var olan kurulumlari gunceller.
 *
 * "CREATE TABLE IF NOT EXISTS" yeni bir sutunu eklemez; daha once kurulmus
 * bir veritabani icin eksik sutunlari burada tamamliyoruz. Her adim once
 * varligi kontrol eder, bu yuzden tekrar calistirmak zararsizdir.
 *
 * @return list<string> Uygulanan degisikliklerin aciklamalari
 */
function sema_yukselt(): array
{
    $yapilanlar = [];

    if (!sema_sutun_var('haberler', 'kategori_id')) {
        db()->exec('ALTER TABLE haberler ADD COLUMN kategori_id INT UNSIGNED NULL AFTER one_cikan');
        $yapilanlar[] = 'haberler.kategori_id sutunu eklendi';
    }

    if (!sema_indeks_var('haberler', 'ix_haber_kategori')) {
        db()->exec('ALTER TABLE haberler ADD INDEX ix_haber_kategori (kategori_id, durum, yayin_tarihi)');
        $yapilanlar[] = 'kategori indeksi eklendi';
    }

    if (!sema_indeks_var('haberler', 'fk_haber_kategori')) {
        try {
            db()->exec(
                'ALTER TABLE haberler ADD CONSTRAINT fk_haber_kategori
                 FOREIGN KEY (kategori_id) REFERENCES kategoriler (id) ON DELETE SET NULL'
            );
            $yapilanlar[] = 'kategori yabanci anahtari eklendi';
        } catch (PDOException $e) {
            // Yabanci anahtar kurulamazsa uygulama yine calisir; sema_kur
            // bunu hata saymaz.
        }
    }

    return $yapilanlar;
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
