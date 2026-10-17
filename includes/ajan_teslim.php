<?php
declare(strict_types=1);

/**
 * Ajanın GitHub'a bıraktığı haberleri sitenin kendisinin alması.
 *
 * NEDEN: GitHub'dan siteye bağlantı (IHS 443) günlerce hiç kurulamadı;
 * 16 Ekim'den itibaren her çalışmada zaman aşımı. Ajan haberleri yazıyor
 * ama gönderemiyordu. Ters yön ise çalışıyor: site GitHub API'ye
 * ulaşıyor (panel ajan durumunu zaten oradan okuyor).
 *
 * Ajan gönderemediğinde bekleyen haberlerin tamamını çalışmanın
 * "valentra-teslim" eserine (artifact) yazıyor. Site en yenisini indirip
 * ingest ucuyla AYNI yoldan (haber_toplu_taslak_ekle) taslak olarak
 * kaydediyor. Her eser o ana kadar bekleyen haberlerin tamamını taşıdığı
 * için yalnızca en yenisi yeter; kopya denetimi daha önce alınanları
 * "yinelenen" sayıp atlıyor.
 *
 * Hiçbir haber yayına alınmaz; onay yine panelde.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/ajan_tetikle.php';
require_once __DIR__ . '/haberler.php';

const AJAN_TESLIM_ESER      = 'valentra-teslim';
const AJAN_TESLIM_DOSYA     = 'haberler.json';
const AJAN_TESLIM_SON_ID    = 'ajan_teslim_son_id';
const AJAN_TESLIM_SON       = 'ajan_teslim_son';
const AJAN_TESLIM_EN_BUYUK  = 10 * 1024 * 1024;

/** Ziyaretçi tetiklemeli denemeler arası en az süre (saniye). */
const AJAN_TESLIM_DENEME_ARALIGI = 1800;

/**
 * GitHub'daki en yeni teslimi alır.
 *
 * @return array{tamam:bool, mesaj:string}
 */
function ajan_teslim_al(): array
{
    $sonuc = ajan_teslim_al_ic();

    ayar_yaz(AJAN_TESLIM_SON, (string) json_encode([
        'zaman' => date('Y-m-d H:i:s'),
        'tamam' => $sonuc['tamam'],
        'mesaj' => $sonuc['mesaj'],
    ], JSON_UNESCAPED_UNICODE));

    return $sonuc;
}

/** @return array{tamam:bool, mesaj:string} */
function ajan_teslim_al_ic(): array
{
    if (!ajan_tetikleyebilir_mi()) {
        return ['tamam' => false, 'mesaj' => 'GitHub anahtarı tanımlı değil (Ajan ve aktarım → Ajan).'];
    }

    if (!class_exists('ZipArchive')) {
        return ['tamam' => false, 'mesaj' => 'Sunucuda PHP zip eklentisi yok; hosting panelinden "zip" eklentisini açın.'];
    }

    $liste = ajan_github_json('https://api.github.com/repos/' . ajan_depo()
        . '/actions/artifacts?name=' . AJAN_TESLIM_ESER . '&per_page=1');

    if ($liste === null) {
        return ['tamam' => false, 'mesaj' => 'GitHub\'a ulaşılamadı ya da anahtarın Actions okuma izni yok.'];
    }

    $eser = $liste['artifacts'][0] ?? null;

    if (!is_array($eser) || ($eser['expired'] ?? false)) {
        return ['tamam' => true, 'mesaj' => 'GitHub\'da bekleyen haber yok.'];
    }

    $id = (int) ($eser['id'] ?? 0);

    if ($id <= (int) ayar_oku(AJAN_TESLIM_SON_ID, '0')) {
        return ['tamam' => true, 'mesaj' => 'Yeni teslim yok; son teslim zaten alındı.'];
    }

    if ((int) ($eser['size_in_bytes'] ?? 0) > AJAN_TESLIM_EN_BUYUK) {
        return ['tamam' => false, 'mesaj' => 'Teslim beklenenden büyük; alınmadı.'];
    }

    $haberler = ajan_teslim_indir((string) ($eser['archive_download_url'] ?? ''));

    if ($haberler === null) {
        return ['tamam' => false, 'mesaj' => 'Teslim GitHub\'dan indirilemedi; birazdan yeniden deneyin.'];
    }

    $sonuc = haber_toplu_taslak_ekle($haberler);

    // Hatali kayit olsa da isaretleniyor: ayni eseri tekrar tekrar
    // indirmek ayni hatayi verir. Sonraki teslim yine hepsini tasiyor.
    ayar_yaz(AJAN_TESLIM_SON_ID, (string) $id);

    return [
        'tamam' => true,
        'mesaj' => $sonuc['eklenen'] . ' yeni haber onay bekleyenlere eklendi'
                 . ($sonuc['yinelenen'] > 0 ? ', ' . $sonuc['yinelenen'] . ' zaten vardı' : '')
                 . ($sonuc['hatalar'] !== [] ? ', ' . count($sonuc['hatalar']) . ' kayıt alınamadı' : '')
                 . '.',
    ];
}

/**
 * Eseri indirir ve içindeki haber listesini döndürür.
 *
 * GitHub indirme adresi kısa ömürlü bir depolama adresine yönlendiriyor;
 * curl yetki başlığını başka sunucuya taşımıyor (CURLOPT_UNRESTRICTED_AUTH
 * kapalı), yani anahtar yalnızca GitHub'a gidiyor.
 *
 * @return list<mixed>|null
 */
function ajan_teslim_indir(string $adres): ?array
{
    if (!str_starts_with($adres, 'https://api.github.com/')) {
        return null;
    }

    $gecici = tempnam(sys_get_temp_dir(), 'vtes');

    if ($gecici === false) {
        return null;
    }

    try {
        $dosya = fopen($gecici, 'wb');

        if ($dosya === false) {
            return null;
        }

        $ch = curl_init($adres);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $dosya,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . trim(ayar_oku(AJAN_GITHUB_ANAHTAR)),
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: Valentra-Panel',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_MAXFILESIZE    => AJAN_TESLIM_EN_BUYUK,
        ]);

        $tamam = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($dosya);

        if ($tamam !== true || $kod !== 200) {
            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($gecici) !== true) {
            return null;
        }

        $json = $zip->getFromName(AJAN_TESLIM_DOSYA);
        $zip->close();

        if (!is_string($json) || strlen($json) > AJAN_TESLIM_EN_BUYUK) {
            return null;
        }

        $veri = json_decode($json, true);

        return is_array($veri) && array_is_list($veri) ? $veri : null;
    } finally {
        @unlink($gecici);
    }
}

/**
 * Sayfa açılışında, YANIT GÖNDERİLDİKTEN SONRA teslimi yoklar.
 *
 * Yönetici paneli açmasa da haberler beklemesin diye: ziyaretçi geldikçe
 * en fazla yarım saatte bir. Ziyaretçi beklemez (grafik_sayfa_sonunda_tazele
 * ile aynı yöntem); yanıtı erken bitiremeyen sunucuda hiçbir şey yapmaz.
 * ASLA HATA FIRLATMAZ.
 */
function ajan_teslim_sayfa_sonunda(): void
{
    $bitir = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
           : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);

    if ($bitir === null) {
        return;
    }

    register_shutdown_function(static function () use ($bitir): void {
        try {
            if (!ajan_tetikleyebilir_mi()
                || time() - (int) ayar_oku('ajan_teslim_son_deneme', '0') < AJAN_TESLIM_DENEME_ARALIGI) {
                return;
            }

            ayar_yaz('ajan_teslim_son_deneme', (string) time());

            ignore_user_abort(true);

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $bitir();
            @set_time_limit(90);

            $sonuc = ajan_teslim_al();

            if (!$sonuc['tamam']) {
                error_log('[valentra] ajan teslimi (sayfa): ' . $sonuc['mesaj']);
            }
        } catch (Throwable $e) {
            error_log('[valentra] ajan teslimi (sayfa) düştü: ' . $e->getMessage());
        }
    });
}
