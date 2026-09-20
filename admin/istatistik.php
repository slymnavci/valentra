<?php
declare(strict_types=1);

/**
 * Ziyaretçi istatistikleri.
 *
 * Sorularin sirasi bilincli: once "su an kac kisi var", sonra "bugun
 * kac kisi geldi", en sonda "ne okudular". Panel acildiginda ilk
 * bakista gorulmesi gereken sey anlik durum.
 *
 * Grafik icin bir kutuphane YOK. On beş gunluk bir cubuk grafigi CSS
 * ile cizilebiliyor; disaridan betik cekmek hem paylasimli hosting'de
 * gereksiz bir bagimlilik hem de panelin cevrimdisi calismasini
 * bozardi.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ziyaret.php';

giris_zorunlu();

$aralik = (int) ($_GET['gun'] ?? 7);

if (!in_array($aralik, [1, 7, 30], true)) {
    $aralik = 7;
}

$ozet       = ziyaret_ozeti();
$gunluk     = ziyaret_gunluk(14);
$haberler   = ziyaret_populer_haberler($aralik, 15);
$sayfalar   = ziyaret_populer_sayfalar($aralik, 10);
$kaynaklar  = ziyaret_yonlendirenler($aralik, 10);
$online     = ziyaret_online_sayfalar(10);

/*
 * Tek bir ziyaretcinin gezintisi.
 *
 * "Hangi ziyaretci nereye girmis" sorusunun cevabi burada. Kimlik
 * yerine takma ad kullaniliyor; ham IP zaten hicbir yerde saklanmiyor
 * (bkz. includes/ziyaret.php basi). Analiz icin gereken "ayni kisi mi"
 * bilgisini takma ad da veriyor.
 */
$kisi     = trim((string) ($_GET['kisi'] ?? ''));
$gezinti  = $kisi !== '' ? ziyaret_gezinti($kisi) : [];
$kisiler  = ziyaret_ziyaretciler($aralik, 100);

/** Grafikte en yuksek sutun; hepsi buna gore olceklenir. */
$enYuksek = max(1, max(array_column($gunluk, 'goruntulenme')));

$gunAdi = static fn (string $tarih): string => [
    'Mon' => 'Pzt', 'Tue' => 'Sal', 'Wed' => 'Çar', 'Thu' => 'Per',
    'Fri' => 'Cum', 'Sat' => 'Cmt', 'Sun' => 'Paz',
][date('D', strtotime($tarih))] ?? '';

$panelBasligi = 'Ziyaretçiler';
require __DIR__ . '/ust.php';
?>

<div class="sayfa-basligi" style="margin-top:20px;">
    <h1>Ziyaretçiler</h1>
    <p class="ipucu">
        Kendi sayacımız — veri dışarıya gitmiyor. Yönetici oturumu
        açıkken yapılan ziyaretler ve bilinen arama motoru botları
        sayılmaz. Kayıtlar <?= (int) ZIYARET_SAKLAMA_GUN ?> gün sonra
        kendiliğinden silinir; ham IP adresi hiçbir yerde saklanmaz.
    </p>
</div>

<!-- Ozet kartlari -->
<div class="olcum-izgara">
    <div class="olcum olcum-vurgu">
        <span class="olcum-sayi"><?= number_format($ozet['online']) ?></span>
        <span class="olcum-ad">şu an sitede</span>
        <span class="olcum-alt">son <?= (int) ZIYARET_ONLINE_DAKIKA ?> dakika</span>
    </div>

    <div class="olcum">
        <span class="olcum-sayi"><?= number_format($ozet['bugun_ziyaretci']) ?></span>
        <span class="olcum-ad">bugün ziyaretçi</span>
        <span class="olcum-alt"><?= number_format($ozet['bugun_goruntulenme']) ?> görüntülenme</span>
    </div>

    <div class="olcum">
        <span class="olcum-sayi"><?= number_format($ozet['dun_ziyaretci']) ?></span>
        <span class="olcum-ad">dün ziyaretçi</span>
        <span class="olcum-alt">karşılaştırma için</span>
    </div>

    <div class="olcum">
        <span class="olcum-sayi"><?= number_format($ozet['hafta_ziyaretci']) ?></span>
        <span class="olcum-ad">son 7 gün</span>
        <span class="olcum-alt"><?= number_format($ozet['hafta_goruntulenme']) ?> görüntülenme</span>
    </div>

    <div class="olcum">
        <span class="olcum-sayi"><?= number_format($ozet['ay_ziyaretci']) ?></span>
        <span class="olcum-ad">son 30 gün</span>
        <span class="olcum-alt"><?= number_format($ozet['ay_goruntulenme']) ?> görüntülenme</span>
    </div>
</div>

<?php if ($ozet['toplam_goruntulenme'] === 0): ?>
    <div class="uyari uyari-bilgi" style="margin-top:20px;">
        <strong>Henüz kayıt yok.</strong>
        Sayaç bu güncellemeyle başladı; siteye ilk ziyaretçi geldiğinde
        buradaki sayılar dolmaya başlar. Kendi ziyaretleriniz sayılmaz —
        denemek için panelden çıkın ya da siteyi gizli sekmede açın.
    </div>
<?php endif; ?>

<!-- Son 14 gun -->
<div class="kutu" style="margin-top:20px;">
    <h2 style="margin-top:0;">Son 14 gün</h2>

    <div class="cubuk-grafik">
        <?php foreach ($gunluk as $gun): ?>
            <?php
            // Yuksekligi yuzde olarak veriyoruz; sifir olan gun de
            // gorunsun diye en az 2 piksellik bir taban birakiliyor.
            $oran = (int) round($gun['goruntulenme'] / $enYuksek * 100);
            ?>
            <div class="cubuk-sutun"
                 title="<?= e(date('d.m.Y', strtotime($gun['tarih']))) ?>: <?= (int) $gun['ziyaretci'] ?> ziyaretçi, <?= (int) $gun['goruntulenme'] ?> görüntülenme">
                <span class="cubuk-deger"><?= $gun['goruntulenme'] > 0 ? (int) $gun['goruntulenme'] : '' ?></span>
                <div class="cubuk" style="height:<?= max(2, $oran) ?>%"></div>
                <span class="cubuk-ad">
                    <?= e($gunAdi($gun['tarih'])) ?><br><?= e(date('d.m', strtotime($gun['tarih']))) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="ipucu" style="margin-bottom:0;">
        Sütun yüksekliği görüntülenme sayısını gösterir; üzerine
        gelince o günün ziyaretçi sayısı da görünür.
    </p>
</div>

<!-- Aralik secimi -->
<nav class="sekmeler" style="margin-top:24px;">
    <a href="?gun=1"  class="<?= $aralik === 1  ? 'aktif' : '' ?>">Bugün</a>
    <a href="?gun=7"  class="<?= $aralik === 7  ? 'aktif' : '' ?>">Son 7 gün</a>
    <a href="?gun=30" class="<?= $aralik === 30 ? 'aktif' : '' ?>">Son 30 gün</a>
</nav>

<div class="istatistik-ikili">
    <!-- En cok okunan haberler -->
    <div class="kutu">
        <h2 style="margin-top:0;">En çok okunan haberler</h2>

        <?php if ($haberler === []): ?>
            <p class="ipucu">Bu aralıkta okunan haber yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead>
                    <tr><th>Haber</th><th class="sag">Okunma</th><th class="sag">Kişi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($haberler as $satir): ?>
                    <tr>
                        <td>
                            <?php if (!empty($satir['slug'])): ?>
                                <a href="/haber.php?s=<?= e((string) $satir['slug']) ?>"
                                   target="_blank" rel="noopener"><?= e((string) $satir['baslik']) ?></a>
                            <?php else: ?>
                                <?= e((string) $satir['baslik']) ?>
                                <span class="ipucu">(silinmiş)</span>
                            <?php endif; ?>
                        </td>
                        <td class="sag"><strong><?= number_format((int) $satir['okunma']) ?></strong></td>
                        <td class="sag"><?= number_format((int) $satir['ziyaretci']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Nereden geldiler -->
    <div class="kutu">
        <h2 style="margin-top:0;">Nereden geldiler</h2>

        <?php if ($kaynaklar === []): ?>
            <p class="ipucu">Bu aralıkta kayıt yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead>
                    <tr><th>Kaynak</th><th class="sag">Görüntülenme</th><th class="sag">Kişi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($kaynaklar as $satir): ?>
                    <tr>
                        <td><?= e((string) $satir['kaynak']) ?></td>
                        <td class="sag"><strong><?= number_format((int) $satir['goruntulenme']) ?></strong></td>
                        <td class="sag"><?= number_format((int) $satir['ziyaretci']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="ipucu" style="margin-bottom:0;">
                "(doğrudan)" adresi elle yazan, yer imine tıklayan ya da
                yönlendiren bilgisini paylaşmayan ziyaretçilerdir.
                Arama motorundan gelenler <code>google.com</code> gibi
                görünür.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="istatistik-ikili">
    <!-- En cok goruntulenen sayfalar -->
    <div class="kutu">
        <h2 style="margin-top:0;">En çok görüntülenen sayfalar</h2>

        <?php if ($sayfalar === []): ?>
            <p class="ipucu">Bu aralıkta kayıt yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead>
                    <tr><th>Adres</th><th class="sag">Görüntülenme</th><th class="sag">Kişi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($sayfalar as $satir): ?>
                    <tr>
                        <td class="kirp"><?= e((string) $satir['yol']) ?></td>
                        <td class="sag"><strong><?= number_format((int) $satir['goruntulenme']) ?></strong></td>
                        <td class="sag"><?= number_format((int) $satir['ziyaretci']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Su an sitede -->
    <div class="kutu">
        <h2 style="margin-top:0;">Şu an bakılan sayfalar</h2>

        <?php if ($online === []): ?>
            <p class="ipucu">Şu anda sitede kimse yok.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead>
                    <tr><th>Sayfa</th><th class="sag">Saat</th></tr>
                </thead>
                <tbody>
                <?php foreach ($online as $satir): ?>
                    <tr>
                        <td class="kirp">
                            <?= e(((string) $satir['baslik']) !== '' ? (string) $satir['baslik'] : (string) $satir['yol']) ?>
                        </td>
                        <td class="sag"><?= e(date('H:i', strtotime((string) $satir['son']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="ipucu" style="margin-bottom:0;">
                Sayfayı yenileyerek güncelleyebilirsiniz.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($kisi !== ''): ?>
    <!-- Tek ziyaretcinin gezintisi -->
    <div class="kutu" style="margin-top:22px;">
        <h2 style="margin-top:0;">
            Ziyaretçi <code><?= e($kisi) ?></code> nereye girdi
        </h2>

        <?php if ($gezinti === []): ?>
            <p class="ipucu">
                Bu takma kimlikle kayıt bulunamadı. Kayıtlar
                <?= (int) ZIYARET_SAKLAMA_GUN ?> gün sonra silinir.
            </p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead>
                    <tr>
                        <th>Zaman</th>
                        <th>IP</th>
                        <th>Sayfa</th>
                        <th>Adres</th>
                        <th>Nereden</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gezinti as $adim): ?>
                    <tr>
                        <td><?= e(date('d.m.Y H:i:s', strtotime((string) $adim['zaman']))) ?></td>
                        <td>
                            <code><?= e(((string) ($adim['ip'] ?? '')) !== ''
                                ? (string) $adim['ip']
                                : '—') ?></code>
                        </td>
                        <td>
                            <?= e(((string) $adim['baslik']) !== ''
                                ? (string) $adim['baslik']
                                : '—') ?>
                        </td>
                        <td class="kirp"><?= e((string) $adim['yol']) ?></td>
                        <td>
                            <?= e(((string) $adim['yonlendiren']) !== ''
                                ? (string) $adim['yonlendiren']
                                : '(doğrudan)') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="ipucu" style="margin-bottom:0;">
                <?= count($gezinti) ?> sayfa görüntülemesi.
                <a href="istatistik.php?gun=<?= (int) $aralik ?>">Listeye dön</a>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Ziyaretciler -->
<div class="kutu" style="margin-top:22px;">
    <h2 style="margin-top:0;">Ziyaretçiler</h2>

    <p class="ipucu">
        Her satır bir ziyaretçi. Takma kimliğe tıklayınca o ziyaretçinin
        gezdiği bütün sayfalar sırasıyla açılır.
    </p>

    <p class="ipucu">
        <strong>IP adresi kişisel veridir.</strong> Bu sütun sitenin
        yöneticisinin talebi üzerine eklendi. Adresler hiçbir üçüncü tarafa
        gönderilmiyor ve diğer kayıtlarla birlikte
        <?= (int) ZIYARET_SAKLAMA_GUN ?> gün sonra siliniyor. KVKK
        kapsamında bir işleme olduğu için sitenin aydınlatma metninde yer
        alması gerekir. Sütun boş görünüyorsa kayıt bu özellik eklenmeden
        önce yazılmıştır.
    </p>

    <?php if ($kisiler === []): ?>
        <p class="ipucu">Bu aralıkta kayıt yok.</p>
    <?php else: ?>
        <table class="liste-tablo">
            <thead>
                <tr>
                    <th>Takma kimlik</th>
                    <th>IP</th>
                    <th class="sag">Sayfa</th>
                    <th>İlk</th>
                    <th>Son</th>
                    <th>Son baktığı</th>
                    <th>Nereden</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($kisiler as $satir): ?>
                <tr>
                    <td>
                        <a href="?gun=<?= (int) $aralik ?>&amp;kisi=<?= e((string) $satir['ziyaretci']) ?>">
                            <code><?= e((string) $satir['ziyaretci']) ?></code>
                        </a>
                    </td>
                    <td>
                        <code><?= e(((string) ($satir['ip'] ?? '')) !== ''
                            ? (string) $satir['ip']
                            : '—') ?></code>
                    </td>
                    <td class="sag"><strong><?= number_format((int) $satir['sayfa']) ?></strong></td>
                    <td><?= e(date('d.m H:i', strtotime((string) $satir['ilk']))) ?></td>
                    <td><?= e(date('d.m H:i', strtotime((string) $satir['son']))) ?></td>
                    <td class="kirp">
                        <?= e(((string) $satir['son_baslik']) !== ''
                            ? (string) $satir['son_baslik']
                            : (string) $satir['son_yol']) ?>
                    </td>
                    <td>
                        <?= e(((string) ($satir['yonlendiren'] ?? '')) !== ''
                            ? (string) $satir['yonlendiren']
                            : '(doğrudan)') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
