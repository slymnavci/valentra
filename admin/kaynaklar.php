<?php
declare(strict_types=1);

/**
 * Ajanin tarayacagi kaynaklarin yonetimi.
 * Buradaki liste api/kaynaklar.php uzerinden ajana aktarilir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/besleme_test.php';

giris_zorunlu();

$hata = '';
$bildirim = '';
/** @var array<int,array{tamam:bool,mesaj:string,adet:int,ornek:string}> */
$testSonuclari = [];

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

    } elseif ($islem === 'test') {
        $id = (int) ($_POST['id'] ?? 0);
        $ifade = db()->prepare('SELECT besleme_url FROM kaynaklar WHERE id = :id');
        $ifade->execute(['id' => $id]);
        $url = (string) $ifade->fetchColumn();

        if ($url !== '') {
            $testSonuclari[$id] = besleme_dene($url);
        }

    } elseif ($islem === 'bul') {
        $id = (int) ($_POST['id'] ?? 0);
        $ifade = db()->prepare('SELECT site_url FROM kaynaklar WHERE id = :id');
        $ifade->execute(['id' => $id]);
        $siteUrl = (string) $ifade->fetchColumn();

        if ($siteUrl !== '') {
            $kesif = besleme_kesfet($siteUrl);

            if ($kesif['bulundu']) {
                db()->prepare('UPDATE kaynaklar SET besleme_url = :url WHERE id = :id')
                    ->execute(['url' => $kesif['url'], 'id' => $id]);

                $bildirim = 'Besleme bulundu ve kaydedildi: ' . $kesif['url'];
            } else {
                $testSonuclari[$id] = [
                    'tamam' => false,
                    'mesaj' => $kesif['mesaj'],
                    'adet'  => 0,
                    'ornek' => '',
                ];
            }
        }

    } elseif ($islem === 'bozuklari_bul') {
        // Once calismayanlari belirle, sonra her biri icin besleme ara.
        $bulunan = 0;
        $denenen = 0;

        foreach (db()->query('SELECT id, site_url, besleme_url FROM kaynaklar WHERE aktif = 1')->fetchAll() as $k) {
            $mevcut = besleme_dene((string) $k['besleme_url'], 8);

            if ($mevcut['tamam']) {
                continue;
            }

            $denenen++;
            $kesif = besleme_kesfet((string) $k['site_url'], 6);

            if ($kesif['bulundu']) {
                db()->prepare('UPDATE kaynaklar SET besleme_url = :url WHERE id = :id')
                    ->execute(['url' => $kesif['url'], 'id' => (int) $k['id']]);
                $bulunan++;
            } else {
                $testSonuclari[(int) $k['id']] = [
                    'tamam' => false, 'mesaj' => $kesif['mesaj'], 'adet' => 0, 'ornek' => '',
                ];
            }
        }

        $bildirim = $denenen . ' çalışmayan kaynak incelendi, ' . $bulunan . ' tanesinin beslemesi bulundu.';

    } elseif ($islem === 'hepsini_test') {
        foreach (db()->query('SELECT id, besleme_url FROM kaynaklar WHERE aktif = 1')->fetchAll() as $k) {
            $testSonuclari[(int) $k['id']] = besleme_dene((string) $k['besleme_url'], 10);
        }

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
        Besleme adresini bilmiyorsanız yalnızca site adresini girin ve ekledikten
        sonra <strong>Besleme bul</strong> düğmesine basın; sitenin ilan ettiği
        RSS adresi otomatik bulunur.
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
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <h2 style="margin:0;font-size:1.05rem;">Kaynaklar (<?= count($kaynaklar) ?>)</h2>

        <?php if ($kaynaklar !== []): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" action="kaynaklar.php">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="islem" value="hepsini_test">
                    <button type="submit" class="dugme">Hepsini test et</button>
                </form>

                <form method="post" action="kaynaklar.php">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="islem" value="bozuklari_bul">
                    <button type="submit" class="dugme dugme-ana">Çalışmayanların beslemesini bul</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($testSonuclari !== []): ?>
        <?php $calisan = count(array_filter($testSonuclari, static fn (array $t): bool => $t['tamam'])); ?>
        <div class="uyari uyari-bilgi" style="margin-bottom:14px;">
            <?= count($testSonuclari) ?> kaynak denendi, <?= $calisan ?> tanesi çalışıyor.
            Çalışmayanların besleme adresini düzeltin ya da kaynağı kapatın;
            ajan kapalı kaynakları taramaz.
        </div>
    <?php endif; ?>

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
                            <input type="hidden" name="islem" value="test">
                            <input type="hidden" name="id" value="<?= (int) $kaynak['id'] ?>">
                            <button type="submit" class="dugme">Test et</button>
                        </form>

                        <form method="post" action="kaynaklar.php">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="bul">
                            <input type="hidden" name="id" value="<?= (int) $kaynak['id'] ?>">
                            <button type="submit" class="dugme">Besleme bul</button>
                        </form>

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

                <?php $test = $testSonuclari[(int) $kaynak['id']] ?? null; ?>
                <?php if ($test !== null): ?>
                    <div class="uyari uyari-<?= $test['tamam'] ? 'basari' : 'hata' ?>"
                         style="margin:8px 0 0;font-size:.82rem;">
                        <?= e($test['mesaj']) ?>
                        <?php if ($test['ornek'] !== ''): ?>
                            <br><span style="opacity:.75;">Örnek başlık: <?= e($test['ornek']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
