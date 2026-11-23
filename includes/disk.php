<?php
declare(strict_types=1);

/**
 * DISK KULLANIMI VE BAKIM (23.11.2026).
 *
 * Site sahibi: "Valentra diski doluyor, neden?" Sunucuya yazan yerler:
 *   - gorseller/haber: kaynaklardan indirilen haber gorselleri ve 480/960/1440
 *     kopyalari. Hic silinmiyordu; haber eskise, reddedilse ya da gorseli
 *     degisse de dosya kaliyordu (en buyuk aday).
 *   - PHP hata gunlukleri (error_log): paylasimli sunucuda klasorlerde birikir.
 *   - Veritabani (paylasimli sunucuda kotaya sayilir): ziyaretler, haberler...
 *   - onbellek/: sayfa onbellegi kendini temizliyor; digerleri kucuk.
 *
 * Google denetimi "Disk kullanimi" bolumunde bunlari olcuyor
 * (disk_olcumu). Bakim (disk_bakim) alti saatte bir, ana sayfa yanitindan
 * sonra calisiyor:
 *   - son DISK_GORSEL_SAKLAMA_GUN gunun yayindaki haberlerine ait olmayan
 *     ve DISK_GORSEL_EN_GENC_GUN gunden eski gorsel dosyalari silinir. Eski
 *     bir habere girilirse gorsel gorsel.php ile yeniden indirilir;
 *   - DISK_GUNLUK_SINIR'i asan error_log dosyasinin yalnizca son
 *     DISK_GUNLUK_KALAN bayti tutulur.
 * Bir seferde en fazla DISK_SILME_TAVANI dosya silinir (ilk temizlik
 * birkac turda biter; sunucu tek seferde yorulmasin).
 */

/*
 * 7 gun (23.11.2026): ilk olcumde gorseller/haber 4,1 GB / 40.793 dosya,
 * disk 5 GB'nin 4,69'unda. Haberlerin cogu son bir ayda eklendigi icin 30
 * gun neredeyse hicbir sey sildirmiyordu. Eski habere girilirse gorsel
 * yeniden iner (o zaman 3 gun "yeni" sayilir).
 */
const DISK_GORSEL_SAKLAMA_GUN = 7;
const DISK_GORSEL_EN_GENC_GUN = 3;
const DISK_SILME_TAVANI       = 5000;
const DISK_GUNLUK_SINIR       = 20 * 1024 * 1024;
const DISK_GUNLUK_KALAN       = 1024 * 1024;
const DISK_BAKIM_ARALIK       = 3600;

/** Klasorun toplam boyutu ve dosya sayisi (alt klasorler dahil; baglantilar izlenmez). @return array{0:int,1:int} */
function disk_klasor_boyutu(string $klasor): array
{
    if (!is_dir($klasor)) {
        return [0, 0];
    }

    $bayt = $adet = 0;
    $gezgin = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($klasor, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    foreach ($gezgin as $dosya) {
        if ($dosya->isFile() && !$dosya->isLink()) {
            $bayt += (int) $dosya->getSize();
            $adet++;
        }
    }

    return [$bayt, $adet];
}

/**
 * Site klasorundeki hata gunlukleri (error_log, *.log). Gorsel ve onbellek
 * klasorlerine girilmez (on binlerce dosya; gunluk olmaz).
 *
 * @return list<array{yol:string,bayt:int}>
 */
function disk_gunlukleri(): array
{
    $kok     = dirname(__DIR__);
    $atla    = ['gorseller', 'onbellek', '.git', 'node_modules', 'vendor'];
    $bulunan = [];
    $gezgin  = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), $atla, true)) && !$f->isLink()
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    foreach ($gezgin as $dosya) {
        $ad = $dosya->getFilename();

        if ($dosya->isFile() && ($ad === 'error_log' || str_ends_with($ad, '.log'))) {
            $bulunan[] = ['yol' => substr($dosya->getPathname(), strlen($kok)), 'bayt' => (int) $dosya->getSize()];
        }
    }

    usort($bulunan, static fn (array $a, array $b): int => $b['bayt'] <=> $a['bayt']);

    return $bulunan;
}

/** Veritabani tablolari, buyukten kucuge. @return list<array{tablo:string,bayt:int,satir:int}> */
function disk_vt_tablolari(): array
{
    try {
        $satirlar = db()->query(
            'SELECT table_name AS tablo, COALESCE(data_length, 0) + COALESCE(index_length, 0) AS bayt, COALESCE(table_rows, 0) AS satir
               FROM information_schema.tables WHERE table_schema = DATABASE()
              ORDER BY bayt DESC'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    return array_map(static fn (array $s): array => ['tablo' => (string) $s['tablo'], 'bayt' => (int) $s['bayt'], 'satir' => (int) $s['satir']], $satirlar);
}

/** "1,2 GB" */
function disk_boyut_yaz(int $bayt): string
{
    foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $birim => $bolen) {
        if ($bayt >= $bolen) {
            return number_format($bayt / $bolen, 1, ',', '.') . ' ' . $birim;
        }
    }

    return $bayt . ' B';
}

/**
 * Olcum: klasorler, gunlukler, veritabani.
 *
 * @return array{klasorler:array<string,array{0:int,1:int}>,gunlukler:list<array{yol:string,bayt:int}>,tablolar:list<array{tablo:string,bayt:int,satir:int}>}
 */
function disk_olcumu(): array
{
    $kok = dirname(__DIR__);
    $klasorler = [];

    foreach (['gorseller/haber', 'gorseller/uye', 'gorseller/yazar', 'gorseller/gazete', 'onbellek', 'ajan/onbellek'] as $k) {
        $klasorler[$k] = disk_klasor_boyutu($kok . '/' . $k);
    }

    return ['klasorler' => $klasorler, 'gunlukler' => disk_gunlukleri(), 'tablolar' => disk_vt_tablolari()];
}

/**
 * Kullanilmayan haber gorsellerini siler.
 *
 * Dosya adi gorsel adresinin sha1 ozeti (+ "-480" gibi kopya eki). Son
 * $gun gunun yayindaki haberlerinin gorselleri ve $enGenc gunden yeni
 * dosyalar korunur. Ozetle baslamayan dosyalar (index.html, .htaccess)
 * hicbir zaman silinmez.
 *
 * @return array{silinen:int,bayt:int,korunan:int,tavan:bool}
 */
function disk_gorselleri_temizle(int $gun = DISK_GORSEL_SAKLAMA_GUN, int $enGenc = DISK_GORSEL_EN_GENC_GUN, int $tavan = DISK_SILME_TAVANI, int $saniye = 0): array
{
    $bitis = $saniye > 0 ? microtime(true) + $saniye : 0.0;

    require_once __DIR__ . '/gorsel_onbellek.php';

    $sonuc  = ['silinen' => 0, 'bayt' => 0, 'korunan' => 0, 'tavan' => false];
    $klasor = haber_gorsel_klasoru();

    if (!is_dir($klasor)) {
        return $sonuc;
    }

    $koru = [];
    $ifade = db()->prepare(
        "SELECT gorsel_url FROM haberler
          WHERE durum = 'yayinda' AND yayin_tarihi >= NOW() - INTERVAL :g DAY AND COALESCE(gorsel_url, '') <> ''"
    );
    $ifade->execute(['g' => max(1, $gun)]);

    foreach ($ifade->fetchAll(PDO::FETCH_COLUMN) as $url) {
        $koru[sha1(guvenli_url((string) $url))] = true;
        $koru[sha1((string) $url)] = true;
    }

    $sinir = time() - max(0, $enGenc) * 86400;

    foreach (new DirectoryIterator($klasor) as $dosya) {
        if (!$dosya->isFile() || $dosya->isLink() || preg_match('/^([0-9a-f]{40})[-.]/', $dosya->getFilename(), $m) !== 1) {
            continue;
        }

        if (isset($koru[$m[1]]) || $dosya->getMTime() > $sinir) {
            $sonuc['korunan']++;
            continue;
        }

        if ($sonuc['silinen'] >= $tavan || ($bitis > 0 && microtime(true) > $bitis)) {
            $sonuc['tavan'] = true;
            break;
        }

        $bayt = (int) $dosya->getSize();

        if (@unlink($dosya->getPathname())) {
            $sonuc['silinen']++;
            $sonuc['bayt'] += $bayt;
        }
    }

    return $sonuc;
}

/** Sinir asan error_log dosyalarinin yalnizca sonunu tutar. @return array{kirpilan:int,bayt:int} */
function disk_gunlukleri_kirp(int $sinir = DISK_GUNLUK_SINIR, int $kalan = DISK_GUNLUK_KALAN): array
{
    $sonuc = ['kirpilan' => 0, 'bayt' => 0];
    $kok   = dirname(__DIR__);

    foreach (disk_gunlukleri() as $g) {
        if ($g['bayt'] <= $sinir) {
            continue;
        }

        $yol = $kok . $g['yol'];
        $f   = @fopen($yol, 'r');

        if ($f === false) {
            continue;
        }

        fseek($f, -$kalan, SEEK_END);
        $son = (string) stream_get_contents($f);
        fclose($f);

        if (@file_put_contents($yol, "[valentra] gunluk kirpildi " . date('Y-m-d H:i:s') . "; onceki boyut " . $g['bayt'] . " bayt\n" . $son, LOCK_EX) !== false) {
            $sonuc['kirpilan']++;
            $sonuc['bayt'] += $g['bayt'] - $kalan;
        }
    }

    return $sonuc;
}

/**
 * Bakim: gorseller ve gunlukler; sonucu "disk_bakim_sonuc" ayarina yazar.
 * $tavan/$saniye: Google denetimi (elle tetiklenebilir) daha cok siler ama
 * sure siniriyla.
 *
 * @return array<string,mixed>
 */
function disk_bakim(int $tavan = DISK_SILME_TAVANI, int $saniye = 0): array
{
    require_once __DIR__ . '/ayarlar.php';

    $gorsel = disk_gorselleri_temizle(DISK_GORSEL_SAKLAMA_GUN, DISK_GORSEL_EN_GENC_GUN, $tavan, $saniye);
    $gunluk = disk_gunlukleri_kirp();
    $sonuc  = ['zaman' => date('Y-m-d H:i:s'), 'gorsel' => $gorsel, 'gunluk' => $gunluk];

    ayar_yaz('disk_bakim_sonuc', (string) json_encode($sonuc));

    return $sonuc;
}

/** Alti saatte bir, ana sayfa yanitindan sonra bakim. */
function disk_bakim_sayfa_sonunda(): void
{
    require_once __DIR__ . '/ayarlar.php';

    if (time() - (int) ayar_oku('disk_bakim_son', '0') < DISK_BAKIM_ARALIK) {
        return;
    }

    // Once damga: ayni anda gelen istekler bakimi ikinci kez baslatmasin.
    ayar_yaz('disk_bakim_son', (string) time());

    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        ignore_user_abort(true);
        @set_time_limit(120);

        try {
            disk_bakim();
        } catch (Throwable $e) {
            error_log('[valentra] disk bakimi: ' . $e->getMessage());
        }
    });
}
