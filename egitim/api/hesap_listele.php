<?php
/**
 * Kayıtlı tüm üyelik hesaplarını döner (şifre hash'i HARİÇ).
 * Yalnızca yönetici erişebilir.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
ppYoneticiDogrula($pdo);
$satirlar = $pdo->query(
    'SELECT kullanici_adi, ad, eposta, rol, kayit_tarihi, son_giris, menu_izin FROM kullanici_hesap ORDER BY kayit_tarihi'
)->fetchAll();

$hesaplar = array_map(function ($r) {
    $izin = null;
    if ($r['menu_izin'] !== null && $r['menu_izin'] !== '') {
        $d = json_decode((string)$r['menu_izin'], true);
        $izin = is_array($d) ? $d : null;
    }
    return [
        'kullaniciAdi' => $r['kullanici_adi'],
        'ad' => $r['ad'],
        'eposta' => $r['eposta'],
        'rol' => $r['rol'],
        'kayitTarihi' => $r['kayit_tarihi'],
        'sonGiris' => $r['son_giris'],
        'menuIzin' => $izin,
    ];
}, $satirlar);

ppJsonYanit(['ok' => true, 'hesaplar' => $hesaplar]);
