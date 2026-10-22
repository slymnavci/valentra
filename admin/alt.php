    </div>

    <?php
    /*
     * Form gonderildikten sonra sayfa kaldigi yerde acilsin (22.10.2026).
     *
     * Paneldeki islemler (test et, onayla, kapat...) ayni sayfaya POST
     * ediyor ve yanit sayfanin basindan aciliyordu; uzun listelerde her
     * islemden sonra yeniden asagi inmek gerekiyordu. Gonderimden hemen
     * once kaydirma konumu saklaniyor, ayni sayfa yeniden acilinca oraya
     * donuluyor. Adresinde #satir olan formlar (kaynaklar) o satira
     * gidiyor; onlara dokunulmuyor.
     */
    ?>
    <script>
    (function () {
        var anahtar = 'panel-konum:' + location.pathname;

        try {
            var kayit = JSON.parse(sessionStorage.getItem(anahtar) || 'null');
            sessionStorage.removeItem(anahtar);

            // Yalnizca az once gonderilen formun yaniti: sonradan baglantiyla
            // gelindiginde sayfa yine bastan acilsin.
            if (kayit && Date.now() - kayit.t < 120000 && !location.hash) {
                window.scrollTo(0, kayit.y || 0);
            }
        } catch (e) {}

        document.addEventListener('submit', function (olay) {
            var form = olay.target;
            var hedef = form.getAttribute('action') || '';

            if (hedef.indexOf('#') !== -1 || (form.method || '').toLowerCase() !== 'post') {
                return;
            }

            try { sessionStorage.setItem(anahtar, JSON.stringify({ y: window.scrollY, t: Date.now() })); } catch (e) {}
        });
    })();
    </script>
</body>
</html>
