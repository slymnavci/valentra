<?php
declare(strict_types=1);

/**
 * Günlük burç yorumları: 12 burç, bugünün yorumları.
 *
 * Site sahibinin istegi (29.10.2026): menude ana baslik "Burclar"; her gun
 * yapay zekanin yazdigi yorumlar (bkz. includes/burclar.php). Bugunun
 * yorumu henuz yoksa en son gunun yorumlari gosteriliyor.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/burclar.php';

$gun      = burc_son_gun();
$yorumlar = $gun !== null ? burc_gun_yorumlari($gun) : [];

$aktifKategori = 'burclar';
$sayfaBasligi  = 'Günlük Burç Yorumları' . ($gun !== null ? ' — ' . burc_tarih($gun) : '') . ' | Valentra';
$sayfaAciklama = 'Koç, Boğa, İkizler, Yengeç, Aslan, Başak, Terazi, Akrep, Yay, Oğlak, Kova ve Balık burçları için '
               . 'günlük burç yorumları: genel, aşk, para ve iş, sağlık; günün şans sayısı ve rengi.';
$seoAdres      = site_adresi() . burclar_yolu();

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Günlük Burç Yorumları</h1>
    <p>
        <?= $gun !== null ? e(burc_tarih($gun)) . ' için ' : '' ?>12 burcun günlük yorumu: genel durum, aşk,
        para ve iş, sağlık; günün şans sayısı ve rengi. Burcunuzu seçin.
    </p>
</div>

<div class="ana-duzen tam">
    <div class="ana-kolon">
        <?php if ($yorumlar === []): ?>
            <div class="bos-durum">
                <strong>Bugünün burç yorumları hazırlanıyor.</strong>
                Yorumlar her sabah burada yayımlanır.
            </div>
        <?php else: ?>
            <ul class="burc-izgara">
                <?php foreach (BURCLAR as $anahtar => [$ad, $sembol, $tarih]): ?>
                    <?php $y = $yorumlar[$anahtar] ?? null; ?>
                    <li>
                        <a href="<?= e(burc_yolu($anahtar)) ?>">
                            <span class="burc-sembol" aria-hidden="true"><?= $sembol ?></span>
                            <span class="burc-ad"><?= e($ad) ?></span>
                            <span class="burc-tarih"><?= e($tarih) ?></span>
                            <?php if ($y !== null): ?>
                                <span class="burc-baslik"><?= e((string) $y['baslik']) ?></span>
                                <span class="burc-ozet"><?= e(kisalt((string) $y['genel'], 150)) ?></span>
                            <?php endif; ?>
                            <span class="burc-devam">Yorumun tamamı &rarr;</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="burc-not"><?= e(BURC_NOTU) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
