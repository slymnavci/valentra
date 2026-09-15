<?php
declare(strict_types=1);

/**
 * Ajanı panelden tetikleme.
 *
 * Ajan GitHub Actions üzerinde çalışır; Gemini anahtarı orada durur ve
 * hosting'e hiç taşınmaz. Panel yalnızca "çalış" komutunu gönderir.
 *
 * Bunun için GitHub'a yazma izni olan bir erişim anahtarı gerekiyor.
 * Anahtar yalnızca bu depoda workflow tetiklemeye yetecek kapsamda
 * olmalı; panelden girilir, veritabanında saklanır.
 */

require_once __DIR__ . '/url.php';

const AJAN_GITHUB_ANAHTAR = 'github_anahtar';
const AJAN_GITHUB_DEPO    = 'github_depo';
const AJAN_WORKFLOW       = 'ajan.yml';

/** Varsayılan depo; panelden değiştirilebilir. */
const AJAN_VARSAYILAN_DEPO = 'slymnavci/valentra';

function ajan_depo(): string
{
    $depo = trim(ayar_oku(AJAN_GITHUB_DEPO));

    return $depo !== '' ? $depo : AJAN_VARSAYILAN_DEPO;
}

function ajan_tetikleyebilir_mi(): bool
{
    return trim(ayar_oku(AJAN_GITHUB_ANAHTAR)) !== '';
}

/**
 * Ajanı çalıştırır.
 *
 * @return array{tamam:bool,mesaj:string}
 */
function ajan_tetikle(bool $kuru = false, int $saat = 36, int $enFazla = 25, string $mod = 'topla'): array
{
    $anahtar = trim(ayar_oku(AJAN_GITHUB_ANAHTAR));

    if ($anahtar === '') {
        return [
            'tamam' => false,
            'mesaj' => 'GitHub erişim anahtarı tanımlı değil. Ayarlar sayfasından girin.',
        ];
    }

    $depo = ajan_depo();

    if (!preg_match('#^[\w.-]+/[\w.-]+$#', $depo)) {
        return ['tamam' => false, 'mesaj' => 'Depo adı "kullanici/depo" biçiminde olmalı.'];
    }

    $adres = 'https://api.github.com/repos/' . $depo
           . '/actions/workflows/' . AJAN_WORKFLOW . '/dispatches';

    $govde = json_encode([
        'ref'    => 'main',
        'inputs' => [
            // Bilinmeyen bir mod GitHub'dan 422 dondurur; bilinen
            // degerlerle sinirlayip anlasilir hata veriyoruz.
            'mod'     => in_array($mod, ['kaynak-testi', 'kanun-testi'], true)
                ? $mod
                : 'topla',
            'kuru'    => $kuru ? 'true' : 'false',
            'saat'    => (string) max(1, $saat),
            'enfazla' => (string) max(1, $enFazla),
        ],
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init($adres);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $govde,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $anahtar,
            'X-GitHub-Api-Version: 2022-11-28',
            'Content-Type: application/json',
            'User-Agent: Valentra-Panel',
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $yanit = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata  = curl_error($ch);
    curl_close($ch);

    if (!is_string($yanit)) {
        return ['tamam' => false, 'mesaj' => 'GitHub\'a ulaşılamadı: ' . $hata];
    }

    // Basarili tetikleme 204 doner, govde bostur.
    if ($kod === 204) {
        if ($mod === 'kaynak-testi') {
            return [
                'tamam' => true,
                'mesaj' => 'Kaynak testi başladı. Sonucu GitHub kayıtlarında '
                         . 'göreceksiniz: hangi kaynak okunuyor, hangisi '
                         . 'neden okunmuyor. Siteye hiçbir şey yazılmaz.',
            ];
        }

        if ($mod === 'kanun-testi') {
            return [
                'tamam' => true,
                'mesaj' => 'Kanun bağlantıları sınanıyor. Sonucu GitHub '
                         . 'kayıtlarında göreceksiniz: hangi kanun adresi '
                         . 'açılıyor, hangisi kırık.',
            ];
        }

        return [
            'tamam' => true,
            'mesaj' => 'Ajan çalışmaya başladı. Haberler birkaç dakika içinde '
                     . 'onay bekleyen listesinde görünecek.',
        ];
    }

    $veri  = json_decode($yanit, true);
    $mesaj = is_array($veri) ? (string) ($veri['message'] ?? '') : '';

    if ($kod === 401) {
        return ['tamam' => false, 'mesaj' => 'GitHub anahtarı geçersiz ya da süresi dolmuş.'];
    }

    if ($kod === 403) {
        return [
            'tamam' => false,
            'mesaj' => 'GitHub anahtarının bu depoda workflow çalıştırma izni yok. '
                     . 'Anahtara "Actions: read and write" izni verin.',
        ];
    }

    if ($kod === 404) {
        return [
            'tamam' => false,
            'mesaj' => 'Depo ya da workflow bulunamadı. Depo adını kontrol edin '
                     . '(' . $depo . ') ve anahtarın bu depoya erişimi olduğundan emin olun.',
        ];
    }

    return [
        'tamam' => false,
        'mesaj' => 'GitHub hatası (HTTP ' . $kod . ')' . ($mesaj !== '' ? ': ' . $mesaj : ''),
    ];
}

/**
 * Son çalışmanın durumu.
 *
 * @return array{var:bool,durum:string,sonuc:string,baslangic:string,adres:string}
 */
function ajan_son_calisma(): array
{
    $anahtar = trim(ayar_oku(AJAN_GITHUB_ANAHTAR));
    $bos = ['var' => false, 'durum' => '', 'sonuc' => '', 'baslangic' => '', 'adres' => ''];

    if ($anahtar === '') {
        return $bos;
    }

    $adres = 'https://api.github.com/repos/' . ajan_depo()
           . '/actions/workflows/' . AJAN_WORKFLOW . '/runs?per_page=1';

    $ch = curl_init($adres);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $anahtar,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Valentra-Panel',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $yanit = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($yanit) || $kod !== 200) {
        return $bos;
    }

    $veri = json_decode($yanit, true);
    $son  = $veri['workflow_runs'][0] ?? null;

    if (!is_array($son)) {
        return $bos;
    }

    return [
        'var'       => true,
        'durum'     => (string) ($son['status'] ?? ''),
        'sonuc'     => (string) ($son['conclusion'] ?? ''),
        'baslangic' => (string) ($son['run_started_at'] ?? ''),
        'adres'     => guvenli_url((string) ($son['html_url'] ?? '')),
    ];
}
