<?php
declare(strict_types=1);

/**
 * Kanun metni bağlantılarının tanı ekranı.
 *
 * Neden panelde: mevzuat.gov.tr'ye ne gelistirme ortamindan ne de
 * GitHub'dan cikis var (ajan uzerinden yapilan kontrol butun adreslere
 * HTTP 0 dondurdu). Kaynagi gercekten deneyebilen tek yer sitenin
 * kendi sunucusu; bu yuzden tani oraya, panele kondu.
 *
 * Sayfa siteye hicbir sey yazmaz, yalnizca dener ve sonucu gosterir —
 * tek yan etkisi kararin onbellegini tazelemesi.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/kanunlar.php';
require_once __DIR__ . '/../includes/kanun_metni.php';
require_once __DIR__ . '/../includes/sertifika_onar.php';

giris_zorunlu();

$panelBasligi = 'Kanun metinleri';
$kanunlar     = kanun_listesi();
$bildirim     = '';
$hata         = '';

/*
 * Yedek kaynak kaydi.
 *
 * mevzuat.gov.tr'ye erisim sunucudan sunucuya degisiyor; kapandiginda
 * metni gosterebilmek icin baska bir adres gerekiyor. Hangi kaynagin
 * acik oldugu ancak sunucunun kendisinden denenerek anlasildigi icin
 * adres kodda sabit degil, buradan giriliyor ve butun resmi adaylardan
 * once deneniyor.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'ca_guncelle' || $islem === 'zincir_onar') {
        $sonuc = $islem === 'ca_guncelle'
            ? ca_paketi_guncelle()
            : sertifika_zinciri_onar(kanun_metin_adaylari($kanunlar[0])[0]['url']);

        $bildirim = $sonuc['tamam'] ? $sonuc['mesaj'] : '';
        $hata     = $sonuc['tamam'] ? '' : $sonuc['mesaj'];

        // Kararlar eski listeye gore verilmisti; yeniden denensin.
        if ($sonuc['tamam']) {
            foreach ($kanunlar as $k) {
                ayar_sil('kanun_gosterim_' . (int) $k['no']);
            }
        }
    }

    $no  = (int) ($_POST['no'] ?? 0);
    $url = trim((string) ($_POST['yedek'] ?? ''));

    if ($islem === 'ca_guncelle' || $islem === 'zincir_onar') {
        // Yukarida islendi.
    } elseif (kanun_bul((string) $no) === null) {
        $hata = 'Kanun bulunamadı.';
    } elseif ($url !== '' && guvenli_url($url) === '') {
        $hata = 'Adres http:// veya https:// ile başlamalı.';
    } else {
        kanun_yedek_yaz($no, $url);

        // Karar onbellegi eski adrese gore verilmisti; temizlenmezse
        // yeni kaynak alti saat boyunca denenmezdi.
        ayar_sil('kanun_gosterim_' . $no);

        $bildirim = $url === ''
            ? 'Yedek kaynak kaldırıldı.'
            : 'Yedek kaynak kaydedildi. Sınayarak çalıştığını görebilirsiniz.';
    }
}

$secilen = trim((string) ($_GET['k'] ?? ''));
$tumu    = isset($_GET['tumu']);

/*
 * Toplam sureye sinir.
 *
 * Kaynak erisime kapaliysa her kanun kendi zaman asimini bekler ve
 * "tumunu sina" dakikalarca surer; sunucu istegi ortasinda keser,
 * ekranda da hicbir sey kalmaz. Butce dolunca kalanlar denenmemis
 * olarak isaretleniyor ve eldeki sonuc gosteriliyor.
 */
const PANEL_TANI_BUTCESI = 60;

/** @var list<array{kanun:array,sonuc:array|null}> */
$sonuclar  = [];
$baslangic = microtime(true);

foreach ($kanunlar as $kanun) {
    $bu = $tumu || kanun_anahtari($kanun) === $secilen;

    if (!$bu) {
        $sonuclar[] = ['kanun' => $kanun, 'sonuc' => null];
        continue;
    }

    if (microtime(true) - $baslangic > PANEL_TANI_BUTCESI) {
        $sonuclar[] = ['kanun' => $kanun, 'sonuc' => null];
        continue;
    }

    // Onbellegi atla: tani her zaman kaynaga gitsin.
    $sonuclar[] = ['kanun' => $kanun, 'sonuc' => kanun_gosterim_guvenli($kanun, false)];
}

require __DIR__ . '/ust.php';
?>

<h1>Kanun metinleri</h1>

<?php if ($bildirim !== ''): ?>
    <div class="uyari"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata"><?= e($hata) ?></div>
<?php endif; ?>

<p class="ipucu">
    Kanun sayfaları metni <strong>mevzuat.gov.tr</strong>'den anlık olarak
    çeker; kopya tutulmaz. Metin gelmiyorsa sebebini burada görebilirsiniz.
    Her kanun için adresler sırayla denenir ve ilk tutan kullanılır.
</p>

<p class="ipucu">
    <strong>Yedek kaynak.</strong> Resmî adresler çalışmıyorsa satırdaki
    kutuya başka bir adres girebilirsiniz — kanun metnini yayımlayan
    herhangi bir sayfa ya da doğrudan bir PDF/DOC dosyası olur. Girilen
    adres bütün resmî adaylardan <em>önce</em> denenir. Uzantısı
    <code>.pdf</code> ise tarayıcının görüntüleyicisinde açılır, değilse
    sayfadan metin ayıklanır. Kaydettikten sonra <strong>Sına</strong>
    ile çalıştığını görün.
</p>

<div class="kutu" style="margin-bottom:18px;">
    <h2 style="margin-top:0;font-size:1.02rem;">Kök sertifika listesi</h2>

    <p class="ipucu"><?= e(ca_paketi_durumu()) ?></p>

    <p class="ipucu">
        <strong>mevzuat.gov.tr'deki sorun bu listede değil.</strong>
        Tanı şunu gösterdi: sunucu TLS el sıkışmasında yalnızca kendi
        sertifikasını gönderiyor, onu imzalayan <em>ara</em> sertifikayı
        göndermiyor (<code>*.tccb.gov.tr ← GeoTrust TLS RSA CA G1</code>,
        curl 60). Ara sertifikayı imzalayan kök — DigiCert Global Root G2 —
        listemizde zaten var; eksik olan zincirin ortası. Tarayıcılar bu
        boşluğu sertifikanın içindeki adresten indirip kendileri kapatır,
        curl kapatmaz.
    </p>

    <p class="ipucu">
        Aşağıdaki düğme aynı işi yapar: eksik ara sertifikayı indirir,
        <strong>imzasının güvendiğimiz bir kök tarafından atıldığını
        doğrular</strong> ve ancak öyle listeye ekler. Doğrulama geçmezse
        hiçbir şey yazılmaz — güvenlik doğrulaması hiçbir aşamada
        kapatılmıyor.
    </p>

    <form method="post" style="display:inline;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="zincir_onar">
        <button class="dugme dugme-ana" type="submit">
            Eksik ara sertifikayı indir ve doğrula
        </button>
    </form>

    <form method="post" style="display:inline;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="ca_guncelle">
        <button class="dugme" type="submit">Kök listesini tazele</button>
    </form>

    <p class="ipucu" style="margin-bottom:0;">
        Kök listesini tazelemek ayrı bir iştir: başka kaynaklarda görülen
        sertifika hatalarını giderir, mevzuat.gov.tr'deki eksik halkayı
        gidermez.
    </p>
</div>

<p>
    <a class="dugme dugme-ana" href="?tumu=1">Tümünü sına</a>
    <?php if ($tumu || $secilen !== ''): ?>
        <a class="dugme" href="kanunlar.php">Listeye dön</a>
    <?php endif; ?>
</p>

<table class="liste-tablo">
    <thead>
        <tr>
            <th>Kanun</th>
            <th>Durum</th>
            <th>Denemeler</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sonuclar as $satir): ?>
        <?php
        $kanun = $satir['kanun'];
        $sonuc = $satir['sonuc'];
        ?>
        <tr>
            <td>
                <strong><?= e($kanun['ad']) ?></strong><br>
                <small><?= (int) $kanun['no'] ?> sayılı · <?= (int) $kanun['tertip'] ?>. tertip</small>
            </td>
            <td>
                <?php if ($sonuc === null): ?>
                    <span class="rozet rozet-skor">sınanmadı</span>
                <?php elseif ($sonuc['tur'] === 'yok'): ?>
                    <span class="rozet rozet-reddedildi">gelmiyor</span>
                <?php else: ?>
                    <span class="rozet rozet-yayinda"><?= e($sonuc['tur']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($sonuc === null): ?>
                    <small>—</small>
                <?php else: ?>
                    <ul class="tani-liste">
                    <?php foreach ($sonuc['denemeler'] as $deneme): ?>
                        <li>
                            <strong><?= e($deneme['ad']) ?></strong> —
                            <?= $deneme['kod'] > 0 ? 'HTTP ' . (int) $deneme['kod'] . ' — ' : '' ?>
                            <?= e($deneme['sonuc']) ?>
                            <br><small><?= e($deneme['url']) ?></small>
                            <?php if ($deneme['ayrinti'] !== ''): ?>
                                <br><small><?= e($deneme['ayrinti']) ?></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </td>
            <td class="sag">
                <a class="dugme" href="?k=<?= e(kanun_anahtari($kanun)) ?>">Sına</a>
                <a class="dugme" href="<?= e(kanun_yolu(kanun_anahtari($kanun))) ?>"
                   target="_blank" rel="noopener">Sayfayı aç</a>

                <form method="post" style="margin-top:8px;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="no" value="<?= (int) $kanun['no'] ?>">
                    <input type="url" name="yedek" placeholder="Yedek kaynak adresi"
                           style="width:230px;"
                           value="<?= e(kanun_yedek_oku((int) $kanun['no'])) ?>">
                    <button class="dugme" type="submit">Kaydet</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p class="ipucu">
    <strong>Sonuç nasıl okunur.</strong>
    “Bağlantı kurulamadı” ya da “Süre doldu”: sunucumuzdan
    mevzuat.gov.tr'ye çıkış yok — hosting firması dış bağlantıları
    kapatmış ya da kaynak sunucumuzun IP'sini engelliyor olabilir.
    “HTTP 403”: kaynak isteği görüyor ama reddediyor.
    “Yanıt PDF değil”: adres var ama hata sayfası dönüyor; tertip
    numarası yanlış olabilir.
    “Güvenlik sertifikası doğrulanamadı”: kök sertifika listesi eski.
</p>

<?php require __DIR__ . '/alt.php'; ?>
