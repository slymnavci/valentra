<?php
declare(strict_types=1);

/**
 * Günün Kelimesi oyunu (bkz. includes/kelime_oyunu.php, api/kelime.php).
 *
 * Sayfa yalnizca oyun alanini ciziyor; cevap sayfada yok, tahminleri
 * assets/kelime.js api/kelime.php'ye soruyor. Oyuncunun o gunku
 * tahminleri ve istatistigi tarayicida (localStorage).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/kelime_oyunu.php';

$oyun = kelime_bugun();
$gunYazi = date('d.m.Y', (int) strtotime($oyun['gun']));

$aktifKategori = 'kelime';
$sayfaBasligi  = 'Günün Kelimesi — gündemden günlük kelime oyunu | Valentra';
$sayfaAciklama = 'Her gün gündemden seçilen ' . KELIME_UZUNLUK . ' harfli kelimeyi ' . KELIME_HAK
               . ' tahminde bulun. Türkçe günlük kelime bulmacası; bitince kelimenin geçtiği haberi okuyun.';
$seoAdres      = site_adresi() . kelime_oyunu_yolu();

require __DIR__ . '/includes/sayfa_ust.php';
require_once __DIR__ . '/includes/gazeteler.php';
?>

<?= gazete_ust_html('valmagazin', 'kelime') ?>

<div class="kategori-basligi">
    <h1>Günün Kelimesi</h1>
    <p>Gündemden seçilen <?= KELIME_UZUNLUK ?> harfli kelimeyi <?= KELIME_HAK ?> tahminde bulun. Her gün yeni kelime.</p>
</div>

<div class="ana-duzen tam">
    <div class="ana-kolon">
        <section class="kelime-oyun" id="kelimeOyun"
                 data-gun="<?= e($oyun['gun']) ?>" data-gun-yazi="<?= e($gunYazi) ?>"
                 data-uzunluk="<?= KELIME_UZUNLUK ?>" data-hak="<?= KELIME_HAK ?>"
                 data-api="/api/kelime.php" aria-label="Günün Kelimesi, <?= e($gunYazi) ?>">
            <div class="kelime-izgara" id="kelimeIzgara" role="grid" aria-label="Tahminler"></div>
            <p class="kelime-mesaj" id="kelimeMesaj" role="status" aria-live="polite"></p>

            <div class="kelime-sonuc" id="kelimeSonuc" hidden>
                <p class="kelime-sonuc-baslik" id="kelimeSonucBaslik"></p>
                <p class="kelime-haber" id="kelimeHaber" hidden>Bu kelime bugün şu haberde geçiyor:
                    <a id="kelimeHaberBag" href="#"></a></p>
                <div class="kelime-paylas">
                    <button type="button" class="dugme-ana" id="kelimePaylas">Sonucu paylaş</button>
                    <a class="kelime-wa" id="kelimeWhatsapp" href="#" target="_blank" rel="noopener">WhatsApp'ta paylaş</a>
                </div>
                <dl class="kelime-istatistik" id="kelimeIstatistik"></dl>
                <p class="ipucu">Yeni kelime yarın sabah burada.</p>
            </div>

            <div class="kelime-klavye" id="kelimeKlavye" aria-label="Klavye"></div>
        </section>

        <noscript><div class="bos-durum">Oyunu oynamak için tarayıcınızda JavaScript açık olmalı.</div></noscript>

        <section class="kelime-nasil">
            <h2>Nasıl oynanır?</h2>
            <p>Bir kelime yazıp Enter'a basın. Her tahminden sonra kutuların rengi kelimeye ne kadar yaklaştığınızı gösterir:</p>
            <ul>
                <li><span class="kelime-ornek k2">A</span> Harf kelimede var ve yeri doğru.</li>
                <li><span class="kelime-ornek k1">L</span> Harf kelimede var ama başka yerde.</li>
                <li><span class="kelime-ornek k0">T</span> Harf kelimede yok.</li>
            </ul>
            <p>Kelime her gün o günün haberlerinden seçilir; bulduğunuzda ya da haklarınız bittiğinde kelimenin geçtiği haberi okuyabilirsiniz.</p>
        </section>
    </div>
</div>

<script src="<?= e(varlik('/assets/kelime.js')) ?>" defer></script>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
