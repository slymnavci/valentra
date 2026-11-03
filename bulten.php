<?php
declare(strict_types=1);

/**
 * Günlük bülten: abone ol, onayla, ayrıl (03.11.2026; bkz. includes/bulten.php).
 *
 *   POST eposta=...        abonelik istegi (onay e-postasi gider)
 *   GET  ?onay=JETON       aboneligi baslatir
 *   GET|POST ?ayril=JETON  ayrilma (POST: e-posta istemcisinin tek tik ayrilmasi)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bulten.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$seoRobots    = 'noindex, follow';
$sayfaBasligi = 'Valentra bülteni';
$mesaj        = null;
$basarili     = false;

if (!bulten_hazir()) {
    $mesaj = 'Bülten birkaç dakika içinde açılacak; lütfen biraz sonra yeniden deneyin.';
} elseif (isset($_GET['ayril'])) {
    $basarili = bulten_ayril((string) $_GET['ayril']);

    // E-posta istemcisinin tek tik ayrilma istegi: sayfa gerekmiyor.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code($basarili ? 200 : 404);
        exit;
    }

    $mesaj = $basarili
        ? 'Bültenden ayrıldınız. Size bir daha bülten gönderilmeyecek.'
        : 'Bu bağlantı geçersiz ya da süresi dolmuş.';
} elseif (isset($_GET['onay'])) {
    $basarili = bulten_onayla((string) $_GET['onay']);
    $mesaj = $basarili
        ? 'Aboneliğiniz başladı. İlk bülteniniz yarın sabah e-postanızda olacak.'
        : 'Bu onay bağlantısı geçersiz ya da süresi dolmuş. Sayfanın altındaki kutudan yeniden abone olabilirsiniz.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bot tuzagi: gorunmeyen alan doluysa sessizce "gonderildi".
    $tuzak = trim((string) ($_POST['web'] ?? '')) !== '';

    // Saatte en fazla 60 yeni istek (onay e-postasi kotuye kullanilmasin).
    $sonSaat = (int) db()->query(
        "SELECT COUNT(*) FROM bulten_aboneler WHERE durum = 'bekliyor' AND olusturuldu >= NOW() - INTERVAL 1 HOUR"
    )->fetchColumn();

    $sonuc = $tuzak ? 'gonderildi' : ($sonSaat >= 60 ? 'yogun' : bulten_abone_ol((string) ($_POST['eposta'] ?? '')));
    $basarili = in_array($sonuc, ['gonderildi', 'zaten'], true);
    $mesaj = match ($sonuc) {
        'gonderildi' => 'Neredeyse tamam: e-posta adresinize bir onay bağlantısı gönderdik. Bağlantıyı açtığınızda aboneliğiniz başlar. '
            . 'E-postayı göremiyorsanız gereksiz (spam) klasörüne bakın.',
        'zaten'      => 'Bu adres zaten bültene abone.',
        'gecersiz'   => 'E-posta adresi geçerli görünmüyor; lütfen kontrol edip yeniden deneyin.',
        'yogun'      => 'Şu anda çok fazla istek var; lütfen biraz sonra yeniden deneyin.',
        default      => 'Onay e-postası şu anda gönderilemedi; lütfen biraz sonra yeniden deneyin.',
    };
} else {
    $mesaj = null;
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Valentra bülteni</h1>
    <p>Her sabah günün en önemli haberleri e-postanızda.</p>
</div>

<div class="ana-duzen tam">
    <div class="ana-kolon">
        <?php if ($mesaj !== null): ?>
            <div class="bos-durum" style="text-align:left;">
                <strong><?= $basarili ? 'Teşekkürler' : 'Bir sorun var' ?></strong>
                <p><?= e($mesaj) ?></p>
                <p><a href="/" style="color:var(--vurgu);text-decoration:underline;">Ana sayfaya dön</a></p>
            </div>
        <?php endif; ?>

        <?php if ($mesaj === null || !$basarili): ?>
            <?= bulten_kutu_html() ?>
            <p class="ipucu">E-posta adresiniz yalnızca bülten için kullanılır ve kimseyle paylaşılmaz. Her bültendeki bağlantıyla tek tıkla ayrılabilirsiniz.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
