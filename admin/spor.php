<?php
declare(strict_types=1);

/**
 * Spor verisi: API-Football anahtarı ve hesap durumu.
 *
 * Anahtar girilince spor sayfalari dunyanin butun liglerini, kulup ve mac
 * ayrintilarini gosteriyor (includes/futbol.php). Girilmemisse TFF'den
 * yalnizca Super Lig. Anahtar ortam degiskeni degil, ayar olarak
 * saklaniyor: girecek kisi GitHub ayarlarina girmiyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/futbol.php';

giris_zorunlu();

$panelBasligi = 'Spor verisi';
$bildirim     = '';
$hata         = '';
$durum        = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');

    if ($islem === 'kaydet') {
        ayar_yaz(FUTBOL_API_ANAHTAR, trim((string) ($_POST['anahtar'] ?? '')));
        $bildirim = futbol_api_acik()
            ? 'Anahtar kaydedildi. Aşağıdaki "Bağlantıyı dene" ile doğrulayın.'
            : 'Anahtar silindi; spor sayfaları TFF\'den Süper Lig\'i gösterecek.';
    } elseif ($islem === 'dene') {
        $durum = futbol_hesap_durumu();

        if ($durum === null) {
            $hata = 'Bağlantı kurulamadı: ' . (ayar_oku('futbol_son_hata') ?: 'anahtar girilmemiş.');
        }
    }
}

$anahtarVar = futbol_api_acik();
$sonHata    = ayar_oku('futbol_son_hata');
[$sayacSaat, $sayac] = array_pad(explode(':', ayar_oku('futbol_saatlik_sayac')), 2, '0');
$buSaat     = $sayacSaat === date('YmdH') ? (int) $sayac : 0;

require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:14px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Futbol veri servisi (API-Football)</h2>
    <p class="ipucu" style="margin:0 0 10px;">
        Anahtar girilince Spor sayfaları dünyanın bütün liglerinin puan durumunu,
        fikstürünü, canlı skorlarını ve gol krallığını; kulüplere tıklanınca
        sezon analizini, son maçları ve gollerini; maçlara tıklanınca gol,
        kart, istatistik ve kadroları gösterir. Anahtar yokken yalnızca
        Süper Lig, TFF'den.
    </p>
    <p class="ipucu" style="margin:0 0 14px;">
        Anahtar için <a href="https://dashboard.api-football.com/register" target="_blank" rel="noopener">api-football.com</a>
        adresinden üye olun; giriş yaptıktan sonra panodaki <em>Account &rarr; My Access</em>
        bölümündeki anahtarı kopyalayıp buraya yapıştırın. Ücretsiz plan günde 100
        istekle sınırlı; canlı skor ve kulüp sayfaları için ücretli plan gerekir.
    </p>

    <form method="post" style="margin-bottom:12px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="kaydet">

        <div class="alan">
            <label for="anahtar">API anahtarı</label>
            <input type="text" id="anahtar" name="anahtar" autocomplete="off"
                   value="<?= e(ayar_oku(FUTBOL_API_ANAHTAR)) ?>"
                   placeholder="api-football.com'dan aldığınız anahtar">
        </div>

        <button type="submit" class="dugme dugme-ana">Kaydet</button>
    </form>

    <?php if ($anahtarVar): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="dene">
            <button type="submit" class="dugme">Bağlantıyı dene</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($durum !== null): ?>
    <div class="kutu" style="margin-top:18px;">
        <h2 style="margin:0 0 10px;font-size:1.05rem;">Hesap</h2>
        <table class="liste-tablo">
            <tbody>
                <tr><td>Plan</td><td><strong><?= e($durum['plan']) ?></strong><?= $durum['aktif'] ? '' : ' (etkin değil)' ?></td></tr>
                <tr><td>Bitiş</td><td><?= e($durum['bitis']) ?></td></tr>
                <tr><td>Bugünkü istek</td><td><?= $durum['istek'] ?> / <?= $durum['sinir'] ?></td></tr>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 10px;font-size:1.05rem;">Kullanım</h2>
    <table class="liste-tablo">
        <tbody>
            <tr><td>Bu saatteki istek</td><td><?= $buSaat ?> / <?= FUTBOL_SAATLIK_SINIR ?> (site tarafındaki koruma sınırı)</td></tr>
            <tr><td>Son hata</td><td><?= $sonHata !== '' ? e($sonHata) : 'Yok' ?></td></tr>
        </tbody>
    </table>
    <p class="ipucu" style="margin:10px 0 0;">
        Veriler önbellekte tutulur (canlı skorlar 2 dakika, puan durumu 10 dakika,
        kulüp istatistiği 6 saat); ziyaretçi sayısı istek sayısını artırmaz.
    </p>
</div>

<?php require __DIR__ . '/alt.php'; ?>
