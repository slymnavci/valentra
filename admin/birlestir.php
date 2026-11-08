<?php
declare(strict_types=1);

/**
 * Benzer haberler: eski kopyalari birlestirme (08.11.2026).
 *
 * Ayni gunlerde yayimlanmis, basligi buyuk olcude ortak haber ciftleri
 * listelenir. Editor birini digerine birlestirir: eski adres kalici (301)
 * olarak yasayan habere yonlenir, eski kayit yayindan kalkar.
 * Bkz. includes/haber_birlestir.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/haber_birlestir.php';

giris_zorunlu();

$panelBasligi = 'Benzer haberler';
$bildirim     = '';
$hata         = '';

/** Kimlik, haber adresi ya da slug'dan haber kimliği. */
$kimlikCoz = static function (string $girdi): int {
    $girdi = trim($girdi);

    if ($girdi === '') {
        return 0;
    }

    if (ctype_digit($girdi)) {
        return (int) $girdi;
    }

    $yol  = (string) (parse_url($girdi, PHP_URL_PATH) ?? $girdi);
    parse_str((string) (parse_url($girdi, PHP_URL_QUERY) ?? ''), $sorgu);
    $slug = isset($sorgu['h']) ? (string) $sorgu['h'] : basename(rtrim($yol, '/'));
    $slug = (string) preg_replace('/\.(?:html?|php)$/i', '', $slug);

    $ifade = db()->prepare('SELECT id FROM haberler WHERE slug = :s LIMIT 1');
    $ifade->execute(['s' => rawurldecode($slug)]);

    return (int) ($ifade->fetchColumn() ?: 0);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $kaynak = $kimlikCoz((string) ($_POST['kaynak'] ?? ''));
    $hedef  = $kimlikCoz((string) ($_POST['hedef'] ?? ''));

    try {
        haber_birlestir($kaynak, $hedef);
        $bildirim = 'Birleştirildi: #' . $kaynak . ' artık #' . $hedef . ' haberine yönleniyor (301).';
    } catch (InvalidArgumentException $e) {
        $hata = $e->getMessage();
    } catch (PDOException $e) {
        error_log('[valentra] birlestir: ' . $e->getMessage());
        $hata = 'Birleştirilemedi. Veritabanı şeması güncel olmayabilir (Site → Veritabanı).';
    }
}

$gun     = max(1, min(120, (int) ($_GET['gun'] ?? 30)));
$ciftler = [];

try {
    $ciftler = haber_benzer_ciftler($gun);
} catch (PDOException $e) {
    error_log('[valentra] benzer ciftler: ' . $e->getMessage());
    $hata = $hata ?: 'Benzer haberler okunamadı.';
}

$kisaTarih = static fn (string $t): string => date('d.m H:i', strtotime($t) ?: 0);

require __DIR__ . '/ust.php';
?>

<h1>Benzer haberler</h1>

<?php if ($bildirim !== ''): ?>
    <div class="uyari"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu">
    <p class="ipucu" style="margin-top:0;">
        Son <?= $gun ?> günde, birbirine <?= HABER_BENZER_SAAT ?> saatten yakın yayımlanmış ve başlığı büyük ölçüde ortak haberler.
        <strong>Birleştir</strong> dediğinizde birleşen haberin adresi kalıcı olarak (301) kalan habere yönlenir;
        birleşen haber listelerden ve site haritasından çıkar. Önerilen yön: metni daha uzun olan haber kalır.
        <a href="?gun=7">7 gün</a> · <a href="?gun=30">30 gün</a> · <a href="?gun=90">90 gün</a>
    </p>

    <table class="liste-tablo">
        <thead><tr><th>Benzerlik</th><th>Kalan haber</th><th>Birleşecek haber</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ciftler as $c):
            [$kalan, $giden] = haber_birlestirme_onerisi($c['a'], $c['b']);
            ?>
            <tr>
                <td>%<?= (int) round($c['benzerlik'] * 100) ?></td>
                <?php foreach ([$kalan, $giden] as $h): ?>
                    <td>
                        <a href="<?= e(haber_yolu((string) $h['slug'])) ?>" target="_blank" rel="noopener"><?= e((string) $h['baslik']) ?></a>
                        <div class="ipucu" style="margin:2px 0 0;">#<?= (int) $h['id'] ?> · <?= e($kisaTarih((string) $h['yayin_tarihi'])) ?>
                            · <?= (int) $h['uzunluk'] ?> karakter<?= $h['kategori_adi'] ? ' · ' . e((string) $h['kategori_adi']) : '' ?></div>
                    </td>
                <?php endforeach; ?>
                <td style="white-space:nowrap;">
                    <?php foreach ([[$giden, $kalan, 'Birleştir'], [$kalan, $giden, 'Ters yönde']] as [$k, $hd, $etiket]): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="kaynak" value="<?= (int) $k['id'] ?>">
                            <input type="hidden" name="hedef" value="<?= (int) $hd['id'] ?>">
                            <button class="dugme<?= $etiket === 'Birleştir' ? ' dugme-ana' : '' ?>" type="submit"><?= e($etiket) ?></button>
                        </form>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($ciftler === []): ?>
            <tr><td colspan="4">Bu aralıkta benzer haber çifti bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="kutu">
    <h2 style="margin-top:0;">Elle birleştir</h2>
    <p class="ipucu" style="margin-top:0;">Haber numarası ya da haberin adresi girilebilir.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <label for="kaynak">Kaldırılacak (yönlenecek) haber</label>
        <input type="text" id="kaynak" name="kaynak" style="width:100%;" placeholder="Örn. 1234 ya da https://valentra.com.tr/haber/...">
        <label for="hedef">Kalacak haber</label>
        <input type="text" id="hedef" name="hedef" style="width:100%;">
        <button class="dugme dugme-ana" type="submit">Birleştir</button>
    </form>
</div>

<?php require __DIR__ . '/alt.php'; ?>
