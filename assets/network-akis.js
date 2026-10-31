/*
 * V Network akisi: X'teki gibi kalinan yer ve yeni paylasimlar
 * (31.10.2026, site sahibi: "bir haberi okudum, tikladim, geri donecegim;
 * geri gittigimde en son hangi haberi okuyorduysam o en ustte kalsin,
 * alttaki de devaminda olan; en uste tiklandiginda yeni haberleri basa
 * getirsin").
 *
 *  1. Karttaki bir baglantiya tiklaninca kartin kimligi oturum belleginde
 *     saklaniyor. Geri donuldugunde (ya da ayni akis sayfasi yeniden
 *     acildiginda) sayfa o kart en ustte olacak sekilde kaydiriliyor; yeni
 *     paylasimlar ustte birikmis olsa da okunan yer kaybolmuyor.
 *  2. Birkac dakikada bir yeni paylasim sayisi soruluyor (network-yeni.php);
 *     varsa ustte "↑ N yeni paylasim" dugmesi. Dugmeye, "Akis" sekmesine ya
 *     da logoya tiklaninca kalinan yer unutulup akisin en yenisi basa geliyor.
 */
(function () {
    'use strict';

    var akis = document.querySelector('.nw-orta');
    if (!akis) { return; }

    var ANAHTAR = 'nw-kalinan-yer';
    var BU_SAYFA = location.pathname + location.search;
    var bellek = null;
    try { bellek = window.sessionStorage; } catch (e) { bellek = null; }

    function ustBosluk() {
        var bant = document.querySelector('.nw-bant');
        return (bant ? bant.getBoundingClientRect().height : 60) + 12;
    }

    /* --- 1. Kalinan yer --- */
    akis.addEventListener('click', function (olay) {
        var bag = olay.target.closest('a, button');
        var kart = olay.target.closest('.nw-gonderi');
        if (!bag || !kart || !bellek) { return; }
        try {
            bellek.setItem(ANAHTAR, JSON.stringify({ sayfa: BU_SAYFA, kart: kart.id, zaman: Date.now() }));
        } catch (e) {}
    });

    function konumla() {
        if (!bellek) { return; }
        var kayit = null;
        try { kayit = JSON.parse(bellek.getItem(ANAHTAR) || 'null'); } catch (e) { kayit = null; }
        // Yarim saatten eski ya da baska bir akis sayfasina ait kayit yok sayilir.
        if (!kayit || kayit.sayfa !== BU_SAYFA || Date.now() - kayit.zaman > 30 * 60 * 1000) { return; }
        // Adreste #h12 gibi bir capa varsa (islemden donus) tarayici oraya gider.
        if (location.hash) { return; }
        var kart = document.getElementById(kayit.kart);
        if (!kart) { return; }
        if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
        window.scrollTo(0, kart.getBoundingClientRect().top + window.pageYOffset - ustBosluk());
    }

    function unut() {
        if (bellek) { try { bellek.removeItem(ANAHTAR); } catch (e) {} }
    }

    konumla();
    // Sayfa tarayicinin geri onbelleginden (bfcache) gelirse de.
    window.addEventListener('pageshow', function (olay) { if (olay.persisted) { konumla(); } });

    /* --- 2. Yeni paylasimlar --- */
    var ilk = akis.querySelector('.nw-gonderi[data-zaman]');
    var dugme = document.createElement('button');
    dugme.type = 'button';
    dugme.className = 'nw-yeni-dugme';
    dugme.hidden = true;
    document.body.appendChild(dugme);

    function enYeniyeGit() {
        unut();
        var adres = new URL(location.href);
        adres.searchParams.delete('once');
        adres.hash = '';
        if (adres.pathname + adres.search === BU_SAYFA && window.pageYOffset > 0 && dugme.hidden) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        location.href = adres.pathname + adres.search;
    }

    dugme.addEventListener('click', enYeniyeGit);

    // "Akis" sekmesi ve logo: kalinan yer unutulur, en yeni basa gelir.
    document.querySelectorAll('.nw-bant-logo, .nw-bant-menu a[href="/network"], .nw-sekmeler a').forEach(function (a) {
        a.addEventListener('click', function (olay) {
            unut();
            if (a.getAttribute('href') === BU_SAYFA && !location.hash) {
                olay.preventDefault();
                enYeniyeGit();
            }
        });
    });

    if (!ilk || new URL(location.href).searchParams.get('once')) { return; }

    var sekme = new URL(location.href).searchParams.get('sekme') || '';
    function sor() {
        if (document.hidden) { return; }
        fetch('/network-yeni.php?once=' + encodeURIComponent(ilk.getAttribute('data-zaman')) + (sekme ? '&sekme=' + encodeURIComponent(sekme) : ''),
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (y) { return y.ok ? y.json() : { sayi: 0 }; })
            .then(function (v) {
                if (v && v.sayi > 0) {
                    dugme.textContent = '↑ ' + v.sayi + (v.sayi >= 99 ? '+' : '') + ' yeni paylaşım';
                    dugme.hidden = false;
                }
            })
            .catch(function () {});
    }

    setInterval(sor, 90 * 1000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { sor(); } });
})();
