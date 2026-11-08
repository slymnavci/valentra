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
 * basta, sonra gunun onem puani; bkz. haber_sirasi) ve her konudan TEK
 * haber (konu_ayni_mi; bkz. includes/konu_siniri.php). Kaydirakla ayni
 * sirayi kullaniyor ama kaydirak ayni konunun iki acisini gosterebilir;
 * burada her satir ayri bir gelisme.
 */

require_once __DIR__ . '/haberler.php';
require_once __DIR__ . '/konu_siniri.php';

const BES_GELISME_ADET = 5;

/**
 * Birbirini tekrar etmeyen en önemli gelişmeler.
 *
 * @return list<array<string,mixed>>
 */
function bes_gelisme(int $adet = BES_GELISME_ADET): array
{
    try {
        $adaylar = haber_manset(40, true);
    } catch (Throwable $e) {
        error_log('[valentra] bes gelisme: ' . $e->getMessage());

        return [];
    }

    $secilen = [];
    $kokler  = [];

    foreach ($adaylar as $h) {
        $k = konu_kokleri((string) $h['baslik']);

        foreach ($kokler as $onceki) {
            if (konu_ayni_mi($k, $onceki)) {
                continue 2;
            }
        }

        $secilen[] = $h;
        $kokler[]  = $k;

        if (count($secilen) >= $adet) {
            break;
        }
    }

    return $secilen;
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
