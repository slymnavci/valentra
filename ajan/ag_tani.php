<?php
declare(strict_types=1);

/**
 * Ağ tanı aracı — barındırıcıya verilecek kanıtı üretir.
 *
 * NEDEN VAR: IHS, 443 portundaki zaman asimini inceleyebilmek icin
 * baglanan sunucunun dis IP adresini istedi. GitHub Actions'in SABIT
 * bir IP'si yok; her calisma Azure havuzundan farkli bir adres aliyor.
 * Bu yuzden "IP" degil, "IP + saat + sonuc" ucluleri vermek gerekiyor;
 * kayitlarda aranacak olan budur.
 *
 * Arac ayrica KONTROL baglantisi yapiyor. Yalnizca "valentra'ya
 * baglanamadik" demek yetersiz: sorunun kosan makinede mi yoksa hedef
 * sunucuda mi oldugunu ayirt etmek gerekir. Ayni anda baska bir
 * adrese baglanabiliyorsak, calisan makinenin agi saglamdir.
 *
 * Model cagrisi yapmaz, siteye veri yazmaz; yalnizca baglanir.
 *
 *   php ajan/ag_tani.php
 */

date_default_timezone_set('Europe/Istanbul');

function yaz(string $s = ''): void
{
    echo $s . PHP_EOL;
}

/**
 * Dış IP adresini öğrenir.
 *
 * Birden cok servis deneniyor: biri kapali ya da yavas olabilir ve
 * tani araci tam da bu yuzden ise yaramaz hale gelmemeli.
 */
function disIp(int $surum): string
{
    $servisler = $surum === 6
        ? ['https://api6.ipify.org', 'https://v6.ident.me']
        : ['https://api.ipify.org', 'https://v4.ident.me', 'https://ifconfig.me/ip'];

    foreach ($servisler as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_IPRESOLVE      => $surum === 6 ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
        ]);
        $yanit = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (is_string($yanit) && $kod === 200 && trim($yanit) !== '') {
            return trim($yanit);
        }
    }

    return '(alınamadı)';
}

/**
 * Bir adrese bağlanmayı dener ve ne olduğunu döndürür.
 *
 * @return array{tamam:bool,kod:int,sure:float,hata:string,ip:string}
 */
function baglan(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                . 'Chrome/128.0.0.0 Safari/537.36',
    ]);

    $bas = microtime(true);
    curl_exec($ch);

    $sonuc = [
        'tamam' => curl_errno($ch) === 0,
        'kod'   => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'sure'  => round(microtime(true) - $bas, 2),
        'hata'  => curl_error($ch),
        'ip'    => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
    ];

    curl_close($ch);

    return $sonuc;
}

$site = getenv('VALENTRA_SITE_URL');
$site = is_string($site) && trim($site) !== '' ? rtrim(trim($site), '/') : 'https://valentra.com.tr';

yaz('================ AĞ TANI ================');
yaz('Tarih (TR saati): ' . date('d.m.Y H:i:s'));
yaz();
yaz('Bağlanan sunucunun dış IP adresi (barındırıcıya verilecek bilgi):');
yaz('  IPv4: ' . disIp(4));
yaz('  IPv6: ' . disIp(6));
yaz();
yaz('Bu adres GitHub Actions havuzundan alınmıştır ve her çalışmada');
yaz('değişebilir. Barındırıcı kayıtlarında YUKARIDAKI SAAT ile birlikte');
yaz('aranmalıdır.');
yaz();

/*
 * Hedef ile birlikte KONTROL adresleri.
 *
 * Kontroller olmadan "baglanamadik" ifadesi tek basina bir sey
 * kanitlamaz; makinenin agi bozuk da olabilir. Kontroller basarili
 * olup hedef duserse sorun hedef sunucudadir.
 */
$hedefler = [
    'valentra.com.tr (hedef)' => $site,
    'github.com (kontrol)'    => 'https://github.com',
    'google.com (kontrol)'    => 'https://www.google.com',
];

$tekrar = 3;

for ($i = 1; $i <= $tekrar; $i++) {
    yaz("--- Deneme {$i}/{$tekrar} — " . date('H:i:s') . ' ---');

    foreach ($hedefler as $ad => $url) {
        $s = baglan($url);

        yaz(sprintf(
            '  %-24s %s  (%.2f sn%s%s)',
            $ad,
            $s['tamam'] ? 'HTTP ' . $s['kod'] : 'BAŞARISIZ',
            $s['sure'],
            $s['ip'] !== '' ? ', sunucu IP ' . $s['ip'] : '',
            $s['hata'] !== '' ? ', ' . $s['hata'] : ''
        ));
    }

    if ($i < $tekrar) {
        sleep(10);
    }
}

yaz();
yaz('Kontrol adresleri başarılı ve yalnızca hedef başarısızsa, sorun');
yaz('çalışan makinenin ağında değil hedef sunucudadır.');
