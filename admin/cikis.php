<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

oturum_baslat();
cikis_yap();
yonlendir('giris.php');
