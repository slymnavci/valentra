<?php
declare(strict_types=1);

/**
 * Google tam denetimi.
 *
 * Site sahibinin istegi (28.10.2026): "Google'a baglanip tum testleri
 * yap; Google ile ilgili hicbir eksik kalmadigina emin olmaliyiz".
 *
 * Search Console servis hesabi anahtari yalnizca sitenin veritabaninda
 * duruyor (depoda ve gelistirme ortaminda yok). Bu yuzden denetimi site
 * KENDISI calistiriyor: Google API'lerine anahtarla baglaniyor, kendi
 * sayfalarini da Googlebot'un gorecegi gibi indirip inceliyor. Sonuc
 * panelde (Arama motorlari -> Google gorunurlugu -> Tam denetim) ve
 * ajan anahtariyla api/google-denetim.php'den okunabiliyor; GitHub'daki
 * "Google denetimi" is akisi bunu cagirip kayda yaziyor.
 *
 * Her madde: ['grup', 'ad', 'durum' (tamam|uyari|hata|bilgi), 'ayrinti',
 * 'oneri']. Bir madde dusse de digerleri calisiyor.
 *
 * Yan etki: Search Console'a gonderilmemis site haritasi varsa
 * gonderiliyor (eksik kalmasin diye; zararsiz ve geri alinabilir).
 */

require_once __DIR__ . '/search_console.php';
require_once __DIR__ . '/arama_bildirim.php';

/** Googlebot'un tarayıcı kimliği (sayfaları onun gibi indirmek için). */
const GD_GOOGLEBOT = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 '
                   . '(KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 '
                   . '(compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

/**
 * Bir adresi indirir; yönlendirme isteğe bağlı izlenir.
 *
 * @return array{kod:int,govde:string,basliklar:array<string,string>,son_url:string,yonlendirme:string,sure:float,hata:string}
 */
function gd_getir(string $url, bool $takip = true, string $ua = GD_GOOGLEBOT, int $zamanAsimi = 20): array
{
    $basliklar = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, http_ortak_secenekler($zamanAsimi));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $takip);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($c, string $satir) use (&$basliklar): int {
        $p = explode(':', $satir, 2);

        if (count($p) === 2) {
            $basliklar[strtolower(trim($p[0]))] = trim($p[1]);
        }

        return strlen($satir);
    });

    $govde = curl_exec($ch);
    $sonuc = [
        'kod'         => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'govde'       => is_string($govde) ? $govde : '',
        'basliklar'   => $basliklar,
        'son_url'     => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'yonlendirme' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        'sure'        => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2),
        'hata'        => is_string($govde) ? '' : http_hata_acikla(curl_errno($ch), curl_error($ch)),
    ];
    curl_close($ch);

    return $sonuc;
}

/**
 * Birden cok adresi ayni anda indirir (Googlebot kimligiyla, yonlendirme
 * izlenerek) ve her yaniti GELDIGI ANDA $isle'ye verir; govdeler
 * saklanmiyor (binlerce haber sayfasinin govdesi bellege sigmiyordu:
 * 28.10.2026'da uc HTTP 500 dondu). Sure butcesi dolunca kalanlar
 * atlanir; islenen adres sayisini dondurur.
 *
 * @param list<string> $adresler
 * @param callable(string, array{kod:int,govde:string,basliklar:array<string,string>,sure:float,hata:string}): void $isle
 */
function gd_toplu_getir(array $adresler, int $esZaman, int $butceSn, callable $isle): int
{
    $bitis  = microtime(true) + $butceSn;
    $islenen = 0;
    $coklu  = curl_multi_init();
    $kuyruk = $adresler;
    $acik   = [];

    $ekle = static function (string $url) use ($coklu, &$acik): void {
        $ch = curl_init($url);
        curl_setopt_array($ch, http_ortak_secenekler(20));
        curl_setopt($ch, CURLOPT_USERAGENT, GD_GOOGLEBOT);
        $basliklar = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($c, string $satir) use (&$basliklar): int {
            $p = explode(':', $satir, 2);

            if (count($p) === 2) {
                $basliklar[strtolower(trim($p[0]))] = trim($p[1]);
            }

            return strlen($satir);
        });
        curl_multi_add_handle($coklu, $ch);
        $acik[(int) $ch] = ['ch' => $ch, 'url' => $url, 'basliklar' => &$basliklar];
    };

    while (($kuyruk !== [] || $acik !== []) && microtime(true) < $bitis) {
        while (count($acik) < $esZaman && $kuyruk !== []) {
            $ekle(array_shift($kuyruk));
        }

        curl_multi_exec($coklu, $calisan);
        curl_multi_select($coklu, 0.5);

        while ($bilgi = curl_multi_info_read($coklu)) {
            $ch = $bilgi['handle'];
            $k  = $acik[(int) $ch];
            $govde = curl_multi_getcontent($ch);
            $isle($k['url'], [
                'kod'       => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'govde'     => is_string($govde) ? $govde : '',
                'basliklar' => $k['basliklar'],
                'sure'      => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2),
                'hata'      => $bilgi['result'] === CURLE_OK ? '' : http_hata_acikla((int) $bilgi['result'], curl_error($ch)),
            ]);
            $islenen++;
            curl_multi_remove_handle($coklu, $ch);
            curl_close($ch);
            unset($acik[(int) $ch]);
        }
    }

    foreach ($acik as $k) {
        curl_multi_remove_handle($coklu, $k['ch']);
        curl_close($k['ch']);
    }

    curl_multi_close($coklu);

    return $islenen;
}

/** Raporda kisa gosterim: alan adi olmadan yol ve sorgu. */
function gd_kisa_adres(string $adres): string
{
    $sorgu = (string) parse_url($adres, PHP_URL_QUERY);

    return rawurldecode((string) (parse_url($adres, PHP_URL_PATH) ?: '/')) . ($sorgu !== '' ? '?' . $sorgu : '');
}

/** Sayfanın <head> bilgileri: robots, canonical, başlık, açıklama, og, JSON-LD. */
function gd_sayfa_coz(string $html): array
{
    $bilgi = ['baslik' => '', 'aciklama' => '', 'robots' => '', 'kanonik' => '', 'og_gorsel' => '', 'semalar' => []];

    if (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
        $bilgi['baslik'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    foreach ([['aciklama', 'name', 'description'], ['robots', 'name', 'robots'], ['og_gorsel', 'property', 'og:image']] as [$alan, $tur, $ad]) {
        if (preg_match('#<meta\s+' . $tur . '="' . preg_quote($ad, '#') . '"\s+content="([^"]*)"#i', $html, $m)) {
            $bilgi[$alan] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    if (preg_match('#<link\s+rel="canonical"\s+href="([^"]*)"#i', $html, $m)) {
        $bilgi['kanonik'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#is', $html, $m);

    foreach ($m[1] as $json) {
        $veri = json_decode(trim($json), true);
        $bilgi['semalar'][] = is_array($veri) ? $veri : ['_bozuk' => true];
    }

    return $bilgi;
}

/** Şemalardaki tüm @type değerleri (graph dahil). */
function gd_sema_turleri(array $semalar): array
{
    $turler = [];
    $gez = static function ($d) use (&$gez, &$turler): void {
        if (!is_array($d)) {
            return;
        }

        if (isset($d['@type'])) {
            foreach ((array) $d['@type'] as $t) {
                $turler[] = (string) $t;
            }
        }

        foreach ($d as $alt) {
            $gez($alt);
        }
    };

    foreach ($semalar as $s) {
        $gez($s);
    }

    return array_values(array_unique($turler));
}

/**
 * Denetimi çalıştırır.
 *
 * @return array{zaman:string,ozet:array<string,int>,maddeler:list<array<string,string>>}
 */
function google_denetimi(int $sureButcesi = 80): array
{
    $baslangic = time();
    $maddeler  = [];
    $ekle = static function (string $grup, string $ad, string $durum, string $ayrinti, string $oneri = '') use (&$maddeler): void {
        $maddeler[] = compact('grup', 'ad', 'durum', 'ayrinti', 'oneri');
    };
    $sureVar = static fn (): bool => time() - $baslangic < $sureButcesi;

    $taban = site_adresi();
    $host  = (string) parse_url($taban, PHP_URL_HOST);

    /* -------------------------------------------------------------- *
     * 1. Search Console baglantisi
     * -------------------------------------------------------------- */
    $hesap = gsc_hesap();
    $jeton = '';
    $site  = '';

    if ($hesap === null) {
        $ekle('Search Console', 'Servis hesabı anahtarı', 'hata', 'Panelde kayıtlı anahtar yok.',
            'Arama motorları → Google görünürlüğü sayfasından servis hesabı JSON anahtarını yükleyin.');
    } else {
        $ekle('Search Console', 'Servis hesabı anahtarı', 'tamam', $hesap['client_email']);

        $j = gsc_jeton();

        if (!$j['tamam']) {
            $ekle('Search Console', 'Google\'a bağlanma (okuma)', 'hata', $j['hata'],
                'Anahtar silinmiş ya da Google Cloud\'da devre dışı olabilir; yeni anahtar oluşturup yükleyin.');
        } else {
            $jeton = $j['jeton'];
            $ekle('Search Console', 'Google\'a bağlanma (okuma)', 'tamam', 'Erişim jetonu alındı.');
        }

        $jy = gsc_jeton(GSC_KAPSAM_YAZ);
        $ekle('Search Console', 'Google\'a bağlanma (yazma: site haritası gönderme)', $jy['tamam'] ? 'tamam' : 'hata',
            $jy['tamam'] ? 'Erişim jetonu alındı.' : $jy['hata']);
        $jetonYaz = $jy['tamam'] ? $jy['jeton'] : '';

        if ($jeton !== '') {
            // Mulk ve izin seviyesi
            $liste = gsc_istek('GET', GSC_API . '/sites', null, $jeton);

            if (!$liste['tamam']) {
                $ekle('Search Console', 'Mülk erişimi', 'hata', $liste['hata'],
                    'Google Cloud projesinde "Google Search Console API" etkin olmalı.');
            } else {
                $sade  = preg_replace('/^www\./', '', $host);
                $izin  = '';

                foreach ((array) ($liste['veri']['siteEntry'] ?? []) as $g) {
                    $adres = (string) ($g['siteUrl'] ?? '');
                    $h     = str_starts_with($adres, 'sc-domain:') ? substr($adres, 10) : preg_replace('/^www\./', '', (string) parse_url($adres, PHP_URL_HOST));

                    if ($h === $sade && ($site === '' || str_starts_with($adres, 'sc-domain:'))) {
                        $site = $adres;
                        $izin = (string) ($g['permissionLevel'] ?? '');
                    }
                }

                if ($site === '') {
                    $ekle('Search Console', 'Mülk erişimi', 'hata', 'Servis hesabı ' . $sade . ' mülkünü göremiyor.',
                        'Search Console → Ayarlar → Kullanıcılar ve izinler → Kullanıcı ekle: ' . $hesap['client_email'] . ' (Tam).');
                } else {
                    $tam = in_array($izin, ['siteOwner', 'siteFullUser'], true);
                    $ekle('Search Console', 'Mülk erişimi ve izin', $tam ? 'tamam' : 'uyari',
                        $site . ' — izin: ' . ($izin !== '' ? $izin : 'bilinmiyor'),
                        $tam ? '' : 'İzin "Tam" olmalı (kısıtlı kullanıcı site haritası gönderemez ve URL denetimi sınırlıdır).');
                    ayar_yaz('gsc_site', $site);

                    if (str_starts_with($site, 'http') && !str_starts_with($site, $taban)) {
                        $ekle('Search Console', 'Mülk adresi', 'uyari', 'Mülk ' . $site . ', site ise ' . $taban,
                            'Alan adı mülkü (sc-domain:' . $sade . ') ya da ' . $taban . '/ önek mülkü kullanılmalı.');
                    }
                }
            }
        }
    }

    /* -------------------------------------------------------------- *
     * 2. Site haritalari (Search Console tarafi)
     * -------------------------------------------------------------- */
    $haritalar = [$taban . '/sitemap.php', $taban . '/haber-haritasi.php'];

    if ($jeton !== '' && $site !== '') {
        $sm = gsc_istek('GET', GSC_API . '/sites/' . rawurlencode($site) . '/sitemaps', null, $jeton);

        if (!$sm['tamam']) {
            $ekle('Site haritası (Google)', 'Gönderilen haritalar', 'hata', $sm['hata']);
        } else {
            $bulunan = [];

            foreach ((array) ($sm['veri']['sitemap'] ?? []) as $h) {
                $yol = (string) ($h['path'] ?? '');
                $bulunan[$yol] = true;
                $gonderilen = 0;
                $dizinde    = 0;

                foreach ((array) ($h['contents'] ?? []) as $c) {
                    $gonderilen += (int) ($c['submitted'] ?? 0);
                    $dizinde    += (int) ($c['indexed'] ?? 0);
                }

                $hata  = (int) ($h['errors'] ?? 0);
                $uyari = (int) ($h['warnings'] ?? 0);
                $okundu = (string) ($h['lastDownloaded'] ?? '');
                $durum = $hata > 0 ? 'hata' : ($uyari > 0 || !empty($h['isPending']) ? 'uyari' : 'tamam');

                $ekle('Site haritası (Google)', basename((string) parse_url($yol, PHP_URL_PATH)), $durum,
                    'Gönderilen ' . $gonderilen . ' adres'
                    . ($dizinde > 0 ? ', dizinde ' . $dizinde : '')
                    . '; son okuma: ' . ($okundu !== '' ? date('d.m.Y H:i', (int) strtotime($okundu)) : 'henüz yok')
                    . ($hata > 0 ? '; ' . $hata . ' hata' : '') . ($uyari > 0 ? '; ' . $uyari . ' uyarı' : '')
                    . (!empty($h['isPending']) ? '; işleniyor' : ''),
                    $hata > 0 ? 'Search Console → Site haritaları bölümünde hatanın ayrıntısına bakın.' : '');
            }

            foreach ($haritalar as $harita) {
                if (isset($bulunan[$harita])) {
                    continue;
                }

                // Eksik harita gonderiliyor (bkz. dosya basi).
                $gonder = $jetonYaz !== ''
                    ? gsc_istek('PUT', GSC_API . '/sites/' . rawurlencode($site) . '/sitemaps/' . rawurlencode($harita), null, $jetonYaz)
                    : ['tamam' => false, 'hata' => 'yazma jetonu yok'];
                $ekle('Site haritası (Google)', basename($harita) . ' gönderimi', $gonder['tamam'] ? 'tamam' : 'hata',
                    $gonder['tamam'] ? 'Search Console\'a gönderilmemişti; şimdi gönderildi.' : 'Gönderilmemiş ve gönderilemedi: ' . $gonder['hata'],
                    $gonder['tamam'] ? '' : 'Search Console → Site haritaları → ' . $harita . ' adresini elle ekleyin.');
            }
        }
    }

    /* -------------------------------------------------------------- *
     * 3. Arama performansi
     * -------------------------------------------------------------- */
    if ($jeton !== '' && $site !== '' && $sureVar()) {
        $bit = date('Y-m-d', strtotime('-2 day'));
        $bas = date('Y-m-d', strtotime('-29 day'));
        $p = gsc_performans($jeton, $site, $bas, $bit, [], 1);

        if (!$p['tamam']) {
            $ekle('Arama performansı', 'Son 28 gün', 'hata', $p['hata']);
        } else {
            $s = $p['satirlar'][0] ?? [];
            $tik = (int) ($s['tiklama'] ?? 0);
            $gos = (int) ($s['gosterim'] ?? 0);
            $ekle('Arama performansı', 'Son 28 gün', $gos > 0 ? 'tamam' : 'uyari',
                $tik . ' tıklama, ' . $gos . ' gösterim'
                . ($gos > 0 ? ', ortalama sıra ' . number_format((float) ($s['sira'] ?? 0), 1, ',', '.') : ''),
                $gos === 0 ? 'Henüz arama gösterimi yok; dizine giren sayfa arttıkça başlar.' : '');
        }
    }

    /* -------------------------------------------------------------- *
     * 4. Site tarafi: robots.txt, yonlendirmeler, haritalar
     * -------------------------------------------------------------- */
    $robots = gd_getir($taban . '/robots.txt');

    if ($robots['kod'] !== 200) {
        $ekle('Tarama', 'robots.txt', 'hata', 'HTTP ' . $robots['kod'] . ' ' . $robots['hata'], 'robots.txt 200 dönmeli.');
    } else {
        $eksik = array_filter($haritalar, static fn (string $h): bool => stripos($robots['govde'], 'Sitemap: ' . $h) === false);
        $tumuKapali = preg_match('#^\s*Disallow:\s*/\s*$#mi', $robots['govde']) === 1;
        $bilinmeyen = [];

        foreach (preg_split('/\R/', $robots['govde']) as $satir) {
            $satir = trim((string) preg_replace('/#.*/', '', $satir));

            if ($satir !== '' && preg_match('/^(user-agent|allow|disallow|sitemap|crawl-delay)\s*:/i', $satir) !== 1) {
                $bilinmeyen[] = $satir;
            }
        }

        if ($bilinmeyen !== []) {
            $ekle('Tarama', 'robots.txt yönergeleri', 'uyari', 'Tanınmayan satır: ' . implode(' | ', array_slice($bilinmeyen, 0, 5)),
                'Google bu satırları yok sayar ama denetim araçları robots.txt\'yi geçersiz sayıyor; sunucu ya da CDN ekliyor olabilir.');
        }
        $ekle('Tarama', 'robots.txt', $tumuKapali ? 'hata' : ($eksik === [] ? 'tamam' : 'uyari'),
            $tumuKapali ? 'Bütün site taramaya kapalı (Disallow: /).' : ($eksik === [] ? 'İki site haritası da bildiriliyor; site taramaya açık.' : 'Bildirilmeyen harita: ' . implode(', ', $eksik)));
    }

    foreach (['http://' . $host . '/' => 'http → https', 'https://www.' . preg_replace('/^www\./', '', $host) . '/' => 'www → asıl adres'] as $adres => $ad) {
        if ($adres === $taban . '/') {
            continue;
        }

        $y = gd_getir($adres, false);
        $hedef = rtrim($y['yonlendirme'], '/');
        $iyi = in_array($y['kod'], [301, 308], true) && $hedef === $taban;
        $ekle('Tarama', 'Yönlendirme: ' . $ad, $iyi ? 'tamam' : ($y['kod'] === 0 ? 'bilgi' : 'uyari'),
            $y['kod'] === 0 ? 'Adrese ulaşılamadı (' . $y['hata'] . ')' : 'HTTP ' . $y['kod'] . ($y['yonlendirme'] !== '' ? ' → ' . $y['yonlendirme'] : ''),
            $iyi || $y['kod'] === 0 ? '' : 'Kalıcı (301) yönlendirme ' . $taban . ' adresine gitmeli.');
    }

    $tumAdresler = [];

    foreach ($haritalar as $harita) {
        $x = gd_getir($harita);
        $ad = basename($harita);

        if ($x['kod'] !== 200) {
            $ekle('Site haritası (site)', $ad, 'hata', 'HTTP ' . $x['kod'] . ' ' . $x['hata']);
            continue;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($x['govde']);

        if ($xml === false) {
            $ekle('Site haritası (site)', $ad, 'hata', 'Geçerli XML değil: ' . trim((string) (libxml_get_errors()[0]->message ?? '')));
            continue;
        }

        $adresler = [];

        foreach ($xml->url as $u) {
            $adresler[] = (string) $u->loc;
        }

        $yabanci = array_filter($adresler, static fn (string $a): bool => !str_starts_with($a, $taban . '/'));
        $durum = $yabanci !== [] ? 'hata' : ($adresler === [] && $ad === 'sitemap.php' ? 'hata' : 'tamam');
        $ekle('Site haritası (site)', $ad, $durum,
            count($adresler) . ' adres' . ($yabanci !== [] ? '; ' . count($yabanci) . ' adres başka alan adında (ör. ' . reset($yabanci) . ')' : '')
            . ($ad === 'haber-haritasi.php' ? ' (son 2 günün haberleri; Google Haberler en fazla 1000 kabul eder)' : ''),
            $yabanci !== [] ? 'Haritadaki tüm adresler ' . $taban . ' ile başlamalı.' : '');

        $tumAdresler = array_merge($tumAdresler, $adresler);
    }

    /* -------------------------------------------------------------- *
     * 5. Haritadaki sayfalar Googlebot gozuyle: haber DISINDAKI butun
     *    sayfalar, haberlerden en yeni 40 ve rastgele 40 (binlerce haberin
     *    hepsi sure butcesine sigmiyor; sablonlari ayni).
     * -------------------------------------------------------------- */
    $tumAdresler = array_values(array_unique($tumAdresler));
    $haberAdresi = static fn (string $a): bool => preg_match('#^/haber(/|\.php)#', substr($a, strlen($taban))) === 1;
    $haberler    = array_values(array_filter($tumAdresler, $haberAdresi));
    $digerleri   = array_values(array_filter($tumAdresler, static fn (string $a): bool => !$haberAdresi($a)));
    $rastgele    = array_slice($haberler, 40);
    shuffle($rastgele);
    $secilen     = array_merge($digerleri, array_slice($haberler, 0, 40), array_slice($rastgele, 0, 40));

    $haberOrnegi = '';
    $sorunlu     = [];
    $basliklar   = [];
    $sureler     = [];

    $denetlenen = gd_toplu_getir($secilen, 4, max(10, $sureButcesi - (time() - $baslangic) - 20),
        static function (string $adres, array $s) use (&$haberOrnegi, &$sorunlu, &$basliklar, &$sureler): void {
            $sorunlar = [];

            if ($s['kod'] !== 200) {
                $sorunlar[] = 'HTTP ' . $s['kod'] . ($s['hata'] !== '' ? ' (' . $s['hata'] . ')' : '');
            } else {
                $b = gd_sayfa_coz($s['govde']);
                $robotsBaslik = strtolower($s['basliklar']['x-robots-tag'] ?? '');
                $sureler[] = $s['sure'];

                if (str_contains(strtolower($b['robots']), 'noindex') || str_contains($robotsBaslik, 'noindex')) {
                    $sorunlar[] = 'site haritasında ama "noindex"';
                }

                if ($b['kanonik'] === '') {
                    $sorunlar[] = 'kanonik etiketi yok';
                } elseif (rtrim($b['kanonik'], '/') !== rtrim($adres, '/')) {
                    $sorunlar[] = 'kanonik başka adresi gösteriyor: ' . $b['kanonik'];
                }

                if ($b['baslik'] === '') {
                    $sorunlar[] = 'başlık (title) yok';
                } else {
                    $basliklar[$b['baslik']][] = $adres;
                }

                if ($b['aciklama'] === '') {
                    $sorunlar[] = 'açıklama (description) yok';
                }

                foreach ($b['semalar'] as $sema) {
                    if (isset($sema['_bozuk'])) {
                        $sorunlar[] = 'bozuk JSON-LD';
                        break;
                    }
                }

                if ($s['sure'] > 5) {
                    $sorunlar[] = 'yanıt çok yavaş (' . $s['sure'] . ' sn)';
                }

                if ($haberOrnegi === '' && in_array('NewsArticle', gd_sema_turleri($b['semalar']), true)) {
                    $haberOrnegi = $adres;
                }
            }

            if ($sorunlar !== []) {
                $sorunlu[$adres] = $sorunlar;
            }
        });

    sort($sureler);
    $ekle('Sayfa denetimi (Googlebot gözüyle)', 'Site haritasındaki sayfalar',
        $sorunlu === [] && $denetlenen === count($secilen) ? 'tamam' : ($sorunlu === [] ? 'uyari' : 'hata'),
        count($tumAdresler) . ' adresten ' . count($digerleri) . ' haber dışı sayfanın tamamı ve ' . min(80, count($haberler))
        . ' haber (en yeni 40 + rastgele 40) seçildi; ' . $denetlenen . '/' . count($secilen) . ' indirildi; ' . ($denetlenen - count($sorunlu)) . ' sorunsuz (HTTP 200, dizine açık, kanonik kendisi, başlık ve açıklama var)'
        . ($sureler !== [] ? '; ortanca yanıt ' . $sureler[intdiv(count($sureler), 2)] . ' sn, en yavaş ' . end($sureler) . ' sn' : ''),
        $denetlenen < count($secilen) ? 'Süre yetmedi; kalanlar bir sonraki denetimde.' : '');

    $i = 0;

    foreach ($sorunlu as $adres => $sorunlar) {
        if (++$i > 40) {
            $ekle('Sayfa denetimi (Googlebot gözüyle)', '…', 'hata', (count($sorunlu) - 40) . ' sorunlu sayfa daha');
            break;
        }

        $ekle('Sayfa denetimi (Googlebot gözüyle)', gd_kisa_adres($adres), 'hata', implode('; ', $sorunlar));
    }

    $kopyaBaslik = array_filter($basliklar, static fn (array $a): bool => count($a) > 1);
    $ekle('Sayfa denetimi (Googlebot gözüyle)', 'Aynı başlıklı (title) sayfalar', $kopyaBaslik === [] ? 'tamam' : 'uyari',
        $kopyaBaslik === [] ? 'Her sayfanın başlığı farklı.'
            : count($kopyaBaslik) . ' başlık birden çok sayfada; ör. "' . array_key_first($kopyaBaslik) . '": '
              . implode(', ', array_map('gd_kisa_adres', array_slice(reset($kopyaBaslik), 0, 3))),
        $kopyaBaslik === [] ? '' : 'Google aynı başlıklı sayfaları kopya sayıp birini dizinden çıkarabilir.');

    // Ana sayfa: yapisal veri, gorsel onizleme, favicon, paylasim gorseli
    $ana = gd_getir($taban . '/');

    if ($ana['kod'] === 200) {
        $b = gd_sayfa_coz($ana['govde']);
        $turler = gd_sema_turleri($b['semalar']);
        $kurum = array_intersect(['NewsMediaOrganization', 'Organization'], $turler) !== [];
        $ekle('Yapısal veri', 'Ana sayfa: kurum ve site şeması', $kurum && in_array('WebSite', $turler, true) ? 'tamam' : 'uyari',
            'Türler: ' . ($turler !== [] ? implode(', ', $turler) : 'yok'));

        $buyuk = str_contains(strtolower($b['robots']), 'max-image-preview:large');
        $ekle('Google Discover / Haberler', 'Büyük görsel izni (max-image-preview:large)', $buyuk ? 'tamam' : 'hata',
            $buyuk ? 'Var.' : 'Yok: haberler Discover\'da ve Haberler\'de küçük resimle görünür.',
            $buyuk ? '' : 'Sayfa başlığına <meta name="robots" content="max-image-preview:large"> eklenmeli.');

        $og = $b['og_gorsel'];
        $ogIyi = $og !== '' && preg_match('#\.(png|jpe?g|webp)(\?|$)#i', $og) === 1;
        $ekle('Paylaşım', 'Varsayılan paylaşım görseli (og:image)', $ogIyi ? 'tamam' : 'uyari', $og !== '' ? $og : 'yok',
            $ogIyi ? '' : 'SVG paylaşım görseli Facebook/WhatsApp/X\'te görünmez; PNG ya da JPG olmalı.');

        $fav = gd_getir($taban . '/favicon.ico', true);
        $ekle('Tarama', 'favicon.ico', $fav['kod'] === 200 ? 'tamam' : 'uyari', 'HTTP ' . $fav['kod'],
            $fav['kod'] === 200 ? '' : 'Kökte favicon.ico olmalı (tarayıcılar ve botlar arıyor).');
    } else {
        $ekle('Sayfa denetimi (Googlebot gözüyle)', 'Ana sayfa', 'hata', 'HTTP ' . $ana['kod'] . ' ' . $ana['hata']);
    }

    // Haber sayfasi: NewsArticle zorunlu/onerilen alanlari
    if ($haberOrnegi === '') {
        $haberOrnegi = (string) db()->query(
            "SELECT slug FROM haberler WHERE durum = 'yayinda' AND " . haber_dizinlenir_kosulu() . ' ORDER BY yayin_tarihi DESC LIMIT 1'
        )->fetchColumn();
        $haberOrnegi = $haberOrnegi !== '' ? $taban . haber_yolu($haberOrnegi) : '';
    }

    if ($haberOrnegi !== '') {
        $h = gd_getir($haberOrnegi);
        $b = gd_sayfa_coz($h['govde']);
        $haber = null;

        foreach ($b['semalar'] as $s) {
            if (($s['@type'] ?? '') === 'NewsArticle') {
                $haber = $s;
            }
        }

        if ($haber === null) {
            $ekle('Yapısal veri', 'Haber şeması (NewsArticle)', 'hata', 'Haber sayfasında NewsArticle yok: ' . $haberOrnegi);
        } else {
            $eksik = [];

            foreach (['headline', 'datePublished', 'image', 'author', 'publisher'] as $alan) {
                if (empty($haber[$alan])) {
                    $eksik[] = $alan;
                }
            }

            if (isset($haber['headline']) && mb_strlen((string) $haber['headline']) > 110) {
                $eksik[] = 'headline 110 karakteri aşıyor';
            }

            $logo = (string) ($haber['publisher']['logo']['url'] ?? '');

            if ($logo !== '' && str_ends_with(strtolower($logo), '.svg')) {
                $eksik[] = 'yayıncı logosu SVG (PNG önerilir)';
            }

            $ekle('Yapısal veri', 'Haber şeması (NewsArticle)', $eksik === [] ? 'tamam' : 'uyari',
                $eksik === [] ? 'Zorunlu ve önerilen alanlar tam: ' . $haberOrnegi : 'Eksik/sorunlu: ' . implode(', ', $eksik) . ' — ' . $haberOrnegi);
        }

    } else {
        $ekle('Yapısal veri', 'Haber şeması (NewsArticle)', 'bilgi', 'Denetlenecek yayında haber bulunamadı.');
    }

    // Google'in kendi gozuyle: URL denetimi (zengin sonuc dahil)
    if ($jeton !== '' && $site !== '' && $sureVar()) {
        // Ana sayfa, ornek haber ve haritadan birer kategori, rehber, kurumsal sayfa
        $denetlenecek = [$taban . '/' => 'Ana sayfa'];

        if ($haberOrnegi !== '') {
            $denetlenecek[$haberOrnegi] = 'Örnek haber';
        }

        require_once __DIR__ . '/kurumsal.php';
        $denetlenecek[$taban . kurumsal_yolu('yayin-ilkeleri')] = 'Yayın ilkeleri';

        foreach (['/kategori' => 'Kategori sayfası', '/rehber' => 'Rehber'] as $onek => $etiket) {
            foreach ($tumAdresler as $a) {
                if (str_starts_with(substr($a, strlen($taban)), $onek) && !isset($denetlenecek[$a])) {
                    $denetlenecek[$a] = $etiket . ' (' . gd_kisa_adres($a) . ')';
                    break;
                }
            }
        }

        foreach ($denetlenecek as $adres => $ad) {
            if (!$sureVar()) {
                break;
            }

            $d = gsc_istek('POST', GSC_DENETIM_API, ['inspectionUrl' => $adres, 'siteUrl' => $site, 'languageCode' => 'tr-TR'], $jeton, 30);

            if (!$d['tamam']) {
                $ekle('URL denetimi (Google)', $ad, 'hata', $d['hata']);
                continue;
            }

            $r  = (array) ($d['veri']['inspectionResult'] ?? []);
            $ix = (array) ($r['indexStatusResult'] ?? []);
            $zengin = (array) ($r['richResultsResult'] ?? []);
            $zenginTurler = array_map(static fn ($i) => (string) ($i['richResultType'] ?? ''), (array) ($zengin['detectedItems'] ?? []));
            $dizinde = ($ix['verdict'] ?? '') === 'PASS';
            $kanonikFarkli = !empty($ix['googleCanonical']) && !empty($ix['userCanonical']) && $ix['googleCanonical'] !== $ix['userCanonical'];

            $ekle('URL denetimi (Google)', $ad, $dizinde && !$kanonikFarkli ? 'tamam' : 'uyari',
                (string) ($ix['coverageState'] ?? 'bilinmiyor')
                . '; tarama: ' . (!empty($ix['lastCrawlTime']) ? date('d.m.Y H:i', (int) strtotime((string) $ix['lastCrawlTime'])) : 'yok')
                . '; getirme: ' . (string) ($ix['pageFetchState'] ?? '-')
                . '; robots.txt: ' . (string) ($ix['robotsTxtState'] ?? '-')
                . ($kanonikFarkli ? '; Google kanonik olarak ' . $ix['googleCanonical'] . ' seçmiş' : '')
                . ($zenginTurler !== [] ? '; zengin sonuç: ' . implode(', ', $zenginTurler) . ' (' . (string) ($zengin['verdict'] ?? '') . ')' : ''),
                $dizinde ? '' : 'Search Console\'da bu adres için "Dizine eklenmesini iste" kullanılabilir: ' . gsc_denetim_adresi($adres));
        }
    }

    /* -------------------------------------------------------------- *
     * 6. Icerik saglligi (veritabani)
     * -------------------------------------------------------------- */
    try {
        $s = db()->query(
            "SELECT COUNT(*) AS toplam,
                    SUM(" . haber_dizinlenir_kosulu() . ") AS dizinlenir,
                    SUM(" . haber_dizinlenir_kosulu() . " AND COALESCE(gorsel_url, '') = '') AS gorselsiz,
                    SUM(" . haber_dizinlenir_kosulu() . " AND TRIM(ozet) = '') AS ozetsiz
               FROM haberler WHERE durum = 'yayinda'"
        )->fetch();
        $kopya = (int) db()->query(
            "SELECT COUNT(*) FROM (SELECT baslik FROM haberler WHERE durum = 'yayinda' AND " . haber_dizinlenir_kosulu()
            . " GROUP BY baslik HAVING COUNT(*) > 1) t"
        )->fetchColumn();

        $ekle('İçerik', 'Yayındaki haberler', 'bilgi',
            (int) $s['toplam'] . ' haber; Google\'a sunulan ' . (int) $s['dizinlenir'] . ', sunulmayan (kısa ya da tek kaynağın özeti; okuyucuya açık) '
            . ((int) $s['toplam'] - (int) $s['dizinlenir']));
        $ekle('İçerik', 'Aynı başlıklı haberler (Google\'a sunulanlar)', $kopya === 0 ? 'tamam' : 'uyari', $kopya . ' başlık birden fazla haberde',
            $kopya === 0 ? '' : 'Aynı başlık Google\'da kopya sayfa gibi görünür; başlıkları ayırın ya da birini kaldırın.');
        $ekle('İçerik', 'Görselsiz / özetsiz haber (Google\'a sunulanlar)', ((int) $s['gorselsiz'] + (int) $s['ozetsiz']) === 0 ? 'tamam' : 'uyari',
            (int) $s['gorselsiz'] . ' görselsiz, ' . (int) $s['ozetsiz'] . ' özetsiz',
            ((int) $s['gorselsiz'] + (int) $s['ozetsiz']) === 0 ? '' : 'Discover ve Haberler görselsiz haberi göstermez; özet arama sonucundaki açıklamadır.');
    } catch (Throwable $e) {
        $ekle('İçerik', 'Veritabanı', 'hata', $e->getMessage());
    }

    /* -------------------------------------------------------------- *
     * 7. Diger motorlar ve bildirim
     * -------------------------------------------------------------- */
    $anahtar = indexnow_anahtari();
    $ia = gd_getir(indexnow_anahtar_adresi());
    $ekle('Bildirim', 'IndexNow anahtar dosyası', $ia['kod'] === 200 && trim($ia['govde']) === $anahtar ? 'tamam' : 'hata',
        'HTTP ' . $ia['kod'] . ' ' . indexnow_anahtar_adresi());

    $son = json_decode(ayar_oku('gsc_harita_son'), true);

    if (is_array($son)) {
        $ekle('Bildirim', 'Son site haritası bildirimi (Google)', !empty($son['tamam']) ? 'tamam' : 'uyari',
            (string) ($son['zaman'] ?? '') . (!empty($son['hata']) ? ' — ' . $son['hata'] : ''));
    }

    $ozet = ['tamam' => 0, 'uyari' => 0, 'hata' => 0, 'bilgi' => 0];

    foreach ($maddeler as $m) {
        $ozet[$m['durum']]++;
    }

    $sonuc = ['zaman' => date('Y-m-d H:i:s'), 'ozet' => $ozet, 'maddeler' => $maddeler];
    ayar_yaz('google_denetim_son', (string) json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $sonuc;
}
