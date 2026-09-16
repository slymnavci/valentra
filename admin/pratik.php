<?php
declare(strict_types=1);

/**
 * Pratik bilgiler onay ekranı.
 *
 * Ajanin getirdigi degerler burada onaylanana kadar sitede gorunmez.
 * Ekran her aday degerin yaninda YAYINDAKI degeri de gosteriyor:
 * onaylayan kisi neyin neye donusecegini gormeden karar vermemeli.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pratik.php';

giris_zorunlu();

$bildirim = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);

    if ($islem === 'onayla' && $id > 0) {
        pratik_onayla($id);
        $bildirim = 'Değer yayına alındı.';
    } elseif ($islem === 'reddet' && $id > 0) {
        pratik_reddet($id);
        $bildirim = 'Aday değer reddedildi; yayındaki değer korundu.';
    } elseif ($islem === 'elle' && $id > 0) {
        pratik_elle_yaz(
            $id,
            (string) ($_POST['deger'] ?? ''),
            (string) ($_POST['donem'] ?? '')
        );
        $bildirim = 'Değer elle güncellendi.';
    } elseif ($islem === 'tumunu_onayla') {
        $adet = 0;

        foreach (pratik_listele() as $satir) {
            if (trim((string) ($satir['aday_deger'] ?? '')) !== '') {
                pratik_onayla((int) $satir['id']);
                $adet++;
            }
        }

        $bildirim = $adet . ' değer yayına alındı.';
    }
}

$satirlar = pratik_listele();
$bekleyen = pratik_bekleyen_sayisi();

$panelBasligi = 'Pratik Bilgiler';
require __DIR__ . '/ust.php';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Pratik bilgiler</h2>
    <p class="ipucu" style="margin:0;">
        Ajan bu değerleri resmî kaynak sayfalarından okur ve
        <strong>onayınıza</strong> sunar. Onaylamadığınız hiçbir değer
        sitede görünmez. Buradaki bir hata haberdeki hatadan daha
        tehlikeli: rakam doğrudan hesaplamada kullanılır, bu yüzden her
        değeri kaynağıyla karşılaştırın.
    </p>

    <?php if ($bekleyen > 0): ?>
        <form method="post" style="margin:14px 0 0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="tumunu_onayla">
            <button type="submit" class="dugme dugme-ana">
                Onay bekleyen <?= (int) $bekleyen ?> değerin tümünü yayına al
            </button>
        </form>
    <?php endif; ?>
</div>

<?php
$oncekiGrup = null;

foreach ($satirlar as $satir):
    $grup       = (string) $satir['grup'];
    $adayDeger  = trim((string) ($satir['aday_deger'] ?? ''));
    $yayinDeger = trim((string) ($satir['deger'] ?? ''));

    if ($grup !== $oncekiGrup):
        $oncekiGrup = $grup;
        ?>
        <div class="bolum-basligi" style="margin-top:26px;">
            <h2><?= e(pratik_grup_adi($grup)) ?></h2>
            <span class="cizgi"></span>
        </div>
    <?php endif; ?>

    <div class="kutu pratik-satir <?= $adayDeger !== '' ? 'bekliyor' : '' ?>">
        <div class="pratik-ust">
            <div>
                <h3><?= e($satir['baslik']) ?></h3>
                <p class="ipucu"><?= e((string) $satir['aciklama']) ?></p>
            </div>

            <?php if (!empty($satir['kaynak_url'])): ?>
                <a class="pratik-kaynak" href="<?= e(guvenli_url((string) $satir['kaynak_url'])) ?>"
                   target="_blank" rel="noopener">
                    <?= e((string) $satir['kaynak_adi']) ?> &nearr;
                </a>
            <?php endif; ?>
        </div>

        <div class="pratik-degerler">
            <div class="pratik-kutu">
                <span class="etiketcik">Yayında</span>
                <?php if ($yayinDeger !== ''): ?>
                    <pre class="pratik-deger"><?= e($yayinDeger) ?></pre>
                    <span class="ipucu">
                        <?= e((string) ($satir['donem'] ?? '')) ?>
                        <?php if (!empty($satir['onay_tarihi'])): ?>
                            &middot; onay: <?= e(tarih_bicimle((string) $satir['onay_tarihi'], false)) ?>
                        <?php endif; ?>
                    </span>
                <?php else: ?>
                    <p class="ipucu" style="margin:0;">Henüz değer yok.</p>
                <?php endif; ?>
            </div>

            <?php if ($adayDeger !== ''): ?>
                <div class="pratik-kutu aday">
                    <span class="etiketcik">Onay bekliyor</span>
                    <pre class="pratik-deger"><?= e($adayDeger) ?></pre>
                    <span class="ipucu">
                        <?= e((string) ($satir['aday_donem'] ?? '')) ?>
                        <?php if ($satir['aday_guven'] !== null): ?>
                            &middot; güven %<?= (int) $satir['aday_guven'] ?>
                        <?php endif; ?>
                    </span>

                    <?php if (!empty($satir['aday_notu'])): ?>
                        <p class="ipucu" style="margin:8px 0 0;">
                            Ajan notu: <?= e((string) $satir['aday_notu']) ?>
                        </p>
                    <?php endif; ?>

                    <div class="pratik-dugmeler">
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="onayla">
                            <input type="hidden" name="id" value="<?= (int) $satir['id'] ?>">
                            <button type="submit" class="dugme dugme-ana">Yayına al</button>
                        </form>

                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="reddet">
                            <input type="hidden" name="id" value="<?= (int) $satir['id'] ?>">
                            <button type="submit" class="dugme dugme-ret">Reddet</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <details class="pratik-elle">
            <summary>Elle düzenle</summary>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="islem" value="elle">
                <input type="hidden" name="id" value="<?= (int) $satir['id'] ?>">

                <div class="alan">
                    <label for="deger-<?= (int) $satir['id'] ?>">Değer</label>
                    <textarea id="deger-<?= (int) $satir['id'] ?>" name="deger"
                              rows="4"><?= e($yayinDeger) ?></textarea>
                </div>

                <div class="alan">
                    <label for="donem-<?= (int) $satir['id'] ?>">Geçerlilik dönemi</label>
                    <input type="text" id="donem-<?= (int) $satir['id'] ?>" name="donem"
                           value="<?= e((string) ($satir['donem'] ?? '')) ?>"
                           placeholder="2026 yılı">
                </div>

                <button type="submit" class="dugme">Kaydet</button>
            </form>
        </details>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/alt.php'; ?>
