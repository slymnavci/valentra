/*
 * Ziyaretci raporu grafikleri (admin/istatistik.php gosterge paneli, 29.10.2026).
 *
 * Kutuphane yok: her .gs-grafik ogesinin data-grafik JSON'undan satir ici
 * SVG ciziliyor. Turler: "cizgi" (bir ya da iki seri), "sutun" (tek seri),
 * "yigin" (kaynaklara gore yigilmis sutun). Fare ya da klavye (sol/sag ok)
 * ile gunun butun degerleri ipucu kutusunda; ayni degerler grafigin
 * altindaki "Tablo olarak goster"de de var (ipucu tek yol degil).
 *
 * Isaret olculeri: cizgi 2 px, sutun en fazla 24 px ve ucu 4 px yuvarlak,
 * yigin dilimleri arasinda 2 px zemin boslugu, izgara 1 px duz cizgi.
 */
(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var sayiBicim = function (n, ondalik) {
        return Number(n).toLocaleString('tr-TR', { minimumFractionDigits: ondalik || 0, maximumFractionDigits: ondalik || 0 });
    };

    function el(ad, nit, ebeveyn) {
        var e = document.createElementNS(NS, ad);
        Object.keys(nit || {}).forEach(function (k) { e.setAttribute(k, nit[k]); });
        if (ebeveyn) { ebeveyn.appendChild(e); }
        return e;
    }

    /** 0'dan baslayan "temiz" eksen: 4 aralik, adim 1-2-2,5-5 x 10^n. */
    function eksen(enCok, ondalik) {
        if (enCok <= 0) { enCok = ondalik ? 1 : 4; }
        var kaba = enCok / 4, us = Math.pow(10, Math.floor(Math.log10(kaba))), adim = us;
        [1, 2, 2.5, 5, 10].some(function (c) { adim = c * us; return adim >= kaba; });
        if (!ondalik) { adim = Math.max(1, Math.ceil(adim)); }
        return { ust: adim * 4, adim: adim };
    }

    /** Ust kosesi yuvarlak (r) dikdortgen; tabani kare. */
    function sutunYolu(x, y, g, h, r) {
        r = Math.min(r, g / 2, h);
        return 'M' + x + ',' + (y + h) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y
            + 'H' + (x + g - r) + 'Q' + (x + g) + ',' + y + ' ' + (x + g) + ',' + (y + r) + 'V' + (y + h) + 'Z';
    }

    function ciz(kap, v) {
        var en = Math.max(280, kap.clientWidth), boy = 220;
        var sol = 44, sag = 16, ust = 14, alt = 26;
        var pg = en - sol - sag, py = boy - ust - alt;
        var n = v.etiketler.length, ondalik = v.ondalik || 0;
        var yigin = v.tur === 'yigin';

        var toplamlar = v.etiketler.map(function (_, i) {
            return v.seriler.reduce(function (t, s) { return t + Number(s.degerler[i] || 0); }, 0);
        });
        var enCok = yigin ? Math.max.apply(null, toplamlar)
            : Math.max.apply(null, v.seriler.map(function (s) { return Math.max.apply(null, s.degerler.map(Number)); }));
        var ek = eksen(enCok, ondalik);
        var Y = function (d) { return ust + py - d / ek.ust * py; };
        var bant = pg / n;
        var X = function (i) { return sol + bant * i + bant / 2; };

        kap.textContent = '';
        var svg = el('svg', { viewBox: '0 0 ' + en + ' ' + boy, width: en, height: boy, role: 'img', tabindex: '0',
            'aria-label': (v.seriler.map(function (s) { return s.ad; }).join(', ')) + ' grafiği; değerler için sol/sağ ok tuşları' }, kap);

        // Izgara ve y ekseni
        for (var t = 0; t <= 4; t++) {
            var d = ek.adim * t, y = Y(d);
            el('line', { x1: sol, x2: en - sag, y1: y, y2: y, 'class': t === 0 ? 'gs-taban' : 'gs-izgara' }, svg);
            var yazi = el('text', { x: sol - 8, y: y + 4, 'text-anchor': 'end', 'class': 'gs-eksen' }, svg);
            yazi.textContent = d >= 1000 ? sayiBicim(d / 1000, d % 1000 ? 1 : 0) + 'B' : sayiBicim(d, ondalik && d % 1 ? 1 : 0);
        }

        // X etiketleri: sigdigi kadar
        var her = Math.max(1, Math.ceil(n / Math.floor(pg / 46)));
        v.etiketler.forEach(function (et, i) {
            if (i % her !== 0 && i !== n - 1) { return; }
            if (i === n - 1 && i % her !== 0 && (i % her) < her / 2) { return; }
            var tx = el('text', { x: X(i), y: boy - 8, 'text-anchor': 'middle', 'class': 'gs-eksen' }, svg);
            tx.textContent = et;
        });

        var vurgu = el('g', {}, svg);
        var genislik = Math.min(24, bant * 0.62);

        if (v.tur === 'sutun' || yigin) {
            v.etiketler.forEach(function (_, i) {
                var taban = Y(0), x = X(i) - genislik / 2;
                var seriler = yigin ? v.seriler : [v.seriler[0]];
                var dolu = seriler.map(function (s) { return Number(s.degerler[i] || 0); });
                var enUst = -1;
                dolu.forEach(function (dd, j) { if (dd > 0) { enUst = j; } });
                seriler.forEach(function (s, j) {
                    var dd = dolu[j];
                    if (dd <= 0) { return; }
                    var h = Y(0) - Y(dd);
                    // Dilimler arasi 2 px zemin boslugu: her dilim ustten 2 px kisa.
                    var gh = yigin && j !== enUst ? Math.max(0, h - 2) : h;
                    var yy = taban - h;
                    if (gh <= 0) { taban -= h; return; }
                    el('path', { d: j === enUst ? sutunYolu(x, yy, genislik, gh, 4) : 'M' + x + ',' + (yy + (h - gh)) + 'h' + genislik + 'v' + gh + 'h' + (-genislik) + 'Z',
                        fill: s.renk, 'class': 'gs-isaret', 'data-i': i }, svg);
                    taban -= h;
                });
            });
        } else {
            v.seriler.forEach(function (s, j) {
                var noktalar = s.degerler.map(function (dd, i) { return X(i) + ',' + Y(Number(dd)); });
                if (v.seriler.length === 1) {
                    el('path', { d: 'M' + X(0) + ',' + Y(0) + 'L' + noktalar.join('L') + 'L' + X(n - 1) + ',' + Y(0) + 'Z',
                        fill: s.renk, 'class': 'gs-alan' }, svg);
                }
                el('polyline', { points: noktalar.join(' '), stroke: s.renk, 'class': 'gs-cizgi' }, svg);
                // Son nokta ve degeri (secici etiket: her noktaya sayi yazilmiyor)
                var son = Number(s.degerler[n - 1]);
                el('circle', { cx: X(n - 1), cy: Y(son), r: 4, fill: s.renk, 'class': 'gs-uc' }, svg);
                if (j === 0 || Math.abs(Y(son) - Y(Number(v.seriler[0].degerler[n - 1]))) > 14) {
                    var uy = el('text', { x: X(n - 1) - 8, y: Y(son) - 10, 'text-anchor': 'end', 'class': 'gs-uc-deger' }, svg);
                    uy.textContent = sayiBicim(son, ondalik);
                }
            });
        }

        // Etkilesim: dikey cizgi en yakin gune oturur; ipucu o gunun butun serileri.
        var capraz = el('line', { y1: ust, y2: ust + py, 'class': 'gs-capraz', visibility: 'hidden' }, vurgu);
        var ipucu = document.createElement('div');
        ipucu.className = 'gs-ipucu';
        ipucu.hidden = true;
        kap.appendChild(ipucu);
        var secili = -1;

        function goster(i) {
            secili = Math.max(0, Math.min(n - 1, i));
            var x = X(secili);
            capraz.setAttribute('x1', x); capraz.setAttribute('x2', x); capraz.setAttribute('visibility', 'visible');
            svg.querySelectorAll('.gs-isaret').forEach(function (p) { p.classList.toggle('gs-soluk', Number(p.getAttribute('data-i')) !== secili); });

            ipucu.textContent = '';
            var b = document.createElement('div'); b.className = 'gs-ipucu-baslik'; b.textContent = v.ipuclari[secili]; ipucu.appendChild(b);
            var satirlar = v.seriler.slice();
            if (yigin) { satirlar = satirlar.slice().reverse(); }
            satirlar.forEach(function (s) {
                var r = document.createElement('div'); r.className = 'gs-ipucu-satir';
                var k = document.createElement('span'); k.className = 'gs-ipucu-anahtar'; k.style.background = s.renk; r.appendChild(k);
                var dg = document.createElement('strong'); dg.textContent = sayiBicim(s.degerler[secili], ondalik); r.appendChild(dg);
                var ad = document.createElement('span'); ad.textContent = s.ad; r.appendChild(ad);
                ipucu.appendChild(r);
            });
            if (yigin) {
                var tp = document.createElement('div'); tp.className = 'gs-ipucu-satir gs-ipucu-toplam';
                var tdg = document.createElement('strong'); tdg.textContent = sayiBicim(toplamlar[secili]); tp.appendChild(tdg);
                var tad = document.createElement('span'); tad.textContent = 'toplam ' + (v.birim || ''); tp.appendChild(tad);
                ipucu.appendChild(tp);
            }
            ipucu.hidden = false;
            var kx = x / en * kap.clientWidth;
            var sagaYasla = kx > kap.clientWidth * 0.6;
            ipucu.style.left = sagaYasla ? '' : (kx + 12) + 'px';
            ipucu.style.right = sagaYasla ? (kap.clientWidth - kx + 12) + 'px' : '';
        }

        function gizle() {
            capraz.setAttribute('visibility', 'hidden');
            ipucu.hidden = true;
            secili = -1;
            svg.querySelectorAll('.gs-soluk').forEach(function (p) { p.classList.remove('gs-soluk'); });
        }

        svg.addEventListener('pointermove', function (o) {
            var r = svg.getBoundingClientRect();
            var x = (o.clientX - r.left) / r.width * en;
            goster(Math.round((x - sol - bant / 2) / bant));
        });
        svg.addEventListener('pointerleave', gizle);
        svg.addEventListener('blur', gizle);
        svg.addEventListener('keydown', function (o) {
            if (o.key === 'ArrowRight' || o.key === 'ArrowLeft') {
                o.preventDefault();
                goster(secili < 0 ? n - 1 : secili + (o.key === 'ArrowRight' ? 1 : -1));
            } else if (o.key === 'Escape') { gizle(); }
        });
    }

    function hepsi() {
        document.querySelectorAll('.gs-grafik[data-grafik]').forEach(function (kap) {
            try { ciz(kap, JSON.parse(kap.getAttribute('data-grafik'))); } catch (e) { kap.textContent = ''; }
        });
    }

    hepsi();
    var zaman = null;
    window.addEventListener('resize', function () { clearTimeout(zaman); zaman = setTimeout(hepsi, 150); });
})();
