<?php
declare(strict_types=1);

/**
 * Derleme haber ajanının ucu (28.10.2026; bkz. includes/derleme.php).
 *
 *   GET  -> malzeme: son saatlerin haberleri (derlemeler ve son gunlerde
 *           derlenmis haberler haric), son gunlerin derleme basliklari,
 *           bugun kac derleme oldugu ve gunluk tavan
 *   POST -> {"derlemeler": [{"baslik","ozet","icerik","neden_onemli",
 *           "etiketler","kategori","resmi_dayanak","haber_idleri",
 *           "ajan_notu","model"}]}
 *
 * Gelen her derleme TASLAK olarak yazilir; yayina alma panelde.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/derleme.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    $saat = max(6, min(72, (int) ($_GET['saat'] ?? 24)));

    ajan_json(200, [
        'bugun'        => date('Y-m-d'),
        'bugun_sayisi' => derleme_bugun_sayisi(),
        'gunluk_tavan' => DERLEME_GUNLUK_TAVAN,
        'haberler'     => derleme_malzeme($saat),
        'onceki'       => derleme_onceki(3),
        'kategoriler'  => array_values(array_map(
            static fn (array $k): string => (string) $k['slug'],
            kategori_listesi()
        )),
    ]);
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !isset($veri['derlemeler']) || !is_array($veri['derlemeler'])) {
    ajan_json(400, ['hata' => 'Gövde {"derlemeler": [...]} biçiminde olmalı.']);
}

if (count($veri['derlemeler']) > DERLEME_GUNLUK_TAVAN) {
    ajan_json(400, ['hata' => 'Tek istekte en fazla ' . DERLEME_GUNLUK_TAVAN . ' derleme gönderilebilir.']);
}

$eklenen   = 0;
$yinelenen = 0;
$yayinlanan = 0;
$hatalar   = [];

foreach ($veri['derlemeler'] as $sira => $derleme) {
    if (!is_array($derleme)) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kayıt nesne değil.'];
        continue;
    }

    try {
        $sonuc = derleme_taslak_ekle($derleme);

        if ($sonuc['durum'] === 'eklendi') {
            $eklenen++;

            if (haber_otomatik_yayin_acik() && haber_otomatik_yayinla((int) $sonuc['id'])) {
                $yayinlanan++;
            }
        } else {
            $yinelenen++;
        }
    } catch (InvalidArgumentException $e) {
        $hatalar[] = ['sira' => $sira, 'mesaj' => $e->getMessage()];
    } catch (Throwable $e) {
        // Bir derlemenin hatasi digerlerini dusurmesin; sebep ajana donsun
        // (anahtarla korunan uc) ve sunucu gunlugune yazilsin.
        error_log('[valentra] derleme kaydedilemedi: ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $hatalar[] = ['sira' => $sira, 'mesaj' => 'Kaydedilemedi: ' . get_class($e) . ': '
            . mb_substr($e->getMessage(), 0, 300, 'UTF-8')];
    }
}

ajan_json(200, [
    'durum'     => 'tamam',
    'eklenen'   => $eklenen,
    'yinelenen' => $yinelenen,
    'yayinlanan' => $yayinlanan,
    'hatalar'   => $hatalar,
    'not'       => haber_otomatik_yayin_acik()
        ? 'Derlemeler yayına alındı (otomatik yayın açık).'
        : 'Derlemeler taslak olarak kaydedildi, yönetici onayı bekliyor.',
]);
