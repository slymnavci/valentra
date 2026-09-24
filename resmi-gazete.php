<?php
declare(strict_types=1);

/**
 * Resmî Gazete sayfası: günün sayısının özeti ve Resmî Gazete haberleri.
 *
 * Ozet resmi fihristten kuruluyor: bolum sayilari ve maddelerin
 * basliklari AYNEN. Model yazmiyor; basligi yanlis ozetlenmis bir
 * yonetmelik burada olmaz. Her madde Resmi Gazete'deki asil metne
 * gidiyor; ajan o maddeden haber yazdiysa yaninda haberin bagi var.
 *
 * Resmi Gazete kaynakli haberler ana sayfa akisinda degil, burada
 * (bkz. HABER_RG_KOSULU).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/resmi_gazete.php';
require_once __DIR__ . '/includes/seo.php';

$istenen = (string) ($_GET['tarih'] ?? '');

if ($istenen !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $istenen)) {
    $istenen = '';
}

$bugun = (new DateTimeImmutable('now', rg_saat_dilimi()))->format('Y-m-d');

// Gecmis bir gunun sayisi degismez; yalnizca bugune bakilirken tazeleniyor.
if ($istenen === '' || $istenen === $bugun) {
    rg_gerekirse_tazele();
}

$gunler   = rg_gunler(14);
$tarih    = $istenen !== '' ? $istenen : ($gunler[0] ?? '');
$maddeler = $tarih !== '' ? rg_gun_maddeleri($tarih) : [];
$bulunamadi = $istenen !== '' && $maddeler === [];

if ($bulunamadi) {
    http_response_code(404);
}

$haberEslesme = rg_madde_haberleri($maddeler);
$sonHaberler  = rg_haberleri(8);
$bolumSayilari = rg_bolum_sayilari($maddeler);

$sayi          = null;
$mukerrerAdet  = 0;

foreach ($maddeler as $m) {
    if ((int) $m['mukerrer'] === 0 && $m['sayi'] !== null && $sayi === null) {
        $sayi = (int) $m['sayi'];
    }

    $mukerrerAdet = max($mukerrerAdet, (int) $m['mukerrer']);
}

$gunAdlari = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

$uzunTarih = static function (string $t) use ($gunAdlari): string {
    $zaman = strtotime($t);

    return $zaman === false ? $t : tarih_bicimle($t, false) . ' ' . $gunAdlari[(int) date('w', $zaman)];
};

$kisaTarih = static function (string $t): string {
    $zaman = strtotime($t);

    return $zaman === false ? $t : date('d.m', $zaman);
};

$aktifKategori = 'resmi-gazete';
$seoAdres      = site_adresi() . rg_yolu($istenen);
$sayfaBasligi  = $tarih !== '' && !$bulunamadi
    ? 'Resmî Gazete ' . tarih_bicimle($tarih, false) . ' — Valentra'
    : 'Resmî Gazete — Valentra';
$sayfaAciklama = 'Günün Resmî Gazete sayısındaki yönetmelik, tebliğ ve kararların '
               . 'listesi; asıl metinlerin bağlantıları ve Valentra haberleri.';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1>Resmî Gazete</h1>
    <p>
        Günün sayısındaki düzenlemeler, Resmî Gazete fihristindeki başlıklarıyla.
        Başlığa tıklayınca asıl metin açılır.
    </p>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">

        <?php if ($gunler !== []): ?>
            <nav class="rg-gunler" aria-label="Önceki sayılar">
                <?php foreach ($gunler as $gun): ?>
                    <a class="<?= $gun === $tarih ? 'aktif' : '' ?>"
                       href="<?= e(rg_yolu($gun === $gunler[0] ? '' : $gun)) ?>"
                       <?= $gun === $tarih ? 'aria-current="page"' : '' ?>>
                        <?= e($gun === $bugun ? 'Bugün' : $kisaTarih($gun)) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($maddeler === []): ?>
            <div class="bos-durum">
                <?php if ($bulunamadi): ?>
                    <strong><?= e($uzunTarih($istenen)) ?> sayısı burada yok.</strong>
                    Son iki haftanın sayıları tutuluyor. Daha eski sayılar için
                <?php else: ?>
                    <strong>Resmî Gazete henüz alınamadı.</strong>
                    Günün sayısı birkaç dakika içinde burada olacak. Beklemek istemezseniz
                <?php endif; ?>
                <a class="rg-dis" href="https://www.resmigazete.gov.tr/" target="_blank"
                   rel="noopener">resmigazete.gov.tr</a>.
            </div>
        <?php else: ?>

            <section class="rg-kunye">
                <div class="rg-kunye-ust">
                    <span class="rg-tarih"><?= e($uzunTarih($tarih)) ?></span>
                    <?php if ($sayi !== null): ?>
                        <span class="rg-sayi">Sayı <?= e((string) $sayi) ?></span>
                    <?php endif; ?>
                    <?php if ($mukerrerAdet > 0): ?>
                        <span class="rg-sayi">+ <?= e((string) $mukerrerAdet) ?> mükerrer</span>
                    <?php endif; ?>
                </div>

                <?php if ($tarih !== $bugun && $istenen === ''): ?>
                    <p class="rg-uyari">
                        Bugünün sayısı henüz alınamadı; son alınan sayı gösteriliyor.
                    </p>
                <?php endif; ?>

                <p class="rg-ozet">
                    Bu sayıda <strong><?= e((string) count($maddeler)) ?></strong> düzenleme var<?php
                    if (count($haberEslesme) > 0): ?>; <strong><?= e((string) count($haberEslesme)) ?></strong>
                    tanesi için Valentra haberi yazıldı<?php endif; ?>.
                </p>

                <ul class="rg-bolum-sayilari">
                    <?php foreach ($bolumSayilari as $bolum => $adet): ?>
                        <li><span><?= e((string) $bolum) ?></span> <strong><?= e((string) $adet) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <?php
            /*
             * Liste fihristin kendi duzeninde: once ust bolum (Yurutme ve
             * Idare, Yargi ...), onun icinde bolum (Yonetmelikler,
             * Tebligler ...). Mukerrer sayilar sonda, kendi basliklariyla.
             * Basliklar yalnizca degistiklerinde basiliyor.
             */
            $oncekiUst   = null;
            $oncekiBolum = null;
            $listeAcik   = false;
            ?>
            <?php foreach ($maddeler as $m): ?>
                <?php
                $ust   = ((int) $m['mukerrer'] > 0 ? 'Mükerrer ' . (int) $m['mukerrer'] . ' · ' : '')
                       . ((string) $m['ust_bolum'] !== '' ? (string) $m['ust_bolum'] : 'Resmî Gazete');
                $bolum = (string) $m['bolum'] !== '' ? (string) $m['bolum'] : 'Diğer';
                $url   = guvenli_url((string) $m['url']);
                $haber = $haberEslesme[(string) $m['url']] ?? null;
                ?>

                <?php if ($ust !== $oncekiUst || $bolum !== $oncekiBolum): ?>
                    <?php if ($listeAcik): ?></ul><?php endif; ?>

                    <?php if ($ust !== $oncekiUst): ?>
                        <div class="bolum-basligi">
                            <h2><?= e($ust) ?></h2>
                            <span class="cizgi"></span>
                        </div>
                    <?php endif; ?>

                    <h3 class="rg-bolum"><?= e($bolum) ?></h3>
                    <ul class="rg-liste">
                    <?php
                    $oncekiUst   = $ust;
                    $oncekiBolum = $bolum;
                    $listeAcik   = true;
                    ?>
                <?php endif; ?>

                <li>
                    <?php if ($url !== ''): ?>
                        <a class="rg-madde" href="<?= e($url) ?>" target="_blank" rel="noopener">
                            <?= e((string) $m['baslik']) ?>
                            <?php if (preg_match('#\.pdf$#i', $url)): ?>
                                <span class="rg-rozet">PDF</span>
                            <?php endif; ?>
                        </a>
                    <?php else: ?>
                        <span class="rg-madde"><?= e((string) $m['baslik']) ?></span>
                    <?php endif; ?>

                    <?php if ($haber !== null): ?>
                        <a class="rg-haber" href="<?= e(haber_yolu((string) $haber['slug'])) ?>">
                            Valentra haberi: <?= e((string) $haber['baslik']) ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if ($listeAcik): ?></ul><?php endif; ?>

            <p class="ipucu rg-not">
                Başlıklar Resmî Gazete fihristinden aynen alınmıştır. Üniversite
                yönetmelikleri ve ilan bölümü listelenmez. Bağlayıcı olan
                Resmî Gazete'de yayımlanan metindir.
            </p>
        <?php endif; ?>

        <?php if ($sonHaberler !== []): ?>
            <div class="bolum-basligi">
                <h2>Resmî Gazete'den haberler</h2>
                <span class="cizgi"></span>
            </div>

            <?php $izgaraHaberleri = $sonHaberler; ?>
            <?php require __DIR__ . '/includes/kart_izgara.php'; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
