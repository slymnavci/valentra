<?php
declare(strict_types=1);

/**
 * Uygulama rehberleri: yazma, düzeltme, onay.
 *
 * Taslaklar sitede gorunmez. "Onizle" oturum acikken taslagi sitedeki
 * son haliyle gosterir (arama motorlarina kapali). "Yayinla" ile
 * sitedeki Rehberler bolumune, site haritasina ve aramaya girer.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rehberler.php';

giris_zorunlu();

$hatalar = [];
$duzenle = isset($_GET['duzenle']) ? (int) $_GET['duzenle'] : null;   // 0 = yeni
$girdi   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);

    if ($islem === 'kaydet' || $islem === 'kaydet_yayinla') {
        $sonuc = rehber_kaydet($_POST, $id);

        if ($sonuc['tamam']) {
            if ($islem === 'kaydet_yayinla') {
                rehber_yayin($sonuc['id'], true);
            }

            yonlendir('rehberler.php?bildirim=' . ($islem === 'kaydet_yayinla' ? 'yayinlandi' : 'kaydedildi')
                      . '&duzenle=' . $sonuc['id'] . '#form');
        }

        $hatalar = $sonuc['hatalar'];
        $duzenle = $id;
        $girdi   = $_POST;
    } elseif ($id > 0 && rehber_bul($id) !== null) {
        match ($islem) {
            'yayinla' => rehber_yayin($id, true),
            'taslak'  => rehber_yayin($id, false),
            'sil'     => rehber_sil($id),
            default   => null,
        };

        $sonucBildirim = ['yayinla' => 'yayinlandi', 'taslak' => 'taslak', 'sil' => 'silindi'][$islem] ?? '';
        yonlendir('rehberler.php?bildirim=' . $sonucBildirim);
    }
}

$bildirimler = [
    'kaydedildi' => ['basari', 'Rehber kaydedildi.'],
    'yayinlandi' => ['basari', 'Rehber yayımlandı: sitede Rehberler bölümünde, site haritasında ve aramada.'],
    'taslak'     => ['bilgi',  'Rehber yayından kaldırıldı, taslaklara alındı.'],
    'silindi'    => ['bilgi',  'Taslak silindi.'],
];

$bildirim  = $bildirimler[(string) ($_GET['bildirim'] ?? '')] ?? null;
$rehberler = rehber_hepsi();
$secili    = $duzenle !== null && $duzenle > 0 ? rehber_bul($duzenle) : null;
$form      = $girdi ?? $secili ?? ['baslik' => '', 'slug' => '', 'ozet' => '', 'icerik' => '', 'konu' => '',
                                   'arac' => '', 'hazirlayan' => 'Valentra Yayın Kurulu', 'kontrol_eden' => '', 'sira' => 100];

$panelBasligi = 'Rehberler';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== null): ?>
    <div class="uyari uyari-<?= e($bildirim[0]) ?>" style="margin-top:20px;"><?= e($bildirim[1]) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">Uygulama rehberleri</h2>
            <p class="ipucu" style="margin:4px 0 0;">
                Kalıcı "nasıl yapılır" içerikleri. Taslaklar sitede görünmez; okuyup
                <strong>Yayımla</strong> dediğinizde sitede, site haritasında ve aramada yer alır.
                Hazırlayan ve kontrol eden bilgisi rehberin başında ve Google'a verilen
                yapısal veride görünür; gerçeği yansıtacak şekilde doldurun.
            </p>
        </div>
        <a class="dugme dugme-ana" href="rehberler.php?duzenle=0#form">Yeni rehber</a>
    </div>

    <table class="liste-tablo" style="margin-top:14px;">
        <thead>
            <tr><th>Rehber</th><th>Konu</th><th>Durum</th><th>Kontrol eden</th><th>Güncellendi</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rehberler as $r): ?>
            <?php $rid = (int) $r['id']; ?>
            <tr>
                <td><strong><?= e((string) $r['baslik']) ?></strong></td>
                <td><?= e((string) $r['konu']) ?></td>
                <td><span class="rozet rozet-<?= e((string) $r['durum']) ?>"><?= $r['durum'] === REHBER_YAYINDA ? 'yayında' : 'taslak' ?></span></td>
                <td><?= (string) ($r['kontrol_eden'] ?? '') !== '' ? e((string) $r['kontrol_eden']) : '<span class="ipucu">—</span>' ?></td>
                <td><?= e(tarih_bicimle((string) $r['guncellendi'], false)) ?></td>
                <td style="white-space:nowrap;">
                    <a class="dugme" href="rehberler.php?duzenle=<?= $rid ?>#form">Düzenle</a>
                    <a class="dugme" href="<?= e(rehber_yolu((string) $r['slug']) . ($r['durum'] === REHBER_YAYINDA ? '' : (str_contains(rehber_yolu('x'), '?') ? '&' : '?') . 'onizleme=1')) ?>"
                       target="_blank" rel="noopener"><?= $r['durum'] === REHBER_YAYINDA ? 'Sitede gör' : 'Önizle' ?></a>
                    <form method="post" action="rehberler.php" style="display:inline;margin:0;">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="id" value="<?= $rid ?>">
                        <?php if ($r['durum'] === REHBER_YAYINDA): ?>
                            <button type="submit" name="islem" value="taslak" class="dugme dugme-ret">Yayından kaldır</button>
                        <?php else: ?>
                            <button type="submit" name="islem" value="yayinla" class="dugme dugme-onay">Yayımla</button>
                            <button type="submit" name="islem" value="sil" class="dugme dugme-ret"
                                    onclick="return confirm('Taslak kalıcı olarak silinsin mi?')">Sil</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rehberler === []): ?>
            <tr><td colspan="6" class="ipucu">Henüz rehber yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($duzenle !== null): ?>
    <div class="kutu" id="form" style="margin-top:20px;">
        <h2 style="margin-top:0;"><?= $duzenle > 0 ? 'Rehberi düzenle' : 'Yeni rehber' ?></h2>

        <?php if ($hatalar !== []): ?>
            <div class="uyari uyari-hata"><?= implode('<br>', array_map('e', $hatalar)) ?></div>
        <?php endif; ?>

        <form method="post" action="rehberler.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="id" value="<?= (int) $duzenle ?>">

            <div class="alan">
                <label for="baslik">Başlık</label>
                <input type="text" id="baslik" name="baslik" maxlength="200" required value="<?= e((string) $form['baslik']) ?>">
                <div class="ipucu">Arama sonucunda görünen başlık. Okuyucunun sorusuyla başlayın ("… nasıl hesaplanır?").</div>
            </div>

            <div class="alan">
                <label for="ozet">Özet (arama sonucundaki açıklama)</label>
                <textarea id="ozet" name="ozet" maxlength="400" rows="2"><?= e((string) $form['ozet']) ?></textarea>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
                <div class="alan">
                    <label for="slug">Adres (boşsa başlıktan)</label>
                    <input type="text" id="slug" name="slug" maxlength="220" value="<?= e((string) $form['slug']) ?>">
                </div>
                <div class="alan">
                    <label for="konu">Konu</label>
                    <input type="text" id="konu" name="konu" maxlength="80" value="<?= e((string) $form['konu']) ?>"
                           placeholder="Finansal yönetim">
                </div>
                <div class="alan">
                    <label for="arac">Bağlı hesaplama aracı</label>
                    <select id="arac" name="arac">
                        <?php foreach (['' => '—', 'kdv' => 'KDV dahil/hariç', 'vade-farki' => 'Vade farkı', 'basabas' => 'Başabaş'] as $d => $a): ?>
                            <option value="<?= e($d) ?>" <?= (string) $form['arac'] === $d ? 'selected' : '' ?>><?= e($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alan">
                    <label for="sira">Sıra</label>
                    <input type="number" id="sira" name="sira" value="<?= (int) $form['sira'] ?>">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
                <div class="alan">
                    <label for="hazirlayan">Hazırlayan</label>
                    <input type="text" id="hazirlayan" name="hazirlayan" maxlength="160" value="<?= e((string) $form['hazirlayan']) ?>">
                </div>
                <div class="alan">
                    <label for="kontrol_eden">Kontrol eden (ad, unvan)</label>
                    <input type="text" id="kontrol_eden" name="kontrol_eden" maxlength="160"
                           value="<?= e((string) ($form['kontrol_eden'] ?? '')) ?>" placeholder="Örn. Ad Soyad, YMM">
                    <div class="ipucu">Metni gerçekten kontrol eden kişi. Boş bırakılırsa sayfada gösterilmez.</div>
                </div>
            </div>

            <div class="alan">
                <label for="icerik">Metin</label>
                <textarea id="icerik" name="icerik" rows="28" required style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.9rem;"><?= e((string) $form['icerik']) ?></textarea>
                <div class="ipucu">
                    Paragrafları boş satırla ayırın. <code>## Başlık</code>, <code>### Alt başlık</code>,
                    <code>- madde</code>, <code>1. adım</code>, <code>&gt; Dikkat: not</code>,
                    tablo için <code>| Kalem | Tutar |</code> satırları (ilk satır başlık),
                    hesaplama aracı bağlantısı için <code>[[arac:vade-farki]]</code>.
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" name="islem" value="kaydet" class="dugme">Kaydet</button>
                <?php if (($secili['durum'] ?? REHBER_TASLAK) !== REHBER_YAYINDA): ?>
                    <button type="submit" name="islem" value="kaydet_yayinla" class="dugme dugme-onay">Kaydet ve yayımla</button>
                <?php endif; ?>
                <a class="dugme" href="rehberler.php">Kapat</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
