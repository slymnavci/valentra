<?php
declare(strict_types=1);

/**
 * Mac ozetleri yonetimi (04.11.2026; bkz. includes/mac_ozeti.php).
 *
 * YouTube kanal listesi (her satira bir kanal adresi), son ozetler, gizle /
 * sil, elle ekleme (YouTube adresi + baslik).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mac_ozeti.php';

giris_zorunlu();

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'kanallar') {
        $satirlar = array_filter(array_map('trim', preg_split('/\R/u', (string) ($_POST['kanallar'] ?? '')) ?: []));
        $gecersiz = array_filter($satirlar, static fn (string $s): bool => !preg_match('#^https://(www\.)?youtube\.com/(@[\w.\-]+|channel/UC[\w-]{22})/?$#', $s));

        if ($gecersiz !== []) {
            $hata = 'Şu satırlar YouTube kanal adresi değil: ' . implode(', ', $gecersiz) . '. Biçim: https://www.youtube.com/@kanaladi';
        } else {
            ayar_yaz('mac_ozeti_kanallari', implode("\n", $satirlar));
            yonlendir('mac-ozetleri.php?bildirim=kanallar');
        }
    } elseif ($islem === 'ekle') {
        $adres  = trim((string) ($_POST['adres'] ?? ''));
        $baslik = trim((string) ($_POST['baslik'] ?? ''));

        if (!preg_match('~(?:v=|youtu\.be/|/embed/|/shorts/)([\w-]{11})~', $adres, $e)) {
            $hata = 'YouTube video adresi tanınmadı (ör. https://www.youtube.com/watch?v=...).';
        } elseif (!mac_ozeti_mi($baslik)) {
            $hata = 'Başlıkta "özet" ya da "maç özeti" geçmeli (ör. "Galatasaray 3-1 Fenerbahçe | Maç Özeti").';
        } else {
            $n = mac_ozeti_ekle([['video_id' => $e[1], 'baslik' => $baslik, 'kanal' => trim((string) ($_POST['kanal'] ?? '')), 'yayin' => time()]]);
            yonlendir('mac-ozetleri.php?bildirim=' . ($n > 0 ? 'eklendi' : 'vardi'));
        }
    } elseif ($islem === 'gizle' || $islem === 'goster') {
        db()->prepare('UPDATE mac_ozetleri SET durum = :d WHERE id = :id')
            ->execute(['d' => $islem === 'gizle' ? 'gizli' : 'yayinda', 'id' => (int) ($_POST['id'] ?? 0)]);
        yonlendir('mac-ozetleri.php?bildirim=durum');
    } elseif ($islem === 'sil') {
        db()->prepare('DELETE FROM mac_ozetleri WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        yonlendir('mac-ozetleri.php?bildirim=silindi');
    }
}

$bildirim = match ((string) ($_GET['bildirim'] ?? '')) {
    'kanallar' => 'Kanal listesi kaydedildi; ajan bir sonraki çalışmada bu kanallara bakar.',
    'eklendi'  => 'Özet eklendi.',
    'vardi'    => 'Bu video zaten kayıtlı.',
    'durum'    => 'Kaydedildi.',
    'silindi'  => 'Özet silindi.',
    default    => '',
};

$ozetler = db()->query('SELECT * FROM mac_ozetleri ORDER BY yayin DESC, id DESC LIMIT 100')->fetchAll();
$toplam  = (int) db()->query("SELECT COUNT(*) FROM mac_ozetleri WHERE durum = 'yayinda'")->fetchColumn();
$son24   = (int) db()->query('SELECT COUNT(*) FROM mac_ozetleri WHERE olusturuldu > NOW() - INTERVAL 1 DAY')->fetchColumn();

$panelBasligi = 'Maç özetleri';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:20px;">
    <h2 style="margin-top:0;">Maç özetleri</h2>
    <p class="ipucu" style="margin-top:0;">
        Maçlar bittikten sonra yayıncıların YouTube kanallarına düşen özet videoları iki saatte bir
        kendiliğinden gelir. Ana sayfaya ve haber akışına girmez; ValSpor → <a href="<?= e(mac_ozetleri_yolu()) ?>" target="_blank" rel="noopener">Maç Özetleri</a>
        sayfasında ve her özetin kendi sayfasında YouTube oynatıcısıyla izlenir. Google'a site haritası ve video
        yapısal verisiyle bildirilir.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
        <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;"><div class="ipucu">Yayındaki özet</div><div style="font-size:1.8rem;font-weight:800;"><?= $toplam ?></div></div>
        <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;"><div class="ipucu">Son 24 saatte gelen</div><div style="font-size:1.8rem;font-weight:800;"><?= $son24 ?></div></div>
    </div>
</div>

<div class="istatistik-ikili">
    <div class="kutu">
        <h2 style="margin-top:0;">YouTube kanalları</h2>
        <form method="post" action="mac-ozetleri.php" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="kanallar">
            <textarea name="kanallar" rows="8" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem;"><?= e(implode("\n", mac_ozeti_kanallari())) ?></textarea>
            <p class="ipucu">Her satıra bir kanal: <code>https://www.youtube.com/@kanaladi</code>. Yalnızca başlığında “özet”, “maç özeti”, “geniş özet” ya da “highlights” geçen videolar alınır; kısa videolar (#shorts) ve canlı yayınlar alınmaz. Okunamayan kanal GitHub'daki çalışma kaydında yazar.</p>
            <button type="submit" class="dugme dugme-ana">Kaydet</button>
        </form>
    </div>
    <div class="kutu">
        <h2 style="margin-top:0;">Elle ekle</h2>
        <form method="post" action="mac-ozetleri.php" style="display:grid;gap:10px;margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="ekle">
            <label>YouTube adresi<br><input type="url" name="adres" required placeholder="https://www.youtube.com/watch?v=..." style="width:100%;"></label>
            <label>Başlık<br><input type="text" name="baslik" required maxlength="300" placeholder="Galatasaray 3-1 Fenerbahçe | Maç Özeti" style="width:100%;"></label>
            <label>Yayıncı <span class="ipucu">(isteğe bağlı)</span><br><input type="text" name="kanal" maxlength="120" placeholder="beIN SPORTS Türkiye" style="width:100%;"></label>
            <div><button type="submit" class="dugme dugme-ana">Ekle</button></div>
        </form>
    </div>
</div>

<div class="kutu" style="margin-top:20px;overflow-x:auto;">
    <h2 style="margin-top:0;">Son özetler</h2>
    <?php if ($ozetler === []): ?>
        <p class="ipucu">Henüz özet yok. Ajan iki saatte bir kanallara bakar; ilk özetler maçlar bittikten sonra gelir.</p>
    <?php else: ?>
        <table class="liste-tablo">
            <thead><tr><th></th><th>Özet</th><th>Yayıncı</th><th>Tarih</th><th>Durum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ozetler as $o): ?>
                <tr>
                    <td><img src="<?= e(mac_ozeti_gorsel($o, 'mqdefault')) ?>" alt="" width="96" height="54" style="border-radius:6px;object-fit:cover;"></td>
                    <td><a href="<?= e(mac_ozeti_yolu((string) $o['slug'])) ?>" target="_blank" rel="noopener"><?= e((string) $o['baslik']) ?></a>
                        <?php if ((string) $o['skor'] !== ''): ?><br><span class="ipucu"><?= e($o['ev'] . ' ' . $o['skor'] . ' ' . $o['deplasman']) ?></span><?php endif; ?></td>
                    <td><?= e((string) $o['kanal']) ?></td>
                    <td><?= e(tarih_bicimle((string) $o['yayin'])) ?></td>
                    <td><?= $o['durum'] === 'yayinda' ? 'Yayında' : 'Gizli' ?></td>
                    <td>
                        <form method="post" action="mac-ozetleri.php" style="margin:0;display:flex;gap:6px;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                            <?php if ($o['durum'] === 'yayinda'): ?>
                                <button type="submit" name="islem" value="gizle" class="dugme">Gizle</button>
                            <?php else: ?>
                                <button type="submit" name="islem" value="goster" class="dugme dugme-ana">Yayınla</button>
                            <?php endif; ?>
                            <button type="submit" name="islem" value="sil" class="dugme dugme-ret" onclick="return confirm('Özet silinsin mi?')">Sil</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
