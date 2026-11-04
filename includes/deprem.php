<?php
declare(strict_types=1);

/**
 * Son depremler: canlı liste ve otomatik deprem haberleri.
 *
 * Site sahibinin istegi (03.11.2026, "baska neler yapabiliriz" onerisi):
 * Turkiye'de "son depremler", "deprem mi oldu" en cok aranan sorgulardan.
 * Veri AFAD'in acik deprem servisinden (resmi kaynak), ulasilamazsa
 * Kandilli Rasathanesi'nin son depremler listesinden.
 *
 *   /son-depremler            canli liste (iki dakikada bir tazelenir)
 *   deprem_haberleri_uret()   DEPREM_HABER_ESIGI ve ustu Turkiye depremine
 *                             sablonla haber (Valentra Veri; modelsiz,
 *                             rakamlar verinin kendisinden)
 *
 * Haber, sayfalarin sonunda (yanittan sonra) en fazla uc dakikada bir
 * denetleniyor (deprem_denetle_sayfa_sonunda), ayrica canli veri is akisi
 * her durttugunde.
 */

require_once __DIR__ . '/canli_onbellek.php';
require_once __DIR__ . '/ayarlar.php';

/** Bu büyüklük ve üstü Türkiye depremi için haber yazılır. */
const DEPREM_HABER_ESIGI = 4.0;

/** Listede gösterilen en küçük büyüklük. */
const DEPREM_LISTE_ESIGI = 1.5;

/** Haberi yazılacak depremin en fazla yaşı (dakika); eskisi atlanır. */
const DEPREM_HABER_YAS_SINIRI = 180;

/** Liste önbelleği (saniye). */
const DEPREM_ONBELLEK = 120;

const DEPREM_AFAD_API = 'https://deprem.afad.gov.tr/apiv2/event/filter';
const DEPREM_KANDILLI = 'http://www.koeri.boun.edu.tr/scripts/lst0.asp';

/** Son depremler sayfasının adresi. */
function deprem_yolu(): string
{
    return temiz_adres_acik() ? '/son-depremler' : '/deprem.php';
}

/**
 * Son 24 saatin depremleri, yeniden eskiye.
 *
 * @return array{liste:list<array<string,mixed>>,zaman:int,kaynak:string}
 */
function deprem_son_liste(): array
{
    $kayit = canli_onbellek('deprem_liste', DEPREM_ONBELLEK, static function (): ?array {
        $liste = deprem_afad_getir(new DateTimeImmutable('-24 hours'), DEPREM_LISTE_ESIGI);
        $kaynak = 'AFAD';

        if ($liste === null) {
            $liste  = deprem_kandilli_getir();
            $kaynak = 'Kandilli Rasathanesi';
        }

        return $liste === null ? null : ['liste' => $liste, 'kaynak' => $kaynak];
    });

    return [
        'liste'  => (array) ($kayit['veri']['liste'] ?? []),
        'zaman'  => (int) $kayit['zaman'],
        'kaynak' => (string) ($kayit['veri']['kaynak'] ?? ''),
    ];
}

/**
 * AFAD açık deprem servisi. Servisin saatleri UTC; Türkiye saatine
 * çevriliyor.
 *
 * @return list<array<string,mixed>>|null
 */
function deprem_afad_getir(DateTimeImmutable $baslangic, float $enKucuk, int $limit = 500, string $il = ''): ?array
{
    $utc = new DateTimeZone('UTC');
    $parametre = [
        'start'   => $baslangic->setTimezone($utc)->format('Y-m-d\TH:i:s'),
        'end'     => (new DateTimeImmutable('+1 hour', $utc))->format('Y-m-d\TH:i:s'),
        'minmag'  => number_format($enKucuk, 1, '.', ''),
        'orderby' => 'timedesc',
        'limit'   => (string) $limit,
    ];

    $govde = canli_indir(DEPREM_AFAD_API . '?' . http_build_query($parametre), 10);
    $veri  = $govde !== null ? json_decode($govde, true) : null;

    if (!is_array($veri) || !array_is_list($veri)) {
        return null;
    }

    return deprem_afad_coz($veri, $il);
}

/**
 * @param list<mixed> $veri
 * @return list<array<string,mixed>>
 */
function deprem_afad_coz(array $veri, string $il = ''): array
{
    $liste = [];
    $tr    = new DateTimeZone('Europe/Istanbul');

    foreach ($veri as $d) {
        if (!is_array($d) || !isset($d['magnitude'], $d['date'])) {
            continue;
        }

        try {
            $zaman = (new DateTimeImmutable((string) $d['date'], new DateTimeZone('UTC')))->setTimezone($tr);
        } catch (Exception $e) {
            continue;
        }

        $kayit = [
            'id'        => 'afad-' . (string) ($d['eventID'] ?? $zaman->getTimestamp()),
            'zaman'     => $zaman->getTimestamp(),
            'buyukluk'  => round((float) $d['magnitude'], 1),
            'tur'       => (string) ($d['type'] ?? ''),
            'derinlik'  => round((float) ($d['depth'] ?? 0), 1),
            'enlem'     => (float) ($d['latitude'] ?? 0),
            'boylam'    => (float) ($d['longitude'] ?? 0),
            'yer'       => trim((string) ($d['location'] ?? '')),
            'il'        => trim((string) ($d['province'] ?? '')),
            'ilce'      => trim((string) ($d['district'] ?? '')),
            'ulke'      => trim((string) ($d['country'] ?? '')),
        ];

        if ($il !== '' && mb_strtolower($kayit['il'], 'UTF-8') !== mb_strtolower($il, 'UTF-8')) {
            continue;
        }

        $liste[] = $kayit;
    }

    usort($liste, static fn (array $a, array $b): int => $b['zaman'] <=> $a['zaman']);

    return $liste;
}

/**
 * Kandilli'nin son 500 deprem listesi (düz metin; saatler Türkiye saati).
 *
 * @return list<array<string,mixed>>|null
 */
function deprem_kandilli_getir(): ?array
{
    $govde = canli_indir(DEPREM_KANDILLI, 10);

    if ($govde === null) {
        return null;
    }

    $liste = deprem_kandilli_coz($govde);

    return $liste === [] ? null : $liste;
}

/** @return list<array<string,mixed>> */
function deprem_kandilli_coz(string $govde): array
{
    if (!mb_check_encoding($govde, 'UTF-8')) {
        $govde = (string) mb_convert_encoding($govde, 'UTF-8', 'Windows-1254');
    }

    $liste = [];
    $tr    = new DateTimeZone('Europe/Istanbul');
    $sinir = time() - 86400;

    foreach (preg_split('/\R/u', $govde) ?: [] as $satir) {
        // 2026.11.03 09:12:34  39.1234   28.1234        7.0      -.-  1.8  -.-   SINDIRGI (BALIKESIR)    İlksel
        if (preg_match('~^(\d{4}\.\d{2}\.\d{2}) (\d{2}:\d{2}:\d{2})\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)\s+(.+?)\s{2,}~u', $satir, $m) !== 1) {
            continue;
        }

        $zaman = DateTimeImmutable::createFromFormat('Y.m.d H:i:s', $m[1] . ' ' . $m[2], $tr);

        if ($zaman === false || $zaman->getTimestamp() < $sinir) {
            continue;
        }

        // Buyukluk: ML, yoksa Mw, yoksa MD.
        $buyukluk = 0.0;

        foreach ([[$m[7], 'ML'], [$m[8], 'Mw'], [$m[6], 'MD']] as [$deger, $tur]) {
            if (is_numeric($deger) && (float) $deger > 0) {
                $buyukluk = (float) $deger;
                break;
            }
        }

        if ($buyukluk < DEPREM_LISTE_ESIGI) {
            continue;
        }

        $yer = trim(preg_replace('/\s+/u', ' ', $m[9]) ?? '');
        $il  = preg_match('~\(([^)]+)\)\s*$~u', $yer, $p) === 1 ? $p[1] : '';
        $yer = deprem_buyuk_harften($yer);

        $liste[] = [
            'id'       => 'kandilli-' . $zaman->format('YmdHis') . '-' . $m[3],
            'zaman'    => $zaman->getTimestamp(),
            'buyukluk' => round($buyukluk, 1),
            'tur'      => $tur,
            'derinlik' => round((float) $m[5], 1),
            'enlem'    => (float) $m[3],
            'boylam'   => (float) $m[4],
            'yer'      => $yer,
            'il'       => deprem_buyuk_harften($il),
            'ilce'     => '',
            // Kandilli yalnizca Turkiye ve cevresini veriyor; parantezde il
            // varsa Turkiye.
            'ulke'     => $il !== '' ? 'Türkiye' : '',
        ];
    }

    return $liste;
}

/** Türkiye'nin 81 ili (Kandilli'nin büyük harfli ASCII yazımını düzeltmek için). */
const DEPREM_ILLER = ['Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Aksaray', 'Amasya', 'Ankara', 'Antalya', 'Ardahan',
    'Artvin', 'Aydın', 'Balıkesir', 'Bartın', 'Batman', 'Bayburt', 'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa',
    'Çanakkale', 'Çankırı', 'Çorum', 'Denizli', 'Diyarbakır', 'Düzce', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum', 'Eskişehir',
    'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari', 'Hatay', 'Iğdır', 'Isparta', 'İstanbul', 'İzmir', 'Kahramanmaraş',
    'Karabük', 'Karaman', 'Kars', 'Kastamonu', 'Kayseri', 'Kilis', 'Kırıkkale', 'Kırklareli', 'Kırşehir', 'Kocaeli', 'Konya',
    'Kütahya', 'Malatya', 'Manisa', 'Mardin', 'Mersin', 'Muğla', 'Muş', 'Nevşehir', 'Niğde', 'Ordu', 'Osmaniye', 'Rize',
    'Sakarya', 'Samsun', 'Şanlıurfa', 'Siirt', 'Sinop', 'Sivas', 'Şırnak', 'Tekirdağ', 'Tokat', 'Trabzon', 'Tunceli', 'Uşak',
    'Van', 'Yalova', 'Yozgat', 'Zonguldak'];

/** Türkçe harfleri ASCII'ye indirip büyük harf: "İzmir" -> "IZMIR". */
function deprem_ascii(string $metin): string
{
    return strtoupper(strtr($metin, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
        'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U', 'i' => 'i']));
}

/**
 * Kandilli'nin büyük harfli yer adı -> okunur yazım. İl adları listeden
 * (doğru Türkçe yazım); geri kalanı: metinde Türkçe harf varsa I = ı,
 * yoksa (ASCII yazım) I = i.
 */
function deprem_buyuk_harften(string $metin): string
{
    static $iller = null;
    $iller ??= array_combine(array_map('deprem_ascii', DEPREM_ILLER), DEPREM_ILLER);

    $turkce = preg_match('/[ÇĞİÖŞÜçğıöşü]/u', $metin) === 1;
    $kucuk  = mb_strtolower($turkce ? str_replace(['I', 'İ'], ['ı', 'i'], $metin) : str_replace('I', 'i', $metin), 'UTF-8');

    $metin = (string) preg_replace_callback('/(^|[\s(\-])(\p{L})/u', static function (array $m): string {
        $harf = $m[2] === 'i' ? 'İ' : ($m[2] === 'ı' ? 'I' : mb_strtoupper($m[2], 'UTF-8'));

        return $m[1] . $harf;
    }, $kucuk);

    return (string) preg_replace_callback('/\p{L}+/u', static function (array $m) use ($iller): string {
        return $iller[deprem_ascii($m[0])] ?? $m[0];
    }, $metin);
}

/** Depremin yerini okunur yazar: "Sındırgı (Balıkesir)". */
function deprem_yer_adi(array $d): string
{
    if ($d['ilce'] !== '' && $d['il'] !== '') {
        return $d['ilce'] . ' (' . $d['il'] . ')';
    }

    return $d['yer'] !== '' ? $d['yer'] : ($d['il'] !== '' ? $d['il'] : 'Bilinmeyen yer');
}

/** Büyüklüğün Türkçe yazımı: 4.6 -> "4,6" */
function deprem_buyukluk_yazi(float $b): string
{
    return number_format($b, 1, ',', '');
}

/** Türkiye'de mi (haber yalnızca Türkiye depremleri için)? */
function deprem_turkiyede_mi(array $d): bool
{
    $ulke = mb_strtolower((string) $d['ulke'], 'UTF-8');

    return ($ulke === 'türkiye' || $ulke === 'turkiye' || $ulke === 'turkey') && $d['il'] !== '';
}

/* ------------------------------------------------------------------ *
 * Otomatik deprem haberleri
 * ------------------------------------------------------------------ */

/** Sayfa gönderildikten sonra, en fazla üç dakikada bir haber denetimi. */
function deprem_denetle_sayfa_sonunda(): void
{
    if (time() - (int) ayar_oku('deprem_denetim_son', '0') < 180) {
        return;
    }

    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        ignore_user_abort(true);
        @set_time_limit(60);

        try {
            deprem_haberleri_uret();
        } catch (Throwable $e) {
            error_log('[valentra] deprem haberi: ' . $e->getMessage());
        }
    });
}

/**
 * Eşik üstü yeni Türkiye depremleri için haber yazar.
 *
 * @return list<string> yapılanlar (günlük için)
 */
function deprem_haberleri_uret(): array
{
    ayar_yaz('deprem_denetim_son', (string) time());

    $liste = deprem_afad_getir(new DateTimeImmutable('-' . DEPREM_HABER_YAS_SINIRI . ' minutes'), DEPREM_HABER_ESIGI, 50);
    $kaynak = 'AFAD';

    if ($liste === null) {
        $liste  = array_values(array_filter(
            deprem_kandilli_getir() ?? [],
            static fn (array $d): bool => $d['buyukluk'] >= DEPREM_HABER_ESIGI
                && $d['zaman'] >= time() - DEPREM_HABER_YAS_SINIRI * 60
        ));
        $kaynak = 'Kandilli Rasathanesi';
    }

    $yazilan = json_decode(ayar_oku('deprem_yazilan'), true);
    $yazilan = is_array($yazilan) ? $yazilan : [];
    $sonuc   = [];

    // Eskiden yeniye: ardisik depremlerde ilki once yazilsin.
    foreach (array_reverse($liste) as $d) {
        if (!deprem_turkiyede_mi($d) || isset($yazilan[$d['id']]) || deprem_yakinda_yazildi($d, $yazilan)) {
            continue;
        }

        $h = deprem_haber_metni($d, $kaynak);
        $yazilan[$d['id']] = ['z' => $d['zaman'], 'e' => $d['enlem'], 'b' => $d['boylam'], 'm' => $d['buyukluk']];
        $sonuc[] = deprem_haber_kaydet($d, $h) . ': ' . $h['baslik'];
    }

    // Son iki gunun kaydi yeter.
    $yazilan = array_filter($yazilan, static fn ($k): bool => is_array($k) && (int) ($k['z'] ?? 0) > time() - 2 * 86400);
    ayar_yaz('deprem_yazilan', (string) json_encode($yazilan));

    return $sonuc;
}

/**
 * Aynı deprem iki kaynaktan (AFAD ve Kandilli farklı kimlikle) ya da
 * büyüklüğü güncellenerek ikinci kez gelirse: 15 dakika ve ~30 km içinde
 * yazılmış bir haber varsa atla.
 *
 * @param array<string,array<string,float|int>> $yazilan
 */
function deprem_yakinda_yazildi(array $d, array $yazilan): bool
{
    foreach ($yazilan as $k) {
        if (abs((int) $k['z'] - $d['zaman']) <= 900
            && abs((float) $k['e'] - $d['enlem']) < 0.3
            && abs((float) $k['b'] - $d['boylam']) < 0.3) {
            return true;
        }
    }

    return false;
}

/**
 * Haber metni (şablon; rakamlar yalnızca veriden).
 *
 * @return array{baslik:string,ozet:string,icerik:string,onem:int,etiketler:string}
 */
function deprem_haber_metni(array $d, string $kaynak): array
{
    $tr     = new DateTimeZone('Europe/Istanbul');
    $zaman  = (new DateTimeImmutable('@' . $d['zaman']))->setTimezone($tr);
    $saat   = $zaman->format('H.i');
    $gun    = deprem_gun_adi($zaman);
    $b      = deprem_buyukluk_yazi((float) $d['buyukluk']);
    $il     = (string) $d['il'];
    $yer    = $d['ilce'] !== '' ? deprem_ek($il, 'in') . ' ' . $d['ilce'] . ' ilçesinde' : deprem_yer_adi($d) . ' merkezli';
    $kaynakIn = deprem_ek($kaynak, 'in');
    $derin  = number_format((float) $d['derinlik'], 1, ',', '');

    $baslik = $d['ilce'] !== ''
        ? deprem_ek($il, 'in') . ' ' . $d['ilce'] . ' ilçesinde ' . $b . ' büyüklüğünde deprem'
        : deprem_yer_adi($d) . ' merkezli ' . $b . ' büyüklüğünde deprem';

    $ozet = $kaynak . ' verilerine göre ' . $yer . ' ' . $b . ' büyüklüğünde deprem meydana geldi. Deprem saat '
        . $saat . ' sularında, ' . $derin . ' kilometre derinlikte kaydedildi.';

    $p = [];
    $p[] = $kaynakIn . ' ilk verilerine göre deprem ' . $gun . ' saat ' . $saat . ' sularında kaydedildi. Merkez üssü '
        . deprem_yer_adi($d) . ' olan depremin büyüklüğü ' . $b . ($d['tur'] !== '' ? ' (' . $d['tur'] . ')' : '')
        . ', derinliği ' . $derin . ' kilometre olarak ölçüldü. Depremin koordinatları '
        . number_format((float) $d['enlem'], 4, ',', '') . ' kuzey enlemi ve '
        . number_format((float) $d['boylam'], 4, ',', '') . ' doğu boylamı.';

    // Bolgenin son bir haftasi (ayni il): artci ve oncu sarsintilar.
    $hafta = deprem_afad_getir(new DateTimeImmutable('-7 days'), 2.0, 500, $il);

    if ($hafta !== null && $hafta !== []) {
        $digerleri = array_values(array_filter($hafta, static fn (array $x): bool => $x['id'] !== $d['id']));
        $buyukler  = array_values(array_filter($digerleri, static fn (array $x): bool => $x['buyukluk'] >= 3.0));
        $son24     = array_values(array_filter($digerleri, static fn (array $x): bool => $x['zaman'] >= $d['zaman'] - 86400));

        $cumle = 'AFAD kayıtlarına göre ' . deprem_de($il) . ' son yedi günde 2 ve üzeri büyüklükte '
            . count($digerleri) . ' deprem daha oldu';
        $cumle .= $buyukler !== [] ? '; bunlardan ' . count($buyukler) . "'i 3 ve üzeri büyüklükteydi." : '.';

        if ($son24 !== []) {
            $enBuyuk = array_reduce($son24, static fn (?array $a, array $x): array => $a === null || $x['buyukluk'] > $a['buyukluk'] ? $x : $a);
            $cumle .= ' Depremden önceki 24 saatte ilde ' . count($son24) . ' sarsıntı kaydedildi, en büyüğü '
                . deprem_buyukluk_yazi((float) $enBuyuk['buyukluk']) . ' büyüklüğündeydi.';
        }

        $p[] = $cumle;
    }

    // Hasar ya da can kaybi bilgisi veride yok: olmadigini da yazmiyoruz.
    $p[] = 'Depremin bölgede hasara ya da can kaybına yol açıp açmadığına ilişkin bilgi, yetkililerin açıklamalarıyla netleşecek.'
        . ($d['buyukluk'] >= 5.0
            ? ' Bu büyüklükteki depremlerin ardından artçı sarsıntılar yaşanabilir; hasar gören binalara girilmemesi önerilir.'
            : '');

    $p[] = 'Türkiye\'de ve çevresinde son 24 saatte olan bütün depremler, büyüklük, derinlik ve yer bilgisiyle '
        . 'Valentra\'nın Son Depremler sayfasında iki dakikada bir güncelleniyor.';

    $p[] = 'Deprem büyüklüğü ve yeri ilk verilerdir; ' . $kaynak . ' analiz tamamlandıkça değerleri güncelleyebilir.';

    return [
        'baslik'    => $baslik,
        'ozet'      => $ozet,
        'icerik'    => implode("\n\n", $p),
        'onem'      => $d['buyukluk'] >= 6.0 ? 98 : ($d['buyukluk'] >= 5.0 ? 90 : ($d['buyukluk'] >= 4.5 ? 76 : 62)),
        'etiketler' => 'deprem, ' . $il . ', AFAD',
    ];
}

/** Haberi kaydeder ve yayına alır (Valentra Veri; özgün haber sayılır). */
function deprem_haber_kaydet(array $d, array $h): string
{
    require_once __DIR__ . '/haberler.php';
    require_once __DIR__ . '/seo.php';

    $kayit = haber_taslak_ekle([
        'baslik'        => $h['baslik'],
        'ozet'          => $h['ozet'],
        'icerik'        => $h['icerik'],
        'kategori'      => 'gundem',
        'gorsel_url'    => site_adresi() . '/assets/ozgun/deprem.jpg',
        'etiketler'     => $h['etiketler'],
        'kaynak_adi'    => 'Valentra Veri',
        'kaynak_url'    => site_adresi() . deprem_yolu() . '?deprem=' . rawurlencode((string) $d['id']),
        'kaynak_baslik' => $h['baslik'],
        'kaynak_zamani' => time(),
        'guven_skoru'   => 95,
        'onem'          => $h['onem'],
        // 4,5 ve ustu deprem canli veriden geliyor; son dakika.
        'son_dakika'    => $h['onem'] >= 76,
        'model'         => 'valentra-veri',
        'ajan_notu'     => 'Özgün veri haberi (deprem): metin şablondan, rakamlar AFAD/Kandilli verisinden.',
    ]);

    if ($kayit['durum'] !== 'eklendi' || $kayit['id'] === null) {
        return 'zaten vardı';
    }

    return haber_otomatik_yayinla($kayit['id']) ? 'yayında' : 'taslakta kaldı';
}

/** "Malatya" + in -> "Malatya'nın", "Balıkesir" -> "Balıkesir'in" (ünlü uyumu). */
function deprem_ek(string $ad, string $ek): string
{
    $unluler = preg_replace('/[^aeıioöuüAEIİOÖUÜ]/u', '', $ad) ?? '';
    $son     = mb_strtolower(mb_substr($unluler, -1, 1, 'UTF-8'), 'UTF-8');
    $sonHarf = mb_strtolower(mb_substr($ad, -1, 1, 'UTF-8'), 'UTF-8');
    $unluyle = in_array($sonHarf, ['a', 'e', 'ı', 'i', 'o', 'ö', 'u', 'ü'], true);

    $u = match ($son) {
        'a', 'ı' => 'ı',
        'e', 'i' => 'i',
        'o', 'u' => 'u',
        'ö', 'ü' => 'ü',
        default  => 'i',
    };

    return $ad . "'" . ($unluyle ? 'n' : '') . $u . 'n';
}

/** "Balıkesir" -> "Balıkesir'de", "Malatya" -> "Malatya'da", "Kahramanmaraş" -> "Kahramanmaraş'ta". */
function deprem_de(string $ad): string
{
    $unluler = preg_replace('/[^aeıioöuüAEIİOÖUÜ]/u', '', $ad) ?? '';
    $son     = mb_strtolower(mb_substr($unluler, -1, 1, 'UTF-8'), 'UTF-8');
    $sonHarf = mb_strtolower(mb_substr($ad, -1, 1, 'UTF-8'), 'UTF-8');
    $sert    = in_array($sonHarf, ['ç', 'f', 'h', 'k', 'p', 's', 'ş', 't'], true);
    $u       = in_array($son, ['a', 'ı', 'o', 'u'], true) ? 'a' : 'e';

    return $ad . "'" . ($sert ? 't' : 'd') . $u;
}

function deprem_gun_adi(DateTimeImmutable $t): string
{
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $bugun = (new DateTimeImmutable('now', $t->getTimezone()))->format('Y-m-d');

    if ($t->format('Y-m-d') === $bugun) {
        return 'bugün';
    }

    return (int) $t->format('j') . ' ' . $aylar[(int) $t->format('n')];
}
