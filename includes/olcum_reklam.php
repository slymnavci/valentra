<?php
declare(strict_types=1);

/**
 * Google Analytics (GA4), AdSense ve çerez bildirimi.
 *
 * Site sahibinin istegi (29.10.2026): "tiklanma sayisi artmasi lazim";
 * kaydirma akisinda gelen her haber Google Analytics'te de ayri sayfa
 * goruntulemesi sayilsin, reklam gosterilsin. Kimlikler panelden
 * (Yonetim > Arama motoru ayarlari); bos ise hicbir betik yuklenmiyor.
 *
 * Izin (KVKK): Google'in izin kipi (Consent Mode v2) varsayilan "red";
 * okur cerez bildiriminde "Kabul et" derse cerezli olcum ve kisisellestirilmis
 * reklam aciliyor, "Reddet" derse cerezsiz sayim ve kisisellestirilmemis
 * reklam. Secim tarayicida saklaniyor.
 */

require_once __DIR__ . '/ayarlar.php';

/** GA4 ölçüm kimliği (G-XXXXXXX); tanımsızsa boş. */
function olcum_ga4(): string
{
    $k = strtoupper(trim(ayar_oku('ga4_kimlik')));

    return preg_match('/^G-[A-Z0-9]{4,15}$/', $k) === 1 ? $k : '';
}

/** AdSense yayıncı kimliği (ca-pub-…); tanımsızsa boş. */
function reklam_istemci(): string
{
    $k = trim(ayar_oku('adsense_kimlik'));

    if (preg_match('/^pub-\d{10,20}$/', $k) === 1) {
        $k = 'ca-' . $k;
    }

    return preg_match('/^ca-pub-\d{10,20}$/', $k) === 1 ? $k : '';
}

/** Haber içi reklam biriminin kimliği (data-ad-slot); yoksa boş (otomatik reklam). */
function reklam_birim(): string
{
    $k = trim(ayar_oku('adsense_birim'));

    return preg_match('/^\d{6,12}$/', $k) === 1 ? $k : '';
}

/** <head> içine: izin varsayılanları, GA4 ve AdSense betikleri. */
function olcum_head_html(): string
{
    $ga = olcum_ga4();
    $ad = reklam_istemci();

    if ($ga === '' && $ad === '') {
        return '';
    }

    $html = "<script>\nwindow.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\n"
        . "gtag('consent', 'default', {ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});\n"
        . "try { if (localStorage.getItem('vz_cerez') === 'evet') { gtag('consent', 'update', {ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted'}); } } catch (e) {}\n"
        . "</script>\n";

    if ($ga !== '') {
        $html .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . '"></script>' . "\n"
            . "<script>gtag('js', new Date()); gtag('config', " . json_encode($ga) . ");</script>\n";
    }

    if ($ad !== '') {
        $html .= '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . e($ad) . '" crossorigin="anonymous"></script>' . "\n";
    }

    return $html;
}

/**
 * Haber içi reklam alanı; AdSense tanımsızsa boş.
 *
 * $hemen: betikle hemen doldur (sayfanin kendi haberi). Kaydirmayla gelen
 * haberde innerHTML ile eklenen betik calismadigi icin akis betigi
 * yeni alanlari kendisi dolduruyor (haber.php).
 */
function reklam_alani_html(bool $hemen = true): string
{
    $istemci = reklam_istemci();

    if ($istemci === '') {
        return '';
    }

    $birim = reklam_birim();

    return '<div class="reklam-alani"><span class="reklam-etiket">Reklam</span>'
        . '<ins class="adsbygoogle" style="display:block" data-ad-client="' . e($istemci) . '"'
        . ($birim !== '' ? ' data-ad-slot="' . e($birim) . '"' : '')
        . ' data-ad-format="auto" data-full-width-responsive="true"' . ($hemen ? ' data-vz-dolu="1"' : '') . '></ins></div>'
        . ($hemen ? '<script>(window.adsbygoogle = window.adsbygoogle || []).push({});</script>' : '');
}

/** Çerez bildirimi (yalnızca GA4 ya da AdSense açıksa). */
function cerez_bildirimi_html(): string
{
    if (olcum_ga4() === '' && reklam_istemci() === '') {
        return '';
    }

    require_once __DIR__ . '/kurumsal.php';

    $gizlilik = e(kurumsal_yolu('gizlilik'));

    return <<<HTML
<div class="cerez-bildirimi" id="cerezBildirimi" hidden role="dialog" aria-label="Çerez bildirimi">
    <p>Valentra, ziyaretleri ölçmek ve reklam göstermek için çerez kullanır. Kabul ederseniz ölçüm ve reklamlar size göre kişiselleştirilebilir.
        <a href="{$gizlilik}">Ayrıntılar</a></p>
    <div class="cerez-dugmeler">
        <button type="button" data-cerez="hayir">Reddet</button>
        <button type="button" data-cerez="evet" class="ana">Kabul et</button>
    </div>
</div>
<script>
(function () {
    var k = document.getElementById('cerezBildirimi'), s = null;
    try { s = localStorage.getItem('vz_cerez'); } catch (e) {}
    if (!k || s) { return; }
    k.hidden = false;
    k.addEventListener('click', function (o) {
        var c = o.target.getAttribute('data-cerez');
        if (!c) { return; }
        try { localStorage.setItem('vz_cerez', c); } catch (e) {}
        if (c === 'evet' && window.gtag) {
            gtag('consent', 'update', {ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted'});
        }
        k.hidden = true;
    });
})();
</script>
HTML;
}
