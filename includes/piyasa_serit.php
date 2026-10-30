<?php
declare(strict_types=1);

/**
 * Piyasa şeridi: dolar, euro, gram altın, BIST 100.
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
if ($piyasa['usd'] === null && $piyasa['eur'] === null && $piyasa['bist'] === null
    && ($piyasa['altin'] ?? null) === null) {
    return;
}

/** 41,2345 -> "41,2345" (TL kuru dort hane, endeks iki hane) */
$bicimle = static fn (?float $s, int $hane): string =>
    $s === null ? '—' : number_format($s, $hane, ',', '.');

/**
 * Yon oku ve yuzde.
 *
 * Uc kalem de ayni gosterimi kullansin diye tek yere alindi; onceden
 * yalnizca BIST'te vardi ve iki yerde kopyalanmis olurdu.
 *
 * null, "degismedi" degil "bilmiyoruz" demek — ilk gun referans
 * olusmadan degisim hesaplanamaz — ve o durumda hicbir sey
 * basilmiyor. Sifiri yukari okla gostermek yanlis bilgi olurdu.
 */
$yon = static function (?float $degisim, string $ad) : string {
    if ($degisim === null) {
        return '<span class="degisim bos" data-piyasa="' . $ad . '"></span>';
    }

    $sinif = $degisim > 0 ? 'arti' : ($degisim < 0 ? 'eksi' : 'duraan');
    $ok    = $degisim > 0 ? '▲' : ($degisim < 0 ? '▼' : '■');

    return '<span class="degisim ' . $sinif . '" data-piyasa="' . $ad . '">'
        . $ok . ' %' . number_format(abs($degisim), 2, ',', '.')
        . '</span>';
};

/*
 * Kalemin simgesi: yuvarlak bayrak ya da isaret (site sahibinin istegi,
 * 30.10.2026: "USD ve EUR artisi belli olmuyor; adi kirmizi yaziyor, sanki
 * dusus var gibi gorunuyor; bayrakla gosterelim", BigPara gibi). Ad artik
 * notr renkte; yukselis ve dusus yalnizca degisimin renginde. Emoji bayrak
 * kullanilmadi: Windows'ta harf olarak gorunuyor.
 */
$simge = static fn (string $svg): string => '<span class="piyasa-simge">' . $svg . '</span>';
$simgeler = [
    'usd'   => $simge('<svg viewBox="0 0 26 20" aria-hidden="true"><rect width="26" height="20" fill="#fff"/><rect y="0.0" width="26" height="1.54" fill="#b22234"/><rect y="3.08" width="26" height="1.54" fill="#b22234"/><rect y="6.15" width="26" height="1.54" fill="#b22234"/><rect y="9.23" width="26" height="1.54" fill="#b22234"/><rect y="12.31" width="26" height="1.54" fill="#b22234"/><rect y="15.38" width="26" height="1.54" fill="#b22234"/><rect y="18.46" width="26" height="1.54" fill="#b22234"/><rect width="12" height="10.8" fill="#3c3b6e"/></svg>'),
    'eur'   => $simge('<svg viewBox="0 0 20 20" aria-hidden="true"><rect width="20" height="20" fill="#003399"/><circle cx="16.00" cy="10.00" r="1.05" fill="#ffcc00"/><circle cx="15.20" cy="13.00" r="1.05" fill="#ffcc00"/><circle cx="13.00" cy="15.20" r="1.05" fill="#ffcc00"/><circle cx="10.00" cy="16.00" r="1.05" fill="#ffcc00"/><circle cx="7.00" cy="15.20" r="1.05" fill="#ffcc00"/><circle cx="4.80" cy="13.00" r="1.05" fill="#ffcc00"/><circle cx="4.00" cy="10.00" r="1.05" fill="#ffcc00"/><circle cx="4.80" cy="7.00" r="1.05" fill="#ffcc00"/><circle cx="7.00" cy="4.80" r="1.05" fill="#ffcc00"/><circle cx="10.00" cy="4.00" r="1.05" fill="#ffcc00"/><circle cx="13.00" cy="4.80" r="1.05" fill="#ffcc00"/><circle cx="15.20" cy="7.00" r="1.05" fill="#ffcc00"/></svg>'),
    'altin' => $simge('<svg viewBox="0 0 20 20" aria-hidden="true"><rect width="20" height="20" fill="#e2b33c"/><path d="M4.5 13.5h11l-2-5h-7z" fill="#f6d77a" stroke="#a87a12" stroke-width=".9" stroke-linejoin="round"/><path d="M7.3 8.5l.8-2h3.8l.8 2" fill="none" stroke="#a87a12" stroke-width=".9" stroke-linejoin="round"/></svg>'),
    'bist'  => $simge('<svg viewBox="0 0 20 20" aria-hidden="true"><rect width="20" height="20" fill="#1f2a44"/><path d="M4 13.5l3.5-3.5 2.5 2.5 5-5.5" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'),
];
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
                    <?= $simgeler['usd'] ?>
                    <span class="ad"><span class="uzun">Dolar</span><span class="kisa">USD</span></span>
                    <span class="deger" data-piyasa="usd"><?= e($bicimle($piyasa['usd'], 4)) ?></span>
                    <?= $yon($piyasa['usd_degisim'] ?? null, 'usd_degisim') ?>
                </span>
            <?php endif; ?>

            <?php if ($piyasa['eur'] !== null): ?>
                <span class="piyasa-oge">
                    <?= $simgeler['eur'] ?>
                    <span class="ad"><span class="uzun">Euro</span><span class="kisa">EUR</span></span>
                    <span class="deger" data-piyasa="eur"><?= e($bicimle($piyasa['eur'], 4)) ?></span>
                    <?= $yon($piyasa['eur_degisim'] ?? null, 'eur_degisim') ?>
                </span>
            <?php endif; ?>

            <?php if (($piyasa['altin'] ?? null) !== null): ?>
                <span class="piyasa-oge">
                    <?= $simgeler['altin'] ?>
                    <span class="ad"><span class="uzun">Gram Altın</span><span class="kisa">Altın</span></span>
                    <span class="deger" data-piyasa="altin"><?= e($bicimle($piyasa['altin'], 2)) ?></span>
                    <?= $yon($piyasa['altin_degisim'] ?? null, 'altin_degisim') ?>
                </span>
            <?php endif; ?>

            <?php // Ons altin ve guncelleme saati seritten cikti (24.10.2026, site sahibi). ?>
            <?php if ($piyasa['bist'] !== null): ?>
                <span class="piyasa-oge">
                    <?= $simgeler['bist'] ?>
                    <span class="ad"><span class="uzun">BIST 100</span><span class="kisa">BIST</span></span>
                    <span class="deger" data-piyasa="bist"><?= e($bicimle($piyasa['bist'], 2)) ?></span>
                    <?= $yon($piyasa['bist_degisim'] ?? null, 'bist_degisim') ?>
                </span>
            <?php endif; ?>
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
                if (v.altin !== null && v.altin !== undefined) { yaz('altin', bicimle(v.altin, 2)); }

                // Butun kalemler ayni sekilde tazeleniyor; PHP tarafindaki
                // $yon islevinin JavaScript karsiligi.
                ['usd_degisim', 'eur_degisim', 'altin_degisim', 'bist_degisim'].forEach(function (ad) {
                    var d = v[ad];

                    if (d === null || d === undefined) { return; }

                    var sinif = d > 0 ? 'arti' : (d < 0 ? 'eksi' : 'duraan');
                    var ok    = d > 0 ? '▲' : (d < 0 ? '▼' : '■');

                    yaz(ad, ok + ' %' + bicimle(Math.abs(d), 2));

                    serit.querySelectorAll('[data-piyasa="' + ad + '"]').forEach(function (e) {
                        e.className = 'degisim ' + sinif;
                    });
                });

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
