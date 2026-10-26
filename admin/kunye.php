<?php
declare(strict_types=1);

/**
 * Künye ve iletişim bilgileri.
 *
 * Sitenin Kunye, Iletisim ve Yayin Ilkeleri sayfalari bu bilgileri
 * gosteriyor (bkz. includes/kurumsal.php). Bos alan sayfada gorunmuyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/kurumsal.php';

giris_zorunlu();

$panelBasligi = 'Künye ve iletişim';
$bildirim     = '';
$hata         = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $eposta = trim((string) ($_POST['kunye_eposta'] ?? ''));

    if ($eposta !== '' && filter_var($eposta, FILTER_VALIDATE_EMAIL) === false) {
        $hata = 'E-posta adresi geçerli değil.';
    } else {
        foreach (kunye_alanlari() as $anahtar => $alan) {
            $deger = trim(str_replace("\r\n", "\n", (string) ($_POST[$anahtar] ?? '')));
            ayar_yaz($anahtar, mb_substr($deger, 0, $alan['cok_satir'] ? 500 : 200));
        }

        $bildirim = 'Kaydedildi. Künye ve İletişim sayfaları güncellendi.';
    }
}

$bilgi  = kunye_bilgileri();
$eksik  = kunye_eksikleri();

require __DIR__ . '/ust.php';
?>

<h1>Künye ve iletişim</h1>

<?php if ($bildirim !== ''): ?>
    <div class="uyari"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata"><?= e($hata) ?></div>
<?php endif; ?>

<?php if ($eksik !== []): ?>
    <div class="uyari uyari-hata">
        Künyede eksik bilgi var: <strong><?= e(implode(', ', $eksik)) ?></strong>.
        Basın Kanunu internet haber sitelerinde künye istiyor; Google da yayıncının
        açıkça belli olmasını bekliyor.
    </div>
<?php endif; ?>

<div class="kutu">
    <p class="ipucu" style="margin-top:0;">
        Buraya girilen bilgiler sitenin
        <?php foreach (kurumsal_sayfalar() as $anahtar => $ad): ?>
            <a href="<?= e(kurumsal_yolu($anahtar)) ?>" target="_blank" rel="noopener"><?= e($ad) ?></a><?= $anahtar !== 'iletisim' ? ',' : '' ?>
        <?php endforeach; ?>
        sayfalarında görünür. Boş bırakılan alan sayfada hiç gösterilmez.
    </p>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">

        <?php foreach (kunye_alanlari() as $anahtar => $alan): ?>
            <label for="<?= e($anahtar) ?>"><?= e($alan['etiket']) ?></label>
            <?php if ($alan['cok_satir']): ?>
                <textarea id="<?= e($anahtar) ?>" name="<?= e($anahtar) ?>" rows="2" style="width:100%;"><?= e($bilgi[$anahtar]) ?></textarea>
            <?php else: ?>
                <input type="<?= $anahtar === 'kunye_eposta' ? 'email' : 'text' ?>" id="<?= e($anahtar) ?>"
                       name="<?= e($anahtar) ?>" style="width:100%;" value="<?= e($bilgi[$anahtar]) ?>">
            <?php endif; ?>
            <p class="ipucu" style="margin-top:2px;"><?= e($alan['ipucu']) ?></p>
        <?php endforeach; ?>

        <button class="dugme dugme-ana" type="submit">Kaydet</button>
    </form>
</div>

<?php require __DIR__ . '/alt.php'; ?>
