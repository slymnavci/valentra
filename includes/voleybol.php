<?php
declare(strict_types=1);

/**
 * Voleybol verileri: Sultanlar Ligi ve Efeler Ligi puan durumu (ValSpor'un
 * Voleybol sayfasi).
 *
 * Site sahibinin istegi (30.10.2026): her sporun sayfasinda o sporun
 * verileri. Kaynak Turkiye Voleybol Federasyonu'nun lig sayfalari
 * (tvf.org.tr/lig/...): sayfada tek tablo, ilk sutun sira, ikincisi takim
 * (logo + ad), sonra O G M P A V SAV ASP VSP SPAV ve set dagilimi. GitHub
 * Actions'tan sinandi (30.10.2026, kaynak testi --ham). Sayfa yapisi
 * degisir ya da sunucu erisemezse bolum basilmiyor; haberler yine gorunuyor.
 */

require_once __DIR__ . '/canli_onbellek.php';
require_once __DIR__ . '/http_ortak.php';

const VOLEYBOL_LIGLERI = [
    'sultanlar-ligi' => 'Sultanlar Ligi',
    'efeler-ligi'    => 'Efeler Ligi',
];

/** @return array{veri:?array,zaman:int} */
function voleybol_puan_durumu(string $lig): array
{
    return canli_onbellek('tvf_' . $lig, 1800, static function () use ($lig): ?array {
        $sonuc = http_getir('https://tvf.org.tr/lig/' . rawurlencode($lig), 15);

        if (!$sonuc['tamam']) {
            error_log('[valentra] voleybol ' . $lig . ': ' . $sonuc['neden']);

            return null;
        }

        $satirlar = voleybol_tablo_coz((string) $sonuc['govde']);

        return $satirlar !== [] ? $satirlar : null;
    });
}

/**
 * TVF lig sayfasindaki puan tablosu.
 *
 * @return list<array{sira:int,takim:string,logo:string,o:int,g:int,m:int,p:int,a:int,v:int}>
 */
function voleybol_tablo_coz(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $satirlar = [];

    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $hucreler = $tr->getElementsByTagName('td');

        // Baslik satirinda td yok (th); en az O G M P A V gerekli.
        if ($hucreler->length < 8) {
            continue;
        }

        $metin = static fn (int $i): string => trim(preg_replace('/\s+/u', ' ', (string) $hucreler->item($i)?->textContent) ?? '');
        $img   = $hucreler->item(1)?->getElementsByTagName('img')->item(0);

        if (!ctype_digit($metin(0)) || $metin(1) === '') {
            continue;
        }

        $satirlar[] = [
            'sira'  => (int) $metin(0),
            'takim' => $metin(1),
            'logo'  => $img instanceof DOMElement ? guvenli_gorsel_adresi($img->getAttribute('src')) : '',
            'o'     => (int) $metin(2),
            'g'     => (int) $metin(3),
            'm'     => (int) $metin(4),
            'p'     => (int) $metin(5),
            'a'     => (int) $metin(6),
            'v'     => (int) $metin(7),
        ];
    }

    return $satirlar;
}

/** Yalnizca https gorsel adresi (sayfaya dis kaynaktan giriyor). */
function guvenli_gorsel_adresi(string $adres): string
{
    return preg_match('#^https://[^\s"\'<>]+$#i', $adres) ? $adres : '';
}

/** Takim adi: TVF hepsini buyuk harfle yaziyor ("VAKIFBANK"); Turkce kucuk harf. */
function voleybol_takim_adi(string $ad): string
{
    $kucuk = mb_strtolower(strtr($ad, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');

    return mb_convert_case($kucuk, MB_CASE_TITLE, 'UTF-8');
}

function voleybol_puan_html(string $lig): string
{
    $kayit = voleybol_puan_durumu($lig);

    if ($kayit['veri'] === null) {
        return '';
    }

    ob_start();
    ?>
    <div class="spor-kutu spor-puan">
        <div class="tablo-kaydir">
            <table class="puan-tablosu">
                <thead>
                    <tr>
                        <th scope="col" class="sira">#</th>
                        <th scope="col" class="takim">Takım</th>
                        <th scope="col" title="Oynanan">O</th>
                        <th scope="col" title="Galibiyet">G</th>
                        <th scope="col" title="Mağlubiyet">M</th>
                        <th scope="col" title="Alınan set" class="genis">AS</th>
                        <th scope="col" title="Verilen set" class="genis">VS</th>
                        <th scope="col" title="Puan" class="puan">P</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kayit['veri'] as $s): ?>
                        <tr>
                            <td class="sira"><?= $s['sira'] ?></td>
                            <td class="takim">
                                <?php if ($s['logo'] !== ''): ?><img src="<?= e($s['logo']) ?>" alt="" loading="lazy" width="18" height="18" referrerpolicy="no-referrer"><?php endif; ?>
                                <?= e(voleybol_takim_adi($s['takim'])) ?>
                            </td>
                            <td><?= $s['o'] ?></td>
                            <td><?= $s['g'] ?></td>
                            <td><?= $s['m'] ?></td>
                            <td class="genis"><?= $s['a'] ?></td>
                            <td class="genis"><?= $s['v'] ?></td>
                            <td class="puan"><?= $s['p'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="canli-not">Kaynak: Türkiye Voleybol Federasyonu<?= $kayit['zaman'] > 0 ? ' · güncelleme ' . e(date('H:i', $kayit['zaman'])) : '' ?></p>
    <?php

    return (string) ob_get_clean();
}
