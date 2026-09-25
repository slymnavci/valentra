<?php
declare(strict_types=1);

/**
 * Ana sayfa ilk ekran: "Bugün bilmeniz gerekenler" + kısayollar.
 *
 * Mansetin hemen altinda. Kisayollar (takvim, KDV hesabi, pratik
 * bilgiler) okuyucunun siteye en sik geri donme sebebi; menude bir
 * acilir listenin icinde kalmasinlar diye burada da duruyorlar.
 *
 * $bugunMaddeleri'ni cagiran doldurur (index.php).
 */

$bugunMaddeleri = $bugunMaddeleri ?? [];
?>
<section class="bugun-bant <?= $bugunMaddeleri === [] ? 'yalniz-kisayol' : '' ?>" aria-label="Bugün">
    <?php if ($bugunMaddeleri !== []): ?>
        <div class="bugun-liste">
            <h2 class="bugun-baslik">Bugün bilmeniz gerekenler</h2>

            <ul>
                <?php foreach ($bugunMaddeleri as $madde): ?>
                    <li class="<?= $madde['vurgu'] ? 'vurgu' : '' ?>">
                        <a href="<?= e($madde['href']) ?>">
                            <span class="bugun-etiket"><?= e($madde['etiket']) ?></span>
                            <span class="bugun-metin"><?= e($madde['metin']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <nav class="kisayollar" aria-label="Kısayollar">
        <a href="<?= e(takvim_yolu()) ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
                <rect x="3.5" y="5" width="17" height="15.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/>
                <path d="M3.5 9.5h17M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
            <span><strong>Vergi Takvimi</strong><small>Beyan ve ödeme günleri</small></span>
        </a>
        <a href="<?= e(araclar_yolu()) ?>#kdv">
            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
                <rect x="5" y="3" width="14" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/>
                <path d="M8 7h8M8.5 12h1M11.5 12h1M14.5 12h1M8.5 16h1M11.5 16h1M14.5 16h1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
            <span><strong>KDV Hesapla</strong><small>Dahil / hariç, vade farkı</small></span>
        </a>
        <a href="<?= e(pratik_yolu()) ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 3.5h9l3.5 3.5v13.5H6z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                <path d="M9 11h6M9 14.5h6M9 18h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
            <span><strong>Pratik Bilgiler</strong><small>Oranlar ve tutarlar</small></span>
        </a>
    </nav>
</section>
