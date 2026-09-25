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
            'iframe_url' => $_POST['iframe_url'] ?? '',
            'etiketler'  => $_POST['etiketler'] ?? '',
            'one_cikan'   => $_POST['one_cikan'] ?? null,
            'kategori_id' => $_POST['kategori_id'] ?? '',

            'analiz_degisen'   => $_POST['analiz_degisen'] ?? '',
            'analiz_etkilenen' => $_POST['analiz_etkilenen'] ?? '',
            'analiz_zaman'     => $_POST['analiz_zaman'] ?? '',
            'analiz_islem'     => $_POST['analiz_islem'] ?? '',
            'isletme_etkisi'   => $_POST['isletme_etkisi'] ?? '',
            'uygulama_ornegi'  => $_POST['uygulama_ornegi'] ?? '',
            'resmi_dayanak'    => $_POST['resmi_dayanak'] ?? '',
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
            'iframe_url' => (string) ($_POST['iframe_url'] ?? ''),
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

    <?php /*
        VALENTRA ANALIZ — editorun denetiminde.

        "Hangi islem yapilmali" cevabi modelden geliyor ve mesleki
        bir yayinda okuyucuya yol gosteriyor. Bu
        yuzden onay ekraninda duzenlenebilir olmasi sart. Bos
        birakilan alan sayfada hic gorunmez.
    */ ?>
    <fieldset class="alan analiz-alani">
        <legend>Valentra Analiz</legend>
        <div class="ipucu" style="margin-bottom:12px;">
            Okuyucunun haberi kendi işine uyarlaması için. Boş bıraktığınız
            alan sayfada görünmez. <strong>Özellikle son alanı kontrol edin:</strong>
            kaynağın zorunlu kılmadığı bir işlem yazılmamalı.
        </div>

        <label for="analiz_degisen">Ne değişti?</label>
        <textarea id="analiz_degisen" name="analiz_degisen" rows="2"
                  maxlength="600"><?= e($haber['analiz_degisen'] ?? '') ?></textarea>

        <label for="analiz_etkilenen">Kimleri ilgilendiriyor?</label>
        <textarea id="analiz_etkilenen" name="analiz_etkilenen" rows="2"
                  maxlength="600"><?= e($haber['analiz_etkilenen'] ?? '') ?></textarea>

        <label for="analiz_zaman">Ne zaman uygulanacak?</label>
        <textarea id="analiz_zaman" name="analiz_zaman" rows="2"
                  maxlength="600"><?= e($haber['analiz_zaman'] ?? '') ?></textarea>

        <label for="analiz_islem">Ne yapılmalı? <span class="ipucu">(kontrol listesi: her madde "- " ile ayrı satırda)</span></label>
        <textarea id="analiz_islem" name="analiz_islem" rows="3"
                  maxlength="600"><?= e($haber['analiz_islem'] ?? '') ?></textarea>

        <label for="isletme_etkisi">İşletmeye etkisi</label>
        <textarea id="isletme_etkisi" name="isletme_etkisi" rows="3"
                  maxlength="800"><?= e($haber['isletme_etkisi'] ?? '') ?></textarea>

        <label for="uygulama_ornegi">Uygulama örneği <span class="ipucu">(hesaplama ya da muhasebe kaydı; rakamlar varsayımsal olmalı)</span></label>
        <textarea id="uygulama_ornegi" name="uygulama_ornegi" rows="7"
                  maxlength="6000"><?= e($haber['uygulama_ornegi'] ?? '') ?></textarea>
        <div class="ipucu" style="margin:-4px 0 10px;">
            <strong>Hesabı ve kaydı mutlaka kontrol edin:</strong> bot oranı
            kaynaktan alır ama hesabı kendisi yapar. Borç ve alacak toplamı eşit mi?
        </div>

        <label for="resmi_dayanak">Resmî dayanak <span class="ipucu">(kanun/madde, tebliğ sıra no, Resmî Gazete tarih ve sayısı)</span></label>
        <input type="text" id="resmi_dayanak" name="resmi_dayanak" maxlength="400"
               value="<?= e($haber['resmi_dayanak'] ?? '') ?>">
    </fieldset>

    <div class="alan">
        <label for="gorsel_url">Görsel adresi</label>
        <input type="url" id="gorsel_url" name="gorsel_url" maxlength="500"
               value="<?= e($haber['gorsel_url'] ?? '') ?>">
    </div>

    <div class="alan">
        <label for="iframe_url">Gömülü içerik adresi (iframe)</label>
        <input type="url" id="iframe_url" name="iframe_url" maxlength="1000"
               placeholder="https://..."
               value="<?= e($haber['iframe_url'] ?? '') ?>">
        <div class="ipucu">
            Yalnızca http/https adresleri kabul edilir. Bazı dış siteler güvenlik
            politikaları nedeniyle iframe içinde açılmaya izin vermeyebilir.
        </div>
    </div>

    <div class="alan">
        <label for="kategori_id">Konu grubu</label>
        <select id="kategori_id" name="kategori_id"
                style="width:100%;padding:10px 12px;border:1px solid var(--cizgi);border-radius:7px;font-family:inherit;font-size:.92rem;">
            <option value="">— seçilmedi —</option>
            <?php foreach (kategori_listesi() as $kategori): ?>
                <option value="<?= (int) $kategori['id'] ?>"
                    <?= (int) ($haber['kategori_id'] ?? 0) === (int) $kategori['id'] ? 'selected' : '' ?>>
                    <?= e($kategori['ad']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="ipucu">Ajan bir grup önerir; buradan değiştirebilirsiniz.</div>
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
