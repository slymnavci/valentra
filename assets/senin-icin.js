/*
 * "Senin icin": sehrim ve ilgi alanlarim (03.11.2026; bkz.
 * includes/senin_icin.php, api/senin-icin.php).
 *
 * Secim tarayicida (localStorage 'vz_tercih': {s: 'izmir', k: ['spor']}).
 * Secim yoksa kucuk bir tanitim kutusu; varsa sehrin ve konularin
 * haberleri. "Bu bolumu gizle" 60 gun sormuyor. Hava kartinda secilen il
 * (hava_il) ilk secimde sehir olarak oneriliyor.
 */
(function () {
    'use strict';

    var kutu = document.getElementById('senin-icin');
    if (!kutu || !window.fetch) { return; }

    var konular = {}, sehirler = {};
    try { konular = JSON.parse(kutu.getAttribute('data-konular') || '{}'); sehirler = JSON.parse(kutu.getAttribute('data-sehirler') || '{}'); } catch (e) { return; }

    function oku(ad) { try { return localStorage.getItem(ad); } catch (e) { return null; } }
    function yaz(ad, d) { try { localStorage.setItem(ad, d); } catch (e) {} }

    var tercih = null;
    try { tercih = JSON.parse(oku('vz_tercih') || 'null'); } catch (e) { tercih = null; }
    if (Number(oku('vz_tercih_gizli') || 0) > Date.now()) { return; }

    var ayarlar = kutu.querySelector('[data-senin-ayarlar]');
    var tanit = kutu.querySelector('[data-senin-tanit]');
    var haberler = kutu.querySelector('[data-senin-haberler]');
    var ozet = kutu.querySelector('[data-senin-ozet]');
    var sec = kutu.querySelector('[data-senin-sehir]');
    var konuKutu = kutu.querySelector('[data-senin-konular]');

    Object.keys(sehirler).forEach(function (k) {
        var o = document.createElement('option'); o.value = k; o.textContent = sehirler[k]; sec.appendChild(o);
    });
    Object.keys(konular).forEach(function (k) {
        var l = document.createElement('label'); l.className = 'senin-cip';
        var c = document.createElement('input'); c.type = 'checkbox'; c.value = k;
        var s = document.createElement('span'); s.textContent = konular[k];
        l.appendChild(c); l.appendChild(s); konuKutu.appendChild(l);
    });

    function formuDoldur() {
        var t = tercih || { s: (oku('hava_il') || ''), k: [] };
        sec.value = sehirler[t.s] ? t.s : '';
        konuKutu.querySelectorAll('input').forEach(function (c) { c.checked = (t.k || []).indexOf(c.value) !== -1; });
    }

    function getir() {
        if (!tercih || (!tercih.s && !(tercih.k || []).length)) { return; }
        var parcalar = [];
        if (tercih.s && sehirler[tercih.s]) { parcalar.push(sehirler[tercih.s]); }
        (tercih.k || []).forEach(function (k) { if (konular[k]) { parcalar.push(konular[k]); } });
        ozet.textContent = parcalar.join(' · ');
        haberler.innerHTML = '<p class="senin-yukleniyor">Haberler yükleniyor…</p>';
        var adres = '/api/senin-icin.php?s=' + encodeURIComponent(tercih.s || '') + '&k=' + encodeURIComponent((tercih.k || []).slice().sort().join(','));
        fetch(adres, { credentials: 'omit' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (html) {
            haberler.innerHTML = html || '<p class="senin-bos">Şu an haber getirilemedi.</p>';
        }).catch(function () { haberler.innerHTML = ''; });
    }

    function goster() {
        kutu.hidden = false;
        var var_ = tercih && (tercih.s || (tercih.k || []).length);
        tanit.hidden = !!var_ || !ayarlar.hidden;
        ozet.hidden = !var_;
        kutu.querySelector('.senin-ayarla').hidden = !var_;
        kutu.classList.toggle('senin-secimsiz', !var_);
    }

    kutu.addEventListener('click', function (o) {
        var d = o.target.closest('[data-senin]');
        if (!d) { return; }
        var ne = d.getAttribute('data-senin');
        if (ne === 'ayarla') {
            formuDoldur();
            ayarlar.hidden = !ayarlar.hidden;
            goster();
        } else if (ne === 'kaydet') {
            var k = [];
            konuKutu.querySelectorAll('input:checked').forEach(function (c) { k.push(c.value); });
            tercih = { s: sec.value, k: k };
            yaz('vz_tercih', JSON.stringify(tercih));
            if (window.gtag) { try { gtag('event', 'senin_icin_kaydet', { sehir: tercih.s, konu_sayisi: k.length }); } catch (e) {} }
            ayarlar.hidden = true;
            goster();
            getir();
        } else if (ne === 'gizle') {
            yaz('vz_tercih_gizli', String(Date.now() + 60 * 864e5));
            kutu.hidden = true;
        }
    });

    goster();
    getir();
})();
