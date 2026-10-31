/*
 * V Network: fotograf secme ve kucultme, video baglantisi alani.
 *
 * Sitede dosya yuklemeli (multipart) istekler kapali (kokteki .htaccess);
 * fotograf burada en uzun kenari 1600 piksele (profil resmi 800) kucultulup
 * JPEG data: adresi olarak gizli bir form alanina yaziliyor ve form siradan
 * bicimde gidiyor. Sunucu yine de sinayip yeniden kodluyor
 * (includes/network_foto.php). Sinirlar formdaki data- niteliklerinden.
 */
(function () {
    'use strict';

    function kucult(dosya, kenar) {
        return new Promise(function (coz, reddet) {
            var okuyucu = new FileReader();
            okuyucu.onerror = function () { reddet(new Error('Fotoğraf okunamadı.')); };
            okuyucu.onload = function () {
                var resim = new Image();
                resim.onerror = function () { reddet(new Error('Bu dosya geçerli bir fotoğraf değil.')); };
                resim.onload = function () {
                    var oran = Math.min(1, kenar / Math.max(resim.width, resim.height));
                    var g = Math.max(1, Math.round(resim.width * oran));
                    var y = Math.max(1, Math.round(resim.height * oran));
                    var tuval = document.createElement('canvas');
                    tuval.width = g;
                    tuval.height = y;
                    var c = tuval.getContext('2d');
                    c.fillStyle = '#fff';
                    c.fillRect(0, 0, g, y);
                    c.drawImage(resim, 0, 0, g, y);
                    coz(tuval.toDataURL('image/jpeg', 0.85));
                };
                resim.src = okuyucu.result;
            };
            okuyucu.readAsDataURL(dosya);
        });
    }

    function hataGoster(form, metin) {
        var p = form.querySelector('.nw-paylas-hata');
        if (!p) { alert(metin); return; }
        p.textContent = metin;
        p.hidden = !metin;
    }

    /* --- Paylasim kutusu --- */
    var paylas = document.getElementById('nw-paylas');

    if (paylas) {
        var secici = paylas.querySelector('.nw-foto-sec');
        var gizli = paylas.querySelector('input[name="fotolar"]');
        var onizleme = paylas.querySelector('.nw-paylas-onizleme');
        var enFazla = parseInt(secici.getAttribute('data-en-fazla'), 10) || 4;
        var mb = parseInt(secici.getAttribute('data-mb'), 10) || 8;
        var fotolar = [];

        var ciz = function () {
            onizleme.innerHTML = '';
            fotolar.forEach(function (veri, i) {
                var kutu = document.createElement('span');
                kutu.className = 'nw-onizleme-oge';
                var img = document.createElement('img');
                img.src = veri;
                img.alt = '';
                var sil = document.createElement('button');
                sil.type = 'button';
                sil.textContent = '×';
                sil.setAttribute('aria-label', 'Fotoğrafı çıkar');
                sil.addEventListener('click', function () { fotolar.splice(i, 1); ciz(); });
                kutu.appendChild(img);
                kutu.appendChild(sil);
                onizleme.appendChild(kutu);
            });
            onizleme.hidden = fotolar.length === 0;
            gizli.value = fotolar.length ? JSON.stringify(fotolar) : '';
        };

        secici.addEventListener('change', function () {
            hataGoster(paylas, '');
            var secilen = Array.prototype.slice.call(secici.files || []);
            secici.value = '';

            if (fotolar.length + secilen.length > enFazla) {
                hataGoster(paylas, 'Bir paylaşıma en fazla ' + enFazla + ' fotoğraf ekleyebilirsiniz.');
                secilen = secilen.slice(0, Math.max(0, enFazla - fotolar.length));
            }

            var buyuk = secilen.filter(function (d) { return d.size > mb * 1024 * 1024; });
            if (buyuk.length) {
                hataGoster(paylas, 'Fotoğraf en fazla ' + mb + ' MB olabilir.');
                secilen = secilen.filter(function (d) { return d.size <= mb * 1024 * 1024; });
            }

            Promise.all(secilen.map(function (d) { return kucult(d, 1600); }))
                .then(function (liste) { fotolar = fotolar.concat(liste).slice(0, enFazla); ciz(); })
                .catch(function (h) { hataGoster(paylas, h.message); });
        });

        var videoAc = paylas.querySelector('.nw-video-ac');
        var videoAlan = paylas.querySelector('.nw-paylas-video');
        videoAc.addEventListener('click', function () {
            videoAlan.hidden = !videoAlan.hidden;
            if (!videoAlan.hidden) { videoAlan.querySelector('input').focus(); }
        });

        paylas.addEventListener('submit', function (olay) {
            var metin = paylas.querySelector('textarea').value.trim();
            var video = videoAlan.querySelector('input').value.trim();
            if (!metin && !video && !fotolar.length) {
                olay.preventDefault();
                hataGoster(paylas, 'Bir şeyler yazın, fotoğraf ya da video bağlantısı ekleyin.');
                return;
            }
            paylas.querySelector('button[type="submit"]').disabled = true;
        });
    }

    /* --- Profil resmi --- */
    var profil = document.getElementById('nw-profil-form');

    if (profil) {
        var pSecici = profil.querySelector('.nw-profil-foto-sec');
        var pGizli = profil.querySelector('input[name="foto"]');
        var pOnizleme = profil.querySelector('.nw-profil-foto-onizleme');
        var pMb = parseInt(pSecici.getAttribute('data-mb'), 10) || 8;

        pSecici.addEventListener('change', function () {
            hataGoster(profil, '');
            var d = pSecici.files && pSecici.files[0];
            if (!d) { return; }
            if (d.size > pMb * 1024 * 1024) {
                hataGoster(profil, 'Fotoğraf en fazla ' + pMb + ' MB olabilir.');
                return;
            }
            kucult(d, 800).then(function (veri) {
                pGizli.value = veri;
                pOnizleme.innerHTML = '';
                var img = document.createElement('img');
                img.src = veri;
                img.alt = '';
                img.className = 'uye-simge';
                img.style.cssText = 'width:96px;height:96px;object-fit:cover';
                pOnizleme.appendChild(img);
            }).catch(function (h) { hataGoster(profil, h.message); });
        });
    }
})();
