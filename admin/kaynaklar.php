<?php
declare(strict_types=1);

/**
 * Ajanin tarayacagi kaynaklarin yonetimi.
 * Buradaki liste api/kaynaklar.php uzerinden ajana aktarilir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

giris_zorunlu();

$hata = '';
$bildirim = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'ekle') {
        $ad         = trim((string) ($_POST['ad'] ?? ''));
        $siteUrl    = guvenli_url((string) ($_POST['site_url'] ?? ''));
        $beslemeUrl = guvenli_url((string) ($_POST['besleme_url'] ?? ''));
        $tur        = (string) ($_POST['tur'] ?? 'rss');

        if (!in_array($tur, ['rss', 'resmi', 'web'], true)) {
            $tur = 'rss';
        }

        if ($ad === '') {
            $hata = 'Kaynağa bir ad verin.';
        } elseif ($siteUrl === '') {
            $hata = 'Site adresi http:// veya https:// ile başlamalı.';
        } elseif ($beslemeUrl === '') {
            $hata = 'Besleme (RSS) adresi http:// veya https:// ile başlamalı.';
        } else {
            db()->prepare(
                'INSERT INTO kaynaklar (ad, site_url, besleme_url, tur) VALUES (:ad, :site, :besleme, :tur)'
            )->execute([
                'ad'      => mb_substr($ad, 0, 160, 'UTF-8'),
                'site'    => $siteUrl,
                'besleme' => $beslemeUrl,
                'tur'     => $tur,
            ]);

            $bildirim = 'Kaynak eklendi.';
        }

    } elseif ($islem === 'durum') {
        db()->prepare('UPDATE kaynaklar SET aktif = 1 - aktif WHERE id = :id')
            ->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        yonlendir('kaynaklar.php');

    } elseif ($islem === 'sil') {
        db()->prepare('DELETE FROM kaynaklar WHERE id = :id')
            ->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        yonlendir('kaynaklar.php');
    }
}

$kaynaklar = db()->query('SELECT * FROM kaynaklar ORDER BY aktif DESC, ad')->fetchAll();

$turAdlari = ['resmi' => 'Resmî', 'rss' => 'RSS', 'web' => 'Web'];

$panelBasligi = 'Haber kaynakları';
require __DIR__ . '/ust.php';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:14px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Kaynak ekle</h2>
    <p class="ipucu" style="margin:0 0 16px;">
        Ajan her çalıştığında bu listedeki aktif kaynakların RSS beslemesini tarar.
        Besleme adresi genelde sitenin ekonomi kategorisinin <code>/rss</code> ya da
        <code>/feed</code> adresidir.
    </p>

    <form method="post" action="kaynaklar.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="ekle">

        <div class="alan">
            <label for="ad">Kaynak adı</label>
            <input type="text" id="ad" name="ad" placeholder="Resmî Gazete" required>
        </div>

        <div class="alan">
            <label for="site_url">Site adresi</label>
            <input type="url" id="site_url" name="site_url" placeholder="https://www.resmigazete.gov.tr" required>
        </div>

        <div class="alan">
            <label for="besleme_url">Besleme (RSS/Atom) adresi</label>
            <input type="url" id="besleme_url" name="besleme_url" placeholder="https://ornek.com/ekonomi/rss" required>
        </div>

        <div class="alan">
            <label for="tur">Tür</label>
            <select id="tur" name="tur" style="width:100%;padding:10px 12px;border:1px solid var(--cizgi);border-radius:7px;font-family:inherit;font-size:.92rem;">
                <option value="resmi">Resmî kaynak (Resmî Gazete, GİB, Bakanlık)</option>
                <option value="rss" selected>Haber sitesi</option>
                <option value="web">Diğer</option>
            </select>
            <div class="ipucu">Resmî kaynaklar ajan tarafından daha yüksek güvenle işaretlenir.</div>
        </div>

        <button type="submit" class="dugme dugme-ana">Ekle</button>
    </form>
</div>

<div class="kutu">
    <h2 style="margin:0 0 14px;font-size:1.05rem;">Kaynaklar (<?= count($kaynaklar) ?>)</h2>

    <?php if ($kaynaklar === []): ?>
        <p class="ipucu" style="margin:0;">
            Henüz kaynak yok. Ajan çalışması için en az bir aktif kaynak gerekli.
        </p>
    <?php else: ?>
        <?php foreach ($kaynaklar as $kaynak): ?>
            <div style="padding:12px 0;border-bottom:1px solid var(--cizgi);">
                <div class="satir-bilgi" style="align-items:center;">
                    <strong style="color:var(--metin);"><?= e($kaynak['ad']) ?></strong>

                    <span class="rozet <?= (int) $kaynak['aktif'] === 1 ? 'rozet-yayinda' : 'rozet-reddedildi' ?>">
                        <?= (int) $kaynak['aktif'] === 1 ? 'aktif' : 'kapalı' ?>
                    </span>

                    <span class="rozet rozet-skor"><?= e($turAdlari[$kaynak['tur']] ?? $kaynak['tur']) ?></span>

                    <span>
                        son tarama:
                        <?= $kaynak['son_tarama'] !== null ? e(tarih_bicimle($kaynak['son_tarama'])) : 'hiç' ?>
                    </span>

                    <span style="margin-left:auto;display:flex;gap:8px;">
                        <form method="post" action="kaynaklar.php">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="durum">
                            <input type="hidden" name="id" value="<?= (int) $kaynak['id'] ?>">
                            <button type="submit" class="dugme">
                                <?= (int) $kaynak['aktif'] === 1 ? 'Kapat' : 'Aç' ?>
                            </button>
                        </form>

                        <form method="post" action="kaynaklar.php"
                              onsubmit="return confirm('Bu kaynak silinecek. Emin misiniz?');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="sil">
                            <input type="hidden" name="id" value="<?= (int) $kaynak['id'] ?>">
                            <button type="submit" class="dugme dugme-ret">Sil</button>
                        </form>
                    </span>
                </div>

                <div class="satir-bilgi" style="margin-top:4px;font-size:.74rem;">
                    <span><?= e($kaynak['besleme_url'] ?? '') ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
