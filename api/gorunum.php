<?php
declare(strict_types=1);

/**
 * Sayfa görüntülemesinin tarayıcıdan doğrulanması.
 *
 * Sayfanin altindaki betik (ziyaret_betigi) sayfa gercekten
 * gorundugunde ve kapanirken buraya yaziyor: satir "insan" isaretleniyor,
 * goruntulenme suresi ve kaydirma derinligi guncelleniyor. Ayrinti icin
 * includes/ziyaret.php basina bakin.
 *
 * Guvenlik: her satirin numarasi sunucunun gizli tuzuyla imzalaniyor;
 * imzasi tutmayan istek hicbir seyi degistirmiyor. Satir yalnizca
 * yazildiktan sonraki bir gun icinde guncellenebiliyor. Degerler
 * sinirlaniyor (sure en fazla bir saat, kaydirma 0-100); sureler
 * GREATEST ile yaziliyor ki ayni sayfanin iki bildirimi birbirini
 * kucultmesin.
 *
 * Her durumda 204 donuyor: betik yaniti okumuyor, hata ayrintisi da
 * disariya bilgi vermesin.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ziyaret.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(204);
    exit;
}

$id      = (int) ($_POST['id'] ?? 0);
$imza    = (string) ($_POST['t'] ?? '');
$anahtar = strtolower((string) ($_POST['k'] ?? ''));
$yeni    = ($_POST['y'] ?? '') === '1' ? 1 : 0;
$sure    = max(0, min(ZIYARET_EN_UZUN_SURE, (int) ($_POST['s'] ?? 0)));
$derin   = max(0, min(100, (int) ($_POST['o'] ?? 0)));

if ($id <= 0 || !hash_equals(ziyaret_isaret_imzasi($id), $imza)) {
    http_response_code(204);
    exit;
}

if (preg_match('/^[0-9a-f]{16}$/', $anahtar) !== 1) {
    $anahtar = '';
}

try {
    $ifade = db()->prepare(
        'UPDATE ziyaretler
            SET insan       = 1,
                tarayici_id = IF(tarayici_id = \'\', :k, tarayici_id),
                yeni        = GREATEST(yeni, :y),
                -- Gercekte gecen sureden uzun olamaz (saat kaymasi ya da
                -- elle yazilmis deger): satirin yazildigi andan bu yana.
                sure        = GREATEST(sure, LEAST(:s, TIMESTAMPDIFF(SECOND, zaman, NOW()) + 5)),
                kaydirma    = GREATEST(kaydirma, :o)
          WHERE id = :id
            AND zaman >= (NOW() - INTERVAL 1 DAY)'
    );
    $ifade->execute(['k' => $anahtar, 'y' => $yeni, 's' => $sure, 'o' => $derin, 'id' => $id]);

    // Ilk dogrulama: raporlar bu andan itibaren dogrulanmis satirlari
    // sayiyor (ziyaret_insan_kosulu).
    if ($ifade->rowCount() > 0 && ziyaret_olcum_baslangici() === '') {
        ayar_yaz('olcum_baslangic', date('Y-m-d H:i:s'));
    }
} catch (Throwable $e) {
    error_log('[valentra] gorunum: ' . $e->getMessage());
}

http_response_code(204);
