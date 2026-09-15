<?php
declare(strict_types=1);

/**
 * Kaynak tanı aracı.
 *
 * Her kaynak icin iki yolu da dener ve neden calismadigini soyler:
 *   RSS   -> HTTP kodu, icerik turu, kac girdi, kaci son N saatte
 *   Kazima -> sayfadaki baglanti sayisi, elemeden gecen aday sayisi,
 *             en kalabalik adres kaliplari, ornek baglantilar
 *
 * Neden ayri bir arac: paylasimli hostingin CA deposu eksik oldugu icin
 * panelden yapilan test bazi kaynaklarda SSL hatasi veriyor, ama ajan
 * GitHub uzerinde calistigi icin ayni kaynagi okuyabiliyor. Gercegi
 * gormek icin testin ajanin kostugu yerde kosmasi gerekiyor.
 *
 * Model cagrisi yapmaz, siteye hicbir sey yazmaz; yalnizca okur.
 *
 * Calistirma:
 *   php ajan/kaynak_dene.php [--saat=36] [--kaynak=ad-parcasi]
 */

require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Besleme.php';
require_once __DIR__ . '/src/Kazima.php';
require_once __DIR__ . '/src/Suzgec.php';
require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/kazima.php';

use Valentra\Ajan\{Besleme, Http, Site, Suzgec};

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

$secenekler = getopt('', ['saat::', 'kaynak::']);
$saat       = max(1, (int) ($secenekler['saat'] ?? 36));
$suzgu      = trim((string) ($secenekler['kaynak'] ?? ''));

function yaz(string $mesaj = ''): void
{
    echo $mesaj . PHP_EOL;
}

/**
 * Sutun hizalamasi icin dolgu.
 *
 * sprintf'in %-20s dolgusu bayt sayar; "Kazıma" gibi Turkce karakterli
 * bir metin oldugundan kisa gorunup sutunlari kaydiriyor.
 */
function dolgu(string $metin, int $genislik): string
{
    $eksik = $genislik - mb_strlen($metin, 'UTF-8');

    return $eksik > 0 ? $metin . str_repeat(' ', $eksik) : $metin;
}

function ayar(string $ad): string
{
    $deger = getenv($ad);

    if (!is_string($deger) || trim($deger) === '') {
        fwrite(STDERR, "HATA: {$ad} ortam değişkeni tanımlı değil.\n");
        exit(1);
    }

    return trim($deger);
}

$site    = new Site(ayar('VALENTRA_SITE_URL'), ayar('VALENTRA_AGENT_KEY'));
$http    = new Http();
$besleme = new Besleme($http);
$suzgec  = new Suzgec();

try {
    $yapilandirma = $site->yapilandirma();
} catch (Throwable $e) {
    fwrite(STDERR, 'Yapılandırma alınamadı: ' . $e->getMessage() . "\n");
    exit(1);
}

$kaynaklar = $yapilandirma['kaynaklar'] ?? [];

if ($suzgu !== '') {
    $kaynaklar = array_values(array_filter(
        $kaynaklar,
        static fn (array $k): bool => mb_stripos((string) $k['ad'], $suzgu) !== false
    ));
}

yaz(count($kaynaklar) . ' kaynak sınanacak (son ' . $saat . ' saat).');
yaz();

/** @var list<array{ad:string,yol:string,not:string}> */
$ozet = [];

foreach ($kaynaklar as $kaynak) {
    $ad         = (string) $kaynak['ad'];
    $beslemeUrl = trim((string) ($kaynak['besleme_url'] ?? ''));
    $listeUrl   = trim((string) ($kaynak['liste_url'] ?? ''));
    $secici     = trim((string) ($kaynak['liste_secici'] ?? ''));

    yaz('=== ' . $ad . ' ===');

    $calisanYol = '';
    $not        = '';

    // --- RSS ---------------------------------------------------------------
    if ($beslemeUrl === '') {
        yaz('  RSS: adres tanımlı değil');
    } else {
        $yanit = $http->dene($beslemeUrl);
        $tur   = strtok($yanit['tur'], ';') ?: '?';

        if ($yanit['govde'] === null || $yanit['kod'] < 200 || $yanit['kod'] >= 300) {
            yaz(sprintf(
                '  RSS: BAŞARISIZ — HTTP %d %s',
                $yanit['kod'],
                $yanit['hata'] !== '' ? '(' . $yanit['hata'] . ')' : ''
            ));
            $not = 'RSS: HTTP ' . $yanit['kod'];
        } else {
            $girdiler = $besleme->oku($beslemeUrl, $saat);
            $tumu     = $besleme->oku($beslemeUrl, 24 * 365);

            yaz(sprintf(
                '  RSS: HTTP %d, %s, %d bayt — toplam %d girdi, son %d saatte %d',
                $yanit['kod'],
                $tur,
                $yanit['boyut'],
                count($tumu),
                $saat,
                count($girdiler)
            ));

            if ($tumu === []) {
                // 200 dondu ama girdi yok: genelde XML degil HTML gelmistir.
                yaz('       XML olarak ayrıştırılamadı ya da girdi yok. İlk 120 karakter:');
                yaz('       ' . mb_substr(trim(preg_replace('/\s+/u', ' ', $yanit['govde']) ?? ''), 0, 120));
                $not = 'RSS: 200 ama girdi yok';
            } else {
                $calisanYol = 'RSS';
                $not = count($girdiler) . ' girdi';
            }

            $gecen = 0;

            foreach ($girdiler as $girdi) {
                if ($suzgec->gecer($girdi['baslik'], $girdi['ozet'])) {
                    $gecen++;
                }
            }

            if ($girdiler !== []) {
                yaz('       Ön elemeden geçen: ' . $gecen);

                foreach (array_slice($girdiler, 0, 3) as $girdi) {
                    $p = $suzgec->puanlar($girdi['baslik'], $girdi['ozet']);
                    yaz(sprintf(
                        '       [v:%d e:%d] %s',
                        $p['vergi'],
                        $p['ekonomi'],
                        mb_substr($girdi['baslik'], 0, 90)
                    ));
                }
            }
        }
    }

    // --- Kazima ------------------------------------------------------------
    if ($listeUrl === '') {
        yaz('  Kazıma: adres tanımlı değil');
    } else {
        $yanit = $http->dene($listeUrl);

        if ($yanit['govde'] === null || $yanit['kod'] < 200 || $yanit['kod'] >= 300) {
            yaz(sprintf(
                '  Kazıma: BAŞARISIZ — HTTP %d %s',
                $yanit['kod'],
                $yanit['hata'] !== '' ? '(' . $yanit['hata'] . ')' : ''
            ));

            if ($not === '') {
                $not = 'Kazıma: HTTP ' . $yanit['kod'];
            }
        } else {
            $tani = kazima_tani($yanit['govde'], $listeUrl, $secici);

            yaz(sprintf(
                '  Kazıma: HTTP %d, %d bayt — sayfada %d bağlantı, %d aday, sonuç %d',
                $yanit['kod'],
                $yanit['boyut'],
                $tani['toplam_a'],
                $tani['aday'],
                $tani['sonuc']
            ));

            if ($tani['kapsam_var']) {
                yaz('       Seçici kullanıldı: ' . $secici);
            }

            if ($tani['kaliplar'] !== []) {
                $parcalar = [];

                foreach ($tani['kaliplar'] as $kalip => $adet) {
                    $parcalar[] = $kalip . ' (' . $adet . ')';
                }

                yaz('       En kalabalık kalıplar: ' . implode(', ', $parcalar));
            }

            if ($tani['sonuc'] === 0) {
                if ($tani['aday'] === 0) {
                    yaz('       Hiçbir bağlantı elemeden geçmedi: başlıklar 20 karakterden');
                    yaz('       kısa olabilir ya da bağlantılar başka alan adına gidiyor.');
                } elseif ($tani['en_iyi_adet'] < 3) {
                    yaz('       En kalabalık kalıpta 3\'ten az bağlantı var; haber listesi');
                    yaz('       sayılmadı. Sayfa doğru duyuru sayfası mı?');
                }

                foreach ($tani['ornekler'] as $ornek) {
                    yaz('       örnek: ' . mb_substr($ornek, 0, 120));
                }

                if ($not === '') {
                    $not = 'Kazıma: sonuç yok';
                }
            } elseif ($calisanYol === '') {
                $calisanYol = 'Kazıma';
                $not = $tani['sonuc'] . ' bağlantı';

                foreach ($tani['ornekler'] as $ornek) {
                    yaz('       örnek: ' . mb_substr($ornek, 0, 120));
                }
            }
        }
    }

    $ozet[] = ['ad' => $ad, 'yol' => $calisanYol, 'not' => $not];
    yaz();
}

// --- Ozet ------------------------------------------------------------------

yaz('================ ÖZET ================');

$calisan = array_values(array_filter($ozet, static fn (array $o): bool => $o['yol'] !== ''));
$bozuk   = array_values(array_filter($ozet, static fn (array $o): bool => $o['yol'] === ''));

yaz();
yaz('ÇALIŞAN (' . count($calisan) . ')');

foreach ($calisan as $o) {
    yaz('  ' . dolgu($o['ad'], 26) . ' ' . dolgu($o['yol'], 7) . ' ' . $o['not']);
}

yaz();
yaz('ÇALIŞMAYAN (' . count($bozuk) . ')');

foreach ($bozuk as $o) {
    yaz('  ' . dolgu($o['ad'], 26) . ' ' . ($o['not'] !== '' ? $o['not'] : 'adres tanımlı değil'));
}

yaz();
yaz('Çalışmayanları panelden düzeltin ya da kapatın; kapalı kaynak taranmaz.');
