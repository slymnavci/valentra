<?php
declare(strict_types=1);

/**
 * Büyük maç canlı skoru: Galatasaray, Fenerbahçe ve Beşiktaş.
 *
 * Site sahibinin istegi (08.11.2026): "ESPN'den alsin ve skoru versin;
 * gol olunca hemen gostersin." Uc buyuk kulubun maci oynanirken her
 * sayfanin ustunde skor bandi (includes/sayfa_ust.php); bant skoru
 * api/canli-skor.php'den 20 saniyede bir soruyor, gol olunca yanip
 * soner. Mac goruntusu YOK: yayin haklari yayincida.
 *
 * ESPN'e yuk:
 *   - Gunun fiksturu (ligler x bugun) 30 dakikada bir okunuyor; maci
 *     olmayan gunlerde baska istek yok.
 *   - Uc buyuklerden birinin maci baslamak uzereyken ya da oynanirken o
 *     ligin o gunku cevabi en fazla BUYUK_MAC_TAZE saniyede bir
 *     tazeleniyor (saatte ~180 istek; FUTBOL_SAATLIK_SINIR 600).
 *   - Veri futbol_gun_maclari ile ayni onbellek kaydinda: fikstur ve mac
 *     sayfalari da ayni taze skoru goruyor.
 * Kupa (Turkiye Kupasi) yok: ESPN kodu sinanmadi.
 */

require_once __DIR__ . '/futbol.php';

/** Takip edilen kulüpler (ESPN adında). */
const BUYUK_MAC_KULUPLER = '/galatasaray|fenerbah|be[sş]ikta[sş]/iu';

/** Bakılan ligler. */
const BUYUK_MAC_LIGLER = ['tur.1', 'uefa.champions', 'uefa.europa', 'uefa.europa.conf'];

/** Maç sırasında ESPN'e en sık bu kadar saniyede bir gidiliyor. */
const BUYUK_MAC_TAZE = 20;

/** Başlamadan bu kadar önce bant çıkar (saniye). */
const BUYUK_MAC_ONCE = 15 * 60;

/** Başlama saatinden bu kadar sonrasına kadar (maç sonucu bir süre kalsın). */
const BUYUK_MAC_SONRA = 150 * 60;

/** Günün fikstürü kaç saniyede bir okunur. */
const BUYUK_MAC_FIKSTUR = 30 * 60;

/**
 * Şu an bantta gösterilecek maçlar.
 *
 * $taze: true ise (yalnizca api/canli-skor.php) oynanan macin ligi
 * BUYUK_MAC_TAZE saniyeden eskiyse hemen yeniden cekiliyor; false ise
 * yalnizca onbellek (sayfa uretimi ESPN'i beklemesin).
 *
 * @return list<array<string,mixed>>
 */
function buyuk_maclar(bool $taze = false): array
{
    if (!futbol_api_acik()) {
        return [];
    }

    $istanbul = new DateTimeZone('Europe/Istanbul');
    $simdi    = time();
    $gunler   = [(new DateTimeImmutable('now', $istanbul))->format('Ymd')];

    // Gece yarisini gecen mac: dunun fiksturunde.
    if ((int) (new DateTimeImmutable('now', $istanbul))->format('G') < 3) {
        $gunler[] = (new DateTimeImmutable('-1 day', $istanbul))->format('Ymd');
    }

    $sonuc = [];

    foreach (BUYUK_MAC_LIGLER as $lig) {
        foreach ($gunler as $gun) {
            $kayit  = futbol_gun_maclari($lig, $gun, BUYUK_MAC_FIKSTUR);
            $adaylar = buyuk_mac_adaylari($kayit['veri']['maclar'] ?? [], $simdi);

            if ($adaylar === []) {
                continue;
            }

            // Oynanan (ya da baslamak uzere olan) mac var: skor taze olsun.
            $suruyor = array_filter($adaylar, static fn (array $m): bool => !in_array($m['durum'], FUTBOL_BITTI, true));

            if ($taze && $suruyor !== [] && $simdi - $kayit['zaman'] >= BUYUK_MAC_TAZE) {
                $yeni = buyuk_mac_tazele($lig, $gun);

                if ($yeni !== null) {
                    $kayit   = $yeni;
                    $adaylar = buyuk_mac_adaylari($kayit['veri']['maclar'] ?? [], $simdi);
                }
            }

            foreach ($adaylar as $m) {
                $sonuc[$m['id']] = buyuk_mac_ozeti($m, $lig) + ['veri_zamani' => $kayit['zaman']];
            }
        }
    }

    $sonuc = array_values($sonuc);
    usort($sonuc, static fn (array $a, array $b): int => $a['zaman'] <=> $b['zaman']);

    return $sonuc;
}

/**
 * Büyük üçten birinin oynadığı ve zaman penceresindeki maçlar.
 *
 * @param list<array<string,mixed>> $maclar
 * @return list<array<string,mixed>>
 */
function buyuk_mac_adaylari(array $maclar, int $simdi): array
{
    return array_values(array_filter($maclar, static function (array $m) use ($simdi): bool {
        $zaman = (int) ($m['zaman'] ?? 0);

        return $zaman > 0
            && $simdi >= $zaman - BUYUK_MAC_ONCE
            && $simdi <= $zaman + BUYUK_MAC_SONRA
            && !in_array($m['durum'] ?? '', ['PST', 'CANC', 'ABD'], true)
            && preg_match(BUYUK_MAC_KULUPLER, ($m['ev'] ?? '') . ' ' . ($m['dep'] ?? '')) === 1;
    }));
}

/**
 * Ligin günlük cevabını hemen yeniden çeker (en fazla BUYUK_MAC_TAZE
 * saniyede bir; aynı anda gelen ziyaretçiler ESPN'e tek istek atar).
 *
 * @return array{veri:array,zaman:int}|null
 */
function buyuk_mac_tazele(string $lig, string $gun): ?array
{
    $yol       = '/site/v2/sports/soccer/' . $lig . '/scoreboard';
    $parametre = ['dates' => $gun, 'limit' => 200];
    $anahtar   = futbol_onbellek_anahtari($yol, $parametre);

    try {
        $son = (int) ayar_oku('buyuk_mac_deneme_' . $anahtar, '0');

        if (time() - $son < BUYUK_MAC_TAZE) {
            return null;
        }

        // Once isaretle: cekim surerken gelen ziyaretci ikinci istek atmasin.
        ayar_yaz('buyuk_mac_deneme_' . $anahtar, (string) time());
    } catch (Throwable $e) {
        return null;
    }

    $kirp = futbol_gun_kirpici($lig);

    return canli_onbellek_uret($anahtar, static function () use ($yol, $parametre, $kirp): ?array {
        $cevap = futbol_istek($yol, $parametre);

        return $cevap === null ? null : $kirp($cevap);
    });
}

/**
 * Bandın ihtiyacı olan alanlar.
 *
 * @param array<string,mixed> $m
 * @return array<string,mixed>
 */
function buyuk_mac_ozeti(array $m, string $lig): array
{
    require_once __DIR__ . '/spor_panel.php';

    // Son gol: "GOL! Icardi 34'" icin.
    $sonGol = null;

    foreach ($m['olaylar'] ?? [] as $o) {
        if (($o['tip'] ?? '') === 'Goal') {
            $sonGol = [
                'dk'     => (int) $o['dk'] . ($o['ek'] > 0 ? '+' . (int) $o['ek'] : '') . "'",
                'oyuncu' => (string) $o['oyuncu'],
                'takim'  => (int) $o['takim'] === (int) $m['ev_id'] ? 'ev' : 'dep',
                'detay'  => (string) $o['detay'],
            ];
        }
    }

    $durum = (string) $m['durum'];

    return [
        'id'       => (int) $m['id'],
        'zaman'    => (int) $m['zaman'],
        'saat'     => (new DateTimeImmutable('@' . (int) $m['zaman']))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('H:i'),
        'durum'    => $durum,
        'durum_yazi' => match (true) {
            $durum === 'HT'                          => 'Devre arası',
            $durum === 'LIVE'                        => ((int) $m['dakika'] > 0 ? (int) $m['dakika'] . "'" : 'Başladı'),
            in_array($durum, FUTBOL_BITTI, true)     => 'Maç sonu',
            default                                  => 'Başlıyor',
        },
        'canli'    => in_array($durum, FUTBOL_CANLI, true),
        'lig'      => (string) $m['lig'],
        'ev'       => futbol_kisa_takim((string) $m['ev']),
        'dep'      => futbol_kisa_takim((string) $m['dep']),
        'ev_logo'  => (string) $m['ev_logo'],
        'dep_logo' => (string) $m['dep_logo'],
        'ev_gol'   => $m['ev_gol'],
        'dep_gol'  => $m['dep_gol'],
        'son_gol'  => $sonGol,
        'adres'    => spor_bolum_yolu('mac', ['mac' => (int) $m['id'], 'lig' => $lig, 'gun' => futbol_espn_gunu((int) $m['zaman'])]),
    ];
}

/**
 * "Galatasaray SK" -> "Galatasaray"; ESPN'in Türkçe karaktersiz yazdığı
 * kulüp adları düzeltiliyor ("Fenerbahce" -> "Fenerbahçe").
 */
function futbol_kisa_takim(string $ad): string
{
    $ad = trim((string) preg_replace('/\s+(A\.?Ş\.?|SK|S\.K\.|JK|FK|AŞ)$/u', '', $ad));

    return strtr($ad, [
        'Fenerbahce'  => 'Fenerbahçe',
        'Besiktas'    => 'Beşiktaş',
        'Basaksehir'  => 'Başakşehir',
        'Istanbul '   => 'İstanbul ',
        'Goztepe'     => 'Göztepe',
        'Kasimpasa'   => 'Kasımpaşa',
        'Eyupspor'    => 'Eyüpspor',
        'Karagumruk'  => 'Karagümrük',
        'Gaziantep'   => 'Gaziantep',
        'Genclerbirligi' => 'Gençlerbirliği',
        'Caykur'      => 'Çaykur',
        'Alanyaspor'  => 'Alanyaspor',
    ]);
}

/**
 * Skor bandı. Sayfa (sayfa_ust.php) ve uç (api/canli-skor.php) aynı
 * HTML'i bu işlevden alıyor; betik bandı uçtan gelenle değiştiriyor.
 *
 * @param list<array<string,mixed>> $maclar
 */
function buyuk_mac_bandi_html(array $maclar): string
{
    if ($maclar === []) {
        return '';
    }

    $html = '';

    foreach ($maclar as $m) {
        $oynandi = $m['ev_gol'] !== null && $m['dep_gol'] !== null;
        $skor    = $oynandi ? (int) $m['ev_gol'] . ' - ' . (int) $m['dep_gol'] : $m['saat'];
        $gol     = $m['son_gol'] !== null
            ? 'GOL! ' . ($m['son_gol']['oyuncu'] !== '' ? $m['son_gol']['oyuncu'] . ' ' : '') . $m['son_gol']['dk']
              . ($m['son_gol']['detay'] === 'Own Goal' ? ' (k.k.)' : ($m['son_gol']['detay'] === 'Penalty' ? ' (pen.)' : ''))
            : '';

        $html .= '<a class="mac-bant-mac' . ($m['canli'] ? ' canli' : '') . '" href="' . e((string) $m['adres']) . '"'
            . ' data-mac="' . (int) $m['id'] . '" data-gol="' . ($oynandi ? (int) $m['ev_gol'] + (int) $m['dep_gol'] : 0) . '"'
            . ' data-golcu="' . e($gol) . '">'
            . ($m['canli'] ? '<span class="canli-rozet">Canlı</span>' : '<span class="mac-bant-lig">' . e((string) $m['lig']) . '</span>')
            . '<span class="mac-bant-takim ev">' . ($m['ev_logo'] !== '' ? '<img src="' . e((string) $m['ev_logo']) . '" alt="" width="20" height="20" loading="lazy">' : '')
            . e((string) $m['ev']) . '</span>'
            . '<b class="mac-bant-skor' . ($oynandi ? '' : ' oynanmadi') . '">' . e($skor) . '</b>'
            . '<span class="mac-bant-takim dep">' . e((string) $m['dep'])
            . ($m['dep_logo'] !== '' ? '<img src="' . e((string) $m['dep_logo']) . '" alt="" width="20" height="20" loading="lazy">' : '') . '</span>'
            . '<span class="mac-bant-durum">' . e((string) $m['durum_yazi']) . '</span>'
            . '<span class="mac-bant-gol" aria-live="polite"></span>'
            . '</a>';
    }

    return '<div class="sinirli mac-bant-ic">' . $html . '</div>';
}

/**
 * Bandın kabuğu ve betiği (her sayfanın üstünde; sayfa_ust.php).
 *
 * Sayfa 60 saniyelik sayfa onbelleginden gelebilir; bu yuzden bant ilk
 * acilista da uctan tazeleniyor. Gol: macin toplam golu, bu sekmede
 * daha once gorulenden fazlaysa bant 12 saniye yanip soner ve golu atan
 * yazilir. Ilk gorulen skorda yanip sonmez (sayfa yeni acildi).
 */
function buyuk_mac_bandi(): string
{
    try {
        $maclar = buyuk_maclar(false);
    } catch (Throwable $e) {
        error_log('[valentra] mac bandi: ' . $e->getMessage());

        return '';
    }

    if ($maclar === []) {
        return '';
    }

    return '<div class="mac-bant" id="mac-bant" role="region" aria-label="Canlı skor">' . buyuk_mac_bandi_html($maclar) . '</div>'
        . '<script>' . BUYUK_MAC_BETIK . '</script>';
}

const BUYUK_MAC_BETIK = <<<'JS'
(function () {
    var bant = document.getElementById('mac-bant');
    if (!bant || !window.fetch) { return; }

    function oku(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
    function yaz(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }

    function golleriDenetle() {
        bant.querySelectorAll('.mac-bant-mac').forEach(function (a) {
            var anahtar = 'mac-gol:' + a.getAttribute('data-mac');
            var simdi = parseInt(a.getAttribute('data-gol') || '0', 10);
            var once = oku(anahtar);
            yaz(anahtar, String(simdi));

            if (once !== null && simdi > parseInt(once, 10)) {
                a.querySelector('.mac-bant-gol').textContent = a.getAttribute('data-golcu') || 'GOL!';
                a.classList.add('gol-oldu');
                setTimeout(function () { a.classList.remove('gol-oldu'); }, 12000);
            }
        });
    }

    function sor() {
        if (document.hidden) { setTimeout(sor, 20000); return; }

        fetch('/api/canli-skor.php', { credentials: 'omit' })
            .then(function (c) { return c.ok ? c.json() : null; })
            .then(function (v) {
                if (!v) { setTimeout(sor, 60000); return; }

                if (!v.maclar || v.maclar.length === 0) { bant.remove(); return; }

                // Yanip sonen mac kalsin: yalnizca degisen bilgiyi yenile.
                var gecici = document.createElement('div');
                gecici.innerHTML = v.html;
                var yeni = gecici.firstElementChild;
                yeni.querySelectorAll('.mac-bant-mac').forEach(function (a) {
                    var eski = bant.querySelector('.mac-bant-mac[data-mac="' + a.getAttribute('data-mac') + '"]');
                    if (eski && eski.classList.contains('gol-oldu')) {
                        a.classList.add('gol-oldu');
                        a.querySelector('.mac-bant-gol').textContent = eski.querySelector('.mac-bant-gol').textContent;
                        setTimeout(function () { a.classList.remove('gol-oldu'); }, 12000);
                    }
                });
                bant.replaceChildren(yeni);
                golleriDenetle();

                if (v.yenile > 0) { setTimeout(sor, v.yenile * 1000); }
            })
            .catch(function () { setTimeout(sor, 60000); });
    }

    golleriDenetle();
    sor();
})();
JS;
