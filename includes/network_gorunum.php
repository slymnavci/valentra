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

/**
 * Akista okunan kart = bir sayfa goruntulenmesi (01.11.2026, site sahibi:
 * "network'te her okunan haber bir sayfa gibi gorunsun; asagi kaydirdikca
 * okunan sayfa sayisi da degissin"). Kart en az yarisi ekranda 1,5 saniye
 * kalinca network-okundu.php'ye bir kez bildiriliyor; tarayici anahtari
 * sitenin ziyaret betigiyle ayni (vz_k), ayni kisi iki kisi sayilmasin.
 */
function nw_okuma_betigi(): string
{
    return <<<'HTML'
<script>
(function () {
    if (!('IntersectionObserver' in window)) { return; }
    var gonderilen = {}, sayaclar = {}, anahtar = '';
    try { anahtar = localStorage.getItem('vz_k') || ''; } catch (e) {}
    function gonder(id) {
        if (gonderilen[id]) { return; }
        gonderilen[id] = true;
        var d = new URLSearchParams();
        d.append('hedef', id);
        d.append('k', anahtar);
        if (navigator.sendBeacon) { navigator.sendBeacon('/network-okundu.php', d); }
        else { fetch('/network-okundu.php', { method: 'POST', body: d, keepalive: true, credentials: 'same-origin' }).catch(function () {}); }
        // Google Analytics'e de bir sayfa goruntulenmesi (site sahibi, 14.11.2026: "asagiya kaydirilip her
        // yeni haber okundugunda bir sayfa okumasi sayalim; Google'a da sayfa sayisi bu sekilde gitsin").
        // Adres kartin kendi sayfasi (/network/h/12); cerez izni yoksa gtag zaten gondermez.
        if (typeof window.gtag === 'function') {
            var kart = document.getElementById(id), bas = kart ? kart.querySelector('h2, h3') : null;
            var yol = '/network/' + id.charAt(0) + '/' + id.slice(1);
            try {
                window.gtag('event', 'page_view', {
                    page_location: location.origin + yol, page_path: yol,
                    page_title: bas ? bas.textContent.trim().slice(0, 150) : document.title,
                    page_referrer: location.href
                });
            } catch (e) {}
        }
    }
    var gozcu = new IntersectionObserver(function (olaylar) {
        olaylar.forEach(function (o) {
            var id = o.target.id;
            if (!/^[hg]\d+$/.test(id) || gonderilen[id]) { return; }
            if (o.intersectionRatio >= 0.5 && !document.hidden) {
                if (!sayaclar[id]) { sayaclar[id] = setTimeout(function () { gonder(id); gozcu.unobserve(o.target); }, 1500); }
            } else if (sayaclar[id]) {
                clearTimeout(sayaclar[id]);
                delete sayaclar[id];
            }
        });
    }, { threshold: [0, 0.5] });
    document.querySelectorAll('.nw-orta .nw-gonderi, .nw-profil .nw-gonderi').forEach(function (k) { gozcu.observe(k); });
    // Asagi indikce yuklenen kartlar da sayilsin (assets/network-akis.js, surekli akis).
    window.nwOkumaGozle = function (k) { gozcu.observe(k); };
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

/**
 * Tepki düğmesi (üye) ya da giriş bağlantısı.
 *
 * Dugmeye dokununca tepki secici aciliyor (details; betik gerekmiyor,
 * telefonda da calisiyor): Begen, Alkis, Sevgi, Saskin, Uzgun, Kizgin
 * (NW_TEPKILER). Dugme uyenin verdigi tepkiyi gosteriyor; ayni tepkiye
 * yeniden basmak geri aliyor.
 *
 * $benim: uyenin tepkisi ('' = yok).
 */
function nw_begen_html(string $anahtar, int $sayi, string $benim, string $donus): string
{
    $benim = isset(NW_TEPKILER[$benim]) ? $benim : '';
    [$simge, , $verdi] = NW_TEPKILER[$benim !== '' ? $benim : 'begen'];
    $etiket = '<span class="nw-simge" aria-hidden="true">' . $simge . '</span> '
            . ($benim !== '' ? $verdi : 'Beğen');

    if (aktif_uye() === null) {
        return '<a class="nw-eylem" href="' . e(uye_yolu('giris', $donus)) . '">' . $etiket . '</a>';
    }

    $dugmeler = '';

    foreach (NW_TEPKILER as $k => [$s, $ad]) {
        $dugmeler .= '<button type="submit" name="tepki" value="' . e($k) . '" class="nw-tepki-sec' . ($k === $benim ? ' secili' : '')
                   . '" title="' . e($ad) . '" aria-label="' . e($ad) . '" aria-pressed="' . ($k === $benim ? 'true' : 'false') . '">'
                   . '<span aria-hidden="true">' . $s . '</span><small>' . e($ad) . '</small></button>';
    }

    return '<details class="nw-tepki">'
         . '<summary class="nw-eylem' . ($benim !== '' ? ' secili' : '') . '">' . $etiket . '</summary>'
         . '<form method="post" action="/network-islem.php" class="nw-tepki-panel">'
         . nw_form_alanlari('begen', $donus, ['hedef' => $anahtar]) . $dugmeler . '</form></details>';
}

/** Tepki özeti: en çok verilen üç tepkinin simgesi ve toplam ("👍😢👏 12"). */
function nw_tepki_ozet_html(array $tepkiler, int $toplam): string
{
    if ($toplam <= 0) {
        return '';
    }

    arsort($tepkiler);
    $simgeler = '';

    foreach (array_slice(array_keys(array_filter($tepkiler)), 0, 3) as $k) {
        $simgeler .= NW_TEPKILER[$k][0] ?? '';
    }

    $baslik = implode(', ', array_map(
        static fn (string $k, int $n): string => (NW_TEPKILER[$k][1] ?? $k) . ' ' . $n,
        array_keys($tepkiler), array_values($tepkiler)
    ));

    return '<span class="nw-tepki-ozet" title="' . e($baslik) . '">' . ($simgeler !== '' ? $simgeler : '👍') . ' ' . $toplam . '</span>';
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
    if ($oge['tur'] === 'yorum') {
        return nw_yorum_kart_html($oge);
    }

    $v     = $oge['veri'];
    $id    = (int) $v['id'];
    $haber = $oge['tur'] === 'haber';
    $anahtar = ($haber ? 'h' : 'g') . $id;
    $uye   = aktif_uye();
    $begeni = $baglam['begeni'][$haber ? 'haber' : 'gonderi'] ?? ['sayi' => [], 'tepki' => [], 'benim' => []];
    $yorum  = $baglam['yorum'][$haber ? 'haber' : 'gonderi'][$id] ?? ['sayi' => 0, 'son' => []];
    $detayYol = $haber ? nw_yol('h/' . $id) : nw_gonderi_yolu($id);
    $zaman = (string) ($haber ? $v['yayin_tarihi'] : $v['olusturuldu']);
    $begeniSayi = (int) ($begeni['sayi'][$id] ?? 0);
    $okunma = $haber ? (int) ($baglam['okunma'][$id] ?? 0) : 0;
    // Islemden sonra ayni karta donulsun (akista #h12, #g5).
    $donus = $detay ? $donus : (preg_replace('/#.*$/', '', $donus) ?? $donus) . '#' . $anahtar;

    ob_start();
    ?>
    <article class="nw-gonderi" id="<?= e($anahtar) ?>"<?= !empty($oge['zaman']) ? ' data-zaman="' . (int) $oge['zaman'] . '"' : '' ?>>
        <header class="nw-gonderi-ust">
            <?php if ($haber): ?>
                <?php
                /*
                 * Kartin basinda haberin alindigi GAZETE (13.11.2026, site sahibi:
                 * "basliklari ValHaber yapmayacagiz; gazetenin adi yazsin"). Ad o
                 * gazetenin akisina gider. Logo kullanilmiyor: adin bas harfi.
                 * Kaynagi olmayan haberde (Valentra derlemesi) eski "ValHaber".
                 */
                $gazeteAd   = trim((string) ($v['kaynak_adi'] ?? '')) !== '' ? nw_gazete_adi((string) $v['kaynak_adi']) : '';
                $gazeteSlug = $gazeteAd !== '' ? nw_gazete_slug($gazeteAd) : '';
                $kaynakUrl  = guvenli_url((string) ($v['kaynak_url'] ?? ''));
                $kaynakUrl  = preg_match('#^https?://#i', $kaynakUrl) === 1 ? $kaynakUrl : '';
                ?>
                <?php if ($gazeteAd !== ''): ?>
                    <a class="nw-hesap-simge nw-gazete-simge" href="<?= e(nw_yol('', ['gazete' => $gazeteSlug])) ?>" tabindex="-1" aria-hidden="true"><?= nw_gazete_simge_html($gazeteSlug, $gazeteAd, 40) ?></a>
                <?php else: ?>
                    <span class="nw-hesap-simge" aria-hidden="true"><img src="/assets/logo.svg" alt="" width="24" height="19"></span>
                <?php endif; ?>
                <div>
                    <?php if ($gazeteAd !== ''): ?>
                        <a class="nw-hesap" href="<?= e(nw_yol('', ['gazete' => $gazeteSlug])) ?>"><?= e($gazeteAd) ?></a>
                    <?php else: ?>
                        <strong class="nw-hesap">Val<span>Haber</span></strong>
                    <?php endif; ?>
                    <span class="nw-meta"><a href="<?= e($detayYol) ?>"><time datetime="<?= e(date('c', (int) strtotime($zaman))) ?>"><?= e(yorum_zaman($zaman)) ?></time></a><?php if (!empty($v['kategori_adi'])): ?> · <?= e((string) $v['kategori_adi']) ?><?php endif; ?></span>
                    <?php /* Haberi mansetine / gundemine tasiyan gazeteler (14.11.2026; bkz. manset_sinyal.php). */ ?>
                    <?php if (trim((string) ($v['manset_yayinlar'] ?? '')) !== ''): ?>
                        <span class="nw-manset-rozet"><?php /* Eski kayitlarda ek "(TRT manşetinde)" diye yaziliydi; ikisi de taninir. */ ?><?= str_contains((string) $v['manset_yayinlar'], 'manşet') ? 'Manşette' : 'Gündeminde' ?>: <?= e(trim((string) preg_replace('/\s*\((TRT manşetinde|manşette)\)/u', '', (string) $v['manset_yayinlar']))) ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <a href="<?= e(nw_profil_yolu((int) $v['uye_id'])) ?>" tabindex="-1" aria-hidden="true"><?= uye_simge_html($v, 40) ?></a>
                <div>
                    <a class="nw-hesap" href="<?= e(nw_profil_yolu((int) $v['uye_id'])) ?>"><?= e((string) $v['ad']) ?><?= uye_rozet_html($v) ?></a>
                    <span class="nw-meta"><a href="<?= e($detayYol) ?>"><time datetime="<?= e(date('c', (int) strtotime($zaman))) ?>"><?= e(yorum_zaman($zaman)) ?></time></a></span>
                </div>
                <?php if ($uye !== null): ?>
                    <form method="post" action="/network-islem.php" class="nw-gonderi-islem">
                        <?php if ((int) $v['uye_id'] === (int) $uye['id'] || uye_yonetici_mi($uye)): ?>
                            <?= nw_form_alanlari('gonderi_sil', $detay ? nw_yol() : $donus, ['gonderi' => $id]) ?>
                            <button type="submit" onclick="return confirm('Paylaşım silinsin mi?')" title="Paylaşımı sil">Sil</button>
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
                <?php /* Akista 5-6 satirlik ozet (14.11.2026; bkz. nw_haber_ozeti); haber sayfasinda ozetin tamami. */ ?>
                <?php $kartOzeti = $detay ? (string) $v['ozet'] : nw_haber_ozeti($v); ?>
                <?php if ($kartOzeti !== ''): ?><p><?= e($kartOzeti) ?></p><?php endif; ?>
            </a>
            <?php if (haber_video_adresi((string) ($v['iframe_url'] ?? '')) !== ''): ?>
                <?= nw_video_html((string) $v['iframe_url'], (string) $v['baslik']) ?>
            <?php elseif (haber_gorsel($v) !== ''): ?>
                <?php $konuGorseli = '/assets/kategori/' . haber_gorsel_konu_adi((string) ($v['kategori_slug'] ?? '')) . '.jpg'; ?>
                <a class="nw-gorsel" href="<?= e($detayYol) ?>">
                    <?php $set = haber_gorsel_srcset($v); ?>
                    <?php /* Sayfadaki ILK haber gorseli hemen ve oncelikli yuklenir (14.11.2026, hiz): ekranin en buyuk
                             ogesi o; digerleri ekrana yaklasinca. */ ?>
                    <?php static $ilkGorsel = true; $oncelikli = $ilkGorsel; $ilkGorsel = false; ?>
                    <?php /* Gorsel yuklenemezse kart resimsiz kalmasin: konu gorseline dus (13.11.2026). */ ?>
                    <img src="<?= e(haber_gorsel_kucuk($v, 960)) ?>" alt="<?= e((string) $v['baslik']) ?>" <?= $oncelikli ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async" referrerpolicy="no-referrer"
                         <?php if ($set !== ''): ?>srcset="<?= e($set) ?>" sizes="(max-width: 680px) 100vw, 640px"<?php endif; ?>
                         onerror="this.onerror=null;this.removeAttribute('srcset');this.src='<?= e($konuGorseli) ?>'">
                </a>
            <?php else: ?>
                <?php /* Her haber resimli (site sahibi, 13.11.2026): gorseli olmayan haberde konu gorseli. */ ?>
                <a class="nw-gorsel nw-gorsel-konu" href="<?= e($detayYol) ?>">
                    <img src="<?= e('/assets/kategori/' . haber_gorsel_konu_adi((string) ($v['kategori_slug'] ?? '')) . '.jpg') ?>" alt="" loading="lazy">
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
            <span>
                <?= nw_tepki_ozet_html($begeni['tepki'][$id] ?? [], $begeniSayi) ?>
                <?php if ($uye !== null && uye_yonetici_mi($uye)): ?>
                    <?php // Yalnizca yonetici gorur: YZ hesaplarindan hemen 8-15 begeni (01.11.2026). ?>
                    <form method="post" action="/network-islem.php" class="nw-yz-begeni">
                        <?= nw_form_alanlari('yz_begeni', $donus, ['hedef' => $anahtar]) ?>
                        <button type="submit" title="YZ hesaplarından bu içeriğe hemen beğeni gönder (yalnızca yönetici görür)">⚡ Beğeni gönder</button>
                    </form>
                <?php endif; ?>
            </span>
            <span>
                <?php if ($yorum['sayi'] > 0): ?><a href="<?= e($detayYol) ?>#yorumlar"><?= $yorum['sayi'] ?> yorum</a><?php endif; ?>
                <?php if ($haber && $okunma > 0): ?><?= $yorum['sayi'] > 0 ? ' · ' : '' ?><?= $okunma ?> kişi okudu<?php endif; ?>
            </span>
        </div>

        <div class="nw-eylemler">
            <?= nw_begen_html($anahtar, $begeniSayi, (string) ($begeni['benim'][$id] ?? ''), $donus) ?>
            <a class="nw-eylem" href="<?= e($detayYol) ?>#yorumlar"><span class="nw-simge" aria-hidden="true">💬</span> Yorum yap</a>
            <?php /* "Haberi oku" gazetenin kendi haberine gider (site sahibi, 13.11.2026:
                     "biz ozetini yazacagiz; haberi oku dedigimizde o gazetenin haberine
                     gitsin"). Kaynak adresi yoksa Valentra'daki habere. */ ?>
            <?php if ($haber && $kaynakUrl !== ''): ?>
                <a class="nw-eylem" href="<?= e($kaynakUrl) ?>" target="_blank" rel="noopener nofollow"><span class="nw-simge" aria-hidden="true">📰</span> Haberi oku<span class="nw-uzun"> ↗</span></a>
            <?php elseif ($haber && !$detay): ?>
                <a class="nw-eylem" href="<?= e($detayYol) ?>"><span class="nw-simge" aria-hidden="true">📰</span> Habere git</a>
            <?php endif; ?>
        </div>

        <?= yorum_bolumu_html($v, $detay ? null : $yorum, $donus, $haber ? 'haber' : 'gonderi', $detayYol) ?>
    </article>
    <?php

    return (string) ob_get_clean();
}

/**
 * Haber yorumu karti (01.11.2026): yorumu yazan uye, yorum, altinda
 * yorumlanan haberin kucuk onizlemesi. Tiklayinca V Network'teki haber
 * sayfasinda o yoruma gidiliyor; yanit ve begeni orada.
 *
 * @param array{tur:string, zaman?:int, veri:array<string,mixed>} $oge
 */
function nw_yorum_kart_html(array $oge): string
{
    $v        = $oge['veri'];
    $haberId  = (int) $v['haber_id'];
    $detay    = nw_yol('h/' . $haberId);
    $yorumYol = $detay . '#yorum-' . (int) $v['id'];
    $zaman    = (string) $v['olusturuldu'];
    $gorsel   = haber_gorsel_kucuk(['id' => $haberId, 'gorsel_url' => (string) ($v['gorsel_url'] ?? '')], 480);

    ob_start();
    ?>
    <article class="nw-gonderi nw-yorum-kart" id="y<?= (int) $v['id'] ?>"<?= !empty($oge['zaman']) ? ' data-zaman="' . (int) $oge['zaman'] . '"' : '' ?>>
        <header class="nw-gonderi-ust">
            <a href="<?= e(nw_profil_yolu((int) $v['uye_id'])) ?>" tabindex="-1" aria-hidden="true"><?= uye_simge_html($v, 40) ?></a>
            <div>
                <a class="nw-hesap" href="<?= e(nw_profil_yolu((int) $v['uye_id'])) ?>"><?= e((string) $v['ad']) ?><?= uye_rozet_html($v) ?></a>
                <span class="nw-meta">bir haberi yorumladı · <a href="<?= e($yorumYol) ?>"><time datetime="<?= e(date('c', (int) strtotime($zaman))) ?>"><?= e(yorum_zaman($zaman)) ?></time></a></span>
            </div>
        </header>

        <div class="nw-metin"><?= yorum_metin_html((string) $v['metin']) ?></div>

        <a class="nw-alinti" href="<?= e($detay) ?>">
            <?php if ($gorsel !== ''): ?>
                <img src="<?= e($gorsel) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">
            <?php endif; ?>
            <span>
                <small>Val<b>Haber</b><?php if (!empty($v['kategori_adi'])): ?> · <?= e((string) $v['kategori_adi']) ?><?php endif; ?></small>
                <strong><?= e((string) $v['baslik']) ?></strong>
            </span>
        </a>

        <div class="nw-eylemler">
            <a class="nw-eylem" href="<?= e($yorumYol) ?>"><span class="nw-simge" aria-hidden="true">💬</span> Tartışmaya katıl</a>
            <a class="nw-eylem" href="<?= e($detay) ?>"><span class="nw-simge" aria-hidden="true">📰</span> Habere git</a>
        </div>
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
        if ($o['tur'] === 'yorum') {
            continue; // yorum karti kendi verisiyle ciziliyor
        }

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
         . '<span><strong>' . e((string) $k['ad']) . uye_rozet_html($k) . '</strong>'
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
