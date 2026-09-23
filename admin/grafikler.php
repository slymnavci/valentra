<?php
declare(strict_types=1);

/**
 * Grafik oluşturucu.
 *
 * Yonetici bir EVDS serisi seciyor, nasil gosterilecegini ayarliyor,
 * veriyi cekip onizlemede goruyor ve yayina aliyor. Yayindaki grafik
 * ana sayfada piyasa seridinin altinda cikiyor; verisi ajanin her
 * calismasinda (4 saatte bir) kendiliginden tazeleniyor.
 *
 * Onizleme bilincli olarak SON DEGERI ve tarihini buyuk yaziyor: seri
 * kodu yanlissa (baska bir seriyse) bunu en hizli gosteren sey, bilinen
 * bir rakamla karsilastirmak. Politika faizi sanilan serinin fonlama
 * maliyeti ciktigi deneyimden sonra eklendi.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grafikler.php';
require_once __DIR__ . '/../includes/grafik.php';

giris_zorunlu();

$bildirim = '';
$hata     = '';
$formHatalari = [];
$formVeri = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);

    if ($islem === 'kaydet') {
        $girdi = $_POST;

        // Hazir listeden secildiyse bos birakilan alanlar oradan dolsun.
        $hazir = grafik_katalogu()[(string) ($_POST['hazir'] ?? '')] ?? null;

        if ($hazir !== null && trim((string) ($girdi['seri_kodu'] ?? '')) === '') {
            $girdi['seri_kodu'] = (string) $_POST['hazir'];
        }

        if ($hazir !== null && trim((string) ($girdi['baslik'] ?? '')) === '') {
            $girdi['baslik'] = $hazir['ad'];
        }

        $kayit = grafik_kaydet($girdi, $id);

        if ($kayit['tamam']) {
            // Kaydeder kaydetmez veriyi cek: onizleme hemen gorunsun.
            grafik_tazele($kayit['id']);

            /*
             * Yonlendirmede yalnizca NUMARA tasiniyor, mesaj metni degil.
             * Metin adreste gitseydi, panelde istenen yaziyi gosteren bir
             * baglanti hazirlanip yoneticiye gonderilebilirdi. Mesaj
             * asagida veritabanindaki durumdan uretiliyor.
             */
            yonlendir('grafikler.php?kaydedildi=' . $kayit['id'] . '#grafik-' . $kayit['id']);
        }

        $formHatalari = $kayit['hatalar'];
        $formVeri     = $girdi + ['id' => $id];
    } elseif ($islem === 'tazele' && $id > 0) {
        $sonuc = grafik_tazele($id);

        if ($sonuc['tamam']) {
            $bildirim = 'Veri tazelendi: ' . $sonuc['mesaj'];
        } else {
            $hata = 'Veri alınamadı: ' . $sonuc['mesaj'] . ' Son iyi veri korundu.';
        }
    } elseif (($islem === 'yayinla' || $islem === 'kaldir') && $id > 0) {
        $sonuc = grafik_yayin($id, $islem === 'yayinla');

        if ($sonuc['tamam']) {
            $bildirim = $sonuc['mesaj'];
        } else {
            $hata = $sonuc['mesaj'];
        }
    } elseif ($islem === 'sil' && $id > 0) {
        grafik_sil($id);
        $bildirim = 'Grafik silindi.';
    }
}

// Kaydet sonrasi: mesaj grafigin kayitli durumundan uretiliyor.
if (isset($_GET['kaydedildi']) && $bildirim === '' && $hata === '') {
    $kaydedilen = grafik_bul((int) $_GET['kaydedildi']);

    if ($kaydedilen !== null && empty($kaydedilen['son_hata'])
        && pratik_seri_oku($kaydedilen['seri'] ?? null) !== []) {
        $bildirim = 'Kaydedildi ve veri alındı. Aşağıda grafiği ve son değeri kontrol edip yayına alın.';
    } elseif ($kaydedilen !== null) {
        $hata = 'Kaydedildi ama veri alınamadı: ' . (string) ($kaydedilen['son_hata'] ?? 'bilinmeyen hata');
    }
}

$grafikler = grafik_listele();
$duzenlenen = isset($_GET['duzenle']) ? grafik_bul((int) $_GET['duzenle']) : null;

if ($formVeri === [] && $duzenlenen !== null) {
    $formVeri = $duzenlenen;
}

$evdsVar = ayar_oku('evds_anahtari') !== '';

$panelBasligi = 'Grafikler';
require __DIR__ . '/ust.php';

$secili = static fn (mixed $a, mixed $b): string => (string) $a === (string) $b ? ' selected' : '';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:14px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Grafikler</h2>
    <p class="ipucu" style="margin:0;">
        TCMB EVDS'deki herhangi bir seriden grafik oluşturun. Yayına aldığınız
        grafikler ana sayfada piyasa şeridinin altında görünür; verisi
        ajanın her çalışmasında (4 saatte bir) kendiliğinden tazelenir.
        Veri alınamazsa son iyi veri ekranda kalır.
    </p>

    <?php if (!$evdsVar): ?>
        <div class="uyari uyari-hata" style="margin-top:12px;">
            EVDS anahtarı girilmemiş; veri çekilemez.
            <a href="pratik.php">Pratik bilgiler</a> sayfasından girin.
        </div>
    <?php endif; ?>
</div>

<?php foreach ($grafikler as $g):
    $seri    = pratik_seri_oku($g['seri'] ?? null);
    $son     = $seri !== [] ? $seri[count($seri) - 1] : null;
    $ayar    = grafik_ciz_ayari($g);
    ?>
    <div class="kutu grafik-kart" id="grafik-<?= (int) $g['id'] ?>" style="margin-top:18px;">
        <div class="grafik-kart-ust">
            <div>
                <h3 style="margin:0 0 4px;"><?= e((string) $g['baslik']) ?></h3>
                <p class="ipucu" style="margin:0;">
                    <code><?= e((string) $g['seri_kodu']) ?></code>
                    · <?= e(grafik_donusumleri()[$g['donusum']] ?? $g['donusum']) ?>
                    · <?= e(grafik_donemleri()[(int) $g['donem_ay']] ?? $g['donem_ay'] . ' ay') ?>
                    · <?= $g['ana_sayfa'] ? 'ana sayfada' : 'ana sayfada değil' ?>
                    · sıra <?= (int) $g['sira'] ?>
                </p>
            </div>
            <span class="rozet <?= $g['yayinda'] ? 'rozet-yayinda' : 'rozet-taslak' ?>">
                <?= $g['yayinda'] ? 'Yayında' : 'Taslak' ?>
            </span>
        </div>

        <?php if ($son !== null): ?>
            <p class="grafik-son-deger">
                Son değer: <strong><?= e(grafik_deger((float) $son[1], (string) $g['birim'],
                    $g['birim'] === 'tl' ? 4 : 2)) ?></strong>
                <span class="ipucu">— <?= e(grafik_tarih_uzun((string) $son[0], true)) ?>.
                Bildiğiniz bir rakamla karşılaştırın; tutmuyorsa seri kodu yanlış olabilir.</span>
            </p>
            <?= grafik_ciz($seri, $ayar) ?>
        <?php else: ?>
            <p class="ipucu">Henüz veri yok.</p>
        <?php endif; ?>

        <p class="ipucu" style="margin:10px 0 0;">
            <?php if (!empty($g['seri_tarihi'])): ?>
                Veri: <?= e(tarih_bicimle((string) $g['seri_tarihi'], true)) ?>
            <?php endif; ?>
            <?php if (!empty($g['son_hata'])): ?>
                <br><strong style="color:var(--kirmizi);">Son deneme başarısız:</strong>
                <?= e((string) $g['son_hata']) ?>
                (<?= e(tarih_bicimle((string) $g['son_deneme'], true)) ?>)
            <?php endif; ?>
        </p>

        <div class="grafik-kart-dugmeler">
            <?php foreach ([
                ['tazele', 'Veriyi şimdi çek', 'dugme'],
                $g['yayinda'] ? ['kaldir', 'Yayından kaldır', 'dugme'] : ['yayinla', 'Yayına al', 'dugme dugme-ana'],
            ] as [$islemAdi, $etiket, $sinif]): ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="islem" value="<?= e($islemAdi) ?>">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <button type="submit" class="<?= e($sinif) ?>"><?= e($etiket) ?></button>
                </form>
            <?php endforeach; ?>

            <a class="dugme" href="grafikler.php?duzenle=<?= (int) $g['id'] ?>#form">Düzenle</a>

            <form method="post" onsubmit="return confirm('Bu grafik silinsin mi?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="islem" value="sil">
                <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                <button type="submit" class="dugme dugme-ret">Sil</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php
$fid     = (int) ($formVeri['id'] ?? 0);
$fKod    = (string) ($formVeri['seri_kodu'] ?? '');
$katalog = grafik_katalogu();
?>
<div class="kutu" id="form" style="margin-top:26px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">
        <?= $fid > 0 ? 'Grafiği düzenle' : 'Yeni grafik' ?>
    </h2>
    <p class="ipucu" style="margin:0 0 14px;">
        Hazır listeden seçin ya da EVDS'de bulduğunuz seri kodunu yazın
        (EVDS'de seriyi açtığınızda kodu görünür, örnek:
        <code>TP.DK.USD.A.YTL</code>). Kaydedince veri hemen çekilir ve
        yukarıda önizlemesi çıkar; yayına almak ayrı bir adımdır.
        <?php if ($fid > 0): ?>
            Seri kodunu, gösterimi, dönemi ya da çizim türünü değiştirirseniz
            eski veri silinir ve grafik taslağa döner.
        <?php endif; ?>
    </p>

    <?php if ($formHatalari !== []): ?>
        <div class="uyari uyari-hata" style="margin-bottom:12px;">
            <?php foreach ($formHatalari as $h): ?><div><?= e($h) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="grafik-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="kaydet">
        <input type="hidden" name="id" value="<?= $fid ?>">

        <div class="alan">
            <label for="hazir">Hazır seri</label>
            <select id="hazir" name="hazir">
                <option value="">— Başka EVDS serisi (kodu aşağıya yazın) —</option>
                <?php foreach ($katalog as $kod => $k): ?>
                    <option value="<?= e($kod) ?>"<?= $secili($fKod, $kod) ?>
                            data-ad="<?= e($k['ad']) ?>" data-birim="<?= e($k['birim']) ?>"
                            data-donusum="<?= e($k['donusum']) ?>" data-tur="<?= e($k['tur']) ?>"
                            data-donem="<?= (int) $k['donem'] ?>" data-kaynak="<?= e($k['kaynak']) ?>">
                        <?= e($k['ad']) ?> (<?= e($kod) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="alan">
            <label for="seri_kodu">EVDS seri kodu</label>
            <input type="text" id="seri_kodu" name="seri_kodu" value="<?= e($fKod) ?>"
                   placeholder="TP.DK.USD.A.YTL" autocomplete="off" spellcheck="false">
        </div>

        <div class="alan">
            <label for="baslik">Başlık</label>
            <input type="text" id="baslik" name="baslik" maxlength="160"
                   value="<?= e((string) ($formVeri['baslik'] ?? '')) ?>" placeholder="Dolar kuru">
        </div>

        <div class="grafik-form-izgara">
            <div class="alan">
                <label for="donusum">Gösterim</label>
                <select id="donusum" name="donusum">
                    <?php foreach (grafik_donusumleri() as $k => $ad): ?>
                        <option value="<?= e($k) ?>"<?= $secili($formVeri['donusum'] ?? 'duzey', $k) ?>><?= e($ad) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="alan">
                <label for="donem_ay">Dönem</label>
                <select id="donem_ay" name="donem_ay">
                    <?php foreach (grafik_donemleri() as $k => $ad): ?>
                        <option value="<?= (int) $k ?>"<?= $secili($formVeri['donem_ay'] ?? 24, $k) ?>><?= e($ad) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="alan">
                <label for="birim">Birim</label>
                <select id="birim" name="birim">
                    <?php foreach (grafik_birimleri() as $k => $ad): ?>
                        <option value="<?= e($k) ?>"<?= $secili($formVeri['birim'] ?? 'yuzde', $k) ?>><?= e($ad) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="alan">
                <label for="tur">Çizim</label>
                <select id="tur" name="tur">
                    <?php foreach (grafik_turleri() as $k => $ad): ?>
                        <option value="<?= e($k) ?>"<?= $secili($formVeri['tur'] ?? 'cizgi', $k) ?>><?= e($ad) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grafik-form-izgara">
            <div class="alan">
                <label for="kaynak_adi">Kaynak adı (grafiğin altında yazar)</label>
                <input type="text" id="kaynak_adi" name="kaynak_adi" maxlength="160"
                       value="<?= e((string) ($formVeri['kaynak_adi'] ?? 'TCMB — EVDS')) ?>">
            </div>

            <div class="alan">
                <label for="sira">Sıra (küçük olan önce)</label>
                <input type="number" id="sira" name="sira" value="<?= (int) ($formVeri['sira'] ?? 100) ?>">
            </div>
        </div>

        <label class="grafik-onay">
            <input type="checkbox" name="ana_sayfa" value="1"
                <?= !isset($formVeri['ana_sayfa']) || !empty($formVeri['ana_sayfa']) ? 'checked' : '' ?>>
            Yayına alınınca ana sayfada göster
        </label>

        <p class="ipucu" style="margin:8px 0 14px;">
            Yıllık ya da aylık değişim seçilirse birim yüzde, çizim çizgi olur.
        </p>

        <button type="submit" class="dugme dugme-ana">Kaydet ve veriyi çek</button>
        <?php if ($fid > 0): ?>
            <a class="dugme" href="grafikler.php">Vazgeç</a>
        <?php endif; ?>
    </form>
</div>

<script>
(function () {
    /*
     * Hazir seri secilince alanlari doldur. Betik yoksa sunucu tarafi
     * ayni isi yapiyor (bos kod ve baslik hazir seriden doluyor); bu
     * yalnizca yoneticinin secimini aninda gormesi icin.
     */
    var hazir = document.getElementById('hazir');

    if (!hazir) { return; }

    hazir.addEventListener('change', function () {
        var o = hazir.options[hazir.selectedIndex];

        if (!o || !o.value) { return; }

        document.getElementById('seri_kodu').value = o.value;
        document.getElementById('baslik').value    = o.getAttribute('data-ad');
        document.getElementById('birim').value     = o.getAttribute('data-birim');
        document.getElementById('donusum').value   = o.getAttribute('data-donusum');
        document.getElementById('tur').value       = o.getAttribute('data-tur');
        document.getElementById('donem_ay').value  = o.getAttribute('data-donem');
        document.getElementById('kaynak_adi').value = o.getAttribute('data-kaynak');
    });
})();
</script>

<?php require __DIR__ . '/alt.php'; ?>
