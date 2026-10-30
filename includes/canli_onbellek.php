<?php
declare(strict_types=1);

/**
 * Canli veri onbellegi: puan durumu, piyasa grafikleri, sirket fiyatlari.
 *
 * Spor ve ekonomi sayfalarindaki canli bolumler (22.10.2026) veriyi
 * TFF'den ve Yahoo Finance'ten aliyor. Her ziyaretci icin kaynaga gitmek
 * hem yavas hem kaynagi yorar; bu yuzden:
 *
 * 1) Veri ayarlar tablosunda, zaman damgasiyla duruyor. Taze ise oradan.
 *
 * 2) Bayatsa ESKISI gosteriliyor ve yenisi YANIT GONDERILDIKTEN SONRA
 *    cekiliyor (fastcgi_finish_request). Ziyaretci kaynagin yavasligini
 *    hic beklemiyor; bir sonraki ziyaretci taze veriyi goruyor.
 *
 * 3) Hic veri yoksa (ilk acilis) hemen cekiliyor, ama istek basina
 *    toplam bir sure butcesiyle: sayfada on iki seri varsa ve kaynak
 *    yavassa sayfa dakikalarca beklememeli. Butce dolunca kalanlar
 *    yanittan sonra cekiliyor; o seriler o ziyarette bos gorunuyor.
 *
 * 4) Ayni seri icin dakikada bir denemeden fazlasi yapilmiyor: kaynak
 *    dusmusse her ziyaretci yeniden denemesin.
 */

require_once __DIR__ . '/ayarlar.php';

/** Bir istekte ilk acilis cekimlerine ayrilan sure (saniye). */
const CANLI_ESZAMANLI_BUTCE = 6.0;

/** Ayni anahtar icin iki deneme arasi en az bu kadar saniye. */
const CANLI_DENEME_ARALIGI = 60;

/**
 * Önbellekten okur; bayatsa yanıttan sonra tazeler, yoksa hemen üretir.
 *
 * @param callable():(?array) $uret  null donerse "alinamadi" sayilir
 * @return array{veri:?array,zaman:int} zaman: verinin cekildigi an (0: yok)
 */
function canli_onbellek(string $anahtar, int $sure, callable $uret): array
{
    $kayit = canli_onbellek_oku($anahtar);
    $simdi = time();

    /*
     * Zorla tazeleme (api/canli-tazele.php): sure ve butceye bakmadan
     * hemen cek. Ziyaretci gelmeyen saatlerde veri eskimesin diye ajan
     * duzenli olarak durtuyor. Cekim basarisizsa son iyi veri kalir.
     */
    if (canli_zorla()) {
        static $tazelenen = [];

        // Ayni istekte ikinci kez cekme (altin, ons ve doları yeniden istiyor).
        if (isset($tazelenen[$anahtar])) {
            return $kayit ?? ['veri' => null, 'zaman' => 0];
        }

        $tazelenen[$anahtar] = true;

        return canli_onbellek_uret($anahtar, $uret) ?? $kayit ?? ['veri' => null, 'zaman' => 0];
    }

    if ($kayit !== null && $simdi - $kayit['zaman'] < $sure) {
        return $kayit;
    }

    if (!canli_deneme_izni($anahtar)) {
        return $kayit ?? ['veri' => null, 'zaman' => 0];
    }

    if ($kayit === null && canli_butce_var()) {
        return canli_onbellek_uret($anahtar, $uret) ?? ['veri' => null, 'zaman' => 0];
    }

    canli_sonra_tazele($anahtar, $uret);

    return $kayit ?? ['veri' => null, 'zaman' => 0];
}

/**
 * Zorla tazeleme kipi: argumanla acilir/kapanir, argumansiz okunur.
 *
 * Yalnizca api/canli-tazele.php aciyor; sayfa isteklerinde hep kapali.
 */
function canli_zorla(?bool $ac = null): bool
{
    static $acik = false;

    if ($ac !== null) {
        $acik = $ac;
    }

    return $acik;
}

/**
 * Tazeleme sayaci: zorla kipte kac kayit alindi / alinamadi.
 *
 * @return array{tamam:int,hata:int}
 */
function canli_sayac(?string $sonuc = null): array
{
    static $sayac = ['tamam' => 0, 'hata' => 0];

    if ($sonuc !== null) {
        $sayac[$sonuc]++;
    }

    return $sayac;
}

/** @return array{veri:array,zaman:int}|null */
function canli_onbellek_oku(string $anahtar): ?array
{
    try {
        $ham = ayar_oku('canli_' . $anahtar);
    } catch (Throwable $e) {
        return null;
    }

    $kayit = $ham !== '' ? json_decode($ham, true) : null;

    if (!is_array($kayit) || !is_array($kayit['v'] ?? null)) {
        return null;
    }

    return ['veri' => $kayit['v'], 'zaman' => (int) ($kayit['t'] ?? 0)];
}

/** @return array{veri:array,zaman:int}|null */
function canli_onbellek_uret(string $anahtar, callable $uret): ?array
{
    try {
        $veri = $uret();
    } catch (Throwable $e) {
        error_log('[valentra] canli veri (' . $anahtar . ') alinamadi: ' . $e->getMessage());
        $veri = null;
    }

    if (!is_array($veri) || $veri === []) {
        if (canli_zorla()) {
            canli_sayac('hata');
        }

        return null;
    }

    if (canli_zorla()) {
        canli_sayac('tamam');
    }

    $kayit = ['veri' => $veri, 'zaman' => time()];

    try {
        ayar_yaz('canli_' . $anahtar, (string) json_encode(
            ['t' => $kayit['zaman'], 'v' => $veri],
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        ));
    } catch (Throwable $e) {
        error_log('[valentra] canli veri (' . $anahtar . ') yazilamadi: ' . $e->getMessage());
    }

    return $kayit;
}

/** Bu anahtar için deneme yapılabilir mi? Evetse denemeyi işaretler. */
function canli_deneme_izni(string $anahtar): bool
{
    static $buIstekte = [];

    if (isset($buIstekte[$anahtar])) {
        return false;
    }

    try {
        $son = (int) ayar_oku('canli_deneme_' . $anahtar, '0');

        if (time() - $son < CANLI_DENEME_ARALIGI) {
            return false;
        }

        // Once isaretle: yavas cekim surerken gelen ziyaretci ayni isi
        // ikinci kez baslatmasin.
        ayar_yaz('canli_deneme_' . $anahtar, (string) time());
    } catch (Throwable $e) {
        return false;
    }

    $buIstekte[$anahtar] = true;

    return true;
}

/** İlk açılış çekimleri için süre kaldı mı? */
function canli_butce_var(): bool
{
    $baslangic = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

    return microtime(true) - $baslangic < CANLI_ESZAMANLI_BUTCE;
}

/** Yanıt gönderildikten sonra çalışacak tazelemeyi sıraya koyar. */
function canli_sonra_tazele(string $anahtar, callable $uret): void
{
    static $kuyruk = null;

    if ($kuyruk === null) {
        $kuyruk = new ArrayObject();
        $liste  = $kuyruk;

        register_shutdown_function(static function () use ($liste): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            ignore_user_abort(true);
            @set_time_limit(60);

            foreach ($liste as [$ad, $is]) {
                canli_onbellek_uret($ad, $is);
            }
        });
    }

    $kuyruk[] = [$anahtar, $uret];
}

/** Kısa zaman aşımıyla indirir; başarısızsa null. */
function canli_indir(string $url, int $zamanAsimi = 8): ?string
{
    require_once __DIR__ . '/http_ortak.php';

    $sonuc = http_getir($url, $zamanAsimi);

    return $sonuc['tamam'] ? (string) $sonuc['govde'] : null;
}

/**
 * Birden çok adresi AYNI ANDA indirir (curl_multi); sıra korunur.
 * Ayarlar canli_indir ile ayni (http_ortak_secenekler).
 *
 * @param array<string,string> $adresler anahtar => adres
 * @return array<string,?string> anahtar => govde (basarisizsa null)
 */
function canli_coklu_indir(array $adresler, int $zamanAsimi = 8): array
{
    require_once __DIR__ . '/http_ortak.php';

    $coklu   = curl_multi_init();
    $tutamac = [];

    foreach ($adresler as $anahtar => $adres) {
        $ch = curl_init($adres);
        curl_setopt_array($ch, http_ortak_secenekler($zamanAsimi));
        curl_multi_add_handle($coklu, $ch);
        $tutamac[$anahtar] = $ch;
    }

    do {
        $durum = curl_multi_exec($coklu, $suren);

        if ($suren > 0) {
            curl_multi_select($coklu, 1.0);
        }
    } while ($suren > 0 && $durum === CURLM_OK);

    $sonuc = [];

    foreach ($tutamac as $anahtar => $ch) {
        $govde = curl_multi_getcontent($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $sonuc[$anahtar] = is_string($govde) && $govde !== '' && $kod >= 200 && $kod < 300 ? $govde : null;
        curl_multi_remove_handle($coklu, $ch);
        curl_close($ch);
    }

    curl_multi_close($coklu);

    return $sonuc;
}

/** "13:05" ya da "22 Eki 13:05": verinin ne zaman çekildiği. */
function canli_zaman_yazi(int $zaman): string
{
    if ($zaman <= 0) {
        return '';
    }

    $aylar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

    return date('Y-m-d', $zaman) === date('Y-m-d')
        ? date('H:i', $zaman)
        : (int) date('j', $zaman) . ' ' . $aylar[(int) date('n', $zaman) - 1] . ' ' . date('H:i', $zaman);
}
