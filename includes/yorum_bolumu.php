<?php
declare(strict_types=1);

/**
 * Yorum bolumu: haber sayfasinda butun yorumlar, Valentra Network akisinda
 * yorum sayisi ve en yeni iki yorum. Ikisinde de altta yorum kutusu
 * (giris yapmamis ziyaretciye "Yorum yapmak icin giris yapin").
 *
 * Onbellek: giris yapmamis ziyaretcinin sayfasi bir dakika onbellekte
 * (includes/sayfa_onbellek.php); o sayfada form jetonu YOK, yalnizca giris
 * baglantisi. Uyenin sayfasi onbellege girmiyor; form ve "sil" orada.
 */

require_once __DIR__ . '/yorumlar.php';

/**
 * @param array<string,mixed>                              $haber  id ve slug yeterli
 * @param array{sayi:int, son:list<array<string,mixed>>}|null $ozet   akista: sayi ve son yorumlar; null = butun yorumlar
 * @param string                                           $donus  gonderimden sonra donulecek site ici adres
 */
function yorum_bolumu_html(array $haber, ?array $ozet, string $donus): string
{
    $haberId  = (int) $haber['id'];
    $uye      = aktif_uye();
    $yorumlar = $ozet === null ? yorum_listesi($haberId) : $ozet['son'];
    $sayi     = $ozet === null ? count($yorumlar) : $ozet['sayi'];
    $haberYol = haber_yolu((string) $haber['slug']);
    $jeton    = $uye !== null ? uye_form_jetonu() : '';
    $sonuc    = (int) ($_GET['yh'] ?? 0) === $haberId ? yorum_sonuc_mesaji((string) ($_GET['yorum'] ?? '')) : null;

    ob_start();
    ?>
    <section class="yorumlar<?= $ozet !== null ? ' yorumlar-ozet' : '' ?>" id="<?= $ozet === null ? 'yorumlar' : 'yorumlar-' . $haberId ?>" aria-label="Yorumlar">
        <?php if ($ozet === null): ?>
            <h2 class="yorum-baslik">Yorumlar<?= $sayi > 0 ? ' <span>(' . $sayi . ')</span>' : '' ?></h2>
        <?php elseif ($sayi > count($yorumlar)): ?>
            <a class="yorum-tumu" href="<?= e($haberYol) ?>#yorumlar"><?= $sayi ?> yorumun tümünü gör</a>
        <?php endif; ?>

        <?php if ($sonuc !== null): ?>
            <p class="yorum-sonuc yorum-sonuc-<?= e($sonuc[0]) ?>" role="status"><?= e($sonuc[1]) ?></p>
        <?php endif; ?>

        <?php if ($yorumlar !== []): ?>
            <ul class="yorum-listesi">
                <?php foreach ($yorumlar as $y): ?>
                    <li class="yorum" id="yorum-<?= (int) $y['id'] ?>">
                        <span class="uye-simge" style="--uye-renk: <?= e(uye_renk((string) $y['ad'])) ?>" aria-hidden="true"><?= e(uye_bas_harf((string) $y['ad'])) ?></span>
                        <div class="yorum-govde">
                            <div class="yorum-balon">
                                <strong class="yorum-ad"><?= e((string) $y['ad']) ?></strong>
                                <p><?= yorum_metin_html((string) $y['metin']) ?></p>
                            </div>
                            <div class="yorum-alt">
                                <time datetime="<?= e(date('c', (int) strtotime((string) $y['olusturuldu']))) ?>"><?= e(yorum_zaman((string) $y['olusturuldu'])) ?></time>
                                <?php if ($uye !== null): ?>
                                    <form method="post" action="/yorum.php" class="yorum-islem">
                                        <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
                                        <input type="hidden" name="yorum" value="<?= (int) $y['id'] ?>">
                                        <input type="hidden" name="haber" value="<?= $haberId ?>">
                                        <input type="hidden" name="donus" value="<?= e($donus) ?>">
                                        <?php if ((int) $y['uye_id'] === (int) $uye['id']): ?>
                                            <button type="submit" name="islem" value="sil" onclick="return confirm('Yorumunuz silinsin mi?')">Sil</button>
                                        <?php else: ?>
                                            <button type="submit" name="islem" value="sikayet" onclick="return confirm('Bu yorumu uygunsuz diye bildirmek istiyor musunuz?')">Şikâyet et</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ($ozet === null): ?>
            <p class="yorum-bos">Henüz yorum yok. İlk yorumu siz yazın.</p>
        <?php endif; ?>

        <?php if ($uye !== null): ?>
            <form method="post" action="/yorum.php" class="yorum-yaz">
                <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
                <input type="hidden" name="islem" value="ekle">
                <input type="hidden" name="haber" value="<?= $haberId ?>">
                <input type="hidden" name="donus" value="<?= e($donus) ?>">
                <span class="uye-simge" style="--uye-renk: <?= e(uye_renk((string) $uye['ad'])) ?>" aria-hidden="true"><?= e(uye_bas_harf((string) $uye['ad'])) ?></span>
                <label class="gizli-etiket" for="yorum-metin-<?= $haberId ?>">Yorumunuz</label>
                <textarea id="yorum-metin-<?= $haberId ?>" name="metin" rows="1" maxlength="<?= YORUM_EN_FAZLA ?>" required
                          placeholder="Yorum yazın…"></textarea>
                <button type="submit">Gönder</button>
            </form>
        <?php else: ?>
            <p class="yorum-giris">
                Yorum yapmak için <a href="<?= e(uye_yolu('giris', $donus)) ?>">giriş yapın</a>
                ya da <a href="<?= e(uye_yolu('kayit', $donus)) ?>">ücretsiz üye olun</a>.
            </p>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}
