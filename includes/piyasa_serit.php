<?php
declare(strict_types=1);

/**
 * Piyasa şeridi: dolar, euro, BIST 100.
 *
 * Ilk deger sunucu tarafinda basiliyor; JavaScript kapali olsa da serit
 * dolu geliyor. Sonrasini 2 dakikada bir tarayici tazeliyor.
 *
 * Kayma CSS animasyonuyla: dar ekranda icerik sigmadiginda kendiliginden
 * akiyor, genis ekranda durup duruyor.
 */

require_once __DIR__ . '/piyasa.php';

$piyasa = piyasa_verisi();

// Hicbir deger yoksa serit hic cizilmesin; bos bir cubuk kotu durur.
if ($piyasa['usd'] === null && $piyasa['eur'] === null && $piyasa['bist'] === null) {
    return;
}

/** 41,2345 -> "41,2345" (TL kuru dort hane, endeks iki hane) */
$bicimle = static fn (?float $s, int $hane): string =>
    $s === null ? '—' : number_format($s, $hane, ',', '.');
?>
<div class="piyasa-serit" id="piyasaSerit" aria-label="Piyasa özeti">
    <div class="piyasa-akis">
        <?php
        /*
         * Grup iki kez basiliyor.
         *
         * Kesintisiz kayma icin gerekli: birinci kopya soldan cikarken
         * ikincisi sagdan giriyor, animasyon basa dondugunde goruntu
         * ayni oluyor ve zipla olmuyor. Ikinci kopya ekran okuyuculara
         * gizli, yoksa degerler iki kez okunurdu.
         */
        for ($kopya = 0; $kopya < 2; $kopya++):
        ?>
        <div class="piyasa-grup"<?= $kopya === 1 ? ' aria-hidden="true"' : '' ?>>
            <?php if ($piyasa['usd'] !== null): ?>
                <span class="piyasa-oge">
                    <span class="ad">DOLAR</span>
                    <span class="deger" data-piyasa="usd"><?= e($bicimle($piyasa['usd'], 4)) ?></span>
                </span>
            <?php endif; ?>

            <?php if ($piyasa['eur'] !== null): ?>
                <span class="piyasa-oge">
                    <span class="ad">EURO</span>
                    <span class="deger" data-piyasa="eur"><?= e($bicimle($piyasa['eur'], 4)) ?></span>
                </span>
            <?php endif; ?>

            <?php if ($piyasa['bist'] !== null): ?>
                <span class="piyasa-oge">
                    <span class="ad">BIST 100</span>
                    <span class="deger" data-piyasa="bist"><?= e($bicimle($piyasa['bist'], 2)) ?></span>
                    <?php if ($piyasa['bist_degisim'] !== null): ?>
                        <span class="degisim <?= $piyasa['bist_degisim'] >= 0 ? 'arti' : 'eksi' ?>"
                              data-piyasa="bist_degisim">
                            <?= $piyasa['bist_degisim'] >= 0 ? '▲' : '▼' ?>
                            %<?= e(number_format(abs($piyasa['bist_degisim']), 2, ',', '.')) ?>
                        </span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>

            <span class="piyasa-oge piyasa-zaman">
                <span class="ad">GÜNCELLEME</span>
                <span class="deger" data-piyasa="zaman"><?= e(date('H:i', strtotime($piyasa['zaman']))) ?></span>
            </span>
        </div>
        <?php endfor; ?>
    </div>
</div>

<script>
(function () {
    var serit = document.getElementById('piyasaSerit');

    if (!serit) { return; }

    function bicimle(sayi, hane) {
        return sayi === null || sayi === undefined
            ? '—'
            : sayi.toLocaleString('tr-TR', {
                minimumFractionDigits: hane,
                maximumFractionDigits: hane
              });
    }

    function yaz(ad, metin) {
        serit.querySelectorAll('[data-piyasa="' + ad + '"]').forEach(function (e) {
            e.textContent = metin;
        });
    }

    function tazele() {
        fetch('/api/piyasa.php', { cache: 'no-store' })
            .then(function (y) { return y.ok ? y.json() : null; })
            .then(function (v) {
                if (!v) { return; }

                if (v.usd !== null)  { yaz('usd', bicimle(v.usd, 4)); }
                if (v.eur !== null)  { yaz('eur', bicimle(v.eur, 4)); }
                if (v.bist !== null) { yaz('bist', bicimle(v.bist, 2)); }

                if (v.bist_degisim !== null && v.bist_degisim !== undefined) {
                    var ok = v.bist_degisim >= 0 ? '▲' : '▼';
                    yaz('bist_degisim', ok + ' %' + bicimle(Math.abs(v.bist_degisim), 2));

                    serit.querySelectorAll('[data-piyasa="bist_degisim"]').forEach(function (e) {
                        e.className = 'degisim ' + (v.bist_degisim >= 0 ? 'arti' : 'eksi');
                    });
                }

                if (v.zaman) { yaz('zaman', v.zaman.substring(11, 16)); }
            })
            .catch(function () { /* Gecici hata seridi bozmasin, eski deger kalsin. */ });
    }

    setInterval(tazele, 120000);

    // Sekme arkaya atilip geri gelince eski deger gostermeyelim.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { tazele(); }
    });
})();
</script>
