/*
 * Tarayici bildirimi ve "uygulama olarak yukle" (03.11.2026; bkz.
 * includes/web_push.php, /sw.js).
 *
 * - Service worker her sayfada kaydediliyor (kurulabilir site icin sart).
 * - Izin hemen istenmiyor (tarayicilar ilk saniyede soran siteleri
 *   cezalandiriyor): okur sayfada 20 saniye kalinca ya da ikinci sayfayi
 *   acinca kucuk bir kutu: "Son dakika haberlerini kacirmayin". "Daha
 *   sonra" 7 gun, kapatma 30 gun sormuyor.
 * - iPhone/iPad Safari'de bildirim yalnizca ana ekrana eklenmis sitede
 *   calisiyor: orada kutu "Ana ekrana ekleyin" diyor.
 * - Android/Chrome'da "Uygulamayi yukle" dugmesi de kutuda.
 * - Alt bantta [data-bildirim-dugme]: ac / kapat.
 */
(function () {
    'use strict';

    var betik = document.currentScript;
    var anahtar = betik ? betik.getAttribute('data-anahtar') : '';
    var destek = 'serviceWorker' in navigator;
    var pushVar = destek && 'PushManager' in window && 'Notification' in window;
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var bagimsiz = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    var yuklemeIstegi = null;
    var kayit = null;

    function oku(ad) { try { return localStorage.getItem(ad); } catch (e) { return null; } }
    function yaz(ad, deger) { try { localStorage.setItem(ad, deger); } catch (e) {} }
    function ertelendi() { return Number(oku('vz_bildirim_ertele') || 0) > Date.now(); }
    function ertele(gun) { yaz('vz_bildirim_ertele', String(Date.now() + gun * 864e5)); }

    function anahtarDizisi(b64) {
        var dolgu = '='.repeat((4 - b64.length % 4) % 4);
        var ham = atob((b64 + dolgu).replace(/-/g, '+').replace(/_/g, '/'));
        var dizi = new Uint8Array(ham.length);
        for (var i = 0; i < ham.length; i++) { dizi[i] = ham.charCodeAt(i); }
        return dizi;
    }

    function sunucuya(islem, abonelik) {
        var j = abonelik.toJSON();
        var govde = new URLSearchParams({ islem: islem, uc: j.endpoint });
        if (islem === 'abone') {
            govde.append('p256dh', j.keys.p256dh);
            govde.append('auth', j.keys.auth);
            govde.append('tarayici', ios ? 'ios' : (/android/i.test(navigator.userAgent) ? 'android' : 'masaustu'));
        }
        return fetch('/bildirim.php', { method: 'POST', body: govde, credentials: 'same-origin' });
    }

    function abone() {
        if (!kayit || !anahtar) { return Promise.reject(new Error('hazir degil')); }
        return Notification.requestPermission().then(function (izin) {
            if (izin !== 'granted') { throw new Error('izin yok'); }
            return kayit.pushManager.getSubscription().then(function (var_) {
                return var_ || kayit.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: anahtarDizisi(anahtar) });
            });
        }).then(function (s) {
            yaz('vz_bildirim', 'acik');
            if (window.gtag) { try { gtag('event', 'bildirim_abone'); } catch (e) {} }
            return sunucuya('abone', s);
        });
    }

    function iptal() {
        if (!kayit) { return Promise.resolve(); }
        return kayit.pushManager.getSubscription().then(function (s) {
            yaz('vz_bildirim', 'kapali');
            if (!s) { return; }
            return sunucuya('iptal', s).catch(function () {}).then(function () { return s.unsubscribe(); });
        });
    }

    /* ---- kutu ---- */

    var kutu = null;

    function kapat(gun) {
        if (kutu) { kutu.remove(); kutu = null; }
        if (gun) { ertele(gun); }
    }

    function kutuGoster() {
        if (kutu || ertelendi() || document.getElementById('cerezBildirimi') && !document.getElementById('cerezBildirimi').hidden) { return; }

        var iosIpucu = ios && !bagimsiz;
        if (!iosIpucu && !(pushVar && Notification.permission === 'default' && anahtar)) { return; }

        kutu = document.createElement('div');
        kutu.className = 'bildirim-kutu';
        kutu.setAttribute('role', 'dialog');
        kutu.setAttribute('aria-label', 'Bildirimler');

        var simge = '<span class="bildirim-kutu-simge" aria-hidden="true">🔔</span>';
        if (iosIpucu) {
            kutu.innerHTML = simge + '<div class="bildirim-kutu-metin"><strong>Valentra\'yı ana ekranınıza ekleyin</strong>'
                + '<span>Alttaki <b>Paylaş</b> <span aria-hidden="true">⬆︎</span> düğmesine, sonra <b>Ana Ekrana Ekle</b>\'ye dokunun. Son dakika bildirimlerini de alırsınız.</span></div>'
                + '<div class="bildirim-kutu-dugmeler"><button type="button" data-k="tamam" class="ana">Tamam</button></div>'
                + '<button type="button" class="bildirim-kutu-kapat" data-k="kapat" aria-label="Kapat">×</button>';
        } else {
            kutu.innerHTML = simge + '<div class="bildirim-kutu-metin"><strong>Son dakika haberlerini kaçırmayın</strong>'
                + '<span>Önemli gelişmelerde size kısa bir bildirim gönderelim. Günde birkaç taneyi geçmez; istediğiniz an kapatabilirsiniz.</span></div>'
                + '<div class="bildirim-kutu-dugmeler"><button type="button" data-k="sonra">Daha sonra</button>'
                + (yuklemeIstegi ? '<button type="button" data-k="yukle">Uygulamayı yükle</button>' : '')
                + '<button type="button" data-k="ac" class="ana">Bildirimleri aç</button></div>'
                + '<button type="button" class="bildirim-kutu-kapat" data-k="kapat" aria-label="Kapat">×</button>';
        }

        kutu.addEventListener('click', function (o) {
            var k = o.target.closest('[data-k]');
            if (!k) { return; }
            var ne = k.getAttribute('data-k');
            if (ne === 'ac') {
                abone().then(function () { kapat(0); dugmeGuncelle(); }).catch(function () { kapat(30); });
            } else if (ne === 'yukle') {
                uygulamaYukle();
            } else if (ne === 'sonra') {
                kapat(7);
            } else {
                kapat(ne === 'tamam' ? 30 : 30);
            }
        });
        document.body.appendChild(kutu);
        requestAnimationFrame(function () { if (kutu) { kutu.classList.add('acik'); } });
    }

    function uygulamaYukle() {
        if (!yuklemeIstegi) { return; }
        yuklemeIstegi.prompt();
        yuklemeIstegi.userChoice.then(function (s) {
            if (window.gtag) { try { gtag('event', 'uygulama_yukle', { sonuc: s && s.outcome }); } catch (e) {} }
            yuklemeIstegi = null;
            var d = kutu && kutu.querySelector('[data-k="yukle"]');
            if (d) { d.remove(); }
        });
    }

    window.addEventListener('beforeinstallprompt', function (o) {
        o.preventDefault();
        yuklemeIstegi = o;
        document.querySelectorAll('[data-uygulama-dugme]').forEach(function (d) { d.hidden = false; });
    });

    /* ---- alt bant dugmeleri ---- */

    function dugmeGuncelle() {
        var dugmeler = document.querySelectorAll('[data-bildirim-dugme]');
        if (!dugmeler.length) { return; }
        if (!pushVar || !kayit) {
            dugmeler.forEach(function (d) { d.hidden = !(ios && !bagimsiz); d.textContent = '🔔 Bildirim almak için ana ekrana ekleyin'; d.disabled = true; });
            return;
        }
        kayit.pushManager.getSubscription().then(function (s) {
            var acik = !!s && Notification.permission === 'granted';
            dugmeler.forEach(function (d) {
                d.hidden = false;
                d.disabled = Notification.permission === 'denied';
                d.textContent = Notification.permission === 'denied' ? '🔕 Bildirimler tarayıcıda engelli'
                    : (acik ? '🔕 Bildirimleri kapat' : '🔔 Son dakika bildirimlerini aç');
                d.setAttribute('aria-pressed', acik ? 'true' : 'false');
            });
        });
    }

    document.addEventListener('click', function (o) {
        var d = o.target.closest('[data-bildirim-dugme]');
        if (d && !d.disabled) {
            (d.getAttribute('aria-pressed') === 'true' ? iptal() : abone()).catch(function () {}).then(dugmeGuncelle);
        }
        var u = o.target.closest('[data-uygulama-dugme]');
        if (u) { uygulamaYukle(); }
    });

    /* ---- baslangic ---- */

    if (!destek) { return; }

    navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function (r) {
        kayit = r;
        return navigator.serviceWorker.ready;
    }).then(function (r) {
        kayit = r;
        dugmeGuncelle();

        // Izin verilmis ama abonelik kaybolmus (tarayici temizligi): sessizce yenile.
        if (pushVar && Notification.permission === 'granted' && oku('vz_bildirim') !== 'kapali' && anahtar) {
            r.pushManager.getSubscription().then(function (s) {
                return s || r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: anahtarDizisi(anahtar) });
            }).then(function (s) { return sunucuya('abone', s); }).catch(function () {});
            return;
        }

        // Ikinci sayfa ya da 20 saniye.
        var sayac = 0;
        try { sayac = Number(sessionStorage.getItem('vz_sayfa') || 0) + 1; sessionStorage.setItem('vz_sayfa', String(sayac)); } catch (e) {}
        setTimeout(kutuGoster, sayac >= 2 ? 4000 : 20000);
    }).catch(function () {});
})();
