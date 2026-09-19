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

giris_zorunlu();

$panelBasligi = 'Kanun metinleri';
$kanunlar     = kanun_listesi();

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
    $sonuclar[] = ['kanun' => $kanun, 'sonuc' => kanun_gosterim($kanun, false)];
}

require __DIR__ . '/ust.php';
?>

<h1>Kanun metinleri</h1>

<p class="ipucu">
    Kanun sayfaları metni <strong>mevzuat.gov.tr</strong>'den anlık olarak
    çeker; kopya tutulmaz. Metin gelmiyorsa sebebini burada görebilirsiniz.
    Her kanun için adresler sırayla denenir ve ilk tutan kullanılır.
</p>

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
                <a class="dugme" href="/kanun.php?k=<?= e(kanun_anahtari($kanun)) ?>"
                   target="_blank" rel="noopener">Sayfayı aç</a>
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
