<?php
declare(strict_types=1);

/**
 * Ajanin derledigi haberleri taslak olarak alan uc.
 *
 *   POST /api/ingest.php
 *   Authorization: Bearer <anahtar>
 *   Content-Type: application/json
 *
 *   {"haberler": [{"baslik": "...", "icerik": "...", "kaynak_url": "...", ...}]}
 *
 * Gelen kayit taslak olarak yazilir. Otomatik yayin aciksa (varsayilan,
 * site sahibinin karari 23.10.2026; panelde Ajan sayfasindan kapatilir)
 * hemen yayina alinir; kapaliysa yonetici onayini bekler. Kopya denetimi
 * her iki durumda ayni.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

$anahtarId = ajan_anahtar_dogrula($neden);

if ($anahtarId === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);

if (!is_array($veri) || !isset($veri['haberler']) || !is_array($veri['haberler'])) {
    ajan_json(400, ['hata' => 'Gövde {"haberler": [...]} biçiminde olmalı.']);
}

if (count($veri['haberler']) > 100) {
    ajan_json(400, ['hata' => 'Tek istekte en fazla 100 haber gönderilebilir.']);
}

$sonuc = haber_toplu_taslak_ekle($veri['haberler']);
$otomatik = haber_otomatik_yayin_acik();

ajan_json(200, [
    'durum'     => 'tamam',
    'eklenen'   => $sonuc['eklenen'],
    'yinelenen' => $sonuc['yinelenen'],
    'yayinlanan' => $sonuc['yayinlanan'],
    'ana_sayfa'  => haber_ana_sayfa_durumu(),
    'hatalar'   => $sonuc['hatalar'],
    'not'       => $otomatik
        ? 'Güven puanı %' . HABER_OTOMATIK_GUVEN_ESIGI . ' (OpenAI modellerinde %'
            . HABER_OTOMATIK_GUVEN_ESIGI_OPENAI . ') ve üzeri olup kaynakta yayın tarihi bulunanlar yayına alındı; diğerleri onay bekliyor.'
        : 'Haberler taslak olarak kaydedildi, yönetici onayı bekliyor.',
]);
