/*
 * Paylasim dugmeleri (03.11.2026; bkz. includes/paylas.php).
 * Baglantilar betiksiz de calisiyor; burada: "Baglantiyi kopyala", telefonun
 * kendi paylas menusu ve Analytics olayi. Olay belgeye bagli: kaydirma
 * akisinda sonradan gelen haberlerin dugmeleri de calisiyor.
 */
(function () {
    'use strict';

    var yerel = typeof navigator.share === 'function';

    function goster() {
        if (!yerel) { return; }
        document.querySelectorAll('.paylas-yerel[hidden]').forEach(function (d) { d.hidden = false; });
    }

    function olay(yontem) {
        if (window.gtag) { try { gtag('event', 'share', { method: yontem, content_type: 'haber' }); } catch (e) {} }
    }

    function kopyala(metin) {
        if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(metin); }
        return new Promise(function (tamam, hata) {
            var t = document.createElement('textarea');
            t.value = metin; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            try { document.execCommand('copy') ? tamam() : hata(); } catch (e) { hata(e); }
            t.remove();
        });
    }

    document.addEventListener('click', function (o) {
        var d = o.target.closest('[data-paylas]');
        if (!d) { return; }
        var ne = d.getAttribute('data-paylas');
        var kutu = d.closest('[data-paylas-adres]');
        var adres = kutu ? kutu.getAttribute('data-paylas-adres') : location.href;
        var baslik = kutu ? kutu.getAttribute('data-paylas-baslik') : document.title;

        if (ne === 'kopyala') {
            o.preventDefault();
            kopyala(adres).then(function () {
                d.classList.add('kopyalandi');
                var eski = d.getAttribute('title');
                d.setAttribute('title', 'Kopyalandı');
                setTimeout(function () { d.classList.remove('kopyalandi'); d.setAttribute('title', eski); }, 1800);
            }).catch(function () { window.prompt('Bağlantıyı kopyalayın:', adres); });
            olay('kopyala');
        } else if (ne === 'yerel') {
            o.preventDefault();
            navigator.share({ title: baslik, text: baslik, url: adres.replace('utm_source=paylas', 'utm_source=yerel') }).then(function () { olay('yerel'); }).catch(function () {});
        } else {
            olay(ne);
        }
    });

    goster();
    // Kaydirma akisinda sonradan eklenen haberler
    if (yerel && 'MutationObserver' in window) {
        new MutationObserver(goster).observe(document.body, { childList: true, subtree: true });
    }
})();
