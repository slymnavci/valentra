<?php
declare(strict_types=1);

/**
 * Ajanin calisma durumu (su an: OpenAI kredi/anahtar durumu).
 *
 *   POST /api/ajan-durum.php
 *   {"openai": {"model": "gpt-6-luna", "durum": "kredi|anahtar|tamam", "mesaj": "..."}}
 *
 * Panel uyarisi icin (bkz. includes/openai_durum.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ajan_yetki.php';
require_once __DIR__ . '/../includes/openai_durum.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ajan_json(405, ['hata' => 'Yalnızca POST kabul edilir.']);
}

if (ajan_anahtar_dogrula($neden) === null) {
    ajan_json(401, ajan_yetki_hatasi($neden));
}

$govde  = file_get_contents('php://input');
$veri   = json_decode($govde !== false ? $govde : '', true);
$openAi = is_array($veri) ? ($veri['openai'] ?? null) : null;

if (!is_array($openAi) || !in_array($openAi['durum'] ?? '', ['kredi', 'anahtar', 'tamam'], true)) {
    ajan_json(400, ['hata' => 'Gövde {"openai": {"durum": "kredi|anahtar|tamam", ...}} biçiminde olmalı.']);
}

openai_durum_yaz([
    'model' => trim((string) ($openAi['model'] ?? '')),
    'durum' => (string) $openAi['durum'],
    'mesaj' => trim((string) ($openAi['mesaj'] ?? '')),
]);

ajan_json(200, ['durum' => 'tamam']);
