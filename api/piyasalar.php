<?php
declare(strict_types=1);

/**
 * Ekonomi sayfasinin canli bolumleri (HTML parcasi): ?bolum=kartlar ya
 * da ?bolum=sirketler.
 *
 * Sayfadaki betik 2 dakikada bir yokluyor. Veri sunucuda onbellekte;
 * ziyaretci sayisi Yahoo'ya giden istegi artirmiyor.
 *
 * Acik uc: yetki istemez, dondurdugu bilgi zaten herkese acik.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/piyasa_panel.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60');

echo match ((string) ($_GET['bolum'] ?? '')) {
    // Bolum sayfasi yalnizca kendi araclarini istiyor (bilinmeyen ad atilir).
    'kartlar'   => piyasa_kartlari_html(array_values(array_intersect(
        explode(',', (string) ($_GET['araclar'] ?? '')),
        array_keys(piyasa_araclari())
    ))),
    'sirketler' => piyasa_sirketler_html(),
    default     => '',
};
