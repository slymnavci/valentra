<?php
declare(strict_types=1);

/**
 * Gunluk e-posta bulteni sayfasi (03.11.2026; bkz. includes/bulten.php).
 *
 *   GET  /bulten.php               abonelik kutusu
 *   POST /bulten.php  eposta       abone ol (onay e-postasi gider)
 *   GET  /bulten.php?onay=ANAHTAR  aboneligi onayla
 *   GET  /bulten.php?iptal=ANAHTAR "Abonelikten cik" dugmesi (baglanti
 *                                  tarayicilari tek basina cikarmasin)
 *   POST /bulten.php?iptal=ANAHTAR abonelikten cik (e-posta istemcisinin
 *                                  tek tik istegi de buraya: RFC 8058)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bulten.php';
require_once __DIR__ . '/includes/kurumsal.php';

header('Cache-Control: no-store');

$onay  = (string) ($_GET['onay'] ?? '');
$iptal = (string) ($_GET['iptal'] ?? '');
$post  = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$durum = '';

if ($iptal !== '' && $post) {
    $durum = bulten_ayril($iptal) ? 'ayrildi' : 'bulunamadi';

    // E-posta istemcisinin tek tik istegi: sayfa degil, yalnizca yanit.
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        http_response_code($durum === 'ayrildi' ? 200 : 404);
        exit;
    }
} elseif ($iptal !== '') {
    $durum = bulten_abone_anahtarla($iptal) !== null ? 'iptal_sor' : 'bulunamadi';
} elseif ($onay !== '') {
    $durum = bulten_onayla($onay) ? 'onaylandi' : 'bulunamadi';
} elseif ($post) {
    if (trim((string) ($_POST['web'] ?? '')) !== '') {
        $durum = 'gonderildi'; // tuzak alan doluysa bot: kayit yok, ayni yanit
    } else {
        require_once __DIR__ . '/includes/ziyaret.php';
        $sonuc = bulten_abone_ol((string) ($_POST['eposta'] ?? ''), ziyaret_adresi());
        $durum = match ($sonuc) {
            'gecersiz' => 'gecersiz',
            'cok'      => 'cok',
            default    => 'gonderildi',
        };
    }
}

$sayfaBasligi  = 'Günlük e-posta bülteni — Valentra';
$sayfaAciklama = 'Valentra günlük bülteni: günün en önemli haberleri her sabah e-postanızda.';

if ($onay !== '' || $iptal !== '' || $post) {
    $seoRobots = 'noindex, nofollow';
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<section class="bulten-sayfa">
    <?php if ($durum === 'gonderildi'): ?>
        <div class="bulten-sonuc">
            <h1>E-postanızı kontrol edin</h1>
            <p>Abonelik onayı için bir e-posta gönderdik. İçindeki <strong>“Aboneliği onayla”</strong> düğmesine basınca bülten
               her sabah gelmeye başlar. Birkaç dakika içinde gelmezse istenmeyen (spam) klasörüne bakın.</p>
            <p><a href="/">Ana sayfaya dön</a></p>
        </div>
    <?php elseif ($durum === 'onaylandi'): ?>
        <div class="bulten-sonuc">
            <h1>Aboneliğiniz onaylandı 🎉</h1>
            <p>Günün özeti her sabah saat <?= str_pad((string) BULTEN_SAAT, 2, '0', STR_PAD_LEFT) ?>:00 sularında e-postanızda olacak.
               Her e-postanın altında tek tıkla abonelikten çıkabilirsiniz.</p>
            <p><a href="/">Haberlere göz atın</a></p>
        </div>
    <?php elseif ($durum === 'iptal_sor'): ?>
        <div class="bulten-sonuc">
            <h1>Bültenden çıkmak istiyor musunuz?</h1>
            <p>Valentra günlük bülteni artık gönderilmez.</p>
            <form method="post" action="<?= e(bulten_yolu('iptal', $iptal)) ?>">
                <button type="submit" class="bulten-cik">Abonelikten çık</button>
            </form>
        </div>
    <?php elseif ($durum === 'ayrildi'): ?>
        <div class="bulten-sonuc">
            <h1>Abonelikten çıktınız</h1>
            <p>Artık bülten almayacaksınız. Fikrinizi değiştirirseniz aşağıdan yeniden abone olabilirsiniz.</p>
        </div>
        <?= bulten_kutu_html('sayfa') ?>
    <?php elseif ($durum === 'bulunamadi'): ?>
        <div class="bulten-sonuc">
            <h1>Bağlantı geçersiz</h1>
            <p>Bu bağlantı geçersiz ya da süresi dolmuş. Aşağıdan yeniden abone olabilirsiniz.</p>
        </div>
        <?= bulten_kutu_html('sayfa') ?>
    <?php else: ?>
        <h1>Günlük e-posta bülteni</h1>
        <?php if ($durum === 'gecersiz'): ?>
            <p class="uyari uyari-hata">Geçerli bir e-posta adresi yazın.</p>
        <?php elseif ($durum === 'cok'): ?>
            <p class="uyari uyari-hata">Bu bağlantıdan çok fazla abonelik isteği geldi; bir saat sonra yeniden deneyin.</p>
        <?php endif; ?>
        <p class="spot"><strong>Her sabah 5 dakikada gündem ve cebinizi etkileyen gelişmeler.</strong>
           Günün en önemli haberleri; zam, kira, akaryakıt, faiz ve enflasyondaki değişiklikler, köşe yazısı ve varsa canlı anlatım; her sabah tek bir e-postada.
           Ücretsizdir, reklam içermez, istediğiniz an tek tıkla çıkabilirsiniz.</p>
        <?= bulten_kutu_html('sayfa') ?>
        <p class="ipucu">E-posta adresiniz yalnızca bülten için kullanılır, kimseyle paylaşılmaz.
           Ayrıntılar: <a href="<?= e(kurumsal_yolu('gizlilik')) ?>">Gizlilik ve çerezler</a>.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
