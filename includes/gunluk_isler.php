<?php
declare(strict_types=1);

/**
 * Panel -> Gunluk isler: her gun yapilan isler tek yerden.
 *
 * Onceden bu isler bes ayri sayfaya dagilmisti (ajan, kose yazilari,
 * grafikler, Google gorunurlugu...). Burada her is tek bir anahtarla
 * calistiriliyor ve ayni bicimde sonuc donduruyor; sayfa bunlari tek
 * tek ya da "hepsini sirayla" calistiriyor. Her is AYRI bir istekte
 * kosuyor: paylasimli hostingin zaman asimi bir isin digerini
 * yarida kesmesine yol acmasin.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/ajan_tetikle.php';
require_once __DIR__ . '/ajan_teslim.php';

/**
 * Sayfada gorunen isler, gosterim sirasiyla. "Hepsini sirayla" veri ve
 * arama gruplarini, ajandan da yalnizca 'ajan'i calistiriyor: ajan ayni
 * anda tek calisma kabul ediyor.
 *
 * @return array<string, array{grup:string, ad:string, aciklama:string}>
 */
function gunluk_isler(): array
{
    return [
        'piyasa'   => ['grup' => 'veri',  'ad' => 'Kurlar ve borsa',
                       'aciklama' => 'Dolar, euro ve BIST verisini şimdi yeniden çeker (normalde 2 dakikada bir kendiliğinden).'],
        // Grafikler, Resmi Gazete ve pratik bilgiler listeden cikti
        // (22.10.2026, site sahibinin karari: ajan yalnizca haber ve
        // kose yazisi uretiyor; ana sayfada grafik yok). Islevleri
        // asagida duruyor, geri almak icin satirlari eklemek yeterli.
        'gsc'      => ['grup' => 'arama', 'ad' => 'Search Console verisi',
                       'aciklama' => 'Google\'daki tıklama, gösterim ve dizin durumunu günceller.'],
        'indexnow' => ['grup' => 'arama', 'ad' => 'Bing ve Yandex\'e bildir',
                       'aciklama' => 'Sitenin tüm adreslerini IndexNow ile gönderir.'],
        'harita'   => ['grup' => 'arama', 'ad' => 'Site haritasını Google\'a gönder',
                       'aciklama' => 'Google\'a site haritasını yeniden okumasını söyler.'],
        'teslim' => ['grup' => 'ajan', 'ad' => 'GitHub\'da bekleyen haberleri al',
                          'aciklama' => 'Ajan siteye ulaşamadığında yazdığı haberleri GitHub\'da bırakır; site onları buradan alır. Ziyaretçi geldikçe yarım saatte bir kendiliğinden de yapılır.'],
        'ajan'     => ['grup' => 'ajan',  'ad' => 'Haberleri topla ve köşe yazılarını yaz',
                       'aciklama' => 'Ajan önce haberleri toplar, sonra en önemli haberlerden günün köşe yazılarını yazar. Hepsi onayınıza düşer.'],
        'ajan_haber'  => ['grup' => 'ajan', 'ad' => 'Yalnızca haberleri topla',
                          'aciklama' => 'Kaynakları tarar, yeni haberleri onay bekleyenlere ekler.'],
        'ajan_kose'   => ['grup' => 'ajan', 'ad' => 'Yalnızca köşe yazılarını yaz',
                          'aciklama' => 'Günün köşe yazılarını yazar (günlük tavan dolmuşsa yazmaz).'],
    ];
}

/**
 * Bir isi calistirir.
 *
 * @return array{tamam:bool, mesaj:string}
 */
function gunluk_is_calistir(string $is): array
{
    try {
        return match ($is) {
            'piyasa'      => gunluk_piyasa(),
            'gsc'         => gunluk_gsc(),
            'indexnow'    => gunluk_indexnow(),
            'harita'      => gunluk_harita(),
            'teslim' => ajan_teslim_al(),
            'ajan'        => gunluk_ajan('gunluk'),
            'ajan_haber'  => gunluk_ajan('topla'),
            'ajan_kose'   => gunluk_ajan('kose-yazisi'),
            default       => ['tamam' => false, 'mesaj' => 'Bilinmeyen iş.'],
        };
    } catch (Throwable $e) {
        error_log('[valentra] gunluk is ' . $is . ': ' . $e->getMessage());

        return ['tamam' => false, 'mesaj' => 'Beklenmeyen hata: ' . $e->getMessage()];
    }
}

/** @return array{tamam:bool, mesaj:string} */
function gunluk_piyasa(): array
{
    require_once __DIR__ . '/piyasa.php';

    @set_time_limit(60);
    $v = piyasa_verisi(true);

    // Hic onbellek yokken cekim basarisizsa piyasa_verisi bos degerleri
    // "tazelendi" olarak donduruyor; bu da basarisizlik.
    if (!$v['tazelendi'] || ($v['usd'] === null && $v['bist'] === null)) {
        return ['tamam' => false, 'mesaj' => 'Kaynaklardan yeni veri alınamadı; son iyi veri gösterilmeye devam ediyor.'];
    }

    $sayi = static fn (?float $x, int $ondalik): string => $x === null ? '—' : number_format($x, $ondalik, ',', '.');

    return [
        'tamam' => true,
        'mesaj' => 'Dolar ' . $sayi($v['usd'], 4) . ' · Euro ' . $sayi($v['eur'], 4)
                 . ' · BIST ' . $sayi($v['bist'], 0)
                 . ($v['kaynak'] !== '' ? ' (kaynak: ' . $v['kaynak'] . ')' : ''),
    ];
}

/** @return array{tamam:bool, mesaj:string} */
function gunluk_grafik(): array
{
    require_once __DIR__ . '/grafikler.php';

    @set_time_limit(90);
    $o = grafik_bayatlari_tazele(3, 8, 40);

    if ($o['tazelenen'] === 0 && $o['basarisiz'] === 0) {
        return ['tamam' => true, 'mesaj' => 'Grafikler zaten güncel.'];
    }

    $mesaj = $o['tazelenen'] . ' grafik tazelendi';

    if ($o['basarisiz'] > 0) {
        $mesaj .= ', ' . $o['basarisiz'] . ' grafik alınamadı (' . implode('; ', array_slice($o['hatalar'], 0, 2)) . ')';
    }

    return ['tamam' => $o['basarisiz'] === 0, 'mesaj' => $mesaj . '.'];
}

/** @return array{tamam:bool, mesaj:string} */
function gunluk_rg(): array
{
    require_once __DIR__ . '/resmi_gazete.php';

    @set_time_limit(90);
    $o = rg_tazele(2, 15);

    if ($o['gunler'] === 0) {
        return ['tamam' => false, 'mesaj' => 'Resmî Gazete okunamadı' . ($o['hatalar'] !== [] ? ': ' . $o['hatalar'][0] : '.')];
    }

    return ['tamam' => true, 'mesaj' => $o['gunler'] . ' gün, ' . $o['madde'] . ' madde güncellendi.'];
}

/** @return array{tamam:bool, mesaj:string} */
function gunluk_gsc(): array
{
    require_once __DIR__ . '/search_console.php';

    if (gsc_hesap() === null) {
        return ['tamam' => false, 'mesaj' => 'Search Console bağlı değil (Arama motorları → Google görünürlüğü).'];
    }

    @set_time_limit(90);
    $o = gsc_tazele(60);

    if (!$o['tamam']) {
        return ['tamam' => false, 'mesaj' => 'Alınamadı: ' . implode('; ', array_slice($o['hatalar'], 0, 2))];
    }

    return ['tamam' => true, 'mesaj' => $o['sorgu'] . ' arama, ' . $o['sayfa'] . ' sayfa, ' . $o['denetlenen'] . ' adresin dizin durumu güncellendi.'];
}

/** @return array{tamam:bool, mesaj:string} */
function gunluk_indexnow(): array
{
    require_once __DIR__ . '/arama_bildirim.php';

    @set_time_limit(60);
    $adresler = arama_tum_adresler();
    $o = indexnow_gonder($adresler);
    ayar_yaz('indexnow_son', (string) json_encode($o, JSON_UNESCAPED_UNICODE));

    return $o['tamam']
        ? ['tamam' => true,  'mesaj' => count($adresler) . ' adres Bing ve Yandex\'e bildirildi.']
        : ['tamam' => false, 'mesaj' => $o['hata']];
}

/** @return array{tamam:bool, mesaj:string} */
function gunluk_harita(): array
{
    require_once __DIR__ . '/search_console.php';

    if (gsc_hesap() === null) {
        return ['tamam' => false, 'mesaj' => 'Search Console bağlı değil (Arama motorları → Google görünürlüğü).'];
    }

    $o = gsc_site_haritasi_gonder();
    ayar_yaz('gsc_harita_son_deneme', (string) time());
    ayar_yaz('gsc_harita_son', (string) json_encode($o, JSON_UNESCAPED_UNICODE));

    return $o['tamam']
        ? ['tamam' => true,  'mesaj' => 'Site haritası Google\'a yeniden gönderildi.']
        : ['tamam' => false, 'mesaj' => $o['hata']];
}

/**
 * Ajani GitHub'da baslatir. Suren bir calisma varken gondermiyor:
 * GitHub sirada tek calisma tutuyor ve yeni istek bekleyeni iptal
 * ediyor (bkz. admin/ajan.php).
 *
 * @return array{tamam:bool, mesaj:string}
 */
function gunluk_ajan(string $mod): array
{
    if (!ajan_tetikleyebilir_mi()) {
        return ['tamam' => false, 'mesaj' => 'GitHub anahtarı tanımlı değil (Ajan ve aktarım → Ajan).'];
    }

    $suren = ajan_son_calisma();

    if ($suren['var'] && $suren['durum'] !== 'completed') {
        return ['tamam' => false, 'mesaj' => 'Ajan zaten çalışıyor; bitince yeniden deneyin. Şimdi göndermek süreni iptal ederdi.'];
    }

    // Haber toplama son 5 saat (bkz. ajan/topla.php); kose yazisi bunu kullanmiyor.
    return ajan_tetikle(false, 5, 0, $mod);
}

/**
 * Onay bekleyen islerin sayilari (ust kutular icin).
 *
 * @return array{haber:int, kose:int, pratik:int}
 */
function gunluk_bekleyenler(): array
{
    $sayilar = ['haber' => (int) (haber_durum_sayilari()[HABER_TASLAK] ?? 0), 'kose' => 0, 'pratik' => 0];

    try {
        require_once __DIR__ . '/kose.php';
        $sayilar['kose'] = (int) (kose_durum_sayilari()[HABER_TASLAK] ?? 0);
    } catch (Throwable $e) {
        // tablo yoksa 0
    }

    try {
        require_once __DIR__ . '/pratik.php';
        $sayilar['pratik'] = pratik_bekleyen_sayisi();
    } catch (Throwable $e) {
        // tablo yoksa 0
    }

    return $sayilar;
}

/**
 * Isin son calismasi, kisa bir satir olarak ("29 Eylül 22:51 · başarılı").
 * Bilinmiyorsa bos.
 */
function gunluk_son_durum(string $is): string
{
    $zamanli = static function (string $zaman, ?bool $tamam = null, string $ek = ''): string {
        if ($zaman === '') {
            return '';
        }

        return 'Son: ' . tarih_bicimle($zaman)
             . ($tamam === null ? '' : ($tamam ? ' · başarılı' : ' · başarısız'))
             . ($ek !== '' ? ' · ' . $ek : '');
    };
    $damga = static fn (string $saniye): string => (int) $saniye > 0 ? date('Y-m-d H:i:s', (int) $saniye) : '';
    $json  = static function (string $ad) use ($zamanli): string {
        $r = json_decode(ayar_oku($ad), true);

        return is_array($r) ? $zamanli((string) ($r['zaman'] ?? ''), (bool) ($r['tamam'] ?? false)) : '';
    };

    return match ($is) {
        'piyasa'   => (static function () use ($zamanli): string {
            require_once __DIR__ . '/piyasa.php';
            $v = piyasa_onbellekten(true);

            return $v !== null ? $zamanli($v['zaman']) : '';
        })(),
        'rg'       => $zamanli($damga(ayar_oku('rg_son_cekim', '0'))),
        'gsc'      => $zamanli(ayar_oku('gsc_son_tazeleme')),
        'indexnow' => $json('indexnow_son'),
        'harita'   => $json('gsc_harita_son'),
        'teslim' => $json(AJAN_TESLIM_SON),
        default    => '',
    };
}
