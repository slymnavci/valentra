<?php
declare(strict_types=1);

/** Haber kartlari izgarasi. $izgaraHaberleri dizisini alir. */

if (($izgaraHaberleri ?? []) === []) {
    return;
}
?>
<section class="kart-izgara">
    <?php foreach ($izgaraHaberleri as $haber): ?>
        <?php $kartGorsel = haber_gorsel($haber); ?>
        <article class="kart <?= $kartGorsel === '' ? 'yazili' : '' ?>">
            <a href="<?= e(haber_yolu((string) $haber['slug'])) ?>">
                <?php if ($kartGorsel !== ''): ?>
                    <img class="gorsel" src="<?= e($kartGorsel) ?>" alt="" loading="lazy"
                         referrerpolicy="no-referrer"
                         onerror="this.closest('.kart').classList.add('yazili');this.remove()">
                <?php endif; ?>
                <div class="govde">
                    <?php if (haber_video_adresi((string) ($haber['iframe_url'] ?? '')) !== ''): ?>
                        <span class="video-rozet" title="Videolu haber">&#9654; Video</span>
                    <?php endif; ?>
                    <?php if (!empty($haber['kategori_adi'])): ?>
                        <span class="etiket"><?= e($haber['kategori_adi']) ?></span>
                    <?php else: ?>
                        <?php $etiketler = etiketleri_coz($haber['etiketler']); ?>
                        <?php if ($etiketler !== []): ?>
                            <span class="etiket">#<?= e($etiketler[0]) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <h3><?= e($haber['baslik']) ?></h3>
                    <p class="ozet"><?= e(kisalt($haber['ozet'], 120)) ?></p>

                    <div class="kart-alt">
                        <span><?= e(tarih_bicimle($haber['yayin_tarihi'])) ?></span>
                        <?php if ($haber['kaynak_adi'] !== ''): ?>
                            <span>&middot; <?= e(kaynak_gorunen_ad((string) $haber['kaynak_adi'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </article>
    <?php endforeach; ?>
</section>
