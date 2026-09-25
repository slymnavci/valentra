<?php
declare(strict_types=1);

/**
 * Eğitim platformu: durum ve suleymanavci.com.tr'den aktarım.
 *
 * Aktarim bu sayfadaki betikle adim adim yuruyor (bkz.
 * includes/egitim_aktarim.php). Eski sitenin yonetici oturumu ve
 * uygulama anahtari yalnizca PHP oturumunda tutuluyor; veritabanina
 * yazilan tek sey uygulama anahtari (egitim platformu onu kullaniyor).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/egitim_aktarim.php';

giris_zorunlu();

/* ---------------- Betigin cagirdigi adimlar (JSON) ---------------- */

$adim = (string) ($_GET['adim'] ?? '');

if ($adim !== '') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_dogrula($_SERVER['HTTP_X_CSRF'] ?? null);
    @set_time_limit(300);

    $cevap = static function (array $veri): void {
        echo json_encode($veri, JSON_UNESCAPED_UNICODE);
        exit;
    };

    $govde = json_decode((string) file_get_contents('php://input'), true) ?: [];

    if ($adim === 'baglan') {
        $adres   = trim((string) ($govde['adres'] ?? ''));
        $anahtar = trim((string) ($govde['anahtar'] ?? ''));

        // https zorunlu (sifre ve anahtar gidiyor); yerel deneme icin
        // yalnizca kendi makinesi haric.
        if (preg_match('#^https://[a-z0-9.-]+/?$#i', $adres) !== 1
            && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/?$#', $adres) !== 1) {
            $cevap(['tamam' => false, 'hata' => 'Adres https:// ile başlayan bir alan adı olmalı.']);
        }

        $sonuc = egitim_baglan($adres, (string) ($govde['kullanici'] ?? ''), (string) ($govde['sifre'] ?? ''), $anahtar);

        if (!$sonuc['tamam']) {
            $cevap(['tamam' => false, 'hata' => $sonuc['hata']]);
        }

        $_SESSION['egitim_aktarim'] = ['adres' => $adres, 'anahtar' => $anahtar, 'token' => $sonuc['token']];

        // Egitim platformu artik bu anahtarla calisiyor: yonetici eski
        // sitede kullandigi anahtari aynen kullanmaya devam eder.
        ayar_yaz('egitim_app_anahtari', $anahtar);

        $cevap(['tamam' => true, 'ozet' => $sonuc['ozet']]);
    }

    $oturum = $_SESSION['egitim_aktarim'] ?? null;

    if (!is_array($oturum)) {
        $cevap(['tamam' => false, 'hata' => 'Aktarım oturumu yok; yeniden bağlanın.']);
    }

    if ($adim === 'tablo') {
        $cevap(egitim_tablo_aktar($oturum['adres'], $oturum['anahtar'], $oturum['token'],
                                  (string) ($govde['tablo'] ?? ''), (int) ($govde['sayfa'] ?? 0)));
    }

    if ($adim === 'dosya') {
        $cevap(egitim_dosya_aktar($oturum['adres'], $oturum['anahtar'], $oturum['token'],
                                  (string) ($govde['yol'] ?? ''), (string) ($govde['sha1'] ?? '')));
    }

    if ($adim === 'bitir') {
        ayar_yaz('egitim_aktarim_tarihi', date('Y-m-d H:i:s'));
        ayar_yaz('egitim_aktarim_ozeti', (string) json_encode($govde['ozet'] ?? [], JSON_UNESCAPED_UNICODE));
        unset($_SESSION['egitim_aktarim']);
        $cevap(['tamam' => true]);
    }

    $cevap(['tamam' => false, 'hata' => 'Bilinmeyen adım.']);
}

/* ---------------- Egitim yoneticisi (form) ---------------- */

$bildirim = '';
$hata     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'yonetici') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $sonuc = egitim_yonetici_kaydet(
        (string) ($_POST['kullanici_adi'] ?? ''),
        (string) ($_POST['ad'] ?? ''),
        (string) ($_POST['eposta'] ?? ''),
        (string) ($_POST['sifre'] ?? '')
    );

    if ($sonuc['tamam']) {
        $bildirim = $sonuc['mesaj'];
    } else {
        $hata = $sonuc['mesaj'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'kilit') {
    csrf_dogrula($_POST['csrf'] ?? null);
    $silinen  = egitim_kilitleri_temizle();
    $bildirim = 'Giriş kilitleri temizlendi (' . $silinen . ' hatalı deneme kaydı silindi).';
}

/* ---------------- Sayfa ---------------- */

$anahtarVar  = ayar_oku('egitim_app_anahtari') !== '';
$sonAktarim  = ayar_oku('egitim_aktarim_tarihi');
$sonOzet     = json_decode(ayar_oku('egitim_aktarim_ozeti'), true) ?: [];
$icerikVar   = is_file(EGITIM_KOK . '/content/dersler.json');

try {
    $uyeSayisi = (int) db()->query('SELECT COUNT(*) FROM kullanici_hesap')->fetchColumn();
    $yoneticiler = db()->query(
        "SELECT kullanici_adi, ad, son_giris FROM kullanici_hesap WHERE rol = 'yonetici' ORDER BY kullanici_adi"
    )->fetchAll();
} catch (PDOException $e) {
    $uyeSayisi = null;
    $yoneticiler = [];
}

$panelBasligi = 'Eğitim';
require __DIR__ . '/ust.php';
?>

<div class="kutu" style="margin-top:20px;">
    <h2 style="margin-top:0;">TMS/TFRS Eğitim Platformu</h2>
    <p>
        Platform <a href="/egitim/" target="_blank" rel="noopener"><strong>valentra.com.tr/egitim</strong></a>
        adresinde çalışır; giriş, üyeler, dersler, soru bankası, ilerleme ve forum kendi
        <em>Yönetim</em> ekranından yönetilir. Bu sayfa yalnızca durum ve ilk aktarım içindir.
    </p>
    <ul style="line-height:1.8;margin:0;">
        <li>Uygulama anahtarı: <?= $anahtarVar ? '<strong style="color:var(--yesil);">tanımlı</strong>' : '<strong style="color:var(--kirmizi);">yok</strong> — aktarım sırasında eski sitedeki anahtar kullanılacak' ?></li>
        <li>İçerik (dersler/sorular): <?= $icerikVar ? '<strong style="color:var(--yesil);">var</strong>' : '<strong style="color:var(--kirmizi);">yok</strong>' ?></li>
        <li>Üye sayısı: <?= $uyeSayisi === null ? '—' : '<strong>' . $uyeSayisi . '</strong>' ?></li>
        <li>Son aktarım: <?= $sonAktarim !== '' ? e(tarih_bicimle($sonAktarim)) : 'yapılmadı' ?></li>
    </ul>
</div>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:20px;"><?= e($bildirim) ?></div>
<?php endif; ?>
<?php if ($hata !== ''): ?>
    <div class="uyari uyari-hata" style="margin-top:20px;"><?= e($hata) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:20px;" id="yonetici">
    <h2 style="margin-top:0;">Eğitim yöneticisi</h2>
    <p class="ipucu">
        Eğitim platformunun kendi Yönetim ekranına (üyeler, dersler, sorular) girecek hesap.
        Kullanıcı adı zaten varsa şifresi ve rolü güncellenir; yoksa yönetici olarak açılır.
        Şifre yalnızca şifrelenmiş (bcrypt) hâliyle saklanır.
    </p>

    <?php if ($yoneticiler !== []): ?>
        <p>Mevcut yöneticiler:
            <?php foreach ($yoneticiler as $y): ?>
                <strong><?= e((string) $y['kullanici_adi']) ?></strong> (<?= e((string) $y['ad']) ?>)<?= $y !== end($yoneticiler) ? ',' : '' ?>
            <?php endforeach; ?>
        </p>
    <?php else: ?>
        <p><strong style="color:var(--kirmizi);">Eğitimde henüz yönetici yok.</strong> Aşağıdan oluşturun ya da eski siteden aktarın.</p>
    <?php endif; ?>

    <form method="post" action="egitim.php#yonetici" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="yonetici">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
            <div class="alan">
                <label for="y_kullanici">Kullanıcı adı</label>
                <input type="text" id="y_kullanici" name="kullanici_adi" value="savci" required>
            </div>
            <div class="alan">
                <label for="y_ad">Ad soyad</label>
                <input type="text" id="y_ad" name="ad" value="Süleyman Avcı" required>
            </div>
            <div class="alan">
                <label for="y_eposta">E-posta (isteğe bağlı)</label>
                <input type="email" id="y_eposta" name="eposta">
            </div>
            <div class="alan">
                <label for="y_sifre">Şifre</label>
                <input type="password" id="y_sifre" name="sifre" required minlength="8" autocomplete="new-password">
            </div>
        </div>
        <button type="submit" class="dugme dugme-ana">Yöneticiyi kaydet</button>
    </form>

    <form method="post" action="egitim.php#yonetici" style="margin-top:12px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="kilit">
        <button type="submit" class="dugme">Giriş kilitlerini temizle</button>
        <span class="ipucu">Aynı bağlantıdan 15 dakikada 10 hatalı girişte eğitim girişi geçici olarak kilitlenir.</span>
    </form>
</div>

<div class="kutu" style="margin-top:20px;">
    <h2 style="margin-top:0;">suleymanavci.com.tr'den aktar</h2>
    <p class="ipucu">
        Eski sitedeki dersler, materyal dosyaları, sorular, üyeler (şifreleriyle), ilerleme kayıtları,
        denemeler, forum ve ders asistanı geçmişi buraya kopyalanır. <strong>Finansal raporlar ve
        verileri aktarılmaz.</strong> Eski sitede hiçbir şey silinmez. Aktarımı tekrar çalıştırmak
        güvenlidir: kayıtlar güncellenir, aynı dosyalar yeniden indirilmez. Eski sitede yönetici
        hesabınızla giriş yapılır; şifreniz saklanmaz.
    </p>

    <form id="aktarimFormu" autocomplete="off">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
            <div class="alan">
                <label for="adres">Eski site</label>
                <input type="text" id="adres" value="https://suleymanavci.com.tr" required>
            </div>
            <div class="alan">
                <label for="kullanici">Yönetici kullanıcı adı</label>
                <input type="text" id="kullanici" required>
            </div>
            <div class="alan">
                <label for="sifre">Şifre</label>
                <input type="password" id="sifre" required>
            </div>
            <div class="alan">
                <label for="anahtar">Uygulama anahtarı</label>
                <input type="password" id="anahtar" required>
                <div class="ipucu">Eski sitede Yönetim → Pratik Sistemi Bağlantısı'na girdiğiniz anahtar.</div>
            </div>
        </div>
        <button type="submit" class="dugme dugme-ana" id="aktarDugme">Aktarımı başlat</button>
    </form>

    <div id="aktarimDurum" style="margin-top:14px;display:none;">
        <div style="height:10px;border-radius:6px;background:var(--zemin, #eef1f5);overflow:hidden;">
            <div id="aktarimCubuk" style="height:100%;width:0;background:var(--yesil);transition:width .2s;"></div>
        </div>
        <p id="aktarimYazi" style="margin:8px 0 0;"></p>
        <ul id="aktarimHatalar" style="color:var(--kirmizi);margin:6px 0 0;padding-left:1.2em;"></ul>
    </div>

    <?php if ($sonOzet !== []): ?>
        <details style="margin-top:12px;">
            <summary class="ipucu" style="cursor:pointer;">Son aktarımın özeti</summary>
            <pre style="white-space:pre-wrap;font-size:.85rem;"><?= e((string) json_encode($sonOzet, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
        </details>
    <?php endif; ?>
</div>

<script>
(function () {
    var csrf = <?= json_encode(csrf_jeton()) ?>;
    var form = document.getElementById('aktarimFormu');

    function istek(adim, govde) {
        return fetch('egitim.php?adim=' + adim, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF': csrf},
            body: JSON.stringify(govde || {}),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function yaz(metin, oran) {
        document.getElementById('aktarimYazi').textContent = metin;
        if (oran !== undefined) { document.getElementById('aktarimCubuk').style.width = Math.round(oran * 100) + '%'; }
    }

    function hata(metin) {
        var li = document.createElement('li');
        li.textContent = metin;
        document.getElementById('aktarimHatalar').appendChild(li);
    }

    form.addEventListener('submit', async function (olay) {
        olay.preventDefault();
        var dugme = document.getElementById('aktarDugme');
        dugme.disabled = true;
        document.getElementById('aktarimDurum').style.display = 'block';
        document.getElementById('aktarimHatalar').innerHTML = '';
        yaz('Eski siteye bağlanılıyor…', 0);

        var sonuc = await istek('baglan', {
            adres: document.getElementById('adres').value.trim(),
            kullanici: document.getElementById('kullanici').value.trim(),
            sifre: document.getElementById('sifre').value,
            anahtar: document.getElementById('anahtar').value.trim()
        }).catch(function (e) { return {tamam: false, hata: String(e)}; });

        document.getElementById('sifre').value = '';

        if (!sonuc.tamam) { yaz('Bağlanılamadı.'); hata(sonuc.hata); dugme.disabled = false; return; }

        var ozet = sonuc.ozet, boyut = ozet.sayfa_boyutu || 500;
        var isler = [];
        Object.keys(ozet.tablolar).forEach(function (t) {
            var n = ozet.tablolar[t];
            if (n === null) { return; }
            var sayfa = Math.max(1, Math.ceil(n / boyut));
            for (var s = 0; s < sayfa; s++) { isler.push({tur: 'tablo', tablo: t, sayfa: s}); }
        });
        ozet.dosyalar.forEach(function (d) { isler.push({tur: 'dosya', yol: d.yol, sha1: d.sha1, boyut: d.boyut}); });

        var sayac = {satir: 0, dosya: 0, atlanan: 0, hata: 0};

        for (var i = 0; i < isler.length; i++) {
            var is = isler[i];
            yaz((is.tur === 'tablo' ? 'Tablo: ' + is.tablo + ' (sayfa ' + (is.sayfa + 1) + ')' : 'Dosya: ' + is.yol)
                + ' — ' + (i + 1) + ' / ' + isler.length, i / isler.length);

            var r = await istek(is.tur, is).catch(function (e) { return {tamam: false, hata: String(e)}; });

            if (!r.tamam) { sayac.hata++; hata(r.hata); continue; }
            if (is.tur === 'tablo') { sayac.satir += r.satir; }
            else if (r.atlandi) { sayac.atlanan++; }
            else { sayac.dosya++; }
        }

        await istek('bitir', {ozet: {tablolar: ozet.tablolar, dosya_sayisi: ozet.dosyalar.length, sonuc: sayac}});

        yaz('Tamamlandı: ' + sayac.satir + ' kayıt, ' + sayac.dosya + ' dosya indirildi'
            + (sayac.atlanan ? ', ' + sayac.atlanan + ' dosya zaten güncel' : '')
            + (sayac.hata ? ', ' + sayac.hata + ' hata (aşağıda; aktarımı yeniden başlatabilirsiniz)' : '') + '.', 1);
        dugme.disabled = false;
    });
})();
</script>

<?php require __DIR__ . '/alt.php'; ?>
