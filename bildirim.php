<?php
declare(strict_types=1);

/**
 * Tarayici bildirimi aboneligi (03.11.2026; bkz. includes/web_push.php).
 *
 *   POST /bildirim.php  islem=abone  uc, p256dh, auth   aboneligi kaydet
 *   POST /bildirim.php  islem=iptal  uc                  aboneligi sil
 *   POST /bildirim.php  islem=tik    g=<gonderim no>     bildirime tiklandi (sw.js)
 *
 * Govde form (multipart sitede kapali). Abonelik adresi yalnizca bilinen
 * push servislerinden kabul ediliyor; dakikada en fazla 60 yeni abone.
 * Yanit JSON {"tamam": true|false}.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/web_push.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"tamam":false}';
    exit;
}

$islem = (string) ($_POST['islem'] ?? '');
$tamam = false;

try {
    if ($islem === 'abone') {
        $yeni = (int) db()->query('SELECT COUNT(*) FROM push_aboneleri WHERE olusturuldu > NOW() - INTERVAL 1 MINUTE')->fetchColumn();

        if ($yeni < 60) {
            $tamam = push_abone_kaydet(
                (string) ($_POST['uc'] ?? ''),
                (string) ($_POST['p256dh'] ?? ''),
                (string) ($_POST['auth'] ?? ''),
                (string) ($_POST['tarayici'] ?? '')
            );
        }
    } elseif ($islem === 'iptal') {
        push_abone_sil((string) ($_POST['uc'] ?? ''));
        $tamam = true;
    } elseif ($islem === 'tik') {
        db()->prepare('UPDATE push_gonderimleri SET tiklama = tiklama + 1 WHERE id = :id')
            ->execute(['id' => (int) ($_POST['g'] ?? 0)]);
        $tamam = true;
    }
} catch (PDOException $e) {
    error_log('[valentra] bildirim aboneligi: ' . $e->getMessage());
}

http_response_code($tamam ? 200 : 400);
echo json_encode(['tamam' => $tamam]);
