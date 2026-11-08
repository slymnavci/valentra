<?php
declare(strict_types=1);

/**
 * Gündem dosyaları: editör seçimi ve dosyadan çıkarma (08.11.2026).
 *
 * Okur denetimi: enflasyon dosyasinin basinda tufekli saldiri haberi,
 * kira dosyasinda Glasgow'daki bir restoranin davasi vardi. Eslestirme
 * sikilastirildi (bkz. includes/konu.php, konu_eslesir); yine de kelime
 * kurali her haberi dogru ayiramaz. Burada editor:
 *
 *   - dosyanin en ustundeki haberi kendisi secer (bos: otomatik);
 *   - dosyaya yanlislikla giren haberi cikarir, gerekirse geri alir.
 *
 * Ayarlar: konu_one_<slug> (haber id), konu_haric_<slug> (virgullu id).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/konu.php';

giris_zorunlu();

$panelBasligi = 'Gündem dosyaları';
$slug         = (string) ($_GET['k'] ?? '');
$bildirim     = '';

if ($slug !== '' && !isset(KONULAR[$slug])) {
    $slug = '';
}

/** @return list<int> */
$haricListesi = static fn (string $s): array => array_values(array_filter(
    array_map('intval', explode(',', ayar_oku('konu_haric_' . $s))),
    static fn (int $id): bool => $id > 0
));

if ($slug !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');
    $id    = max(0, (int) ($_POST['id'] ?? 0));
    $haric = $haricListesi($slug);

    if ($islem === 'one') {
        if ($id > 0) {
            ayar_yaz('konu_one_' . $slug, (string) $id);
            $haric = array_values(array_diff($haric, [$id]));
            ayar_yaz('konu_haric_' . $slug, implode(',', $haric));
            $bildirim = 'Haber dosyanın başına alındı.';
        } else {
            ayar_sil('konu_one_' . $slug);
            $bildirim = 'Dosyanın başı yeniden otomatik seçiliyor.';
        }
    } elseif ($islem === 'cikar' && $id > 0) {
        $haric[] = $id;
        ayar_yaz('konu_haric_' . $slug, implode(',', array_unique($haric)));

        if (konu_editor_secimi($slug) === $id) {
            ayar_sil('konu_one_' . $slug);
        }

        $bildirim = 'Haber bu dosyadan çıkarıldı.';
    } elseif ($islem === 'geri' && $id > 0) {
        ayar_yaz('konu_haric_' . $slug, implode(',', array_diff($haric, [$id])));
        $bildirim = 'Haber dosyaya geri alındı.';
    }

    header('Location: konular.php?k=' . rawurlencode($slug) . '&tamam=' . rawurlencode($bildirim));
    exit;
}

if (isset($_GET['tamam'])) {
    $bildirim = mb_substr((string) $_GET['tamam'], 0, 120);
}

require __DIR__ . '/ust.php';
?>

<h1>Gündem dosyaları</h1>

<?php if ($bildirim !== ''): ?>
    <div class="uyari"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($slug === ''): ?>
    <div class="kutu">
        <p class="ipucu" style="margin-top:0;">
            Dosyaya, başlığında konunun kelimesi geçen (ya da hem spotunda hem etiketlerinde geçen) haber girer.
            Adli olay haberleri ile dünya, spor, teknoloji ve kültür-sanat kategorileri dosyalara girmez.
            Yine de yanlış giren haberi buradan çıkarabilir, dosyanın başına çıkacak haberi seçebilirsiniz.
        </p>
        <table class="liste-tablo">
            <thead><tr><th>Dosya</th><th>Başta</th><th>Çıkarılan</th></tr></thead>
            <tbody>
            <?php foreach (KONULAR as $s => $k): ?>
                <tr>
                    <td><a href="konular.php?k=<?= e(rawurlencode($s)) ?>"><?= e(konu_basligi($s)) ?></a></td>
                    <td><?= konu_editor_secimi($s) > 0 ? 'Editör seçimi' : 'Otomatik' ?></td>
                    <td><?= count($haricListesi($s)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else:
    $haberler = konu_haberleri($slug);
    $secim    = konu_editor_secimi($slug);
    $haric    = $haricListesi($slug);
    ?>
    <p><a href="konular.php">&larr; Bütün dosyalar</a> ·
        <a href="<?= e(konu_yolu($slug)) ?>" target="_blank" rel="noopener">Sayfayı gör ↗</a></p>

    <div class="kutu">
        <h2 style="margin-top:0;"><?= e(konu_basligi($slug)) ?></h2>
        <p class="ipucu" style="margin-top:0;">
            Dosyanın başı: <strong><?= $secim > 0 ? 'editör seçimi (#' . $secim . ')' : 'otomatik — başlığında konunun geçtiği en yeni haber' ?></strong>
        </p>
        <?php if ($secim > 0): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="islem" value="one">
                <input type="hidden" name="id" value="0">
                <button class="dugme" type="submit">Otomatiğe dön</button>
            </form>
        <?php endif; ?>

        <table class="liste-tablo">
            <thead><tr><th>Tarih</th><th>Haber</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($haberler as $h): ?>
                <tr>
                    <td><?= e(date('d.m.Y H:i', strtotime((string) $h['yayin_tarihi']) ?: 0)) ?></td>
                    <td>
                        <?php if ((int) $h['id'] === $secim): ?><strong>[Başta]</strong> <?php endif; ?>
                        <a href="<?= e(haber_yolu((string) $h['slug'])) ?>" target="_blank" rel="noopener"><?= e((string) $h['baslik']) ?></a>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php foreach (['one' => 'Başa al', 'cikar' => 'Dosyadan çıkar'] as $islem => $etiket): ?>
                            <?php if ($islem === 'one' && (int) $h['id'] === $secim) { continue; } ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                                <input type="hidden" name="islem" value="<?= e($islem) ?>">
                                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                                <button class="dugme" type="submit"><?= e($etiket) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($haberler === []): ?>
                <tr><td colspan="3">Bu dosyada haber yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($haric !== []):
        $yer    = implode(',', array_fill(0, count($haric), '?'));
        $ifade  = db()->prepare("SELECT id, baslik, slug, yayin_tarihi FROM haberler WHERE id IN ({$yer}) ORDER BY yayin_tarihi DESC");
        $ifade->execute($haric);
        ?>
        <div class="kutu">
            <h2 style="margin-top:0;">Dosyadan çıkarılanlar</h2>
            <table class="liste-tablo">
                <tbody>
                <?php foreach ($ifade->fetchAll() as $h): ?>
                    <tr>
                        <td><?= e(date('d.m.Y', strtotime((string) $h['yayin_tarihi']) ?: 0)) ?></td>
                        <td><?= e((string) $h['baslik']) ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                                <input type="hidden" name="islem" value="geri">
                                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                                <button class="dugme" type="submit">Geri al</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
