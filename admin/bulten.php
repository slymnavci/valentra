<?php
declare(strict_types=1);

/**
 * Gunluk e-posta bulteni yonetimi (03.11.2026; bkz. includes/bulten.php).
 *
 * Abone sayilari, ac/kapat, bugunun bulteninin onizlemesi, kendine deneme,
 * bugunun gonderimini simdi baslat/surdur, gecmis sayilar ve son aboneler.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bulten.php';

giris_zorunlu();

// Onizleme: iframe'in icinde gosterilen e-posta.
if (isset($_GET['onizle'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'");
    echo str_replace('{IPTAL}', '#', bulten_icerik()['html']);
    exit;
}

$hata = '';
$bildirim = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'ayar') {
        ayar_yaz('bulten_acik', !empty($_POST['acik']) ? '1' : '0');
        yonlendir('bulten.php?bildirim=ayar');
    } elseif ($islem === 'deneme') {
        $adres = trim((string) ($_POST['adres'] ?? ''));

        if (!filter_var($adres, FILTER_VALIDATE_EMAIL)) {
            $hata = 'Deneme için geçerli bir e-posta adresi yazın.';
        } else {
            $icerik = bulten_icerik();

            try {
                bulten_tek_gonder($adres, str_repeat('0', 32), ['konu' => '[Deneme] ' . $icerik['konu'], 'html' => $icerik['html'], 'metin' => $icerik['metin']]);
                yonlendir('bulten.php?bildirim=deneme');
            } catch (RuntimeException $e) {
                $hata = 'E-posta gönderilemedi: ' . $e->getMessage() . ' (Panel → Site → E-posta ayarlarını kontrol edin.)';
            }
        }
    } elseif ($islem === 'simdi') {
        @set_time_limit(150);
        $s = bulten_isle(120);
        yonlendir('bulten.php?bildirim=simdi&g=' . $s['gonderilen'] . '&h=' . $s['hata']);
    } elseif ($islem === 'abone_sil') {
        db()->prepare('DELETE FROM bulten_aboneleri WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        yonlendir('bulten.php?bildirim=silindi');
    }
}

$bildirim = match ((string) ($_GET['bildirim'] ?? '')) {
    'ayar'    => 'Kaydedildi.',
    'deneme'  => 'Deneme e-postası gönderildi; gelen kutunuza bakın.',
    'simdi'   => 'Gönderim çalıştı: ' . (int) ($_GET['g'] ?? 0) . ' e-posta gitti' . ((int) ($_GET['h'] ?? 0) > 0 ? ', ' . (int) $_GET['h'] . ' hata' : '') . '.',
    'silindi' => 'Abone silindi.',
    default   => '',
};

$sayilar  = bulten_sayilar();
$gecmis   = [];
$aboneler = [];

try {
    $gecmis   = db()->query('SELECT id, gun, konu, durum, hedef, gonderilen, hata, son_hata, olusturuldu, bitti FROM bulten_sayilari ORDER BY gun DESC LIMIT 30')->fetchAll();
    $aboneler = db()->query('SELECT id, eposta, durum, olusturuldu, onaylandi, ayrildi FROM bulten_aboneleri ORDER BY id DESC LIMIT 100')->fetchAll();
} catch (PDOException $e) {
    $hata = $hata ?: 'Veritabanı: ' . $e->getMessage();
}

$smtp = eposta_ayarlari()['host'] !== '';
$yeni7 = 0;

try {
    $yeni7 = (int) db()->query("SELECT COUNT(*) FROM bulten_aboneleri WHERE durum = 'aktif' AND onaylandi > NOW() - INTERVAL 7 DAY")->fetchColumn();
} catch (PDOException $e) {
}

$panelBasligi = 'E-posta bülteni';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>
<?php if (!$smtp): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;">
        SMTP ayarı girilmemiş: e-postalar sunucunun kendi gönderimiyle gider ve istenmeyen klasörüne düşebilir.
        <a href="eposta.php">Panel → Site → E-posta</a> sayfasından ayarlayın.
    </div>
<?php endif; ?>

<div class="kutu" style="margin-top:20px;">
    <h2 style="margin-top:0;">Günlük e-posta bülteni</h2>
    <p class="ipucu" style="margin-top:0;">
        Okurlar haber sayfalarının altındaki kutudan abone olur, onay e-postasına tıklayınca abonelik başlar.
        Her sabah <?= str_pad((string) BULTEN_SAAT, 2, '0', STR_PAD_LEFT) ?>:00'den sonra son 24 saatin en önemli
        <?= BULTEN_HABER ?> haberi, günün köşe yazısı ve açık canlı anlatım kendiliğinden gönderilir.
        Her e-postada tek tıkla abonelikten çıkma var.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
        <?php foreach ([['Aktif abone', $sayilar['aktif'], 'son 7 günde +' . $yeni7], ['Onay bekleyen', $sayilar['bekliyor'], 'e-postayı onaylamadı'], ['Ayrılan', $sayilar['iptal'], 'abonelikten çıktı']] as [$ad, $n, $alt]): ?>
            <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
                <div class="ipucu"><?= e($ad) ?></div>
                <div style="font-size:1.8rem;font-weight:800;"><?= number_format($n, 0, ',', '.') ?></div>
                <div class="ipucu"><?= e($alt) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <form method="post" action="bulten.php" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin:16px 0 0;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="ayar">
        <label style="display:flex;gap:8px;align-items:center;font-weight:700;">
            <input type="checkbox" name="acik" value="1"<?= bulten_acik() ? ' checked' : '' ?>> Bülten açık (kutu sitede görünür, her sabah gönderilir)
        </label>
        <button type="submit" class="dugme dugme-ana">Kaydet</button>
    </form>
</div>

<div class="istatistik-ikili">
    <div class="kutu">
        <h2 style="margin-top:0;">Bugünün bülteni</h2>
        <p class="ipucu" style="margin-top:0;">Şu an gönderilse böyle görünür.</p>
        <iframe src="bulten.php?onizle=1" title="Bülten önizlemesi" sandbox style="width:100%;height:560px;border:1px solid var(--cizgi);border-radius:8px;background:#f4f4f5;"></iframe>
    </div>
    <div class="kutu">
        <h2 style="margin-top:0;">Deneme ve gönderim</h2>
        <form method="post" action="bulten.php" style="margin:0 0 18px;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="deneme">
            <label>Kendinize deneme gönderin<br>
                <input type="email" name="adres" required style="width:100%;" placeholder="ornek@alanadi.com">
            </label>
            <button type="submit" class="dugme dugme-ana" style="margin-top:8px;">Deneme gönder</button>
            <p class="ipucu">Deneme, abonelere gitmez; konu satırında [Deneme] yazar.</p>
        </form>
        <form method="post" action="bulten.php" style="margin:0;" onsubmit="return confirm('Bugünün bülteni bütün aktif abonelere gönderilsin mi? (Bugün zaten gönderildiyse tekrar gitmez.)')">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="simdi">
            <button type="submit" class="dugme"<?= $sayilar['aktif'] === 0 ? ' disabled' : '' ?>>Bugünün bültenini şimdi gönder / sürdür</button>
            <p class="ipucu">Normalde gerek yok: sabah <?= str_pad((string) BULTEN_SAAT, 2, '0', STR_PAD_LEFT) ?>:00'den sonra ilk ziyaretle kendiliğinden başlar ve bitene kadar sürer. Her gün tek bülten gider.</p>
        </form>
    </div>
</div>

<div class="kutu" style="margin-top:20px;overflow-x:auto;">
    <h2 style="margin-top:0;">Gönderilen bültenler</h2>
    <?php if ($gecmis === []): ?>
        <p class="ipucu">Henüz bülten gönderilmedi.</p>
    <?php else: ?>
        <table class="liste-tablo">
            <thead><tr><th>Gün</th><th>Konu</th><th class="sag">Abone</th><th class="sag">Giden</th><th class="sag">Hata</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($gecmis as $g): ?>
                <tr>
                    <td><?= e(tarih_bicimle((string) $g['gun'], false)) ?></td>
                    <td><?= e((string) $g['konu']) ?><?= (string) $g['son_hata'] !== '' ? '<br><span class="ipucu" style="color:#c0121b;">' . e((string) $g['son_hata']) . '</span>' : '' ?></td>
                    <td class="sag"><?= (int) $g['hedef'] ?></td>
                    <td class="sag"><?= (int) $g['gonderilen'] ?></td>
                    <td class="sag"><?= (int) $g['hata'] ?></td>
                    <td><?= $g['durum'] === 'bitti' ? 'Tamam' : 'Gönderiliyor' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="kutu" style="margin-top:20px;overflow-x:auto;">
    <h2 style="margin-top:0;">Son aboneler</h2>
    <?php if ($aboneler === []): ?>
        <p class="ipucu">Henüz abone yok.</p>
    <?php else: ?>
        <table class="liste-tablo">
            <thead><tr><th>E-posta</th><th>Durum</th><th>Kayıt</th><th>Onay</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($aboneler as $a): ?>
                <tr>
                    <td><?= e((string) $a['eposta']) ?></td>
                    <td><?= ['aktif' => 'Aktif', 'bekliyor' => 'Onay bekliyor', 'iptal' => 'Ayrıldı'][$a['durum']] ?? e((string) $a['durum']) ?></td>
                    <td><?= e(tarih_bicimle((string) $a['olusturuldu'])) ?></td>
                    <td><?= $a['onaylandi'] ? e(tarih_bicimle((string) $a['onaylandi'])) : '—' ?></td>
                    <td>
                        <form method="post" action="bulten.php" style="margin:0;" onsubmit="return confirm('Abone kalıcı olarak silinsin mi?')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="abone_sil">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="dugme dugme-ret">Sil</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
