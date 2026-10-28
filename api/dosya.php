<?php
declare(strict_types=1);

/**
 * "Valentra Dosya" açıklayıcı yazı ajanının ucu (bkz. includes/dosya.php,
 * ajan/aciklayici.php).
 *
 *   GET  -> malzeme: firsat sorgulari ve ilgili haberler, son gunlerin
 *           dosya basliklari, bugunku sayilar
 *   POST -> {"haberler": [{"tur","baslik","ozet","icerik","kategori","haber_idleri","onem","editor_notu"}]}
 *
 * Gelen her yazi TASLAK; bu uc yayimlayamaz, yayina alma panelde.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/dosya.php';

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($yontem === 'GET') {
    ajan_json(200, dosya_malzeme());
}

if ($yontem !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca GET ve POST kabul edilir.']);
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !is_array($veri['haberler'] ?? null) || count($veri['haberler']) > 6) {
    ajan_json(400, ['hata' => 'Gövde {"haberler": [...]} biçiminde ve en fazla 6 kayıt olmalı.']);
}

$sonuc = ['eklenen' => 0, 'yinelenen' => 0, 'tavan' => 0, 'hatalar' => []];

foreach ($veri['haberler'] as $sira => $h) {
    try {
        $kayit = dosya_kaydet(is_array($h) ? $h : []);

        match ($kayit['durum']) {
            'eklendi' => $sonuc['eklenen']++,
            'tavan'   => $sonuc['tavan']++,
            default   => $sonuc['yinelenen']++,
        };
    } catch (InvalidArgumentException $e) {
        $sonuc['hatalar'][] = ['sira' => $sira, 'mesaj' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[valentra] dosya kaydi: ' . $e->getMessage());
        $sonuc['hatalar'][] = ['sira' => $sira, 'mesaj' => 'Kaydedilemedi.'];
    }
}

ajan_json(200, $sonuc);
