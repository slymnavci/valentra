<?php
declare(strict_types=1);

/**
 * Okuyucu temalari (10.11.2026, site sahibi: "gece/gunduz yerine tema;
 * bastiginda renk secenekleri gelsin, istedigi renkte gazeteyi okusun;
 * cok sik secenekler; Gece de bir tema olarak kalsin").
 *
 * Renkler assets/style.css'te (:root[data-palet="..."]). taban: temanin
 * acik ya da koyu oldugu (data-tema); koyu temalarda gece kurallari
 * (logo, gorsel parlakligi vb.) da gecerli. Onizleme renkleri secicideki
 * kucuk gazete cizimi icin.
 */

const TEMALAR = [
    /*
     * EDITORYAL (10.11.2026, site sahibi: "cok profesyonel bir tema; okuyucu
     * sectiginde site bambaska bir gorunume kavussun"). Renkten fazlasi:
     * dergi duzeni (cetvel cizgileri, keskin koseler, serif basliklar, bas
     * harf). Kurallari kendi dosyasinda ('dosya'); yalnizca secen okura
     * yuklenir (bkz. tema_dosya_betigi).
     */
    'editoryal' => ['ad' => 'Editoryal', 'not' => 'Dergi düzeni: fildişi kâğıt, mürekkep laciverti, serif başlıklar', 'taban' => 'acik', 'grup' => 'İmza tasarım',
                 'renk' => ['#f4f0e6', '#fbf8f1', '#0f1a2c', '#8f8a7c', '#8a5a12', '#0f1a2c'],
                 'dosya' => '/assets/tema-editoryal.css'],
    'klasik' => ['ad' => 'Valentra Klasik', 'not' => 'Beyaz zemin, kırmızı imza', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#f7f7f8', '#ffffff', '#18181b', '#8a8c93', '#d7161f', '#e6e6ea']],
    'gazete' => ['ad' => 'Gazete Kâğıdı', 'not' => 'Sabah gazetesinin sıcak kâğıdı, klasik başlıklar', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#efe7d8', '#f8f2e6', '#241c14', '#8c7c69', '#9b1c1c', '#ddd1bd']],
    'zumrut' => ['ad' => 'Zümrüt', 'not' => 'Ferah yeşil, sakin okuma', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#eef4f0', '#ffffff', '#102a1f', '#7d978a', '#0b7a4b', '#d4e2d9']],
    'okyanus' => ['ad' => 'Okyanus', 'not' => 'Berrak mavi, net başlıklar', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#edf3fa', '#ffffff', '#0f1e33', '#7d8ca3', '#1a5cbc', '#d5e0ee']],
    'pudra'  => ['ad' => 'Pudra & Mürdüm', 'not' => 'Yumuşak pembe, mürdüm vurgu', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#f8f0f2', '#fffafb', '#2a1622', '#9a8291', '#8e2453', '#ebdbe2']],
    'gunbatimi' => ['ad' => 'Gün Batımı', 'not' => 'Şeftali zemin, kiremit vurgu', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#fbf3ec', '#fffaf5', '#2b1a10', '#a08572', '#c2410c', '#f0dfd0']],
    'lavanta' => ['ad' => 'Lavanta', 'not' => 'Lila tonlar, mor imza', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#f4f2fb', '#ffffff', '#1e1838', '#8a85a8', '#5b3fd1', '#e2ddf3']],
    'grafit' => ['ad' => 'Grafit', 'not' => 'Sade gri, siyah vurgu, dergi havası', 'taban' => 'acik', 'grup' => 'Aydınlık',
                 'renk' => ['#f2f2f1', '#ffffff', '#1a1a1a', '#8f8f8f', '#1a1a1a', '#e0e0de']],
    'gece'   => ['ad' => 'Gece', 'not' => 'Göz yormayan karanlık', 'taban' => 'koyu', 'grup' => 'Koyu',
                 'renk' => ['#111113', '#1c1c20', '#f2f2f4', '#6e6e76', '#ef3b43', '#2e2e34']],
    'lacivert' => ['ad' => 'Gece Mavisi & Altın', 'not' => 'Lacivert gece, altın ışıltı', 'taban' => 'koyu', 'grup' => 'Koyu',
                 'renk' => ['#0b1324', '#121d35', '#e9eef8', '#5f6f8c', '#f2b84b', '#24324f']],
    'komur'  => ['ad' => 'Kömür & Amber', 'not' => 'Derin siyah, sıcak amber ışık', 'taban' => 'koyu', 'grup' => 'Koyu',
                 'renk' => ['#0a0a0a', '#161616', '#ededed', '#5c5c5c', '#ffb547', '#282828']],
    'sise'   => ['ad' => 'Şişe Yeşili', 'not' => 'Koyu yeşil zemin, nane vurgu', 'taban' => 'koyu', 'grup' => 'Koyu',
                 'renk' => ['#0c1a17', '#12241f', '#e6f2ee', '#5d7a71', '#4fd1a5', '#1f3a33']],
    'siyahbeyaz' => ['ad' => 'Siyah-Beyaz Gazete', 'not' => 'Her şey siyah-beyaz, yalnızca fotoğraflar renkli', 'taban' => 'acik', 'grup' => 'Özel',
                 'renk' => ['#f5f5f2', '#ffffff', '#111111', '#9a9a9a', '#111111', '#d9d9d6'], 'foto' => true],
    'kontrast' => ['ad' => 'Yüksek Kontrast', 'not' => 'Siyah zemin, beyaz yazı, sarı vurgu', 'taban' => 'koyu', 'grup' => 'Özel',
                 'renk' => ['#000000', '#000000', '#ffffff', '#e0e0e0', '#ffd400', '#ffffff']],
];

/** Yazı boyutları (data-boyut; boş = normal). */
const TEMA_BOYUTLARI = ['kucuk' => ['Küçük', '.8rem'], '' => ['Normal', '.95rem'], 'buyuk' => ['Büyük', '1.15rem'], 'cokbuyuk' => ['Çok büyük', '1.35rem']];

/** Yazı tipleri (data-font; boş = modern). */
const TEMA_FONTLARI = [
    ''         => ['Modern', '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif'],
    'klasik'   => ['Klasik', 'Georgia, Cambria, "Times New Roman", serif'],
    'kitap'    => ['Kitap', '"Iowan Old Style", "Palatino Linotype", Palatino, "Book Antiqua", serif'],
    'okunakli' => ['Okunaklı', 'Verdana, "Segoe UI", Tahoma, sans-serif'],
];

/** <head> içinde, stil dosyasından önce: seçili temayı hemen uygular. */
function tema_bas_betigi(): string
{
    $taban = [];

    foreach (TEMALAR as $ad => $t) {
        $taban[$ad] = $t['taban'];
    }

    // Eski gece/gunduz secimi (valentra-tema) temaya cevriliyor: koyu -> Gece.
    return '<script>(function(){try{var T=' . json_encode($taban) . ',d=document.documentElement,'
        . 'p=localStorage.getItem("valentra-palet"),e=localStorage.getItem("valentra-tema");'
        . 'if(!p&&e==="koyu"){p="gece";}else if(!p&&e==="acik"){p="klasik";}'
        . 'if(p&&T[p]){d.setAttribute("data-tema",T[p]);if(p!=="klasik"&&p!=="gece"){d.setAttribute("data-palet",p);}}'
        . 'var b=localStorage.getItem("valentra-boyut"),f=localStorage.getItem("valentra-font");'
        . 'if(b){d.setAttribute("data-boyut",b);}if(f){d.setAttribute("data-font",f);}'
        . '}catch(x){}})();</script>';
}

/** Üst banttaki tema düğmesi ve seçici paneli. */
/**
 * Kendi stil dosyasi olan temalar: palet => surumlu adres.
 *
 * @return array<string,string>
 */
function tema_dosyalari(): array
{
    $d = [];

    foreach (TEMALAR as $ad => $t) {
        if (!empty($t['dosya'])) {
            $d[$ad] = varlik((string) $t['dosya']);
        }
    }

    return $d;
}

/**
 * Secili temanin kendi stil dosyasi (varsa), style.css'ten SONRA.
 *
 * document.write bilincli: ayristirici bu stil dosyasini bekler, sayfa bir
 * an temasiz gorunmez. Yalnizca boyle bir tema secmis okurda calisir;
 * diger okurlar (ve olcum araclari) icin hicbir sey yuklenmez.
 */
function tema_dosya_betigi(): string
{
    $d = tema_dosyalari();

    if ($d === []) {
        return '';
    }

    return '<script>(function(){var D=' . json_encode($d, JSON_UNESCAPED_SLASHES) . ',p=document.documentElement.getAttribute("data-palet");'
        . 'if(p&&D[p]){document.write(\'<link rel="stylesheet" id="tema-dosya" data-palet="\'+p+\'" href="\'+D[p]+\'">\');}'
        . '})();</script>';
}

function tema_secici_html(): string
{
    $secenekler = '';
    $grup = '';

    foreach (TEMALAR as $ad => $t) {
        if ($t['grup'] !== $grup) {
            $secenekler .= ($grup !== '' ? '</ul>' : '') . '<p class="tema-bolum">' . e($t['grup']) . '</p>'
                . '<ul class="tema-izgara" role="radiogroup" aria-label="' . e($t['grup']) . ' temalar">';
            $grup = $t['grup'];
        }

        [$zemin, $kart, $metin, $soluk, $vurgu, $cizgi] = $t['renk'];
        $stil = '--o-zemin:' . $zemin . ';--o-kart:' . $kart . ';--o-metin:' . $metin . ';--o-soluk:' . $soluk
              . ';--o-vurgu:' . $vurgu . ';--o-cizgi:' . $cizgi;

        $secenekler .= '<li><button type="button" class="tema-secenek" role="radio" aria-checked="false" data-palet="' . e($ad) . '">'
            . '<span class="tema-onizleme" style="' . e($stil) . '" aria-hidden="true">'
            . '<i class="o-logo"></i><i class="o-nokta"></i><i class="o-kart"></i><i class="o-baslik"></i><i class="o-satir1"></i>'
            . (!empty($t['foto']) ? '<i class="o-foto"></i>' : '')
            . '<i class="o-satir2"></i><i class="o-rozet"></i><i class="o-yan1"></i><i class="o-yan2"></i><i class="o-yan3"></i><i class="o-yan4"></i>'
            . '</span>'
            . '<span class="tema-ad">' . e($t['ad']) . '<span class="tema-tik" aria-hidden="true">✓</span></span>'
            . '<span class="tema-not">' . e($t['not']) . '</span>'
            . '</button></li>';
    }

    $boyutlar = '';

    foreach (TEMA_BOYUTLARI as $kod => [$ad, $punto]) {
        $boyutlar .= '<li><button type="button" role="radio" aria-checked="false" data-boyut="' . e($kod) . '">'
            . '<span class="ornek" style="font-size:' . e($punto) . '">A</span><span class="ad">' . e($ad) . '</span></button></li>';
    }

    $fontlar = '';

    foreach (TEMA_FONTLARI as $kod => [$ad, $aile]) {
        $fontlar .= '<li><button type="button" role="radio" aria-checked="false" data-font="' . e($kod) . '">'
            . '<span class="ornek" style="font-family:' . e($aile) . ';font-size:1.25rem">Aa</span><span class="ad">' . e($ad) . '</span></button></li>';
    }

    return '<div class="tema-secici">'
        . '<button type="button" class="tema-dugme" id="tema-dugme" aria-haspopup="true" aria-expanded="false" aria-controls="tema-panel"'
        . ' aria-label="Tema seç" title="Tema seç">'
        . '<svg class="tema-boya" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.8 1.8-1.7 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-.9.8-1.7 1.7-1.7H17a4 4 0 0 0 4-4C21 6.6 17 3 12 3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>'
        . '<circle cx="7.5" cy="11" r="1.3" fill="currentColor"/><circle cx="10" cy="7" r="1.3" fill="currentColor"/>'
        . '<circle cx="14.5" cy="7" r="1.3" fill="currentColor"/><circle cx="17" cy="11" r="1.3" fill="currentColor"/>'
        . '</svg></button>'
        . '<div class="tema-panel" id="tema-panel" role="dialog" aria-label="Gazete teması" hidden>'
        . '<div class="tema-panel-ust"><strong>Gazetenizi kendinize göre ayarlayın</strong><span>Bu cihazda saklanır</span></div>'
        . $secenekler . '</ul>'
        . '<p class="tema-bolum">Yazı boyutu</p><ul class="tema-ayar" role="radiogroup" aria-label="Yazı boyutu">' . $boyutlar . '</ul>'
        . '<p class="tema-bolum">Yazı tipi</p><ul class="tema-ayar" role="radiogroup" aria-label="Yazı tipi">' . $fontlar . '</ul>'
        . '<div class="tema-otomatik"><span>Ya da</span><button type="button" role="radio" aria-checked="false" data-palet="">'
        . 'cihazıma göre gündüz/gece</button></div>'
        . '</div></div>';
}

/** Seçicinin betiği (sayfa sonunda). */
function tema_secici_betigi(): string
{
    $taban = [];

    foreach (TEMALAR as $ad => $t) {
        $taban[$ad] = $t['taban'];
    }

    return '<script>(function(){'
        . 'var T=' . json_encode($taban) . ',d=document.documentElement,b=document.getElementById("tema-dugme"),'
        . 'p=document.getElementById("tema-panel");if(!b||!p){return;}'
        . 'var s=p.querySelectorAll("[data-palet]");'
        . 'function oku(){try{return localStorage.getItem("valentra-palet")||"";}catch(x){return "";}}'
        . 'function etiket(){var a=oku();if(!a){var e=null;try{e=localStorage.getItem("valentra-tema");}catch(x){}a=e==="koyu"?"gece":(e==="acik"?"klasik":"");}'
        . 's.forEach(function(o){o.setAttribute("aria-checked",o.getAttribute("data-palet")===a?"true":"false");});}'
        // Kendi stil dosyasi olan tema: dosya yuklenince uygulanir (yari
        // giyinmis sayfa gorunmesin).
        . 'var D=' . json_encode(tema_dosyalari(), JSON_UNESCAPED_SLASHES) . ';'
        . 'function uygula(a){if(a&&D[a]){var l=document.getElementById("tema-dosya");'
        . 'if(!l||l.getAttribute("data-palet")!==a){if(l){l.remove();}l=document.createElement("link");l.rel="stylesheet";l.id="tema-dosya";'
        . 'l.setAttribute("data-palet",a);l.href=D[a];l.onload=function(){uygula2(a);};l.onerror=function(){uygula2(a);};document.head.appendChild(l);return;}}'
        . 'uygula2(a);}'
        . 'function uygula2(a){d.classList.add("tema-gecis");'
        . 'if(a&&T[a]){d.setAttribute("data-tema",T[a]);if(a!=="klasik"&&a!=="gece"){d.setAttribute("data-palet",a);}else{d.removeAttribute("data-palet");}}'
        . 'else{d.removeAttribute("data-tema");d.removeAttribute("data-palet");}'
        . 'try{if(a){localStorage.setItem("valentra-palet",a);}else{localStorage.removeItem("valentra-palet");}localStorage.removeItem("valentra-tema");}catch(x){}'
        . 'var m=document.querySelector("meta[name=theme-color]");if(m){m.setAttribute("content",getComputedStyle(d).getPropertyValue("--kart").trim()||"#1f1f23");}'
        . 'setTimeout(function(){d.classList.remove("tema-gecis");},400);etiket();}'
        // Panel govdeye tasiniyor: ust bandin kirpmasi/katmani paneli ortmesin.
        . 'function ac(){if(p.parentNode!==document.body){document.body.appendChild(p);}'
        . 'var r=b.getBoundingClientRect();if(window.innerWidth>640){p.style.top=(r.bottom+10)+"px";p.style.right=Math.max(16,window.innerWidth-r.right)+"px";}else{p.style.top="";p.style.right="";}'
        . 'p.hidden=false;b.setAttribute("aria-expanded","true");etiket();var c=p.querySelector("[aria-checked=true]")||s[0];if(c){c.focus();}}'
        . 'function kapa(o){p.hidden=true;b.setAttribute("aria-expanded","false");if(o){b.focus();}}'
        . 'b.addEventListener("click",function(e){e.stopPropagation();p.hidden?ac():kapa(false);});'
        . 's.forEach(function(o){o.addEventListener("click",function(){uygula(o.getAttribute("data-palet"));});});'
        . 'function ayar(n){var g=p.querySelectorAll("[data-"+n+"]"),v="";try{v=localStorage.getItem("valentra-"+n)||"";}catch(x){}'
        . 'function isaret(){g.forEach(function(o){o.setAttribute("aria-checked",o.getAttribute("data-"+n)===v?"true":"false");});}'
        . 'isaret();g.forEach(function(o){o.addEventListener("click",function(){v=o.getAttribute("data-"+n);'
        . 'if(v){d.setAttribute("data-"+n,v);}else{d.removeAttribute("data-"+n);}'
        . 'try{if(v){localStorage.setItem("valentra-"+n,v);}else{localStorage.removeItem("valentra-"+n);}}catch(x){}isaret();});});}'
        . 'ayar("boyut");ayar("font");'
        . 'document.addEventListener("click",function(e){if(!p.hidden&&!p.contains(e.target)&&e.target!==b){kapa(false);}});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!p.hidden){kapa(true);}});'
        . 'window.addEventListener("resize",function(){if(!p.hidden){kapa(false);}});'
        . '})();</script>';
}
