<?php
declare(strict_types=1);

/**
 * Spor sayfalarinin canli bolumu (HTML parcasi): ?bolum=puan-durumu,
 * fikstur ya da mac; lig, tur ve mac parametreleriyle.
 *
 * Sayfadaki betik bunu 3 dakikada bir yokluyor ve bolumu yeniliyor; mac
 * gunu skorlar sayfa yenilenmeden guncellenir. Veri sunucuda onbellekte
 * (10 dakika), ziyaretci sayisi TFF'ye giden istegi artirmiyor.
 *
 * Acik uc: yetki istemez, dondurdugu bilgi zaten herkese acik.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/spor_panel.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60');

echo spor_paneli_html((string) ($_GET['bolum'] ?? ''), spor_parametreleri($_GET));
