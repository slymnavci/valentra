<?php
/**
 * İçeriği (SINAV+DERSLER, SORULAR) doğrudan sunucudaki JSON dosyalarına
 * yazar. GitHub/deploy.yml'ye gerek kalmadan, Yönetim panelindeki her
 * değişiklik bu uç nokta üzerinden anında canlıya yazılır — bu istek
 * bu sunucunun (suleymanavci.com.tr) kendi PHP'si tarafından çalıştığı
 * için doğrudan dosya sistemine yazabilir.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$govde = ppGovdeOku();

$dersler = $govde['dersler'] ?? null;   // { sinav, dersler }
$sorular = $govde['sorular'] ?? null;   // { dersId: { konuId: [...] } }
$menu = $govde['menu'] ?? null;         // { ogeler, ozelSayfalar }

if (!is_array($dersler) && !is_array($sorular) && !is_array($menu)) {
    ppJsonYanit(['ok' => false, 'hata' => 'İstek gövdesinde dersler, sorular ve/veya menu bulunmalı.'], 400);
}

$icerikKlasoru = dirname(__DIR__) . '/content';
if (!is_dir($icerikKlasoru) || !is_writable($icerikKlasoru)) {
    ppJsonYanit(['ok' => false, 'hata' => "Sunucuda public/content/ klasörüne yazma izni yok. Dosya izinlerini kontrol edin."], 500);
}

if (is_array($dersler)) {
    $json = json_encode($dersler, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if (file_put_contents($icerikKlasoru . '/dersler.json', $json) === false) {
        ppJsonYanit(['ok' => false, 'hata' => 'dersler.json sunucuya yazılamadı.'], 500);
    }
}

if (is_array($sorular)) {
    $json = json_encode($sorular, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if (file_put_contents($icerikKlasoru . '/sorular.json', $json) === false) {
        ppJsonYanit(['ok' => false, 'hata' => 'sorular.json sunucuya yazılamadı.'], 500);
    }
}

if (is_array($menu)) {
    $json = json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if (file_put_contents($icerikKlasoru . '/menu.json', $json) === false) {
        ppJsonYanit(['ok' => false, 'hata' => 'menu.json sunucuya yazılamadı.'], 500);
    }
}

ppJsonYanit(['ok' => true]);
