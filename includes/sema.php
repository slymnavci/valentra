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

    return ['tamam' => sema_hazir(), 'calisan' => $calisan, 'mesaj' => ''];
}
