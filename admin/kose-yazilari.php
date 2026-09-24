<?php
declare(strict_types=1);

/**
 * Köşe yazıları ("Valentra Diyor ki…") onayı.
 *
 * Ajan her gun 3-4 yazi yazip buraya taslak olarak birakiyor. Yazi
 * okunup gerekirse duzeltiliyor ve yayimlaniyor. Ajanin editor notu
 * (dogrulanmasi gereken rakamlar) her yazinin ustunde.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ayarlar.php';
require_once __DIR__ . '/../includes/ajan_tetikle.php';
require_once __DIR__ . '/../includes/kose.php';

giris_zorunlu();

$gecerliDurumlar = [HABER_TASLAK, HABER_YAYINDA, HABER_REDDEDILDI];
$durum = (string) ($_GET['durum'] ?? HABER_TASLAK);

if (!in_array($durum, $gecerliDurumlar, true)) {
    $durum = HABER_TASLAK;
}

$hata = '';
$tetikMesaji = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);

    if ($islem === 'yaz') {
        $tetikMesaji = ajan_tetikle(false, 36, 25, 'kose-yazisi');
    } elseif ($id > 0 && kose_bul($id) !== null) {
        $bildirim = null;
        $sekme    = $durum;

        try {
            if ($islem === 'kaydet' || $islem === 'kaydet_yayinla') {
                kose_guncelle(
                    $id,
                    (string) ($_POST['baslik'] ?? ''),
                    (string) ($_POST['ozet'] ?? ''),
                    (string) ($_POST['icerik'] ?? '')
                );
                $bildirim = 'kaydedildi';
            }

            if ($islem === 'kaydet_yayinla' || $islem === 'yayinla') {
                kose_yayinla($id, aktif_yonetici_id());
                $bildirim = 'yayinlandi';
                $sekme    = HABER_YAYINDA;
            } elseif ($islem === 'reddet') {
                kose_durum_degistir($id, HABER_REDDEDILDI);
                $bildirim = 'reddedildi';
                $sekme    = HABER_REDDEDILDI;
            } elseif ($islem === 'geri_al') {
                kose_durum_degistir($id, HABER_TASLAK);
                $bildirim = 'geri';
                $sekme    = HABER_TASLAK;
            } elseif ($islem === 'sil') {
                kose_sil($id);
                $bildirim = 'silindi';
            }
        } catch (InvalidArgumentException $e) {
            $hata = $e->getMessage();
        }

        if ($hata === '' && $bildirim !== null) {
            yonlendir('kose-yazilari.php?durum=' . $sekme . '&bildirim=' . $bildirim);
        }
    }
}

$bildirimler = [
    'kaydedildi' => ['basari', 'Yazı kaydedildi.'],
    'yayinlandi' => ['basari', 'Yazı yayımlandı; ana sayfadaki "Valentra Diyor ki…" kutusunda.'],
    'reddedildi' => ['bilgi',  'Yazı reddedildi. Reddedilen yazılar günlük sayıya girmez; ajanı yeniden çalıştırırsanız yerine yenisi yazılır.'],
    'geri'       => ['bilgi',  'Yazı yayından kaldırıldı, onay bekleyenlere alındı.'],
    'silindi'    => ['bilgi',  'Yazı silindi.'],
];

$bildirim = $bildirimler[(string) ($_GET['bildirim'] ?? '')] ?? null;
$sayilar  = kose_durum_sayilari();
$yazilar  = kose_listele($durum);

$panelBasligi = 'Köşe yazıları';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== null): ?>
    <div class="uyari uyari-<?= e($bildirim[0]) ?>" style="margin-top:20px;"><?= e($bildirim[1]) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>

<?php if ($tetikMesaji !== null): ?>
    <div class="uyari uyari-<?= $tetikMesaji['tamam'] ? 'basari' : 'hata' ?>" style="margin-top:20px;">
        <?= e($tetikMesaji['mesaj']) ?>
    </div>
<?php endif; ?>

<div class="kutu toplama-cubugu" style="margin-top:20px;">
    <div>
        <strong>Valentra Diyor ki…</strong>
        <p class="ipucu" style="margin:4px 0 0;">
            Ajan her gün öğleden sonra gündemin 3-4 konusunu seçip her biri
            için ayrı bir yazı yazar. Hiçbir yazı onayınız olmadan
            yayımlanmaz. Beklemek istemezseniz şimdi yazdırabilirsiniz;
            bugün için eksik kalan kadar yazı yazılır.
        </p>
    </div>

    <?php if (ajan_tetikleyebilir_mi()): ?>
        <form method="post" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="yaz">
            <button type="submit" class="dugme dugme-ana">Şimdi yaz</button>
        </form>
    <?php else: ?>
        <a href="ajan.php" class="dugme">Ajanı ayarla</a>
    <?php endif; ?>
</div>

<nav class="sekmeler">
    <a href="?durum=taslak" class="<?= $durum === HABER_TASLAK ? 'aktif' : '' ?>">
        Onay bekleyen
        <?php if ($sayilar[HABER_TASLAK] > 0): ?>
            <span class="adet"><?= $sayilar[HABER_TASLAK] ?></span>
        <?php endif; ?>
    </a>
    <a href="?durum=yayinda" class="<?= $durum === HABER_YAYINDA ? 'aktif' : '' ?>">
        Yayında (<?= $sayilar[HABER_YAYINDA] ?>)
    </a>
    <a href="?durum=reddedildi" class="<?= $durum === HABER_REDDEDILDI ? 'aktif' : '' ?>">
        Reddedilen (<?= $sayilar[HABER_REDDEDILDI] ?>)
    </a>
</nav>

<?php if ($yazilar === []): ?>
    <div class="bos-durum">
        <?php if ($durum === HABER_TASLAK): ?>
            <strong>Onay bekleyen yazı yok.</strong>
            Ajan günün yazılarını yazdığında burada listelenecek.
        <?php elseif ($durum === HABER_YAYINDA): ?>
            <strong>Yayında yazı yok.</strong>
        <?php else: ?>
            <strong>Reddedilen yazı yok.</strong>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($yazilar as $yazi): ?>
    <?php $yid = (int) $yazi['id']; ?>
    <article class="kutu" style="margin-top:16px;">
        <div class="satir-bilgi" style="margin-bottom:6px;">
            <span class="rozet rozet-<?= e((string) $yazi['durum']) ?>"><?= e((string) $yazi['durum']) ?></span>
            <span><?= e(tarih_bicimle((string) $yazi['gun'], false)) ?></span>
            <?php if ((string) $yazi['gundem'] !== ''): ?>
                <span class="rozet rozet-skor"><?= e((string) $yazi['gundem']) ?></span>
            <?php endif; ?>
            <span><?= e((string) count(preg_split('/\s+/u', trim((string) $yazi['icerik'])) ?: [])) ?> kelime</span>
        </div>

        <h3 style="margin:4px 0 6px;"><?= e((string) $yazi['baslik']) ?></h3>

        <?php if ((string) $yazi['ozet'] !== ''): ?>
            <p class="ipucu" style="margin:0 0 8px;"><?= e((string) $yazi['ozet']) ?></p>
        <?php endif; ?>

        <?php if ((string) $yazi['ajan_notu'] !== ''): ?>
            <div class="uyari uyari-bilgi" style="margin:8px 0;">
                <strong>Doğrulanacaklar:</strong> <?= e((string) $yazi['ajan_notu']) ?>
            </div>
        <?php endif; ?>

        <?php $dayanaklar = kose_dayanaklar((string) $yazi['haber_idleri']); ?>
        <?php if ($dayanaklar !== []): ?>
            <div class="satir-bilgi" style="margin:6px 0 10px;flex-wrap:wrap;">
                Dayandığı haberler:
                <?php foreach ($dayanaklar as $d): ?>
                    <a href="<?= e($d['adres']) ?>" target="_blank" rel="noopener"><?= e(kisalt($d['baslik'], 70)) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <details>
            <summary style="cursor:pointer;font-weight:600;">Metni oku ve düzelt</summary>

            <form method="post" action="kose-yazilari.php?durum=<?= e($durum) ?>" style="margin-top:12px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="id" value="<?= $yid ?>">

                <div class="alan">
                    <label for="baslik-<?= $yid ?>">Başlık</label>
                    <input type="text" id="baslik-<?= $yid ?>" name="baslik" maxlength="300" required
                           value="<?= e((string) $yazi['baslik']) ?>">
                </div>

                <div class="alan">
                    <label for="ozet-<?= $yid ?>">Özet</label>
                    <textarea id="ozet-<?= $yid ?>" name="ozet" maxlength="600" rows="2"><?= e((string) $yazi['ozet']) ?></textarea>
                </div>

                <div class="alan">
                    <label for="icerik-<?= $yid ?>">Yazı</label>
                    <textarea id="icerik-<?= $yid ?>" name="icerik" rows="22" required><?= e((string) $yazi['icerik']) ?></textarea>
                    <div class="ipucu">
                        Paragrafları boş satırla ayırın. Ara başlık satırı "## " ile,
                        madde satırları "- " ile başlar.
                    </div>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" name="islem" value="kaydet" class="dugme">Kaydet</button>
                    <?php if ($yazi['durum'] !== HABER_YAYINDA): ?>
                        <button type="submit" name="islem" value="kaydet_yayinla" class="dugme dugme-onay">Kaydet ve yayımla</button>
                    <?php endif; ?>
                </div>
            </form>
        </details>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <?php
            $dugmeler = match ((string) $yazi['durum']) {
                HABER_TASLAK     => ['yayinla' => ['Yayımla', 'dugme-onay'], 'reddet' => ['Reddet', 'dugme-ret']],
                HABER_YAYINDA    => ['geri_al' => ['Yayından kaldır', 'dugme-ret']],
                default          => ['yayinla' => ['Yine de yayımla', 'dugme-onay'], 'sil' => ['Sil', 'dugme-ret']],
            };
            ?>

            <?php if ($yazi['durum'] === HABER_YAYINDA): ?>
                <a class="dugme" href="<?= e(kose_yolu((string) $yazi['slug'])) ?>" target="_blank" rel="noopener">Sitede gör</a>
            <?php endif; ?>

            <?php foreach ($dugmeler as $islemAdi => [$etiket, $sinif]): ?>
                <form method="post" action="kose-yazilari.php?durum=<?= e($durum) ?>" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="id" value="<?= $yid ?>">
                    <button type="submit" name="islem" value="<?= e($islemAdi) ?>" class="dugme <?= e($sinif) ?>"
                        <?= $islemAdi === 'sil' ? 'onclick="return confirm(\'Yazı kalıcı olarak silinsin mi?\')"' : '' ?>>
                        <?= e($etiket) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </article>
<?php endforeach; ?>

<?php require __DIR__ . '/alt.php'; ?>
