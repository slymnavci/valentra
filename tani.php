<?php
declare(strict_types=1);

/**
 * Veritabanı bağlantı teşhisi.
 *
 * includes/database.php yalnızca "Veritabani baglantisi kurulamadi." yazıp
 * çıktığı için sebep görünmüyor. Bu sayfa bağlantıyı kendisi deneyip hatayı
 * sınıflandırır ve çözümü söyler.
 *
 * Parola hiçbir koşulda gösterilmez; kullanıcı adı ve veritabanı adı
 * maskelenir. Bağlantı çalışıyorken sayfa hiçbir ayrıntı vermez.
 */

mb_internal_encoding('UTF-8');

/** Bu sayfa bootstrap'a bağlı olmadan çalışır; kendi kaçış yardımcısını kullanır. */
function e_tani(?string $d): string
{
    return htmlspecialchars($d ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Değeri baştan birkaç karakter bırakıp maskeler. */
function maskele(string $deger): string
{
    $uzunluk = mb_strlen($deger, 'UTF-8');

    if ($uzunluk === 0) {
        return '(boş)';
    }

    if ($uzunluk <= 3) {
        return str_repeat('•', $uzunluk);
    }

    return mb_substr($deger, 0, 2, 'UTF-8') . str_repeat('•', min($uzunluk - 2, 8));
}

/**
 * Ayar dosyasından bilgileri, bağlanmayı denemeden okur.
 *
 * @return array{host:string,name:string,user:string,pass:string}|null
 */
function ayarlari_oku(string $dosya): ?array
{
    if (!is_file($dosya)) {
        return null;
    }

    $icerik = file_get_contents($dosya);

    if ($icerik === false) {
        return null;
    }

    $al = static function (string $degisken) use ($icerik): string {
        // base64_decode('...') ya da doğrudan '...' biçimini destekler
        if (preg_match('/\$' . $degisken . "\s*=\s*base64_decode\('([^']*)'\)/", $icerik, $m)) {
            return (string) base64_decode($m[1], true);
        }

        if (preg_match('/\$' . $degisken . "\s*=\s*'([^']*)'/", $icerik, $m)) {
            return $m[1];
        }

        return '';
    };

    return [
        'host' => $al('dbHost'),
        'name' => $al('dbName'),
        'user' => $al('dbUser'),
        'pass' => $al('dbPass'),
    ];
}

$ayarDosyasi = __DIR__ . '/includes/database.php';
$ayarlar     = ayarlari_oku($ayarDosyasi);

$durum   = 'bilinmiyor';
$baslik  = '';
$aciklama = '';
$cozum   = [];
$kod     = '';

if (!is_file($ayarDosyasi)) {
    $durum   = 'hata';
    $baslik  = 'Ayar dosyası sunucuda yok';
    $aciklama = 'includes/database.php bulunamadı. Deploy adımı bu dosyayı üretir.';
    $cozum   = ['GitHub Actions deploy işinin başarıyla tamamlandığını kontrol edin.'];

} elseif ($ayarlar === null || $ayarlar['host'] === '') {
    $durum   = 'hata';
    $baslik  = 'Ayar dosyası okunamadı';
    $aciklama = 'includes/database.php var ama içindeki bağlantı bilgileri çözümlenemedi.';
    $cozum   = ['Deploy adımındaki dosya üretimi beklenen biçimde çalışmamış olabilir.'];

} else {
    try {
        new PDO(
            "mysql:host={$ayarlar['host']};dbname={$ayarlar['name']};charset=utf8mb4",
            $ayarlar['user'],
            $ayarlar['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );

        $durum  = 'tamam';
        $baslik = 'Veritabanı bağlantısı çalışıyor';

    } catch (PDOException $e) {
        $durum = 'hata';
        $kod   = (string) $e->getCode();

        // Sürücü hata numarası (1045, 1049, 2002 ...)
        $surucuKodu = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

        if ($surucuKodu === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
            $surucuKodu = (int) $m[1];
        }

        switch ($surucuKodu) {
            case 1045:
                $baslik  = 'Kullanıcı adı veya parola hatalı';
                $aciklama = 'Sunucuya ulaşıldı ama giriş reddedildi.';
                $cozum   = [
                    'IHS panelindeki veritabanı kullanıcı adı ve parolasını doğrulayın.',
                    'GitHub → Settings → Secrets bölümünde DB_USERNAME ve DB_PASSWORD değerlerini güncelleyin.',
                    'Parolada başta/sonda boşluk kalmadığından emin olun.',
                ];
                break;

            case 1049:
                $baslik  = 'Veritabanı bulunamadı';
                $aciklama = 'Giriş başarılı ama bu adda bir veritabanı yok.';
                $cozum   = [
                    'IHS panelinden veritabanını oluşturun.',
                    'DB_NAME secret değerinin paneldeki adla birebir aynı olduğunu kontrol edin (genelde kullanıcıadı_veritabani biçimindedir).',
                ];
                break;

            case 2002:
            case 2003:
                $baslik  = 'Veritabanı sunucusuna ulaşılamıyor';
                $aciklama = 'Verilen adreste MySQL sunucusu yanıt vermedi.';
                $cozum   = [
                    'Paylaşımlı hostingde DB_HOST genellikle localhost olmalıdır.',
                    'IHS panelinde belirtilen sunucu adresini birebir kullanın.',
                ];
                break;

            case 1044:
                $baslik  = 'Veritabanına erişim reddedildi';
                $aciklama = 'Kullanıcı doğrulandı ama bu veritabanına erişemiyor. '
                          . 'Ya yetki verilmemiş ya da veritabanı adı yanlış.';
                $cozum   = [
                    'IHS panelinde kullanıcıyı veritabanına ekleyip tüm yetkileri verin.',
                    'DB_NAME secret değerinin paneldeki adla birebir aynı olduğunu kontrol edin '
                        . '(genelde kullanıcıadı_veritabani biçimindedir).',
                ];
                break;

            default:
                $baslik  = 'Bağlantı kurulamadı';
                $aciklama = 'Sürücü hata numarası: ' . ($surucuKodu !== 0 ? $surucuKodu : 'bilinmiyor');
                $cozum   = [
                    'DB_HOST, DB_NAME, DB_USERNAME ve DB_PASSWORD secret değerlerini IHS panelindekilerle karşılaştırın.',
                ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Bağlantı Teşhisi — Valentra</title>
    <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <div class="giris-sayfa">
        <div class="giris-kutusu" style="max-width:520px;">
            <div class="logo">
                <img src="/assets/logo.svg" alt="" width="54" height="47">
                <span class="ad">VALENTRA</span>
            </div>
            <div class="aciklama">Veritabanı Bağlantı Teşhisi</div>

            <?php if ($durum === 'tamam'): ?>
                <div class="uyari uyari-basari">
                    <strong><?= e_tani($baslik) ?></strong>
                    Kuruluma geçebilirsiniz.
                </div>
                <div class="kutu">
                    <a class="dugme dugme-ana" href="/kurulum.php" style="width:100%;">Kuruluma git</a>
                    <p class="ipucu" style="margin:14px 0 0;">
                        Bağlantı çalıştığına göre bu dosyaya artık ihtiyaç yok;
                        <code>tani.php</code> dosyasını sunucudan silebilirsiniz.
                    </p>
                </div>

            <?php else: ?>
                <div class="uyari uyari-hata">
                    <strong><?= e_tani($baslik) ?></strong>
                    <?= e_tani($aciklama) ?>
                </div>

                <?php if ($cozum !== []): ?>
                    <div class="kutu">
                        <h2 style="margin:0 0 12px;font-size:1rem;">Ne yapmalı</h2>
                        <ol style="margin:0;padding-left:20px;">
                            <?php foreach ($cozum as $adim): ?>
                                <li style="margin-bottom:8px;"><?= e_tani($adim) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endif; ?>

                <?php if ($ayarlar !== null): ?>
                    <div class="kutu">
                        <h2 style="margin:0 0 12px;font-size:1rem;">Sunucuya ulaşan değerler</h2>
                        <p class="ipucu" style="margin:0 0 10px;">
                            Parola hiçbir koşulda gösterilmez. Diğer değerler maskelenmiştir;
                            amaç secret'ın boş mu, yanlış mı geldiğini görmektir.
                        </p>
                        <div class="satir-bilgi">
                            <span>DB_HOST: <strong><?= e_tani($ayarlar['host'] !== '' ? $ayarlar['host'] : '(boş)') ?></strong></span>
                        </div>
                        <div class="satir-bilgi">
                            <span>DB_NAME: <strong><?= e_tani(maskele($ayarlar['name'])) ?></strong></span>
                        </div>
                        <div class="satir-bilgi">
                            <span>DB_USERNAME: <strong><?= e_tani(maskele($ayarlar['user'])) ?></strong></span>
                        </div>
                        <div class="satir-bilgi">
                            <span>DB_PASSWORD: <strong><?= $ayarlar['pass'] === '' ? 'BOŞ — secret ulaşmamış' : 'dolu (' . mb_strlen($ayarlar['pass'], 'UTF-8') . ' karakter)' ?></strong></span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
