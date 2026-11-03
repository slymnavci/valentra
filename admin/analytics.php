<?php
declare(strict_types=1);

/**
 * Google Analytics verisi panelde (02.11.2026; bkz. includes/analytics.php).
 *
 * Kurulum tamam degilse adim adim kurulum kutusu (servis hesabi, Data API,
 * Analytics'te Goruntuleyici izni, mulk kimligi). Tamamsa: su an sitede
 * (dakikada bir kendiliginden yenilenir), son 7 / 28 gun ozeti onceki
 * donemle, 90 gunluk cubuklar, en cok okunan sayfalar, ziyaretcilerin
 * geldigi kanallar ve siteler, cihaz ve sehir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analytics.php';
require_once __DIR__ . '/../includes/olcum_reklam.php';

giris_zorunlu();

// "Su an sitede" kutusunun dakikalik yenilemesi.
if (isset($_GET['anlik'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $a = ga_mulk() !== '' && gsc_hesap() !== null ? ga_anlik() : ['kullanici' => 0, 'sayfalar' => [], 'cihazlar' => [], 'hata' => ''];
    echo json_encode([
        'kullanici' => $a['kullanici'],
        'sayfalar'  => $a['sayfalar'],
        'cihazlar'  => array_map(static fn (array $c): array => ['ad' => ga_turkce($c['ad']), 'kullanici' => $c['kullanici']], $a['cihazlar']),
        'hata'      => $a['hata'],
        'saat'      => date('H:i'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$bildirim = '';
$hata     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'mulk') {
        $mulk = ga_mulk_temizle((string) ($_POST['mulk'] ?? ''));

        if ($mulk === '') {
            $hata = 'Mülk kimliği 6–12 haneli bir sayı olmalı (Analytics → Yönetici → Mülk bilgileri → "Mülk kimliği"). G- ile başlayan ölçüm kimliği değil.';
        } else {
            ayar_yaz('ga4_mulk', $mulk);
            ayar_sil('ga_rapor_onbellek');
            ayar_sil('ga_anlik_onbellek');
            yonlendir('analytics.php?bildirim=kaydedildi');
        }
    } elseif ($islem === 'mulk_sil') {
        ayar_sil('ga4_mulk');
        ayar_sil('ga_rapor_onbellek');
        ayar_sil('ga_anlik_onbellek');
        yonlendir('analytics.php?bildirim=silindi');
    } elseif ($islem === 'tazele') {
        @set_time_limit(60);
        ga_raporlar(true);
        ga_anlik(true);
        yonlendir('analytics.php?bildirim=tazelendi');
    }
}

$bildirim = match ((string) ($_GET['bildirim'] ?? '')) {
    'kaydedildi' => 'Mülk kimliği kaydedildi.',
    'silindi'    => 'Mülk kimliği silindi.',
    'tazelendi'  => 'Veriler Google Analytics\'ten yeniden alındı.',
    default      => '',
};

$hesap   = gsc_hesap();
$mulk    = ga_mulk();
$olcum   = olcum_ga4();
$hazir   = $hesap !== null && $mulk !== '';
$rapor   = null;
$anlik   = null;

if ($hazir) {
    @set_time_limit(60);
    $rapor = ga_raporlar();
    $anlik = ga_anlik();
}

$sayi  = static fn (float $x): string => number_format($x, 0, ',', '.');
$sure  = static function (float $sn): string {
    $sn = (int) round($sn);

    return $sn >= 60 ? intdiv($sn, 60) . ' dk ' . ($sn % 60) . ' sn' : $sn . ' sn';
};
$degisim = static function (float $simdi, float $once): string {
    if ($once <= 0) {
        return '';
    }

    $oran = ($simdi - $once) / $once * 100;
    $renk = abs($oran) < 0.5 ? 'var(--metin-soluk)' : ($oran > 0 ? 'var(--yesil)' : 'var(--kirmizi)');

    return '<span style="color:' . $renk . ';font-size:.85rem;">'
         . ($oran > 0 ? '+' : '') . number_format($oran, 0, ',', '.') . '%</span>';
};

$panelBasligi = 'Google Analytics';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>

<?php if (!$hazir): ?>
    <div class="kutu" style="margin-top:20px;">
        <h2 style="margin-top:0;">Google Analytics bağlantısını kurun</h2>
        <p>
            Bu sayfa sitenizin Google Analytics verisini (şu an sitede kaç kişi var, kaç kişi okudu,
            hangi haberler okundu, ziyaretçiler nereden geldi) panelde gösterir. Bir kez, yaklaşık
            5 dakikalık kurulum:
        </p>
        <ol style="line-height:1.8;">
            <li>
                <strong>Servis hesabı.</strong>
                <?php if ($hesap !== null): ?>
                    ✅ Hazır. Servis hesabının e-postası:
                    <code style="user-select:all;"><?= e($hesap['client_email']) ?></code>
                <?php else: ?>
                    ❌ Henüz yok. Önce <a href="google.php">Google görünürlüğü</a> sayfasındaki
                    "Search Console bağlantısını kurun" adımlarını yapın (servis hesabı ve JSON anahtarı).
                    Analytics de aynı hesabı kullanır. Sonra bu sayfaya dönün.
                <?php endif; ?>
            </li>
            <li>
                <strong>API'yi açın.</strong> Servis hesabının bulunduğu Google Cloud projesi seçiliyken
                <a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener">Google Analytics Data API</a>
                sayfasını açıp <strong>Etkinleştir</strong>'e basın.
            </li>
            <li>
                <strong>Analytics'te izin verin.</strong>
                <a href="https://analytics.google.com/" target="_blank" rel="noopener">analytics.google.com</a> →
                sol alttaki dişli (<strong>Yönetici</strong>) → <strong>Mülk erişim yönetimi</strong> →
                sağ üstteki <strong>+</strong> → <strong>Kullanıcı ekle</strong>. E-posta olarak yukarıdaki
                servis hesabı e-postasını yazın, rol olarak <strong>Görüntüleyici</strong> seçin,
                "e-postayla bilgilendir" işaretini kaldırıp <strong>Ekle</strong>'ye basın.
            </li>
            <li>
                <strong>Mülk kimliğini girin.</strong> Analytics → Yönetici → <strong>Mülk bilgileri</strong>
                sayfasının sağ üstündeki <strong>MÜLK KİMLİĞİ</strong> (9–10 haneli sayı). G- ile başlayan
                ölçüm kimliği (<?= $olcum !== '' ? e($olcum) : 'G-…' ?>) değil.
                <form method="post" action="analytics.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:10px 0 0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="islem" value="mulk">
                    <label>Mülk kimliği<br>
                        <input type="text" name="mulk" value="<?= e($mulk) ?>" inputmode="numeric" placeholder="ör. 512345678" required style="width:220px;">
                    </label>
                    <button type="submit" class="dugme dugme-ana">Kaydet</button>
                </form>
            </li>
        </ol>
        <?php if ($olcum === ''): ?>
            <p class="uyari uyari-hata" style="margin:12px 0 0;">
                Sitede ölçüm kodu da yok: <a href="seo.php">Arama motoru ayarları</a> sayfasına G- ile başlayan
                ölçüm kimliğini girin, yoksa Analytics ziyaretçi toplamaz.
            </p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="kutu" style="margin-top:20px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
        <div>
            <strong>Bağlı:</strong> mülk <code><?= e($mulk) ?></code>
            <?php if ($olcum !== ''): ?> &middot; ölçüm kodu <code><?= e($olcum) ?></code><?php endif; ?>
            <div class="ipucu" style="margin-top:4px;">
                Son güncelleme: <?= $rapor['zaman'] > 0 ? e(date('d.m.Y H:i', $rapor['zaman'])) : '—' ?>
                &middot; raporlar saatte bir, "şu an sitede" dakikada bir yenilenir.
                Çerez bildirimini kabul etmeyen okurlar Google'da sınırlı sayılır.
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" action="analytics.php" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <button type="submit" name="islem" value="tazele" class="dugme dugme-ana">Şimdi güncelle</button>
            </form>
            <form method="post" action="analytics.php" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <button type="submit" name="islem" value="mulk_sil" class="dugme dugme-ret"
                        onclick="return confirm('Mülk kimliği silinsin mi? (Sitedeki ölçüm sürer; yalnızca panel bağlantısı kalkar.)')">Bağlantıyı kaldır</button>
            </form>
        </div>
    </div>

    <?php if ($rapor['hata'] !== ''): ?>
        <div class="uyari uyari-hata" style="margin-top:14px;">
            <strong>Google Analytics'ten veri alınamadı:</strong> <?= e($rapor['hata']) ?>
            <div style="margin-top:6px;">
                Servis hesabı: <code style="user-select:all;"><?= e($hesap['client_email']) ?></code>
                &middot; <a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener">Data API'yi aç</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="kutu" style="margin-top:20px;" id="anlik">
        <h2 style="margin-top:0;">Şu an sitede <span class="ipucu" style="font-weight:400;">(son 30 dakika, <span data-anlik="saat"><?= e(date('H:i', $anlik['zaman'])) ?></span>)</span></h2>
        <div style="display:grid;grid-template-columns:minmax(160px,220px) 1fr;gap:16px;align-items:start;">
            <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
                <div class="ipucu">Etkin kullanıcı</div>
                <div style="font-size:2.4rem;font-weight:800;" data-anlik="kullanici"><?= $sayi($anlik['kullanici']) ?></div>
                <div class="ipucu" data-anlik="cihazlar"><?= e(implode(' · ', array_map(static fn (array $c): string => ga_turkce($c['ad']) . ' ' . $c['kullanici'], $anlik['cihazlar']))) ?></div>
            </div>
            <div>
                <table class="liste-tablo">
                    <thead><tr><th>Okunan sayfa</th><th class="sag">Kişi</th></tr></thead>
                    <tbody data-anlik="sayfalar">
                    <?php foreach ($anlik['sayfalar'] as $s): ?>
                        <tr><td><?= e($s['ad']) ?></td><td class="sag"><?= $sayi($s['kullanici']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($anlik['sayfalar'] === []): ?>
                        <tr><td colspan="2" class="ipucu">Şu an kimse yok.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($rapor['ozet'] !== []): ?>
        <?php $oz = $rapor['ozet']; ?>
        <?php foreach ([['Son 7 gün', 'g7', 'o7'], ['Son 28 gün', 'g28', 'o28']] as [$baslik, $g, $o]): ?>
            <div class="kutu" style="margin-top:20px;">
                <h2 style="margin-top:0;"><?= e($baslik) ?> <span class="ipucu" style="font-weight:400;">(bugün dahil; önceki dönemle)</span></h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
                    <?php foreach ([
                        ['Kullanıcı', $sayi($oz[$g]['kullanici']), $degisim($oz[$g]['kullanici'], $oz[$o]['kullanici'])],
                        ['Yeni kullanıcı', $sayi($oz[$g]['yeni']), $degisim($oz[$g]['yeni'], $oz[$o]['yeni'])],
                        ['Sayfa görüntüleme', $sayi($oz[$g]['goruntuleme']), $degisim($oz[$g]['goruntuleme'], $oz[$o]['goruntuleme'])],
                        ['Oturum', $sayi($oz[$g]['oturum']), $degisim($oz[$g]['oturum'], $oz[$o]['oturum'])],
                        ['Ort. oturum süresi', $sure($oz[$g]['sure']), $degisim($oz[$g]['sure'], $oz[$o]['sure'])],
                    ] as [$ad, $deger, $fark]): ?>
                        <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
                            <div class="ipucu"><?= e($ad) ?></div>
                            <div style="font-size:1.5rem;font-weight:800;"><?= e($deger) ?> <?= $fark ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    /*
     * OKUR DAVRANISI (03.11.2026): kaydirma akisi isliyor mu, okur kac
     * haber okuyor, bulten ve Telegram ne kadar okur getiriyor.
     */
    if ($rapor['ozet'] !== []):
        $oz      = $rapor['ozet'];
        $olay    = $rapor['olaylar'] + ['g7' => [], 'o7' => []];
        $akis7   = (int) ($olay['g7']['sonraki_haber'] ?? 0);
        $akisO7  = (int) ($olay['o7']['sonraki_haber'] ?? 0);
        $pv7     = max(1, (int) ($olay['g7']['page_view'] ?? $oz['g7']['goruntuleme']));
        $okurBasi  = $oz['g7']['oturum'] > 0 ? $oz['g7']['goruntuleme'] / $oz['g7']['oturum'] : 0.0;
        $okurBasiO = $oz['o7']['oturum'] > 0 ? $oz['o7']['goruntuleme'] / $oz['o7']['oturum'] : 0.0;
        $organik = 0;

        foreach ($rapor['kanallar'] as $k) {
            $organik += $k['ad'] === 'Organic Search' ? $k['oturum'] : 0;
        }

        $kendi = $rapor['kendi'];
    ?>
        <div class="kutu" style="margin-top:20px;">
            <h2 style="margin-top:0;">Okur davranışı <span class="ipucu" style="font-weight:400;">(son 7 gün; önceki 7 günle)</span></h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;">
                <?php foreach ([
                    ['Oturum başına sayfa', number_format($okurBasi, 2, ',', '.'), $degisim($okurBasi, $okurBasiO), 'Bir okur bir ziyarette ortalama kaç sayfa açtı.'],
                    ['Kaydırmayla açılan haber', $sayi($akis7), $degisim($akis7, $akisO7), 'Haberin altından sıradaki habere geçiş.'],
                    ['Akışın payı', '%' . number_format($akis7 * 100 / $pv7, 1, ',', '.'), '', 'Bütün sayfa görüntülemelerinin kaçı kaydırmayla geldi.'],
                ] as [$ad, $deger, $fark, $ipucu]): ?>
                    <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
                        <div class="ipucu"><?= e($ad) ?></div>
                        <div style="font-size:1.5rem;font-weight:800;"><?= e($deger) ?> <?= $fark ?></div>
                        <div class="ipucu" style="font-size:.8rem;"><?= e($ipucu) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3 style="margin:18px 0 8px;font-size:1rem;">Okur getiren kanallarımız <span class="ipucu" style="font-weight:400;">(28 gün, oturum)</span></h3>
            <table class="liste-tablo">
                <thead><tr><th>Kanal</th><th class="sag">Oturum</th><th class="sag">Sayfa görüntüleme</th></tr></thead>
                <tbody>
                    <tr><td>Google araması (organik)</td><td class="sag"><?= $sayi($organik) ?></td><td class="sag">—</td></tr>
                    <tr><td>E-posta bülteni</td><td class="sag"><?= $sayi($kendi['bulten']['oturum'] ?? 0) ?></td><td class="sag"><?= $sayi($kendi['bulten']['goruntuleme'] ?? 0) ?></td></tr>
                    <tr><td>Telegram kanalı</td><td class="sag"><?= $sayi($kendi['telegram']['oturum'] ?? 0) ?></td><td class="sag"><?= $sayi($kendi['telegram']['goruntuleme'] ?? 0) ?></td></tr>
                </tbody>
            </table>
            <p class="ipucu" style="margin:8px 0 0;">Kaydırma ölçümü 3 Kasım 2026'dan itibaren; bülten ve Telegram, kuruldukları günden itibaren görünür.</p>
        </div>
    <?php endif; ?>

    <?php if ($rapor['gunluk'] !== []): ?>
        <?php $enYuksek = max(1, max(array_column($rapor['gunluk'], 'goruntuleme'))); ?>
        <div class="kutu" style="margin-top:20px;">
            <h2 style="margin-top:0;">Son 90 gün</h2>
            <div class="cubuk-grafik">
                <?php foreach ($rapor['gunluk'] as $i => $gun): ?>
                    <div class="cubuk-sutun" title="<?= e(date('d.m.Y', strtotime($gun['tarih']))) ?>: <?= $sayi($gun['goruntuleme']) ?> görüntüleme, <?= $sayi($gun['kullanici']) ?> kullanıcı">
                        <span class="cubuk-deger"></span>
                        <div class="cubuk" style="height:<?= max(2, (int) round($gun['goruntuleme'] / $enYuksek * 100)) ?>%"></div>
                        <span class="cubuk-ad"><?= ($i % 10 === 0 || $i === count($rapor['gunluk']) - 1) ? e(date('d.m', strtotime($gun['tarih']))) : '' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="ipucu" style="margin-bottom:0;">Sütun yüksekliği o günün sayfa görüntülemesi; üzerine gelince kullanıcı sayısı da görünür.</p>
        </div>
    <?php endif; ?>

    <div class="kutu" style="margin-top:20px;">
        <h2 style="margin-top:0;">En çok okunan sayfalar <span class="ipucu" style="font-weight:400;">(son 28 gün)</span></h2>
        <?php if ($rapor['sayfalar'] === []): ?>
            <p class="ipucu">Henüz veri yok. Ölçüm yeni kurulduysa raporlar 24–48 saat içinde dolar.</p>
        <?php else: ?>
            <table class="liste-tablo">
                <thead><tr><th>Sayfa</th><th class="sag">Görüntüleme</th><th class="sag">Kullanıcı</th></tr></thead>
                <tbody>
                <?php foreach ($rapor['sayfalar'] as $s): ?>
                    <tr>
                        <td>
                            <?= e($s['baslik'] !== '' && $s['baslik'] !== '(not set)' ? $s['baslik'] : $s['yol']) ?>
                            <br><a href="<?= e(str_starts_with($s['yol'], '/') ? $s['yol'] : '/') ?>" target="_blank" rel="noopener" class="ipucu"><?= e($s['yol']) ?></a>
                        </td>
                        <td class="sag"><?= $sayi($s['goruntuleme']) ?></td>
                        <td class="sag"><?= $sayi($s['kullanici']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="istatistik-ikili">
        <div class="kutu">
            <h2 style="margin-top:0;">Ziyaretçiler nereden geliyor <span class="ipucu" style="font-weight:400;">(28 gün)</span></h2>
            <table class="liste-tablo">
                <thead><tr><th>Kanal</th><th class="sag">Oturum</th><th class="sag">Kullanıcı</th></tr></thead>
                <tbody>
                <?php foreach ($rapor['kanallar'] as $k): ?>
                    <tr><td><?= e(ga_turkce($k['ad'])) ?></td><td class="sag"><?= $sayi($k['oturum']) ?></td><td class="sag"><?= $sayi($k['kullanici']) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($rapor['kanallar'] === []): ?><tr><td colspan="3" class="ipucu">Henüz veri yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="kutu">
            <h2 style="margin-top:0;">Gelinen siteler <span class="ipucu" style="font-weight:400;">(28 gün)</span></h2>
            <table class="liste-tablo">
                <thead><tr><th>Kaynak</th><th class="sag">Oturum</th></tr></thead>
                <tbody>
                <?php foreach ($rapor['kaynaklar'] as $k): ?>
                    <tr><td><?= e(ga_turkce($k['ad'])) ?></td><td class="sag"><?= $sayi($k['oturum']) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($rapor['kaynaklar'] === []): ?><tr><td colspan="2" class="ipucu">Henüz veri yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="istatistik-ikili">
        <div class="kutu">
            <h2 style="margin-top:0;">Cihaz <span class="ipucu" style="font-weight:400;">(28 gün)</span></h2>
            <?php $cihazToplam = max(1, array_sum(array_column($rapor['cihazlar'], 'kullanici'))); ?>
            <table class="liste-tablo">
                <thead><tr><th>Cihaz</th><th class="sag">Kullanıcı</th><th class="sag">Pay</th></tr></thead>
                <tbody>
                <?php foreach ($rapor['cihazlar'] as $c): ?>
                    <tr><td><?= e(ga_turkce($c['ad'])) ?></td><td class="sag"><?= $sayi($c['kullanici']) ?></td><td class="sag">%<?= number_format($c['kullanici'] / $cihazToplam * 100, 0, ',', '.') ?></td></tr>
                <?php endforeach; ?>
                <?php if ($rapor['cihazlar'] === []): ?><tr><td colspan="3" class="ipucu">Henüz veri yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="kutu">
            <h2 style="margin-top:0;">Şehir <span class="ipucu" style="font-weight:400;">(28 gün)</span></h2>
            <table class="liste-tablo">
                <thead><tr><th>Şehir</th><th class="sag">Kullanıcı</th></tr></thead>
                <tbody>
                <?php foreach ($rapor['sehirler'] as $c): ?>
                    <tr><td><?= e(ga_turkce($c['ad'])) ?></td><td class="sag"><?= $sayi($c['kullanici']) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($rapor['sehirler'] === []): ?><tr><td colspan="2" class="ipucu">Henüz veri yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="ipucu" style="margin-top:16px;">
        Bu rakamlar Google Analytics'in sayımıdır; <a href="istatistik.php">Ziyaretçiler</a> sayfası sitenin kendi
        sayacıdır. Google çerezleri reddeden okurları sınırlı saydığı ve botları farklı elediği için iki sayı
        birebir tutmaz.
    </p>

    <script>
    (function () {
        var kutu = document.getElementById('anlik');
        if (!kutu || !window.fetch) { return; }
        function yaz(ad, metin) { var o = kutu.querySelector('[data-anlik="' + ad + '"]'); if (o) { o.textContent = metin; } }
        function yenile() {
            if (document.hidden) { return; }
            fetch('analytics.php?anlik=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (v) {
                yaz('kullanici', String(v.kullanici).replace(/\B(?=(\d{3})+(?!\d))/g, '.'));
                yaz('saat', v.saat);
                yaz('cihazlar', (v.cihazlar || []).map(function (c) { return c.ad + ' ' + c.kullanici; }).join(' · '));
                var g = kutu.querySelector('[data-anlik="sayfalar"]');
                if (!g) { return; }
                g.textContent = '';
                (v.sayfalar || []).forEach(function (s) {
                    var tr = document.createElement('tr'), a = document.createElement('td'), b = document.createElement('td');
                    a.textContent = s.ad; b.textContent = s.kullanici; b.className = 'sag';
                    tr.appendChild(a); tr.appendChild(b); g.appendChild(tr);
                });
                if (!(v.sayfalar || []).length) {
                    var tr = document.createElement('tr'), td = document.createElement('td');
                    td.colSpan = 2; td.className = 'ipucu'; td.textContent = 'Şu an kimse yok.';
                    tr.appendChild(td); g.appendChild(tr);
                }
            }).catch(function () {});
        }
        setInterval(yenile, 60000);
    })();
    </script>

<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
