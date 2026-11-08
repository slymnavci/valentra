<?php
declare(strict_types=1);

/**
 * Günün Kelimesi ucu (bkz. includes/kelime_oyunu.php).
 *
 *   POST g=<gun> t=<tahmin>  -> {"sonuc":[2,1,0,..],"bitti":bool,(+cevap)}
 *   POST g=<gun> pes=1       -> cevap (alti hak bittiginde)
 *
 * Cevap sayfaya yazilmiyor; tahmin burada karsilastiriliyor. Gun
 * degistiyse 409: sayfa yeni gunun oyununu baslatiyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/kelime_oyunu.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$cevapla = static function (int $kod, array $govde): never {
    http_response_code($kod);
    echo json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $cevapla(405, ['hata' => 'Yalnızca POST.']);
}

$oyun = kelime_bugun();

if ((string) ($_POST['g'] ?? '') !== $oyun['gun']) {
    $cevapla(409, ['hata' => 'Yeni günün kelimesi geldi.', 'gun' => $oyun['gun']]);
}

$cevapBilgisi = [
    'cevap' => $oyun['kelime'],
    'haber' => $oyun['haber_slug'] !== ''
        ? ['baslik' => $oyun['haber_baslik'], 'adres' => haber_yolu($oyun['haber_slug'])]
        : null,
];

if (($_POST['pes'] ?? '') === '1') {
    $cevapla(200, $cevapBilgisi);
}

$tahmin = kelime_buyuk(trim((string) ($_POST['t'] ?? '')));

if (!kelime_gecerli_mi($tahmin)) {
    $cevapla(400, ['hata' => KELIME_UZUNLUK . ' harfli bir kelime yazın.']);
}

$sonuc = kelime_karsilastir($tahmin, $oyun['kelime']);
$dogru = !in_array(0, $sonuc, true) && !in_array(1, $sonuc, true);

$cevapla(200, ['sonuc' => $sonuc, 'dogru' => $dogru] + ($dogru ? $cevapBilgisi : []));
