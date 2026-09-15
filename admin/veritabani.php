<?php
declare(strict_types=1);

/**
 * Veritabani semasini gunceller.
 *
 * kurulum.php ilk hesap olustuktan sonra kendini kapatir; sonradan gelen
 * sema degisiklikleri (yeni tablo, yeni sutun, yeni varsayilan kayit) bu
 * sayfadan uygulanir. Islem tekrar calistirilabilir: var olan tablolara
 * ve doldurulmus kayitlara dokunmaz.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sema.php';

giris_zorunlu();

$sonuc = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);
    $sonuc = sema_kur(dirname(__DIR__) . '/sql/schema.sql');
}

$tablolar = sema_durumu();
$sayilar  = [];

foreach (['kategoriler' => 'Konu grubu', 'kaynaklar' => 'Kaynak', 'haberler' => 'Haber'] as $tablo => $ad) {
    try {
        $sayilar[$ad] = (int) db()->query('SELECT COUNT(*) FROM `' . $tablo . '`')->fetchColumn();
    } catch (PDOException $e) {
        $sayilar[$ad] = null;
    }
}

$eksikSutunlar = [];

$beklenenSutunlar = [
    ['haberler', 'kategori_id'],
    ['haberler', 'iframe_url'],
    ['kategoriler', 'ust_id'],
    ['kaynaklar', 'liste_url'],
    ['kaynaklar', 'liste_secici'],
];

foreach ($beklenenSutunlar as [$tablo, $sutun]) {
    if (in_array($tablo, array_keys(array_filter($tablolar)), true) && !sema_sutun_var($tablo, $sutun)) {
        $eksikSutunlar[] = $tablo . '.' . $sutun;
    }
}

$guncelMi = !in_array(false, $tablolar, true) && $eksikSutunlar === [];

$panelBasligi = 'Veritabanı';
require __DIR__ . '/ust.php';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($sonuc !== null): ?>
    <div class="uyari uyari-<?= $sonuc['tamam'] ? 'basari' : 'hata' ?>" style="margin-top:14px;">
        <?php if ($sonuc['tamam']): ?>
            <strong>Veritabanı güncellendi.</strong>
            <?= (int) $sonuc['calisan'] ?> işlem uygulandı.
        <?php else: ?>
            <strong>Güncelleme tamamlanamadı.</strong>
            <?= e($sonuc['mesaj']) ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">
        Şema durumu
        <?php if ($guncelMi): ?>
            <span class="rozet rozet-yayinda">güncel</span>
        <?php else: ?>
            <span class="rozet rozet-taslak">güncelleme gerekli</span>
        <?php endif; ?>
    </h2>

    <p class="ipucu" style="margin:8px 0 16px;">
        Site güncellendiğinde yeni tablo veya sütunlar gerekebilir. Bu düğme
        <code>sql/schema.sql</code> dosyasını uygular; mevcut verilere dokunmaz
        ve tekrar çalıştırmak zararsızdır.
    </p>

    <div class="satir-bilgi" style="margin-bottom:6px;">
        <?php foreach ($tablolar as $tablo => $var): ?>
            <span class="rozet <?= $var ? 'rozet-yayinda' : 'rozet-reddedildi' ?>"><?= e($tablo) ?></span>
        <?php endforeach; ?>
    </div>

    <?php if ($eksikSutunlar !== []): ?>
        <div class="uyari uyari-hata" style="margin:12px 0;">
            Eksik sütun: <?= e(implode(', ', $eksikSutunlar)) ?>.
            Bu yüzden site hata veriyor olabilir.
        </div>
    <?php endif; ?>

    <div class="satir-bilgi" style="margin-bottom:16px;">
        <?php foreach ($sayilar as $ad => $adet): ?>
            <span><?= e($ad) ?>: <strong><?= $adet === null ? '—' : $adet ?></strong></span>
        <?php endforeach; ?>
    </div>

    <form method="post" action="veritabani.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <button type="submit" class="dugme dugme-ana">Veritabanını güncelle</button>
    </form>
</div>

<?php require __DIR__ . '/alt.php'; ?>
