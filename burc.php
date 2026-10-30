<?php
declare(strict_types=1);

/**
 * Bir burcun günlük yorumu: genel, aşk, para ve iş, sağlık; şans sayısı ve
 * rengi; diğer burçlar ve son günlerin yorumları (bkz. includes/burclar.php).
 *
 *   /burc/koc            en son gunun yorumu
 *   /burc/koc?gun=Y-m-d  o gunun yorumu (arsiv)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/burclar.php';

$burc = (string) ($_GET['b'] ?? '');

if (!isset(BURCLAR[$burc])) {
    header('Location: ' . burclar_yolu(), true, 302);
    exit;
}

[$ad, $sembol, $tarihAraligi, $element] = BURCLAR[$burc];

$istenen = (string) ($_GET['gun'] ?? '');
$gun     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $istenen) ? $istenen : burc_son_gun();
$yorum   = $gun !== null ? (burc_gun_yorumlari($gun)[$burc] ?? null) : null;
$gecmis  = burc_gecmis($burc, 8);

$aktifKategori = 'burc-' . $burc;
$sayfaBasligi  = $ad . ' Burcu Günlük Yorumu' . ($gun !== null ? ' — ' . burc_tarih($gun) : '') . ' | Valentra';
$sayfaAciklama = $yorum !== null
    ? kisalt((string) $yorum['genel'], 155)
    : $ad . ' burcu günlük yorumu: aşk, para ve iş, sağlık; günün şans sayısı ve rengi.';
$seoAdres      = site_adresi() . burc_yolu($burc, $istenen !== '' ? (string) $gun : '');

// Arsiv gunleri Google'a sunulmuyor: ayni burcun gunluk sayfasi tek adreste.
if ($istenen !== '') {
    $seoRobots = 'noindex, follow';
}

require __DIR__ . '/includes/sayfa_ust.php';
require_once __DIR__ . '/includes/gazeteler.php';
?>

<?= gazete_ust_html('valmagazin', 'burclar') ?>

<div class="ana-duzen">
    <div class="ana-kolon">
        <article class="detay burc-detay">
            <div class="burc-ust">
                <span class="burc-sembol buyuk" aria-hidden="true"><?= $sembol ?></span>
                <div>
                    <h1><?= e($ad) ?> Burcu Günlük Yorumu</h1>
                    <p class="kunye"><?= e($tarihAraligi) ?> &middot; <?= e($element) ?> grubu<?= $gun !== null ? ' &middot; ' . e(burc_tarih($gun)) : '' ?></p>
                </div>
            </div>

            <?php if ($yorum === null): ?>
                <div class="bos-durum">
                    <strong>Bu günün yorumu yok.</strong>
                    Burç yorumları her sabah yayımlanır.
                </div>
            <?php else: ?>
                <?php if ((string) $yorum['baslik'] !== ''): ?>
                    <p class="ozet"><?= e((string) $yorum['baslik']) ?></p>
                <?php endif; ?>

                <div class="icerik">
                    <h2>Genel</h2>
                    <p><?= nl2br(e((string) $yorum['genel'])) ?></p>

                    <?php foreach (['ask' => 'Aşk ve ilişkiler', 'para' => 'Para ve iş', 'saglik' => 'Sağlık'] as $alan => $baslik): ?>
                        <?php if (trim((string) $yorum[$alan]) !== ''): ?>
                            <h2><?= e($baslik) ?></h2>
                            <p><?= nl2br(e((string) $yorum[$alan])) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($yorum['sans_sayisi'] !== null || (string) $yorum['sans_rengi'] !== ''): ?>
                    <dl class="burc-sans">
                        <?php if ($yorum['sans_sayisi'] !== null): ?>
                            <div><dt>Şans sayısı</dt><dd><?= (int) $yorum['sans_sayisi'] ?></dd></div>
                        <?php endif; ?>
                        <?php if ((string) $yorum['sans_rengi'] !== ''): ?>
                            <div><dt>Şans rengi</dt><dd><?= e((string) $yorum['sans_rengi']) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>

                <p class="burc-not"><?= e(BURC_NOTU) ?></p>
            <?php endif; ?>

            <?php if (count($gecmis) > 1): ?>
                <section class="ilgili-haberler" aria-label="Önceki günler">
                    <h2>Önceki günlerin yorumları</h2>
                    <ul>
                        <?php foreach ($gecmis as $g): ?>
                            <?php if ($g['gun'] === $gun) { continue; } ?>
                            <li>
                                <a href="<?= e(burc_yolu($burc, (string) $g['gun'])) ?>"><?= e(burc_tarih((string) $g['gun'])) ?><?= (string) $g['baslik'] !== '' ? ': ' . e((string) $g['baslik']) : '' ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </article>
    </div>

    <aside class="yan-pencere" aria-label="Diğer burçlar">
        <section class="pencere">
            <h2 class="pencere-baslik">Diğer burçlar</h2>
            <ul class="burc-liste">
                <?php foreach (BURCLAR as $anahtar => [$bAd, $bSembol, $bTarih]): ?>
                    <li<?= $anahtar === $burc ? ' class="aktif"' : '' ?>>
                        <a href="<?= e(burc_yolu($anahtar)) ?>"><span aria-hidden="true"><?= $bSembol ?></span> <?= e($bAd) ?> <small><?= e($bTarih) ?></small></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
