<?php
declare(strict_types=1);

/**
 * Google görünürlüğü: Search Console verisi ve sayfa kalitesi denetimi.
 *
 * Kurulum yapilmadiysa adim adim kurulum kutusu. Yapildiysa: son 28
 * gunun tiklama/gosterim/CTR/sira ozeti (onceki 28 gunle), gunluk
 * gosterim cubuklari, firsat sorgulari, sorgu ve sayfa tablolari, site
 * haritasi durumu, URL denetimi (dizinde mi, degilse Google'in sebebi)
 * ve Google'a sormadan bulunabilen baslik/ozet sorunlari.
 *
 * Veri bayatsa (20 saat) sayfa gonderildikten SONRA tazeleniyor; ayrica
 * ajan her gun durtuyor ve "Şimdi güncelle" dugmesi var.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/search_console.php';
require_once __DIR__ . '/../includes/rehberler.php';

giris_zorunlu();

$bildirim = '';
$hata     = '';
$tazeleme = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'anahtar') {
        $sonuc = gsc_hesap_kaydet((string) ($_POST['json'] ?? ''));

        if ($sonuc['tamam']) {
            @set_time_limit(90);
            $tazeleme = gsc_tazele(45);
            $bildirim = $sonuc['mesaj'];
        } else {
            $hata = $sonuc['mesaj'];
        }
    } elseif ($islem === 'tazele') {
        @set_time_limit(90);
        $tazeleme = gsc_tazele(60);
    } elseif ($islem === 'sil') {
        gsc_hesap_sil();
        $bildirim = 'Servis hesabı anahtarı silindi. Search Console\'dan da kullanıcıyı kaldırabilirsiniz.';
    }
}

$hesap    = gsc_hesap();
$sonHata  = ayar_oku('gsc_son_hata');
$sonTaze  = ayar_oku('gsc_son_tazeleme');
$site     = ayar_oku('gsc_site');
$donem    = gsc_donem_ozeti();
$seri     = array_slice(gsc_gunluk_seri(90), -28);
$sorgular = gsc_tablo('gsc_sorgular');
$sayfalar = gsc_tablo('gsc_sayfalar');
$firsat   = gsc_firsatlar($sorgular['satirlar']);
$denetim  = gsc_denetimler();
$haritalar = json_decode(ayar_oku('gsc_site_haritalari'), true) ?: [];
$kalite   = gsc_kalite_denetimi();

// Otomatik tazeleme: sayfa gittikten sonra, bekletmeden.
if ($tazeleme === null && gsc_tazelenmeli()) {
    $bitir = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
           : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);

    if ($bitir !== null) {
        register_shutdown_function(static function () use ($bitir): void {
            try {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                ignore_user_abort(true);
                $bitir();
                @set_time_limit(90);
                gsc_tazele(60);
            } catch (Throwable $e) {
                error_log('[valentra] search console tazeleme (panel): ' . $e->getMessage());
            }
        });
    }
}

/** Degisim yuzdesi: +12% / -5%. Sira icin dusmek iyidir. */
$degisim = static function (float $simdi, float $once, bool $tersIyi = false): string {
    if ($once <= 0) {
        return '';
    }

    $oran  = ($simdi - $once) / $once * 100;
    $iyi   = $tersIyi ? $oran < 0 : $oran > 0;
    $renk  = abs($oran) < 0.5 ? 'var(--metin-soluk)' : ($iyi ? 'var(--yesil)' : 'var(--kirmizi)');

    return '<span style="color:' . $renk . ';font-size:.85rem;">'
         . ($oran > 0 ? '+' : '') . number_format($oran, 0, ',', '.') . '%</span>';
};

$yuzde = static fn (float $x): string => '%' . number_format($x * 100, 1, ',', '.');
$sayi  = static fn (float $x): string => number_format($x, 0, ',', '.');
$sira  = static fn (float $x): string => number_format($x, 1, ',', '.');

$panelBasligi = 'Google görünürlüğü';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>

<?php if ($tazeleme !== null): ?>
    <div class="uyari uyari-<?= $tazeleme['tamam'] ? 'basari' : 'hata' ?>" style="margin-top:20px;">
        <?php if ($tazeleme['tamam']): ?>
            Search Console verisi güncellendi: <?= (int) $tazeleme['gun'] ?> gün, <?= (int) $tazeleme['sorgu'] ?> sorgu,
            <?= (int) $tazeleme['sayfa'] ?> sayfa, <?= (int) $tazeleme['denetlenen'] ?> adres denetimi.
        <?php else: ?>
            Veri alınamadı.
        <?php endif; ?>
        <?php foreach ($tazeleme['hatalar'] as $h): ?>
            <br><small><?= e($h) ?></small>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($hesap === null): ?>

    <div class="kutu" style="margin-top:20px;">
        <h2 style="margin-top:0;">Search Console bağlantısını kurun</h2>
        <p>
            Bu sayfa, Google'da hangi aramalarla bulunduğunuzu, hangi sayfaların dizinde olduğunu
            ve olmayanların nedenini her gün Search Console'dan otomatik çeker. Bunun için Google'a
            yalnızca <strong>okuma</strong> yetkisi olan bir servis hesabı tanıtmanız gerekiyor
            (yaklaşık 10 dakika, bir kez):
        </p>
        <ol style="line-height:1.7;">
            <li><a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud Console</a>'da
                yeni bir proje açın (ör. "Valentra").</li>
            <li>Aynı projede <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener">Google Search Console API</a>
                sayfasını açıp <strong>Etkinleştir</strong>'e basın.</li>
            <li><a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Servis hesapları</a>
                sayfasında <strong>Servis hesabı oluştur</strong> deyin; ad verin (ör. "valentra-search-console"),
                rol vermeden bitirin.</li>
            <li>Oluşan hesaba girip <strong>Anahtarlar → Anahtar ekle → Yeni anahtar oluştur → JSON</strong> seçin.
                Bir .json dosyası iner.</li>
            <li><a href="https://search.google.com/search-console/users" target="_blank" rel="noopener">Search Console → Ayarlar → Kullanıcılar ve izinler</a>
                bölümünde <strong>Kullanıcı ekle</strong> deyin, servis hesabının e-postasını
                (…@….iam.gserviceaccount.com) yazın, izin olarak <strong>Tam</strong> seçin.
                (Adres denetimi için "Tam" gerekiyor; panel yine de yalnızca okuma yapar.)</li>
            <li>İnen .json dosyasını Not Defteri ile açın, içeriğin tamamını aşağıya yapıştırın.</li>
        </ol>

        <form method="post" action="google.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="anahtar">
            <div class="alan">
                <label for="json">Servis hesabı JSON anahtarı</label>
                <textarea id="json" name="json" rows="8" required
                          placeholder='{"type": "service_account", "project_id": "...", "private_key": "-----BEGIN PRIVATE KEY-----...", "client_email": "...@....iam.gserviceaccount.com", ...}'
                          style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem;"></textarea>
                <div class="ipucu">
                    Anahtardan yalnızca e-posta ve özel anahtar saklanır; istekler salt okunur yetkiyle
                    imzalanır. Anahtar sızsa bile Search Console'da hiçbir şey değiştirilemez; yine de
                    başkasıyla paylaşmayın. İstediğiniz an bu sayfadan silebilirsiniz.
                </div>
            </div>
            <button type="submit" class="dugme dugme-ana">Kaydet ve bağlan</button>
        </form>
    </div>

<?php else: ?>

    <div class="kutu" style="margin-top:20px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
        <div>
            <strong>Bağlı:</strong> <?= e($hesap['client_email']) ?>
            <?php if ($site !== ''): ?> &middot; mülk <code><?= e($site) ?></code><?php endif; ?>
            <div class="ipucu" style="margin-top:4px;">
                <?= $sonTaze !== '' ? 'Son güncelleme: ' . e(tarih_bicimle($sonTaze)) : 'Henüz veri alınmadı.' ?>
                &middot; Search Console verisi 2–3 gün geriden gelir; sayfa günde bir kendiliğinden güncellenir.
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" action="google.php" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <button type="submit" name="islem" value="tazele" class="dugme dugme-ana">Şimdi güncelle</button>
            </form>
            <form method="post" action="google.php" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <button type="submit" name="islem" value="sil" class="dugme dugme-ret"
                        onclick="return confirm('Servis hesabı anahtarı silinsin mi?')">Anahtarı sil</button>
            </form>
        </div>
    </div>

    <?php if ($sonHata !== '' && $tazeleme === null): ?>
        <div class="uyari uyari-hata" style="margin-top:14px;">
            <strong>Son güncellemede sorun:</strong> <?= e($sonHata) ?>
        </div>
    <?php endif; ?>

    <?php if ($donem['bit'] !== null): ?>
        <?php $s = $donem['simdi']; $o = $donem['once']; ?>
        <div class="kutu" style="margin-top:20px;">
            <h2 style="margin-top:0;">Son 28 gün <span class="ipucu" style="font-weight:400;">(<?= e(tarih_bicimle($donem['bit'], false)) ?> dahil; önceki 28 günle)</span></h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
                <?php foreach ([
                    ['Tıklama', $sayi($s['tiklama']), $degisim($s['tiklama'], $o['tiklama'])],
                    ['Gösterim', $sayi($s['gosterim']), $degisim($s['gosterim'], $o['gosterim'])],
                    ['Tıklama oranı', $yuzde($s['ctr']), $degisim($s['ctr'], $o['ctr'])],
                    ['Ortalama sıra', $s['gosterim'] > 0 ? $sira($s['sira']) : '—', $degisim($s['sira'], $o['sira'], true)],
                ] as [$ad, $deger, $fark]): ?>
                    <div style="padding:12px 14px;border:1px solid var(--cizgi);border-radius:8px;">
                        <div class="ipucu"><?= e($ad) ?></div>
                        <div style="font-size:1.6rem;font-weight:800;"><?= e($deger) ?> <?= $fark ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($seri !== []): ?>
                <?php $enYuksek = max(1, max(array_column($seri, 'gosterim'))); ?>
                <div class="cubuk-grafik" style="margin-top:16px;">
                    <?php foreach ($seri as $gun): ?>
                        <div class="cubuk-sutun" title="<?= e(date('d.m.Y', strtotime($gun['tarih']))) ?>: <?= $gun['gosterim'] ?> gösterim, <?= $gun['tiklama'] ?> tıklama">
                            <span class="cubuk-deger"><?= $gun['tiklama'] > 0 ? $gun['tiklama'] : '' ?></span>
                            <div class="cubuk" style="height:<?= max(2, (int) round($gun['gosterim'] / $enYuksek * 100)) ?>%"></div>
                            <span class="cubuk-ad"><?= e(date('d.m', strtotime($gun['tarih']))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="ipucu" style="margin-bottom:0;">Sütun yüksekliği gösterim, üstündeki sayı tıklama.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($firsat !== []): ?>
        <div class="kutu" style="margin-top:20px;">
            <h2 style="margin-top:0;">Fırsat sorguları</h2>
            <p class="ipucu">
                Google'da görünüyorsunuz ama ilk sıralarda değilsiniz ya da tıklanmıyorsunuz. Bu aramalara
                cevap veren bir rehber yazmak ya da ilgili sayfanın başlığını ve özetini aramaya göre
                netleştirmek en hızlı kazançtır.
            </p>
            <table class="liste-tablo">
                <thead><tr><th>Sorgu</th><th class="sag">Gösterim</th><th class="sag">Tıklama</th><th class="sag">Oran</th><th class="sag">Sıra</th></tr></thead>
                <tbody>
                <?php foreach ($firsat as $r): ?>
                    <tr>
                        <td><?= e((string) $r['anahtar'][0]) ?></td>
                        <td class="sag"><?= $sayi($r['gosterim']) ?></td>
                        <td class="sag"><?= $sayi($r['tiklama']) ?></td>
                        <td class="sag"><?= $yuzde($r['ctr']) ?></td>
                        <td class="sag"><?= $sira($r['sira']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="istatistik-ikili">
        <?php foreach ([['Aramalar', $sorgular, 'sorgu'], ['Sayfalar', $sayfalar, 'sayfa']] as [$baslik, $tablo, $tur]): ?>
            <div class="kutu">
                <h2 style="margin-top:0;"><?= e($baslik) ?> <span class="ipucu" style="font-weight:400;">(son 28 gün)</span></h2>
                <?php if ($tablo['satirlar'] === []): ?>
                    <p class="ipucu">Henüz veri yok. Yeni sitelerde Google'ın gösterim vermeye başlaması birkaç hafta sürebilir.</p>
                <?php else: ?>
                    <table class="liste-tablo">
                        <thead><tr><th><?= $tur === 'sorgu' ? 'Arama' : 'Sayfa' ?></th><th class="sag">Tıklama</th><th class="sag">Gösterim</th><th class="sag">Sıra</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($tablo['satirlar'], 0, 50) as $r): ?>
                            <?php $ad = (string) $r['anahtar'][0]; ?>
                            <tr>
                                <td style="word-break:break-word;">
                                    <?php if ($tur === 'sayfa'): ?>
                                        <a href="<?= e($ad) ?>" target="_blank" rel="noopener"><?= e((string) preg_replace('#^https?://[^/]+#', '', $ad) ?: '/') ?></a>
                                    <?php else: ?>
                                        <?= e($ad) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="sag"><strong><?= $sayi($r['tiklama']) ?></strong></td>
                                <td class="sag"><?= $sayi($r['gosterim']) ?></td>
                                <td class="sag"><?= $sira($r['sira']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="kutu" style="margin-top:20px;">
        <h2 style="margin-top:0;">Dizin durumu</h2>
        <p class="ipucu">
            Önemli sayfalar Google'ın adres denetimiyle tek tek sorgulanıyor (her güncellemede en fazla
            <?= GSC_DENETIM_TUR ?> adres, her adres <?= GSC_DENETIM_GUN ?> günde bir). Sorunlular üstte.
        </p>

        <?php if ($haritalar !== []): ?>
            <p style="margin:8px 0 14px;">
                <?php foreach ($haritalar as $h): ?>
                    <strong>Site haritası</strong> <code><?= e((string) ($h['path'] ?? '')) ?></code>:
                    son okuma <?= e(isset($h['lastDownloaded']) ? tarih_bicimle(date('Y-m-d H:i:s', (int) strtotime((string) $h['lastDownloaded']))) : '—') ?>
                    <?php foreach ((array) ($h['contents'] ?? []) as $c): ?>
                        &middot; <?= $sayi((float) ($c['submitted'] ?? 0)) ?> adres gönderildi
                    <?php endforeach; ?>
                    <?php if ((int) ($h['errors'] ?? 0) > 0): ?>
                        &middot; <span style="color:var(--kirmizi);"><?= (int) $h['errors'] ?> hata</span>
                    <?php endif; ?>
                    <?php if ((int) ($h['warnings'] ?? 0) > 0): ?>
                        &middot; <span style="color:var(--sari);"><?= (int) $h['warnings'] ?> uyarı</span>
                    <?php endif; ?>
                    <br>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <?php if ($denetim === []): ?>
            <p class="ipucu">Henüz adres denetlenmedi.</p>
        <?php else: ?>
            <?php
            $dizinde = count(array_filter($denetim, static fn (array $d): bool => $d['sonuc'] === 'PASS'));
            ?>
            <p><strong><?= $dizinde ?> / <?= count($denetim) ?></strong> denetlenen adres Google dizininde.</p>
            <table class="liste-tablo">
                <thead><tr><th>Adres</th><th>Durum</th><th>Ne yapılmalı</th><th>Son tarama</th></tr></thead>
                <tbody>
                <?php foreach ($denetim as $d): ?>
                    <?php [$durum, $oneri] = gsc_kapsam_acikla((string) $d['kapsam']); ?>
                    <tr>
                        <td style="word-break:break-word;">
                            <a href="<?= e((string) $d['url']) ?>" target="_blank" rel="noopener"><?= e((string) preg_replace('#^https?://[^/]+#', '', (string) $d['url']) ?: '/') ?></a>
                        </td>
                        <td>
                            <span class="rozet rozet-<?= $d['sonuc'] === 'PASS' ? 'yayinda' : ($d['sonuc'] === 'FAIL' ? 'reddedildi' : 'taslak') ?>"><?= e($durum) ?></span>
                        </td>
                        <td class="ipucu"><?= e($oneri) ?></td>
                        <td><?= $d['son_tarama'] ? e(tarih_bicimle((string) $d['son_tarama'], false)) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php endif; ?>

<div class="kutu" style="margin-top:20px;">
    <h2 style="margin-top:0;">Sayfa kalitesi (kendi verimizden)</h2>
    <p class="ipucu">
        Google'a sormadan görülebilen sorunlar. Aynı başlıklı sayfalar birbirinin rakibi olur;
        uzun başlıklar arama sonucunda kesilir; özeti boş ya da çok kısa sayfanın açıklamasını
        Google metinden rastgele seçer.
    </p>

    <?php
    $gruplar = [
        'ayni_baslik' => ['Aynı başlığı taşıyan haberler', static fn (array $r): string => $r['adet'] . ' haber'],
        'uzun_baslik' => ['70 karakterden uzun başlıklar', static fn (array $r): string => $r['uzunluk'] . ' karakter'],
        'kisa_ozet'   => ['Özeti 70 karakterden kısa haberler', static fn (array $r): string => $r['uzunluk'] . ' karakter'],
    ];
    ?>

    <?php foreach ($gruplar as $anahtar => [$baslik, $ek]): ?>
        <h3 style="margin:14px 0 6px;"><?= e($baslik) ?>: <?= count($kalite[$anahtar]) ?><?= count($kalite[$anahtar]) >= 20 ? '+' : '' ?></h3>
        <?php if ($kalite[$anahtar] === []): ?>
            <p class="ipucu" style="margin:0;">Sorun yok.</p>
        <?php else: ?>
            <ul style="margin:0;padding-left:1.2em;">
                <?php foreach (array_slice($kalite[$anahtar], 0, 8) as $r): ?>
                    <li>
                        <a href="<?= e(haber_yolu((string) $r['slug'])) ?>" target="_blank" rel="noopener"><?= e(kisalt((string) $r['baslik'], 110)) ?></a>
                        <span class="ipucu">(<?= e($ek($r)) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>

    <h3 style="margin:14px 0 6px;">Kalıcı içerik</h3>
    <p style="margin:0;">
        Yayında <strong><?= rehber_yayinda_sayisi() ?></strong> uygulama rehberi var.
        Haberler birkaç gün okunur; "nasıl hesaplanır" rehberleri aylarca arama trafiği getirir.
        <a href="rehberler.php">Rehberleri yönetin</a>.
    </p>
</div>

<?php require __DIR__ . '/alt.php'; ?>
