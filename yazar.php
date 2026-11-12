<?php
declare(strict_types=1);

/**
 * Yazar sayfası: portre, alan, tanıtım ve bütün yazıları (en yenisi önce).
 *
 * Site sahibinin istegi (23.10.2026): okuyucu her yazarin gecmis
 * yazilarina ulasabilsin. Adres /yazar/<anahtar> (temiz adres) ya da
 * yazar.php?y=<anahtar>.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kose.php';
require_once __DIR__ . '/includes/seo.php';

const YAZAR_SAYFA_ADET = 12;

// Kaldirilan yazar karakterlerinin adresi (11.11.2026) yazilarinin baglandigi
// analiz masasina kalici yonleniyor (bkz. KOSE_ESKI_YAZARLAR).
$istenen = (string) ($_GET['y'] ?? '');

if (isset(KOSE_ESKI_YAZARLAR[$istenen])) {
    header('Location: ' . yazar_yolu(KOSE_ESKI_YAZARLAR[$istenen]), true, 301);
    exit;
}

$yazar = yazar_bul($istenen);

if ($yazar === null) {
    http_response_code(404);
    $sayfaBasligi = 'Yazar bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    echo '<div class="bos-durum"><strong>Yazar bulunamadı.</strong> <a href="'
        . e(kose_liste_yolu()) . '">Tüm yazılar &rarr;</a></div>';
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$sayfa = max(1, (int) ($_GET['sayfa'] ?? 1));
$sonuc = kose_yazarin_yazilari($yazar['anahtar'], $sayfa, YAZAR_SAYFA_ADET);
$yazilar  = $sonuc['yazilar'];
$sonSayfa = max(1, (int) ceil($sonuc['toplam'] / YAZAR_SAYFA_ADET));
$gorseller = kose_gorselleri($yazilar);

$aktifKategori = 'kose';
$sayfaBasligi  = $yazar['ad'] . ' — Günlük analiz yazıları' . ($sayfa > 1 ? ' — Sayfa ' . $sayfa : '') . ' | Valentra';
$sayfaAciklama = $yazar['ad'] . ': ' . $yazar['tanitim'];
$seoAdres      = site_adresi() . yazar_yolu($yazar['anahtar'], $sayfa);

// Arsiv sayfalari (2., 3. ...) Google'a acik (08.11.2026, site sahibi:
// "gercek arsiv sayfalarinin taranabilir baglantilari ve kendilerine ait
// canonical adresleri olmali"; Google'in sayfalama onerisi). Canonical
// sayfanin kendisi (sayfa numarasiyla); robots.txt artik ?sayfa= engellemiyor.

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi yazar-basligi">
    <div>
        <h1><?= e($yazar['ad']) ?></h1>
        <p class="yazar-alan"><?= e($yazar['alan']) ?></p>
        <p><?= e($yazar['tanitim']) ?></p>
        <?php // Site sahibinin karari (23.10.2026): not yalnizca yazar sayfasinda, tek satir. ?>
        <p class="yazar-not">Bu bölümdeki yazılar günün haberlerinden yapay zekâ desteğiyle hazırlanır.</p>
    </div>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">
        <?php if ($yazilar === []): ?>
            <div class="bos-durum">
                <strong>Henüz yayımlanmış yazısı yok.</strong>
                Yazıları yayımlandıkça burada listelenecek.
            </div>
        <?php else: ?>
            <div class="bolum-basligi">
                <h2>Yazıları (<?= (int) $sonuc['toplam'] ?>)</h2>
                <span class="cizgi"></span>
            </div>

            <ul class="kose-liste">
                <?php foreach ($yazilar as $yazi): ?>
                    <?php $g = $gorseller[(int) $yazi['id']] ?? null; ?>
                    <li>
                        <a href="<?= e(kose_yolu((string) $yazi['slug'])) ?>" class="<?= $g !== null ? 'gorselli' : '' ?>">
                            <?php if ($g !== null): ?>
                                <img class="kose-kucuk" src="<?= e($g['kucuk']) ?>" alt="<?= e((string) $yazi['baslik']) ?>" loading="lazy"
                                     referrerpolicy="no-referrer"
                                     onerror="this.parentElement.classList.remove('gorselli');this.remove()">
                            <?php endif; ?>
                            <span class="kose-gundem"><?= e(tarih_bicimle((string) $yazi['gun'], false)) ?><?=
                                (string) $yazi['gundem'] !== '' ? ' · ' . e((string) $yazi['gundem']) : '' ?></span>
                            <span class="kose-baslik"><?= e((string) $yazi['baslik']) ?></span>
                            <?php if ((string) $yazi['ozet'] !== ''): ?>
                                <span class="kose-ozet"><?= e((string) $yazi['ozet']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($sonSayfa > 1): ?>
                <nav class="kart-alt" style="justify-content:center;margin:32px 0;gap:14px;" aria-label="Sayfalar">
                    <?php if ($sayfa > 1): ?>
                        <a class="rg-dis" href="<?= e(yazar_yolu($yazar['anahtar'], $sayfa - 1)) ?>">&larr; Daha yeni</a>
                    <?php endif; ?>
                    <span><?= $sayfa ?> / <?= $sonSayfa ?></span>
                    <?php if ($sayfa < $sonSayfa): ?>
                        <a class="rg-dis" href="<?= e(yazar_yolu($yazar['anahtar'], $sayfa + 1)) ?>">Daha eski &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
