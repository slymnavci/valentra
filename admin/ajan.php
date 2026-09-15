<?php
declare(strict_types=1);

/**
 * Ajanı panelden çalıştırma ekranı.
 *
 * Ajan GitHub Actions üzerinde çalışır; buradaki düğme yalnızca "çalış"
 * komutunu gönderir. Gemini anahtarı GitHub'da kalır, hosting'e taşınmaz.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ayarlar.php';
require_once __DIR__ . '/../includes/ajan_tetikle.php';

giris_zorunlu();

$bildirim = '';
$hata     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'ayar') {
        $yeniAnahtar = trim((string) ($_POST['github_anahtar'] ?? ''));
        $depo        = trim((string) ($_POST['github_depo'] ?? ''));

        // Bos birakildiysa mevcut anahtar korunur: kullanici yalnizca
        // depo adini degistirmek isteyebilir.
        if ($yeniAnahtar !== '') {
            ayar_yaz(AJAN_GITHUB_ANAHTAR, $yeniAnahtar);
        }

        if ($depo !== '') {
            ayar_yaz(AJAN_GITHUB_DEPO, $depo);
        }

        $bildirim = 'Ayarlar kaydedildi.';

    } elseif ($islem === 'anahtar_sil') {
        ayar_sil(AJAN_GITHUB_ANAHTAR);
        $bildirim = 'GitHub anahtarı silindi.';

    } elseif ($islem === 'calistir' || $islem === 'calistir_kuru' || $islem === 'kaynak_testi') {
        // Suren bir calisma varken yenisini gondermek ise yaramaz:
        // GitHub ayni eszamanlilik grubunda sirada tek bir calisma
        // tutuyor, yeni istek bekleyeni IPTAL ediyor. Dugmeye art arda
        // basildiginda ikisi de "cancelled" olup hicbir haber gelmiyordu.
        $suren = ajan_son_calisma();

        if ($suren['var'] && $suren['durum'] !== 'completed') {
            $hata = 'Zaten bir çalışma sürüyor. Bitmesini bekleyin; '
                  . 'şimdi yeni istek göndermek sürdekini iptal eder.';
        } else {
            $sonuc = ajan_tetikle(
                $islem === 'calistir_kuru',
                max(1, (int) ($_POST['saat'] ?? 36)),
                max(1, (int) ($_POST['enfazla'] ?? 25)),
                $islem === 'kaynak_testi' ? 'kaynak-testi' : 'topla',
            );

            if ($sonuc['tamam']) {
                $bildirim = $sonuc['mesaj'];
            } else {
                $hata = $sonuc['mesaj'];
            }
        }
    }
}

$anahtarVar = ajan_tetikleyebilir_mi();
$mevcutAnahtar = ayar_oku(AJAN_GITHUB_ANAHTAR);
$sonCalisma = $anahtarVar ? ajan_son_calisma() : ['var' => false];

$bekleyen = haber_durum_sayilari()[HABER_TASLAK] ?? 0;
$sonKayit = db()->query('SELECT * FROM ajan_kayitlari ORDER BY baslangic DESC LIMIT 5')->fetchAll();

$panelBasligi = 'Ajan';
require __DIR__ . '/ust.php';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:14px;"><?= e($hata) ?></div>
<?php endif; ?>

<!-- Calistirma -->
<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Haberleri topla</h2>
    <p class="ipucu" style="margin:0 0 16px;">
        Tüm aktif kaynaklar taranır, vergi haberleri ayıklanır ve yazılıp
        <strong>onay bekleyen</strong> listesine eklenir. Hiçbir haber
        doğrudan yayımlanmaz.
    </p>

    <?php if (!$anahtarVar): ?>
        <div class="uyari uyari-bilgi" style="margin:0;">
            Çalıştırmak için önce aşağıdan GitHub erişim anahtarını girin.
        </div>
    <?php else: ?>
        <form method="post" action="ajan.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">

            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
                <div class="alan" style="margin-bottom:0;">
                    <label for="saat">Kaç saat geriye bakılsın</label>
                    <input type="text" id="saat" name="saat" value="36" style="width:110px;">
                </div>

                <div class="alan" style="margin-bottom:0;">
                    <label for="enfazla">En fazla kaç aday</label>
                    <input type="text" id="enfazla" name="enfazla" value="25" style="width:110px;">
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" name="islem" value="calistir"
                        class="dugme dugme-ana">Ajanı şimdi çalıştır</button>

                <button type="submit" name="islem" value="calistir_kuru"
                        class="dugme">Kuru çalıştır (kaydetmeden dene)</button>

                <button type="submit" name="islem" value="kaynak_testi"
                        class="dugme">Kaynakları sına</button>
            </div>

            <p class="ipucu" style="margin:14px 0 0;">
                Kuru çalışmada haberler kaydedilmez, yalnızca GitHub
                kayıtlarında görünür. Metin kalitesini denemek için kullanışlı.
            </p>

            <p class="ipucu" style="margin:8px 0 0;">
                <strong>Kaynakları sına:</strong> her kaynağın hem RSS hem
                kazıma yolunu dener ve çalışmayanın nedenini yazar. Model
                çağrısı yapmaz, siteye hiçbir şey yazmaz — ücretsizdir.
                Sonuç GitHub kayıtlarında görünür.
            </p>
        </form>
    <?php endif; ?>
</div>

<!-- Durum -->
<?php if ($sonCalisma['var']): ?>
    <div class="kutu">
        <h2 style="margin:0 0 12px;font-size:1.05rem;">Son çalışma</h2>

        <div class="satir-bilgi">
            <?php
            $durum = $sonCalisma['durum'];
            $sonuc = $sonCalisma['sonuc'];

            [$rozet, $metin] = match (true) {
                $durum !== 'completed' => ['rozet-taslak', 'çalışıyor'],
                $sonuc === 'success'   => ['rozet-yayinda', 'başarılı'],
                default                => ['rozet-reddedildi', $sonuc !== '' ? $sonuc : 'başarısız'],
            };
            ?>
            <span class="rozet <?= $rozet ?>" id="calisma-rozeti"><?= e($metin) ?></span>

            <?php if ($sonCalisma['baslangic'] !== ''): ?>
                <span><?= e(tarih_bicimle($sonCalisma['baslangic'])) ?></span>
            <?php endif; ?>

            <?php if ($sonCalisma['adres'] !== ''): ?>
                <a href="<?= e($sonCalisma['adres']) ?>" target="_blank" rel="noopener">
                    Ayrıntılı kaydı gör
                </a>
            <?php endif; ?>

            <span style="margin-left:auto;">
                Onay bekleyen: <strong id="bekleyen-sayi"><?= (int) $bekleyen ?></strong>
                <?php if ($bekleyen > 0): ?>
                    &nbsp;<a href="index.php?durum=taslak">listeye git</a>
                <?php endif; ?>
            </span>
        </div>

        <?php if ($durum !== 'completed'): ?>
            <p class="ipucu" id="calisma-notu" style="margin:12px 0 0;">
                Çalışma sürüyor. Bittiğinde burada haber verilecek,
                sayfayı yenilemenize gerek yok.
            </p>

            <!--
                Calisma bitince haber ver.
                Onceden "birkac dakika sonra sayfayi yenileyin" deyip
                birakiyorduk; kullanici bittigini ancak elle yenileyerek
                ogreniyordu. Sekme arkadaysa tarayici bildirimi de
                gonderiliyor.
            -->
            <div id="calisma-sonuc" style="display:none;margin-top:12px;"></div>

            <script>
            (function () {
                var not    = document.getElementById('calisma-notu');
                var kutu   = document.getElementById('calisma-sonuc');
                var baslik = document.title;
                var deneme = 0;

                // Bildirim izni yalnizca calisma surerken istenir;
                // sayfa acilir acilmaz sormak rahatsiz edici olurdu.
                if ('Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission();
                }

                function bildir(mesaj) {
                    document.title = '✓ ' + baslik;

                    if ('Notification' in window && Notification.permission === 'granted') {
                        new Notification('Valentra ajanı bitti', { body: mesaj });
                    }
                }

                function bitti(veri) {
                    var basarili = veri.sonuc === 'success';
                    var gonderim = veri.son_gonderim;

                    var mesaj = basarili
                        ? 'Çalışma tamamlandı.'
                        : 'Çalışma ' + (veri.sonuc || 'başarısız') + ' ile bitti.';

                    if (basarili && gonderim) {
                        mesaj += ' ' + gonderim.eklenen + ' yeni taslak eklendi'
                               + (gonderim.yinelenen > 0
                                   ? ', ' + gonderim.yinelenen + ' zaten vardı.'
                                   : '.');
                    }

                    if (not) { not.style.display = 'none'; }

                    // Rozet ve sayac "calisiyor"/"0" olarak kalmasin.
                    var rozet = document.getElementById('calisma-rozeti');

                    if (rozet) {
                        rozet.className = 'rozet ' + (basarili ? 'rozet-yayinda' : 'rozet-reddedildi');
                        rozet.textContent = basarili ? 'başarılı' : (veri.sonuc || 'başarısız');
                    }

                    var sayac = document.getElementById('bekleyen-sayi');

                    if (sayac) { sayac.textContent = veri.bekleyen; }

                    kutu.className = 'uyari uyari-' + (basarili ? 'basari' : 'hata');
                    kutu.style.display = '';
                    kutu.textContent = mesaj + ' ';

                    if (veri.bekleyen > 0) {
                        var bag = document.createElement('a');
                        bag.href = 'index.php?durum=taslak';
                        bag.textContent = 'Onay bekleyen ' + veri.bekleyen + ' haberi gör';
                        kutu.appendChild(bag);
                    }

                    bildir(mesaj);
                }

                function yokla() {
                    fetch('ajan_durum.php', { credentials: 'same-origin' })
                        .then(function (y) { return y.ok ? y.json() : null; })
                        .then(function (veri) {
                            if (!veri) { return; }

                            if (!veri.calisiyor) {
                                bitti(veri);
                                return;
                            }

                            // Calisma uzarsa yoklama araligi aciliyor:
                            // 10 sn ile basla, 60 sn'yi gecme.
                            deneme++;
                            setTimeout(yokla, Math.min(10000 + deneme * 2000, 60000));
                        })
                        .catch(function () {
                            // Gecici ag hatasi yoklamayi bitirmemeli.
                            setTimeout(yokla, 30000);
                        });
                }

                setTimeout(yokla, 10000);
            })();
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Son ajan kayitlari (siteye gelen gonderimler) -->
<?php if ($sonKayit !== []): ?>
    <div class="kutu">
        <h2 style="margin:0 0 12px;font-size:1.05rem;">Siteye gelen son gönderimler</h2>

        <?php foreach ($sonKayit as $kayit): ?>
            <div class="satir-bilgi" style="padding:8px 0;border-bottom:1px solid var(--cizgi);">
                <span><?= e(tarih_bicimle($kayit['baslangic'])) ?></span>
                <span class="rozet <?= $kayit['durum'] === 'hata' ? 'rozet-reddedildi' : 'rozet-yayinda' ?>">
                    <?= e($kayit['durum']) ?>
                </span>
                <span><?= (int) $kayit['eklenen'] ?> yeni</span>
                <span><?= (int) $kayit['yinelenen'] ?> yinelenen</span>
                <?php if ($kayit['mesaj'] !== ''): ?>
                    <span><?= e($kayit['mesaj']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Ayarlar -->
<div class="kutu">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">GitHub bağlantısı</h2>
    <p class="ipucu" style="margin:0 0 16px;">
        Ajan GitHub Actions üzerinde çalışır. Buradaki düğmenin çalışması için
        GitHub'a "çalış" diyebilecek bir erişim anahtarı gerekiyor.
        <br><br>
        <strong>Anahtarı nasıl alırsınız:</strong> GitHub → Settings →
        Developer settings → Personal access tokens → Fine-grained tokens →
        Generate new token. Yalnızca <code>valentra</code> deposunu seçin ve
        <code>Actions: Read and write</code> iznini verin. Başka izne gerek yok.
    </p>

    <form method="post" action="ajan.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="ayar">

        <div class="alan">
            <label for="github_anahtar">GitHub erişim anahtarı</label>
            <input type="password" id="github_anahtar" name="github_anahtar"
                   placeholder="<?= $anahtarVar ? 'Kayıtlı: ' . e(ayar_maskele($mevcutAnahtar)) : 'github_pat_...' ?>"
                   autocomplete="off">
            <div class="ipucu">
                <?= $anahtarVar
                    ? 'Değiştirmek istemiyorsanız boş bırakın.'
                    : 'Anahtar veritabanında saklanır ve ekranda hiçbir zaman açık gösterilmez.' ?>
            </div>
        </div>

        <div class="alan">
            <label for="github_depo">Depo</label>
            <input type="text" id="github_depo" name="github_depo"
                   value="<?= e(ajan_depo()) ?>" placeholder="kullanici/depo">
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="dugme dugme-ana">Kaydet</button>
        </div>
    </form>

    <?php if ($anahtarVar): ?>
        <form method="post" action="ajan.php" style="margin-top:12px;"
              onsubmit="return confirm('GitHub anahtarı silinecek; ajan panelden çalıştırılamaz. Emin misiniz?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="anahtar_sil">
            <button type="submit" class="dugme dugme-ret">Anahtarı sil</button>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/alt.php'; ?>
