<?php
/**
 * OpenAI Responses API model fallback helper.
 *
 * Seçilen model erişilebilir değilse yalnızca model/erişim hatalarında
 * güçlü yedek modellere sırayla geçer. Kota, bakiye, ağ veya başka API
 * hatalarında sessizce model değiştirmez.
 */

function ppOpenAiModelErisimHatasi(array $yanit, int $httpKod): bool
{
    if ($httpKod >= 200 && $httpKod < 300) return false;

    $hata = $yanit['error'] ?? [];
    $kod = strtolower(trim((string)($hata['code'] ?? '')));
    $tip = strtolower(trim((string)($hata['type'] ?? '')));
    $mesaj = strtolower(trim((string)($hata['message'] ?? '')));

    if (in_array($kod, ['model_not_found', 'invalid_model', 'unsupported_model'], true)) {
        return true;
    }

    if (in_array($tip, ['model_not_found', 'invalid_model', 'unsupported_model'], true)) {
        return true;
    }

    return (bool)preg_match(
        '/does not exist|do not have access|not have access|model[^\n]*(not found|unavailable|unsupported|permission|access denied)/i',
        $mesaj
    );
}

function ppOpenAiFallbackModelleri(?string $istenenModel): array
{
    $adaylar = [
        trim((string)$istenenModel),
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-5.5',
        'gpt-5.1',
        'gpt-4o',
    ];

    $sonuc = [];
    foreach ($adaylar as $model) {
        if ($model === '' || in_array($model, $sonuc, true)) continue;
        $sonuc[] = $model;
    }
    return $sonuc;
}

/**
 * Responses API çağrısını yapar. İlk model yalnızca model/erişim nedeniyle
 * reddedilirse listedeki diğer modellere geçer.
 */
function ppOpenAiResponsesIstek(string $anahtar, array $govde, int $timeout = 60): array
{
    $istenenModel = trim((string)($govde['model'] ?? '')) ?: 'gpt-5.6-terra';
    $adaylar = ppOpenAiFallbackModelleri($istenenModel);
    $denenen = [];
    $sonYanit = [];
    $sonHttpKod = 0;
    $sonCurlHata = '';
    $sonModel = $istenenModel;

    foreach ($adaylar as $model) {
        $denenen[] = $model;
        $sonModel = $model;
        $istekGovdesi = $govde;
        $istekGovdesi['model'] = $model;

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $anahtar,
            ],
            CURLOPT_POSTFIELDS => json_encode($istekGovdesi, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $hamYanit = curl_exec($ch);
        $sonCurlHata = curl_error($ch);
        $sonHttpKod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($hamYanit === false) {
            return [
                'ok' => false,
                'data' => [],
                'http_kod' => $sonHttpKod,
                'curl_hata' => $sonCurlHata,
                'istenen_model' => $istenenModel,
                'kullanilan_model' => $model,
                'gercek_model' => $model,
                'fallback_kullanildi' => $model !== $istenenModel,
                'denenen_modeller' => $denenen,
            ];
        }

        $sonYanit = json_decode($hamYanit, true);
        if (!is_array($sonYanit)) $sonYanit = [];

        if ($sonHttpKod >= 200 && $sonHttpKod < 300) {
            $gercekModel = (string)($sonYanit['model'] ?? $model);
            return [
                'ok' => true,
                'data' => $sonYanit,
                'http_kod' => $sonHttpKod,
                'curl_hata' => '',
                'istenen_model' => $istenenModel,
                'kullanilan_model' => $model,
                'gercek_model' => $gercekModel,
                'fallback_kullanildi' => $model !== $istenenModel,
                'denenen_modeller' => $denenen,
            ];
        }

        // Yalnızca model yok/erişim yok hatalarında bir sonraki modele geç.
        if (!ppOpenAiModelErisimHatasi($sonYanit, $sonHttpKod)) {
            break;
        }
    }

    return [
        'ok' => false,
        'data' => $sonYanit,
        'http_kod' => $sonHttpKod,
        'curl_hata' => $sonCurlHata,
        'istenen_model' => $istenenModel,
        'kullanilan_model' => $sonModel,
        'gercek_model' => $sonModel,
        'fallback_kullanildi' => $sonModel !== $istenenModel,
        'denenen_modeller' => $denenen,
    ];
}
