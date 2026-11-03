<?php
declare(strict_types=1);

/**
 * Tarayici bildirimleri (Web Push; 03.11.2026; bkz. includes/web_push.php).
 *
 * Abone sayisi, otomatik bildirim ayari (ac/kapat, gunluk tavan), elle
 * gonderim (son haberlerden biri ya da serbest metin) ve gecmis: kac
 * kisiye ulasti, kac kisi tikladi.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/haberler.php';
require_once __DIR__ . '/../includes/web_push.php';

giris_zorunlu();

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'ayar') {
        ayar_yaz('push_otomatik', !empty($_POST['otomatik']) ? '1' : '0');
        ayar_yaz('push_gunluk_tavan', (string) max(1, min(10, (int) ($_POST['tavan'] ?? PUSH_GUNLUK_TAVAN))));
        yonlendir('bildirimler.php?bildirim=ayar');
    } elseif ($islem === 'haber') {
        $h = db()->prepare('SELECT id, baslik, slug, ozet, gorsel_url FROM haberler WHERE id = :id AND durum = :d');
        $h->execute(['id' => (int) ($_POST['haber'] ?? 0), 'd' => HABER_YAYINDA]);
        $h = $h->fetch();

        if (!$h) {
            $hata = 'Haber bulunamadı ya da yayında değil.';
        } elseif (push_abone_sayisi() === 0) {
            $hata = 'Henüz abone yok; bildirim gönderilecek kimse yok.';
        } else {
            push_haber_gonder($h, 'elle');
            yonlendir('bildirimler.php?bildirim=gonderildi');
        }
    } elseif ($islem === 'serbest') {
        $baslik = trim((string) ($_POST['baslik'] ?? ''));
        $metin  = trim((string) ($_POST['metin'] ?? ''));
        $adres  = trim((string) ($_POST['adres'] ?? '/'));

        // Yalnizca site ici adres (bildirim baska siteye goturmesin).
        if (preg_match('#^https?://#i', $adres)) {
            $p = parse_url($adres);
            $adres = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        }

        if ($baslik === '') {
            $hata = 'Başlık boş olamaz.';
        } elseif (!str_starts_with($adres, '/') || str_starts_with($adres, '//')) {
            $hata = 'Adres sitenin içinden olmalı (ör. /haber/... ya da https://valentra.com.tr/...).';
        } elseif (push_abone_sayisi() === 0) {
            $hata = 'Henüz abone yok; bildirim gönderilecek kimse yok.';
        } else {
            push_gonderim_ac($baslik, $metin, $adres, '', null, 'elle');
            yonlendir('bildirimler.php?bildirim=gonderildi');
        }
    } elseif ($islem === 'devam') {
        @set_time_limit(90);
        push_kuyrugu_isle(60);
        yonlendir('bildirimler.php?bildirim=devam');
    }
}

$bildirim = match ((string) ($_GET['bildirim'] ?? '')) {
    'ayar'       => 'Ayarlar kaydedildi.',
    'gonderildi' => 'Bildirim gönderime alındı; aboneler birkaç dakika içinde alır. Sonuç aşağıdaki tabloda.',
    'devam'      => 'Bekleyen gönderim işlendi.',
    default      => '',
};

// Panel acilisinda bekleyen varsa sayfa gittikten sonra bir parti daha.
push_bekleyen_varsa_devam();

$aboneler = ['toplam' => 0, 'turler' => []];
$gecmis = [];
$haberler = [];
$hafta = ['adet' => 0, 'ulasan' => 0, 'tiklama' => 0];

try {
    $aboneler['toplam'] = push_abone_sayisi();
    $aboneler['turler'] = db()->query("SELECT IF(tarayici = '', 'diğer', tarayici) AS t, COUNT(*) AS n FROM push_aboneleri GROUP BY t ORDER BY n DESC")->fetchAll();
    $aboneler['yeni7'] = (int) db()->query('SELECT COUNT(*) FROM push_aboneleri WHERE olusturuldu > NOW() - INTERVAL 7 DAY')->fetchColumn();
    $gecmis = db()->query('SELECT * FROM push_gonderimleri ORDER BY id DESC LIMIT 40')->fetchAll();
    $hafta = db()->query('SELECT COUNT(*) AS adet, COALESCE(SUM(basarili), 0) AS ulasan, COALESCE(SUM(tiklama), 0) AS tiklama FROM push_gonderimleri WHERE olusturuldu > NOW() - INTERVAL 7 DAY')->fetch() ?: $hafta;
    $ifade = db()->prepare(
        'SELECT h.id, h.baslik, h.onem, h.olusturuldu FROM haberler h
          WHERE h.durum = :d ORDER BY h.olusturuldu DESC, h.id DESC LIMIT 40'
    );
    $ifade->execute(['d' => HABER_YAYINDA]);
    $haberler = $ifade->fetchAll();
} catch (PDOException $e) {
    $hata = $hata ?: 'Veritabanı: ' . $e->getMessage();
}

$sayi = static fn (float $x): string => number_format($x, 0, ',', '.');
$turAdi = ['android' => 'Android', 'ios' => 'iPhone / iPad', 'masaustu' => 'Bilgisayar', 'yenileme' => 'Yenilenen', 'diğer' => 'Diğer'];
$otomatik = push_otomatik_acik();
$tavan = max(1, (int) ayar_oku('push_gunluk_tavan', (string) PUSH_GUNLUK_TAVAN));

$panelBasligi = 'Tarayıcı bildirimleri';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:20px;">
    <h2 style="margin-top:0;">Tarayıcı bildirimleri</h2>
    <p class="ipucu" style="margin-top:0;">
        Okurlar sitede “Bildirimleri aç” deyince telefonlarına ve bilgisayarlarına son dakika bildirimi
        gelir; tıklayınca habere döner. Uygulama indirmeye ya da üye olmaya gerek yok. iPhone'da
        bildirim için siteyi ana ekrana eklemek gerekiyor; site bunu okura kendisi anlatıyor.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
        <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
            <div class="ipucu">Abone</div>
            <div style="font-size:1.8rem;font-weight:800;"><?= $sayi($aboneler['toplam']) ?></div>
            <div class="ipucu">son 7 günde +<?= $sayi($aboneler['yeni7'] ?? 0) ?></div>
        </div>
        <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
            <div class="ipucu">Son 7 gün gönderilen</div>
            <div style="font-size:1.8rem;font-weight:800;"><?= $sayi((float) $hafta['adet']) ?></div>
            <div class="ipucu"><?= $sayi((float) $hafta['ulasan']) ?> kez ulaştı</div>
        </div>
        <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
            <div class="ipucu">Tıklayan</div>
            <div style="font-size:1.8rem;font-weight:800;"><?= $sayi((float) $hafta['tiklama']) ?></div>
            <div class="ipucu">oran %<?= (float) $hafta['ulasan'] > 0 ? number_format($hafta['tiklama'] / $hafta['ulasan'] * 100, 1, ',', '.') : '0' ?></div>
        </div>
        <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
            <div class="ipucu">Cihaz</div>
            <?php foreach ($aboneler['turler'] as $t): ?>
                <div><?= e($turAdi[$t['t']] ?? $t['t']) ?>: <strong><?= $sayi((float) $t['n']) ?></strong></div>
            <?php endforeach; ?>
            <?php if ($aboneler['turler'] === []): ?><div class="ipucu">Henüz abone yok.</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="istatistik-ikili">
    <div class="kutu">
        <h2 style="margin-top:0;">Otomatik bildirim</h2>
        <form method="post" action="bildirimler.php" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="ayar">
            <label style="display:flex;gap:8px;align-items:center;font-weight:700;">
                <input type="checkbox" name="otomatik" value="1"<?= $otomatik ? ' checked' : '' ?>>
                Son dakika ve önemli haberlerde kendiliğinden gönder
            </label>
            <label style="display:block;margin-top:12px;">Günde en fazla
                <input type="number" name="tavan" min="1" max="10" value="<?= $tavan ?>" style="width:70px;"> bildirim
            </label>
            <ul class="ipucu" style="margin:12px 0;padding-left:18px;line-height:1.6;">
                <li>Başlığında ya da kaynağında “son dakika” geçen veya önemi <?= PUSH_ONEM_ESIGI ?> ve üstü olan haber, yayına girdiği anda gönderilir (son 3 saatin haberi).</li>
                <li>İki otomatik bildirim arasında en az <?= PUSH_EN_KISA_ARA ?> dakika olur.</li>
                <li>Gece 00:00–07:00 arası otomatik bildirim gitmez.</li>
                <li>Günde 5'i geçen bildirim okurların izni geri almasına yol açar.</li>
            </ul>
            <button type="submit" class="dugme dugme-ana">Kaydet</button>
        </form>
    </div>

    <div class="kutu">
        <h2 style="margin-top:0;">Elle gönder</h2>
        <form method="post" action="bildirimler.php" style="margin:0 0 16px;" onsubmit="return confirm('Bu haber bütün abonelere bildirim olarak gönderilsin mi?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="haber">
            <label>Haber<br>
                <select name="haber" style="width:100%;" required>
                    <?php foreach ($haberler as $h): ?>
                        <option value="<?= (int) $h['id'] ?>"><?= e(date('H:i', strtotime((string) $h['olusturuldu']))) ?> · <?= e(mb_strimwidth((string) $h['baslik'], 0, 90, '…')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="dugme dugme-ana" style="margin-top:10px;">Bu haberi gönder</button>
        </form>
        <details>
            <summary style="cursor:pointer;font-weight:700;">Serbest bildirim yaz</summary>
            <form method="post" action="bildirimler.php" style="margin:10px 0 0;" onsubmit="return confirm('Bildirim bütün abonelere gönderilsin mi?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="islem" value="serbest">
                <label>Başlık<br><input type="text" name="baslik" maxlength="120" required style="width:100%;"></label>
                <label style="display:block;margin-top:8px;">Metin<br><textarea name="metin" rows="2" maxlength="180" style="width:100%;"></textarea></label>
                <label style="display:block;margin-top:8px;">Açılacak adres<br><input type="text" name="adres" value="/" placeholder="/haber/... ya da https://valentra.com.tr/..." style="width:100%;"></label>
                <button type="submit" class="dugme dugme-ana" style="margin-top:10px;">Gönder</button>
            </form>
        </details>
    </div>
</div>

<div class="kutu" style="margin-top:20px;overflow-x:auto;">
    <h2 style="margin-top:0;">Gönderilen bildirimler</h2>
    <?php if ($gecmis === []): ?>
        <p class="ipucu">Henüz bildirim gönderilmedi.</p>
    <?php else: ?>
        <?php if (array_filter($gecmis, static fn (array $g): bool => $g['durum'] !== 'bitti') !== []): ?>
            <form method="post" action="bildirimler.php" style="margin:0 0 12px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <button type="submit" name="islem" value="devam" class="dugme">Bekleyen gönderimi şimdi sürdür</button>
            </form>
        <?php endif; ?>
        <table class="liste-tablo">
            <thead><tr><th>Zaman</th><th>Tür</th><th>Bildirim</th><th class="sag">Abone</th><th class="sag">Ulaştı</th><th class="sag">Tıklama</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($gecmis as $g): ?>
                <tr>
                    <td><?= e(tarih_bicimle((string) $g['olusturuldu'])) ?></td>
                    <td><?= $g['tur'] === 'otomatik' ? 'Otomatik' : 'Elle' ?></td>
                    <td>
                        <strong><?= e((string) $g['baslik']) ?></strong>
                        <br><a href="<?= e((string) $g['adres']) ?>" target="_blank" rel="noopener" class="ipucu"><?= e(rtrim((string) preg_replace('/[?&]utm_[a-z]+=[^&]*/', '', (string) $g['adres']), '?&') ?: '/') ?></a>
                    </td>
                    <td class="sag"><?= $sayi((float) $g['hedef']) ?></td>
                    <td class="sag"><?= $sayi((float) $g['basarili']) ?><?= (int) $g['basarisiz'] > 0 ? ' <span class="ipucu">(' . $sayi((float) $g['basarisiz']) . ' ulaşmadı)</span>' : '' ?></td>
                    <td class="sag"><?= $sayi((float) $g['tiklama']) ?><?= (int) $g['basarili'] > 0 ? ' <span class="ipucu">%' . number_format($g['tiklama'] / $g['basarili'] * 100, 1, ',', '.') . '</span>' : '' ?></td>
                    <td><?= ['bekliyor' => 'Sırada', 'gonderiliyor' => 'Gönderiliyor', 'bitti' => 'Tamam'][$g['durum']] ?? e((string) $g['durum']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
