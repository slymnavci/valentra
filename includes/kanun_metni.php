<?php
declare(strict_types=1);

/**
 * Kanun metnini resmî kaynaktan alıp site içinde gösterir.
 *
 * Neden cerceve degil: mevzuat.gov.tr sayfanin baska bir site icinde
 * gosterilmesine izin vermiyor (X-Frame-Options). Cerceve denendi,
 * bombos gri bir kutu cikti. Ustelik engellenen cerceve tarayicida yine
 * "load" olayini tetikledigi icin bunu JavaScript ile fark etmek de
 * guvenilir degil.
 *
 * Bu yuzden metin SUNUCU tarafinda cekiliyor ve kendi sayfamizda
 * gosteriliyor. Kopyasi TUTULMUYOR: her seferinde resmi kaynaktan
 * aliniyor, yalnizca kisa sureli onbellege konuyor. Boylece gosterilen
 * metin her zaman yururlukteki hali oluyor ve sayfanin ustunde de
 * resmi kaynaga giden bag duruyor.
 *
 * Erisim garanti degil: mevzuat.gov.tr veri merkezi IP'lerini
 * engelliyor (GitHub uzerinden yapilan kontrol 12 adresin 12'sine de
 * HTTP 0 dondurdu). Site Turkiye'de barindigi icin oradan erisim
 * mumkun olabilir; olmazsa sayfa duzgunce baglantiya dusuyor.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/url.php';

/** Onbellek suresi: kanun metinleri nadiren degisir. */
const KANUN_ONBELLEK_SURE = 6 * 3600;

/**
 * Kanunun gövde metnini döndürür.
 *
 * @return array{tamam:bool,govde:string,neden:string,onbellek:bool}
 */
function kanun_metni_getir(array $kanun): array
{
    $anahtar = 'kanun_metni_' . (int) $kanun['no'];
    $onbellek = kanun_onbellekten($anahtar);

    if ($onbellek !== null) {
        return ['tamam' => true, 'govde' => $onbellek, 'neden' => '', 'onbellek' => true];
    }

    $ham = kanun_indir(kanun_adresi($kanun));

    if ($ham === null) {
        return [
            'tamam'    => false,
            'govde'    => '',
            'neden'    => 'Resmî kaynağa şu anda ulaşılamadı.',
            'onbellek' => false,
        ];
    }

    $govde = kanun_govdeyi_ayikla($ham);

    if ($govde === '') {
        return [
            'tamam'    => false,
            'govde'    => '',
            'neden'    => 'Sayfa alındı ama kanun metni ayıklanamadı.',
            'onbellek' => false,
        ];
    }

    ayar_yaz($anahtar, json_encode(
        ['zaman' => time(), 'govde' => $govde],
        JSON_UNESCAPED_UNICODE
    ));

    return ['tamam' => true, 'govde' => $govde, 'neden' => '', 'onbellek' => false];
}

function kanun_onbellekten(string $anahtar): ?string
{
    $ham = ayar_oku($anahtar);

    if ($ham === '') {
        return null;
    }

    $veri = json_decode($ham, true);

    if (!is_array($veri) || !isset($veri['zaman'], $veri['govde'])) {
        return null;
    }

    if (time() - (int) $veri['zaman'] > KANUN_ONBELLEK_SURE) {
        return null;
    }

    return (string) $veri['govde'];
}

/** Kısa zaman aşımıyla indirir; ziyaretçinin sayfasını bekletmemeli. */
function kanun_indir(string $url): ?string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                . 'Chrome/128.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: tr-TR,tr;q=0.9',
        ],
    ]);

    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($govde) || $kod < 200 || $kod >= 300) {
        return null;
    }

    return $govde;
}

/**
 * Sayfadan kanun metnini ayıklar ve güvenli HTML'e indirger.
 *
 * Disaridan gelen HTML oldugu gibi basilamaz: script, iframe, form ve
 * olay nitelikleri sayfaya kod sokabilir. Bu yuzden beyaz liste
 * yaklasimi kullaniliyor — yalnizca metin bicimlendiren etiketler
 * birakiliyor, gerisi duz metne indirgeniyor.
 */
function kanun_govdeyi_ayikla(string $html): string
{
    // Metin tasimayan bloklari tamamen at.
    $html = (string) preg_replace(
        '#<(script|style|noscript|svg|nav|footer|header|form|iframe|object|embed)\b[^>]*>.*?</\1>#is',
        ' ',
        $html
    );

    $onceki = libxml_use_internal_errors(true);
    $belge  = new DOMDocument();
    $belge->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);

    $xpath = new DOMXPath($belge);

    /*
     * Metnin bulundugu kapsayici aranıyor.
     *
     * Kesin bir secici yok ve sayfa duzeni degisebilir; bu yuzden
     * "en cok metin tasiyan div" secilir. Belirli bir id'ye baglanmak
     * duzen degisince sessizce bos sonuc verirdi.
     */
    $enIyi     = null;
    $enIyiBoyut = 0;

    foreach ($xpath->query('//div|//article|//main|//td') ?: [] as $dugum) {
        $uzunluk = mb_strlen(trim($dugum->textContent), 'UTF-8');

        if ($uzunluk > $enIyiBoyut) {
            $enIyiBoyut = $uzunluk;
            $enIyi      = $dugum;
        }
    }

    if ($enIyi === null || $enIyiBoyut < 500) {
        return '';
    }

    return kanun_guvenli_html($belge, $enIyi);
}

/**
 * Düğümü yalnızca izin verilen etiketlerle yeniden yazar.
 *
 * Beyaz liste: yalnizca metin bicimlendiren etiketler. Nitelikler
 * tamamen atiliyor (href dahil), cunku disaridan gelen bir adres
 * ziyaretciyi baska yere goturebilir ya da javascript: tasiyabilir.
 */
function kanun_guvenli_html(DOMDocument $belge, DOMNode $dugum): string
{
    $izinli = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'sup', 'sub',
               'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
               'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span'];

    $yaz = static function (DOMNode $n) use (&$yaz, $izinli): string {
        if ($n->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($n->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $ad = strtolower($n->nodeName);
        $ic = '';

        foreach ($n->childNodes as $cocuk) {
            $ic .= $yaz($cocuk);
        }

        if (!in_array($ad, $izinli, true)) {
            return $ic;
        }

        if ($ad === 'br') {
            return '<br>';
        }

        // div ve span'i p'ye indirgemiyoruz ama nitelikleri atiyoruz.
        return '<' . $ad . '>' . $ic . '</' . $ad . '>';
    };

    return $yaz($dugum);
}
