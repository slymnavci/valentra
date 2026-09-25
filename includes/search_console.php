<?php
declare(strict_types=1);

/**
 * Google Search Console verisi.
 *
 * Neden: "Google bizi goruyor mu, hangi aramalarla geliyorlar, hangi
 * sayfa dizinde yok ve neden" sorularinin cevabi yalnizca Search
 * Console'da. Panelin ziyaret sayaci Googlebot'u bilerek saymiyor ve
 * arama sorgusunu hic gormuyor.
 *
 * Kimlik: bir Google Cloud SERVIS HESABI. Site sahibi hesabin JSON
 * anahtarini panele yapistiriyor ve hesabin e-postasini Search
 * Console'a kullanici olarak ekliyor. Istekler SALT OKUNUR kapsamla
 * (webmasters.readonly) imzalaniyor: anahtar ele gecse bile Search
 * Console'da hicbir sey degistirilemez.
 *
 * Veriyi SITE cekiyor (ajan yalnizca durtuyor; panel acildiginda da
 * bayatsa tazeleniyor). Search Console verisi 2-3 gun geriden gelir;
 * gunde bir tazeleme yeterli.
 *
 * Saklananlar:
 *   gsc_gunluk   — son 16 ayin gunluk tiklama/gosterim/CTR/sira toplami
 *   gsc_denetim  — URL denetimi: dizinde mi, degilse Google'in sebebi
 *   ayarlar      — son 28 gunun sorgulari ve sayfalari (JSON), site
 *                  haritasi durumu, son tazeleme ve son hata
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/http_ortak.php';
require_once __DIR__ . '/seo.php';

const GSC_KAPSAM          = 'https://www.googleapis.com/auth/webmasters.readonly';
const GSC_JETON_ADRESI    = 'https://oauth2.googleapis.com/token';
const GSC_API             = 'https://www.googleapis.com/webmasters/v3';
const GSC_DENETIM_API     = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
const GSC_TAZELEME_SAAT   = 20;   // otomatik tazeleme araligi
const GSC_DENETIM_GUN     = 4;    // bir URL en erken kac gunde bir yeniden denetlenir
const GSC_DENETIM_TUR     = 15;   // bir tazelemede en fazla kac URL denetlenir
const GSC_SATIR           = 250;  // sorgu/sayfa tablosu satir sayisi

/* ------------------------------------------------------------------------
 * Anahtar
 * --------------------------------------------------------------------- */

/**
 * Kayitli servis hesabi; yoksa null.
 *
 * @return array{client_email:string,private_key:string}|null
 */
function gsc_hesap(): ?array
{
    $ham = ayar_oku('gsc_hizmet_hesabi');

    if ($ham === '') {
        return null;
    }

    $veri = json_decode($ham, true);

    if (!is_array($veri) || ($veri['client_email'] ?? '') === '' || ($veri['private_key'] ?? '') === '') {
        return null;
    }

    return ['client_email' => (string) $veri['client_email'], 'private_key' => (string) $veri['private_key']];
}

/**
 * Panelden yapistirilan JSON anahtari denetler ve saklar.
 *
 * Yalnizca iki alan saklaniyor (e-posta ve ozel anahtar); proje
 * numarasi gibi geri kalan alanlara ihtiyac yok.
 *
 * @return array{tamam:bool,mesaj:string}
 */
function gsc_hesap_kaydet(string $json): array
{
    $veri = json_decode(trim($json), true);

    if (!is_array($veri)) {
        return ['tamam' => false, 'mesaj' => 'Yapıştırılan metin JSON değil. İndirdiğiniz .json dosyasının içeriğinin tamamını yapıştırın.'];
    }

    if (($veri['type'] ?? '') !== 'service_account') {
        return ['tamam' => false, 'mesaj' => 'Bu bir servis hesabı anahtarı değil ("type": "service_account" bekleniyordu).'];
    }

    $eposta = (string) ($veri['client_email'] ?? '');
    $anahtar = (string) ($veri['private_key'] ?? '');

    if (filter_var($eposta, FILTER_VALIDATE_EMAIL) === false || !str_contains($anahtar, 'PRIVATE KEY')) {
        return ['tamam' => false, 'mesaj' => 'Anahtarda client_email ya da private_key alanı eksik.'];
    }

    if (openssl_pkey_get_private($anahtar) === false) {
        return ['tamam' => false, 'mesaj' => 'Özel anahtar okunamadı; dosya bozulmuş olabilir. Yeni bir JSON anahtarı indirin.'];
    }

    ayar_yaz('gsc_hizmet_hesabi', json_encode(
        ['client_email' => $eposta, 'private_key' => $anahtar],
        JSON_UNESCAPED_SLASHES
    ));

    // Onceki hesabin jetonu ve site secimi yeni hesapta gecersiz.
    ayar_sil('gsc_jeton');
    ayar_sil('gsc_site');
    ayar_sil('gsc_son_hata');

    return ['tamam' => true, 'mesaj' => 'Anahtar kaydedildi: ' . $eposta];
}

function gsc_hesap_sil(): void
{
    foreach (['gsc_hizmet_hesabi', 'gsc_jeton', 'gsc_site', 'gsc_son_hata'] as $a) {
        ayar_sil($a);
    }
}

/* ------------------------------------------------------------------------
 * HTTP ve kimlik
 * --------------------------------------------------------------------- */

/** base64url, dolgu yok. */
function gsc_b64(string $veri): string
{
    return rtrim(strtr(base64_encode($veri), '+/', '-_'), '=');
}

/**
 * Google'a JSON ya da form istegi.
 *
 * http_getir() kullanilmiyor: o yalnizca GET yapiyor ve tarayici gibi
 * HTML isteyen basliklar gonderiyor.
 *
 * @param array<string,mixed>|string|null $govde dizi JSON'a, metin form olarak gider
 * @return array{tamam:bool,kod:int,veri:array<mixed>,hata:string}
 */
function gsc_istek(string $yontem, string $url, array|string|null $govde = null, string $jeton = '', int $zamanAsimi = 25): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, http_ortak_secenekler($zamanAsimi));

    $basliklar = ['Accept: application/json'];

    if ($jeton !== '') {
        $basliklar[] = 'Authorization: Bearer ' . $jeton;
    }

    if ($govde !== null) {
        if (is_array($govde)) {
            $basliklar[] = 'Content-Type: application/json';
            $govde = json_encode($govde, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $basliklar[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
    }

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $yontem);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $basliklar);
    // Kimlik tasiyan istek: yonlendirme izlenmiyor (bkz. http_getir).
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    $yanit = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata  = curl_error($ch);
    $no    = curl_errno($ch);
    curl_close($ch);

    if (!is_string($yanit)) {
        return ['tamam' => false, 'kod' => 0, 'veri' => [], 'hata' => http_hata_acikla($no, $hata)];
    }

    $veri = json_decode($yanit, true);
    $veri = is_array($veri) ? $veri : [];

    if ($kod < 200 || $kod >= 300) {
        $mesaj = (string) ($veri['error']['message'] ?? $veri['error_description'] ?? $veri['error'] ?? '');

        return ['tamam' => false, 'kod' => $kod, 'veri' => $veri,
                'hata' => 'Google HTTP ' . $kod . ($mesaj !== '' ? ': ' . $mesaj : '') . gsc_hata_ipucu($kod, $mesaj)];
    }

    return ['tamam' => true, 'kod' => $kod, 'veri' => $veri, 'hata' => ''];
}

/** Google'in sik verdigi hatalarin Turkce karsiligi ve cozumu. */
function gsc_hata_ipucu(int $kod, string $mesaj): string
{
    $m = strtolower($mesaj);

    return match (true) {
        str_contains($m, 'account not found')
            => ' — Servis hesabı bulunamadı: hesap silinmiş ya da JSON başka bir projeye ait. Yeni bir JSON anahtarı indirip yapıştırın.',
        str_contains($m, 'invalid jwt signature')
            => ' — Anahtar iptal edilmiş ya da değiştirilmiş. Google Cloud\'da yeni bir JSON anahtarı oluşturun.',
        str_contains($m, 'has not been used') || str_contains($m, 'is disabled')
            => ' — Google Cloud projesinde "Google Search Console API" etkin değil; API kitaplığından etkinleştirin (birkaç dakika sürebilir).',
        $kod === 403
            => ' — Servis hesabı bu mülkte yetkili değil. Search Console → Ayarlar → Kullanıcılar ve izinler bölümünde hesabı "Tam" izinle ekleyin.',
        $kod === 429
            => ' — Google kotası doldu; bir sonraki güncellemede yeniden denenecek.',
        default => '',
    };
}

/**
 * Erisim jetonu (bir saat gecerli; onbellekte 55 dakika).
 *
 * @return array{tamam:bool,jeton:string,hata:string}
 */
function gsc_jeton(): array
{
    $hesap = gsc_hesap();

    if ($hesap === null) {
        return ['tamam' => false, 'jeton' => '', 'hata' => 'Servis hesabı anahtarı girilmemiş.'];
    }

    $onbellek = json_decode(ayar_oku('gsc_jeton'), true);

    if (is_array($onbellek) && (int) ($onbellek['bitis'] ?? 0) > time() + 60) {
        return ['tamam' => true, 'jeton' => (string) $onbellek['jeton'], 'hata' => ''];
    }

    $simdi = time();
    $girdi = gsc_b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
           . '.'
           . gsc_b64((string) json_encode([
               'iss'   => $hesap['client_email'],
               'scope' => GSC_KAPSAM,
               'aud'   => GSC_JETON_ADRESI,
               'iat'   => $simdi,
               'exp'   => $simdi + 3600,
           ], JSON_UNESCAPED_SLASHES));

    $imza = '';

    if (!openssl_sign($girdi, $imza, $hesap['private_key'], OPENSSL_ALGO_SHA256)) {
        return ['tamam' => false, 'jeton' => '', 'hata' => 'Anahtar imzalanamadı (OpenSSL).'];
    }

    $yanit = gsc_istek('POST', GSC_JETON_ADRESI, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $girdi . '.' . gsc_b64($imza),
    ]));

    $jeton = (string) ($yanit['veri']['access_token'] ?? '');

    if (!$yanit['tamam'] || $jeton === '') {
        return ['tamam' => false, 'jeton' => '', 'hata' => 'Google oturum açmadı: ' . $yanit['hata']];
    }

    ayar_yaz('gsc_jeton', (string) json_encode([
        'jeton' => $jeton,
        'bitis' => $simdi + min(3300, (int) ($yanit['veri']['expires_in'] ?? 3300)),
    ]));

    return ['tamam' => true, 'jeton' => $jeton, 'hata' => ''];
}

/* ------------------------------------------------------------------------
 * Mulk (site) secimi
 * --------------------------------------------------------------------- */

/**
 * Servis hesabinin gordugu Search Console mulkleri icinden bu siteyi
 * bulur. Alan adi mulku (sc-domain:) varsa o tercih ediliyor: http,
 * https, www'lu ve www'suz butun adresleri kapsiyor.
 *
 * @return array{tamam:bool,site:string,hata:string,gorulen:list<string>}
 */
function gsc_site(string $jeton): array
{
    $kayitli = ayar_oku('gsc_site');

    if ($kayitli !== '') {
        return ['tamam' => true, 'site' => $kayitli, 'hata' => '', 'gorulen' => []];
    }

    $yanit = gsc_istek('GET', GSC_API . '/sites', null, $jeton);

    if (!$yanit['tamam']) {
        return ['tamam' => false, 'site' => '', 'hata' => $yanit['hata'], 'gorulen' => []];
    }

    $host    = preg_replace('/^www\./', '', (string) parse_url(site_adresi(), PHP_URL_HOST));
    $gorulen = [];
    $aday    = '';

    foreach ((array) ($yanit['veri']['siteEntry'] ?? []) as $giris) {
        $adres = (string) ($giris['siteUrl'] ?? '');
        $gorulen[] = $adres;

        if ($adres === 'sc-domain:' . $host) {
            $aday = $adres;
            break;
        }

        $h = preg_replace('/^www\./', '', (string) parse_url($adres, PHP_URL_HOST));

        if ($h === $host && $aday === '') {
            $aday = $adres;
        }
    }

    if ($aday === '') {
        $hesap = gsc_hesap();

        return ['tamam' => false, 'site' => '', 'gorulen' => $gorulen,
                'hata' => 'Servis hesabı Search Console\'da ' . $host . ' mülkünü göremiyor. '
                        . 'Search Console → Ayarlar → Kullanıcılar ve izinler bölümünden '
                        . ($hesap['client_email'] ?? 'servis hesabı') . ' adresini kullanıcı olarak ekleyin.'];
    }

    ayar_yaz('gsc_site', $aday);

    return ['tamam' => true, 'site' => $aday, 'hata' => '', 'gorulen' => $gorulen];
}

/* ------------------------------------------------------------------------
 * Veri cekme
 * --------------------------------------------------------------------- */

/**
 * @param list<string> $boyutlar
 * @return array{tamam:bool,satirlar:list<array<string,mixed>>,hata:string}
 */
function gsc_performans(string $jeton, string $site, string $bas, string $bit, array $boyutlar, int $satir = GSC_SATIR): array
{
    $yanit = gsc_istek(
        'POST',
        GSC_API . '/sites/' . rawurlencode($site) . '/searchAnalytics/query',
        ['startDate' => $bas, 'endDate' => $bit, 'dimensions' => $boyutlar,
         'rowLimit' => $satir, 'dataState' => 'final'],
        $jeton
    );

    if (!$yanit['tamam']) {
        return ['tamam' => false, 'satirlar' => [], 'hata' => $yanit['hata']];
    }

    $satirlar = [];

    foreach ((array) ($yanit['veri']['rows'] ?? []) as $r) {
        $satirlar[] = [
            'anahtar'  => (array) ($r['keys'] ?? []),
            'tiklama'  => (int) ($r['clicks'] ?? 0),
            'gosterim' => (int) ($r['impressions'] ?? 0),
            'ctr'      => (float) ($r['ctr'] ?? 0),
            'sira'     => (float) ($r['position'] ?? 0),
        ];
    }

    return ['tamam' => true, 'satirlar' => $satirlar, 'hata' => ''];
}

/**
 * Denetlenecek adresler, oncelik sirasiyla: ana bolumler, rehberler,
 * son haberler ve kose yazilari.
 *
 * @return list<string>
 */
function gsc_denetim_adaylari(): array
{
    $taban   = site_adresi();
    $adresler = [$taban . '/'];

    foreach (['pratik_yolu', 'takvim_yolu', 'araclar_yolu', 'kose_liste_yolu', 'kanunlar_yolu', 'rehberler_yolu'] as $f) {
        if (function_exists($f)) {
            $adresler[] = $taban . $f();
        }
    }

    $sorgular = [
        "SELECT slug FROM rehberler WHERE durum = 'yayinda' ORDER BY sira, id" => 'rehber_yolu',
        "SELECT slug FROM haberler WHERE durum = 'yayinda' ORDER BY yayin_tarihi DESC LIMIT 40" => 'haber_yolu',
        "SELECT slug FROM kose_yazilari WHERE durum = 'yayinda' ORDER BY gun DESC, sira LIMIT 12" => 'kose_yolu',
    ];

    foreach ($sorgular as $sql => $yol) {
        if (!function_exists($yol)) {
            continue;
        }

        try {
            foreach (db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $adresler[] = $taban . $yol((string) $slug);
            }
        } catch (PDOException $e) {
            // Tablo henuz yoksa o grup atlanir.
        }
    }

    return array_values(array_unique($adresler));
}

/**
 * Google'in "coverageState" metinleri, Turkce ve ne yapilacagiyla.
 *
 * @return array{0:string,1:string} [durum, oneri]
 */
function gsc_kapsam_acikla(string $kapsam): array
{
    $sozluk = [
        'Submitted and indexed'                     => ['Dizinde (site haritasından)', ''],
        'Indexed, not submitted in sitemap'         => ['Dizinde (site haritasında yok)', 'Site haritasında olmalı; kontrol edin.'],
        'Crawled - currently not indexed'           => ['Tarandı, dizine alınmadı', 'Google sayfayı gördü ama değerli bulmadı: içeriği özgünleştirip derinleştirin, başka sayfalardan bağlantı verin.'],
        'Discovered - currently not indexed'        => ['Bulundu, henüz taranmadı', 'Google adresi biliyor ama taramadı. Genelde yeni sitelerde görülür; iç bağlantılar ve düzenli yayın hızlandırır.'],
        'URL is unknown to Google'                  => ['Google bu adresi bilmiyor', 'Site haritasına girdiğinden ve başka sayfalardan bağlantı aldığından emin olun.'],
        'Duplicate without user-selected canonical' => ['Kopya sayılmış', 'Google başka bir sayfayı asıl kabul etti; başlık ve metin benzerliğini azaltın.'],
        'Duplicate, Google chose different canonical than user' => ['Google başka asıl sayfa seçti', 'Canonical etiketi ile Google\'ın seçimi farklı; benzer sayfaları birleştirin ya da ayrıştırın.'],
        'Alternate page with proper canonical tag'  => ['Alternatif sayfa (sorun değil)', ''],
        'Excluded by ‘noindex’ tag'                 => ['noindex ile hariç', 'Bilerek hariç değilse etiketi kaldırın.'],
        "Excluded by 'noindex' tag"                 => ['noindex ile hariç', 'Bilerek hariç değilse etiketi kaldırın.'],
        'Blocked by robots.txt'                     => ['robots.txt engelliyor', 'robots.txt kuralını kontrol edin.'],
        'Not found (404)'                           => ['Bulunamadı (404)', 'Adres değiştiyse eski adresi yenisine yönlendirin.'],
        'Soft 404'                                  => ['Boş sayfa (soft 404)', 'Sayfa içerik göstermiyor; içerik ekleyin ya da 404 döndürün.'],
        'Page with redirect'                        => ['Yönlendiren sayfa', ''],
        'Server error (5xx)'                        => ['Sunucu hatası', 'Sunucu Google\'a hata döndü; hosting kayıtlarına bakın.'],
    ];

    return $sozluk[$kapsam] ?? [$kapsam !== '' ? $kapsam : 'Bilinmiyor', ''];
}

/**
 * Bir adresi denetler ve sonucu saklar.
 *
 * @return array{tamam:bool,hata:string}
 */
function gsc_url_denetle(string $jeton, string $site, string $url): array
{
    $yanit = gsc_istek('POST', GSC_DENETIM_API, ['inspectionUrl' => $url, 'siteUrl' => $site, 'languageCode' => 'tr-TR'], $jeton, 30);

    if (!$yanit['tamam']) {
        return ['tamam' => false, 'hata' => $yanit['hata']];
    }

    $d = (array) ($yanit['veri']['inspectionResult']['indexStatusResult'] ?? []);

    $tarama = (string) ($d['lastCrawlTime'] ?? '');
    $tarama = $tarama !== '' && strtotime($tarama) !== false ? date('Y-m-d H:i:s', (int) strtotime($tarama)) : null;

    db()->prepare(
        'INSERT INTO gsc_denetim (url_ozet, url, sonuc, kapsam, son_tarama, google_kanonik,
                                  robots, getirme, denetlendi)
         VALUES (:ozet, :url, :sonuc, :kapsam, :tarama, :kanonik, :robots, :getirme, NOW())
         ON DUPLICATE KEY UPDATE sonuc = VALUES(sonuc), kapsam = VALUES(kapsam),
                                 son_tarama = VALUES(son_tarama), google_kanonik = VALUES(google_kanonik),
                                 robots = VALUES(robots), getirme = VALUES(getirme), denetlendi = NOW()'
    )->execute([
        'ozet'    => hash('sha256', $url),
        'url'     => mb_substr($url, 0, 500),
        'sonuc'   => mb_substr((string) ($d['verdict'] ?? ''), 0, 20),
        'kapsam'  => mb_substr((string) ($d['coverageState'] ?? ''), 0, 200),
        'tarama'  => $tarama,
        'kanonik' => mb_substr((string) ($d['googleCanonical'] ?? ''), 0, 500) ?: null,
        'robots'  => mb_substr((string) ($d['robotsTxtState'] ?? ''), 0, 40),
        'getirme' => mb_substr((string) ($d['pageFetchState'] ?? ''), 0, 60),
    ]);

    return ['tamam' => true, 'hata' => ''];
}

/**
 * Hepsini tazeler.
 *
 * Her adim bagimsiz: biri duserse digerleri yine calisiyor ve hata
 * listeye yaziliyor. Son iyi veri silinmiyor.
 *
 * @return array{tamam:bool,site:string,gun:int,sorgu:int,sayfa:int,denetlenen:int,hatalar:list<string>}
 */
function gsc_tazele(int $sureButcesi = 60): array
{
    $baslangic = time();
    $ozet = ['tamam' => false, 'site' => '', 'gun' => 0, 'sorgu' => 0, 'sayfa' => 0, 'denetlenen' => 0, 'hatalar' => []];

    ayar_yaz('gsc_son_deneme', (string) time());

    $jeton = gsc_jeton();

    if (!$jeton['tamam']) {
        $ozet['hatalar'][] = $jeton['hata'];
        ayar_yaz('gsc_son_hata', $jeton['hata']);

        return $ozet;
    }

    $site = gsc_site($jeton['jeton']);

    if (!$site['tamam']) {
        $ozet['hatalar'][] = $site['hata'];
        ayar_yaz('gsc_son_hata', $site['hata']);

        return $ozet;
    }

    $j = $jeton['jeton'];
    $s = $site['site'];
    $ozet['site'] = $s;

    // Veri 2-3 gun geriden geliyor; bitis dunden onceki gun.
    $bit = date('Y-m-d', strtotime('-2 days'));

    // 1) Gunluk toplamlar, son 16 ay (Search Console'un tuttugu en uzun sure).
    $gunluk = gsc_performans($j, $s, date('Y-m-d', strtotime('-485 days')), $bit, ['date'], 500);

    if ($gunluk['tamam']) {
        $ekle = db()->prepare(
            'INSERT INTO gsc_gunluk (tarih, tiklama, gosterim, ctr, sira)
             VALUES (:t, :k, :g, :c, :s)
             ON DUPLICATE KEY UPDATE tiklama = VALUES(tiklama), gosterim = VALUES(gosterim),
                                     ctr = VALUES(ctr), sira = VALUES(sira)'
        );

        foreach ($gunluk['satirlar'] as $r) {
            $ekle->execute(['t' => (string) $r['anahtar'][0], 'k' => $r['tiklama'], 'g' => $r['gosterim'],
                            'c' => round($r['ctr'], 4), 's' => round($r['sira'], 2)]);
        }

        $ozet['gun'] = count($gunluk['satirlar']);
    } else {
        $ozet['hatalar'][] = 'Günlük toplamlar: ' . $gunluk['hata'];
    }

    // 2) Son 28 gunun sorgulari ve sayfalari.
    $bas28 = date('Y-m-d', strtotime($bit . ' -27 days'));

    foreach (['query' => ['gsc_sorgular', 'sorgu'], 'page' => ['gsc_sayfalar', 'sayfa']] as $boyut => [$ayar, $alan]) {
        $sonuc = gsc_performans($j, $s, $bas28, $bit, [$boyut]);

        if ($sonuc['tamam']) {
            ayar_yaz($ayar, (string) json_encode(
                ['bas' => $bas28, 'bit' => $bit, 'satirlar' => $sonuc['satirlar']],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            $ozet[$alan] = count($sonuc['satirlar']);
        } else {
            $ozet['hatalar'][] = ($boyut === 'query' ? 'Sorgular: ' : 'Sayfalar: ') . $sonuc['hata'];
        }
    }

    // 3) Site haritalari.
    $haritalar = gsc_istek('GET', GSC_API . '/sites/' . rawurlencode($s) . '/sitemaps', null, $j);

    if ($haritalar['tamam']) {
        ayar_yaz('gsc_site_haritalari', (string) json_encode(
            (array) ($haritalar['veri']['sitemap'] ?? []),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    } else {
        $ozet['hatalar'][] = 'Site haritası: ' . $haritalar['hata'];
    }

    // 4) URL denetimi: hic denetlenmemisler once, sonra en eskiler.
    try {
        $denetlenmis = db()->query('SELECT url, denetlendi FROM gsc_denetim')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $denetlenmis = [];
    }

    $sinir  = time() - GSC_DENETIM_GUN * 86400;
    $adaylar = array_values(array_filter(
        gsc_denetim_adaylari(),
        static fn (string $u): bool => !isset($denetlenmis[$u]) || strtotime((string) $denetlenmis[$u]) < $sinir
    ));
    usort($adaylar, static fn (string $a, string $b): int
        => strtotime((string) ($denetlenmis[$a] ?? '1970-01-01')) <=> strtotime((string) ($denetlenmis[$b] ?? '1970-01-01')));

    foreach (array_slice($adaylar, 0, GSC_DENETIM_TUR) as $url) {
        if (time() - $baslangic >= $sureButcesi) {
            break;
        }

        $sonuc = gsc_url_denetle($j, $s, $url);

        if (!$sonuc['tamam']) {
            $ozet['hatalar'][] = 'URL denetimi (' . $url . '): ' . $sonuc['hata'];
            // Yetki ya da kota hatasinda kalanlari denemenin anlami yok.
            break;
        }

        $ozet['denetlenen']++;
    }

    $ozet['tamam'] = $ozet['gun'] > 0 || $ozet['sorgu'] > 0 || $ozet['denetlenen'] > 0;

    if ($ozet['tamam']) {
        ayar_yaz('gsc_son_tazeleme', date('Y-m-d H:i:s'));
    }

    ayar_yaz('gsc_son_hata', implode(' | ', $ozet['hatalar']));

    return $ozet;
}

/** Otomatik tazeleme zamani geldi mi? */
function gsc_tazelenmeli(): bool
{
    return gsc_hesap() !== null
        && time() - (int) ayar_oku('gsc_son_deneme', '0') >= GSC_TAZELEME_SAAT * 3600;
}

/* ------------------------------------------------------------------------
 * Panel icin okuma
 * --------------------------------------------------------------------- */

/**
 * Iki donemin toplami: son 28 gun ve ondan onceki 28 gun.
 *
 * @return array{simdi:array<string,float>,once:array<string,float>,bit:?string}
 */
function gsc_donem_ozeti(): array
{
    $bos = ['tiklama' => 0.0, 'gosterim' => 0.0, 'ctr' => 0.0, 'sira' => 0.0];

    try {
        $bit = db()->query('SELECT MAX(tarih) FROM gsc_gunluk')->fetchColumn();
    } catch (PDOException $e) {
        return ['simdi' => $bos, 'once' => $bos, 'bit' => null];
    }

    if (!$bit) {
        return ['simdi' => $bos, 'once' => $bos, 'bit' => null];
    }

    $toplam = static function (string $bas, string $son): array {
        $ifade = db()->prepare(
            'SELECT COALESCE(SUM(tiklama),0) t, COALESCE(SUM(gosterim),0) g,
                    COALESCE(SUM(sira * gosterim) / NULLIF(SUM(gosterim),0), 0) s
               FROM gsc_gunluk WHERE tarih BETWEEN :b AND :s'
        );
        $ifade->execute(['b' => $bas, 's' => $son]);
        $r = $ifade->fetch();

        $t = (float) $r['t'];
        $g = (float) $r['g'];

        return ['tiklama' => $t, 'gosterim' => $g, 'ctr' => $g > 0 ? $t / $g : 0.0, 'sira' => (float) $r['s']];
    };

    $bit = (string) $bit;

    return [
        'simdi' => $toplam(date('Y-m-d', strtotime($bit . ' -27 days')), $bit),
        'once'  => $toplam(date('Y-m-d', strtotime($bit . ' -55 days')), date('Y-m-d', strtotime($bit . ' -28 days'))),
        'bit'   => $bit,
    ];
}

/**
 * @return list<array{tarih:string,tiklama:int,gosterim:int}>
 */
function gsc_gunluk_seri(int $gun = 90): array
{
    try {
        $ifade = db()->prepare(
            'SELECT tarih, tiklama, gosterim FROM gsc_gunluk
              WHERE tarih >= (SELECT MAX(tarih) FROM gsc_gunluk) - INTERVAL :g DAY
              ORDER BY tarih'
        );
        $ifade->bindValue('g', $gun, PDO::PARAM_INT);
        $ifade->execute();

        return array_map(static fn (array $r): array => [
            'tarih' => (string) $r['tarih'], 'tiklama' => (int) $r['tiklama'], 'gosterim' => (int) $r['gosterim'],
        ], $ifade->fetchAll());
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Kayitli sorgu ya da sayfa tablosu.
 *
 * @return array{bas:string,bit:string,satirlar:list<array<string,mixed>>}
 */
function gsc_tablo(string $ayar): array
{
    $veri = json_decode(ayar_oku($ayar), true);

    return is_array($veri) && isset($veri['satirlar'])
        ? $veri + ['bas' => '', 'bit' => '']
        : ['bas' => '', 'bit' => '', 'satirlar' => []];
}

/**
 * Firsat sorgulari: cok gosterim alan ama ilk sayfanin altinda ya da
 * tiklanmayan aramalar. Bu sorgulara ozel icerik yazmak ya da mevcut
 * sayfayi gelistirmek en hizli kazanc.
 *
 * @param list<array<string,mixed>> $satirlar
 * @return list<array<string,mixed>>
 */
function gsc_firsatlar(array $satirlar, int $limit = 15): array
{
    $firsat = array_values(array_filter(
        $satirlar,
        static fn (array $r): bool => $r['gosterim'] >= 20 && ($r['sira'] > 8 || $r['ctr'] < 0.02)
    ));

    usort($firsat, static fn (array $a, array $b): int => $b['gosterim'] <=> $a['gosterim']);

    return array_slice($firsat, 0, $limit);
}

/**
 * @return list<array<string,mixed>>
 */
function gsc_denetimler(): array
{
    try {
        return db()->query(
            "SELECT * FROM gsc_denetim
              ORDER BY FIELD(sonuc, 'FAIL', 'NEUTRAL', 'PARTIAL', 'PASS'), denetlendi DESC"
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* ------------------------------------------------------------------------
 * Sayfa kalitesi (Google'a sormadan, kendi verimizden)
 * --------------------------------------------------------------------- */

/**
 * Baslik ve aciklama sorunlari.
 *
 * Google sonuc sayfasinda basligin yaklasik 60 karakterini gosteriyor;
 * ayni baslikli iki sayfa birbirinin rakibi oluyor; ozeti bos sayfanin
 * aciklamasini Google metinden rastgele kesiyor.
 *
 * @return array<string,list<array<string,mixed>>>
 */
function gsc_kalite_denetimi(): array
{
    $sonuc = ['ayni_baslik' => [], 'uzun_baslik' => [], 'kisa_ozet' => []];

    try {
        $sonuc['ayni_baslik'] = db()->query(
            "SELECT baslik, COUNT(*) AS adet, MIN(slug) AS slug FROM haberler
              WHERE durum = 'yayinda' GROUP BY baslik HAVING adet > 1 ORDER BY adet DESC LIMIT 20"
        )->fetchAll();

        $sonuc['uzun_baslik'] = db()->query(
            "SELECT baslik, slug, CHAR_LENGTH(baslik) AS uzunluk FROM haberler
              WHERE durum = 'yayinda' AND CHAR_LENGTH(baslik) > 70
              ORDER BY yayin_tarihi DESC LIMIT 20"
        )->fetchAll();

        $sonuc['kisa_ozet'] = db()->query(
            "SELECT baslik, slug, CHAR_LENGTH(COALESCE(ozet,'')) AS uzunluk FROM haberler
              WHERE durum = 'yayinda' AND CHAR_LENGTH(COALESCE(ozet,'')) < 70
              ORDER BY yayin_tarihi DESC LIMIT 20"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('[valentra] kalite denetimi: ' . $e->getMessage());
    }

    return $sonuc;
}
