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
function ajan_tetikle(bool $kuru = false, int $saat = 5, int $enFazla = 25, string $mod = 'topla', string $konu = ''): array
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
            'mod'     => in_array($mod, ['gunluk', 'kaynak-testi', 'kanun-testi', 'pratik-bilgiler', 'kose-yazisi'], true)
                ? $mod
                : 'topla',
            'kuru'    => $kuru ? 'true' : 'false',
            'saat'    => (string) max(1, $saat),
            // 0: ajanin varsayilani (baslik paylarinin toplami ya da
            // secili baslik basina 20).
            'enfazla' => $enFazla > 0 ? (string) $enFazla : '',
            // Yalnizca bir basligi toplamak icin (panel, 22.10.2026).
            'konu'    => in_array($konu, HABER_AKIS_KATEGORILERI, true) ? $konu : '',
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

        if ($mod === 'pratik-bilgiler') {
            return [
                'tamam' => true,
                'mesaj' => 'Pratik bilgiler toplanıyor. Değerler onayınıza '
                         . 'düşecek; onaylamadığınız hiçbir değer sitede '
                         . 'görünmez.',
            ];
        }

        if ($mod === 'kose-yazisi') {
            return [
                'tamam' => true,
                'mesaj' => 'Köşe yazıları yazılıyor. Birkaç dakika içinde '
                         . 'onay bekleyen yazılar listesinde görünecek; '
                         . 'onaylamadığınız hiçbir yazı sitede görünmez.',
            ];
        }

        if ($mod === 'gunluk') {
            return [
                'tamam' => true,
                'mesaj' => 'Ajan çalışmaya başladı: önce haberler toplanacak, '
                         . 'ardından günün köşe yazıları yazılacak. Birkaç '
                         . 'dakika içinde onay bekleyen listelerde görünürler.',
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
 * @return array{var:bool,id:int,durum:string,sonuc:string,baslangic:string,adres:string}
 */
function ajan_son_calisma(): array
{
    $anahtar = trim(ayar_oku(AJAN_GITHUB_ANAHTAR));
    $bos = ['var' => false, 'id' => 0, 'durum' => '', 'sonuc' => '', 'baslangic' => '', 'adres' => ''];

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
        'id'        => (int) ($son['id'] ?? 0),
        'durum'     => (string) ($son['status'] ?? ''),
        'sonuc'     => (string) ($son['conclusion'] ?? ''),
        'baslangic' => (string) ($son['run_started_at'] ?? ''),
        'adres'     => guvenli_url((string) ($son['html_url'] ?? '')),
    ];
}

/** GitHub API'den JSON okur; olmazsa null. */
function ajan_github_json(string $adres): ?array
{
    $anahtar = trim(ayar_oku(AJAN_GITHUB_ANAHTAR));

    if ($anahtar === '' || !str_starts_with($adres, 'https://api.github.com/')) {
        return null;
    }

    $ch = curl_init($adres);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $anahtar,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Valentra-Panel',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);

    $yanit = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($yanit) || $kod !== 200) {
        return null;
    }

    $veri = json_decode($yanit, true);

    return is_array($veri) ? $veri : null;
}

/**
 * Bir ajan çalışmasının bildirdiği sorunlar (iş akışının "Sorunları
 * bildir" adımının yazdığı hata satırları).
 *
 * Ajan siteye ulaşamadığında nedeni siteye yazamıyor; ama site GitHub'a
 * ulaşabiliyor. Neden buradan okunuyor. Anahtarın "Checks" okuma izni
 * yoksa boş döner; panel o zaman yalnızca Actions bağlantısını gösterir.
 *
 * @return list<string>
 */
function ajan_calisma_sorunlari(int $calismaId): array
{
    if ($calismaId <= 0) {
        return [];
    }

    $isler = ajan_github_json('https://api.github.com/repos/' . ajan_depo()
        . '/actions/runs/' . $calismaId . '/jobs?per_page=5');

    $sorunlar = [];

    foreach ($isler['jobs'] ?? [] as $is) {
        $checkRun = (string) ($is['check_run_url'] ?? '');

        if ($checkRun === '') {
            continue;
        }

        $notlar = ajan_github_json($checkRun . '/annotations?per_page=50') ?? [];

        foreach ($notlar as $not) {
            if (($not['annotation_level'] ?? '') !== 'failure'
                || ($not['title'] ?? '') !== 'Valentra ajanı') {
                continue;
            }

            $mesaj = trim((string) ($not['message'] ?? ''));

            if ($mesaj !== '' && !in_array($mesaj, $sorunlar, true)) {
                $sorunlar[] = $mesaj;
            }
        }
    }

    return $sorunlar;
}

/**
 * Ajandan siteye en son ne zaman haber geldi (saat). Hiç gelmediyse null.
 */
function ajan_son_gonderimden_beri_saat(): ?int
{
    $saat = db()->query(
        'SELECT TIMESTAMPDIFF(HOUR, MAX(baslangic), NOW()) FROM ajan_kayitlari'
    )->fetchColumn();

    return $saat === null || $saat === false ? null : (int) $saat;
}

/**
 * Ajan zamanlı olarak günde 5 kez çalışıyor; en uzun ara gece 8 saat.
 * Bundan uzun süre hiç haber gelmediyse bir şey ters gidiyor.
 */
const AJAN_SESSIZLIK_ESIGI_SAAT = 10;

/**
 * Panelde gösterilecek ajan uyarısı: neden ve ne yapmalı.
 *
 * İki ayrı işaret birleştiriliyor; biri tek başına yetmiyor:
 *   - GitHub'daki son çalışma başarısızsa: nedeni oradan okunuyor.
 *   - Sitede uzun süredir yeni ajan kaydı yoksa: GitHub'a da
 *     ulaşılamasa (anahtar girilmemiş, süresi dolmuş) fark ediliyor.
 *
 * @param array{var:bool,id?:int,durum?:string,sonuc?:string,adres?:string} $sonCalisma
 * @return array{baslik:string,satirlar:list<string>,adres:string}|null
 */
function ajan_uyarisi(array $sonCalisma): ?array
{
    $satirlar = [];
    $baslik   = '';

    $basarisiz = $sonCalisma['var']
        && ($sonCalisma['durum'] ?? '') === 'completed'
        && in_array($sonCalisma['sonuc'] ?? '', ['failure', 'timed_out', 'startup_failure'], true);

    if ($basarisiz) {
        $baslik   = 'Son ajan çalışmasında bir sorun var.';
        $satirlar = ajan_calisma_sorunlari((int) ($sonCalisma['id'] ?? 0));

        if ($satirlar === []) {
            $satirlar[] = 'Nedeni panelden okunamadı; "Ayrıntı" bağlantısından GitHub\'daki çalışma kaydına bakın.';
        }
    }

    $saat = ajan_son_gonderimden_beri_saat();

    if ($saat !== null && $saat >= AJAN_SESSIZLIK_ESIGI_SAAT) {
        $baslik = $baslik !== '' ? $baslik : 'Ajandan bir süredir haber gelmiyor.';
        array_unshift($satirlar, 'Ajandan siteye ' . $saat . ' saattir hiç haber ulaşmadı '
            . '(normalde günde 5 kez gelir).');
    }

    if ($satirlar === []) {
        return null;
    }

    return [
        'baslik'   => $baslik,
        'satirlar' => $satirlar,
        'adres'    => (string) ($sonCalisma['adres'] ?? ''),
    ];
}

/** ajan_uyarisi() sonucunu panel kutusu olarak basar. */
function ajan_uyarisi_goster(?array $uyari): void
{
    if ($uyari === null) {
        return;
    }
    ?>
    <div class="uyari uyari-hata" style="margin:10px 0;">
        <strong><?= e($uyari['baslik']) ?></strong>
        <ul style="margin:6px 0 0;padding-left:18px;">
            <?php foreach ($uyari['satirlar'] as $satir): ?>
                <li><?= e($satir) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($uyari['adres'] !== ''): ?>
            <p style="margin:6px 0 0;"><a href="<?= e($uyari['adres']) ?>" target="_blank" rel="noopener">Ayrıntı (GitHub)</a></p>
        <?php endif; ?>
    </div>
    <?php
}
