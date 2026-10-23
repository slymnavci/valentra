<?php
declare(strict_types=1);

/**
 * Videolu haberler: video ajanının çalışmaları ve sitedeki videolu haberler.
 *
 * Site sahibinin istegi (23.10.2026): "videolu haberler icin ayri bir
 * ajan kuralim. bunlari takip edebileyim. bakalim haberler geliyor mu
 * nasil geliyor gorelim." Ajan GitHub'da calisiyor (video.yml); her
 * calismanin raporunda kanal kanal ve video video ne oldugu yaziyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ajan_tetikle.php';

giris_zorunlu();

$tetikMesaji = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);
    $tetikMesaji = ajan_video_tetikle(($_POST['kuru'] ?? '') === '1', (int) ($_POST['saat'] ?? 12));
}

$anahtarVar  = ajan_tetikleyebilir_mi();
$calismalar  = $anahtarVar ? ajan_video_calismalari() : [];

$videolar = db()->query(
    "SELECT id, baslik, slug, durum, kaynak_adi, kaynak_url, iframe_url, olusturuldu
       FROM haberler
      WHERE iframe_url IS NOT NULL AND iframe_url <> ''
      ORDER BY olusturuldu DESC
      LIMIT 50"
)->fetchAll();

$sayim = db()->query(
    "SELECT SUM(olusturuldu >= NOW() - INTERVAL 1 DAY) AS gun,
            SUM(olusturuldu >= NOW() - INTERVAL 7 DAY) AS hafta,
            COUNT(*) AS toplam
       FROM haberler
      WHERE iframe_url IS NOT NULL AND iframe_url <> ''"
)->fetch() ?: ['gun' => 0, 'hafta' => 0, 'toplam' => 0];

$durumAdi = ['taslak' => 'Onay bekliyor', 'yayinda' => 'Yayında', 'reddedildi' => 'Reddedildi'];

$panelBasligi = 'Videolu haberler';
require __DIR__ . '/ust.php';
?>

<h1 class="sayfa-basligi">Videolu haberler</h1>

<?php if ($tetikMesaji !== null): ?>
    <div class="uyari uyari-<?= $tetikMesaji['tamam'] ? 'basari' : 'hata' ?>" style="margin-top:20px;">
        <?= e($tetikMesaji['mesaj']) ?>
    </div>
<?php endif; ?>

<div class="olcum-izgara" style="margin-top:20px;">
    <div class="olcum"><div class="olcum-ad">Son 24 saat</div><div class="olcum-sayi"><?= (int) $sayim['gun'] ?></div></div>
    <div class="olcum"><div class="olcum-ad">Son 7 gün</div><div class="olcum-sayi"><?= (int) $sayim['hafta'] ?></div></div>
    <div class="olcum"><div class="olcum-ad">Toplam</div><div class="olcum-sayi"><?= (int) $sayim['toplam'] ?></div></div>
</div>

<div class="kutu toplama-cubugu" style="margin-top:20px;">
    <div>
        <strong>Video haber ajanı</strong>
        <p class="ipucu" style="margin:4px 0 0;">
            İki saatte bir haber kuruluşlarının YouTube kanallarını tarar
            (TRT Haber, NTV, CNN TÜRK, Habertürk, Bloomberg HT, TRT Spor),
            yeni videolardan haber yazar; video haberin içinde oynar.
            Kanallar <a href="kaynaklar.php">Kaynaklar</a> sayfasından
            yönetilir. Her çalışmanın raporunda hangi videonun neden
            alınmadığı yazar.
        </p>
    </div>

    <?php if ($anahtarVar): ?>
        <form method="post" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <label class="ipucu" style="margin:0;">Son
                <input type="number" name="saat" value="12" min="1" max="72" style="width:60px;"> saat</label>
            <label class="ipucu" style="margin:0;"><input type="checkbox" name="kuru" value="1"> Deneme (siteye ekleme)</label>
            <button type="submit" class="dugme dugme-ana">Şimdi çalıştır</button>
        </form>
    <?php else: ?>
        <a href="ajan.php" class="dugme">Ajanı ayarla</a>
    <?php endif; ?>
</div>

<?php if ($calismalar !== []): ?>
    <div class="kutu" style="margin-top:20px;">
        <strong>Son çalışmalar</strong>
        <table class="liste-tablo" style="margin-top:10px;">
            <thead><tr><th>Başlangıç</th><th>Durum</th><th>Özet</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($calismalar as $c): ?>
                <?php
                $rozet = $c['durum'] !== 'completed' ? ['taslak', 'Çalışıyor']
                    : ($c['sonuc'] === 'success' ? ['yayinda', 'Tamam'] : ['reddedildi', 'Hata']);
                ?>
                <tr>
                    <td style="white-space:nowrap;"><?= $c['baslangic'] !== '' ? e(date('d.m H:i', (int) strtotime($c['baslangic']))) : '—' ?></td>
                    <td><span class="rozet rozet-<?= $rozet[0] ?>"><?= e($rozet[1]) ?></span></td>
                    <td><?= e($c['ozet']) ?></td>
                    <td><?php if ($c['adres'] !== ''): ?><a href="<?= e($c['adres']) ?>" target="_blank" rel="noopener">Rapor ↗</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php elseif ($anahtarVar): ?>
    <p class="ipucu" style="margin-top:20px;">Video ajanı henüz hiç çalışmadı.</p>
<?php endif; ?>

<div class="kutu" style="margin-top:20px;">
    <strong>Sitedeki videolu haberler</strong>
    <?php if ($videolar === []): ?>
        <p class="bos-durum">Henüz videolu haber yok.</p>
    <?php else: ?>
        <table class="liste-tablo" style="margin-top:10px;">
            <thead><tr><th>Eklendi</th><th>Haber</th><th>Kanal</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($videolar as $v): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= e(date('d.m H:i', (int) strtotime((string) $v['olusturuldu']))) ?></td>
                    <td style="word-break:break-word;">
                        <a href="duzenle.php?id=<?= (int) $v['id'] ?>"><?= e((string) $v['baslik']) ?></a>
                        <?php if ($v['durum'] === 'yayinda'): ?>
                            · <a href="<?= e(haber_yolu((string) $v['slug'])) ?>" target="_blank" rel="noopener">sitede ↗</a>
                        <?php endif; ?>
                        <?php if ((string) $v['kaynak_url'] !== ''): ?>
                            · <a href="<?= e((string) $v['kaynak_url']) ?>" target="_blank" rel="noopener">video ↗</a>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $v['kaynak_adi']) ?></td>
                    <td><span class="rozet rozet-<?= e((string) $v['durum']) ?>"><?= e($durumAdi[$v['durum']] ?? (string) $v['durum']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
