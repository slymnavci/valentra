<?php
declare(strict_types=1);

/**
 * Yorum bolumu: haber sayfasinda ve V Network'te. Butun yorumlar (haber /
 * paylasim sayfasi) ya da akista yorum sayisi ve en yeni iki yorum.
 * Altta yorum kutusu; giris yapmamis ziyaretciye "Yorum yapmak icin giris
 * yapin". Yorumcunun adi ve resmi V Network profiline bagli.
 *
 * Onbellek: giris yapmamis ziyaretcinin sayfasi bir dakika onbellekte
 * (includes/sayfa_onbellek.php); o sayfada form jetonu YOK, yalnizca giris
 * baglantisi. Uyenin sayfasi onbellege girmiyor; form ve "sil" orada.
 */

require_once __DIR__ . '/yorumlar.php';
require_once __DIR__ . '/network.php';

/**
 * @param array<string,mixed>                                 $hedef  haber ya da paylasim (id yeterli)
 * @param array{sayi:int, son:list<array<string,mixed>>}|null $ozet   akista: sayi ve son yorumlar; null = butun yorumlar
 * @param string                                              $donus  gonderimden sonra donulecek site ici adres
 * @param string                                              $tur    'haber' ya da 'gonderi'
 * @param string                                              $tumu   "tumunu gor" baglantisi (akista)
 */
function yorum_bolumu_html(array $hedef, ?array $ozet, string $donus, string $tur = 'haber', string $tumu = ''): string
{
    $hedefId  = (int) $hedef['id'];
    $anahtar  = ($tur === 'gonderi' ? 'g' : 'h') . $hedefId;
    $uye      = aktif_uye();
    $yorumlar = $ozet === null ? yorum_listesi($hedefId, 200, $tur) : $ozet['son'];
    $sayi     = $ozet === null ? count($yorumlar) : $ozet['sayi'];
    $jeton    = $uye !== null ? uye_form_jetonu() : '';
    $sonuc    = (string) ($_GET['yh'] ?? '') === $anahtar ? yorum_sonuc_mesaji((string) ($_GET['yorum'] ?? '')) : null;

    ob_start();
    ?>
    <section class="yorumlar<?= $ozet !== null ? ' yorumlar-ozet' : '' ?>" id="<?= $ozet === null ? 'yorumlar' : 'yorumlar-' . e($anahtar) ?>" aria-label="Yorumlar">
        <?php if ($ozet === null): ?>
            <h2 class="yorum-baslik">Yorumlar<?= $sayi > 0 ? ' <span>(' . $sayi . ')</span>' : '' ?></h2>
        <?php elseif ($sayi > count($yorumlar) && $tumu !== ''): ?>
            <a class="yorum-tumu" href="<?= e($tumu) ?>#yorumlar"><?= $sayi ?> yorumun tümünü gör</a>
        <?php endif; ?>

        <?php if ($sonuc !== null): ?>
            <p class="yorum-sonuc yorum-sonuc-<?= e($sonuc[0]) ?>" role="status"><?= e($sonuc[1]) ?></p>
        <?php endif; ?>

        <?php if ($yorumlar !== []): ?>
            <ul class="yorum-listesi">
                <?php foreach ($yorumlar as $y): ?>
                    <li class="yorum" id="yorum-<?= (int) $y['id'] ?>">
                        <a href="<?= e(nw_profil_yolu((int) $y['uye_id'])) ?>" tabindex="-1" aria-hidden="true"><?= uye_simge_html($y) ?></a>
                        <div class="yorum-govde">
                            <div class="yorum-balon">
                                <a class="yorum-ad" href="<?= e(nw_profil_yolu((int) $y['uye_id'])) ?>"><?= e((string) $y['ad']) ?><?= uye_rozet_html($y) ?></a>
                                <p><?= yorum_metin_html((string) $y['metin']) ?></p>
                            </div>
                            <div class="yorum-alt">
                                <time datetime="<?= e(date('c', (int) strtotime((string) $y['olusturuldu']))) ?>"><?= e(yorum_zaman((string) $y['olusturuldu'])) ?></time>
                                <?php if ($uye !== null): ?>
                                    <form method="post" action="/yorum.php" class="yorum-islem">
                                        <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
                                        <input type="hidden" name="yorum" value="<?= (int) $y['id'] ?>">
                                        <input type="hidden" name="hedef" value="<?= e($anahtar) ?>">
                                        <input type="hidden" name="donus" value="<?= e($donus) ?>">
                                        <?php if ((int) $y['uye_id'] === (int) $uye['id'] || uye_yonetici_mi($uye)): ?>
                                            <button type="submit" name="islem" value="sil" onclick="return confirm('Yorum silinsin mi?')">Sil</button>
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
                <input type="hidden" name="hedef" value="<?= e($anahtar) ?>">
                <input type="hidden" name="donus" value="<?= e($donus) ?>">
                <?= uye_simge_html($uye) ?>
                <label class="gizli-etiket" for="yorum-metin-<?= e($anahtar) ?>">Yorumunuz</label>
                <textarea id="yorum-metin-<?= e($anahtar) ?>" name="metin" rows="1" maxlength="<?= YORUM_EN_FAZLA ?>" required
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
