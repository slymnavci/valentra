<?php
declare(strict_types=1);

/**
 * Ajanin kaynak tarama sonuclari (13.11.2026; bkz. includes/kaynak_durum.php).
 *
 *   POST /api/kaynak-durum.php
 *   {"kaynaklar": [{"id": 12, "durum": "tamam|bos|hata", "neden": "...", "girdi": 40}]}
 *
 * Ust uste hata veren kaynak katlanan surelerle bekletilir; durum panelde
 * (Kaynaklar) gorunur.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/kaynak_durum.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

if (ajan_anahtar_dogrula($neden) === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$govde = file_get_contents('php://input');
$veri  = json_decode($govde !== false ? $govde : '', true);
$liste = is_array($veri) ? ($veri['kaynaklar'] ?? null) : null;

if (!is_array($liste)) {
    ajan_json(400, ['hata' => 'Gövde {"kaynaklar": [{"id": 1, "durum": "tamam|bos|hata"}]} biçiminde olmalı.']);
}

$yazilan  = 0;
$bekleyen = 0;

foreach (array_slice($liste, 0, 1000) as $k) {
    if (!is_array($k)) {
        continue;
    }

    try {
        $sonuc = kaynak_durum_yaz((int) ($k['id'] ?? 0), (string) ($k['durum'] ?? ''), (string) ($k['neden'] ?? ''));
    } catch (Throwable $e) {
        error_log('[valentra] kaynak durumu: ' . $e->getMessage());
        $sonuc = null;
    }

    if ($sonuc !== null) {
        $yazilan++;
        $bekleyen += $sonuc['hata_sayisi'] > 0 ? 1 : 0;
    }
}

ajan_json(200, ['durum' => 'tamam', 'yazilan' => $yazilan, 'hata_bekleyen' => $bekleyen]);
