<?php
declare(strict_types=1);

/**
 * Spor verisi: ESPN kaynağının durumu, açma/kapama ve bağlantı denemesi.
 *
 * Spor sayfalari dunyanin liglerini, kulup ve mac ayrintilarini ESPN'in
 * herkese acik uclarindan aliyor (includes/futbol.php). Uclar belgesiz;
 * ESPN degistirir ya da erisimi kapatirsa buradan kapatilir ve spor
 * sayfalari TFF'den Super Lig'e doner.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/futbol.php';

giris_zorunlu();

$panelBasligi = 'Spor verisi';
$bildirim     = '';
$hata         = '';
$deneme       = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'ac') {
        ayar_yaz(FUTBOL_KAPALI, '0');
        $bildirim = 'ESPN açıldı; spor sayfaları dünya liglerini gösterecek.';
    } elseif ($islem === 'kapat') {
        ayar_yaz(FUTBOL_KAPALI, '1');
        $bildirim = 'ESPN kapatıldı; spor sayfaları TFF\'den Süper Lig\'i gösterecek.';
    } elseif ($islem === 'dene') {
        // Onbellegi atlayip dogrudan: ESPN su an cevap veriyor mu?
        $cevap  = futbol_istek('/v2/sports/soccer/' . FUTBOL_VARSAYILAN_LIG . '/standings');
        $takim  = count($cevap['children'][0]['standings']['entries'] ?? []);
        $deneme = $takim > 0;

        if ($deneme) {
            $bildirim = 'ESPN cevap veriyor: Süper Lig puan durumunda ' . $takim . ' takım okundu.';
        } else {
            $hata = 'ESPN\'den veri alınamadı: ' . (ayar_oku('futbol_son_hata') ?: 'nedeni bilinmiyor.');
        }
    }
}

$acik    = futbol_api_acik();
$sonHata = ayar_oku('futbol_son_hata');
[$sayacSaat, $sayac] = array_pad(explode(':', ayar_oku('futbol_saatlik_sayac')), 2, '0');
$buSaat  = $sayacSaat === date('YmdH') ? (int) $sayac : 0;

require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:14px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Futbol verisi: ESPN</h2>
    <p class="ipucu" style="margin:0 0 10px;">
        Spor sayfaları dünyanın liglerinin puan durumunu, fikstürünü, canlı skorlarını
        ve gol krallığını; kulüplere tıklanınca sezon analizini, son maçları ve
        gollerini; maçlara tıklanınca gol, kart ve istatistikleri ESPN'den alır.
        Anahtar ve ücret gerekmez.
    </p>
    <p class="ipucu" style="margin:0 0 14px;">
        ESPN'in bu verileri resmî bir hizmet olarak sunmadığını unutmayın: adresler
        haber verilmeden değişebilir ya da kapanabilir. Spor sayfaları boş kalırsa
        aşağıdan bağlantıyı deneyin; ESPN cevap vermiyorsa kapatın, sayfalar
        TFF'den Süper Lig'e döner.
    </p>

    <p style="margin:0 0 12px;">Durum: <strong><?= $acik ? 'Açık' : 'Kapalı (TFF\'den Süper Lig)' ?></strong></p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="<?= $acik ? 'kapat' : 'ac' ?>">
            <button type="submit" class="dugme <?= $acik ? '' : 'dugme-ana' ?>"><?= $acik ? 'ESPN\'i kapat' : 'ESPN\'i aç' ?></button>
        </form>

        <?php if ($acik): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="islem" value="dene">
                <button type="submit" class="dugme dugme-ana">Bağlantıyı dene</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 10px;font-size:1.05rem;">Kullanım</h2>
    <table class="liste-tablo">
        <tbody>
            <tr><td>Bu saatteki istek</td><td><?= $buSaat ?> / <?= FUTBOL_SAATLIK_SINIR ?> (sitenin koyduğu sınır)</td></tr>
            <tr><td>Son hata</td><td><?= $sonHata !== '' ? e($sonHata) : 'Yok' ?></td></tr>
        </tbody>
    </table>
    <p class="ipucu" style="margin:10px 0 0;">
        Veriler önbellekte tutulur: canlı ve bugünkü maçlar 2 dakika, puan durumu
        10 dakika, biten maçlar bir hafta. Ziyaretçi sayısı ESPN'e giden istek
        sayısını artırmaz. Seçicideki ligler <code>includes/futbol.php</code>
        içindeki listeden gelir.
    </p>
</div>

<?php require __DIR__ . '/alt.php'; ?>
