<?php
declare(strict_types=1);

/**
 * Gunluk isler: her gun yapilanlar tek sayfada.
 *
 *  - Onay bekleyenler (haber, kose yazisi, pratik bilgi) ve haberleri
 *    tek tikla yayinlama.
 *  - Ajan: haber toplama, kose yazisi, pratik bilgiler.
 *  - Site verisi: kurlar, grafikler, Resmi Gazete.
 *  - Arama motorlari: Search Console verisi, IndexNow, site haritasi.
 *
 * Her is ayri bir istekte kosuyor (bkz. includes/gunluk_isler.php).
 * Dugmeler JavaScript'siz de calisiyor; JS varsa sayfa yenilenmeden
 * sonuc satirin altina yaziliyor ve "Hepsini sirayla" isleri tek tek
 * calistiriyor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gunluk_isler.php';

giris_zorunlu();

$isler    = gunluk_isler();
$sonuclar = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = ($_POST['ajax'] ?? '') === '1';

    if ($ajax) {
        // Oturum ya da CSRF hatasi HTML sayfasi dondurmesin; JS JSON bekliyor.
        if (!hash_equals(csrf_jeton(), (string) ($_POST['csrf'] ?? ''))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['tamam' => false, 'mesaj' => 'Oturum yenilendi; sayfayı yenileyip tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        csrf_dogrula($_POST['csrf'] ?? null);
    }

    $is = (string) ($_POST['is'] ?? '');

    if (!isset($isler[$is])) {
        $sonuc = ['tamam' => false, 'mesaj' => 'Bilinmeyen iş.'];
    } else {
        $sonuc = gunluk_is_calistir($is);
    }

    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($sonuc + ['son' => gunluk_son_durum($is)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sonuclar[$is] = $sonuc;
}

$bekleyen   = gunluk_bekleyenler();
$ajanVar    = ajan_tetikleyebilir_mi();
$sonCalisma = $ajanVar ? ajan_son_calisma() : ['var' => false, 'durum' => '', 'sonuc' => '', 'baslangic' => '', 'adres' => ''];
$ajanSuruyor = $sonCalisma['var'] && $sonCalisma['durum'] !== 'completed';

$bildirim = null;
$adet     = (int) ($_GET['adet'] ?? 0);

if (($_GET['bildirim'] ?? '') === 'toplu_onay') {
    $bildirim = $adet > 0 ? ['basari', $adet . ' haber yayımlandı.'] : ['bilgi', 'Yayımlanacak haber yoktu.'];
}

$gruplar = [
    'ajan'  => ['İçerik üret (ajan)', 'Ajan GitHub üzerinde çalışır; birkaç dakika sürer. Ürettiği her şey onayınıza düşer, kendiliğinden yayımlanmaz.'],
    'veri'  => ['Site verisini güncelle', 'Bunlar zaten kendiliğinden güncelleniyor; beklemek istemediğinizde kullanın.'],
    'arama' => ['Arama motorları', 'Yeni yayımlanan sayfalar otomatik bildiriliyor. "Bing ve Yandex\'e bildir" tüm siteyi gönderir; arada bir (haftada bir gibi) yeterli.'],
];

$panelBasligi = 'Günlük işler';
require __DIR__ . '/ust.php';
?>

<?php if ($bildirim !== null): ?>
    <div class="uyari uyari-<?= e($bildirim[0]) ?>" style="margin-top:20px;"><?= e($bildirim[1]) ?></div>
<?php endif; ?>

<div class="kutu toplama-cubugu" style="margin-top:20px;">
    <div>
        <strong>Günlük işler</strong>
        <p class="ipucu" style="margin:4px 0 0;">
            Kurlar, grafikler, Resmî Gazete, Search Console verisi ve Google site haritası sırayla
            güncellenir, sonunda ajan haberleri toplayıp köşe yazılarını yazar.
            Yayımlama yine sizin onayınızla olur.
        </p>
    </div>
    <button type="button" class="dugme dugme-ana" id="hepsini-calistir">Hepsini sırayla yap</button>
</div>

<!-- Onay bekleyenler -->
<div class="gunluk-ozet">
    <div class="kutu">
        <div class="ipucu">Onay bekleyen haber</div>
        <div class="sayi<?= $bekleyen['haber'] === 0 ? ' sifir' : '' ?>"><?= $bekleyen['haber'] ?></div>
        <div class="islemler">
            <?php if ($bekleyen['haber'] > 0): ?>
                <form method="post" action="islem.php" style="margin:0;"
                      onsubmit="return confirm('Onay bekleyen <?= $bekleyen['haber'] ?> haberin hepsi yayımlansın mı?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="islem" value="toplu_onayla">
                    <input type="hidden" name="hepsi" value="1">
                    <input type="hidden" name="donus" value="gunluk">
                    <button type="submit" class="dugme dugme-onay">Hepsini yayınla</button>
                </form>
                <a class="dugme" href="index.php?durum=taslak">Seçerek yayınla</a>
            <?php else: ?>
                <a class="dugme" href="index.php?durum=yayinda">Yayındakiler</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="kutu">
        <div class="ipucu">Onay bekleyen köşe yazısı</div>
        <div class="sayi<?= $bekleyen['kose'] === 0 ? ' sifir' : '' ?>"><?= $bekleyen['kose'] ?></div>
        <div class="islemler">
            <a class="dugme<?= $bekleyen['kose'] > 0 ? ' dugme-ana' : '' ?>" href="kose-yazilari.php">Oku ve yayınla</a>
        </div>
    </div>

    <div class="kutu">
        <div class="ipucu">Onay bekleyen pratik bilgi</div>
        <div class="sayi<?= $bekleyen['pratik'] === 0 ? ' sifir' : '' ?>"><?= $bekleyen['pratik'] ?></div>
        <div class="islemler">
            <a class="dugme<?= $bekleyen['pratik'] > 0 ? ' dugme-ana' : '' ?>" href="pratik.php">İncele ve onayla</a>
        </div>
    </div>
</div>

<?php foreach ($gruplar as $grup => [$baslik, $aciklama]): ?>
    <div class="kutu" style="margin-top:18px;">
        <h2 style="margin:0 0 4px;font-size:1.05rem;"><?= e($baslik) ?></h2>
        <p class="ipucu" style="margin:0 0 6px;"><?= e($aciklama) ?></p>

        <?php if ($grup === 'ajan'): ?>
            <?php if (!$ajanVar): ?>
                <div class="uyari uyari-bilgi" style="margin:10px 0;">
                    Ajanı çalıştırmak için önce <a href="ajan.php">Ajan</a> sayfasından GitHub erişim anahtarını girin.
                </div>
            <?php elseif ($sonCalisma['var']): ?>
                <?php
                [$rozet, $metin] = match (true) {
                    $ajanSuruyor                       => ['rozet-taslak', 'çalışıyor'],
                    $sonCalisma['sonuc'] === 'success' => ['rozet-yayinda', 'başarılı'],
                    default                            => ['rozet-reddedildi', $sonCalisma['sonuc'] !== '' ? $sonCalisma['sonuc'] : 'başarısız'],
                };
                ?>
                <div class="satir-bilgi" style="margin:8px 0 4px;">
                    <span>Son ajan çalışması:</span>
                    <span class="rozet <?= $rozet ?>" id="ajan-rozet"><?= e($metin) ?></span>
                    <?php if ($sonCalisma['baslangic'] !== ''): ?>
                        <span><?= e(tarih_bicimle($sonCalisma['baslangic'])) ?></span>
                    <?php endif; ?>
                    <?php if ($sonCalisma['adres'] !== ''): ?>
                        <a href="<?= e($sonCalisma['adres']) ?>" target="_blank" rel="noopener">ayrıntı</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <ul class="gunluk-liste">
            <?php foreach ($isler as $anahtar => $is): ?>
                <?php if ($is['grup'] !== $grup) { continue; } ?>
                <?php $sonuc = $sonuclar[$anahtar] ?? null; ?>
                <li class="gunluk-is" data-is="<?= e($anahtar) ?>">
                    <span class="isaret" aria-hidden="true"><?= $sonuc === null ? '•' : ($sonuc['tamam'] ? '✓' : '✗') ?></span>
                    <div>
                        <div class="ad"><?= e($is['ad']) ?></div>
                        <p class="ipucu"><?= e($is['aciklama']) ?></p>
                        <p class="ipucu son"><?= e(gunluk_son_durum($anahtar)) ?></p>
                        <p class="sonuc<?= $sonuc === null ? '' : ($sonuc['tamam'] ? ' tamam' : ' hata') ?>"><?= $sonuc !== null ? e($sonuc['mesaj']) : '' ?></p>
                    </div>
                    <form method="post" action="gunluk.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="is" value="<?= e($anahtar) ?>">
                        <button type="submit" class="dugme<?= $anahtar === 'ajan' ? ' dugme-ana' : '' ?>"
                            <?= $is['grup'] === 'ajan' && (!$ajanVar || $ajanSuruyor) ? 'disabled' : '' ?>>Çalıştır</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endforeach; ?>

<script>
(function () {
    var csrf = <?= json_encode(csrf_jeton()) ?>;
    var ajanSuruyor = <?= $ajanSuruyor ? 'true' : 'false' ?>;

    function satir(is) { return document.querySelector('.gunluk-is[data-is="' + is + '"]'); }

    function ajanDugmeleri(kapali) {
        document.querySelectorAll('.gunluk-is[data-is^="ajan"] button').forEach(function (d) { d.disabled = kapali; });
    }

    // Tek bir isi calistirir; sonuc satirin altina yazilir.
    function calistir(is) {
        var li = satir(is);
        var dugme = li.querySelector('button');
        var isaret = li.querySelector('.isaret');
        var sonuc = li.querySelector('.sonuc');

        dugme.disabled = true;
        isaret.textContent = '⏳';
        sonuc.className = 'sonuc';
        sonuc.textContent = 'Çalışıyor…';

        var govde = new URLSearchParams({ csrf: csrf, is: is, ajax: '1' });

        return fetch('gunluk.php', { method: 'POST', body: govde, credentials: 'same-origin' })
            .then(function (y) {
                return y.text().then(function (metin) {
                    try { return JSON.parse(metin); } catch (e) {
                        return { tamam: false, mesaj: 'Sunucu beklenmeyen yanıt verdi (HTTP ' + y.status + ').' };
                    }
                });
            })
            .catch(function () { return { tamam: false, mesaj: 'Sunucuya ulaşılamadı.' }; })
            .then(function (r) {
                isaret.textContent = r.tamam ? '✓' : '✗';
                sonuc.className = 'sonuc ' + (r.tamam ? 'tamam' : 'hata');
                sonuc.textContent = r.mesaj;
                if (r.son) { li.querySelector('.son').textContent = r.son; }

                if (is.indexOf('ajan') === 0 && r.tamam) {
                    ajanDugmeleri(true);
                    ajanYokla();
                } else {
                    dugme.disabled = false;
                }

                return r;
            });
    }

    document.querySelectorAll('.gunluk-is form').forEach(function (f) {
        f.addEventListener('submit', function (o) {
            o.preventDefault();
            calistir(f.querySelector('input[name=is]').value);
        });
    });

    // Hepsini sirayla: once site isleri, en son ajan (ajan suruyorsa atlanir).
    // IndexNow toplu gonderimi BILEREK yok: degismeyen adresleri her gun
    // yeniden bildirmek kurallara aykiri ve kisitlanmaya (429) yol aciyor;
    // yeni sayfalar zaten yayimlaninca tek tek bildiriliyor.
    var hepsi = document.getElementById('hepsini-calistir');
    hepsi.addEventListener('click', function () {
        var sira = ['piyasa', 'grafik', 'rg', 'gsc', 'harita'];
        if (!ajanSuruyor && satir('ajan') && !satir('ajan').querySelector('button').disabled) { sira.push('ajan'); }

        hepsi.disabled = true;
        hepsi.textContent = 'Çalışıyor…';

        sira.reduce(function (zincir, is) {
            return zincir.then(function () { return calistir(is); });
        }, Promise.resolve()).then(function () {
            hepsi.disabled = false;
            hepsi.textContent = 'Hepsini sırayla yap';
        });
    });

    // Ajan bitince rozet guncellenir ve dugmeler acilir; yeni taslaklar
    // icin sayfa yenilenmesi oneriliyor.
    var yoklaniyor = false;
    function ajanYokla() {
        if (yoklaniyor) { return; }
        yoklaniyor = true;
        ajanSuruyor = true;
        var deneme = 0;

        (function yokla() {
            fetch('ajan_durum.php', { credentials: 'same-origin' })
                .then(function (y) { return y.ok ? y.json() : null; })
                .then(function (v) {
                    // Yeni tetiklenen calisma GitHub'da birkac saniye sonra
                    // gorunuyor; ilk yoklamalarda eski "completed" gelebilir.
                    if (!v || (v.calisiyor || deneme < 2)) {
                        deneme++;
                        setTimeout(yokla, Math.min(10000 + deneme * 2000, 60000));
                        return;
                    }

                    yoklaniyor = false;
                    ajanSuruyor = false;
                    ajanDugmeleri(false);

                    var rozet = document.getElementById('ajan-rozet');
                    var basarili = v.sonuc === 'success';
                    if (rozet) {
                        rozet.className = 'rozet ' + (basarili ? 'rozet-yayinda' : 'rozet-reddedildi');
                        rozet.textContent = basarili ? 'başarılı' : (v.sonuc || 'başarısız');
                    }

                    var s = satir('ajan').querySelector('.sonuc');
                    s.className = 'sonuc ' + (basarili ? 'tamam' : 'hata');
                    s.textContent = basarili
                        ? 'Ajan bitti. Onay bekleyen ' + v.bekleyen + ' haber var; yeni köşe yazıları için sayfayı yenileyin.'
                        : 'Ajan çalışması başarısız oldu (' + (v.sonuc || 'bilinmiyor') + ').';
                })
                .catch(function () { setTimeout(yokla, 30000); });
        })();
    }

    if (ajanSuruyor) { ajanYokla(); }
})();
</script>

<?php require __DIR__ . '/alt.php'; ?>
