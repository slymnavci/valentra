<?php
declare(strict_types=1);

/**
 * Ajanin siteye haber gonderirken kullanacagi erisim anahtarlari.
 * Anahtar yalnizca uretildigi anda bir kez gosterilir; veritabaninda
 * sadece SHA-256 ozeti saklanir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

giris_zorunlu();

$yeniAnahtar = '';
$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'uret') {
        $ad = trim((string) ($_POST['ad'] ?? ''));

        if ($ad === '') {
            $hata = 'Anahtara bir ad verin.';
        } else {
            $yeniAnahtar = 'vlt_' . bin2hex(random_bytes(32));

            db()->prepare('INSERT INTO ajan_anahtarlari (ad, anahtar_hash) VALUES (:ad, :hash)')
                ->execute([
                    'ad'   => mb_substr($ad, 0, 120, 'UTF-8'),
                    'hash' => hash('sha256', $yeniAnahtar),
                ]);
        }
    } elseif ($islem === 'kapat') {
        db()->prepare('UPDATE ajan_anahtarlari SET aktif = 0 WHERE id = :id')
            ->execute(['id' => (int) ($_POST['id'] ?? 0)]);

        yonlendir('anahtarlar.php');
    }
}

$anahtarlar = db()->query(
    'SELECT * FROM ajan_anahtarlari ORDER BY aktif DESC, olusturuldu DESC'
)->fetchAll();

$sonKayitlar = db()->query(
    'SELECT * FROM ajan_kayitlari ORDER BY baslangic DESC LIMIT 10'
)->fetchAll();

$panelBasligi = 'Ajan anahtarları';
require __DIR__ . '/ust.php';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($yeniAnahtar !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;">
        <strong>Anahtar oluşturuldu.</strong> Bu değer yalnızca şimdi gösteriliyor —
        kopyalayıp ajanın çalıştığı yerde saklayın. Kapattığınızda bir daha göremezsiniz.
        <code class="anahtar"><?= e($yeniAnahtar) ?></code>
    </div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:14px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Yeni anahtar üret</h2>
    <p class="ipucu" style="margin:0 0 16px;">
        Ajan, haberleri <code>POST /api/ingest.php</code> adresine
        <code>Authorization: Bearer &lt;anahtar&gt;</code> başlığıyla gönderir.
    </p>

    <form method="post" action="anahtarlar.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="uret">

        <div class="alan" style="flex:1;min-width:220px;margin-bottom:0;">
            <label for="ad">Anahtar adı</label>
            <input type="text" id="ad" name="ad" placeholder="Günlük vergi ajanı" required>
        </div>

        <button type="submit" class="dugme dugme-ana">Üret</button>
    </form>
</div>

<div class="kutu">
    <h2 style="margin:0 0 14px;font-size:1.05rem;">Anahtarlar</h2>

    <?php if ($anahtarlar === []): ?>
        <p class="ipucu" style="margin:0;">Henüz anahtar üretilmedi.</p>
    <?php else: ?>
        <?php foreach ($anahtarlar as $anahtar): ?>
            <div class="satir-bilgi" style="padding:10px 0;border-bottom:1px solid var(--cizgi);align-items:center;">
                <strong style="color:var(--metin);"><?= e($anahtar['ad']) ?></strong>

                <span class="rozet <?= (int) $anahtar['aktif'] === 1 ? 'rozet-yayinda' : 'rozet-reddedildi' ?>">
                    <?= (int) $anahtar['aktif'] === 1 ? 'aktif' : 'kapalı' ?>
                </span>

                <span>oluşturuldu <?= e(tarih_bicimle($anahtar['olusturuldu'], false)) ?></span>

                <span>
                    son kullanım:
                    <?= $anahtar['son_kullanim'] !== null
                            ? e(tarih_bicimle($anahtar['son_kullanim']))
                            : 'hiç' ?>
                </span>

                <?php if ((int) $anahtar['aktif'] === 1): ?>
                    <form method="post" action="anahtarlar.php" style="margin-left:auto;">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="islem" value="kapat">
                        <input type="hidden" name="id" value="<?= (int) $anahtar['id'] ?>">
                        <button type="submit" class="dugme dugme-ret">Kapat</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="kutu">
    <h2 style="margin:0 0 14px;font-size:1.05rem;">Son ajan çalışmaları</h2>

    <?php if ($sonKayitlar === []): ?>
        <p class="ipucu" style="margin:0;">Ajan henüz çalışmadı.</p>
    <?php else: ?>
        <?php foreach ($sonKayitlar as $kayit): ?>
            <div class="satir-bilgi" style="padding:8px 0;border-bottom:1px solid var(--cizgi);">
                <span><?= e(tarih_bicimle($kayit['baslangic'])) ?></span>
                <span class="rozet <?= $kayit['durum'] === 'hata' ? 'rozet-reddedildi' : 'rozet-yayinda' ?>">
                    <?= e($kayit['durum']) ?>
                </span>
                <span><?= (int) $kayit['bulunan'] ?> bulundu</span>
                <span><?= (int) $kayit['eklenen'] ?> eklendi</span>
                <span><?= (int) $kayit['yinelenen'] ?> yinelenen</span>
                <?php if ($kayit['mesaj'] !== ''): ?>
                    <span><?= e($kayit['mesaj']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
