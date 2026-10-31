<?php
declare(strict_types=1);

/**
 * Yorum islemleri (Valentra Network, 31.10.2026): ekle, sil (kendi
 * yorumu), sikayet. Yalnizca POST ve giris yapmis uye; form jetonu
 * (includes/uyelik.php) baska sitelerden gonderimi engelliyor.
 *
 * Sonuc ziyaretciye donus adresinde gosteriliyor (?yorum=...): sayfa
 * yeniden yuklendiginde form tekrar gonderilmesin diye yonlendirme.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/yorumlar.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$donus = uye_guvenli_donus((string) ($_POST['donus'] ?? '')) ?: network_yolu();
$uye   = aktif_uye();

if ($uye === null) {
    header('Location: ' . uye_yolu('giris', $donus), true, 303);
    exit;
}

/**
 * Donus adresine sonuc ekler: ?yorum=<kod>&yh=<h12 | g5> (#capa korunuyor).
 * Kod kisa ve bilinen bir anahtar (bkz. yorum_sonuc_mesaji); metin adreste
 * tasinmiyor, yoksa baskasi sahte bir mesajla baglanti hazirlayabilirdi.
 */
function yorum_donus(string $donus, string $kod, string $hedef, string $capa = ''): string
{
    [$yol, $mevcutCapa] = array_pad(explode('#', $donus, 2), 2, '');
    $yol = preg_replace('/([?&])(yorum|yh)=[^&]*/', '$1', $yol) ?? $yol;
    $yol = rtrim(preg_replace('/[?&]{2,}/', '&', $yol) ?? $yol, '?&');

    return $yol . (str_contains($yol, '?') ? '&' : '?') . 'yorum=' . rawurlencode($kod) . '&yh=' . rawurlencode($hedef)
         . ($capa !== '' ? '#' . $capa : ($mevcutCapa !== '' ? '#' . $mevcutCapa : ''));
}

// Yorumun hedefi: "h12" haber, "g5" V Network paylasimi (31.10.2026).
$hedef = (string) ($_POST['hedef'] ?? '');
$tur   = str_starts_with($hedef, 'g') ? 'gonderi' : 'haber';
$hedefId = (int) substr($hedef, 1);
$hedef = ($tur === 'gonderi' ? 'g' : 'h') . $hedefId;

if (!uye_form_dogrula($_POST['jeton'] ?? null)) {
    header('Location: ' . yorum_donus($donus, 'jeton', $hedef), true, 303);
    exit;
}

$islem = (string) ($_POST['islem'] ?? '');

try {
    if ($islem === 'ekle') {
        $id = yorum_ekle($hedefId, (int) $uye['id'], (string) ($_POST['metin'] ?? ''), $tur);
        header('Location: ' . yorum_donus($donus, 'eklendi', $hedef, 'yorum-' . $id), true, 303);
    } elseif ($islem === 'sil') {
        yorum_kendi_sil((int) ($_POST['yorum'] ?? 0), uye_yonetici_mi($uye) ? null : (int) $uye['id']);
        header('Location: ' . yorum_donus($donus, 'silindi', $hedef), true, 303);
    } elseif ($islem === 'sikayet') {
        yorum_sikayet((int) ($_POST['yorum'] ?? 0), (int) $uye['id']);
        header('Location: ' . yorum_donus($donus, 'bildirildi', $hedef), true, 303);
    } else {
        header('Location: ' . $donus, true, 303);
    }
} catch (InvalidArgumentException $e) {
    header('Location: ' . yorum_donus($donus, yorum_hata_kodu($e->getMessage()), $hedef), true, 303);
}
