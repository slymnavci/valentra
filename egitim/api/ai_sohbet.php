<?php
/**
 * AI sohbet proxy'si — assistant.js ve pdf-ozet.js'in DOĞRUDAN
 * OpenAI/Anthropic/Gemini'ye tarayıcıdan attığı isteklerin yerini alır.
 * Anahtar bu dosyada, sunucu tarafında okunur ve sağlayıcıya iletilir;
 * istemciye asla dönmez, tarayıcı ağ sekmesinde de görünmez.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
require_once __DIR__ . '/openai_fallback.php';
ppYetkiKontrol();

$pdo = ppBaglan();
$govde = ppGovdeOku();

/* Kullanıcı kimliği artık istekteki kullanici_adi'nden değil, doğrulanan
   oturum jetonundan alınır — başkasının kayıtlı AI anahtarı kullanılamaz. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];
$saglayici = trim((string)($govde['saglayici'] ?? ''));
$sistem = (string)($govde['sistem'] ?? '');
$mesajlar = $govde['mesajlar'] ?? [];

if (!in_array($saglayici, ['openai', 'anthropic', 'gemini'], true) || !is_array($mesajlar)) {
    ppJsonYanit(['ok' => false, 'hata' => 'Geçerli bir saglayici ve mesajlar zorunludur.'], 400);
}

$sorgu = $pdo->prepare('SELECT anahtar, model FROM kullanici_sir WHERE kullanici_adi = ? AND saglayici = ?');
$sorgu->execute([$kullaniciAdi, $saglayici]);
$satir = $sorgu->fetch();

$adlar = ['openai' => 'ChatGPT', 'anthropic' => 'Claude', 'gemini' => 'Gemini'];
$ad = $adlar[$saglayici];

if (!$satir || $satir['anahtar'] === '') {
    ppJsonYanit(['ok' => false, 'hata' => "$ad için Üyelik ve Ayarlar'da kayıtlı bir API anahtarı yok."], 400);
}

$anahtar = $satir['anahtar'];
$model = $satir['model'] ?: null;

/* Varsayılan cevap bütçesi 4K token. Kullanıcı açıkça uzun/detaylı/kapsamlı
   bir anlatım istediğinde 8K tokena çıkılır. Bu değer üst sınırdır;
   model daha kısa cevap verirse gereksiz token tüketmez. */
$sonKullaniciMesaji = '';
for ($i = count($mesajlar) - 1; $i >= 0; $i--) {
    if (($mesajlar[$i]['rol'] ?? '') === 'kullanici') {
        $sonKullaniciMesaji = (string)($mesajlar[$i]['metin'] ?? '');
        break;
    }
}
$uzunIstekDeseni = '/(detaylı|detayli|kapsamlı|kapsamli|bütün|butun|tamamını|tamamini|tümünü|tumunu|sınav cevabı|sinav cevabi|adım adım|adim adim|uzun anlat|uzun cevap|örnek hazırla|ornek hazirla)/ui';
$maxCiktiToken = preg_match($uzunIstekDeseni, $sonKullaniciMesaji) ? 8000 : 4000;

$fallbackKullanildi = false;
$istenenModel = $model;
$denenenModeller = [];
$openAiSonuc = null;

if ($saglayici === 'openai') {
    /* Responses API. Seçilen model erişilemiyorsa openai_fallback.php
       yalnızca model/erişim hatalarında güçlü yedek modellere geçer. */
    $girdiDizisi = [['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $sistem]]]];
    foreach ($mesajlar as $m) {
        $rol = (($m['rol'] ?? '') === 'kullanici') ? 'user' : 'assistant';
        $icerikTuru = ($rol === 'assistant') ? 'output_text' : 'input_text';
        $girdiDizisi[] = [
            'role' => $rol,
            'content' => [['type' => $icerikTuru, 'text' => (string)($m['metin'] ?? '')]],
        ];
    }

    $govdeIstek = [
        'model' => $model ?: 'gpt-5.6-terra',
        'input' => $girdiDizisi,
        'max_output_tokens' => $maxCiktiToken,
        'store' => false,
    ];

    $openAiSonuc = ppOpenAiResponsesIstek($anahtar, $govdeIstek, 60);
    if (!$openAiSonuc['ok']) {
        if (($openAiSonuc['curl_hata'] ?? '') !== '') {
            ppJsonYanit([
                'ok' => false,
                'hata' => "Sunucudan $ad servisine bağlanılamadı: " . $openAiSonuc['curl_hata'],
                'model' => $model,
                'denenen_modeller' => $openAiSonuc['denenen_modeller'] ?? [],
            ], 502);
        }

        $hataVerisi = $openAiSonuc['data'] ?? [];
        $h = $hataVerisi['error']['message'] ?? 'Bilinmeyen hata';
        ppJsonYanit([
            'ok' => false,
            'hata' => "$ad hatası: $h",
            'model' => $model,
            'denenen_modeller' => $openAiSonuc['denenen_modeller'] ?? [],
        ], 502);
    }

    $d = $openAiSonuc['data'];
    $fallbackKullanildi = (bool)($openAiSonuc['fallback_kullanildi'] ?? false);
    $istenenModel = $openAiSonuc['istenen_model'] ?? ($model ?: 'gpt-5.6-terra');
    $denenenModeller = $openAiSonuc['denenen_modeller'] ?? [];
} elseif ($saglayici === 'gemini') {
    $modelAdi = $model ?: 'gemini-3.1-flash-lite';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelAdi) . ':generateContent?key=' . rawurlencode($anahtar);
    $basliklar = ['Content-Type: application/json'];
    $contents = [];
    foreach ($mesajlar as $m) {
        $contents[] = ['role' => (($m['rol'] ?? '') === 'kullanici') ? 'user' : 'model', 'parts' => [['text' => (string)($m['metin'] ?? '')]]];
    }
    $govdeIstek = [
        'system_instruction' => ['parts' => [['text' => $sistem]]],
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 1500],
    ];
} else {
    $url = 'https://api.anthropic.com/v1/messages';
    $basliklar = ['Content-Type: application/json', 'x-api-key: ' . $anahtar, 'anthropic-version: 2023-06-01'];
    $mesajDizisi = [];
    foreach ($mesajlar as $m) {
        $mesajDizisi[] = ['role' => (($m['rol'] ?? '') === 'kullanici') ? 'user' : 'assistant', 'content' => (string)($m['metin'] ?? '')];
    }
    $govdeIstek = ['model' => $model ?: 'claude-sonnet-5', 'max_tokens' => 1500, 'system' => $sistem, 'messages' => $mesajDizisi];
}

/* OpenAI çağrısı yukarıdaki fallback yardımcısı tarafından yapılır.
   Gemini ve Anthropic eski genel cURL akışını kullanmaya devam eder. */
if ($saglayici !== 'openai') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $basliklar,
        CURLOPT_POSTFIELDS => json_encode($govdeIstek, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);
    $yanit = curl_exec($ch);
    $curlHata = curl_error($ch);
    $httpKod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($yanit === false) {
        ppJsonYanit(['ok' => false, 'hata' => "Sunucudan $ad servisine bağlanılamadı: $curlHata"], 502);
    }

    $d = json_decode($yanit, true);

    if ($httpKod < 200 || $httpKod >= 300) {
        $h = $d['error']['message'] ?? 'Bilinmeyen hata';
        ppJsonYanit(['ok' => false, 'hata' => "$ad hatası: $h", 'model' => $model], 502);
    }
}

$cevap = null;
$gercekModel = $model;
if ($saglayici === 'openai') {
    $parcalar = [];
    foreach ($d['output'] ?? [] as $oge) {
        if (($oge['type'] ?? '') !== 'message') continue;
        foreach ($oge['content'] ?? [] as $icerikParcasi) {
            if (($icerikParcasi['type'] ?? '') === 'output_text' && isset($icerikParcasi['text'])) {
                $parcalar[] = $icerikParcasi['text'];
            }
        }
    }
    $cevap = $parcalar ? implode('', $parcalar) : null;
    $gercekModel = (string)($openAiSonuc['gercek_model'] ?? ($d['model'] ?? ($model ?: 'gpt-5.6-terra')));
} elseif ($saglayici === 'gemini') {
    $parcalar = $d['candidates'][0]['content']['parts'] ?? [];
    $cevap = implode('', array_map(fn($p) => $p['text'] ?? '', $parcalar));
} else {
    $cevap = $d['content'][0]['text'] ?? null;
}

if (!$cevap) {
    ppJsonYanit(['ok' => false, 'hata' => "$ad modelinden cevap alınamadı.", 'model' => $gercekModel], 502);
}

$yanitGovdesi = ['ok' => true, 'cevap' => $cevap, 'model' => $gercekModel];
if ($saglayici === 'openai') {
    $yanitGovdesi['istenen_model'] = $istenenModel;
    $yanitGovdesi['fallback_kullanildi'] = $fallbackKullanildi;
    $yanitGovdesi['denenen_modeller'] = $denenenModeller;
}
ppJsonYanit($yanitGovdesi);
