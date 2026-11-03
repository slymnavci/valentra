<?php
declare(strict_types=1);

/**
 * Canli anlatim yonetimi (03.11.2026; bkz. includes/canli.php).
 *
 * Liste: yeni anlatim ac, acik/biten anlatimlar. Bir anlatim acilinca:
 * gelisme ekle (baslik istege bagli, metin, "onemli", "bildirim gonder"),
 * gelisme sil, anlatimi bitir ya da yeniden ac, baslik/ozet duzenle.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/canli.php';
require_once __DIR__ . '/../includes/web_push.php';

giris_zorunlu();

$hata = '';
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    try {
        if ($islem === 'olustur') {
            $yeni = canli_olustur((string) ($_POST['baslik'] ?? ''), (string) ($_POST['ozet'] ?? ''), (string) ($_POST['gorsel'] ?? ''));
            yonlendir('canli.php?id=' . $yeni . '&bildirim=acildi');
        } elseif ($islem === 'giris') {
            canli_giris_ekle(
                $id,
                (string) ($_POST['baslik'] ?? ''),
                (string) ($_POST['metin'] ?? ''),
                !empty($_POST['onemli']),
                !empty($_POST['bildirim'])
            );
            yonlendir('canli.php?id=' . $id . '&bildirim=eklendi');
        } elseif ($islem === 'giris_sil') {
            db()->prepare('DELETE FROM canli_girisler WHERE id = :g AND anlatim_id = :a')
                ->execute(['g' => (int) ($_POST['giris'] ?? 0), 'a' => $id]);
            yonlendir('canli.php?id=' . $id . '&bildirim=silindi');
        } elseif ($islem === 'bitir' || $islem === 'ac') {
            canli_durum($id, $islem === 'ac');
            yonlendir('canli.php?id=' . $id . '&bildirim=' . $islem);
        } elseif ($islem === 'duzenle') {
            $gorsel = trim((string) ($_POST['gorsel'] ?? ''));

            if ($gorsel !== '' && !preg_match('#^https://#i', $gorsel)) {
                throw new InvalidArgumentException('Görsel adresi https:// ile başlamalı.');
            }

            db()->prepare('UPDATE canli_anlatimlar SET baslik = :b, ozet = :o, gorsel_url = :g WHERE id = :id')
                ->execute(['b' => mb_substr(trim((string) ($_POST['baslik'] ?? '')), 0, 200) ?: 'Canlı anlatım',
                           'o' => mb_substr(trim((string) ($_POST['ozet'] ?? '')), 0, 400), 'g' => mb_substr($gorsel, 0, 500), 'id' => $id]);
            yonlendir('canli.php?id=' . $id . '&bildirim=kaydedildi');
        } elseif ($islem === 'sil') {
            db()->prepare('DELETE FROM canli_anlatimlar WHERE id = :id')->execute(['id' => $id]);
            yonlendir('canli.php?bildirim=anlatim_silindi');
        }
    } catch (InvalidArgumentException $e) {
        $hata = $e->getMessage();
    }
}

$bildirim = match ((string) ($_GET['bildirim'] ?? '')) {
    'acildi'          => 'Canlı anlatım açıldı. İlk gelişmeyi ekleyin; sitenin üstünde kırmızı "CANLI" bandı görünür.',
    'eklendi'         => 'Gelişme yayımlandı.',
    'silindi'         => 'Gelişme silindi.',
    'bitir'           => 'Canlı anlatım sona erdi; sayfa arşivde kalır, üst bant kalkar.',
    'ac'              => 'Canlı anlatım yeniden açıldı.',
    'kaydedildi'      => 'Kaydedildi.',
    'anlatim_silindi' => 'Canlı anlatım silindi.',
    default           => '',
};

$anlatim  = $id > 0 ? canli_bul_id($id) : null;
$girisler = $anlatim !== null ? canli_girisler((int) $anlatim['id']) : [];
$liste    = $anlatim === null ? canli_liste(100) : [];
$abone    = push_abone_sayisi();

$panelBasligi = 'Canlı anlatım';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>

<?php if ($anlatim === null): ?>

    <div class="kutu" style="margin-top:20px;">
        <h2 style="margin-top:0;">Yeni canlı anlatım</h2>
        <p class="ipucu" style="margin-top:0;">
            Seçim, deprem, büyük maç gibi olaylarda dakika dakika güncellenen tek sayfa. Açık anlatım
            sitenin bütün sayfalarının üstünde kırmızı “CANLI” bandıyla duyurulur; okurun sayfası
            yeni gelişmeleri kendiliğinden alır. Olay bitince “Sona erdir” deyin.
        </p>
        <form method="post" action="canli.php" style="display:grid;gap:10px;margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="olustur">
            <label>Başlık<br><input type="text" name="baslik" required minlength="5" maxlength="200" placeholder="ör. 31 Mart seçim sonuçları canlı" style="width:100%;"></label>
            <label>Kısa açıklama <span class="ipucu">(isteğe bağlı)</span><br><textarea name="ozet" rows="2" maxlength="400" style="width:100%;"></textarea></label>
            <label>Görsel adresi <span class="ipucu">(isteğe bağlı, https://…)</span><br><input type="url" name="gorsel" maxlength="500" style="width:100%;"></label>
            <div><button type="submit" class="dugme dugme-ana">Canlı anlatımı aç</button></div>
        </form>
    </div>

    <div class="kutu" style="margin-top:20px;overflow-x:auto;">
        <h2 style="margin-top:0;">Anlatımlar</h2>
        <?php if ($liste === []): ?>
            <p class="ipucu">Henüz canlı anlatım yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Başlık</th><th>Durum</th><th class="sag">Gelişme</th><th>Son güncelleme</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($liste as $a): ?>
                    <tr>
                        <td><strong><?= e((string) $a['baslik']) ?></strong></td>
                        <td><?= $a['durum'] === 'canli' ? '<strong style="color:#d50000;">● Canlı</strong>' : 'Sona erdi' ?></td>
                        <td class="sag"><?= (int) $a['giris_sayisi'] ?></td>
                        <td><?= e(tarih_bicimle((string) $a['guncellendi'])) ?></td>
                        <td><a href="canli.php?id=<?= (int) $a['id'] ?>" class="dugme dugme-ana">Aç</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php $acik = $anlatim['durum'] === 'canli'; ?>
    <p style="margin:18px 0 0;"><a href="canli.php">← Bütün anlatımlar</a></p>

    <div class="kutu" style="margin-top:12px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
        <div>
            <?= $acik ? '<strong style="color:#d50000;">● CANLI</strong>' : '<strong>Sona erdi</strong>' ?>
            <h2 style="margin:4px 0;"><?= e((string) $anlatim['baslik']) ?></h2>
            <a href="<?= e(canli_yolu((string) $anlatim['slug'])) ?>" target="_blank" rel="noopener">Sayfayı aç →</a>
            <span class="ipucu">&middot; <?= count($girisler) ?> gelişme</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" action="canli.php" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="id" value="<?= (int) $anlatim['id'] ?>">
                <?php if ($acik): ?>
                    <button type="submit" name="islem" value="bitir" class="dugme" onclick="return confirm('Canlı anlatım sona ersin mi? Sayfa arşivde kalır.')">Sona erdir</button>
                <?php else: ?>
                    <button type="submit" name="islem" value="ac" class="dugme dugme-ana">Yeniden aç</button>
                <?php endif; ?>
                <button type="submit" name="islem" value="sil" class="dugme dugme-ret" onclick="return confirm('Anlatım ve bütün gelişmeleri kalıcı olarak silinsin mi?')">Sil</button>
            </form>
        </div>
    </div>

    <div class="kutu" style="margin-top:20px;border:2px solid #d50000;">
        <h2 style="margin-top:0;">Gelişme ekle</h2>
        <form method="post" action="canli.php" style="display:grid;gap:10px;margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="giris">
            <input type="hidden" name="id" value="<?= (int) $anlatim['id'] ?>">
            <label>Başlık <span class="ipucu">(isteğe bağlı, kalın görünür)</span><br><input type="text" name="baslik" maxlength="200" style="width:100%;"></label>
            <label>Metin<br><textarea name="metin" rows="5" maxlength="5000" style="width:100%;" placeholder="Paragraflar arasında boş satır bırakın. Bağlantılar kendiliğinden tıklanır olur."></textarea></label>
            <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="onemli" value="1"> Önemli gelişme (kırmızı çerçeveyle öne çıkar)</label>
            <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="bildirim" value="1"<?= $abone === 0 ? ' disabled' : '' ?>>
                Tarayıcı bildirimi olarak da gönder <span class="ipucu">(<?= $abone ?> abone<?= $abone === 0 ? ', henüz kimse yok' : '' ?>; sık kullanmayın)</span></label>
            <div><button type="submit" class="dugme dugme-ana">Yayımla</button></div>
        </form>
    </div>

    <div class="kutu" style="margin-top:20px;">
        <h2 style="margin-top:0;">Yayımlanan gelişmeler</h2>
        <?php if ($girisler === []): ?>
            <p class="ipucu">Henüz gelişme yok.</p>
        <?php endif; ?>
        <?php foreach ($girisler as $g): ?>
            <div style="display:flex;gap:14px;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--cizgi);">
                <div style="min-width:0;">
                    <strong><?= e(date('H:i', strtotime((string) $g['olusturuldu']))) ?></strong>
                    <?= (int) $g['onemli'] === 1 ? '<strong style="color:#d50000;"> · Önemli</strong>' : '' ?>
                    <?php if ((string) $g['baslik'] !== ''): ?><div style="font-weight:800;"><?= e((string) $g['baslik']) ?></div><?php endif; ?>
                    <div class="ipucu" style="white-space:pre-line;"><?= e(mb_strimwidth((string) $g['metin'], 0, 400, '…')) ?></div>
                </div>
                <form method="post" action="canli.php" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="islem" value="giris_sil">
                    <input type="hidden" name="id" value="<?= (int) $anlatim['id'] ?>">
                    <input type="hidden" name="giris" value="<?= (int) $g['id'] ?>">
                    <button type="submit" class="dugme dugme-ret" onclick="return confirm('Gelişme silinsin mi?')">Sil</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <details class="kutu" style="margin-top:20px;">
        <summary style="cursor:pointer;font-weight:700;">Başlık, açıklama ve görseli düzenle</summary>
        <form method="post" action="canli.php" style="display:grid;gap:10px;margin:12px 0 0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="duzenle">
            <input type="hidden" name="id" value="<?= (int) $anlatim['id'] ?>">
            <label>Başlık<br><input type="text" name="baslik" value="<?= e((string) $anlatim['baslik']) ?>" maxlength="200" style="width:100%;"></label>
            <label>Kısa açıklama<br><textarea name="ozet" rows="2" maxlength="400" style="width:100%;"><?= e((string) $anlatim['ozet']) ?></textarea></label>
            <label>Görsel adresi<br><input type="url" name="gorsel" value="<?= e((string) $anlatim['gorsel_url']) ?>" maxlength="500" style="width:100%;"></label>
            <div><button type="submit" class="dugme dugme-ana">Kaydet</button></div>
        </form>
    </details>

<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
