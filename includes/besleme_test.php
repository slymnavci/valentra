<?php
declare(strict_types=1);

/**
 * Bir RSS/Atom beslemesini deneyip sonucu raporlar.
 *
 * Kaynak adreslerinin calisip calismadigini panelden gormek icin.
 * Ajanin kullandigi ayristirma mantiginin aynisini uygular.
 *
 * @return array{tamam:bool,mesaj:string,adet:int,ornek:string}
 */
function besleme_dene(string $url, int $zamanAsimi = 15): array
{
    $url = guvenli_url($url);

    if ($url === '') {
        return ['tamam' => false, 'mesaj' => 'Adres http:// veya https:// ile başlamalı.', 'adet' => 0, 'ornek' => ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $zamanAsimi,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'ValentraBot/1.0 (+https://valentra.com.tr)',
        CURLOPT_ENCODING       => '',
    ]);

    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata  = curl_error($ch);
    $hataNo = curl_errno($ch);
    curl_close($ch);

    if (!is_string($govde)) {
        return [
            'tamam' => false,
            'mesaj' => besleme_baglanti_hatasi($hataNo, $hata),
            'adet'  => 0,
            'ornek' => '',
        ];
    }

    if ($kod < 200 || $kod >= 300) {
        return ['tamam' => false, 'mesaj' => 'Sunucu HTTP ' . $kod . ' döndü.', 'adet' => 0, 'ornek' => ''];
    }

    $onceki = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($govde);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);

    if ($xml === false) {
        return [
            'tamam' => false,
            'mesaj' => 'Yanıt geçerli XML değil. Adres RSS beslemesi yerine normal sayfa olabilir.',
            'adet'  => 0,
            'ornek' => '',
        ];
    }

    $ogeler = [];

    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $oge) {
            $ogeler[] = trim((string) $oge->title);
        }
    } elseif (isset($xml->entry)) {
        foreach ($xml->entry as $oge) {
            $ogeler[] = trim((string) $oge->title);
        }
    }

    if ($ogeler === []) {
        return [
            'tamam' => false,
            'mesaj' => 'XML okundu ama içinde haber öğesi (item/entry) yok.',
            'adet'  => 0,
            'ornek' => '',
        ];
    }

    return [
        'tamam' => true,
        'mesaj' => count($ogeler) . ' haber okundu.',
        'adet'  => count($ogeler),
        'ornek' => mb_substr($ogeler[0], 0, 110, 'UTF-8'),
    ];
}

/**
 * cURL hatasını anlaşılır bir mesaja çevirir.
 *
 * SSL hataları özel önem taşıyor: bu test sizin hosting sunucunuzda
 * çalışır, ajan ise GitHub üzerinde. Sunucunun kök sertifika deposu
 * eskiyse test başarısız olur ama ajan aynı kaynağı sorunsuz okuyabilir.
 * Bu ikisini ayırt edebilmek gerekiyor.
 */
function besleme_baglanti_hatasi(int $hataNo, string $hata): string
{
    // 60: sertifika dogrulanamadi, 35: SSL el sikismasi, 77: CA dosyasi okunamadi
    if (in_array($hataNo, [35, 51, 58, 59, 60, 77, 83], true)) {
        $suresiDolmus = stripos($hata, 'expired') !== false;

        if ($suresiDolmus) {
            return 'Kaynağın güvenlik sertifikasının süresi dolmuş. Bu kaynak tarafındaki '
                 . 'bir sorundur; düzeltilene kadar ajan da okuyamaz.';
        }

        return 'Güvenlik sertifikası doğrulanamadı. Bu genelde hosting sunucunuzun kök '
             . 'sertifika listesinin eski olmasından kaynaklanır — ajan GitHub üzerinde '
             . 'çalıştığı için bu kaynağı yine de okuyabilir. Kaynağı kapatmadan önce '
             . 'ajanı kuru modda çalıştırıp deneyin.';
    }

    if ($hataNo === 28) {
        return 'Sunucu zamanında yanıt vermedi (zaman aşımı).';
    }

    if (in_array($hataNo, [6, 7], true)) {
        return 'Sunucuya ulaşılamadı. Adres yanlış olabilir ya da site kapalı.';
    }

    return 'Bağlantı kurulamadı: ' . $hata;
}

/**
 * Göreli adresi mutlak adrese çevirir.
 */
function besleme_url_birlestir(string $taban, string $goreli): string
{
    if (preg_match('#^https?://#i', $goreli) === 1) {
        return $goreli;
    }

    $parca = parse_url($taban);

    if (!isset($parca['scheme'], $parca['host'])) {
        return '';
    }

    $kok = $parca['scheme'] . '://' . $parca['host']
         . (isset($parca['port']) ? ':' . $parca['port'] : '');

    if (str_starts_with($goreli, '//')) {
        return $parca['scheme'] . ':' . $goreli;
    }

    if (str_starts_with($goreli, '/')) {
        return $kok . $goreli;
    }

    $yol = $parca['path'] ?? '/';
    $dizin = substr($yol, 0, (int) strrpos($yol, '/') + 1);

    return $kok . $dizin . $goreli;
}

/**
 * Sitenin RSS/Atom beslemesini bulur.
 *
 * Önce sayfanın <head> bölümündeki ilanına bakar — siteler beslemelerini
 * <link rel="alternate" type="application/rss+xml"> ile duyurur, bu
 * standart yöntemdir. Bulunamazsa yaygın adresleri dener.
 *
 * Bulunan her aday gerçekten çekilip denenir; yalnızca içinde haber olan
 * bir adres döndürülür.
 *
 * @return array{bulundu:bool,url:string,mesaj:string,denenen:int}
 */
function besleme_kesfet(string $siteUrl, int $enFazlaDeneme = 8): array
{
    $siteUrl = guvenli_url($siteUrl);

    if ($siteUrl === '') {
        return ['bulundu' => false, 'url' => '', 'mesaj' => 'Site adresi geçersiz.', 'denenen' => 0];
    }

    $adaylar = [];

    // 1) Sayfanın kendi ilanı
    $ch = curl_init($siteUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'ValentraBot/1.0 (+https://valentra.com.tr)',
        CURLOPT_ENCODING       => '',
    ]);
    $html = curl_exec($ch);
    $sonAdres = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if (is_string($html) && $html !== '') {
        $belge = new DOMDocument();
        $onceki = libxml_use_internal_errors(true);
        $belge->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        foreach ($belge->getElementsByTagName('link') as $dugum) {
            $tur = strtolower(trim($dugum->getAttribute('type')));
            $rel = strtolower(trim($dugum->getAttribute('rel')));
            $adres = trim($dugum->getAttribute('href'));

            if ($adres === '' || !str_contains($rel, 'alternate')) {
                continue;
            }

            if ($tur === 'application/rss+xml' || $tur === 'application/atom+xml' || $tur === 'text/xml') {
                $mutlak = besleme_url_birlestir($sonAdres !== '' ? $sonAdres : $siteUrl, $adres);

                if ($mutlak !== '') {
                    $adaylar[] = $mutlak;
                }
            }
        }
    }

    // 2) Yaygın adresler
    foreach (['/rss', '/feed', '/rss.xml', '/feed.xml', '/atom.xml', '/index.xml', '/?feed=rss2'] as $yol) {
        $adaylar[] = rtrim($siteUrl, '/') . $yol;
    }

    $adaylar = array_values(array_unique($adaylar));
    $denenen = 0;

    foreach ($adaylar as $aday) {
        if ($denenen >= $enFazlaDeneme) {
            break;
        }

        $denenen++;
        $sonuc = besleme_dene($aday, 10);

        if ($sonuc['tamam']) {
            return [
                'bulundu' => true,
                'url'     => $aday,
                'mesaj'   => $sonuc['mesaj'] . ' (' . $denenen . ' adres denendi)',
                'denenen' => $denenen,
            ];
        }
    }

    return [
        'bulundu' => false,
        'url'     => '',
        'mesaj'   => $denenen . ' adres denendi, çalışan besleme bulunamadı. '
                   . 'Site RSS yayınlamıyor olabilir.',
        'denenen' => $denenen,
    ];
}
