<?php
declare(strict_types=1);

/**
 * V NETWORK (31.10.2026).
 *
 * Site sahibinin istekleri:
 *   - "Valentra Network'umuz olsun; guncel haberler sirayla dussun, en son
 *     gelen en ustte; Facebook ve Instagram'daki gibi altina yorum
 *     yapilsin; mesajlar ValHaber'den gelsin."
 *   - "Biraz daha Facebook tarzi olsun: insanlar tanisabilsin, sohbet
 *     edebilsin, yorum yapabilsin, haberlerin altina yazilar yazabilsin;
 *     kufur vs yasak, bunu bildiren bir yazi olsun; tikladigimizda farkli
 *     bir sayfaya gidelim; baska insanlari takip edelim, takipci kazanalim."
 *   - "Habere tikladigimizda altinda kim ne yorum yapmis, kac kisi okudu,
 *     kac kisi begendi gorelim; haberin tamami altta kalsin, geri tusuyla
 *     Network'e donulsun."
 *   - "Profil resmi, fotograf eklenebilsin; video linklenebilsin ama
 *     yuklenmesin; fotograflarda sinir olsun."
 *   - "Videolu haber ekrana gelince kendiliginden calissin, asagi
 *     kaydirinca dursun."
 *
 * Adresler (.htaccess: /network/... -> network.php?yol=...):
 *   /network                    akis (?sekme=takip: takip ettiklerim)
 *   /network/h/12               haber: ozet, begeni, okunma, butun yorumlar
 *   /network/g/5                uye paylasimi ve butun yorumlari
 *   /network/u/3                profil (?sekme=takipciler|takip)
 *   /network/kisiler            kisiler (?ara=, ?sira=yeni)
 *   /network/mesajlar[/3]       mesajlar (uye)
 *   /network/ayarlar            profili duzenle (uye)
 *   /network/kurallar           topluluk kurallari
 *
 * Sayfalar arama motoruna kapali (uye adlari ve paylasimlari kisisel;
 * haberlerin asli sitede). Giris yapmamis ziyaretcinin sayfasi bir
 * dakika onbellekte, uyeninki degil (includes/sayfa_onbellek.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sayfa_onbellek.php';

sayfa_onbellek(['yol', 'sekme', 'once', 'ara', 'sira']);

require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/network_gorunum.php';

const NW_AKIS_ADEDI = 15;

$yol   = trim((string) ($_GET['yol'] ?? ''), '/');
$uye   = aktif_uye();
$uyeId = $uye !== null ? (int) $uye['id'] : null;
$bildirim = nw_bildirim_html();

/** 404: Network icinde bulunamayan sayfa. */
function nw_bulunamadi(): never
{
    global $networkSayfasi, $nwAktif, $sayfaBasligi, $sayfaAciklama, $seoRobots, $aktifKategori, $govdeSinifi;

    http_response_code(404);
    $networkSayfasi = true;
    $nwAktif        = '';
    $aktifKategori  = 'network';
    $govdeSinifi    = 'nw-govde';
    $sayfaBasligi   = 'Sayfa bulunamadı — V Network';
    $sayfaAciklama  = 'Aradığınız sayfa bulunamadı.';
    $seoRobots      = 'noindex, nofollow';
    require __DIR__ . '/includes/sayfa_ust.php';
    echo '<div class="nw-tek"><div class="bos-durum"><strong>Aradığınız sayfa bulunamadı.</strong>'
       . '<p><a href="' . e(nw_yol()) . '">Akışa dön</a></p></div></div>';
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

function nw_giris_zorunlu(string $donus): void
{
    if (aktif_uye() === null) {
        header('Location: ' . uye_yolu('giris', $donus), true, 303);
        exit;
    }
}

$networkSayfasi = true;
$aktifKategori  = 'network';
$seoRobots      = 'noindex, follow';
$govdeSinifi    = 'nw-govde';
$sayfaAciklama  = 'V Network: Valentra okurlarının buluşma yeri. Haberleri konuşun, paylaşın, yeni insanlarla tanışın.';
$sayfa          = 'akis';
$detayId        = 0;

if ($yol === '') {
    $sayfa = 'akis';
} elseif (preg_match('#^h/(\d+)$#', $yol, $e)) {
    $sayfa = 'haber';
    $detayId = (int) $e[1];
} elseif (preg_match('#^g/(\d+)$#', $yol, $e)) {
    $sayfa = 'gonderi';
    $detayId = (int) $e[1];
} elseif (preg_match('#^u/(\d+)$#', $yol, $e)) {
    $sayfa = 'profil';
    $detayId = (int) $e[1];
} elseif (preg_match('#^mesajlar(?:/(\d+))?$#', $yol, $e)) {
    $sayfa = 'mesajlar';
    $detayId = (int) ($e[1] ?? 0);
} elseif (in_array($yol, ['kisiler', 'ayarlar', 'kurallar'], true)) {
    $sayfa = $yol;
} else {
    nw_bulunamadi();
}

/* --- Sayfalarin verisi --------------------------------------------------- */

$nwAktif = $sayfa;

switch ($sayfa) {
    case 'akis':
        $sekme  = $uyeId !== null && (string) ($_GET['sekme'] ?? '') === 'takip' ? 'takip' : 'herkes';
        $once   = max(0, (int) ($_GET['once'] ?? 0));
        $akis   = nw_akis(NW_AKIS_ADEDI, $once, $sekme === 'takip' ? $uyeId : null);
        $baglam = nw_baglam($akis['ogeler']);
        $takipEdilenler = $uyeId !== null ? nw_takip_edilenler($uyeId) : [];
        $oneriler = array_slice(array_values(array_filter(
            nw_kisiler('', 'populer', 20),
            static fn (array $k): bool => (int) $k['id'] !== $uyeId && !in_array((int) $k['id'], $takipEdilenler, true)
        )), 0, 6);
        $buYol  = nw_yol('', ['sekme' => $sekme === 'takip' ? 'takip' : '', 'once' => $once]);
        $sayfaBasligi = 'V Network — Valentra okurlarının buluşma yeri';
        break;

    case 'haber':
        $h = db()->prepare(
            "SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.iframe_url, h.yayin_tarihi, k.ad AS kategori_adi
               FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.id = :id AND h.durum = :d"
        );
        $h->execute(['id' => $detayId, 'd' => HABER_YAYINDA]);
        $haber = $h->fetch();

        if (!is_array($haber)) {
            nw_bulunamadi();
        }

        // Network'te okunan haber de okunma sayisina giriyor (sayfa_ust.php ziyaret kaydi).
        $ziyaretHaberId = (int) $haber['id'];
        $oge    = ['tur' => 'haber', 'zaman' => 0, 'veri' => $haber];
        $baglam = nw_baglam([$oge]);
        $buYol  = nw_yol('h/' . $detayId);
        $sayfaBasligi = (string) $haber['baslik'] . ' — V Network';
        break;

    case 'gonderi':
        $gonderi = gonderi_bul($detayId);

        if ($gonderi === null) {
            nw_bulunamadi();
        }

        $oge    = ['tur' => 'gonderi', 'zaman' => 0, 'veri' => $gonderi];
        $baglam = nw_baglam([$oge]);
        $buYol  = nw_gonderi_yolu($detayId);
        $sayfaBasligi = (string) $gonderi['ad'] . ' paylaştı — V Network';
        break;

    case 'profil':
        $kisi = nw_uye_bul($detayId);

        if ($kisi === null) {
            nw_bulunamadi();
        }

        $kendi  = $uyeId === (int) $kisi['id'];
        $sekme  = in_array((string) ($_GET['sekme'] ?? ''), ['takipciler', 'takip'], true) ? (string) $_GET['sekme'] : 'paylasimlar';
        $durum  = $uyeId !== null && !$kendi ? takip_durumu($uyeId, (int) $kisi['id']) : ['takip' => false, 'takipci' => false];
        $takipEdilenler = $uyeId !== null ? nw_takip_edilenler($uyeId) : [];
        $buYol  = nw_profil_yolu((int) $kisi['id']);

        if ($sekme === 'paylasimlar') {
            $once   = max(0, (int) ($_GET['once'] ?? 0));
            $akis   = nw_akis(NW_AKIS_ADEDI, $once, null, (int) $kisi['id']);
            $baglam = nw_baglam($akis['ogeler']);
        } else {
            $liste = nw_takip_listesi((int) $kisi['id'], $sekme === 'takipciler');
        }

        $nwAktif = $kendi ? 'profil' : 'kisiler';
        $sayfaBasligi = (string) $kisi['ad'] . ' — V Network';
        break;

    case 'kisiler':
        $ara  = mb_substr(trim((string) ($_GET['ara'] ?? '')), 0, 60);
        $sira = (string) ($_GET['sira'] ?? '') === 'yeni' ? 'yeni' : 'populer';
        $kisiler = nw_kisiler($ara, $sira, 60);
        $takipEdilenler = $uyeId !== null ? nw_takip_edilenler($uyeId) : [];
        $buYol = nw_yol('kisiler', ['ara' => $ara, 'sira' => $sira === 'yeni' ? 'yeni' : '']);
        $sayfaBasligi = 'Kişiler — V Network';
        break;

    case 'mesajlar':
        nw_giris_zorunlu(nw_yol('mesajlar' . ($detayId > 0 ? '/' . $detayId : '')));

        if ($detayId > 0) {
            $karsi = nw_uye_bul($detayId);

            if ($karsi === null || $detayId === $uyeId) {
                nw_bulunamadi();
            }

            $arkadas  = arkadas_mi($uyeId, $detayId);
            // Once sohbet (okundu isareti), sonra liste.
            $mesajlar = mesaj_sohbet($uyeId, $detayId);
        }

        $sohbetler  = mesaj_sohbetleri($uyeId);
        $takipciIdleri = array_map(static fn (array $t): int => (int) $t['id'], nw_takip_listesi($uyeId, true));
        // Yeni sohbet icin karsilikli takiplesilen kisiler.
        $arkadaslar = array_values(array_filter(
            nw_takip_listesi($uyeId, false),
            static fn (array $k): bool => in_array((int) $k['id'], $takipciIdleri, true)
        ));
        $sayfaBasligi = 'Mesajlar — V Network';
        $seoRobots = 'noindex, nofollow';
        break;

    case 'ayarlar':
        nw_giris_zorunlu(nw_yol('ayarlar'));
        $ben = nw_uye_bul($uyeId);
        $nwAktif = 'profil';
        $sayfaBasligi = 'Profili düzenle — V Network';
        $seoRobots = 'noindex, nofollow';
        break;

    case 'kurallar':
        $nwAktif = '';
        $sayfaBasligi = 'Topluluk Kuralları — V Network';
        break;
}

$seoAdres = site_adresi() . ($buYol ?? nw_yol($yol));

require __DIR__ . '/includes/sayfa_ust.php';

/* --- Sol ve sag kenar, paylasim kutusu ------------------------------------ */

$solKenar = static function () use ($uye): void {
    ?>
    <aside class="nw-sol">
        <?php if ($uye !== null): ?>
            <a class="nw-sol-oge" href="<?= e(nw_profil_yolu((int) $uye['id'])) ?>"><?= uye_simge_html($uye, 36) ?><strong><?= e((string) $uye['ad']) ?></strong></a>
        <?php else: ?>
            <div class="nw-yan-kutu">
                <strong>V Network'e katılın</strong>
                <p>Haberleri konuşun, paylaşım yapın, yeni insanlarla tanışın.</p>
                <a class="uye-dugme" href="<?= e(uye_yolu('kayit', nw_yol())) ?>">Ücretsiz üye ol</a>
                <a class="uye-dugme uye-dugme-ikincil" href="<?= e(uye_yolu('giris', nw_yol())) ?>">Giriş yap</a>
            </div>
        <?php endif; ?>
        <a class="nw-sol-oge" href="<?= e(nw_yol()) ?>"><span class="nw-sol-simge" aria-hidden="true">🏠</span>Akış</a>
        <a class="nw-sol-oge" href="<?= e(nw_yol('kisiler')) ?>"><span class="nw-sol-simge" aria-hidden="true">👥</span>Kişiler</a>
        <?php if ($uye !== null): ?>
            <a class="nw-sol-oge" href="<?= e(nw_yol('mesajlar')) ?>"><span class="nw-sol-simge" aria-hidden="true">💬</span>Mesajlar</a>
            <a class="nw-sol-oge" href="<?= e(nw_yol('ayarlar')) ?>"><span class="nw-sol-simge" aria-hidden="true">⚙️</span>Profili düzenle</a>
        <?php endif; ?>
        <a class="nw-sol-oge" href="<?= e(nw_yol('kurallar')) ?>"><span class="nw-sol-simge" aria-hidden="true">📜</span>Topluluk Kuralları</a>
        <a class="nw-sol-oge" href="/"><span class="nw-sol-simge" aria-hidden="true">📰</span>Valentra Haber</a>
        <?php if ($uye !== null): ?>
            <form method="post" action="<?= e(uye_yolu('cikis')) ?>" class="nw-sol-cikis">
                <input type="hidden" name="jeton" value="<?= e(uye_form_jetonu()) ?>">
                <input type="hidden" name="donus" value="<?= e(nw_yol()) ?>">
                <button type="submit">Çıkış yap</button>
            </form>
        <?php endif; ?>
    </aside>
    <?php
};

$kuralKutusu = static function (): void {
    ?>
    <div class="nw-yan-kutu nw-kurallar">
        <strong>Topluluk kuralları</strong>
        <ul>
            <li>Küfür, hakaret ve nefret söylemi yasaktır.</li>
            <li>Kişilere ve kurumlara dayanaksız suçlama yapmayın.</li>
            <li>Uygunsuz içeriği “Şikâyet et” ile bildirin.</li>
        </ul>
        <a href="<?= e(nw_yol('kurallar')) ?>">Kuralların tamamı →</a>
    </div>
    <?php
};

/** Paylasim kutusu: yazi, fotograf (en fazla 4), video baglantisi. */
$paylasimKutusu = static function (string $donus) use ($uye): void {
    if ($uye === null) {
        return;
    }
    ?>
    <form method="post" action="/network-islem.php" class="nw-paylas" id="nw-paylas">
        <?= nw_form_alanlari('gonderi', $donus) ?>
        <input type="hidden" name="fotolar" value="">
        <div class="nw-paylas-ust">
            <?= uye_simge_html($uye, 40) ?>
            <label class="gizli-etiket" for="nw-paylas-metin">Paylaşımınız</label>
            <textarea id="nw-paylas-metin" name="metin" rows="2" maxlength="<?= GONDERI_EN_FAZLA ?>"
                      placeholder="Ne düşünüyorsun, <?= e(explode(' ', (string) $uye['ad'])[0]) ?>?"></textarea>
        </div>
        <div class="nw-paylas-onizleme" hidden></div>
        <div class="nw-paylas-video" hidden>
            <label class="gizli-etiket" for="nw-paylas-video">Video bağlantısı</label>
            <input id="nw-paylas-video" type="url" name="video" maxlength="300" placeholder="YouTube, Vimeo ya da Dailymotion bağlantısı yapıştırın">
        </div>
        <p class="nw-paylas-hata" role="alert" hidden></p>
        <div class="nw-paylas-alt">
            <label class="nw-paylas-ek" title="En fazla <?= NW_FOTO_PAYLASIM_EN_FAZLA ?> fotoğraf, her biri en fazla <?= NW_FOTO_ASIL_EN_FAZLA_MB ?> MB">
                <span aria-hidden="true">🖼️</span> Fotoğraf
                <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="nw-foto-sec" hidden
                       data-en-fazla="<?= NW_FOTO_PAYLASIM_EN_FAZLA ?>" data-mb="<?= NW_FOTO_ASIL_EN_FAZLA_MB ?>">
            </label>
            <button type="button" class="nw-paylas-ek nw-video-ac"><span aria-hidden="true">🎬</span> Video<span class="nw-uzun"> bağlantısı</span></button>
            <button type="submit" class="uye-dugme">Paylaş</button>
        </div>
        <p class="nw-paylas-bilgi">Fotoğraf: paylaşım başına en fazla <?= NW_FOTO_PAYLASIM_EN_FAZLA ?>, her biri en fazla <?= NW_FOTO_ASIL_EN_FAZLA_MB ?> MB, günde <?= NW_FOTO_GUNLUK_EN_FAZLA ?>. Video yalnızca bağlantı olarak paylaşılır.</p>
    </form>
    <?php
};
?>

<div class="nw-sayfa nw-sayfa-<?= e($sayfa) ?>">
    <?= $bildirim ?>

<?php if ($sayfa === 'akis'): ?>
    <div class="nw-uc">
        <?php $solKenar(); ?>
        <div class="nw-orta">
            <?php $paylasimKutusu(nw_yol('', ['sekme' => $sekme === 'takip' ? 'takip' : ''])); ?>
            <?php if ($uyeId !== null): ?>
                <nav class="nw-sekmeler" aria-label="Akış">
                    <a href="<?= e(nw_yol()) ?>" class="<?= $sekme === 'herkes' ? 'aktif' : '' ?>">Herkes</a>
                    <a href="<?= e(nw_yol('', ['sekme' => 'takip'])) ?>" class="<?= $sekme === 'takip' ? 'aktif' : '' ?>">Takip ettiklerim</a>
                </nav>
            <?php endif; ?>

            <?php if ($akis['ogeler'] === []): ?>
                <div class="bos-durum"><strong>Henüz paylaşım yok.</strong> <?= $sekme === 'takip' ? 'Kişiler sayfasından insanları takip edin; paylaşımları burada görünür.' : 'Yeni haberler geldikçe burada akacak.' ?></div>
            <?php endif; ?>

            <?php foreach ($akis['ogeler'] as $oge): ?>
                <?= nw_kart_html($oge, $baglam, $buYol) ?>
            <?php endforeach; ?>

            <?php if ($akis['sonraki'] > 0): ?>
                <nav class="nw-sayfalar"><a href="<?= e(nw_yol('', ['sekme' => $sekme === 'takip' ? 'takip' : '', 'once' => $akis['sonraki']])) ?>">Daha eski paylaşımlar →</a></nav>
            <?php endif; ?>
        </div>
        <aside class="nw-sag">
            <?php if ($oneriler !== []): ?>
                <div class="nw-yan-kutu">
                    <strong>Tanışabileceğin kişiler</strong>
                    <?php foreach ($oneriler as $k): ?>
                        <?= nw_kisi_html($k, $takipEdilenler, $buYol, true) ?>
                    <?php endforeach; ?>
                    <a href="<?= e(nw_yol('kisiler')) ?>">Tüm kişiler →</a>
                </div>
            <?php endif; ?>
            <?php $kuralKutusu(); ?>
        </aside>
    </div>

<?php elseif ($sayfa === 'haber' || $sayfa === 'gonderi'): ?>
    <div class="nw-tek">
        <a class="nw-geri" href="<?= e(nw_yol()) ?>">← Akışa dön</a>
        <?= nw_kart_html($oge, $baglam, $buYol, true) ?>
        <?php if ($sayfa === 'haber'): ?>
            <?php // Haberin tamami altta; ayni sekmede acilir, geri tusu buraya dondurur (site sahibi, 31.10.2026). ?>
            <a class="nw-haber-tamami" href="<?= e(haber_yolu((string) $haber['slug'])) ?>">Haberin tamamını oku →</a>
        <?php endif; ?>
    </div>

<?php elseif ($sayfa === 'profil'): ?>
    <div class="nw-profil">
        <div class="nw-profil-kapak"></div>
        <div class="nw-profil-bas">
            <?= uye_simge_html($kisi, 120) ?>
            <div class="nw-profil-ad">
                <h1><?= e((string) $kisi['ad']) ?></h1>
                <?php if ((string) $kisi['hakkinda'] !== ''): ?><p><?= e((string) $kisi['hakkinda']) ?></p><?php endif; ?>
                <div class="nw-profil-sayilar">
                    <a href="<?= e($buYol) ?>"><strong><?= (int) $kisi['gonderi'] ?></strong> paylaşım</a>
                    <a href="<?= e(nw_yol('u/' . (int) $kisi['id'], ['sekme' => 'takipciler'])) ?>"><strong><?= (int) $kisi['takipci'] ?></strong> takipçi</a>
                    <a href="<?= e(nw_yol('u/' . (int) $kisi['id'], ['sekme' => 'takip'])) ?>"><strong><?= (int) $kisi['takip'] ?></strong> takip</a>
                </div>
            </div>
            <div class="nw-profil-dugmeler">
                <?php if ($kendi): ?>
                    <a class="uye-dugme uye-dugme-ikincil" href="<?= e(nw_yol('ayarlar')) ?>">Profili düzenle</a>
                <?php else: ?>
                    <?= nw_takip_html((int) $kisi['id'], $durum['takip'], $buYol) ?>
                    <?php if ($durum['takip'] && $durum['takipci']): ?>
                        <a class="uye-dugme uye-dugme-ikincil" href="<?= e(nw_yol('mesajlar/' . (int) $kisi['id'])) ?>">Mesaj gönder</a>
                    <?php elseif ($durum['takipci']): ?>
                        <span class="nw-not">Sizi takip ediyor</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <nav class="nw-sekmeler" aria-label="Profil">
            <a href="<?= e($buYol) ?>" class="<?= $sekme === 'paylasimlar' ? 'aktif' : '' ?>">Paylaşımlar</a>
            <a href="<?= e(nw_yol('u/' . (int) $kisi['id'], ['sekme' => 'takipciler'])) ?>" class="<?= $sekme === 'takipciler' ? 'aktif' : '' ?>">Takipçiler</a>
            <a href="<?= e(nw_yol('u/' . (int) $kisi['id'], ['sekme' => 'takip'])) ?>" class="<?= $sekme === 'takip' ? 'aktif' : '' ?>">Takip ettikleri</a>
        </nav>

        <div class="nw-tek">
            <?php if ($sekme === 'paylasimlar'): ?>
                <?php if ($kendi) { $paylasimKutusu($buYol); } ?>
                <?php if ($akis['ogeler'] === []): ?>
                    <div class="bos-durum"><strong>Henüz paylaşım yok.</strong></div>
                <?php endif; ?>
                <?php foreach ($akis['ogeler'] as $oge): ?>
                    <?= nw_kart_html($oge, $baglam, $buYol) ?>
                <?php endforeach; ?>
                <?php if ($akis['sonraki'] > 0): ?>
                    <nav class="nw-sayfalar"><a href="<?= e(nw_yol('u/' . (int) $kisi['id'], ['once' => $akis['sonraki']])) ?>">Daha eski paylaşımlar →</a></nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="nw-yan-kutu">
                    <?php if ($liste === []): ?>
                        <p><?= $sekme === 'takipciler' ? 'Henüz takipçi yok.' : 'Henüz kimseyi takip etmiyor.' ?></p>
                    <?php endif; ?>
                    <?php foreach ($liste as $k): ?>
                        <?= nw_kisi_html($k, $takipEdilenler, nw_yol('u/' . (int) $kisi['id'], ['sekme' => $sekme])) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($sayfa === 'kisiler'): ?>
    <div class="nw-tek nw-genis">
        <h1 class="nw-baslik">Kişiler</h1>
        <form class="nw-ara" method="get" action="<?= e(nw_yol('kisiler')) ?>">
            <label class="gizli-etiket" for="nw-ara">Kişi ara</label>
            <input id="nw-ara" type="search" name="ara" value="<?= e($ara) ?>" placeholder="İsimle ara…" maxlength="60">
            <button type="submit" class="uye-dugme">Ara</button>
        </form>
        <nav class="nw-sekmeler" aria-label="Sıralama">
            <a href="<?= e(nw_yol('kisiler', ['ara' => $ara])) ?>" class="<?= $sira === 'populer' ? 'aktif' : '' ?>">En çok takip edilenler</a>
            <a href="<?= e(nw_yol('kisiler', ['ara' => $ara, 'sira' => 'yeni'])) ?>" class="<?= $sira === 'yeni' ? 'aktif' : '' ?>">Yeni üyeler</a>
        </nav>
        <?php if ($kisiler === []): ?>
            <div class="bos-durum"><strong><?= $ara !== '' ? 'Bu isimde üye bulunamadı.' : 'Henüz üye yok.' ?></strong></div>
        <?php endif; ?>
        <div class="nw-kisiler">
            <?php foreach ($kisiler as $k): ?>
                <?= nw_kisi_html($k, $takipEdilenler, $buYol) ?>
            <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($sayfa === 'mesajlar'): ?>
    <div class="nw-mesajlar<?= $detayId > 0 ? ' sohbet-acik' : '' ?>">
        <aside class="nw-sohbetler">
            <h1 class="nw-baslik">Mesajlar</h1>
            <?php if ($sohbetler === [] && $arkadaslar === []): ?>
                <p class="nw-not">Karşılıklı takipleştiğiniz kişilerle mesajlaşabilirsiniz. <a href="<?= e(nw_yol('kisiler')) ?>">Kişileri keşfedin →</a></p>
            <?php endif; ?>
            <?php foreach ($sohbetler as $s): ?>
                <a class="nw-sohbet<?= (int) $s['id'] === $detayId ? ' aktif' : '' ?><?= (int) $s['okunmamis'] > 0 ? ' okunmamis' : '' ?>" href="<?= e(nw_yol('mesajlar/' . (int) $s['id'])) ?>">
                    <?= uye_simge_html($s, 44) ?>
                    <span><strong><?= e((string) $s['ad']) ?></strong>
                    <small><?= (int) $s['gonderen_id'] === $uyeId ? 'Sen: ' : '' ?><?= e(kisalt((string) $s['metin'], 40)) ?> · <?= e(yorum_zaman((string) $s['olusturuldu'])) ?></small></span>
                    <?php if ((int) $s['okunmamis'] > 0): ?><b class="nw-rozet"><?= (int) $s['okunmamis'] ?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php
            $sohbetIdleri = array_map(static fn (array $s): int => (int) $s['id'], $sohbetler);
            $yeniler = array_values(array_filter($arkadaslar, static fn (array $k): bool => !in_array((int) $k['id'], $sohbetIdleri, true)));
            ?>
            <?php if ($yeniler !== []): ?>
                <p class="nw-ara-baslik">Arkadaşların</p>
                <?php foreach ($yeniler as $k): ?>
                    <a class="nw-sohbet" href="<?= e(nw_yol('mesajlar/' . (int) $k['id'])) ?>"><?= uye_simge_html($k, 44) ?><span><strong><?= e((string) $k['ad']) ?></strong><small>Sohbet başlat</small></span></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>

        <section class="nw-sohbet-alani">
            <?php if ($detayId > 0): ?>
                <header class="nw-sohbet-ust">
                    <a class="nw-geri-mobil" href="<?= e(nw_yol('mesajlar')) ?>" aria-label="Sohbetlere dön">←</a>
                    <a href="<?= e(nw_profil_yolu($detayId)) ?>"><?= uye_simge_html($karsi, 40) ?><strong><?= e((string) $karsi['ad']) ?></strong></a>
                </header>
                <div class="nw-baloncuklar" id="nw-baloncuklar">
                    <?php if ($mesajlar === []): ?>
                        <p class="nw-not">Henüz mesaj yok. İlk mesajı siz gönderin.</p>
                    <?php endif; ?>
                    <?php foreach ($mesajlar as $m): ?>
                        <div class="nw-baloncuk<?= (int) $m['gonderen_id'] === $uyeId ? ' benim' : '' ?>">
                            <p><?= yorum_metin_html((string) $m['metin']) ?></p>
                            <time><?= e(yorum_zaman((string) $m['olusturuldu'])) ?></time>
                        </div>
                    <?php endforeach; ?>
                    <span id="son"></span>
                </div>
                <?php if ($arkadas): ?>
                    <form method="post" action="/network-islem.php" class="yorum-yaz nw-mesaj-yaz">
                        <?= nw_form_alanlari('mesaj', nw_yol('mesajlar/' . $detayId), ['uye' => $detayId]) ?>
                        <label class="gizli-etiket" for="nw-mesaj">Mesajınız</label>
                        <textarea id="nw-mesaj" name="metin" rows="1" maxlength="<?= MESAJ_EN_FAZLA ?>" required placeholder="Mesaj yazın…"></textarea>
                        <button type="submit">Gönder</button>
                    </form>
                <?php else: ?>
                    <p class="nw-not nw-mesaj-kapali">Mesajlaşmak için karşılıklı takipleşmeniz gerekiyor.</p>
                <?php endif; ?>
                <script>(function(){var b=document.getElementById('nw-baloncuklar');if(b){b.scrollTop=b.scrollHeight;}})();</script>
            <?php else: ?>
                <div class="bos-durum"><strong>Bir sohbet seçin.</strong> Karşılıklı takipleştiğiniz kişilere mesaj gönderebilirsiniz.</div>
            <?php endif; ?>
        </section>
    </div>

<?php elseif ($sayfa === 'ayarlar'): ?>
    <div class="nw-tek">
        <h1 class="nw-baslik">Profili düzenle</h1>
        <form method="post" action="/network-islem.php" class="uye-form nw-yan-kutu" id="nw-profil-form">
            <?= nw_form_alanlari('profil', nw_yol('ayarlar')) ?>
            <input type="hidden" name="foto" value="">
            <div class="nw-profil-foto">
                <span class="nw-profil-foto-onizleme"><?= uye_simge_html($ben, 96) ?></span>
                <div>
                    <label class="uye-dugme uye-dugme-ikincil">Profil resmi seç
                        <input type="file" accept="image/jpeg,image/png,image/webp" class="nw-profil-foto-sec" hidden data-mb="<?= NW_FOTO_ASIL_EN_FAZLA_MB ?>">
                    </label>
                    <?php if ((string) $ben['foto'] !== ''): ?>
                        <label class="uye-onay"><input type="checkbox" name="foto_kaldir" value="1"> <span>Profil resmini kaldır</span></label>
                    <?php endif; ?>
                    <p class="nw-not">JPG, PNG ya da WebP; en fazla <?= NW_FOTO_ASIL_EN_FAZLA_MB ?> MB. Kare olarak kırpılır.</p>
                    <p class="nw-paylas-hata" role="alert" hidden></p>
                </div>
            </div>
            <label>Adınız
                <input type="text" name="ad" value="<?= e((string) $ben['ad']) ?>" required minlength="<?= UYE_AD_EN_AZ ?>" maxlength="<?= UYE_AD_EN_FAZLA ?>">
            </label>
            <label>Hakkınızda (profilinizde görünür)
                <textarea name="hakkinda" rows="3" maxlength="<?= HAKKINDA_EN_FAZLA ?>" placeholder="Kendinizi birkaç cümleyle tanıtın…"><?= e((string) $ben['hakkinda']) ?></textarea>
            </label>
            <button type="submit" class="uye-dugme">Kaydet</button>
        </form>
    </div>

<?php elseif ($sayfa === 'kurallar'): ?>
    <div class="nw-tek">
        <article class="nw-yan-kutu nw-kurallar-sayfa">
            <h1>V Network Topluluk Kuralları</h1>
            <p>V Network, Valentra okurlarının haberleri konuştuğu, fikirlerini paylaştığı ve yeni insanlarla tanıştığı bir buluşma yeridir. Burayı herkes için güvenli ve keyifli tutmak hepimizin elinde. Üye olan herkes bu kuralları kabul etmiş sayılır.</p>

            <h2>Yasak olanlar</h2>
            <ul>
                <li><strong>Küfür, hakaret ve aşağılama:</strong> Kimseye küfür edilmez, kimse aşağılanmaz. Küfürlü yazılar sistem tarafından otomatik olarak engellenir.</li>
                <li><strong>Nefret söylemi:</strong> Din, dil, ırk, etnik köken, cinsiyet, engellilik ya da siyasi görüş nedeniyle kimse hedef gösterilemez.</li>
                <li><strong>Dayanaksız suçlama:</strong> Kişilere ve kurumlara yargı kararı ya da resmî bir açıklamaya dayanmayan suç isnadında bulunulmaz.</li>
                <li><strong>Taciz ve rahatsız etme:</strong> İstenmeyen mesaj, ısrarlı takip, tehdit ve korkutma yasaktır.</li>
                <li><strong>Kişisel bilgi paylaşımı:</strong> Başkalarının telefon, adres, kimlik bilgileri ya da özel fotoğrafları izinsiz paylaşılamaz.</li>
                <li><strong>Müstehcen ve şiddet içeren içerik:</strong> Çıplaklık, cinsel içerik ve şiddet görüntüleri paylaşılamaz.</li>
                <li><strong>Yasa dışı içerik:</strong> Terör örgütlerinin propagandası, yasa dışı bahis, uyuşturucu ve benzeri içerikler yasaktır.</li>
                <li><strong>Spam ve reklam:</strong> Aynı içeriği tekrar tekrar paylaşmak, ticari reklam ve dolandırıcılık bağlantıları yasaktır.</li>
                <li><strong>Sahte hesap:</strong> Başka birinin adını ya da kimliğini kullanmak yasaktır.</li>
            </ul>

            <h2>Fotoğraf ve video</h2>
            <ul>
                <li>Bir paylaşıma en fazla <?= NW_FOTO_PAYLASIM_EN_FAZLA ?> fotoğraf eklenebilir; her fotoğraf en fazla <?= NW_FOTO_ASIL_EN_FAZLA_MB ?> MB, günde en fazla <?= NW_FOTO_GUNLUK_EN_FAZLA ?> fotoğraf.</li>
                <li>Yalnızca size ait ya da paylaşma hakkınız olan fotoğrafları paylaşın.</li>
                <li>Video dosyası yüklenmez; YouTube, Vimeo ya da Dailymotion bağlantısı paylaşılabilir.</li>
            </ul>

            <h2>Mesajlaşma</h2>
            <p>İstenmeyen mesajları önlemek için yalnızca <strong>karşılıklı takipleşen</strong> üyeler birbirine özel mesaj gönderebilir.</p>

            <h2>Bildirme ve yaptırımlar</h2>
            <ul>
                <li>Kurallara uymayan bir paylaşımı ya da yorumu “Şikâyet et” ile bildirin. Birden çok üyenin bildirdiği içerik otomatik olarak gizlenir ve site yönetimince incelenir.</li>
                <li>Kuralları çiğneyen içerik uyarısız kaldırılır; tekrarlayan ya da ağır ihlallerde üyelik süresiz durdurulur.</li>
                <li>Yasalara aykırı içerik, yasal yükümlülükler gereği yetkili makamlarla paylaşılabilir.</li>
            </ul>

            <p class="nw-not">Paylaştığınız her içerikten siz sorumlusunuz. Saygılı olun, farklı görüşlere açık olun; gündemi birlikte, nezaketle konuşalım.</p>
        </article>
    </div>
<?php endif; ?>
</div>

<?php if (in_array($sayfa, ['akis', 'haber', 'gonderi', 'profil'], true)): ?>
    <?= nw_oto_oynat_betigi() ?>
<?php endif; ?>
<?php if ($uye !== null && in_array($sayfa, ['akis', 'profil', 'ayarlar'], true)): ?>
    <script src="<?= e(varlik('/assets/network.js')) ?>" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
