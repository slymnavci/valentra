<?php
declare(strict_types=1);

/**
 * Şirket sayfası: bir Borsa İstanbul hissesinin 10 yıllık geçmişi ve
 * analiz verileri. Kod yoksa şirket listesi.
 *
 * Site sahibinin istegi (22.10.2026): "bir sirket secelim, son 10 yillik
 * gecmisini okuyucu izleyebilsin; analistlerin ihtiyac duyabilecegi
 * bilgileri gorelim". Fiyat ve hesaplanan olculer Yahoo Finance
 * serisinden (includes/sirket.php), carpanlar, oneri ve temettu Is
 * Yatirim sirket kartindan (includes/sirket_temel.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/sirket_panel.php';
require_once __DIR__ . '/includes/sirket_temel.php';

// Formdan gelen: yazilan kod ("ara") listeden secilenden onde. Temiz
// adrese yonlendiriliyor; paylasilan adres /sirket/THYAO olsun.
$ara = trim((string) ($_GET['ara'] ?? ''));
$ham = $ara !== '' ? $ara : (string) ($_GET['kod'] ?? '');
$kod = $ham !== '' ? sirket_kod_temiz($ham) : null;

$donemler = sirket_donemleri();
$donem    = (string) ($_GET['donem'] ?? '1y');
$donem    = isset($donemler[$donem]) ? $donem : '1y';

$sorguyla = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/sirket.php');

if ($kod !== null && ($ara !== '' || $ham !== $kod || $sorguyla) && temiz_adres_acik()) {
    header('Location: ' . sirket_yolu($kod, $donem), true, 302);
    exit;
}

$aktifKategori = 'ekonomi';

// --- liste ---------------------------------------------------------------

if ($kod === null) {
    require_once __DIR__ . '/includes/piyasa_panel.php';
    require_once __DIR__ . '/includes/canli_betik.php';

    if ($ham !== '') {
        http_response_code(404);
    }

    $sayfaBasligi  = 'Borsa İstanbul Şirketleri — Valentra';
    $sayfaAciklama = 'Borsa İstanbul şirketleri: 10 yıllık fiyat geçmişi, getiriler, risk ölçüleri, '
                   . 'çarpanlar, analist önerileri ve temettüler.';
    $seoAdres      = site_adresi() . sirket_yolu();
    $sirketler     = piyasa_sirketler_html();

    require __DIR__ . '/includes/sayfa_ust.php';
    ?>
    <div class="kategori-basligi">
        <h1>Borsa İstanbul Şirketleri</h1>
        <p>Bir şirket seçin: 10 yıllık fiyat geçmişi, getiriler, risk ölçüleri, çarpanlar,
            analist önerisi ve temettüler.</p>
    </div>

    <div class="ana-duzen tam">
        <div class="ana-kolon">
            <?php if ($ham !== ''): ?>
                <p class="bos-durum"><strong>"<?= e($ham) ?>" geçerli bir borsa kodu değil.</strong>
                    Kod 3-6 harf ya da rakamdan oluşur (ör. THYAO).</p>
            <?php endif; ?>
            <?= sirket_secici_html() ?>
            <?= $sirketler ?>
            <?= $sirketler !== '' ? canli_betik() : '' ?>
            <p class="piyasa-bag"><a href="<?= e(piyasalar_yolu()) ?>">Piyasalar: BIST 100, döviz, altın &rarr;</a></p>
        </div>

        <?php /* Yan pencere yok: grafik ve tablolar tam genislikte (23.10.2026). */ ?>
    </div>
    <?php
    require __DIR__ . '/includes/sayfa_alt.php';

    return;
}

// --- veri ------------------------------------------------------------------

// Secki disindaki kod once dogrulaniyor (bkz. sirket_kod_izinli); olmayan
// ya da kotayi asan kod icin kaynaga gidilmiyor, onbellek satiri acilmiyor.
$izinli  = sirket_kod_izinli($kod);
$bos     = ['veri' => null, 'zaman' => 0];

// Gerekenleri once ayni anda cek (bkz. sirket_serileri_hazirla).
if ($izinli) {
    sirket_serileri_hazirla([[$kod, '1d'], [$kod, '1y'], [$kod, '10y'], [$kod, $donem], ['XU100', '10y']]);
}

$anlikKayit = $izinli ? sirket_seri($kod, '1d') : $bos;
$yilKayit   = $izinli ? sirket_seri($kod, '1y') : $bos;
$anlik  = $anlikKayit['veri'];
$gunluk = $yilKayit['veri'];
$aylik  = $izinli ? sirket_seri($kod, '10y')['veri'] : null;
$secili = match (true) {
    !$izinli        => null,
    $donem === '1y'  => $gunluk,
    $donem === '10y' => $aylik,
    default          => sirket_seri($kod, $donem)['veri'],
};

// Fiyat, degisim ve guncelleme zamani AYNI kayittan: gun ici (2 dk) varsa
// o, yoksa yillik seri (1 saat).
$fiyatKayit = ($anlik['fiyat'] ?? null) !== null ? $anlikKayit : $yilKayit;
$fiyat   = $fiyatKayit['veri']['fiyat'] ?? null;
$degisim = $fiyatKayit['veri']['degisim'] ?? null;
$ad      = sirket_adi($kod, (string) ($gunluk['ad'] ?? $aylik['ad'] ?? ''));
$listede = isset(piyasa_sirketleri()[$kod]);
$veriVar = $gunluk !== null || $aylik !== null || $anlik !== null;

if (!$veriVar && !$listede) {
    // Yahoo'da da bulunamayan kod: buyuk ihtimalle yanlis yazilmis.
    // Kaynak gecici olarak yanit vermiyor da olabilir; mesaj ikisini de
    // soyluyor.
    http_response_code(404);
}

$analiz = $endeksAnaliz = null;

if ($gunluk !== null || $aylik !== null) {
    $endeksG = piyasa_seri('bist', '1y', 3600)['veri'];
    $endeksA = sirket_seri('XU100', '10y')['veri'];

    $analiz = sirket_analiz(
        $gunluk['noktalar'] ?? [],
        $gunluk['hacimler'] ?? [],
        $aylik['noktalar'] ?? [],
        $endeksG['noktalar'] ?? [],
        $gunluk['yuksek52'] ?? null,
        $gunluk['dusuk52'] ?? null
    );

    if ($endeksG !== null || $endeksA !== null) {
        $endeksAnaliz = sirket_analiz($endeksG['noktalar'] ?? [], [], $endeksA['noktalar'] ?? [], []);
    }
}

$temelKayit = $izinli ? sirket_temel($kod) : $bos;
$temel      = $temelKayit['veri'];
$oneri      = $temel['oneri'] ?? null;
$potansiyel = ($oneri !== null && $oneri['hedef'] !== null) ? sirket_yuzde($fiyat, (float) $oneri['hedef']) : null;

$grafik = '';

if ($secili !== null && count($secili['noktalar']) >= 2) {
    $grafik = grafik_ciz(sirket_grafik_serisi($secili['noktalar']), [
        'baslik'   => $ad . ' (' . $kod . ') — ' . $donemler[$donem]['ad'],
        'ad'       => $kod,
        'birim'    => 'tl',
        'kaynak'   => 'Yahoo Finance',
        'sifirdan' => false,
        'hane'     => 2,
    ]);
}

$sayfaBasligi  = $ad . ' (' . $kod . ') Hisse: 10 Yıllık Geçmiş, Getiri, Çarpanlar — Valentra';
$sayfaAciklama = $ad . ' (' . $kod . ') hissesi: 10 yıllık fiyat grafiği, dönem ve yıllık getiriler, '
               . 'oynaklık, beta, F/K ve PD/DD, analist önerisi ve temettü geçmişi.';
$seoAdres      = site_adresi() . sirket_yolu($kod);

$csv = static fn (string $d): string => '/api/sirket-veri.php?kod=' . rawurlencode($kod) . '&donem=' . rawurlencode($d);

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi sirket-baslik">
    <p class="sirket-yol"><a href="<?= e(piyasalar_yolu()) ?>">Piyasalar</a> &rsaquo;
        <a href="<?= e(sirket_yolu()) ?>">Şirketler</a></p>
    <h1><?= e($ad) ?> <span class="sirket-kodu"><?= e($kod) ?></span></h1>
    <?php if ($fiyat !== null): ?>
        <p class="sirket-fiyat">
            <span class="sf-deger"><?= e(piyasa_sayi((float) $fiyat, 2)) ?> ₺</span>
            <?= piyasa_degisim_html($degisim !== null ? (float) $degisim : null) ?>
            <span class="canli-not">güncelleme <?= e(canli_zaman_yazi($fiyatKayit['zaman'])) ?> · 15 dakikaya kadar gecikmeli olabilir</span>
        </p>
    <?php endif; ?>
</div>

<div class="ana-duzen tam">
    <div class="ana-kolon sirket-sayfa">
        <?= sirket_secici_html($kod) ?>

        <?php if (!$veriVar): ?>
            <div class="bos-durum">
                <strong><?= $listede ? 'Bu şirketin verisi şu an alınamadı.' : '"' . e($kod) . '" için veri bulunamadı.' ?></strong>
                <?= $listede ? 'Birkaç dakika içinde yeniden deneyin.' : 'Kodu kontrol edin ya da birkaç dakika sonra yeniden deneyin.' ?>
            </div>
        <?php else: ?>

        <section class="sirket-bolum" id="grafik" aria-label="Fiyat grafiği">
            <nav class="donem-secici" aria-label="Grafik dönemi">
                <?php foreach ($donemler as $d => $tanim): ?>
                    <a href="<?= e(sirket_yolu($kod, $d)) ?>#grafik"<?= $d === $donem ? ' aria-current="true"' : '' ?>><?= e($tanim['ad']) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($grafik !== ''): ?>
                <div class="piyasa-grafik sirket-grafik"><?= $grafik ?></div>
            <?php else: ?>
                <p class="canli-not">Bu dönemin verisi henüz alınamadı; birkaç dakika sonra yeniden deneyin.</p>
            <?php endif; ?>
            <p class="canli-not">
                Kapanış fiyatları; <?= $donem === '10y' ? 'aylık' : ($donem === '5y' ? 'haftalık' : 'günlük') ?>.
                Verileri indir (CSV):
                <?php foreach ($donemler as $d => $tanim): ?>
                    <a href="<?= e($csv($d)) ?>" rel="nofollow"><?= e($tanim['ad']) ?></a><?= $d !== array_key_last($donemler) ? ' ·' : '' ?>
                <?php endforeach; ?>
            </p>
        </section>

        <?php if ($analiz !== null): ?>
            <section class="sirket-bolum" id="getiriler">
                <div class="bolum-basligi"><h2>Getiriler</h2><span class="cizgi"></span></div>
                <div class="tablo-kaydir">
                    <table class="sirket-tablosu analiz-tablosu">
                        <thead>
                            <tr>
                                <th scope="col">Dönem</th>
                                <th scope="col" class="sayi"><?= e($kod) ?></th>
                                <th scope="col" class="sayi">BIST 100</th>
                                <th scope="col" class="sayi" title="Hisse getirisi eksi endeks getirisi (yüzde puan)">Fark</th>
                                <th scope="col" class="sayi" title="Yıllık bileşik getiri">Yıllık bileşik</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analiz['getiriler'] as $dAd => $g):
                                $eg = $endeksAnaliz['getiriler'][$dAd]['getiri'] ?? null;
                                $fark = ($g['getiri'] !== null && $eg !== null) ? $g['getiri'] - $eg : null; ?>
                                <tr>
                                    <th scope="row"><?= e($dAd) ?></th>
                                    <td class="sayi"><?= sirket_yuzde_html($g['getiri'], 1) ?></td>
                                    <td class="sayi"><?= sirket_yuzde_html($eg, 1) ?></td>
                                    <td class="sayi"><?= $fark !== null ? sirket_yuzde_html($fark, 1) : sirket_yuzde_html(null) ?></td>
                                    <td class="sayi"><?= $g['yillik'] !== null ? sirket_yuzde_html($g['yillik'], 1) : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($analiz['yillik'] !== []): ?>
                <section class="sirket-bolum" id="yillik">
                    <div class="bolum-basligi"><h2>Yıllara Göre Getiri</h2><span class="cizgi"></span></div>
                    <div class="tablo-kaydir">
                        <table class="sirket-tablosu analiz-tablosu">
                            <thead>
                                <tr>
                                    <th scope="col">Yıl</th>
                                    <th scope="col" class="sayi"><?= e($kod) ?></th>
                                    <th scope="col" class="sayi">BIST 100</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analiz['yillik'] as $yil => $g): ?>
                                    <tr>
                                        <th scope="row"><?= (int) $yil ?><?= (int) $yil === (int) date('Y') ? ' <small>(yılbaşından beri)</small>' : '' ?></th>
                                        <td class="sayi"><?= sirket_yuzde_html($g, 1) ?></td>
                                        <td class="sayi"><?= sirket_yuzde_html($endeksAnaliz['yillik'][$yil] ?? null, 1) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <section class="sirket-bolum" id="risk">
                <div class="bolum-basligi"><h2>Risk ve İşlem Ölçüleri</h2><span class="cizgi"></span></div>
                <div class="tablo-kaydir">
                    <table class="sirket-tablosu analiz-tablosu olcu-tablosu">
                        <tbody>
                            <tr>
                                <th scope="row">52 hafta en yüksek / en düşük</th>
                                <td class="sayi"><?= $analiz['yuksek52'] !== null ? e(piyasa_sayi($analiz['yuksek52'], 2)) . ' / ' . e(piyasa_sayi((float) $analiz['dusuk52'], 2)) . ' ₺' : '–' ?></td>
                                <td class="aciklama">Son bir yılın en yüksek ve en düşük fiyatı.</td>
                            </tr>
                            <tr>
                                <th scope="row">Zirveye uzaklık</th>
                                <td class="sayi"><?= sirket_yuzde_html($analiz['zirveye'], 1) ?></td>
                                <td class="aciklama">Son fiyatın 52 haftalık en yüksek fiyata göre farkı.</td>
                            </tr>
                            <tr>
                                <th scope="row">Yıllık oynaklık</th>
                                <td class="sayi"><?= $analiz['oynaklik'] !== null ? '%' . e(piyasa_sayi($analiz['oynaklik'], 1)) : '–' ?></td>
                                <td class="aciklama">Son bir yılın günlük getirilerinin standart sapması, yıllığa çevrilmiş (×√252).</td>
                            </tr>
                            <tr>
                                <th scope="row">Beta (BIST 100)</th>
                                <td class="sayi"><?= $analiz['beta'] !== null ? e(piyasa_sayi($analiz['beta'], 2)) : '–' ?></td>
                                <td class="aciklama">Endeks %1 hareket ettiğinde hissenin ortalama hareketi (son bir yıl, günlük). 1'den büyükse endeksten oynak.</td>
                            </tr>
                            <tr>
                                <th scope="row">Korelasyon (BIST 100)</th>
                                <td class="sayi"><?= $analiz['korelasyon'] !== null ? e(piyasa_sayi($analiz['korelasyon'], 2)) : '–' ?></td>
                                <td class="aciklama">Günlük getirilerin endeksle birlikte hareket derecesi (−1 ile 1 arası).</td>
                            </tr>
                            <?php foreach (['dusus1y' => 'En büyük düşüş (1 yıl)', 'dusus10y' => 'En büyük düşüş (10 yıl)'] as $anahtar => $baslik):
                                $d = $analiz[$anahtar]; ?>
                                <tr>
                                    <th scope="row"><?= e($baslik) ?></th>
                                    <td class="sayi"><?= $d !== null ? sirket_yuzde_html($d['yuzde'], 1) : '–' ?></td>
                                    <td class="aciklama"><?= $d !== null && $d['yuzde'] < 0
                                        ? 'Zirveden dibe: ' . e(sirket_ay($d['zirve'])) . ' → ' . e(sirket_ay($d['dip'])) . ($anahtar === 'dusus10y' ? ' (aylık kapanışlarla).' : '.')
                                        : 'Zirveden dibe en büyük kayıp.' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <th scope="row">Ortalama günlük hacim</th>
                                <td class="sayi"><?= e(sirket_buyuk_sayi($analiz['hacim3a'])) ?> lot</td>
                                <td class="aciklama">Son üç ayın günlük işlem adedi ortalaması.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="canli-not">Getiriler fiyat getirisidir, temettü dahil değildir. Ölçüler Valentra tarafından
                    Yahoo Finance kapanış fiyatlarından hesaplanmıştır.</p>
            </section>
        <?php endif; ?>

        <?php endif; ?>

        <?php if ($temel !== null): ?>
            <section class="sirket-bolum" id="temel">
                <div class="bolum-basligi"><h2>Değerleme ve Analist Görüşü</h2><span class="cizgi"></span></div>

                <div class="temel-izgara">
                    <?php if (!empty($temel['piyasa_degeri'])): $pd = $temel['piyasa_degeri']; ?>
                        <div class="temel-kutu">
                            <h3>Piyasa Değeri</h3>
                            <p class="temel-buyuk"><?= e(sirket_buyuk_sayi((float) $pd['tl'])) ?> ₺</p>
                            <?php if ($pd['usd'] !== null): ?><p>$<?= e(sirket_buyuk_sayi((float) $pd['usd'])) ?></p><?php endif; ?>
                            <?php if ($pd['halka_acik'] !== null): ?><p>Halka açık kısım: <?= e(sirket_buyuk_sayi((float) $pd['halka_acik'])) ?> ₺</p><?php endif; ?>
                            <?php if ($pd['tarih'] !== ''): ?><p class="canli-not"><?= e($pd['tarih']) ?></p><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($oneri !== null): ?>
                        <div class="temel-kutu analist-kutu">
                            <h3>Analist Önerisi</h3>
                            <?php $yon = preg_match('/^(AL|ENDEKS ÜSTÜ)/u', $oneri['oneri']) ? 'arti' : (preg_match('/^(SAT|ENDEKS ALTI)/u', $oneri['oneri']) ? 'eksi' : ''); ?>
                            <p class="temel-buyuk pdeg <?= $yon ?>"><?= e($oneri['oneri']) ?></p>
                            <?php if ($oneri['hedef'] !== null): ?>
                                <p>Hedef fiyat: <strong><?= e(piyasa_sayi((float) $oneri['hedef'], 2)) ?> ₺</strong></p>
                                <?php if ($potansiyel !== null): ?><p>Getiri potansiyeli: <?= sirket_yuzde_html($potansiyel, 1) ?></p><?php endif; ?>
                            <?php endif; ?>
                            <p class="canli-not">İş Yatırım Araştırma · <?= e($oneri['tarih']) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($temel['carpanlar'])): ?>
                        <div class="temel-kutu">
                            <h3>Güncel Çarpanlar</h3>
                            <table class="sirket-tablosu kucuk">
                                <tbody>
                                    <?php foreach ($temel['carpanlar'] as $k => $v): ?>
                                        <tr><th scope="row"><?= e((string) $k) ?></th><td class="sayi"><?= e((string) $v) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($temel['finansal'])): ?>
                        <div class="temel-kutu">
                            <h3>Özet Finansallar <small>(son açıklanan)</small></h3>
                            <table class="sirket-tablosu kucuk">
                                <tbody>
                                    <?php foreach ($temel['finansal'] as $k => $v): ?>
                                        <tr><th scope="row"><?= e((string) $k) ?></th><td class="sayi"><?= e((string) $v) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($temel['temettu'])): ?>
                    <h3 class="sirket-alt-baslik" id="temettu">Temettü Geçmişi</h3>
                    <div class="tablo-kaydir">
                        <table class="sirket-tablosu analiz-tablosu">
                            <thead>
                                <tr>
                                    <th scope="col">Dağıtım Tarihi</th>
                                    <th scope="col" class="sayi">Verim (%)</th>
                                    <th scope="col" class="sayi">Hisse Başı (TL)</th>
                                    <th scope="col" class="sayi">Brüt Oran (%)</th>
                                    <th scope="col" class="sayi">Net Oran (%)</th>
                                    <th scope="col" class="sayi">Toplam (TL)</th>
                                    <th scope="col" class="sayi">Dağıtma Oranı (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($temel['temettu'] as $t): ?>
                                    <tr>
                                        <td><?= e($t['tarih']) ?></td>
                                        <td class="sayi"><?= e($t['verim']) ?></td>
                                        <td class="sayi"><?= e($t['hisse']) ?></td>
                                        <td class="sayi"><?= e($t['brut']) ?></td>
                                        <td class="sayi"><?= e($t['net']) ?></td>
                                        <td class="sayi"><?= e($t['toplam']) ?></td>
                                        <td class="sayi"><?= e($t['dagitma']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($temel['kunye'])): ?>
                    <h3 class="sirket-alt-baslik" id="kunye">Şirket Künyesi</h3>
                    <table class="sirket-tablosu olcu-tablosu kunye-tablosu">
                        <tbody>
                            <?php foreach ($temel['kunye'] as $k => $v): ?>
                                <tr><th scope="row"><?= e((string) $k) ?></th><td><?= e((string) $v) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <p class="canli-not">
                    Kaynak: <a href="<?= e(ISY_KART_URL . rawurlencode($kod)) ?>" target="_blank" rel="noopener">İş Yatırım şirket kartı</a>
                    · güncelleme <?= e(canli_zaman_yazi($temelKayit['zaman'])) ?>.
                    Analist önerisi ve hedef fiyat İş Yatırım'ın görüşüdür; Valentra'nın değildir.
                </p>
            </section>
        <?php endif; ?>

        <p class="yasal-not" role="note">
            <strong>Yatırım tavsiyesi değildir.</strong> Bu sayfadaki bilgiler yalnızca bilgilendirme
            amaçlıdır; SPK mevzuatı kapsamında yatırım danışmanlığı değildir. Veriler gecikmeli olabilir;
            yatırım kararı vermeden önce resmi kaynaklardan (KAP, Borsa İstanbul) doğrulayın.
            <a href="https://www.kap.org.tr/tr/bildirim-sorgu" target="_blank" rel="noopener">KAP bildirimleri &rarr;</a>
        </p>
    </div>

    <?php /* Yan pencere yok: grafik ve tablolar tam genislikte (23.10.2026). */ ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
