<?php
declare(strict_types=1);

/**
 * Ana sayfa vitrini: "Bugün bilmeniz gereken 5 gelişme".
 *
 * Site sahibinin istegi (08.11.2026): "ustte genis hava durumu/yazar
 * alani yerine once 'Bugun bilmeniz gereken 5 gelisme'; cok sayida
 * gundem basligi yerine editorun sectigi, birbirini tekrar etmeyen
 * gundem". Hava durumu piyasa seridinin altinda ince seritte
 * (includes/hava_seridi.php).
 *
 * Secim: ana sayfa sirasi (panelden one cikarilan bugunun haberleri
 * basta, sonra gunun onem puani; bkz. haber_sirasi), her olaydan TEK
 * haber (konu_ayni_mi; bkz. includes/konu_siniri.php) ve alan yuvalari
 * (BES_GELISME_YUVALAR): kutu mansetin ilk bes haberinin kopyasi degil.
 */

require_once __DIR__ . '/haberler.php';
require_once __DIR__ . '/konu_siniri.php';

const BES_GELISME_ADET = 5;

/*
 * Yuvalar (08.11.2026, okur denetimi): kutu manset kaydiracinin ilk bes
 * haberini tekrar ediyordu, ucu politikaydi. Artik secilmis bir ozet:
 * her yuvaya o alanin en onemli haberi, sonuncusu gunun kalan en onemli
 * gelismesi. Bir yuvanin haberi yoksa bos kalmiyor; "diger" gibi
 * dolduruluyor ama ayni alandan en fazla iki haber.
 *
 *   gundem     Gündem ve politika birlikte (tek madde)
 *   ekonomi
 *   dunya
 *   yasam      Günlük yaşam: gündem dosyalarından biri (akaryakıt, kira,
 *              emekli, asgari ücret ...) ya da sağlık
 *   diger      Kalan en önemli gelişme
 */
const BES_GELISME_YUVALAR = ['gundem', 'ekonomi', 'dunya', 'yasam', 'diger'];

/*
 * Yuvaya girmek icin en az onem (12.11.2026, site sahibi: "ABD'de
 * kizamiktan 6 kisinin olmesi ne kadar onemli, okuyucular ne kadar
 * ilgilenir? Turkiye'de cok onemsiz").
 *
 * Yuvalar alanin EN UST siradaki haberini aliyordu, onemi ne olursa olsun:
 * gunun tek saglik haberi ABD'deki kizamik olunca "bilmeniz gereken" bes
 * gelismeden biri oluyordu. Artik yuva yalnizca onemi bu esigi gecen
 * haberi aliyor (ajanin olcusunde 70+: Turkiye gundemi ve ustu; rutin ve
 * sinirli etkili haberler 30-59). Esigi gecen haber yoksa yuva bos
 * kalmiyor: gunun siradaki en onemli haberiyle doluyor, alani ne olursa
 * olsun. Panelden one cikarilan haber esikten muaf (editorun secimi).
 */
const BES_GELISME_ASGARI_ONEM = 70;

/** Haber "bilmeniz gereken" sayılacak kadar önemli mi? */
function bes_gelisme_onemli_mi(array $h): bool
{
    return (int) ($h['onem'] ?? 0) >= BES_GELISME_ASGARI_ONEM || (int) ($h['one_cikan'] ?? 0) === 1;
}

/** Haberin vitrin alanı. */
function bes_gelisme_alani(array $h): string
{
    require_once __DIR__ . '/konu.php';

    $kategori = (string) ($h['kategori_slug'] ?? '');

    if (in_array($kategori, ['spor', 'magazin', 'kultur-sanat', 'teknoloji', 'yemek-tarifleri'], true)) {
        return 'diger';
    }

    if ($kategori === 'saglik' || konu_haberin_konusu($h) !== null) {
        return 'yasam';
    }

    return match ($kategori) {
        'gundem', 'politika' => 'gundem',
        'ekonomi'            => 'ekonomi',
        'dunya'              => 'dunya',
        default              => 'diger',
    };
}

/**
 * Birbirini tekrar etmeyen, alanlara dağılmış en önemli gelişmeler.
 *
 * @return list<array<string,mixed>>
 */
function bes_gelisme(int $adet = BES_GELISME_ADET): array
{
    try {
        $adaylar = haber_manset(60, true);
    } catch (Throwable $e) {
        error_log('[valentra] bes gelisme: ' . $e->getMessage());

        return [];
    }

    // Ayni olayin farkli aciklamalari tek madde: konu_ayni_mi ile
    // tekrar edenler bastan ayiklaniyor (sira korunuyor).
    $tekil  = [];
    $kokler = [];

    foreach ($adaylar as $h) {
        $k = konu_kokleri((string) $h['baslik']);

        foreach ($kokler as $onceki) {
            if (konu_ayni_mi($k, $onceki)) {
                continue 2;
            }
        }

        $h['_alan'] = bes_gelisme_alani($h);
        $tekil[]    = $h;
        $kokler[]   = $k;
    }

    $secilen = [];
    $kullan  = [];

    // 1. Her yuvaya o alanin en ust sirada gelen, yeterince onemli haberi.
    foreach (BES_GELISME_YUVALAR as $yuva) {
        foreach ($tekil as $i => $h) {
            if (!isset($kullan[$i]) && ($yuva === 'diger' || $h['_alan'] === $yuva) && bes_gelisme_onemli_mi($h)) {
                $secilen[$yuva] = $i;
                $kullan[$i]     = true;
                break;
            }
        }
    }

    // 2. Bos kalan yuvalar: siradaki haber, ayni alandan en fazla iki.
    //    Sirayla: onemli haberler (alan basina 2), yine onemliler (alan
    //    basina 3: gunun onemli haberlerinin cogu gundem/politika; ucuncu
    //    bir gundem haberi, onemsiz bir saglik haberinden iyidir), ancak
    //    kutu yine dolmuyorsa (sakin gun) esigin altindakiler.
    $alanSayisi = array_count_values(array_map(static fn (int $i): string => $tekil[$i]['_alan'], $secilen));

    foreach ([[true, 2], [true, 3], [false, 2], [false, 3]] as [$yalnizOnemli, $alanTavani]) {
        foreach ($tekil as $i => $h) {
            if (count($secilen) >= $adet) {
                break 2;
            }

            if (isset($kullan[$i]) || ($alanSayisi[$h['_alan']] ?? 0) >= $alanTavani || ($yalnizOnemli && !bes_gelisme_onemli_mi($h))) {
                continue;
            }

            $secilen[] = $i;
            $kullan[$i] = true;
            $alanSayisi[$h['_alan']] = ($alanSayisi[$h['_alan']] ?? 0) + 1;
        }
    }

    // Gosterim sirasi: yuva sirasi (gundem, ekonomi, dunya, yasam, diger).
    $liste = [];

    foreach (array_slice($secilen, 0, $adet, true) as $i) {
        unset($tekil[$i]['_alan']);
        $liste[] = $tekil[$i];
    }

    return $liste;
}

/** Vitrindeki kutu. */
function bes_gelisme_html(): string
{
    $liste = bes_gelisme();

    if ($liste === []) {
        return '';
    }

    $html = '';

    foreach ($liste as $i => $h) {
        $html .= '<li><span class="bes-no">' . ($i + 1) . '</span><a href="' . e(haber_yolu((string) $h['slug'])) . '">'
            . '<span class="bes-baslik">'
            . (!empty($h['kategori_adi']) ? '<span class="bes-konu">' . e((string) $h['kategori_adi']) . '</span> ' : '')
            . e((string) $h['baslik']) . '</span>'
            . '</a></li>';
    }

    require_once __DIR__ . '/bulten.php';

    $bulten = bulten_acik()
        ? '<a class="bes-bulten" href="' . e(bulten_yolu()) . '">Her sabah 5 dakikada gündem: bülteni e-postanıza alın →</a>'
        : '';

    return '<section class="pencere bes-gelisme" aria-labelledby="bes-baslik">'
        . '<h2 class="pencere-baslik" id="bes-baslik">Bugün bilmeniz gereken ' . count($liste) . ' gelişme</h2>'
        . '<ol class="bes-liste">' . $html . '</ol>'
        . $bulten
        . '</section>';
}
