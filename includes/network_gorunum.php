<?php
declare(strict_types=1);

/**
 * V Network gorunum parcalari: akis kartlari (haber ve uye paylasimi),
 * begeni ve takip dugmeleri, fotograf izgarasi, video oynatici, kisi
 * kartlari. Sayfalar network.php'de.
 */

require_once __DIR__ . '/network.php';
require_once __DIR__ . '/yorum_bolumu.php';

/** Islem formunun ortak gizli alanlari (bkz. network-islem.php). */
function nw_form_alanlari(string $islem, string $donus, array $ek = []): string
{
    $html = '<input type="hidden" name="jeton" value="' . e(uye_form_jetonu()) . '">'
          . '<input type="hidden" name="islem" value="' . e($islem) . '">'
          . '<input type="hidden" name="donus" value="' . e($donus) . '">';

    foreach ($ek as $ad => $deger) {
        $html .= '<input type="hidden" name="' . e((string) $ad) . '" value="' . e((string) $deger) . '">';
    }

    return $html;
}

/**
 * Video: gomme adresi (YouTube/Vimeo/Dailymotion). Ekrana gelince sessiz
 * baslayip kaydirinca duruyor (site sahibinin istegi, 31.10.2026; bkz.
 * nw_oto_oynat_betigi). Tarayicilar sesli otomatik oynatmaya izin
 * vermiyor; ses oynaticinin kendi dugmesinden.
 */
function nw_video_html(string $gomme, string $baslik = 'Video'): string
{
    if (haber_video_adresi($gomme) === '') {
        return '';
    }

    $ek = str_contains($gomme, 'youtube') ? '?enablejsapi=1&mute=1&playsinline=1&rel=0'
        : (str_contains($gomme, 'vimeo') ? '?muted=1&playsinline=1' : '?mute=1&api=postMessage');

    return '<div class="nw-video"><iframe class="oto-oynat" src="' . e($gomme . $ek) . '" title="' . e($baslik) . '" loading="lazy"'
         . ' allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
}

/** Ekrana gelen videoyu oynatir, cikani durdurur (YouTube, Vimeo, Dailymotion). */
function nw_oto_oynat_betigi(): string
{
    return <<<'HTML'
<script>
(function () {
    if (!('IntersectionObserver' in window)) { return; }
    function komut(f, oynat) {
        var w = f.contentWindow, s = f.src || '';
        if (!w) { return; }
        if (s.indexOf('youtube') > -1) {
            w.postMessage(JSON.stringify({ event: 'command', func: oynat ? 'playVideo' : 'pauseVideo', args: [] }), '*');
        } else if (s.indexOf('vimeo') > -1) {
            w.postMessage(JSON.stringify({ method: oynat ? 'play' : 'pause' }), '*');
        } else if (s.indexOf('dailymotion') > -1) {
            w.postMessage(oynat ? 'play' : 'pause', '*');
        }
    }
    var gozcu = new IntersectionObserver(function (olaylar) {
        olaylar.forEach(function (o) {
            var gorunur = o.intersectionRatio >= 0.6;
            o.target.dataset.gorunur = gorunur ? '1' : '';
            komut(o.target, gorunur);
        });
    }, { threshold: [0, 0.6] });
    function izle(f) {
        if (f.dataset.izleniyor) { return; }
        f.dataset.izleniyor = '1';
        gozcu.observe(f);
        // Oynatici yuklenmeden gelen komut kaybolur: yuklenince yeniden.
        f.addEventListener('load', function () {
            setTimeout(function () { if (f.dataset.gorunur) { komut(f, true); } }, 400);
        });
    }
    document.querySelectorAll('iframe.oto-oynat').forEach(izle);
    // Haber sayfasinda kaydirdikca eklenen haberlerin videolari da.
    if ('MutationObserver' in window) {
        new MutationObserver(function () {
            document.querySelectorAll('iframe.oto-oynat:not([data-izleniyor])').forEach(izle);
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
</script>
HTML;
}

/** @param list<array{dosya:string,genislik:int,yukseklik:int}> $fotolar */
function nw_foto_izgara_html(array $fotolar): string
{
    if ($fotolar === []) {
        return '';
    }

    $html = '<div class="nw-fotolar nw-fotolar-' . min(4, count($fotolar)) . '">';

    foreach ($fotolar as $f) {
        $adres = nw_foto_adresi((string) $f['dosya']);

        if ($adres !== '') {
            $html .= '<a href="' . e($adres) . '" target="_blank" rel="noopener"><img src="' . e($adres) . '" alt="" loading="lazy" width="'
                   . (int) $f['genislik'] . '" height="' . (int) $f['yukseklik'] . '"></a>';
        }
    }

    return $html . '</div>';
}

/** Begen dugmesi (uye) ya da giris baglantisi; sayi yaninda. */
function nw_begen_html(string $anahtar, int $sayi, bool $benim, string $donus): string
{
    $etiket = '<span class="nw-simge" aria-hidden="true">👍</span> ' . ($benim ? 'Beğendin' : 'Beğen');

    if (aktif_uye() === null) {
        return '<a class="nw-eylem" href="' . e(uye_yolu('giris', $donus)) . '">' . $etiket . '</a>';
    }

    return '<form method="post" action="/network-islem.php" class="nw-eylem-form">'
         . nw_form_alanlari('begen', $donus, ['hedef' => $anahtar])
         . '<button type="submit" class="nw-eylem' . ($benim ? ' secili' : '') . '" aria-pressed="' . ($benim ? 'true' : 'false') . '">' . $etiket . '</button></form>';
}

/** Takip et / takibi birak dugmesi; kendi profilinde ve girissizde baglanti. */
function nw_takip_html(int $kisiId, bool $takipte, string $donus, bool $kucuk = false): string
{
    $uye = aktif_uye();

    if ($uye === null) {
        return '<a class="uye-dugme' . ($kucuk ? ' kucuk' : '') . '" href="' . e(uye_yolu('giris', $donus)) . '">Takip et</a>';
    }

    if ((int) $uye['id'] === $kisiId) {
        return '';
    }

    return '<form method="post" action="/network-islem.php" class="nw-satir-form">'
         . nw_form_alanlari($takipte ? 'takip_birak' : 'takip', $donus, ['uye' => $kisiId])
         . '<button type="submit" class="uye-dugme' . ($takipte ? ' uye-dugme-ikincil' : '') . ($kucuk ? ' kucuk' : '') . '">'
         . ($takipte ? 'Takiptesin ✓' : 'Takip et') . '</button></form>';
}

/**
 * Akis karti. $baglam: begeni/yorum/okunma bilgileri (sayfanin topluca
 * sorguladigi), fotograflar.
 *
 * @param array{tur:string, veri:array<string,mixed>} $oge
 */
function nw_kart_html(array $oge, array $baglam, string $donus, bool $detay = false): string
{
    $v     = $oge['veri'];
    $id    = (int) $v['id'];
    $haber = $oge['tur'] === 'haber';
    $anahtar = ($haber ? 'h' : 'g') . $id;
    $uye   = aktif_uye();
    $begeni = $baglam['begeni'][$haber ? 'haber' : 'gonderi'] ?? ['sayi' => [], 'benim' => []];
    $yorum  = $baglam['yorum'][$haber ? 'haber' : 'gonderi'][$id] ?? ['sayi' => 0, 'son' => []];
    $detayYol = $haber ? nw_yol('h/' . $id) : nw_gonderi_yolu($id);
    $zaman = (string) ($haber ? $v['yayin_tarihi'] : $v['olusturuldu']);
    $begeniSayi = (int) ($begeni['sayi'][$id] ?? 0);
    $okunma = $haber ? (int) ($baglam['okunma'][$id] ?? 0) : 0;
    // Islemden sonra ayni karta donulsun (akista #h12, #g5).
    $donus = $detay ? $donus : (preg_replace('/#.*$/', '', $donus) ?? $donus) . '#' . $anahtar;

    ob_start();
    ?>
    <article class="nw-gonderi" id="<?= e($anahtar) ?>">
        <header class="nw-gonderi-ust">
            <?php if ($haber): ?>
                <span class="nw-hesap-simge" aria-hidden="true"><img src="/assets/logo.svg" alt="" width="24" height="19"></span>
                <div>
                    <strong class="nw-hesap">Val<span>Haber</span></strong>
                    <span class="nw-meta"><a href="<?= e($detayYol) ?>"><time datetime="<?= e(date('c', (int) strtotime($zaman))) ?>"><?= e(yorum_zaman($zaman)) ?></time></a><?php if (!empty($v['kategori_adi'])): ?> · <?= e((string) $v['kategori_adi']) ?><?php endif; ?></span>
                </div>
            <?php else: ?>
                <a href="<?= e(nw_profil_yolu((int) $v['uye_id'])) ?>" tabindex="-1" aria-hidden="true"><?= uye_simge_html($v, 40) ?></a>
                <div>
                    <a class="nw-hesap" href="<?= e(nw_profil_yolu((int) $v['uye_id'])) ?>"><?= e((string) $v['ad']) ?></a>
                    <span class="nw-meta"><a href="<?= e($detayYol) ?>"><time datetime="<?= e(date('c', (int) strtotime($zaman))) ?>"><?= e(yorum_zaman($zaman)) ?></time></a></span>
                </div>
                <?php if ($uye !== null): ?>
                    <form method="post" action="/network-islem.php" class="nw-gonderi-islem">
                        <?php if ((int) $v['uye_id'] === (int) $uye['id']): ?>
                            <?= nw_form_alanlari('gonderi_sil', $detay ? nw_yol() : $donus, ['gonderi' => $id]) ?>
                            <button type="submit" onclick="return confirm('Paylaşımınız silinsin mi?')" title="Paylaşımı sil">Sil</button>
                        <?php else: ?>
                            <?= nw_form_alanlari('gonderi_sikayet', $donus, ['gonderi' => $id]) ?>
                            <button type="submit" onclick="return confirm('Bu paylaşımı uygunsuz diye bildirmek istiyor musunuz?')" title="Şikâyet et">Şikâyet et</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </header>

        <?php if ($haber): ?>
            <a class="nw-icerik" href="<?= e($detayYol) ?>">
                <h2><?= e((string) $v['baslik']) ?></h2>
                <?php if ((string) $v['ozet'] !== ''): ?><p><?= e($detay ? (string) $v['ozet'] : kisalt((string) $v['ozet'], 240)) ?></p><?php endif; ?>
            </a>
            <?php if (haber_video_adresi((string) ($v['iframe_url'] ?? '')) !== ''): ?>
                <?= nw_video_html((string) $v['iframe_url'], (string) $v['baslik']) ?>
            <?php elseif (haber_gorsel($v) !== ''): ?>
                <a class="nw-gorsel" href="<?= e($detayYol) ?>">
                    <?php $set = haber_gorsel_srcset($v); ?>
                    <img src="<?= e(haber_gorsel_kucuk($v, 960)) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"
                         <?php if ($set !== ''): ?>srcset="<?= e($set) ?>" sizes="(max-width: 680px) 100vw, 640px"<?php endif; ?>
                         onerror="this.remove()">
                </a>
            <?php endif; ?>
        <?php else: ?>
            <?php if (trim((string) $v['metin']) !== ''): ?>
                <div class="nw-metin"><?= yorum_metin_html((string) $v['metin']) ?></div>
            <?php endif; ?>
            <?= nw_foto_izgara_html($baglam['foto'][$id] ?? []) ?>
            <?= nw_video_html((string) ($v['video_url'] ?? '')) ?>
        <?php endif; ?>

        <div class="nw-sayilar">
            <span><?= $begeniSayi > 0 ? '👍 ' . $begeniSayi . ' beğeni' : '' ?></span>
            <span>
                <?php if ($yorum['sayi'] > 0): ?><a href="<?= e($detayYol) ?>#yorumlar"><?= $yorum['sayi'] ?> yorum</a><?php endif; ?>
                <?php if ($haber && $okunma > 0): ?><?= $yorum['sayi'] > 0 ? ' · ' : '' ?><?= $okunma ?> kişi okudu<?php endif; ?>
            </span>
        </div>

        <div class="nw-eylemler">
            <?= nw_begen_html($anahtar, $begeniSayi, !empty($begeni['benim'][$id]), $donus) ?>
            <a class="nw-eylem" href="<?= e($detayYol) ?>#yorumlar"><span class="nw-simge" aria-hidden="true">💬</span> Yorum yap</a>
            <?php if ($haber && !$detay): ?>
                <a class="nw-eylem" href="<?= e($detayYol) ?>"><span class="nw-simge" aria-hidden="true">📰</span> Habere git</a>
            <?php endif; ?>
        </div>

        <?= yorum_bolumu_html($v, $detay ? null : $yorum, $donus, $haber ? 'haber' : 'gonderi', $detayYol) ?>
    </article>
    <?php

    return (string) ob_get_clean();
}

/**
 * Akis kartlarinin topluca sorgulanan bilgileri (sayfa basina birkac sorgu).
 *
 * @param list<array{tur:string, veri:array<string,mixed>}> $ogeler
 */
function nw_baglam(array $ogeler): array
{
    $haberler = $gonderiler = [];

    foreach ($ogeler as $o) {
        if ($o['tur'] === 'haber') {
            $haberler[] = (int) $o['veri']['id'];
        } else {
            $gonderiler[] = (int) $o['veri']['id'];
        }
    }

    $uye   = aktif_uye();
    $uyeId = $uye !== null ? (int) $uye['id'] : null;

    return [
        'begeni' => ['haber' => begeni_bilgisi('haber', $haberler, $uyeId), 'gonderi' => begeni_bilgisi('gonderi', $gonderiler, $uyeId)],
        'yorum'  => ['haber' => yorum_ozetleri($haberler), 'gonderi' => yorum_ozetleri($gonderiler, 2, 'gonderi')],
        'okunma' => okunma_sayilari($haberler),
        'foto'   => gonderi_fotolari($gonderiler),
    ];
}

/** Kisi karti (Kisiler sayfasi, takipci listeleri, oneriler). */
function nw_kisi_html(array $k, array $takipEdilenler, string $donus, bool $kucuk = false): string
{
    $id = (int) $k['id'];

    return '<div class="nw-kisi' . ($kucuk ? ' kucuk' : '') . '">'
         . '<a class="nw-kisi-bas" href="' . e(nw_profil_yolu($id)) . '">' . uye_simge_html($k, $kucuk ? 40 : 56)
         . '<span><strong>' . e((string) $k['ad']) . '</strong>'
         . (isset($k['takipci']) ? '<small>' . (int) $k['takipci'] . ' takipçi</small>' : '')
         . (!$kucuk && (string) ($k['hakkinda'] ?? '') !== '' ? '<small class="nw-kisi-hakkinda">' . e(kisalt((string) $k['hakkinda'], 90)) . '</small>' : '')
         . '</span></a>'
         . nw_takip_html($id, in_array($id, $takipEdilenler, true), $donus, true)
         . '</div>';
}

/** Islem sonucu mesaji (network-islem.php'nin biraktigi kisa omurlu cerez). */
function nw_bildirim_html(): string
{
    $cerez = (string) ($_COOKIE['valentra_nw_bildirim'] ?? '');

    if ($cerez === '') {
        return '';
    }

    setcookie('valentra_nw_bildirim', '', uye_cerez_secenekleri(time() - 3600));
    [$tur, $metin] = array_pad(explode('|', $cerez, 2), 2, '');
    $tur = in_array($tur, ['basari', 'bilgi', 'hata'], true) ? $tur : 'bilgi';

    return '<p class="yorum-sonuc yorum-sonuc-' . $tur . ' nw-bildirim" role="status">' . e(mb_substr($metin, 0, 300)) . '</p>';
}
