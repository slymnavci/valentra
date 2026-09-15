<?php
declare(strict_types=1);

/**
 * Merkezi hata yakalama.
 *
 * Yakalanmayan bir istisna kullanıcıya boş bir 500 sayfası olarak
 * dönmemeli. İki durumu ayırırız:
 *
 *   - Tablolar henüz yok  -> "kurulum gerekli" sayfası (yönlendirici)
 *   - Diğer her şey       -> nötr hata sayfası; ayrıntı sunucu günlüğüne
 *                            yazılır, tarayıcıya asla basılmaz.
 */

/** Eksik tablo hatası mı? (MySQL: 42S02 / 1146) */
function hata_tablo_eksik(Throwable $e): bool
{
    return hata_sqlstate_mi($e, '42S02', 1146);
}

/** Eksik sütun hatası mı? (MySQL: 42S22 / 1054) */
function hata_sutun_eksik(Throwable $e): bool
{
    return hata_sqlstate_mi($e, '42S22', 1054);
}

function hata_sqlstate_mi(Throwable $e, string $durum, int $surucuKodu): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    if ($e->getCode() === $durum) {
        return true;
    }

    return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === $surucuKodu;
}

/**
 * Sema eksikse kendini onarmayi dener.
 *
 * Site guncellendiginde yeni bir sutun gerekebilir; kurulum.php ilk hesap
 * olustuktan sonra kapandigi icin guncelleme baska turlu uygulanamaz ve
 * ziyaretci hata sayfasi gorur. Burada semayi bir kez uygulayip ayni
 * adrese geri donuyoruz.
 *
 * Yalnizca GET isteklerinde ve bir kez denenir: adrese eklenen isaret
 * sonsuz donguyu onler, POST verisi yonlendirmede kaybolacagi icin
 * form gonderimlerinde denenmez.
 */
function hata_semayi_onar(Throwable $e): bool
{
    if (!hata_tablo_eksik($e) && !hata_sutun_eksik($e)) {
        return false;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }

    if (isset($_GET['sema_onarildi'])) {
        return false;
    }

    $semaDosyasi = dirname(__DIR__) . '/sql/schema.sql';

    if (!is_file($semaDosyasi)) {
        return false;
    }

    require_once __DIR__ . '/sema.php';

    try {
        $sonuc = sema_kur($semaDosyasi);
    } catch (Throwable $onarimHatasi) {
        error_log('[valentra] sema onarimi basarisiz: ' . $onarimHatasi->getMessage());

        return false;
    }

    if (empty($sonuc['tamam'])) {
        return false;
    }

    error_log('[valentra] sema otomatik guncellendi (' . $sonuc['calisan'] . ' islem)');

    $hedef = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $ayrac = str_contains($hedef, '?') ? '&' : '?';

    header('Location: ' . $hedef . $ayrac . 'sema_onarildi=1', true, 302);

    return true;
}

function hata_sayfasi_bas(string $baslik, string $mesaj, string $baglantiMetni = '', string $baglanti = ''): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    $e = static fn (string $d): string => htmlspecialchars($d, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($baslik) ?> — Valentra</title>
    <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e_varlik('/assets/admin.css') ?>">
</head>
<body>
    <div class="giris-sayfa">
        <div class="giris-kutusu" style="max-width:480px;">
            <div class="logo">
                <img src="/assets/logo.svg" alt="" width="54" height="47">
                <span class="ad">VALENTRA</span>
            </div>

            <div class="kutu" style="margin-top:18px;text-align:center;">
                <h1 style="margin:0 0 10px;font-size:1.15rem;"><?= $e($baslik) ?></h1>
                <p style="margin:0;color:var(--metin-soluk);"><?= $e($mesaj) ?></p>

                <?php if ($baglanti !== ''): ?>
                    <a class="dugme dugme-ana" href="<?= $e($baglanti) ?>"
                       style="width:100%;margin-top:18px;"><?= $e($baglantiMetni) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
}

/**
 * Yakalanmayan istisnaları karşılar.
 */
function hata_yakala(Throwable $e): void
{
    // Gerçek hata yalnızca sunucu günlüğüne.
    error_log('[valentra] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Once kendini onarmayi dene: eksik sutun/tablo ise semayi uygula.
    if (!headers_sent() && hata_semayi_onar($e)) {
        return;
    }

    if (hata_tablo_eksik($e)) {
        http_response_code(503);
        hata_sayfasi_bas(
            'Site henüz kurulmadı',
            'Veritabanı bağlantısı çalışıyor ancak tablolar oluşturulmamış. '
                . 'Kurulum sayfasından tabloları oluşturun.',
            'Kuruluma git',
            '/kurulum.php'
        );
        return;
    }

    http_response_code(500);
    hata_sayfasi_bas(
        'Bir hata oluştu',
        'İstek şu anda işlenemiyor. Sorun sürerse site yöneticisine bildirin.'
    );
}

set_exception_handler('hata_yakala');
