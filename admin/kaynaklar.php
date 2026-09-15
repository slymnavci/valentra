<?php
declare(strict_types=1);

/**
 * Ajanin tarayacagi kaynaklarin yonetimi.
 * Buradaki liste api/kaynaklar.php uzerinden ajana aktarilir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/besleme_test.php';
require_once __DIR__ . '/../includes/kazima.php';

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
        $listeUrl   = guvenli_url((string) ($_POST['liste_url'] ?? ''));
        $listeSecici = trim((string) ($_POST['liste_secici'] ?? ''));
        $tur        = (string) ($_POST['tur'] ?? 'rss');

        if (!in_array($tur, ['rss', 'resmi', 'web'], true)) {
            $tur = 'rss';
        }

        if ($ad === '') {
            $hata = 'Kaynağa bir ad verin.';
        } elseif ($siteUrl === '') {
            $hata = 'Site adresi http:// veya https:// ile başlamalı.';
        } elseif ($beslemeUrl === '' && $listeUrl === '') {
            $hata = 'RSS besleme adresi ya da kazınacak duyuru sayfası adresinden '
                  . 'en az birini girin.';
        } else {
            db()->prepare(
                'INSERT INTO kaynaklar (ad, site_url, besleme_url, liste_url, liste_secici, tur)
                 VALUES (:ad, :site, :besleme, :liste, :secici, :tur)'
            )->execute([
                'ad'      => mb_substr($ad, 0, 160, 'UTF-8'),
                'site'    => $siteUrl,
                'besleme' => $beslemeUrl !== '' ? $beslemeUrl : null,
                'liste'   => $listeUrl !== '' ? $listeUrl : null,
                'secici'  => $listeSecici !== '' ? mb_substr($listeSecici, 0, 200, 'UTF-8') : null,
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

    } elseif ($islem === 'duzenle') {
        $id          = (int) ($_POST['id'] ?? 0);
        $beslemeUrl  = guvenli_url((string) ($_POST['besleme_url'] ?? ''));
        $listeUrl    = guvenli_url((string) ($_POST['liste_url'] ?? ''));
        $listeSecici = trim((string) ($_POST['liste_secici'] ?? ''));

        if ($beslemeUrl === '' && $listeUrl === '') {
            $hata = 'RSS adresi ya da duyuru sayfası adresinden en az birini girin.';
        } else {
            db()->prepare(
                'UPDATE kaynaklar
                    SET besleme_url = :besleme, liste_url = :liste, liste_secici = :secici
                  WHERE id = :id'
            )->execute([
                'besleme' => $beslemeUrl !== '' ? $beslemeUrl : null,
                'liste'   => $listeUrl !== '' ? $listeUrl : null,
                'secici'  => $listeSecici !== '' ? mb_substr($listeSecici, 0, 200, 'UTF-8') : null,
                'id'      => $id,
            ]);

            $bildirim = 'Kaynak güncellendi.';
        }

    } elseif ($islem === 'kazima_test') {
        $id = (int) ($_POST['id'] ?? 0);
        $ifade = db()->prepare('SELECT liste_url, liste_secici FROM kaynaklar WHERE id = :id');
        $ifade->execute(['id' => $id]);
        $satir = $ifade->fetch();

        if ($satir !== false && (string) $satir['liste_url'] !== '') {
            $kazinan = kazima_sayfayi_dene((string) $satir['liste_url'], (string) ($satir['liste_secici'] ?? ''));
            $testSonuclari[$id] = $kazinan;
        } else {
            $testSonuclari[$id] = [
                'tamam' => false,
                'mesaj' => 'Bu kaynak için duyuru sayfası adresi tanımlı değil.',
                'adet'  => 0,
                'ornek' => '',
            ];
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
            <input type="url" id="besleme_url" name="besleme_url" placeholder="https://ornek.com/ekonomi/rss">
            <div class="ipucu">Bilmiyorsanız boş bırakın, ekledikten sonra "Besleme bul" deyin.</div>
        </div>

        <div class="alan">
            <label for="liste_url">Duyuru sayfası adresi (RSS yoksa)</label>
            <input type="url" id="liste_url" name="liste_url" placeholder="https://kurum.gov.tr/duyurular">
            <div class="ipucu">
                RSS yayınlamayan siteler için. Ajan bu sayfadaki haber bağlantılarını
                kendisi bulur; kurumların duyuru listesi sayfası uygundur.
            </div>
        </div>

        <div class="alan">
            <label for="liste_secici">Kazıma seçicisi (isteğe bağlı)</label>
            <input type="text" id="liste_secici" name="liste_secici" placeholder=".duyuru-listesi a">
            <div class="ipucu">
                Boş bırakın — ajan haber bağlantılarını kendi bulur. Yalnızca
                yanlış bağlantılar geliyorsa CSS seçici girin.
            </div>
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

                        <?php if (($kaynak['liste_url'] ?? '') !== ''): ?>
                            <form method="post" action="kaynaklar.php">
                                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                                <input type="hidden" name="islem" value="kazima_test">
                                <input type="hidden" name="id" value="<?= (int) $kaynak['id'] ?>">
                                <button type="submit" class="dugme">Kazımayı dene</button>
                            </form>
                        <?php endif; ?>

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
                    <?php if (($kaynak['besleme_url'] ?? '') !== ''): ?>
                        <span>RSS: <?= e($kaynak['besleme_url']) ?></span>
                    <?php endif; ?>

                    <?php if (($kaynak['liste_url'] ?? '') !== ''): ?>
                        <span>Kazıma: <?= e($kaynak['liste_url']) ?>
                            <?= ($kaynak['liste_secici'] ?? '') !== ''
                                    ? ' (' . e($kaynak['liste_secici']) . ')' : '' ?></span>
                    <?php endif; ?>

                    <?php if (($kaynak['besleme_url'] ?? '') === '' && ($kaynak['liste_url'] ?? '') === ''): ?>
                        <span style="color:var(--kirmizi);">Adres tanımlı değil</span>
                    <?php endif; ?>
                </div>

                <details style="margin-top:8px;">
                    <summary style="cursor:pointer;font-size:.8rem;color:var(--vurgu);">
                        Adresleri düzenle
                    </summary>

                    <form method="post" action="kaynaklar.php"
                          style="margin-top:10px;padding:12px;background:var(--zemin);border-radius:8px;">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="islem" value="duzenle">
                        <input type="hidden" name="id" value="<?= (int) $kaynak['id'] ?>">

                        <div class="alan" style="margin-bottom:10px;">
                            <label>RSS besleme adresi</label>
                            <input type="url" name="besleme_url"
                                   value="<?= e($kaynak['besleme_url'] ?? '') ?>"
                                   placeholder="https://ornek.com/rss">
                        </div>

                        <div class="alan" style="margin-bottom:10px;">
                            <label>Duyuru sayfası adresi (RSS yoksa kazınır)</label>
                            <input type="url" name="liste_url"
                                   value="<?= e($kaynak['liste_url'] ?? '') ?>"
                                   placeholder="https://kurum.gov.tr/duyurular">
                        </div>

                        <div class="alan" style="margin-bottom:10px;">
                            <label>Kazıma seçicisi (isteğe bağlı)</label>
                            <input type="text" name="liste_secici"
                                   value="<?= e($kaynak['liste_secici'] ?? '') ?>"
                                   placeholder=".duyuru-listesi a">
                            <div class="ipucu">Boş bırakın; ajan bağlantıları kendi bulur.</div>
                        </div>

                        <button type="submit" class="dugme dugme-ana">Kaydet</button>
                    </form>
                </details>

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
