<?php
declare(strict_types=1);

/**
 * V Network islemleri (31.10.2026): paylasim, begeni, takip, mesaj,
 * profil. Yalnizca POST ve giris yapmis uye; form jetonu baska sitelerden
 * gonderimi engelliyor (includes/uyelik.php).
 *
 * Sonuc mesaji kisa omurlu bir cerezle (valentra_nw_bildirim) donus
 * sayfasinda gosteriliyor; sayfa yenilenince form tekrar gitmesin diye
 * her islem yonlendirmeyle bitiyor. Uyenin sayfalari onbellege girmiyor,
 * mesaj yalnizca ona gorunuyor.
 *
 * Fotograflar data: adresi olarak siradan form alaninda geliyor
 * (multipart sitede kapali; bkz. includes/network_foto.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/network.php';
require_once __DIR__ . '/includes/uye_eposta.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$donus = uye_guvenli_donus((string) ($_POST['donus'] ?? '')) ?: nw_yol();
$uye   = aktif_uye();

function nw_bitir(string $donus, string $tur, string $metin): never
{
    setcookie('valentra_nw_bildirim', $tur . '|' . $metin, uye_cerez_secenekleri(time() + 120));
    header('Location: ' . $donus, true, 303);
    exit;
}

if ($uye === null) {
    header('Location: ' . uye_yolu('giris', $donus), true, 303);
    exit;
}

if (!uye_form_dogrula($_POST['jeton'] ?? null)) {
    nw_bitir($donus, 'hata', 'Sayfanın süresi doldu; lütfen yeniden deneyin.');
}

$uyeId = (int) $uye['id'];
$islem = (string) ($_POST['islem'] ?? '');

// E-posta dogrulamasi zorunluysa dogrulanmamis uye paylasim ve mesaj yazamaz (31.10.2026).
if (in_array($islem, ['gonderi', 'mesaj'], true) && ($engel = uye_yazma_engeli($uye)) !== null) {
    nw_bitir($donus, 'hata', $engel);
}

try {
    switch ($islem) {
        case 'gonderi':
            $id = gonderi_ekle($uyeId, (string) ($_POST['metin'] ?? ''), (string) ($_POST['video'] ?? ''), (string) ($_POST['fotolar'] ?? ''));
            nw_bitir((preg_replace('/#.*$/', '', $donus) ?? $donus) . '#g' . $id, 'basari', 'Paylaşımınız yayımlandı.');

        case 'gonderi_sil':
            // Yonetici herkesin paylasimini silebiliyor.
            gonderi_kendi_sil((int) ($_POST['gonderi'] ?? 0), uye_yonetici_mi($uye) ? null : $uyeId);
            nw_bitir($donus, 'bilgi', 'Paylaşım silindi.');

        case 'uye_durdur':
        case 'uye_ac':
            if (!uye_yonetici_mi($uye)) {
                nw_bitir($donus, 'hata', 'Bu işlem için yetkiniz yok.');
            }

            nw_uye_durum_degistir((int) ($_POST['uye'] ?? 0), $islem === 'uye_durdur');
            nw_bitir($islem === 'uye_durdur' ? nw_yol('kisiler') : $donus, 'bilgi', $islem === 'uye_durdur' ? 'Üyelik durduruldu.' : 'Üyelik yeniden açıldı.');

        case 'gonderi_sikayet':
            gonderi_sikayet((int) ($_POST['gonderi'] ?? 0), $uyeId);
            nw_bitir($donus, 'bilgi', 'Bildiriminiz alındı, teşekkürler. Paylaşım site yönetimince incelenecek.');

        case 'begen':
            $hedef = (string) ($_POST['hedef'] ?? '');
            begeni_degistir(str_starts_with($hedef, 'g') ? 'gonderi' : 'haber', (int) substr($hedef, 1), $uyeId);
            header('Location: ' . $donus, true, 303);
            exit;

        case 'takip':
        case 'takip_birak':
            $kisi = (int) ($_POST['uye'] ?? 0);

            if ($islem === 'takip') {
                takip_et($uyeId, $kisi);
            } else {
                takip_birak($uyeId, $kisi);
            }

            header('Location: ' . $donus, true, 303);
            exit;

        case 'mesaj':
            $kisi = (int) ($_POST['uye'] ?? 0);
            mesaj_gonder($uyeId, $kisi, (string) ($_POST['metin'] ?? ''));
            header('Location: ' . nw_yol('mesajlar/' . $kisi) . '#son', true, 303);
            exit;

        case 'profil':
            nw_profil_kaydet($uyeId, (string) ($_POST['ad'] ?? ''), (string) ($_POST['hakkinda'] ?? ''));

            if ((string) ($_POST['foto'] ?? '') !== '') {
                nw_profil_foto($uyeId, (string) $_POST['foto']);
            } elseif (!empty($_POST['foto_kaldir'])) {
                nw_profil_foto($uyeId, '');
            }

            nw_bitir(nw_profil_yolu($uyeId), 'basari', 'Profiliniz güncellendi.');

        default:
            header('Location: ' . $donus, true, 303);
            exit;
    }
} catch (InvalidArgumentException $e) {
    nw_bitir($donus, 'hata', $e->getMessage());
}
