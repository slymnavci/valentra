<?php
declare(strict_types=1);

/**
 * Haberi incele / duzenle / onayla.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

giris_zorunlu();

$id    = (int) ($_GET['id'] ?? 0);
$haber = $id > 0 ? haber_bul($id) : null;

if ($haber === null) {
    http_response_code(404);
    $panelBasligi = 'Bulunamadı';
    require __DIR__ . '/ust.php';
    echo '<div class="bos-durum" style="margin-top:24px;"><strong>Haber bulunamadı.</strong>'
       . '<a href="index.php">Panele dön</a></div>';
    require __DIR__ . '/alt.php';
    exit;
}

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    try {
        haber_guncelle($id, [
            'baslik'     => $_POST['baslik'] ?? '',
            'ozet'       => $_POST['ozet'] ?? '',
            'icerik'     => $_POST['icerik'] ?? '',
            'gorsel_url' => $_POST['gorsel_url'] ?? '',
            'etiketler'  => $_POST['etiketler'] ?? '',
            'one_cikan'  => $_POST['one_cikan'] ?? null,
        ]);

        // "Kaydet ve onayla" dugmesi
        if (($_POST['ayrica'] ?? '') === 'onayla') {
            haber_onayla($id, aktif_yonetici_id());
            yonlendir('index.php?durum=yayinda&bildirim=onaylandi');
        }

        yonlendir('duzenle.php?id=' . $id . '&bildirim=guncellendi');
    } catch (InvalidArgumentException $e) {
        $hata  = $e->getMessage();
        $haber = array_merge($haber, [
            'baslik'     => (string) ($_POST['baslik'] ?? ''),
            'ozet'       => (string) ($_POST['ozet'] ?? ''),
            'icerik'     => (string) ($_POST['icerik'] ?? ''),
            'gorsel_url' => (string) ($_POST['gorsel_url'] ?? ''),
            'etiketler'  => (string) ($_POST['etiketler'] ?? ''),
        ]);
    }
}

$bildirimler = [
    'guncellendi' => ['basari', 'Haber güncellendi.'],
    'geri'        => ['bilgi',  'Haber yayından kaldırıldı.'],
];
$bildirim = $bildirimler[(string) ($_GET['bildirim'] ?? '')] ?? null;

$panelBasligi = 'Haberi düzenle';
require __DIR__ . '/ust.php';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($bildirim !== null): ?>
    <div class="uyari uyari-<?= e($bildirim[0]) ?>" style="margin-top:14px;"><?= e($bildirim[1]) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:14px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <div class="satir-bilgi" style="margin-bottom:0;">
        <span class="rozet rozet-<?= e($haber['durum']) ?>"><?= e($haber['durum']) ?></span>

        <?php if ((int) $haber['guven_skoru'] > 0): ?>
            <span class="rozet rozet-skor">güven %<?= (int) $haber['guven_skoru'] ?></span>
        <?php endif; ?>

        <span>Derlendi: <?= e(tarih_bicimle($haber['olusturuldu'])) ?></span>

        <?php if ($haber['yayin_tarihi'] !== null): ?>
            <span>Yayın: <?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></span>
        <?php endif; ?>

        <?php if ($haber['kaynak_url'] !== ''): ?>
            <a href="<?= e(guvenli_url($haber['kaynak_url'])) ?>" target="_blank" rel="noopener nofollow">
                Kaynak<?= $haber['kaynak_adi'] !== '' ? ': ' . e($haber['kaynak_adi']) : '' ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($haber['ajan_notu'] !== ''): ?>
        <div class="uyari uyari-bilgi" style="margin:14px 0 0;">
            <strong>Ajan notu:</strong> <?= e($haber['ajan_notu']) ?>
        </div>
    <?php endif; ?>
</div>

<form class="kutu" method="post" action="duzenle.php?id=<?= $id ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">

    <div class="alan">
        <label for="baslik">Başlık</label>
        <input type="text" id="baslik" name="baslik" maxlength="300"
               value="<?= e($haber['baslik']) ?>" required>
    </div>

    <div class="alan">
        <label for="ozet">Spot / özet</label>
        <textarea id="ozet" name="ozet" maxlength="600"
                  style="min-height:80px;"><?= e($haber['ozet']) ?></textarea>
        <div class="ipucu">Boş bırakılırsa içerikten otomatik üretilir.</div>
    </div>

    <div class="alan">
        <label for="icerik">Haber metni</label>
        <textarea id="icerik" name="icerik" required><?= e($haber['icerik']) ?></textarea>
        <div class="ipucu">Paragrafları boş satırla ayırın.</div>
    </div>

    <div class="alan">
        <label for="gorsel_url">Görsel adresi</label>
        <input type="url" id="gorsel_url" name="gorsel_url" maxlength="500"
               value="<?= e($haber['gorsel_url'] ?? '') ?>">
    </div>

    <div class="alan">
        <label for="etiketler">Etiketler</label>
        <input type="text" id="etiketler" name="etiketler" maxlength="400"
               value="<?= e($haber['etiketler']) ?>">
        <div class="ipucu">Virgülle ayırın. Örnek: KDV, Gelir Vergisi, Tebliğ</div>
    </div>

    <div class="alan">
        <label style="font-weight:600;">
            <input type="checkbox" name="one_cikan" value="1"
                   <?= (int) $haber['one_cikan'] === 1 ? 'checked' : '' ?>>
            Manşete al
        </label>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="dugme">Kaydet</button>

        <?php if ($haber['durum'] !== HABER_YAYINDA): ?>
            <button type="submit" name="ayrica" value="onayla" class="dugme dugme-onay">
                Kaydet ve ana sayfaya al
            </button>
        <?php endif; ?>
    </div>
</form>

<div class="kutu">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if ($haber['durum'] === HABER_YAYINDA): ?>
            <form method="post" action="islem.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="islem" value="geri_al">
                <input type="hidden" name="donus" value="duzenle">
                <button type="submit" class="dugme dugme-ret">Yayından kaldır</button>
            </form>
        <?php elseif ($haber['durum'] === HABER_TASLAK): ?>
            <form method="post" action="islem.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="islem" value="reddet">
                <button type="submit" class="dugme dugme-ret">Reddet</button>
            </form>
        <?php endif; ?>

        <form method="post" action="islem.php"
              onsubmit="return confirm('Bu haber kalıcı olarak silinecek. Emin misiniz?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="islem" value="sil">
            <button type="submit" class="dugme dugme-ret">Sil</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/alt.php'; ?>
