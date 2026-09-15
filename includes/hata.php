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
    if (!$e instanceof PDOException) {
        return false;
    }

    if ($e->getCode() === '42S02') {
        return true;
    }

    return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1146;
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
    <link rel="stylesheet" href="/assets/admin.css">
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
