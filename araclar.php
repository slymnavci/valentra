<?php
declare(strict_types=1);

/**
 * Hesaplama araçları: KDV dahil/hariç, vade farkı, başabaş noktası.
 *
 * Hesap tamamen tarayicida yapiliyor; sunucuya hicbir tutar gitmiyor ve
 * kaydedilmiyor. Okuyucu kendi rakamlarini girerken bunu bilmeli, o
 * yuzden sayfada acikca yaziyor.
 *
 * Oranlar sabit degil, secilebilir: KDV oranlari degisebiliyor ve eski
 * donem faturasi icin eski oranla hesap gerekebiliyor. Her aracta
 * "Diger" ile serbest oran girilebiliyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/rehberler.php';
require_once __DIR__ . '/includes/seo.php';

$aktifKategori = 'araclar';
$sayfaBasligi  = 'Hesaplama Araçları — Valentra';
$sayfaAciklama = 'KDV dahil/hariç hesaplama, vade farkı ve başabaş noktası hesaplayıcıları.';
$seoAdres      = site_adresi() . araclar_yolu();

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Hesaplama Araçları</h1>
    <p>
        Günlük işte en sık yapılan hesaplar. Hesap tarayıcınızda yapılır;
        girdiğiniz rakamlar hiçbir yere gönderilmez ve kaydedilmez.
    </p>
</div>

<nav class="arac-kisayol" aria-label="Araçlar">
    <a href="#kdv">KDV dahil / hariç</a>
    <a href="#vade-farki">Vade farkı</a>
    <a href="#basabas">Başabaş noktası</a>
</nav>

<div class="arac-izgara">

    <?php /* ---------------------------------------------------------------- KDV */ ?>
    <section class="arac-kart" id="kdv" aria-labelledby="kdv-baslik">
        <h2 id="kdv-baslik">KDV dahil / hariç</h2>
        <p class="arac-aciklama">KDV hariç tutardan toplamı ya da KDV dahil tutardan matrahı bulun.</p>

        <form class="arac-form" data-arac="kdv" novalidate>
            <div class="arac-secim" role="radiogroup" aria-label="Hesap yönü">
                <label><input type="radio" name="yon" value="haric" checked> KDV hariç tutar giriyorum</label>
                <label><input type="radio" name="yon" value="dahil"> KDV dahil tutar giriyorum</label>
            </div>

            <label class="arac-alan">
                <span>Tutar (TL)</span>
                <input type="text" name="tutar" inputmode="decimal" placeholder="Örn. 100.000" autocomplete="off">
            </label>

            <label class="arac-alan">
                <span>KDV oranı</span>
                <select name="oran">
                    <option value="20" selected>%20</option>
                    <option value="10">%10</option>
                    <option value="1">%1</option>
                    <option value="diger">Diğer…</option>
                </select>
            </label>

            <label class="arac-alan arac-diger" hidden>
                <span>Oran (%)</span>
                <input type="text" name="oran_diger" inputmode="decimal" placeholder="Örn. 18">
            </label>
        </form>

        <dl class="arac-sonuc" data-sonuc="kdv" aria-live="polite">
            <div><dt>Matrah (KDV hariç)</dt><dd data-alan="matrah">—</dd></div>
            <div><dt>KDV</dt><dd data-alan="kdv">—</dd></div>
            <div class="arac-toplam"><dt>Toplam (KDV dahil)</dt><dd data-alan="toplam">—</dd></div>
        </dl>
    </section>

    <?php /* --------------------------------------------------------- Vade farki */ ?>
    <section class="arac-kart" id="vade-farki" aria-labelledby="vade-baslik">
        <h2 id="vade-baslik">Vade farkı</h2>
        <p class="arac-aciklama">
            Vadeli satışta ya da geç ödemede, yıllık orana göre basit faizle vade farkını hesaplayın.
        </p>
        <?php if (($rehber = rehber_araca_gore('vade-farki')) !== null): ?>
            <p class="arac-rehber">
                <a href="<?= e(rehber_yolu((string) $rehber['slug'])) ?>">Formül, örnek ve muhasebe kaydı: rehberi okuyun &rarr;</a>
            </p>
        <?php endif; ?>

        <form class="arac-form" data-arac="vade" novalidate>
            <label class="arac-alan">
                <span>Anapara (TL)</span>
                <input type="text" name="anapara" inputmode="decimal" placeholder="Örn. 250.000" autocomplete="off">
            </label>

            <label class="arac-alan">
                <span>Yıllık oran (%)</span>
                <input type="text" name="oran" inputmode="decimal" placeholder="Örn. 45" autocomplete="off">
            </label>

            <label class="arac-alan">
                <span>Vade (gün)</span>
                <input type="text" name="gun" inputmode="numeric" placeholder="Örn. 90" autocomplete="off">
            </label>

            <label class="arac-alan">
                <span>Vade farkına KDV</span>
                <select name="kdv">
                    <option value="0">Hesaplama</option>
                    <option value="20" selected>%20</option>
                    <option value="10">%10</option>
                    <option value="1">%1</option>
                </select>
            </label>
        </form>

        <dl class="arac-sonuc" data-sonuc="vade" aria-live="polite">
            <div><dt>Vade farkı</dt><dd data-alan="fark">—</dd></div>
            <div><dt>Vade farkının KDV'si</dt><dd data-alan="kdv">—</dd></div>
            <div class="arac-toplam"><dt>Vade sonunda toplam</dt><dd data-alan="toplam">—</dd></div>
        </dl>

        <p class="arac-not">
            Formül: anapara × yıllık oran × gün ÷ 365 (basit faiz). Vade farkı, ilişkili
            olduğu teslimin KDV oranına tabidir; sözleşmenizdeki hesap yöntemi farklıysa
            (bileşik faiz, aylık oran) sonuç değişir.
        </p>
    </section>

    <?php /* --------------------------------------------------------- Basabas */ ?>
    <section class="arac-kart" id="basabas" aria-labelledby="basabas-baslik">
        <h2 id="basabas-baslik">Başabaş noktası</h2>
        <p class="arac-aciklama">
            Sabit giderlerinizi karşılamak için kaç adet ya da ne kadarlık satış gerektiğini bulun.
        </p>
        <?php if (($rehber = rehber_araca_gore('basabas')) !== null): ?>
            <p class="arac-rehber">
                <a href="<?= e(rehber_yolu((string) $rehber['slug'])) ?>">Formül, örnek ve muhasebe kaydı: rehberi okuyun &rarr;</a>
            </p>
        <?php endif; ?>

        <form class="arac-form" data-arac="basabas" novalidate>
            <label class="arac-alan">
                <span>Dönemlik sabit giderler (TL)</span>
                <input type="text" name="sabit" inputmode="decimal" placeholder="Örn. 400.000" autocomplete="off">
            </label>

            <label class="arac-alan">
                <span>Birim satış fiyatı (TL)</span>
                <input type="text" name="fiyat" inputmode="decimal" placeholder="Örn. 1.250" autocomplete="off">
            </label>

            <label class="arac-alan">
                <span>Birim değişken maliyet (TL)</span>
                <input type="text" name="degisken" inputmode="decimal" placeholder="Örn. 850" autocomplete="off">
            </label>

            <label class="arac-alan">
                <span>Hedef kâr (TL, isteğe bağlı)</span>
                <input type="text" name="hedef" inputmode="decimal" placeholder="Örn. 100.000" autocomplete="off">
            </label>
        </form>

        <dl class="arac-sonuc" data-sonuc="basabas" aria-live="polite">
            <div><dt>Birim katkı payı</dt><dd data-alan="katki">—</dd></div>
            <div><dt>Katkı payı oranı</dt><dd data-alan="oran">—</dd></div>
            <div class="arac-toplam"><dt>Başabaş satış adedi</dt><dd data-alan="adet">—</dd></div>
            <div><dt>Başabaş ciro</dt><dd data-alan="ciro">—</dd></div>
            <div><dt>Hedef kâr için gereken adet</dt><dd data-alan="hedef_adet">—</dd></div>
        </dl>

        <p class="arac-not">
            Katkı payı = satış fiyatı − değişken maliyet. Başabaş adedi = sabit giderler ÷
            katkı payı (yukarı yuvarlanır). Tutarları KDV hariç girin.
        </p>
    </section>
</div>

<p class="ipucu" style="margin:6px 0 34px;">
    Sonuçlar bilgi amaçlıdır. Resmî bir işlemde kullanmadan önce ilgili mevzuatı ve
    sözleşme koşullarını kontrol edin.
</p>

<script>
(function () {
    var tl = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var adetBicim = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 });
    var yuzde = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 2 });

    /*
     * Turkce sayi yazimi: "1.250.000,50". Nokta binlik ayraci, virgul
     * ondalik. Yalnizca nokta varsa ve son grubu uc haneli degilse
     * ("12.5") ondalik sayilir; "1.250" bin iki yuz elli.
     */
    function sayi(metin) {
        var s = String(metin || '').replace(/\s|TL|%/gi, '');
        if (s === '') { return NaN; }
        if (s.indexOf(',') !== -1) {
            s = s.replace(/\./g, '').replace(',', '.');
        } else if (/^\d{1,3}(\.\d{3})+$/.test(s)) {
            s = s.replace(/\./g, '');
        }
        return /^-?\d+(\.\d+)?$/.test(s) ? parseFloat(s) : NaN;
    }

    function yaz(kart, alan, deger) {
        var dd = kart.querySelector('[data-alan="' + alan + '"]');
        if (dd) { dd.textContent = deger; }
    }

    function temizle(kart) {
        kart.querySelectorAll('[data-alan]').forEach(function (dd) { dd.textContent = '—'; });
    }

    var hesaplar = {
        kdv: function (f, s) {
            var diger = f.querySelector('.arac-diger');
            diger.hidden = f.oran.value !== 'diger';
            var oran = f.oran.value === 'diger' ? sayi(f.oran_diger.value) : parseFloat(f.oran.value);
            var tutar = sayi(f.tutar.value);
            if (!(tutar >= 0) || !(oran >= 0)) { return temizle(s); }
            var matrah, kdv;
            if (f.yon.value === 'dahil') {
                matrah = tutar / (1 + oran / 100);
                kdv = tutar - matrah;
            } else {
                matrah = tutar;
                kdv = tutar * oran / 100;
            }
            yaz(s, 'matrah', tl.format(matrah) + ' TL');
            yaz(s, 'kdv', tl.format(kdv) + ' TL');
            yaz(s, 'toplam', tl.format(matrah + kdv) + ' TL');
        },

        vade: function (f, s) {
            var anapara = sayi(f.anapara.value), oran = sayi(f.oran.value), gun = sayi(f.gun.value);
            if (!(anapara >= 0) || !(oran >= 0) || !(gun >= 0)) { return temizle(s); }
            var fark = anapara * oran / 100 * gun / 365;
            var kdv = fark * parseFloat(f.kdv.value) / 100;
            yaz(s, 'fark', tl.format(fark) + ' TL');
            yaz(s, 'kdv', parseFloat(f.kdv.value) > 0 ? tl.format(kdv) + ' TL' : '—');
            yaz(s, 'toplam', tl.format(anapara + fark + kdv) + ' TL');
        },

        basabas: function (f, s) {
            var sabit = sayi(f.sabit.value), fiyat = sayi(f.fiyat.value), degisken = sayi(f.degisken.value);
            var hedef = sayi(f.hedef.value);
            if (!(sabit >= 0) || !(fiyat > 0) || !(degisken >= 0)) { return temizle(s); }
            var katki = fiyat - degisken;
            yaz(s, 'katki', tl.format(katki) + ' TL');
            yaz(s, 'oran', '%' + yuzde.format(katki / fiyat * 100));
            if (katki <= 0) {
                yaz(s, 'adet', 'Satış fiyatı değişken maliyetin altında: başabaş yok');
                yaz(s, 'ciro', '—');
                yaz(s, 'hedef_adet', '—');
                return;
            }
            var adet = Math.ceil(sabit / katki);
            yaz(s, 'adet', adetBicim.format(adet) + ' adet');
            yaz(s, 'ciro', tl.format(adet * fiyat) + ' TL');
            yaz(s, 'hedef_adet', hedef >= 0 ? adetBicim.format(Math.ceil((sabit + hedef) / katki)) + ' adet' : '—');
        }
    };

    document.querySelectorAll('form.arac-form').forEach(function (f) {
        var tur = f.getAttribute('data-arac');
        var s = document.querySelector('[data-sonuc="' + tur + '"]');
        var calis = function () { hesaplar[tur](f, s); };
        f.addEventListener('input', calis);
        f.addEventListener('change', calis);
        f.addEventListener('submit', function (e) { e.preventDefault(); calis(); });
        calis();
    });
})();
</script>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
