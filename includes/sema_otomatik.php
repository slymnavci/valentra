<?php
declare(strict_types=1);

/**
 * Şema dosyası değiştiğinde veritabanını kendiliğinden günceller.
 *
 * Sorun: sema degisiklikleri veritabanina ancak panelden elle
 * "semayi guncelle" denince iniyordu. Kod deploy ile gidiyor ama
 * veritabani eski kaliyordu; menu kategoriler tablosundan geldigi
 * icin yeni menu gorunmuyor, yeni kaynaklar taranmiyordu. Kullanici
 * "hala duzelmedi" diyor, sebep goze carpmiyor.
 *
 * Mevcut sema_guncel_mi() bunu yakalayamiyor: yalnizca belirli
 * sutunlarin varligina bakiyor, veri degisikligini (kategori agaci,
 * kaynak listesi) hic gormuyor.
 *
 * Cozum: sema dosyasinin sha256 imzasi ayarlarda saklaniyor. Dosya
 * degisince imza tutmaz ve sonraki ilk istekte yukseltme bir kez
 * calisir. sema_kur zaten yinelemeye dayanikli.
 */

const SEMA_IMZA_ANAHTAR  = 'sema_imza';
const SEMA_KILIT_ANAHTAR = 'sema_kilit';

/** Şema dosyasının yolu. */
function sema_dosyasi(): string
{
    return dirname(__DIR__) . '/sql/schema.sql';
}

/**
 * Gerekiyorsa şemayı yükseltir.
 *
 * Her istekte cagriliyor ama maliyeti tek bir kucuk SELECT: imza
 * tutuyorsa hemen donuyor. Ancak imza degistiginde sema.php yukleniyor.
 */
function sema_otomatik_yukselt(): void
{
    $dosya = sema_dosyasi();

    if (!is_file($dosya)) {
        return;
    }

    $imza = hash_file('sha256', $dosya);

    if ($imza === false) {
        return;
    }

    try {
        $mevcut = sema_ayar_oku(SEMA_IMZA_ANAHTAR);

        if ($mevcut === $imza) {
            return;
        }

        /*
         * Kilit: iki istek ayni anda gelirse ikisi de yukseltmeye
         * kalkmasin. sema_kur yinelemeye dayanikli oldugu icin ayni
         * anda calismalari veriyi bozmaz, ama bosuna is ve gereksiz
         * kilitlenme uretir.
         *
         * Kilit zaman damgasi; iki dakikadan eskiyse dusmus sayilir ve
         * yukseltme tekrar denenir. Boylece yarida kalan bir istek
         * yukseltmeyi kalici olarak engellemiyor.
         */
        $kilit = (int) sema_ayar_oku(SEMA_KILIT_ANAHTAR);

        if ($kilit > time() - 120) {
            return;
        }

        sema_ayar_yaz(SEMA_KILIT_ANAHTAR, (string) time());

        require_once __DIR__ . '/sema.php';

        $sonuc = sema_kur($dosya);

        // Imza yalnizca basarili yukseltmede yazilir; basarisizsa
        // sonraki istekte tekrar denenir.
        if ($sonuc['tamam']) {
            sema_ayar_yaz(SEMA_IMZA_ANAHTAR, $imza);
            error_log('[valentra] sema kendiliginden guncellendi (' . $sonuc['calisan'] . ' ifade).');
        } else {
            error_log('[valentra] otomatik sema yukseltmesi basarisiz: ' . $sonuc['mesaj']);
        }

        sema_ayar_sil(SEMA_KILIT_ANAHTAR);
    } catch (Throwable $e) {
        // Ilk kurulumda ayarlar tablosu henuz yok; bu normal.
        // Baska bir hata da sayfayi dusurmemeli.
        error_log('[valentra] otomatik sema kontrolu atlandi: ' . $e->getMessage());
    }
}

/*
 * Ayar okuma/yazma burada ayrica tanimli.
 *
 * includes/ayarlar.php'yi her istekte yuklememek icin: bu dosya
 * bootstrap'tan cagriliyor ve cogu istekte tek bir SELECT disinda
 * hicbir sey yapmiyor.
 */
function sema_ayar_oku(string $anahtar): string
{
    $ifade = db()->prepare('SELECT deger FROM ayarlar WHERE anahtar = :a LIMIT 1');
    $ifade->execute(['a' => $anahtar]);
    $deger = $ifade->fetchColumn();

    return $deger === false ? '' : (string) $deger;
}

function sema_ayar_yaz(string $anahtar, string $deger): void
{
    db()->prepare(
        'INSERT INTO ayarlar (anahtar, deger) VALUES (:a, :d)
         ON DUPLICATE KEY UPDATE deger = VALUES(deger)'
    )->execute(['a' => $anahtar, 'd' => $deger]);
}

function sema_ayar_sil(string $anahtar): void
{
    db()->prepare('DELETE FROM ayarlar WHERE anahtar = :a')->execute(['a' => $anahtar]);
}
