<?php
declare(strict_types=1);

/**
 * Valentra Network: yorumlar ve uyeler (31.10.2026).
 *
 * Yorumlar kufur filtresinden gecince hemen yayinda (bkz.
 * includes/yorumlar.php). Burada:
 *   - Son yorumlar: yayindakiler, en yeni ustte; gizle ya da sil.
 *   - Gizli: uc sikayetle kendiliginden gizlenenler ve elle gizlenenler;
 *     yeniden yayinla ya da sil.
 *   - Uyeler: engelle (giris yapamaz, yorumlari gorunmez) ya da engeli kaldir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/yorumlar.php';
require_once __DIR__ . '/../includes/network.php';

giris_zorunlu();

$sekme = (string) ($_GET['sekme'] ?? 'yayinda');
$sekme = in_array($sekme, ['yayinda', 'gizli', 'paylasimlar', 'uyeler'], true) ? $sekme : 'yayinda';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);

    if ($islem === 'gizle' || $islem === 'yayinla') {
        db()->prepare('UPDATE yorumlar SET durum = :d' . ($islem === 'yayinla' ? ', sikayet = 0' : '') . ' WHERE id = :id')
            ->execute(['d' => $islem === 'gizle' ? 'gizli' : 'yayinda', 'id' => $id]);

        if ($islem === 'yayinla') {
            db()->prepare('DELETE FROM yorum_sikayetleri WHERE yorum_id = :id')->execute(['id' => $id]);
        }
    } elseif ($islem === 'sil') {
        db()->prepare('DELETE FROM yorumlar WHERE id = :id')->execute(['id' => $id]);
    } elseif ($islem === 'g_gizle' || $islem === 'g_yayinla') {
        // V Network paylasimlari (31.10.2026).
        db()->prepare('UPDATE gonderiler SET durum = :d' . ($islem === 'g_yayinla' ? ', sikayet = 0' : '') . ' WHERE id = :id')
            ->execute(['d' => $islem === 'g_gizle' ? 'gizli' : 'yayinda', 'id' => $id]);

        if ($islem === 'g_yayinla') {
            db()->prepare('DELETE FROM gonderi_sikayetleri WHERE gonderi_id = :id')->execute(['id' => $id]);
        }
    } elseif ($islem === 'g_sil') {
        $fotolar = db()->prepare('SELECT dosya FROM gonderi_fotolari WHERE gonderi_id = :id');
        $fotolar->execute(['id' => $id]);
        $dosyalar = $fotolar->fetchAll(PDO::FETCH_COLUMN);
        db()->prepare('DELETE FROM gonderiler WHERE id = :id')->execute(['id' => $id]);

        foreach ($dosyalar as $dosya) {
            nw_foto_sil((string) $dosya);
        }
    } elseif ($islem === 'engelle' || $islem === 'engel_kaldir') {
        db()->prepare('UPDATE uyeler SET durum = :d WHERE id = :id')
            ->execute(['d' => $islem === 'engelle' ? 'engelli' : 'aktif', 'id' => $id]);

        if ($islem === 'engelle') {
            db()->prepare('DELETE FROM uye_oturumlari WHERE uye_id = :id')->execute(['id' => $id]);
        }
    }

    yonlendir('yorumlar.php?sekme=' . $sekme . '&bildirim=1');
}

$sayilar = ['yayinda' => 0, 'gizli' => 0, 'paylasimlar' => 0, 'gizli_paylasim' => 0, 'uyeler' => 0];
$satirlar = [];

try {
    foreach (db()->query('SELECT durum, COUNT(*) AS n FROM yorumlar GROUP BY durum') as $s) {
        $sayilar[(string) $s['durum']] = (int) $s['n'];
    }

    $sayilar['uyeler'] = (int) db()->query('SELECT COUNT(*) FROM uyeler')->fetchColumn();
    $sayilar['paylasimlar'] = (int) db()->query('SELECT COUNT(*) FROM gonderiler')->fetchColumn();
    $sayilar['gizli_paylasim'] = (int) db()->query("SELECT COUNT(*) FROM gonderiler WHERE durum = 'gizli'")->fetchColumn();

    if ($sekme === 'paylasimlar') {
        // Gizlenenler (sikayetle ya da elle) ustte.
        $satirlar = db()->query(
            "SELECT g.id, g.metin, g.video_url, g.durum, g.sikayet, g.olusturuldu, g.ip,
                    u.id AS uye_id, u.ad, u.eposta, u.durum AS uye_durum,
                    (SELECT COUNT(*) FROM gonderi_fotolari f WHERE f.gonderi_id = g.id) AS foto_sayisi
               FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id
              ORDER BY g.durum = 'gizli' DESC, g.sikayet DESC, g.id DESC LIMIT 300"
        )->fetchAll();
        $fotolar = gonderi_fotolari(array_map(static fn (array $g): int => (int) $g['id'], $satirlar));
    } elseif ($sekme === 'uyeler') {
        $satirlar = db()->query(
            'SELECT u.id, u.ad, u.eposta, u.durum, u.olusturuldu, u.son_giris,
                    (SELECT COUNT(*) FROM yorumlar y WHERE y.uye_id = u.id) AS yorum_sayisi
               FROM uyeler u ORDER BY u.id DESC LIMIT 300'
        )->fetchAll();
    } else {
        $ifade = db()->prepare(
            'SELECT y.id, y.metin, y.durum, y.sikayet, y.olusturuldu, y.ip,
                    u.id AS uye_id, u.ad, u.eposta, u.durum AS uye_durum,
                    h.baslik, h.slug, y.gonderi_id
               FROM yorumlar y
               JOIN uyeler u ON u.id = y.uye_id
               LEFT JOIN haberler h ON h.id = y.haber_id
              WHERE y.durum = :d
              ORDER BY ' . ($sekme === 'gizli' ? 'y.sikayet DESC, ' : '') . 'y.id DESC
              LIMIT 300'
        );
        $ifade->execute(['d' => $sekme]);
        $satirlar = $ifade->fetchAll();
    }
} catch (PDOException $e) {
    $satirlar = [];
}

$panelBasligi = 'V Network: yorumlar, paylaşımlar ve üyeler';
require __DIR__ . '/ust.php';
?>

<?php if (isset($_GET['bildirim'])): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;">İşlem tamamlandı.</div>
<?php endif; ?>

<div class="kutu" style="margin-top:20px;">
    <strong>VALENTRA Network</strong>
    <p class="ipucu" style="margin:4px 0 0;">
        Üyelerin yorumları ve paylaşımları küfür filtresinden geçince hemen yayımlanır. Üç ayrı üyenin
        şikâyet ettiği yorum ya da paylaşım kendiliğinden gizlenir (“Gizli” ve “Paylaşımlar” sekmeleri).
        Engellenen üye giriş yapamaz, yorumları sitede görünmez.
        Akış: <a href="/network" target="_blank" rel="noopener">valentra.com.tr/network</a>
    </p>
</div>

<nav class="sekmeler">
    <a href="?sekme=yayinda" class="<?= $sekme === 'yayinda' ? 'aktif' : '' ?>">Son yorumlar (<?= $sayilar['yayinda'] ?>)</a>
    <a href="?sekme=gizli" class="<?= $sekme === 'gizli' ? 'aktif' : '' ?>">
        Gizli / şikâyetli
        <?php if ($sayilar['gizli'] > 0): ?><span class="adet"><?= $sayilar['gizli'] ?></span><?php endif; ?>
    </a>
    <a href="?sekme=paylasimlar" class="<?= $sekme === 'paylasimlar' ? 'aktif' : '' ?>">
        Paylaşımlar (<?= $sayilar['paylasimlar'] ?>)
        <?php if ($sayilar['gizli_paylasim'] > 0): ?><span class="adet" title="Gizli"><?= $sayilar['gizli_paylasim'] ?></span><?php endif; ?>
    </a>
    <a href="?sekme=uyeler" class="<?= $sekme === 'uyeler' ? 'aktif' : '' ?>">Üyeler (<?= $sayilar['uyeler'] ?>)</a>
</nav>

<?php if ($satirlar === []): ?>
    <div class="kutu" style="margin-top:16px;"><p class="ipucu" style="margin:0;">Bu sekmede kayıt yok.</p></div>
<?php elseif ($sekme === 'paylasimlar'): ?>
    <?php foreach ($satirlar as $g): ?>
        <div class="kutu" style="margin-top:12px;<?= $g['durum'] === 'gizli' ? 'border-left:4px solid #c0121b;' : '' ?>">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div>
                    <strong><?= e((string) $g['ad']) ?></strong>
                    <span class="ipucu">&lt;<?= e((string) $g['eposta']) ?>&gt; · <?= e(tarih_bicimle((string) $g['olusturuldu'])) ?> · IP <?= e((string) $g['ip']) ?></span>
                    <?php if ($g['durum'] === 'gizli'): ?><span class="adet">gizli</span><?php endif; ?>
                    <?php if ((int) $g['sikayet'] > 0): ?><span class="adet"><?= (int) $g['sikayet'] ?> şikâyet</span><?php endif; ?>
                    <div class="ipucu"><a href="<?= e(nw_gonderi_yolu((int) $g['id'])) ?>" target="_blank" rel="noopener">Paylaşımı aç</a><?= (int) $g['foto_sayisi'] > 0 ? ' · ' . (int) $g['foto_sayisi'] . ' fotoğraf' : '' ?><?= (string) $g['video_url'] !== '' ? ' · video: ' . e((string) $g['video_url']) : '' ?></div>
                </div>
                <form method="post" style="margin:0;display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <?php if ($g['durum'] === 'yayinda'): ?>
                        <button type="submit" name="islem" value="g_gizle" class="dugme">Gizle</button>
                    <?php else: ?>
                        <button type="submit" name="islem" value="g_yayinla" class="dugme dugme-ana">Yeniden yayınla</button>
                    <?php endif; ?>
                    <button type="submit" name="islem" value="g_sil" class="dugme" onclick="return confirm('Paylaşım ve fotoğrafları kalıcı olarak silinsin mi?')">Sil</button>
                </form>
            </div>
            <?php if (trim((string) $g['metin']) !== ''): ?>
                <p style="margin:10px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;"><?= e((string) $g['metin']) ?></p>
            <?php endif; ?>
            <?php if (!empty($fotolar[(int) $g['id']])): ?>
                <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">
                    <?php foreach ($fotolar[(int) $g['id']] as $f): ?>
                        <a href="<?= e(nw_foto_adresi((string) $f['dosya'])) ?>" target="_blank" rel="noopener"><img src="<?= e(nw_foto_adresi((string) $f['dosya'])) ?>" alt="" style="height:90px;border-radius:6px;"></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php elseif ($sekme === 'uyeler'): ?>
    <div class="kutu" style="margin-top:16px;overflow-x:auto;">
        <table class="liste-tablo">
            <thead><tr><th>Ad</th><th>E-posta</th><th>Yorum</th><th>Kayıt</th><th>Son giriş</th><th>Durum</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($satirlar as $u): ?>
                    <tr>
                        <td><?= e((string) $u['ad']) ?></td>
                        <td><?= e((string) $u['eposta']) ?></td>
                        <td><?= (int) $u['yorum_sayisi'] ?></td>
                        <td><?= e(tarih_bicimle((string) $u['olusturuldu'])) ?></td>
                        <td><?= $u['son_giris'] ? e(tarih_bicimle((string) $u['son_giris'])) : '—' ?></td>
                        <td><?= $u['durum'] === 'aktif' ? 'Aktif' : '<strong style="color:#c0121b">Engelli</strong>' ?></td>
                        <td>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <?php if ($u['durum'] === 'aktif'): ?>
                                    <button type="submit" name="islem" value="engelle" class="dugme" onclick="return confirm('Üye engellensin mi? Yorumları sitede görünmez olur.')">Engelle</button>
                                <?php else: ?>
                                    <button type="submit" name="islem" value="engel_kaldir" class="dugme">Engeli kaldır</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <?php foreach ($satirlar as $y): ?>
        <div class="kutu" style="margin-top:12px;">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div>
                    <strong><?= e((string) $y['ad']) ?></strong>
                    <span class="ipucu">&lt;<?= e((string) $y['eposta']) ?>&gt; · <?= e(tarih_bicimle((string) $y['olusturuldu'])) ?> · IP <?= e((string) $y['ip']) ?></span>
                    <?php if ((int) $y['sikayet'] > 0): ?><span class="adet" title="Şikâyet"><?= (int) $y['sikayet'] ?> şikâyet</span><?php endif; ?>
                    <?php if ($y['uye_durum'] !== 'aktif'): ?><span class="ipucu" style="color:#c0121b;"> · üye engelli</span><?php endif; ?>
                    <?php if (!empty($y['gonderi_id'])): ?>
                        <div class="ipucu">V Network paylaşımına yorum: <a href="<?= e(nw_gonderi_yolu((int) $y['gonderi_id'])) ?>#yorum-<?= (int) $y['id'] ?>" target="_blank" rel="noopener">paylaşımı aç</a></div>
                    <?php else: ?>
                        <div class="ipucu">Haber: <a href="<?= e(haber_yolu((string) $y['slug'])) ?>#yorum-<?= (int) $y['id'] ?>" target="_blank" rel="noopener"><?= e((string) $y['baslik']) ?></a></div>
                    <?php endif; ?>
                </div>
                <form method="post" style="margin:0;display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $y['id'] ?>">
                    <?php if ($y['durum'] === 'yayinda'): ?>
                        <button type="submit" name="islem" value="gizle" class="dugme">Gizle</button>
                    <?php else: ?>
                        <button type="submit" name="islem" value="yayinla" class="dugme dugme-ana">Yeniden yayınla</button>
                    <?php endif; ?>
                    <button type="submit" name="islem" value="sil" class="dugme" onclick="return confirm('Yorum kalıcı olarak silinsin mi?')">Sil</button>
                </form>
            </div>
            <p style="margin:10px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;"><?= e((string) $y['metin']) ?></p>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
