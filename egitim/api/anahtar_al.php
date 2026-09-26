<?php
/**
 * Giriş yapmış kullanıcıya uygulama anahtarını verir.
 *
 * Valentra'da anahtar bir sır değil, istemcinin sabit parçası: yetki
 * oturum jetonundan geliyor (üye uçları ppOturumDogrula, içerik ve üye
 * yönetimi uçları ppYoneticiDogrula ister). Anahtarın kullanıcıya elle
 * girdirilmesi gereksizdi ve üyelerin ilerlemesi anahtarsız olduğu için
 * sunucuya hiç yazılmıyordu.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';

$pdo = ppBaglan();
ppOturumDogrula($pdo);

ppJsonYanit(['ok' => true, 'anahtar' => defined('APP_ANAHTARI') ? APP_ANAHTARI : '']);
