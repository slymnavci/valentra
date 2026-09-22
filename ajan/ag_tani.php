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

/*
 * PORT PORT TCP DENEMESI — barindiricinin "bize hicbir baglanti
 * istegi ulasmadi" cevabina karsi.
 *
 * Barindirici ag katmaninda hicbir istek gormedigini bildirdi. Oysa
 * AYNI GitHub Actions ortamindan ayni sunucuya FTP ile baglanilip
 * dosya yukleniyor: 20.09 22:01'de dagitim basariyla tamamlandi,
 * 22:14'te ayni sunucunun 443 portu zaman asimina ugradi. Yani
 * paketler o aga ulasiyor; ulasmayan yalnizca 443.
 *
 * Bu bolum bunu TEK BIR CALISMADA, saniyeler arayla kanitliyor:
 * ayni makineden ayni IP'ye uc ayri porta ham TCP baglantisi
 * deneniyor. HTTP katmani yok, TLS yok — yalnizca el sikismasi.
 *
 * Adrese DOGRUDAN IP ile gidiliyor ki DNS bir degisken olarak
 * kalmasin; cozumlenen IP de yaziliyor. Barindiricinin sunucusu
 * baska bir adresteyse bu satirdan anlasilir.
 */
yaz();
yaz('================ PORT DENEMESİ ================');
yaz();

$sunucu = (string) parse_url($site, PHP_URL_HOST);
$ip     = gethostbyname($sunucu);

yaz('Alan adı : ' . $sunucu);
yaz('Çözümlenen IP : ' . ($ip !== $sunucu ? $ip : '(çözümlenemedi)'));
yaz('Saat (TR) : ' . date('d.m.Y H:i:s'));
yaz();

if ($ip === $sunucu) {
    yaz('IP çözümlenemediği için port denemesi yapılamadı.');
} else {
    /*
     * FTP ozellikle listede: dagitim isi bu portu kullaniyor ve
     * calisiyor. Ayni calismada 21 acilip 443 acilmazsa, sorunun
     * "bu IP'den bize paket gelmiyor" olmadigi kanitlanmis olur.
     */
    $portlar = [
        21  => 'FTP   (dağıtım bu portu kullanıyor ve çalışıyor)',
        80  => 'HTTP',
        443 => 'HTTPS (ajanın kullandığı port)',
    ];

    foreach ($portlar as $port => $aciklama) {
        $basla = microtime(true);
        $hataNo = 0;
        $hataMetni = '';

        // 10 saniye yeterli: acik bir port milisaniyelerde cevap
        // verir, filtrelenen port zaten hic cevap vermez.
        $soket = @fsockopen($ip, $port, $hataNo, $hataMetni, 10);
        $sure  = microtime(true) - $basla;

        if ($soket !== false) {
            fclose($soket);
            yaz(sprintf('  %s:%-4d AÇIK        (%.2f sn)  %s', $ip, $port, $sure, $aciklama));
        } else {
            yaz(sprintf(
                '  %s:%-4d BAŞARISIZ   (%.2f sn)  %s — %s',
                $ip,
                $port,
                $sure,
                $aciklama,
                $hataMetni !== '' ? $hataMetni : 'hata ' . $hataNo
            ));
        }
    }

    yaz();
    yaz('21 açık ve 443 kapalıysa: paketler bu IP adresinden sunucunun');
    yaz('ağına ULAŞIYOR demektir. O hâlde sorun "bize istek gelmiyor"');
    yaz('değil, yalnızca 443 portunun filtrelenmesidir.');
}
