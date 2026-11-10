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
    'klasik' => ['ad' => 'Valentra Klasik', 'not' => 'Beyaz zemin, kırmızı imza', 'taban' => 'acik',
                 'renk' => ['#f7f7f8', '#ffffff', '#18181b', '#8a8c93', '#d7161f', '#e6e6ea']],
    'gazete' => ['ad' => 'Gazete Kâğıdı', 'not' => 'Sabah gazetesinin sıcak kâğıdı, klasik başlıklar', 'taban' => 'acik',
                 'renk' => ['#efe7d8', '#f8f2e6', '#241c14', '#8c7c69', '#9b1c1c', '#ddd1bd']],
    'zumrut' => ['ad' => 'Zümrüt', 'not' => 'Ferah yeşil, sakin okuma', 'taban' => 'acik',
                 'renk' => ['#eef4f0', '#ffffff', '#102a1f', '#7d978a', '#0b7a4b', '#d4e2d9']],
    'okyanus' => ['ad' => 'Okyanus', 'not' => 'Berrak mavi, net başlıklar', 'taban' => 'acik',
                 'renk' => ['#edf3fa', '#ffffff', '#0f1e33', '#7d8ca3', '#1a5cbc', '#d5e0ee']],
    'pudra'  => ['ad' => 'Pudra & Mürdüm', 'not' => 'Yumuşak pembe, mürdüm vurgu', 'taban' => 'acik',
                 'renk' => ['#f8f0f2', '#fffafb', '#2a1622', '#9a8291', '#8e2453', '#ebdbe2']],
    'gece'   => ['ad' => 'Gece', 'not' => 'Göz yormayan karanlık', 'taban' => 'koyu',
                 'renk' => ['#111113', '#1c1c20', '#f2f2f4', '#6e6e76', '#ef3b43', '#2e2e34']],
    'lacivert' => ['ad' => 'Gece Mavisi & Altın', 'not' => 'Lacivert gece, altın ışıltı', 'taban' => 'koyu',
                 'renk' => ['#0b1324', '#121d35', '#e9eef8', '#5f6f8c', '#f2b84b', '#24324f']],
    'komur'  => ['ad' => 'Kömür & Amber', 'not' => 'Derin siyah, sıcak amber ışık', 'taban' => 'koyu',
                 'renk' => ['#0a0a0a', '#161616', '#ededed', '#5c5c5c', '#ffb547', '#282828']],
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
        . '}catch(x){}})();</script>';
}

/** Üst banttaki tema düğmesi ve seçici paneli. */
function tema_secici_html(): string
{
    $secenekler = '';

    foreach (TEMALAR as $ad => $t) {
        [$zemin, $kart, $metin, $soluk, $vurgu, $cizgi] = $t['renk'];
        $stil = '--o-zemin:' . $zemin . ';--o-kart:' . $kart . ';--o-metin:' . $metin . ';--o-soluk:' . $soluk
              . ';--o-vurgu:' . $vurgu . ';--o-cizgi:' . $cizgi;

        $secenekler .= '<li><button type="button" class="tema-secenek" role="radio" aria-checked="false" data-palet="' . e($ad) . '">'
            . '<span class="tema-onizleme" style="' . e($stil) . '" aria-hidden="true">'
            . '<i class="o-logo"></i><i class="o-nokta"></i><i class="o-kart"></i><i class="o-baslik"></i><i class="o-satir1"></i>'
            . '<i class="o-satir2"></i><i class="o-rozet"></i><i class="o-yan1"></i><i class="o-yan2"></i><i class="o-yan3"></i><i class="o-yan4"></i>'
            . '</span>'
            . '<span class="tema-ad">' . e($t['ad']) . '<span class="tema-tik" aria-hidden="true">✓</span></span>'
            . '<span class="tema-not">' . e($t['not']) . '</span>'
            . '</button></li>';
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
        . '<div class="tema-panel-ust"><strong>Gazetenizi renklendirin</strong><span>Seçiminiz bu cihazda saklanır</span></div>'
        . '<ul class="tema-izgara" role="radiogroup" aria-label="Temalar">' . $secenekler . '</ul>'
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
        . 'function uygula(a){d.classList.add("tema-gecis");'
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
        . 'document.addEventListener("click",function(e){if(!p.hidden&&!p.contains(e.target)&&e.target!==b){kapa(false);}});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!p.hidden){kapa(true);}});'
        . 'window.addEventListener("resize",function(){if(!p.hidden){kapa(false);}});'
        . '})();</script>';
}
