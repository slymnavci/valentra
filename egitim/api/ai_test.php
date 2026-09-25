<?php
/**
 * Kayıtlı OpenAI API anahtarını gerçek bir Responses API çağrısıyla test eder.
 * Anahtar istemciye hiçbir zaman dönmez; yalnızca doğrulanmış oturum sahibinin
 * sunucuda kayıtlı anahtarı kullanılır.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
require_once __DIR__ . '/openai_fallback.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

$sorgu = $pdo->prepare('SELECT anahtar, model FROM kullanici_sir WHERE kullanici_adi = ? AND saglayici = ?');
$sorgu->execute([$kullaniciAdi, 'openai']);
$satir = $sorgu->fetch();

if (!$satir || trim((string)$satir['anahtar']) === '') {
    ppJsonYanit(['ok' => false, 'hata' => "OpenAI için Üyelik ve Ayarlar'da kayıtlı bir API anahtarı yok."], 400);
}

$anahtar = trim((string)$satir['anahtar']);
$model = trim((string)($satir['model'] ?? '')) ?: 'gpt-5.6-terra';

$istek = [
    'model' => $model,
    'input' => 'Bu bir bağlantı testidir. Yanıt olarak yalnızca TAMAM yaz.',
    'max_output_tokens' => 64,
    'store' => false,
];

$baslangic = microtime(true);
$sonuc = ppOpenAiResponsesIstek($anahtar, $istek, 30);
$sureMs = (int)round((microtime(true) - $baslangic) * 1000);

if (!$sonuc['ok']) {
    if (($sonuc['curl_hata'] ?? '') !== '') {
        ppJsonYanit([
            'ok' => false,
            'hata' => "OpenAI'a bağlanılamadı: " . $sonuc['curl_hata'],
            'model' => $model,
            'denenen_modeller' => $sonuc['denenen_modeller'] ?? [],
        ], 502);
    }

    $d = $sonuc['data'] ?? [];
    $h = $d['error']['message'] ?? 'Bilinmeyen hata';
    ppJsonYanit([
        'ok' => false,
        'hata' => "OpenAI hatası: $h",
        'model' => $model,
        'http_kod' => $sonuc['http_kod'] ?? 0,
        'denenen_modeller' => $sonuc['denenen_modeller'] ?? [],
    ], 502);
}

$d = $sonuc['data'];
$parcalar = [];
foreach ($d['output'] ?? [] as $oge) {
    if (($oge['type'] ?? '') !== 'message') continue;
    foreach ($oge['content'] ?? [] as $icerik) {
        if (($icerik['type'] ?? '') === 'output_text' && isset($icerik['text'])) {
            $parcalar[] = (string)$icerik['text'];
        }
    }
}
$cevap = trim(implode('', $parcalar));

ppJsonYanit([
    'ok' => true,
    'anahtar_kayitli' => true,
    'baglanti' => true,
    'istenen_model' => $sonuc['istenen_model'] ?? $model,
    'gercek_model' => $sonuc['gercek_model'] ?? $model,
    'fallback_kullanildi' => (bool)($sonuc['fallback_kullanildi'] ?? false),
    'denenen_modeller' => $sonuc['denenen_modeller'] ?? [$model],
    'cevap' => $cevap,
    'sure_ms' => $sureMs,
]);
