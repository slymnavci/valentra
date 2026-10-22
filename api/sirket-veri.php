<?php
declare(strict_types=1);

/**
 * Şirket fiyat geçmişi, CSV: ?kod=THYAO&donem=10y
 *
 * Sirket sayfasindaki "Verileri indir" baglantisi. Analist seriyi kendi
 * tablosunda islesin diye (22.10.2026). Excel'in Turkce ayarlariyla
 * dogrudan acilsin: noktali virgul ayirici, virgullu ondalik, UTF-8 BOM.
 * Veri sayfayla ayni onbellekten; kaynaga ek istek gitmiyor (yoksa
 * sayfadaki gibi cekiliyor).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/sirket.php';

$kod   = sirket_kod_temiz((string) ($_GET['kod'] ?? ''));
$donem = (string) ($_GET['donem'] ?? '1y');

if ($kod === null || !isset(sirket_donemleri()[$donem])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Geçersiz kod ya da dönem.';
    exit;
}

$veri = sirket_seri($kod, $donem)['veri'];

if ($veri === null || $veri['noktalar'] === []) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Veri şu an alınamadı; birkaç dakika sonra yeniden deneyin.';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $kod . '_' . $donem . '.csv"');
header('Cache-Control: public, max-age=600');
header('X-Robots-Tag: noindex');

echo "\xEF\xBB\xBF", "Tarih;Kapanış (TL);Hacim (lot)\r\n";

foreach ($veri['noktalar'] as $i => [$t, $d]) {
    echo date('d.m.Y', (int) $t), ';',
         number_format((float) $d, 2, ',', ''), ';',
         (int) ($veri['hacimler'][$i] ?? 0), "\r\n";
}

echo "\r\nKaynak: Yahoo Finance (Valentra); yatırım tavsiyesi değildir.\r\n";
