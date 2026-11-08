/*
 * Günün Kelimesi (gunun-kelimesi.php). Tahminler api/kelime.php'de
 * karsilastiriliyor; oyunun o gunku durumu ve istatistik tarayicida.
 */
(function () {
    'use strict';

    var kok = document.getElementById('kelimeOyun');
    if (!kok || !window.fetch) { return; }

    var GUN = kok.dataset.gun, GUN_YAZI = kok.dataset.gunYazi;
    var UZ = parseInt(kok.dataset.uzunluk, 10), HAK = parseInt(kok.dataset.hak, 10), API = kok.dataset.api;
    var izgara = document.getElementById('kelimeIzgara'), mesaj = document.getElementById('kelimeMesaj');
    var klavyeKutu = document.getElementById('kelimeKlavye');
    var DURUM_ANAHTARI = 'vk_kelime', ISTATISTIK_ANAHTARI = 'vk_kelime_ist';
    var SIRALAR = ['ERTYUIOPĞÜ', 'ASDFGHJKLŞİ', '+ZCVBNMÖÇ-'];
    var EMOJI = ['⬜', '🟨', '🟩'];

    function oku(anahtar, varsayilan) {
        try { var v = JSON.parse(localStorage.getItem(anahtar)); return v || varsayilan; } catch (e) { return varsayilan; }
    }
    function yaz(anahtar, deger) { try { localStorage.setItem(anahtar, JSON.stringify(deger)); } catch (e) {} }

    var durum = oku(DURUM_ANAHTARI, null);
    if (!durum || durum.gun !== GUN) { durum = { gun: GUN, tahminler: [], sonuclar: [], bitti: false, kazandi: false, cevap: null }; }
    var yazilan = '', bekliyor = false;

    function buyuk(h) { return h.toLocaleUpperCase('tr-TR'); }

    // Izgara
    var kutular = [];
    for (var r = 0; r < HAK; r++) {
        var satir = document.createElement('div');
        satir.className = 'kelime-satir';
        satir.setAttribute('role', 'row');
        kutular.push([]);
        for (var c = 0; c < UZ; c++) {
            var k = document.createElement('div');
            k.className = 'kelime-kutu';
            k.setAttribute('role', 'gridcell');
            satir.appendChild(k);
            kutular[r].push(k);
        }
        izgara.appendChild(satir);
    }

    // Klavye
    var tuslar = {};
    SIRALAR.forEach(function (sira) {
        var s = document.createElement('div');
        s.className = 'kelime-klavye-satir';
        Array.from(sira).forEach(function (h) {
            var b = document.createElement('button');
            b.type = 'button';
            if (h === '+') { b.textContent = 'Enter'; b.className = 'genis'; b.dataset.tus = 'Enter'; }
            else if (h === '-') { b.textContent = '⌫'; b.className = 'genis'; b.dataset.tus = 'Backspace'; b.setAttribute('aria-label', 'Sil'); }
            else { b.textContent = h; b.dataset.tus = h; tuslar[h] = b; }
            s.appendChild(b);
        });
        klavyeKutu.appendChild(s);
    });

    var ETIKET = ['yok', 'başka yerde', 'doğru yerde'];

    function ciz() {
        var harfDurum = {};
        for (var r = 0; r < HAK; r++) {
            var t = durum.tahminler[r] || (r === durum.tahminler.length ? yazilan : '');
            var harfler = Array.from(t);
            for (var c = 0; c < UZ; c++) {
                var k = kutular[r][c], s = durum.sonuclar[r];
                k.textContent = harfler[c] || '';
                k.className = 'kelime-kutu' + (harfler[c] ? ' dolu' : '') + (s ? ' k' + s[c] : '');
                k.setAttribute('aria-label', harfler[c] ? harfler[c] + (s ? ', ' + ETIKET[s[c]] : '') : 'boş');
                if (s && harfler[c]) { harfDurum[harfler[c]] = Math.max(harfDurum[harfler[c]] === undefined ? -1 : harfDurum[harfler[c]], s[c]); }
            }
        }
        Object.keys(tuslar).forEach(function (h) {
            tuslar[h].className = harfDurum[h] === undefined ? '' : 'k' + harfDurum[h];
        });
        if (durum.bitti) { sonucuGoster(); }
    }

    function bildir(m) { mesaj.textContent = m; }

    function bitir(kazandi, cevap, haber) {
        durum.bitti = true; durum.kazandi = kazandi; durum.cevap = cevap; durum.haber = haber || null;
        yaz(DURUM_ANAHTARI, durum);
        var ist = oku(ISTATISTIK_ANAHTARI, { oynanan: 0, kazanilan: 0, seri: 0, enIyiSeri: 0, sonGun: '' });
        if (ist.sonGun !== GUN) {
            ist.oynanan++;
            if (kazandi) {
                ist.kazanilan++;
                var dun = new Date(GUN + 'T12:00:00'); dun.setDate(dun.getDate() - 1);
                ist.seri = ist.sonKazanilan === dun.toISOString().slice(0, 10) ? ist.seri + 1 : 1;
                ist.sonKazanilan = GUN;
                ist.enIyiSeri = Math.max(ist.enIyiSeri, ist.seri);
            } else { ist.seri = 0; }
            ist.sonGun = GUN;
            yaz(ISTATISTIK_ANAHTARI, ist);
        }
        ciz();
    }

    function paylasimMetni() {
        var satirlar = durum.sonuclar.map(function (s) { return s.map(function (d) { return EMOJI[d]; }).join(''); });
        return 'Valentra Günün Kelimesi ' + GUN_YAZI + ' ' + (durum.kazandi ? durum.sonuclar.length : 'X') + '/' + HAK
            + '\n' + satirlar.join('\n') + '\n' + location.origin + location.pathname;
    }

    function sonucuGoster() {
        var kutu = document.getElementById('kelimeSonuc');
        kutu.hidden = false;
        klavyeKutu.hidden = true;
        document.getElementById('kelimeSonucBaslik').textContent = durum.kazandi
            ? 'Tebrikler! ' + durum.sonuclar.length + '. tahminde buldunuz: ' + (durum.cevap || '')
            : 'Bugünün kelimesi: ' + (durum.cevap || '');
        if (durum.haber) {
            var bag = document.getElementById('kelimeHaberBag');
            bag.textContent = durum.haber.baslik;
            bag.href = durum.haber.adres;
            document.getElementById('kelimeHaber').hidden = false;
        }
        document.getElementById('kelimeWhatsapp').href = 'https://wa.me/?text=' + encodeURIComponent(paylasimMetni());
        var ist = oku(ISTATISTIK_ANAHTARI, { oynanan: 0, kazanilan: 0, seri: 0, enIyiSeri: 0 });
        var dl = document.getElementById('kelimeIstatistik');
        dl.textContent = '';
        [['Oynanan', ist.oynanan], ['Kazanma', ist.oynanan ? Math.round(ist.kazanilan / ist.oynanan * 100) + '%' : '—'],
         ['Seri', ist.seri], ['En iyi seri', ist.enIyiSeri]].forEach(function (p) {
            var d = document.createElement('div');
            var dd = document.createElement('dd'); dd.textContent = p[1];
            var dt = document.createElement('dt'); dt.textContent = p[0];
            d.appendChild(dd); d.appendChild(dt); dl.appendChild(d);
        });
    }

    function gonder() {
        if (bekliyor || durum.bitti) { return; }
        if (Array.from(yazilan).length !== UZ) { bildir(UZ + ' harfli bir kelime yazın.'); return; }
        bekliyor = true;
        var veri = new URLSearchParams();
        veri.append('g', GUN); veri.append('t', yazilan);
        fetch(API, { method: 'POST', body: veri, credentials: 'omit' })
            .then(function (y) { return y.json().then(function (j) { return { kod: y.status, j: j }; }); })
            .then(function (c) {
                bekliyor = false;
                if (c.kod === 409) { bildir('Yeni günün kelimesi geldi; sayfa yenileniyor.'); setTimeout(function () { location.replace(location.pathname + '?y=' + Date.now()); }, 1200); return; }
                if (c.kod !== 200) { bildir(c.j.hata || 'Tahmin gönderilemedi.'); return; }
                durum.tahminler.push(yazilan); durum.sonuclar.push(c.j.sonuc); yazilan = '';
                bildir('');
                yaz(DURUM_ANAHTARI, durum);
                if (c.j.dogru) { bitir(true, c.j.cevap, c.j.haber); return; }
                if (durum.tahminler.length >= HAK) {
                    var v2 = new URLSearchParams(); v2.append('g', GUN); v2.append('pes', '1');
                    fetch(API, { method: 'POST', body: v2, credentials: 'omit' }).then(function (y) { return y.json(); })
                        .then(function (j) { bitir(false, j.cevap, j.haber); })
                        .catch(function () { bitir(false, '', null); });
                    return;
                }
                ciz();
            })
            .catch(function () { bekliyor = false; bildir('Bağlantı kurulamadı, tekrar deneyin.'); });
    }

    function tus(t) {
        if (durum.bitti) { return; }
        if (t === 'Enter') { gonder(); return; }
        if (t === 'Backspace') { yazilan = Array.from(yazilan).slice(0, -1).join(''); ciz(); return; }
        var h = buyuk(t);
        if (/^[A-ZÇĞİÖŞÜ]$/.test(h) && Array.from(yazilan).length < UZ) { yazilan += h; bildir(''); ciz(); }
    }

    klavyeKutu.addEventListener('click', function (o) {
        var b = o.target.closest('button');
        if (b) { tus(b.dataset.tus); }
    });
    document.addEventListener('keydown', function (o) {
        if (o.ctrlKey || o.metaKey || o.altKey) { return; }
        if (o.target.closest && o.target.closest('input, textarea, select')) { return; }
        // Odaktaki dugme/bagda Enter kendi tiklamasini yapsin, iki kez gitmesin.
        if (o.key === 'Enter' && o.target.closest && o.target.closest('button, a')) { return; }
        if (o.key === 'Enter' || o.key === 'Backspace' || o.key.length === 1) {
            if (o.key === 'Enter' || o.key === 'Backspace' || /^[a-zA-ZçğıöşüÇĞİÖŞÜ]$/.test(o.key)) { o.preventDefault(); tus(o.key); }
        }
    });

    document.getElementById('kelimePaylas').addEventListener('click', function () {
        var metin = paylasimMetni();
        if (navigator.share) { navigator.share({ text: metin }).catch(function () {}); return; }
        if (navigator.clipboard) { navigator.clipboard.writeText(metin).then(function () { bildir('Sonuç kopyalandı.'); }); }
    });

    ciz();
})();
