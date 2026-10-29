<?php
declare(strict_types=1);

/**
 * /ads.txt — AdSense yetkili satıcı dosyası (.htaccess -> buraya).
 *
 * Google AdSense, yayinci kimliginin sitenin /ads.txt dosyasinda
 * yazmasini istiyor; yoksa reklam geliri kisitlaniyor. Kimlik panelden
 * (bkz. includes/olcum_reklam.php); tanimsizsa bos dosya.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/olcum_reklam.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$istemci = reklam_istemci();

if ($istemci !== '') {
    echo 'google.com, ' . substr($istemci, 3) . ', DIRECT, f08c47fec0942fa0', "\n";
}
