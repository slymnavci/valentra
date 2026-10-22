<?php
declare(strict_types=1);

/**
 * Canli bolumleri yenileyen betik (sayfa basina bir kez).
 *
 * data-canli-bolum="/api/..." tasiyan her oge, data-canli-aralik saniyede
 * bir (varsayilan 180) o adresten yeniden cizilmis HTML'i alip kendisini
 * onunla degistiriyor. Sekme arkadayken istek atilmiyor; uc bos donerse
 * (kaynak yanit vermedi) eski icerik kaliyor.
 */

function canli_betik(): string
{
    static $yazildi = false;

    if ($yazildi) {
        return '';
    }

    $yazildi = true;

    return <<<'HTML'
<script>
(function () {
    function bagla(oge) {
        var adres = oge.getAttribute('data-canli-bolum');
        var aralik = parseInt(oge.getAttribute('data-canli-aralik') || '180', 10) * 1000;

        setTimeout(function yenile() {
            if (document.hidden) { setTimeout(yenile, aralik); return; }

            fetch(adres, { cache: 'no-store' })
                .then(function (y) { return y.ok ? y.text() : ''; })
                .then(function (html) {
                    var kap = document.createElement('div');
                    kap.innerHTML = html.trim();
                    var yeni = kap.firstElementChild;

                    if (yeni && yeni.getAttribute('data-canli-bolum') === adres && oge.parentNode) {
                        oge.parentNode.replaceChild(yeni, oge);
                        oge = yeni;
                    }
                })
                .catch(function () {})
                .then(function () { setTimeout(yenile, aralik); });
        }, aralik);
    }

    document.querySelectorAll('[data-canli-bolum]').forEach(bagla);
})();
</script>
HTML;
}
