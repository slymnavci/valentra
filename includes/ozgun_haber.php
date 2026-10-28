<?php
declare(strict_types=1);

/**
 * Özgün veri haberleri: sitenin kendi çektiği veriden yazılan haberler.
 *
 * Site sahibinin istegi (28.10.2026, "ozgun haber baska nasil
 * uretebiliriz?" sorusuna onerilenlerin hepsi): baska sitenin haberini
 * yeniden yazmak yerine sitenin zaten cektigi VERIDEN haber.
 *
 *   piyasa_gunluk   hafta ici 18:00 sonrasi: borsa, doviz, altin kapanisi
 *   piyasa_haftalik cuma 18:00 sonrasi: haftanin piyasa ozeti
 *   spor_program    cuma: Super Lig'de haftanin programi
 *   spor_sonuc      haftanin son maci oynaninca: sonuclar ve puan durumu
 *   resmi_gazete    her sabah: bugunku Resmi Gazete'nin ozeti
 *   hava_uyari      her gun: yarin icin kuvvetli yagis, kar, firtina, asiri sicak/soguk
 *   hava_hafta_sonu persembe: hafta sonu hava durumu
 *   vergi_takvimi   pazartesi: bu hafta son gunu dolan vergi odemeleri
 *   ekonomik_veri   yeni gozlem gelince: enflasyon gibi aylik gostergeler
 *   hafta_ozeti     pazar 17:00 sonrasi: haftanin en onemli 10 gelismesi
 *
 * METIN SABLONLA, MODELSIZ: rakamlar yalnizca verinin kendisinden; yapay
 * zeka rakam uyduramiyor. Veri eksikse ya da bayatsa (tatil gunu borsa
 * acilmadiysa) haber YAZILMIYOR.
 *
 * Calisma: "Canli veri" is akisi iki saatte bir api/canli-tazele.php
 * ?bolum=ozgun ucunu durtuyor; her uretici kendi saat penceresine ve
 * "bu donem yazildi mi" kaydina bakiyor. Ayni donem icin ikinci haber
 * yok (ayar + kaynak adresindeki donem).
 *
 * Haberler dogrudan yayina giriyor (kaynak_tarihi = simdi, guven 95);
 * kaynak "Valentra Veri", verinin asil kaynagi metinde yaziyor.
 */

require_once __DIR__ . '/haberler.php';
require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/canli_onbellek.php';
require_once __DIR__ . '/seo.php';

/** Üretici adı => [kategori, görsel]. */
const OZGUN_TURLER = [
    'piyasa_gunluk'   => ['ekonomi', 'piyasa'],
    'piyasa_haftalik' => ['ekonomi', 'piyasa'],
    'spor_program'    => ['spor', 'futbol'],
    'spor_sonuc'      => ['spor', 'futbol'],
    'resmi_gazete'    => ['gundem', 'resmi-gazete'],
    'hava_uyari'      => ['gundem', 'hava'],
    'hava_hafta_sonu' => ['gundem', 'hava'],
    'vergi_takvimi'   => ['ekonomi', 'vergi'],
    'ekonomik_veri'   => ['ekonomi', 'ekonomi'],
    'hafta_ozeti'     => ['gundem', 'hafta'],
];

/** Dört büyük kulüp (spor haberlerinde öne alınıyor; bkz. HABER_BUYUK_KULUPLER). */
const OZGUN_BUYUK_KULUPLER = ['Galatasaray', 'Fenerbahçe', 'Beşiktaş', 'Trabzonspor'];

/** Istanbul saatiyle şimdi. */
function ozgun_simdi(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
}

/**
 * Bütün üreticileri çalıştırır; zamanı gelmeyen ya da verisi olmayan atlanır.
 *
 * @return array<string,string> uretici => sonuc ("yazildi: ...", "zamani degil", ...)
 */
function ozgun_haberleri_uret(?DateTimeImmutable $simdi = null): array
{
    $simdi ??= ozgun_simdi();
    $sonuc = [];

    foreach (array_keys(OZGUN_TURLER) as $tur) {
        try {
            $sonuc[$tur] = ozgun_uretici_calistir($tur, $simdi);
        } catch (Throwable $e) {
            error_log('[valentra] ozgun haber ' . $tur . ': ' . $e->getMessage());
            $sonuc[$tur] = 'hata: ' . $e->getMessage();
        }
    }

    return $sonuc;
}

function ozgun_uretici_calistir(string $tur, DateTimeImmutable $simdi): string
{
    $donem = ozgun_donem($tur, $simdi);

    if ($donem === null) {
        return 'zamanı değil';
    }

    if (ayar_oku('ozgun_son_' . $tur) === $donem) {
        return 'bu dönem yazıldı (' . $donem . ')';
    }

    $haber = match ($tur) {
        'piyasa_gunluk'   => ozgun_piyasa_gunluk($simdi),
        'piyasa_haftalik' => ozgun_piyasa_haftalik($simdi),
        'spor_program'    => ozgun_spor_program($simdi),
        'spor_sonuc'      => ozgun_spor_sonuc($simdi, $donem),
        'resmi_gazete'    => ozgun_resmi_gazete($simdi),
        'hava_uyari'      => ozgun_hava_uyari($simdi),
        'hava_hafta_sonu' => ozgun_hava_hafta_sonu($simdi),
        'vergi_takvimi'   => ozgun_vergi_takvimi($simdi),
        'ekonomik_veri'   => ozgun_ekonomik_veri(),
        'hafta_ozeti'     => ozgun_hafta_ozeti($simdi),
    };

    if ($haber === null) {
        return 'veri yok ya da yazılacak bir şey yok';
    }

    // Ekonomik veri birden cok haber dondurebilir (her gosterge ayri).
    $liste = array_is_list($haber) ? $haber : [$haber];
    $yazilan = [];

    foreach ($liste as $h) {
        $kayit = ozgun_haber_kaydet($tur, $donem . ($h['donem_eki'] ?? ''), $h);
        $yazilan[] = $kayit . ': ' . $h['baslik'];
    }

    // Ekonomik veride donem gostergenin kendi kaydinda; genel kayit yok.
    if ($tur !== 'ekonomik_veri') {
        ayar_yaz('ozgun_son_' . $tur, $donem);
    }

    return implode(' · ', $yazilan);
}

/**
 * Üreticinin dönemi (aynı dönem için bir haber); zamanı değilse null.
 */
function ozgun_donem(string $tur, DateTimeImmutable $simdi): ?string
{
    $saat = (int) $simdi->format('G');
    $gun  = (int) $simdi->format('N');   // 1 pazartesi ... 7 pazar
    $tarih = $simdi->format('Y-m-d');

    return match ($tur) {
        // Borsa Istanbul 18:00'de kapaniyor; is akisi 18:35'te durtuyor.
        'piyasa_gunluk'   => $gun <= 5 && $saat >= 18 ? $tarih : null,
        'piyasa_haftalik' => $gun === 5 && $saat >= 18 ? $simdi->format('o-\WW') : null,
        'spor_program'    => in_array($gun, [4, 5], true) && $saat >= 10 ? $simdi->format('o-\WW') : null,
        // Hafta tamamlaninca (pazartesi aksami maci da olabilir): donem hafta numarasi.
        'spor_sonuc'      => in_array($gun, [1, 2, 3], true) && $saat >= 8 ? 'hafta' : null,
        'resmi_gazete'    => $saat >= 7 ? $tarih : null,
        'hava_uyari'      => $saat >= 10 && $saat < 21 ? $tarih : null,
        'hava_hafta_sonu' => $gun === 4 && $saat >= 10 ? $simdi->format('o-\WW') : null,
        'vergi_takvimi'   => $gun === 1 && $saat >= 8 ? $simdi->format('o-\WW') : null,
        'ekonomik_veri'   => 'gosterge',
        'hafta_ozeti'     => $gun === 7 && $saat >= 17 ? $simdi->format('o-\\WW') : null,
        default           => null,
    };
}

/**
 * Haberi kaydeder ve yayına alır.
 *
 * @param array{baslik:string,ozet:string,icerik:string,onem:int,kaynak_yolu:string,etiketler?:string} $h
 * @return string 'yayında' | 'taslakta kaldı' | 'zaten vardı'
 */
function ozgun_haber_kaydet(string $tur, string $donem, array $h): string
{
    [$kategori, $gorsel] = OZGUN_TURLER[$tur];

    // Donem kaynak adresinde: ayni turun her donemi ayri adres (haberin
    // kopya denetimi adrese de bakiyor).
    $yol = $h['kaynak_yolu'];
    $url = site_adresi() . $yol . (str_contains($yol, '?') ? '&' : '?') . 'veri=' . rawurlencode($tur . '-' . $donem);

    // Haber sayfasi ozeti metnin ustunde gosteriyor; metin ayni cumleyle
    // baslamasin (ilk paragraf ozetse metinden cikar).
    $icerik = $h['icerik'];

    if (str_starts_with($icerik, $h['ozet'] . "\n\n")) {
        $icerik = substr($icerik, strlen($h['ozet']) + 2);
    }

    $kayit = haber_taslak_ekle([
        'baslik'        => $h['baslik'],
        'ozet'          => $h['ozet'],
        'icerik'        => $icerik,
        'kategori'      => $kategori,
        'gorsel_url'    => site_adresi() . '/assets/ozgun/' . $gorsel . '.jpg',
        'etiketler'     => $h['etiketler'] ?? '',
        'kaynak_adi'    => 'Valentra Veri',
        'kaynak_url'    => $url,
        'kaynak_baslik' => $h['baslik'],
        'kaynak_zamani' => time(),
        'guven_skoru'   => 95,
        'onem'          => $h['onem'],
        'model'         => 'valentra-veri',
        'ajan_notu'     => 'Özgün veri haberi (' . $tur . '): metin şablondan, rakamlar verinin kendisinden.',
    ]);

    if ($kayit['durum'] !== 'eklendi' || $kayit['id'] === null) {
        return 'zaten vardı';
    }

    return haber_otomatik_yayinla($kayit['id']) ? 'yayında' : 'taslakta kaldı';
}

/* ------------------------------------------------------------------------
 * Yardımcılar
 * --------------------------------------------------------------------- */

/** 1234.5 -> "1.234,50" */
function ozgun_sayi(float $s, int $hane = 2): string
{
    return number_format($s, $hane, ',', '.');
}

/** Yüzde: "yüzde 1,25 yükselişle" / "yüzde 0,40 düşüşle" / "yatay" */
function ozgun_yon(?float $y, string $artis = 'yükselişle', string $dusus = 'düşüşle', string $yatay = 'değişmeden'): string
{
    if ($y === null) {
        return '';
    }

    if (abs($y) < 0.005) {
        return $yatay;
    }

    return 'yüzde ' . ozgun_sayi(abs($y)) . ' ' . ($y > 0 ? $artis : $dusus);
}

/** "▲ %1,25" kısa gösterim (liste satırları için). */
function ozgun_kisa_yuzde(?float $y): string
{
    if ($y === null) {
        return '';
    }

    return ($y > 0 ? '+' : ($y < 0 ? '−' : '')) . '%' . ozgun_sayi(abs($y));
}

/** "28 Ekim 2026 Çarşamba" */
function ozgun_tarih(DateTimeImmutable $t, bool $gunAdi = true): string
{
    $aylar  = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $gunler = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

    return (int) $t->format('j') . ' ' . $aylar[(int) $t->format('n') - 1] . ' ' . $t->format('Y')
        . ($gunAdi ? ' ' . $gunler[(int) $t->format('N') - 1] : '');
}

/** Liste paragrafı: her satır "- " ile (haber sayfası madde listesi yapıyor). */
function ozgun_liste(array $satirlar): string
{
    return implode("\n", array_map(static fn (string $s): string => '- ' . $s, $satirlar));
}

/* ------------------------------------------------------------------------
 * 1. Piyasa: günlük kapanış ve haftalık özet
 * --------------------------------------------------------------------- */

/** @return array<string,mixed>|null */
function ozgun_piyasa_gunluk(DateTimeImmutable $simdi): ?array
{
    require_once __DIR__ . '/piyasa_tablolari.php';

    // Borsa bugun acildi mi? Gun ici serinin son noktasi bugune ait
    // olmali; tatilde dunku rakamlar "bugunun kapanisi" diye yazilmasin.
    $bist = piyasa_seri('bist', '1d', 120)['veri'];
    $son  = is_array($bist) && ($bist['noktalar'] ?? []) !== [] ? end($bist['noktalar']) : null;

    if ($bist === null || $son === null || $son === false || $bist['fiyat'] === null
        || (new DateTimeImmutable('@' . $son[0]))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d') !== $simdi->format('Y-m-d')) {
        return null;
    }

    $endeks = piyasa_tablo_verisi('endeks')['veri'] ?? [];
    $doviz  = piyasa_tablo_verisi('doviz')['veri'] ?? [];
    $emtia  = piyasa_tablo_verisi('emtia')['veri'] ?? [];
    $usd    = $doviz['USDTRY=X'] ?? null;
    $eur    = $doviz['EURTRY=X'] ?? null;
    $ons    = $emtia['GC=F'] ?? null;
    $brent  = $emtia['BZ=F'] ?? null;

    if ($usd === null) {
        return null;
    }

    $gram       = $ons !== null ? $ons['fiyat'] * $usd['fiyat'] / PIYASA_ONS_GRAM : null;
    $gramDeg    = ($ons !== null && $ons['degisim'] !== null && $usd['degisim'] !== null)
        ? ((1 + $ons['degisim'] / 100) * (1 + $usd['degisim'] / 100) - 1) * 100 : null;
    $bistDeg    = $bist['degisim'];
    $tarih      = ozgun_tarih($simdi);

    $baslik = 'Borsa günü ' . ($bistDeg === null || abs($bistDeg) < 0.005 ? 'yatay' : ozgun_yon($bistDeg))
        . ' tamamladı; dolar ' . ozgun_sayi($usd['fiyat'], 2) . ' lirada';

    $ilk = 'Borsa İstanbul\'da BIST 100 endeksi ' . $tarih . ' günü '
        . ($bistDeg === null ? '' : ozgun_yon($bistDeg) . ' ') . ozgun_sayi($bist['fiyat'], 2) . ' puandan kapandı. '
        . 'Dolar/TL ' . ozgun_sayi($usd['fiyat'], 4)
        . ($eur !== null ? ', euro/TL ' . ozgun_sayi($eur['fiyat'], 4) : '')
        . ($gram !== null ? ', gram altın ' . ozgun_sayi($gram, 2) . ' lira' : '') . ' seviyesinde.';

    $paragraflar = [$ilk];

    // Endeksler
    $satirlar = [];

    foreach (['XU030.IS' => 'BIST 30', 'XBANK.IS' => 'Bankacılık', 'XUSIN.IS' => 'Sınai', 'XHOLD.IS' => 'Holding', 'XUTEK.IS' => 'Teknoloji'] as $s => $ad) {
        if (isset($endeks[$s])) {
            $satirlar[] = $ad . ': ' . ozgun_sayi($endeks[$s]['fiyat'], 2) . ' puan (' . ozgun_kisa_yuzde($endeks[$s]['degisim']) . ')';
        }
    }

    if ($satirlar !== []) {
        $paragraflar[] = "## Endeksler\n\n" . ozgun_liste($satirlar);
    }

    // Yukselen / dusen hisseler
    $sirket = piyasa_sirket_fiyatlari()['veri'] ?? [];
    $hisse  = [];

    foreach (piyasa_sirketleri() as $kod => $ad) {
        if (isset($sirket[$kod]) && $sirket[$kod]['degisim'] !== null) {
            $hisse[] = ['kod' => $kod, 'ad' => $ad] + $sirket[$kod];
        }
    }

    if (count($hisse) >= 10) {
        usort($hisse, static fn (array $a, array $b): int => $b['degisim'] <=> $a['degisim']);
        $bicim = static fn (array $h): string => $h['ad'] . ' (' . $h['kod'] . '): ' . ozgun_sayi($h['fiyat'], 2) . ' lira, ' . ozgun_kisa_yuzde($h['degisim']);

        $paragraflar[] = "## Günün en çok yükselen hisseleri\n\n" . ozgun_liste(array_map($bicim, array_slice($hisse, 0, 5)));
        $paragraflar[] = "## Günün en çok düşen hisseleri\n\n" . ozgun_liste(array_map($bicim, array_slice(array_reverse($hisse), 0, 5)));
    }

    // Doviz ve emtia
    $satirlar = ['Dolar/TL: ' . ozgun_sayi($usd['fiyat'], 4) . ' (' . ozgun_kisa_yuzde($usd['degisim']) . ')'];

    if ($eur !== null) {
        $satirlar[] = 'Euro/TL: ' . ozgun_sayi($eur['fiyat'], 4) . ' (' . ozgun_kisa_yuzde($eur['degisim']) . ')';
    }

    if (isset($doviz['GBPTRY=X'])) {
        $satirlar[] = 'Sterlin/TL: ' . ozgun_sayi($doviz['GBPTRY=X']['fiyat'], 4) . ' (' . ozgun_kisa_yuzde($doviz['GBPTRY=X']['degisim']) . ')';
    }

    if ($gram !== null) {
        $satirlar[] = 'Gram altın (has değeri): ' . ozgun_sayi($gram, 2) . ' lira (' . ozgun_kisa_yuzde($gramDeg) . ')';
    }

    if ($ons !== null) {
        $satirlar[] = 'Ons altın: ' . ozgun_sayi($ons['fiyat'], 2) . ' dolar (' . ozgun_kisa_yuzde($ons['degisim']) . ')';
    }

    if ($brent !== null) {
        $satirlar[] = 'Brent petrol: ' . ozgun_sayi($brent['fiyat'], 2) . ' dolar (' . ozgun_kisa_yuzde($brent['degisim']) . ')';
    }

    $paragraflar[] = "## Döviz, altın ve petrol\n\n" . ozgun_liste($satirlar);
    $paragraflar[] = 'Veriler Yahoo Finance\'ten, ' . $simdi->format('H:i') . ' itibarıyla alınmıştır ve gecikmeli olabilir. '
        . 'Gram altın, ons fiyatı ve dolar kurundan hesaplanan has değeridir; kuyumcu fiyatı işçilik nedeniyle daha yüksektir. '
        . 'Bu haber yatırım tavsiyesi değildir. Anlık fiyatlar Valentra Piyasalar sayfasında.';

    return [
        'baslik'      => $baslik,
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 62,
        'kaynak_yolu' => '/piyasalar.php',
        'etiketler'   => 'borsa, BIST 100, dolar, euro, altın, piyasalar',
    ];
}

/**
 * Haftalık değişim: bu haftanın son kapanışı / geçen haftanın son kapanışı.
 *
 * @return array{son:float,degisim:float}|null
 */
function ozgun_haftalik_degisim(string $sembol, DateTimeImmutable $simdi): ?array
{
    // Onbellekte bir saat: ayni turda ikinci deneme kaynaga gitmesin.
    $veri = canli_onbellek('ozgun_hafta_' . $sembol, 3600, static fn (): ?array => yahoo_grafik($sembol, '1mo', '1d'))['veri'];

    if ($veri === null || count($veri['noktalar']) < 6) {
        return null;
    }

    $pazartesi = $simdi->modify('monday this week')->setTime(0, 0)->getTimestamp();
    $onceki    = null;

    foreach ($veri['noktalar'] as [$t, $d]) {
        if ($t < $pazartesi) {
            $onceki = $d;
        }
    }

    $son = end($veri['noktalar'])[1];

    if ($onceki === null || $onceki <= 0) {
        return null;
    }

    return ['son' => $son, 'degisim' => ($son - $onceki) / $onceki * 100];
}

/** @return array<string,mixed>|null */
function ozgun_piyasa_haftalik(DateTimeImmutable $simdi): ?array
{
    require_once __DIR__ . '/piyasalar.php';

    $bist = ozgun_haftalik_degisim('XU100.IS', $simdi);
    $usd  = ozgun_haftalik_degisim('USDTRY=X', $simdi);

    if ($bist === null || $usd === null) {
        return null;
    }

    $eur   = ozgun_haftalik_degisim('EURTRY=X', $simdi);
    $ons   = ozgun_haftalik_degisim('GC=F', $simdi);
    $brent = ozgun_haftalik_degisim('BZ=F', $simdi);
    $btc   = ozgun_haftalik_degisim('BTC-USD', $simdi);

    $gram    = $ons !== null ? $ons['son'] * $usd['son'] / PIYASA_ONS_GRAM : null;
    $gramDeg = $ons !== null ? ((1 + $ons['degisim'] / 100) * (1 + $usd['degisim'] / 100) - 1) * 100 : null;

    $baslik = 'Piyasalarda haftanın özeti: BIST 100 haftayı ' . ($bist['degisim'] >= 0 ? 'yükselişle' : 'düşüşle')
        . ' kapattı, dolar ' . ozgun_sayi($usd['son'], 2) . ' lirada';

    $ilk = 'Borsa İstanbul\'da BIST 100 endeksi bu hafta ' . ozgun_yon($bist['degisim'], 'yükseldi', 'geriledi', 'yatay seyretti')
        . ' ve haftayı ' . ozgun_sayi($bist['son'], 2) . ' puandan tamamladı. Dolar/TL haftalık bazda '
        . ozgun_yon($usd['degisim'], 'arttı', 'azaldı', 'yatay seyretti') . ' ve ' . ozgun_sayi($usd['son'], 4) . ' seviyesinde.';

    $satirlar = [
        'BIST 100: ' . ozgun_sayi($bist['son'], 2) . ' puan (haftalık ' . ozgun_kisa_yuzde($bist['degisim']) . ')',
        'Dolar/TL: ' . ozgun_sayi($usd['son'], 4) . ' (haftalık ' . ozgun_kisa_yuzde($usd['degisim']) . ')',
    ];

    if ($eur !== null) {
        $satirlar[] = 'Euro/TL: ' . ozgun_sayi($eur['son'], 4) . ' (haftalık ' . ozgun_kisa_yuzde($eur['degisim']) . ')';
    }

    if ($gram !== null) {
        $satirlar[] = 'Gram altın (has değeri): ' . ozgun_sayi($gram, 2) . ' lira (haftalık ' . ozgun_kisa_yuzde($gramDeg) . ')';
    }

    if ($ons !== null) {
        $satirlar[] = 'Ons altın: ' . ozgun_sayi($ons['son'], 2) . ' dolar (haftalık ' . ozgun_kisa_yuzde($ons['degisim']) . ')';
    }

    if ($brent !== null) {
        $satirlar[] = 'Brent petrol: ' . ozgun_sayi($brent['son'], 2) . ' dolar (haftalık ' . ozgun_kisa_yuzde($brent['degisim']) . ')';
    }

    if ($btc !== null) {
        $satirlar[] = 'Bitcoin: ' . ozgun_sayi($btc['son'], 0) . ' dolar (haftalık ' . ozgun_kisa_yuzde($btc['degisim']) . ')';
    }

    // En iyi ve en kotu: haftalik getiriye gore siralanmis kalemler.
    $kalemler = array_filter([
        'BIST 100' => $bist['degisim'], 'dolar' => $usd['degisim'], 'euro' => $eur['degisim'] ?? null,
        'gram altın' => $gramDeg, 'Brent petrol' => $brent['degisim'] ?? null, 'Bitcoin' => $btc['degisim'] ?? null,
    ], static fn ($d): bool => $d !== null);
    arsort($kalemler);
    $enIyi  = array_key_first($kalemler);
    $enKotu = array_key_last($kalemler);

    $paragraflar = [
        $ilk,
        "## Haftalık tablo\n\n" . ozgun_liste($satirlar),
        'Haftanın en iyi performansı ' . ozgun_kisa_yuzde($kalemler[$enIyi]) . ' ile ' . $enIyi
            . ', en zayıf performansı ' . ozgun_kisa_yuzde($kalemler[$enKotu]) . ' ile ' . $enKotu . ' oldu.',
        'Haftalık değişim, geçen haftanın son kapanışına göre hesaplanmıştır. Veriler Yahoo Finance\'ten alınmıştır ve gecikmeli olabilir; '
            . 'gram altın has değeridir. Bu haber yatırım tavsiyesi değildir.',
    ];

    return [
        'baslik'      => $baslik,
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 66,
        'kaynak_yolu' => '/piyasalar.php',
        'etiketler'   => 'haftalık piyasa, borsa, dolar, altın, petrol, bitcoin',
    ];
}

/* ------------------------------------------------------------------------
 * 2. Süper Lig: haftanın programı ve hafta sonu sonuçları
 * --------------------------------------------------------------------- */

function ozgun_buyuk_mu(string $takim): bool
{
    foreach (OZGUN_BUYUK_KULUPLER as $k) {
        if (mb_stripos($takim, $k) !== false || mb_stripos($takim, str_replace(['ç', 'ş'], ['c', 's'], $k)) !== false) {
            return true;
        }
    }

    return false;
}

/** @return array<string,mixed>|null */
function ozgun_spor_program(DateTimeImmutable $simdi): ?array
{
    require_once __DIR__ . '/spor.php';

    $veri = spor_super_lig()['veri'];

    if ($veri === null || $veri['hafta'] === null || $veri['haftanin_maclari'] === []) {
        return null;
    }

    // Program haberi: maclar henuz oynanmamis olmali ve bu hafta sonuna denk gelmeli.
    $bugun  = $simdi->format('Y-m-d');
    $sinir  = $simdi->modify('+5 days')->format('Y-m-d');
    $maclar = array_values(array_filter(
        $veri['haftanin_maclari'],
        static fn (array $m): bool => $m['skor'] === '' && $m['tarih'] >= $bugun && $m['tarih'] <= $sinir
    ));

    if (count($maclar) < 4) {
        return null;
    }

    usort($maclar, static fn (array $a, array $b): int => [$a['tarih'], $a['saat']] <=> [$b['tarih'], $b['saat']]);

    $hafta  = (int) $veri['hafta'];
    $buyuk  = array_values(array_filter($maclar, static fn (array $m): bool => ozgun_buyuk_mu($m['ev']) || ozgun_buyuk_mu($m['dep'])));
    $gunAdi = static fn (string $t): string => ozgun_tarih(new DateTimeImmutable($t), true);

    $ilk = 'Trendyol Süper Lig\'de ' . $hafta . '. hafta heyecanı ' . $gunAdi($maclar[0]['tarih']) . ' günü başlıyor. ';

    if ($buyuk !== []) {
        $ilk .= 'Dört büyük kulübün rakipleri belli: '
            . implode('; ', array_map(static fn (array $m): string => $m['ev'] . ' - ' . $m['dep'], $buyuk)) . '.';
    }

    $paragraflar = [trim($ilk)];

    if ($buyuk !== []) {
        $paragraflar[] = "## Büyük kulüplerin maçları\n\n" . ozgun_liste(array_map(
            static fn (array $m): string => $m['ev'] . ' - ' . $m['dep'] . ': ' . $gunAdi($m['tarih']) . ($m['saat'] !== '' ? ', saat ' . $m['saat'] : ''),
            $buyuk
        ));
    }

    // Gun gun program
    $gunler = [];

    foreach ($maclar as $m) {
        $gunler[$m['tarih']][] = ($m['saat'] !== '' ? $m['saat'] . ' ' : '') . $m['ev'] . ' - ' . $m['dep'];
    }

    foreach ($gunler as $t => $satirlar) {
        $paragraflar[] = '## ' . $gunAdi($t) . "\n\n" . ozgun_liste($satirlar);
    }

    // Puan durumunda ilk 4 ve buyuklerin yeri
    if ($veri['puan'] !== []) {
        $ust = array_slice($veri['puan'], 0, 4);
        $paragraflar[] = 'Haftaya girerken zirvede ' . implode(', ', array_map(
            static fn (array $s): string => $s['sira'] . '. ' . $s['takim'] . ' (' . $s['p'] . ' puan)',
            $ust
        )) . ' yer alıyor.';
    }

    $paragraflar[] = 'Maç programı Türkiye Futbol Federasyonu\'nun (TFF) resmi fikstüründen alınmıştır; saatler değişebilir. '
        . 'Puan durumu ve fikstür Valentra Spor sayfasında.';

    $baslik = 'Süper Lig\'de ' . $hafta . '. hafta programı'
        . ($buyuk !== [] ? ': ' . implode(', ', array_slice(array_map(static fn (array $m): string => $m['ev'] . '-' . $m['dep'], $buyuk), 0, 2)) : '');

    return [
        'baslik'      => mb_substr($baslik, 0, 140, 'UTF-8'),
        'ozet'        => $paragraflar[0],
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 72,
        'kaynak_yolu' => '/kategori.php?k=spor',
        'etiketler'   => 'Süper Lig, maç programı, ' . implode(', ', OZGUN_BUYUK_KULUPLER),
    ];
}

/**
 * Hafta sonu sonuçları: son oynanan haftanın bütün maçları bitince bir kez.
 *
 * Donem hafta numarasiyla: ayni hafta icin ikinci haber yok.
 *
 * @return array<string,mixed>|null
 */
function ozgun_spor_sonuc(DateTimeImmutable $simdi, string &$donem): ?array
{
    require_once __DIR__ . '/spor.php';

    $veri = spor_super_lig()['veri'];

    if ($veri === null || $veri['puan'] === []) {
        return null;
    }

    $hafta = spor_son_oynanan_hafta($veri['fikstur']);

    if ($hafta === null) {
        return null;
    }

    $maclar = $veri['fikstur'][$hafta] ?? [];

    // Haftanin butun maclari oynanmis olmali.
    if (count($maclar) < 4 || array_filter($maclar, static fn (array $m): bool => $m['skor'] === '') !== []) {
        return null;
    }

    $donem = 'hafta-' . $hafta . '-' . $simdi->format('Y');

    if (ayar_oku('ozgun_son_spor_sonuc') === $donem) {
        return null;
    }

    $sonucCumle = static function (array $m): string {
        if (!preg_match('/(\d+)\s*-\s*(\d+)/', $m['skor'], $e)) {
            return $m['ev'] . ' ' . $m['skor'] . ' ' . $m['dep'];
        }

        return $m['ev'] . ' ' . $e[1] . '-' . $e[2] . ' ' . $m['dep'];
    };

    $buyuk = array_values(array_filter($maclar, static fn (array $m): bool => ozgun_buyuk_mu($m['ev']) || ozgun_buyuk_mu($m['dep'])));
    $lider = $veri['puan'][0];

    $ilk = 'Trendyol Süper Lig\'de ' . $hafta . '. hafta tamamlandı. ' . $lider['takim'] . ' ' . $lider['p']
        . ' puanla liderliğini sürdürüyor.';

    if (isset($veri['puan'][1])) {
        $ikinci = $veri['puan'][1];
        $fark   = $lider['p'] - $ikinci['p'];
        $ilk   .= ' ' . $ikinci['takim'] . ' ' . $ikinci['p'] . ' puanla ikinci sırada'
            . ($fark > 0 ? '; aradaki fark ' . $fark . ' puan.' : ' ve puanlar eşit.');
    }

    $paragraflar = [$ilk];

    if ($buyuk !== []) {
        $paragraflar[] = "## Dört büyüğün sonuçları\n\n" . ozgun_liste(array_map($sonucCumle, $buyuk));
    }

    $paragraflar[] = "## Haftanın bütün sonuçları\n\n" . ozgun_liste(array_map($sonucCumle, $maclar));

    $paragraflar[] = "## Puan durumunda ilk 6\n\n" . ozgun_liste(array_map(
        static fn (array $s): string => $s['sira'] . '. ' . $s['takim'] . ': ' . $s['p'] . ' puan (' . $s['o'] . ' maç, averaj ' . $s['av'] . ')',
        array_slice($veri['puan'], 0, 6)
    ));

    $buyukSira = array_values(array_filter($veri['puan'], static fn (array $s): bool => ozgun_buyuk_mu($s['takim']) && $s['sira'] > 6));

    if ($buyukSira !== []) {
        $paragraflar[] = 'Dört büyüklerden ' . implode(', ', array_map(
            static fn (array $s): string => $s['takim'] . ' ' . $s['p'] . ' puanla ' . $s['sira'] . '. sırada',
            $buyukSira
        )) . '.';
    }

    $paragraflar[] = 'Sonuçlar ve puan durumu Türkiye Futbol Federasyonu\'nun (TFF) resmi verisinden alınmıştır. '
        . 'Güncel puan durumu Valentra Spor sayfasında.';

    $baslik = 'Süper Lig\'de ' . $hafta . '. haftanın sonuçları: ' . $lider['takim'] . ' zirvede';

    if ($buyuk !== []) {
        $baslik .= ', ' . $sonucCumle($buyuk[0]);
    }

    return [
        'baslik'      => mb_substr($baslik, 0, 140, 'UTF-8'),
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 74,
        'kaynak_yolu' => '/kategori.php?k=spor',
        'etiketler'   => 'Süper Lig, maç sonuçları, puan durumu, ' . implode(', ', OZGUN_BUYUK_KULUPLER),
    ];
}

/* ------------------------------------------------------------------------
 * 3. Resmî Gazete özeti
 * --------------------------------------------------------------------- */

/** @return array<string,mixed>|null */
function ozgun_resmi_gazete(DateTimeImmutable $simdi): ?array
{
    require_once __DIR__ . '/resmi_gazete.php';

    $maddeler = rg_gun_maddeleri($simdi->format('Y-m-d'));

    if (count($maddeler) < 2) {
        return null;
    }

    $bolumler = [];

    foreach ($maddeler as $m) {
        $bolum = (string) ($m['bolum'] !== '' ? $m['bolum'] : ($m['ust_bolum'] !== '' ? $m['ust_bolum'] : 'Diğer'));
        $bolumler[$bolum][] = trim((string) $m['baslik']);
    }

    $sayi  = (int) ($maddeler[0]['sayi'] ?? 0);
    $ozetSayilar = [];

    foreach ($bolumler as $ad => $liste) {
        $ozetSayilar[] = count($liste) . ' ' . mb_strtolower($ad, 'UTF-8');
    }

    $ilk = 'Resmî Gazete\'nin ' . ozgun_tarih($simdi) . ' tarihli' . ($sayi > 0 ? ' ' . $sayi . ' sayılı' : '')
        . ' nüshasında toplam ' . count($maddeler) . ' düzenleme yer aldı: ' . implode(', ', array_slice($ozetSayilar, 0, 6)) . '.';

    // Ilk maddeden one cikan baslik (genellikle yonetmelik ya da karar).
    $paragraflar = [$ilk];

    foreach ($bolumler as $ad => $liste) {
        $gosterilen = array_slice($liste, 0, 25);
        $paragraflar[] = '## ' . $ad . "\n\n" . ozgun_liste($gosterilen)
            . (count($liste) > 25 ? "\n- ve " . (count($liste) - 25) . ' madde daha' : '');
    }

    $paragraflar[] = 'Liste Resmî Gazete\'nin günlük fihristinden alınmıştır. Maddelerin tam metinlerine '
        . 'resmigazete.gov.tr adresinden ve Valentra Resmî Gazete sayfasından ulaşabilirsiniz.';

    return [
        'baslik'      => 'Bugün Resmî Gazete\'de neler var? ' . ozgun_tarih($simdi, false) . ' tarihli Resmî Gazete özeti',
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 58,
        'kaynak_yolu' => '/resmi-gazete.php',
        'etiketler'   => 'Resmî Gazete, yönetmelik, karar, tebliğ',
    ];
}

/* ------------------------------------------------------------------------
 * 4. Hava: yarın için uyarılar ve hafta sonu tahmini
 * --------------------------------------------------------------------- */

/**
 * Büyükşehirlerin günlük tahmini (bugünden itibaren 4 gün), tek istek.
 *
 * @return array<string,list<array{tarih:string,kod:int,en_yuksek:float,en_dusuk:float,yagis:float,kar:float,ruzgar:float}>>|null il => gunler
 */
function ozgun_hava_tahmini(): ?array
{
    require_once __DIR__ . '/hava.php';

    $iller = HAVA_BUYUKSEHIRLER;

    $kayit = canli_onbellek('ozgun_hava_tahmin', 3600, static function () use ($iller): ?array {
        $ham = canli_indir(
            'https://api.open-meteo.com/v1/forecast?latitude=' . implode(',', array_map(static fn (string $il): float => HAVA_ILLERI[$il][1], $iller))
            . '&longitude=' . implode(',', array_map(static fn (string $il): float => HAVA_ILLERI[$il][2], $iller))
            . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,snowfall_sum,wind_gusts_10m_max'
            . '&forecast_days=4&timezone=Europe%2FIstanbul',
            10
        );

        $liste = $ham !== null ? json_decode($ham, true) : null;

        if (!is_array($liste) || !array_is_list($liste) || count($liste) !== count($iller)) {
            return null;
        }

        $sonuc = [];

        foreach ($liste as $i => $tek) {
            $d = $tek['daily'] ?? null;

            if (!is_array($d) || !isset($d['time'])) {
                return null;
            }

            foreach ((array) $d['time'] as $j => $tarih) {
                if (!isset($d['temperature_2m_max'][$j], $d['temperature_2m_min'][$j])) {
                    continue;
                }

                $sonuc[$iller[$i]][] = [
                    'tarih'     => (string) $tarih,
                    'kod'       => (int) ($d['weather_code'][$j] ?? 0),
                    'en_yuksek' => (float) $d['temperature_2m_max'][$j],
                    'en_dusuk'  => (float) $d['temperature_2m_min'][$j],
                    'yagis'     => (float) ($d['precipitation_sum'][$j] ?? 0),
                    'kar'       => (float) ($d['snowfall_sum'][$j] ?? 0),
                    'ruzgar'    => (float) ($d['wind_gusts_10m_max'][$j] ?? 0),
                ];
            }
        }

        return $sonuc;
    });

    return $kayit['veri'];
}

/** Uyarı eşikleri. */
const OZGUN_HAVA_YAGIS_MM  = 25.0;   // gunluk toplam yagis
const OZGUN_HAVA_KAR_CM   = 5.0;
const OZGUN_HAVA_RUZGAR   = 70.0;   // km/sa, en kuvvetli hamle
const OZGUN_HAVA_SICAK    = 38.0;
const OZGUN_HAVA_SOGUK    = -10.0;

/** @return array<string,mixed>|null */
function ozgun_hava_uyari(DateTimeImmutable $simdi): ?array
{
    $tahmin = ozgun_hava_tahmini();

    if ($tahmin === null) {
        return null;
    }

    $yarin = $simdi->modify('+1 day')->format('Y-m-d');
    $uyarilar = ['yagis' => [], 'kar' => [], 'ruzgar' => [], 'sicak' => [], 'soguk' => []];

    foreach ($tahmin as $il => $gunler) {
        foreach ($gunler as $g) {
            if ($g['tarih'] !== $yarin) {
                continue;
            }

            $ad = HAVA_ILLERI[$il][0];

            if ($g['kar'] >= OZGUN_HAVA_KAR_CM) {
                $uyarilar['kar'][] = $ad . ' (yaklaşık ' . ozgun_sayi($g['kar'], 0) . ' cm)';
            } elseif ($g['yagis'] >= OZGUN_HAVA_YAGIS_MM) {
                $uyarilar['yagis'][] = $ad . ' (' . ozgun_sayi($g['yagis'], 0) . ' mm)';
            }

            if ($g['ruzgar'] >= OZGUN_HAVA_RUZGAR) {
                $uyarilar['ruzgar'][] = $ad . ' (saatte ' . ozgun_sayi($g['ruzgar'], 0) . ' km)';
            }

            if ($g['en_yuksek'] >= OZGUN_HAVA_SICAK) {
                $uyarilar['sicak'][] = $ad . ' (' . ozgun_sayi($g['en_yuksek'], 0) . ' derece)';
            }

            if ($g['en_dusuk'] <= OZGUN_HAVA_SOGUK) {
                $uyarilar['soguk'][] = $ad . ' (eksi ' . ozgun_sayi(abs($g['en_dusuk']), 0) . ' derece)';
            }
        }
    }

    $basliklar = [
        'yagis'  => ['kuvvetli yağış', 'Kuvvetli yağış beklenen illerde günlük toplam yağışın 25 milimetreyi aşması bekleniyor'],
        'kar'    => ['kar yağışı', 'Kar beklenen illerde ulaşımda aksamalar yaşanabilir'],
        'ruzgar' => ['kuvvetli rüzgar', 'Rüzgarın hamlelerle saatte 70 kilometreyi aşması bekleniyor'],
        'sicak'  => ['aşırı sıcak', 'Sıcaklığın 38 dereceyi aşması bekleniyor'],
        'soguk'  => ['dondurucu soğuk', 'Gece sıcaklığının eksi 10 derecenin altına inmesi bekleniyor'],
    ];

    $dolu = array_filter($uyarilar);

    if ($dolu === []) {
        return null;
    }

    $turler = array_map(static fn (string $t): string => $basliklar[$t][0], array_keys($dolu));
    $iller  = array_unique(array_merge(...array_map(
        static fn (array $l): array => array_map(static fn (string $s): string => (string) preg_replace('/\s*\(.*$/u', '', $s), $l),
        array_values($dolu)
    )));

    $ilAdi  = implode(', ', array_slice($iller, 0, 4)) . (count($iller) > 4 ? ' ve ' . (count($iller) - 4) . ' ilde daha' : '');
    $baslik = 'Yarın için ' . implode(' ve ', array_slice($turler, 0, 2)) . ' uyarısı: ' . $ilAdi;

    $ilk = ozgun_tarih($simdi->modify('+1 day')) . ' günü için yapılan tahminlere göre ' . $ilAdi
        . ($ilAdi !== '' ? ' için ' : '') . implode(', ', $turler) . ' bekleniyor.';

    $paragraflar = [$ilk];

    foreach ($dolu as $tur => $liste) {
        $paragraflar[] = '## ' . mb_strtoupper(mb_substr($basliklar[$tur][0], 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($basliklar[$tur][0], 1, null, 'UTF-8')
            . "\n\n" . $basliklar[$tur][1] . ":\n" . ozgun_liste($liste);
    }

    $paragraflar[] = 'Tahminler Open-Meteo hava modeli verisinden, 30 büyükşehir için hesaplanmıştır ve değişebilir. '
        . 'Resmî uyarılar için Meteoroloji Genel Müdürlüğü\'nün (MGM) açıklamalarını takip edin.';

    return [
        'baslik'      => mb_substr($baslik, 0, 140, 'UTF-8'),
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 68,
        'kaynak_yolu' => '/',
        'etiketler'   => 'hava durumu, meteoroloji, uyarı, ' . implode(', ', array_slice($iller, 0, 5)),
    ];
}

/** @return array<string,mixed>|null */
function ozgun_hava_hafta_sonu(DateTimeImmutable $simdi): ?array
{
    require_once __DIR__ . '/hava.php';

    $tahmin = ozgun_hava_tahmini();

    if ($tahmin === null) {
        return null;
    }

    $cumartesi = $simdi->modify('saturday this week')->format('Y-m-d');
    $pazar     = $simdi->modify('sunday this week')->format('Y-m-d');
    $sehirler  = ['istanbul', 'ankara', 'izmir', 'bursa', 'antalya', 'adana', 'konya', 'trabzon', 'erzurum', 'diyarbakir'];
    $satirlar  = [];
    $yagisli   = [];

    foreach ($sehirler as $il) {
        $gunler = [];

        foreach ($tahmin[$il] ?? [] as $g) {
            if ($g['tarih'] === $cumartesi || $g['tarih'] === $pazar) {
                $gunler[$g['tarih']] = $g;
            }
        }

        if (!isset($gunler[$cumartesi], $gunler[$pazar])) {
            continue;
        }

        $parca = [];

        foreach ([$cumartesi => 'Cumartesi', $pazar => 'Pazar'] as $t => $ad) {
            $g = $gunler[$t];
            [$durum] = hava_kodu($g['kod'], true);
            $parca[] = $ad . ' ' . mb_strtolower($durum, 'UTF-8') . ', ' . ozgun_sayi($g['en_dusuk'], 0) . '/' . ozgun_sayi($g['en_yuksek'], 0) . ' derece';

            if ($g['yagis'] >= 5) {
                $yagisli[HAVA_ILLERI[$il][0]] = true;
            }
        }

        $satirlar[] = HAVA_ILLERI[$il][0] . ': ' . implode('; ', $parca);
    }

    if (count($satirlar) < 5) {
        return null;
    }

    $ist = $tahmin['istanbul'] ?? [];
    $istCmt = array_values(array_filter($ist, static fn (array $g): bool => $g['tarih'] === $cumartesi))[0] ?? null;

    $ilk = 'Hafta sonu hava nasıl olacak? ' . ozgun_tarih(new DateTimeImmutable($cumartesi), false) . ' Cumartesi ve Pazar günleri için tahminlere göre '
        . ($yagisli !== [] ? implode(', ', array_slice(array_keys($yagisli), 0, 4)) . ' başta olmak üzere bazı illerde yağış bekleniyor.'
                           : 'büyükşehirlerin çoğunda önemli bir yağış beklenmiyor.')
        . ($istCmt !== null ? ' İstanbul\'da cumartesi sıcaklığın ' . ozgun_sayi($istCmt['en_dusuk'], 0) . ' ile ' . ozgun_sayi($istCmt['en_yuksek'], 0) . ' derece arasında olması bekleniyor.' : '');

    return [
        // Ek getirmeden ("İzmir'de/İstanbul'da" uyumu): "... için yağış bekleniyor".
        'baslik'      => 'Hafta sonu hava durumu: ' . ($yagisli !== [] ? implode(', ', array_slice(array_keys($yagisli), 0, 3)) . ' için yağış bekleniyor'
                                                                        : 'büyükşehirlerde yağış beklenmiyor'),
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", [
            $ilk,
            "## Büyükşehirlerde hafta sonu\n\n" . ozgun_liste($satirlar),
            'Sıcaklıklar en düşük/en yüksek olarak verilmiştir. Tahminler Open-Meteo hava modeli verisinden alınmıştır ve '
                . 'hafta sonuna doğru değişebilir; resmî uyarılar için Meteoroloji Genel Müdürlüğü\'nü (MGM) takip edin.',
        ]),
        'onem'        => 60,
        'kaynak_yolu' => '/',
        'etiketler'   => 'hava durumu, hafta sonu, ' . implode(', ', array_map(static fn (string $il): string => HAVA_ILLERI[$il][0], array_slice($sehirler, 0, 5))),
    ];
}

/* ------------------------------------------------------------------------
 * 5. Vergi takvimi: bu hafta son günü dolan ödemeler
 * --------------------------------------------------------------------- */

/** @return array<string,mixed>|null */
function ozgun_vergi_takvimi(DateTimeImmutable $simdi): ?array
{
    require_once __DIR__ . '/takvim.php';

    $yaklasan = takvim_yaklasanlar(6, 30);

    if ($yaklasan === []) {
        return null;
    }

    $gunler = [];

    foreach ($yaklasan as $s) {
        $gunler[$s['tarih']][] = $s['baslik'] . ($s['aciklama'] !== '' ? ': ' . rtrim($s['aciklama'], '.') : '');
    }

    $ilk = 'Bu hafta vergi takviminde ' . count($yaklasan) . ' son gün bulunuyor. '
        . 'İlki ' . ozgun_tarih(new DateTimeImmutable($yaklasan[0]['tarih'])) . ' günü: ' . $yaklasan[0]['baslik'] . '.';

    $paragraflar = [$ilk];

    foreach ($gunler as $t => $liste) {
        $paragraflar[] = '## ' . ozgun_tarih(new DateTimeImmutable($t)) . "\n\n" . ozgun_liste($liste);
    }

    $paragraflar[] = 'Son gün hafta sonu ya da resmî tatile denk gelirse süre izleyen ilk iş gününe uzar. '
        . 'Tarihler Gelir İdaresi Başkanlığı\'nın vergi takvimine göre derlenmiştir; kesin bilgi için gib.gov.tr adresini kontrol edin. '
        . 'Yılın bütün tarihleri Valentra Vergi Takvimi sayfasında.';

    return [
        'baslik'      => 'Bu hafta vergi takviminde ' . count($yaklasan) . ' son gün var: ' . mb_substr($yaklasan[0]['baslik'], 0, 60, 'UTF-8'),
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 55,
        'kaynak_yolu' => takvim_yolu(),
        'etiketler'   => 'vergi takvimi, beyanname, KDV, SGK, son gün',
    ];
}

/* ------------------------------------------------------------------------
 * 6. Ekonomik veri: aylık göstergelerde yeni açıklama
 * --------------------------------------------------------------------- */

/**
 * Yayındaki aylık EVDS göstergelerinden yeni gözlem gelenler için haber.
 *
 * Her gostergenin son gozlem tarihi ayarlarda; yeni tarih gelince haber.
 * Ilk calismada yalnizca tarih kaydediliyor (eski veri haber olmasin).
 * Gunluk seriler (kur gibi) haber degil: ardisik gozlemler 25 gunden
 * yakin ise atlaniyor.
 *
 * @return list<array<string,mixed>>|null
 */
function ozgun_ekonomik_veri(): ?array
{
    require_once __DIR__ . '/grafikler.php';

    try {
        $grafikler = grafik_listele();
    } catch (Throwable $e) {
        return null;
    }

    $haberler = [];
    $aylar    = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    foreach ($grafikler as $g) {
        if (empty($g['yayinda']) || ($g['seri'] ?? '') === '') {
            continue;
        }

        $seri = json_decode((string) $g['seri'], true);

        if (!is_array($seri) || count($seri) < 13) {
            continue;
        }

        $n    = count($seri);
        [$sonTarih, $son]       = $seri[$n - 1];
        [$oncekiTarih, $onceki] = $seri[$n - 2];

        if ((strtotime((string) $sonTarih) - strtotime((string) $oncekiTarih)) < 25 * 86400) {
            continue;
        }

        $anahtar = 'ozgun_gosterge_' . (int) $g['id'];
        $kayitli = ayar_oku($anahtar);

        if ($kayitli === (string) $sonTarih) {
            continue;
        }

        ayar_yaz($anahtar, (string) $sonTarih);

        if ($kayitli === '') {
            continue;   // ilk calisma: yalnizca kaydet
        }

        $t        = new DateTimeImmutable((string) $sonTarih);
        $donemAdi = $aylar[(int) $t->format('n') - 1] . ' ' . $t->format('Y');
        $yuzde    = (string) $g['birim'] === 'yuzde';
        $bicim    = static fn (float $d): string => ($yuzde ? 'yüzde ' : '') . ozgun_sayi($d, 2);
        $gecenYil = $seri[$n - 13][1] ?? null;
        $ad       = (string) $g['baslik'];

        $fark = (float) $son - (float) $onceki;
        $yon  = abs($fark) < 0.005 ? 'önceki ayla aynı kaldı' : ($fark > 0 ? 'önceki aya göre yükseldi' : 'önceki aya göre geriledi');

        $ilk = $ad . ' ' . $donemAdi . ' döneminde ' . $bicim((float) $son) . ' oldu ve ' . $yon
            . ' (önceki ay: ' . $bicim((float) $onceki) . ').';

        $satirlar = [
            $donemAdi . ': ' . $bicim((float) $son),
            'Önceki ay: ' . $bicim((float) $onceki),
        ];

        if ($gecenYil !== null) {
            $satirlar[] = 'Geçen yılın aynı ayı: ' . $bicim((float) $gecenYil);
        }

        // Son 12 ayin en yuksegi ve en dusugu
        $sonOnIki = array_column(array_slice($seri, -12), 1);
        $satirlar[] = 'Son 12 ayın en yükseği: ' . $bicim((float) max($sonOnIki)) . ', en düşüğü: ' . $bicim((float) min($sonOnIki));

        $haberler[] = [
            'donem_eki'   => '-' . (int) $g['id'] . '-' . $sonTarih,
            'baslik'      => $ad . ' ' . $donemAdi . ' döneminde ' . $bicim((float) $son) . ' oldu',
            'ozet'        => $ilk,
            'icerik'      => implode("\n\n", [
                $ilk,
                "## Rakamlarla\n\n" . ozgun_liste($satirlar),
                'Veri ' . ((string) ($g['kaynak_adi'] ?? '') !== '' ? (string) $g['kaynak_adi'] : 'TCMB EVDS') . ' kaynağından alınmıştır. '
                    . 'Göstergenin uzun dönem grafiği Valentra Ekonomik Göstergeler sayfasında.',
            ]),
            'onem'        => 78,
            'kaynak_yolu' => '/piyasalar.php?bolum=gostergeler',
            'etiketler'   => $ad . ', ekonomi, TCMB, TÜİK',
        ];
    }

    return $haberler === [] ? null : $haberler;
}

/* ------------------------------------------------------------------------
 * 7. Haftanın özeti: en önemli 10 gelişme
 * --------------------------------------------------------------------- */

/**
 * Son 7 günün en önemli haberleri (önem puanına göre), konu başına en
 * fazla 3; veri haberleri ve tarifler hariç. Metin haberlerin kendi
 * özetlerinden, model yok.
 *
 * @return array<string,mixed>|null
 */
function ozgun_hafta_ozeti(DateTimeImmutable $simdi): ?array
{
    $ifade = db()->prepare(
        'SELECT h.baslik, h.ozet, h.slug, k.slug AS kategori_slug, k.ad AS kategori
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d
            AND COALESCE(h.ajan_modeli, \'\') NOT LIKE \'valentra-%\'
            AND COALESCE(k.slug, \'\') <> \'yemek-tarifleri\'
            AND NOT (' . HABER_RG_KOSULU . ')
            AND COALESCE(h.kaynak_tarihi, h.yayin_tarihi) >= NOW() - INTERVAL 7 DAY
          ORDER BY h.onem DESC, h.yayin_tarihi DESC
          LIMIT 80'
    );
    $ifade->execute(['d' => HABER_YAYINDA]);

    $secilen = [];
    $konu    = [];
    $gorulen = [];

    foreach ($ifade->fetchAll() as $h) {
        $k = (string) ($h['kategori_slug'] ?? '');
        // Ayni olayin ikinci haberi: basligin ilk 5 kelimesi ayniysa atla.
        $iz = implode(' ', array_slice(preg_split('/\s+/u', mb_strtolower((string) $h['baslik'], 'UTF-8')) ?: [], 0, 5));

        if (($konu[$k] ?? 0) >= 3 || isset($gorulen[$iz])) {
            continue;
        }

        $konu[$k] = ($konu[$k] ?? 0) + 1;
        $gorulen[$iz] = true;
        $secilen[]    = $h;

        if (count($secilen) >= 10) {
            break;
        }
    }

    if (count($secilen) < 6) {
        return null;
    }

    $bas = $simdi->modify('-6 days');
    $ilk = ozgun_tarih($bas, false) . ' ile ' . ozgun_tarih($simdi, false) . ' arasında Türkiye\'de ve dünyada öne çıkan '
        . count($secilen) . ' gelişmeyi sizin için derledik.';

    $paragraflar = [$ilk];

    foreach ($secilen as $i => $h) {
        $paragraflar[] = '## ' . ($i + 1) . '. ' . trim((string) $h['baslik']);
        $paragraflar[] = trim((string) $h['ozet']) . ' (' . ((string) ($h['kategori'] ?? '') !== '' ? $h['kategori'] : 'Gündem') . ')';
    }

    $paragraflar[] = 'Liste, Valentra\'da bu hafta yayımlanan haberlerin önem puanına göre hazırlanmıştır; haberlerin tamamı ilgili konu sayfalarında.';

    return [
        'baslik'      => 'Haftanın özeti: ' . count($secilen) . ' başlıkta bu hafta neler oldu? (' . ozgun_tarih($bas, false) . ' - ' . ozgun_tarih($simdi, false) . ')',
        'ozet'        => $ilk,
        'icerik'      => implode("\n\n", $paragraflar),
        'onem'        => 70,
        'kaynak_yolu' => '/',
        'etiketler'   => 'haftanın özeti, gündem, bu hafta',
    ];
}
