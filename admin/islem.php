<?php
declare(strict_types=1);

/**
 * Panel islemleri (POST). Her istek CSRF jetonu ile dogrulanir.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

giris_zorunlu();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Yalnızca POST.');
}

csrf_dogrula($_POST['csrf'] ?? null);

$id    = (int) ($_POST['id'] ?? 0);
$islem = (string) ($_POST['islem'] ?? '');
$donus = (string) ($_POST['donus'] ?? '');

if ($id <= 0 || haber_bul($id) === null) {
    http_response_code(404);
    exit('Haber bulunamadı.');
}

switch ($islem) {
    case 'onayla':
        haber_onayla($id, aktif_yonetici_id());
        $bildirim = 'onaylandi';
        break;

    case 'reddet':
        haber_durum_degistir($id, HABER_REDDEDILDI);
        $bildirim = 'reddedildi';
        break;

    case 'geri_al':
        haber_durum_degistir($id, HABER_TASLAK);
        $bildirim = 'geri';
        break;

    case 'one_cikar':
        haber_one_cikar($id, true);
        $bildirim = 'one_cikti';
        break;

    case 'one_cikarma':
        haber_one_cikar($id, false);
        $bildirim = 'one_kalkti';
        break;

    case 'sil':
        haber_sil($id);
        $bildirim = 'silindi';
        break;

    default:
        http_response_code(400);
        exit('Bilinmeyen işlem.');
}

if ($donus === 'duzenle' && $islem !== 'sil') {
    yonlendir('duzenle.php?id=' . $id . '&bildirim=' . $bildirim);
}

// Öne çıkarma durumu değiştirmez; kullanıcı baktığı sekmede kalsın.
if ($islem === 'one_cikar' || $islem === 'one_cikarma') {
    $haber = haber_bul($id);
    yonlendir('index.php?durum=' . rawurlencode((string) ($haber['durum'] ?? HABER_TASLAK)) . '&bildirim=' . $bildirim);
}

$durumSekmesi = match ($islem) {
    'onayla'  => HABER_YAYINDA,
    'reddet'  => HABER_REDDEDILDI,
    default   => HABER_TASLAK,
};

yonlendir('index.php?durum=' . $durumSekmesi . '&bildirim=' . $bildirim);
