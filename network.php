<?php
declare(strict_types=1);

/**
 * VALENTRA NETWORK (31.10.2026).
 *
 * Site sahibinin istegi: "Valentra Network'umuz olsun; guncel haberler
 * sirayla dussun, en son gelen haber en ustte kalsin; Facebook ve
 * Instagram'da oldugu gibi haberlerin altina kullanicilar yorum
 * yapabilsin; mesajlar ValHaber'den gelsin; ust menude en sagda VALENTRA
 * Network yazsin ve buradan giris yapalim".
 *
 * Akis: yayindaki haberler yayin zamanina gore, en yeni en ustte (ana
 * sayfanin onem sirasi degil). Her haber ValHaber hesabinin paylasimi
 * gibi: baslik, ozet, gorsel; altinda yorum sayisi, en yeni iki yorum ve
 * yorum kutusu. Butun yorumlar haber sayfasinda (#yorumlar).
 *
 * Giris yapmamis ziyaretcinin sayfasi bir dakika onbellekte; uyeninki
 * degil (bkz. includes/sayfa_onbellek.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

sayfa_onbellek(['sayfa']);

require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/yorum_bolumu.php';

/** Akistaki gonderi sayisi (sayfa basina). */
const NETWORK_SAYFA_ADEDI = 15;

/** Yemek tarifleri haber degil; akista yok. */
function network_akisi(int $adet, int $atla): array
{
    $ifade = db()->prepare(
        "SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.iframe_url, h.yayin_tarihi, h.olusturuldu,
                k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d AND (k.slug IS NULL OR k.slug <> 'yemek-tarifleri')
          ORDER BY h.yayin_tarihi DESC, h.id DESC
          LIMIT :l OFFSET :a"
    );
    $ifade->bindValue('d', HABER_YAYINDA);
    $ifade->bindValue('l', $adet, PDO::PARAM_INT);
    $ifade->bindValue('a', $atla, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

$sayfa    = max(1, min(50, (int) ($_GET['sayfa'] ?? 1)));
$gonderiler = network_akisi(NETWORK_SAYFA_ADEDI + 1, ($sayfa - 1) * NETWORK_SAYFA_ADEDI);
$devamVar = count($gonderiler) > NETWORK_SAYFA_ADEDI;
$gonderiler = array_slice($gonderiler, 0, NETWORK_SAYFA_ADEDI);
$ozetler  = yorum_ozetleri(array_map(static fn (array $h): int => (int) $h['id'], $gonderiler));
$uye      = aktif_uye();

$aktifKategori = 'network';
$sayfaBasligi  = 'Valentra Network — Gündemi Birlikte Konuşalım';
$sayfaAciklama = 'Valentra Network: en son haberler sırayla akıyor; üye olun, haberlere yorum yapın, gündemi tartışın.';
$seoAdres      = site_adresi() . network_yolu($sayfa);

if ($sayfa > 1) {
    $seoRobots = 'noindex, follow';
}

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="nw-duzen">
    <div class="nw-akis">
        <header class="nw-ust">
            <h1 class="nw-marka"><span class="nw-val">VALENTRA</span> Network</h1>
            <p>En son haberler en üstte. Okuyun, yorumlayın, gündemi birlikte konuşalım.</p>
        </header>

        <?php if ($gonderiler === []): ?>
            <div class="bos-durum"><strong>Henüz paylaşım yok.</strong> Yeni haberler geldikçe burada akacak.</div>
        <?php endif; ?>

        <?php foreach ($gonderiler as $h): ?>
            <?php
            $yol    = haber_yolu((string) $h['slug']);
            $gorsel = haber_gorsel($h);
            $zaman  = (string) ($h['yayin_tarihi'] ?: $h['olusturuldu']);
            ?>
            <article class="nw-gonderi" id="g<?= (int) $h['id'] ?>">
                <header class="nw-gonderi-ust">
                    <span class="nw-hesap-simge" aria-hidden="true"><img src="/assets/logo.svg" alt="" width="24" height="19"></span>
                    <div>
                        <strong class="nw-hesap">Val<span>Haber</span></strong>
                        <span class="nw-meta">
                            <time datetime="<?= e(date('c', (int) strtotime($zaman))) ?>"><?= e(yorum_zaman($zaman)) ?></time>
                            <?php if (!empty($h['kategori_adi'])): ?> · <?= e((string) $h['kategori_adi']) ?><?php endif; ?>
                        </span>
                    </div>
                </header>

                <a class="nw-icerik" href="<?= e($yol) ?>">
                    <h2><?= e((string) $h['baslik']) ?></h2>
                    <?php if ((string) $h['ozet'] !== ''): ?>
                        <p><?= e(kisalt((string) $h['ozet'], 240)) ?></p>
                    <?php endif; ?>
                    <?php if ($gorsel !== ''): ?>
                        <?php $set = haber_gorsel_srcset($h); ?>
                        <img src="<?= e(haber_gorsel_kucuk($h, 960)) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"
                             <?php if ($set !== ''): ?>srcset="<?= e($set) ?>" sizes="(max-width: 680px) 100vw, 640px"<?php endif; ?>
                             onerror="this.remove()">
                    <?php endif; ?>
                </a>

                <div class="nw-eylemler">
                    <a href="<?= e($yol) ?>#yorumlar">💬 <?= $ozetler[(int) $h['id']]['sayi'] > 0 ? $ozetler[(int) $h['id']]['sayi'] . ' yorum' : 'Yorum yap' ?></a>
                    <a href="<?= e($yol) ?>">Haberin tamamı →</a>
                </div>

                <?= yorum_bolumu_html($h, $ozetler[(int) $h['id']], network_yolu($sayfa) . '#g' . (int) $h['id']) ?>
            </article>
        <?php endforeach; ?>

        <nav class="nw-sayfalar">
            <?php if ($sayfa > 1): ?><a href="<?= e(network_yolu($sayfa - 1)) ?>">← Daha yeni</a><?php endif; ?>
            <?php if ($devamVar): ?><a href="<?= e(network_yolu($sayfa + 1)) ?>">Daha eski paylaşımlar →</a><?php endif; ?>
        </nav>
    </div>

    <aside class="nw-yan">
        <div class="nw-yan-kutu">
            <?php if ($uye !== null): ?>
                <div class="nw-uye">
                    <span class="uye-simge" style="--uye-renk: <?= e(uye_renk((string) $uye['ad'])) ?>" aria-hidden="true"><?= e(uye_bas_harf((string) $uye['ad'])) ?></span>
                    <div><strong><?= e((string) $uye['ad']) ?></strong><span>Valentra Network üyesi</span></div>
                </div>
                <form method="post" action="<?= e(uye_yolu('cikis')) ?>">
                    <input type="hidden" name="jeton" value="<?= e(uye_form_jetonu()) ?>">
                    <input type="hidden" name="donus" value="<?= e(network_yolu()) ?>">
                    <button type="submit" class="uye-dugme uye-dugme-ikincil">Çıkış yap</button>
                </form>
            <?php else: ?>
                <strong>Gündemi konuşmaya katılın</strong>
                <p>Haberlere yorum yapmak için ücretsiz üye olun.</p>
                <a class="uye-dugme" href="<?= e(uye_yolu('kayit', network_yolu())) ?>">Üye ol</a>
                <a class="uye-dugme uye-dugme-ikincil" href="<?= e(uye_yolu('giris', network_yolu())) ?>">Giriş yap</a>
            <?php endif; ?>
        </div>
        <div class="nw-yan-kutu nw-kurallar">
            <strong>Yorum kuralları</strong>
            <ul>
                <li>Saygılı bir dil kullanın; küfür ve hakaret içeren yorumlar yayımlanmaz.</li>
                <li>Kişilere ve kurumlara dayanaksız suçlama yapmayın.</li>
                <li>Uygunsuz bir yorumu “Şikâyet et” ile bildirin; birden çok bildirim alan yorum gizlenir.</li>
            </ul>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
