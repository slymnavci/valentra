/*
 * Valentra service worker (03.11.2026; bkz. includes/web_push.php).
 *
 * 1) Tarayici bildirimi: sunucunun sifreli mesajini gosterir, tiklaninca
 *    haberi acar (acik Valentra sekmesi varsa onu kullanir).
 * 2) Kurulabilir site (PWA): sayfalar her zaman agdan gelir, haberler
 *    onbellege alinmaz (eski haber gostermesin). Baglanti yoksa kisa bir
 *    "cevrimdisi" sayfasi.
 */
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (o) { o.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (o) {
    var v = {};
    try { v = o.data ? o.data.json() : {}; } catch (e) { v = { baslik: o.data ? o.data.text() : '' }; }
    var baslik = v.baslik || 'Valentra';
    var secenek = {
        body: v.metin || '',
        icon: '/assets/logo-512.png',
        badge: '/assets/ikon-rozet.png',
        tag: v.etiket || 'valentra',
        renotify: true,
        data: { adres: v.adres || '/', etiket: v.etiket || '' },
        lang: 'tr'
    };
    if (v.gorsel) { secenek.image = v.gorsel; }
    o.waitUntil(self.registration.showNotification(baslik, secenek));
});

self.addEventListener('notificationclick', function (o) {
    o.notification.close();
    var veri = o.notification.data || {};
    var adres = new URL(veri.adres || '/', self.location.origin).href;
    var no = String(veri.etiket || '').replace(/^v/, '');
    var bildir = /^\d+$/.test(no)
        ? fetch('/bildirim.php', { method: 'POST', body: new URLSearchParams({ islem: 'tik', g: no }) }).catch(function () {})
        : Promise.resolve();
    o.waitUntil(Promise.all([bildir, self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (pencereler) {
        for (var i = 0; i < pencereler.length; i++) {
            var p = pencereler[i];
            if (p.url.indexOf(self.location.origin) === 0 && 'navigate' in p) {
                return p.navigate(adres).then(function (q) { return (q || p).focus(); });
            }
        }
        return self.clients.openWindow(adres);
    })]));
});

// Abonelik tarayici tarafindan yenilenirse sunucuya yeni adresi bildir.
self.addEventListener('pushsubscriptionchange', function (o) {
    var eski = o.oldSubscription;
    o.waitUntil((o.newSubscription ? Promise.resolve(o.newSubscription)
        : self.registration.pushManager.subscribe(eski ? eski.options : { userVisibleOnly: true }))
        .then(function (yeni) {
            var j = yeni.toJSON();
            return fetch('/bildirim.php', { method: 'POST', body: new URLSearchParams({
                islem: 'abone', uc: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth, tarayici: 'yenileme'
            }) });
        }).catch(function () {}));
});

var CEVRIMDISI = '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Bağlantı yok — Valentra</title><body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;background:#fff;color:#1f1f23;text-align:center;padding:16px">'
    + '<div><h1 style="font-size:1.4rem">İnternet bağlantısı yok</h1><p>Bağlantı gelince sayfa yeniden yüklenecek.</p>'
    + '<button onclick="location.reload()" style="font:inherit;padding:10px 18px;border:0;border-radius:8px;background:#c0121b;color:#fff">Yeniden dene</button></div>'
    + '<script>addEventListener("online",function(){location.reload()})</script></body></html>';

self.addEventListener('fetch', function (o) {
    if (o.request.mode !== 'navigate' || o.request.method !== 'GET') { return; }
    o.respondWith(fetch(o.request).catch(function () {
        return new Response(CEVRIMDISI, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }));
});
