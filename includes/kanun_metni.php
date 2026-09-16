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
 * Ikinci tuzak: "/mevzuat?MevzuatNo=..." adresi bir JavaScript
 * uygulamasi. Sunucudan gelen HTML bos bir kabuk; metin tarayicida
 * sonradan doluyor. Yani indirme basarili olsa bile icinde kanun
 * metni cikmiyor. Bu yuzden once metnin duragan dosya halleri
 * (PDF, DOC) deneniyor, sayfa en son sirada.
 *
 * Erisim yine de garanti degil: mevzuat.gov.tr veri merkezi IP'lerini
 * engelliyor olabilir (GitHub uzerinden yapilan kontrol 12 adresin
 * 12'sine de HTTP 0 dondurdu; bu ortamdan da cikis kapali). Site
 * Turkiye'de barindigi icin oradan erisim mumkun. Hangi yolun
 * tuttugunu gormek icin: /kanun.php?k=213&tani=1
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/http_ortak.php';

/** Onbellek suresi: kanun metinleri nadiren degisir. */
const KANUN_ONBELLEK_SURE = 6 * 3600;

/**
 * Kanunun sayfada nasıl gösterileceğini belirler.
 *
 * Uc yol sirayla deneniyor ve ilk tutan kullaniliyor:
 *   1) PDF  - resmi metnin duragan hali; tarayicinin kendi
 *             goruntuleyicisinde, kendi sayfamizin icinde acilir.
 *   2) DOC  - bazi kanunlarda Word-HTML olarak duruyor; ayiklanip
 *             metin olarak basilir.
 *   3) Sayfa- JavaScript uygulamasi oldugu icin genelde bos gelir,
 *             yine de son sans olarak deneniyor.
 *
 * Sonuc (hangi yolun tuttugu ve varsa metin) onbellege yaziliyor;
 * PDF'in kendisi onbellege KONMUYOR, o her istekte kaynaktan
 * aktariliyor.
 *
 * @return array{tur:string,govde:string,neden:string,onbellek:bool,
 *               denemeler:list<array{ad:string,url:string,kod:int,sonuc:string}>}
 */
function kanun_gosterim(array $kanun, bool $onbellekKullan = true): array
{
    $anahtar = 'kanun_gosterim_' . (int) $kanun['no'];

    if ($onbellekKullan) {
        $onbellek = kanun_onbellekten($anahtar);

        if ($onbellek !== null) {
            return $onbellek + ['onbellek' => true, 'denemeler' => []];
        }
    }

    $adresler  = kanun_metin_adresleri($kanun);
    $denemeler = [];
    $sonuc     = null;

    // 1) PDF: yalnizca bas kismi indirilip dosya imzasina bakiliyor.
    $pdf = http_bas_getir($adresler['pdf'], 1024, 15);

    if (!$pdf['tamam']) {
        $denemeler[] = ['ad' => 'PDF', 'url' => $adresler['pdf'],
                        'kod' => $pdf['kod'], 'sonuc' => $pdf['neden']];
    } elseif (!str_starts_with($pdf['govde'], '%PDF')) {
        $denemeler[] = ['ad' => 'PDF', 'url' => $adresler['pdf'],
                        'kod' => $pdf['kod'], 'sonuc' => 'Yanıt PDF değil.'];
    } else {
        $denemeler[] = ['ad' => 'PDF', 'url' => $adresler['pdf'],
                        'kod' => $pdf['kod'], 'sonuc' => 'Tamam'];
        $sonuc = ['tur' => 'pdf', 'govde' => '', 'neden' => ''];
    }

    // 2) DOC: Word-HTML ise ayikla.
    if ($sonuc === null) {
        $doc = http_getir($adresler['doc'], 25);

        if (!$doc['tamam']) {
            $denemeler[] = ['ad' => 'DOC', 'url' => $adresler['doc'],
                            'kod' => $doc['kod'], 'sonuc' => $doc['neden']];
        } else {
            $govde = kanun_word_html_ayikla($doc['govde']);

            $denemeler[] = ['ad' => 'DOC', 'url' => $adresler['doc'],
                            'kod' => $doc['kod'],
                            'sonuc' => $govde === ''
                                ? 'Dosya alındı (' . strlen($doc['govde'])
                                  . ' bayt) ama metin ayıklanamadı.'
                                : 'Tamam'];

            if ($govde !== '') {
                $sonuc = ['tur' => 'html', 'govde' => $govde, 'neden' => ''];
            }
        }
    }

    // 3) Son care: uygulamanin kendi sayfasi.
    if ($sonuc === null) {
        $sayfa = http_getir($adresler['sayfa'], 25);

        if (!$sayfa['tamam']) {
            $denemeler[] = ['ad' => 'Sayfa', 'url' => $adresler['sayfa'],
                            'kod' => $sayfa['kod'], 'sonuc' => $sayfa['neden']];
        } else {
            $govde = kanun_govdeyi_ayikla($sayfa['govde']);

            $denemeler[] = ['ad' => 'Sayfa', 'url' => $adresler['sayfa'],
                            'kod' => $sayfa['kod'],
                            'sonuc' => $govde === ''
                                ? 'Sayfa alındı (' . strlen($sayfa['govde'])
                                  . ' bayt) ama metin yok; JavaScript ile doluyor.'
                                : 'Tamam'];

            if ($govde !== '') {
                $sonuc = ['tur' => 'html', 'govde' => $govde, 'neden' => ''];
            }
        }
    }

    if ($sonuc === null) {
        return [
            'tur'       => 'yok',
            'govde'     => '',
            'neden'     => kanun_neden_ozetle($denemeler),
            'onbellek'  => false,
            'denemeler' => $denemeler,
        ];
    }

    ayar_yaz($anahtar, json_encode(
        ['zaman' => time()] + $sonuc,
        JSON_UNESCAPED_UNICODE
    ));

    return $sonuc + ['onbellek' => false, 'denemeler' => $denemeler];
}

/**
 * Başarısız denemeleri tek cümlede özetler.
 *
 * Onceki surumde yalnizca son hata gorunuyordu; hangi yolun nerede
 * takildigi belli olmadigi icin sorunu uzaktan cozmek korlemesineydi.
 *
 * @param list<array{ad:string,url:string,kod:int,sonuc:string}> $denemeler
 */
function kanun_neden_ozetle(array $denemeler): string
{
    $parcalar = [];

    foreach ($denemeler as $deneme) {
        $parcalar[] = $deneme['ad'] . ': ' . $deneme['sonuc'];
    }

    return implode(' | ', $parcalar);
}

/**
 * Word olarak sunulan metni ayıklar.
 *
 * mevzuat.gov.tr'deki ".doc" dosyalarinin bir kismi gercekte Word'un
 * HTML olarak kaydettigi dosyalar; bunlar ayiklanabiliyor. Bir kismi
 * ise ikili (binary) Word belgesi ve PHP ile makul bicimde
 * okunamiyor — o durumda bos donup bir sonraki yola geciliyor.
 */
function kanun_word_html_ayikla(string $govde): string
{
    $bas = substr($govde, 0, 4096);

    if (stripos($bas, '<html') === false && stripos($bas, '<body') === false) {
        return '';
    }

    // Word'un kosullu yorumlari ve stil bloklari metin tasimaz.
    $govde = (string) preg_replace('#<!--.*?-->#s', ' ', $govde);

    return kanun_govdeyi_ayikla($govde);
}

/**
 * Önbellekteki kararı döndürür.
 *
 * Yalnizca "hangi yol tuttu" ve varsa metin saklaniyor. PDF yolunda
 * dosyanin kendisi saklanmiyor: kanun metninin kalici kopyasini
 * tutmamak bilincli bir tercih (bkz. dosya basi).
 *
 * @return array{tur:string,govde:string,neden:string}|null
 */
function kanun_onbellekten(string $anahtar): ?array
{
    $ham = ayar_oku($anahtar);

    if ($ham === '') {
        return null;
    }

    $veri = json_decode($ham, true);

    if (!is_array($veri) || !isset($veri['zaman'], $veri['tur'])) {
        return null;
    }

    if (time() - (int) $veri['zaman'] > KANUN_ONBELLEK_SURE) {
        return null;
    }

    return [
        'tur'   => (string) $veri['tur'],
        'govde' => (string) ($veri['govde'] ?? ''),
        'neden' => '',
    ];
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
