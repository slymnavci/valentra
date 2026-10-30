<?php
declare(strict_types=1);

/**
 * Piyasa şeridi: dolar, euro, gram ve ons altın, BIST 100, ABD borsaları
 * (S&P 500, Nasdaq, Dow Jones) ve Brent petrol.
 *
 * Ilk deger sunucu tarafinda basiliyor; JavaScript kapali olsa da serit
 * dolu geliyor. Sonrasini 2 dakikada bir tarayici tazeliyor.
 *
 * Kayma CSS animasyonuyla: icerik seride sigmadiginda akiyor (uzerine
 * gelince duruyor), sigdiginda duruyor. Dokuz kalem genis ekranda bile
 * cogu zaman sigmiyor; sigip sigmadigini asagidaki betik olcuyor.
 */

require_once __DIR__ . '/piyasa.php';

$piyasa = piyasa_verisi();

// Hicbir deger yoksa serit hic cizilmesin; bos bir cubuk kotu durur.
/*
 * Kalemler, sirasiyla: [anahtar, uzun ad, kisa ad, hane, simge].
 *
 * Ons altin geri geldi, ABD borsalari ve Brent eklendi (site sahibi,
 * 30.10.2026: "icinde altin ons fiyati, abd borsa bilgileri de olsun,
 * petrol fiyatini yazalim"). Ons ve ABD endeksleri dolar cinsinden.
 */
$kalemler = [
    ['usd',    'Dolar',        'USD',     4, 'usd'],
    ['eur',    'Euro',         'EUR',     4, 'eur'],
    ['altin',  'Gram Altın',   'Gram',    2, 'altin'],
    ['ons',    'Ons Altın',    'Ons',     2, 'ons'],
    ['bist',   'BIST 100',     'BIST',    2, 'bist'],
    ['sp500',  'S&P 500',      'S&P',     2, 'abd'],
    ['nasdaq', 'Nasdaq',       'Nasdaq',  2, 'abd'],
    ['dow',    'Dow Jones',    'Dow',     2, 'abd'],
    ['brent',  'Brent Petrol', 'Brent',   2, 'petrol'],
];

$kalemler = array_values(array_filter(
    $kalemler,
    static fn (array $k): bool => ($piyasa[$k[0]] ?? null) !== null
));

// Hicbir deger yoksa serit hic cizilmesin; bos bir cubuk kotu durur.
if ($kalemler === []) {
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
    'ons'   => $simge('<svg viewBox="0 0 20 20" aria-hidden="true"><rect width="20" height="20" fill="#a87a12"/><path d="M4.5 13.5h11l-2-5h-7z" fill="#e2b33c" stroke="#5e430a" stroke-width=".9" stroke-linejoin="round"/><path d="M7.3 8.5l.8-2h3.8l.8 2" fill="none" stroke="#5e430a" stroke-width=".9" stroke-linejoin="round"/></svg>'),
    'bist'  => $simge('<svg viewBox="0 0 20 20" aria-hidden="true"><rect width="20" height="20" fill="#1f2a44"/><path d="M4 13.5l3.5-3.5 2.5 2.5 5-5.5" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'),
    // ABD endeksleri: BIST'in grafik simgesi, ABD bayraginin renklerinde.
    'abd'   => $simge('<svg viewBox="0 0 20 20" aria-hidden="true"><rect width="20" height="20" fill="#3c3b6e"/><path d="M4 13.5l3.5-3.5 2.5 2.5 5-5.5" fill="none" stroke="#ff6b70" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'),
    'petrol'=> $simge('<svg viewBox="0 0 20 20" aria-hidden="true"><rect width="20" height="20" fill="#2b2b30"/><path d="M10 4.2c2.2 3 3.6 5 3.6 6.9a3.6 3.6 0 0 1-7.2 0c0-1.9 1.4-3.9 3.6-6.9z" fill="#f2f2f4"/></svg>'),
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
            <?php foreach ($kalemler as [$anahtar, $uzun, $kisa, $hane, $simgeAdi]): ?>
                <span class="piyasa-oge">
                    <?= $simgeler[$simgeAdi] ?>
                    <span class="ad"><span class="uzun"><?= e($uzun) ?></span><span class="kisa"><?= e($kisa) ?></span></span>
                    <span class="deger" data-piyasa="<?= e($anahtar) ?>" data-hane="<?= $hane ?>"><?= e($bicimle($piyasa[$anahtar], $hane)) ?></span>
                    <?= $yon($piyasa[$anahtar . '_degisim'] ?? null, $anahtar . '_degisim') ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

<script>
(function () {
    var serit = document.getElementById('piyasaSerit');

    if (!serit) { return; }

    var akis = serit.querySelector('.piyasa-akis');
    var grup = serit.querySelector('.piyasa-grup');

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

    /*
     * Sigmiyorsa kaydir. Hiz sabit (saniyede ~50 piksel): kalem sayisi
     * ya da ekran genisligi degisse de okuma hizi ayni kaliyor.
     */
    function olc() {
        serit.classList.remove('kayan');

        var sigmiyor = grup.scrollWidth > serit.clientWidth + 1;

        serit.classList.toggle('kayan', sigmiyor);

        if (sigmiyor) {
            akis.style.animationDuration = Math.max(20, Math.round(grup.scrollWidth / 50)) + 's';
        }
    }

    olc();

    if (window.ResizeObserver) {
        new ResizeObserver(olc).observe(serit);
    } else {
        window.addEventListener('resize', olc);
    }

    function tazele() {
        fetch('/api/piyasa.php', { cache: 'no-store' })
            .then(function (y) { return y.ok ? y.json() : null; })
            .then(function (v) {
                if (!v) { return; }

                // Sunucunun bastigi kalemler tazeleniyor; hane sayisi isaretin uzerinde.
                serit.querySelectorAll('.piyasa-grup:first-child .deger[data-piyasa]').forEach(function (e) {
                    var ad = e.getAttribute('data-piyasa');
                    var d  = v[ad + '_degisim'];

                    if (v[ad] !== null && v[ad] !== undefined) {
                        yaz(ad, bicimle(v[ad], parseInt(e.getAttribute('data-hane'), 10) || 2));
                    }

                    // PHP tarafindaki $yon islevinin JavaScript karsiligi.
                    if (d === null || d === undefined) { return; }

                    var sinif = d > 0 ? 'arti' : (d < 0 ? 'eksi' : 'duraan');
                    var ok    = d > 0 ? '▲' : (d < 0 ? '▼' : '■');

                    yaz(ad + '_degisim', ok + ' %' + bicimle(Math.abs(d), 2));

                    serit.querySelectorAll('[data-piyasa="' + ad + '_degisim"]').forEach(function (e) {
                        e.className = 'degisim ' + sinif;
                    });
                });

                olc();
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
