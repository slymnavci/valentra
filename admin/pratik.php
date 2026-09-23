<?php
declare(strict_types=1);

/**
 * Pratik bilgiler onay ekranı.
 *
 * Ajanin getirdigi degerler burada onaylanana kadar sitede gorunmez.
 * Ekran her aday degerin yaninda YAYINDAKI degeri de gosteriyor:
 * onaylayan kisi neyin neye donusecegini gormeden karar vermemeli.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pratik.php';
require_once __DIR__ . '/../includes/ayarlar.php';
require_once __DIR__ . '/../includes/ekonomi.php';
require_once __DIR__ . '/../includes/grafik.php';

giris_zorunlu();

$bildirim   = '';
$apiDeneme  = null;   // panelden yapilan API denemesinin sonucu
$apiDenenen = '';     // hangi satir denendi

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula($_POST['csrf'] ?? null);

    $islem = (string) ($_POST['islem'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);

    if ($islem === 'onayla' && $id > 0) {
        pratik_onayla($id);
        $bildirim = 'Değer yayına alındı.';
    } elseif ($islem === 'reddet' && $id > 0) {
        pratik_reddet($id);
        $bildirim = 'Aday değer reddedildi; yayındaki değer korundu.';
    } elseif ($islem === 'elle' && $id > 0) {
        pratik_elle_yaz(
            $id,
            (string) ($_POST['deger'] ?? ''),
            (string) ($_POST['donem'] ?? '')
        );
        $bildirim = 'Değer elle güncellendi.';
    } elseif ($islem === 'kaynak' && $id > 0) {
        pratik_kaynak_yaz(
            $id,
            (string) ($_POST['kaynak_url'] ?? ''),
            (string) ($_POST['kaynak_adi'] ?? '')
        );
        $bildirim = 'Kaynak adresi güncellendi.';
    } elseif ($islem === 'evds_kaydet') {
        ayar_yaz('evds_anahtari', trim((string) ($_POST['evds_anahtari'] ?? '')));
        $bildirim = 'EVDS anahtarı kaydedildi.';
    } elseif ($islem === 'api_dene') {
        /*
         * Panelden tek satiri API'den cekip ONAYA koyar.
         *
         * Neden panelden: ajan haftada bir kosuyor ve GitHub'in IP'si
         * kamu sitelerinde engellenebiliyor. Site Turkiye'de barindigi
         * icin buradan yapilan istek cogu zaman gecerken ajanin
         * istegi dusuyor. Bir de anahtarin dogru girilip girilmedigi
         * ancak boyle aninda gorulebiliyor.
         *
         * Yayindaki degere DOKUNMUYOR: basarili sonuc da aday olarak
         * yaziliyor, onay yine burada veriliyor.
         */
        $deneAnahtar = (string) ($_POST['anahtar'] ?? '');
        $seri        = ekonomi_serisi($deneAnahtar);

        if ($seri === null) {
            $bildirim = 'Bu bilgi için tanımlı bir API serisi yok.';
        } else {
            $apiDeneme = ekonomi_oku($seri, ayar_oku('evds_anahtari'));
            $apiDenenen = $deneAnahtar;

            if ($apiDeneme['tamam']) {
                try {
                    $yazim = pratik_aday_yaz([
                        'anahtar' => $deneAnahtar,
                        'deger'   => $apiDeneme['deger'],
                        'donem'   => $apiDeneme['donem'],
                        'not'     => $apiDeneme['not'] . ' (panelden çekildi)',
                        'guven'   => 95,
                        'seri'    => $apiDeneme['seri'] ?? null,
                    ]);

                    /*
                     * Gelen deger yayindakinin aynisiysa aday
                     * YAZILMIYOR. Ekranin "onay bekliyor" demesi
                     * yanlis olurdu: onay kutusu hic acilmayacak ve
                     * kullanici bekledigi seyi bulamayacakti.
                     */
                    $apiDeneme['durum'] = $yazim['durum'];
                } catch (Throwable $e) {
                    $apiDeneme['hata']  = 'Değer alındı ama kaydedilemedi: '
                                        . $e->getMessage();
                    $apiDeneme['tamam'] = false;
                }
            }
        }
    } elseif ($islem === 'tumunu_onayla') {
        $adet = 0;

        foreach (pratik_listele() as $satir) {
            if (trim((string) ($satir['aday_deger'] ?? '')) !== '') {
                pratik_onayla((int) $satir['id']);
                $adet++;
            }
        }

        $bildirim = $adet . ' değer yayına alındı.';
    }
}

$satirlar = pratik_listele();
$bekleyen = pratik_bekleyen_sayisi();

$panelBasligi = 'Pratik Bilgiler';
require __DIR__ . '/ust.php';
?>

<p style="margin:20px 0 0;"><a href="index.php">&larr; Panele dön</a></p>

<?php if ($bildirim !== ''): ?>
    <div class="uyari uyari-basari" style="margin-top:14px;"><?= e($bildirim) ?></div>
<?php endif; ?>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Pratik bilgiler</h2>
    <p class="ipucu" style="margin:0;">
        Ajan bu değerleri resmî kaynak sayfalarından okur ve
        <strong>onayınıza</strong> sunar. Onaylamadığınız hiçbir değer
        sitede görünmez. Buradaki bir hata haberdeki hatadan daha
        tehlikeli: rakam doğrudan hesaplamada kullanılır, bu yüzden her
        değeri kaynağıyla karşılaştırın.
    </p>

    <?php if ($bekleyen > 0): ?>
        <form method="post" style="margin:14px 0 0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="tumunu_onayla">
            <button type="submit" class="dugme dugme-ana">
                Onay bekleyen <?= (int) $bekleyen ?> değerin tümünü yayına al
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="kutu" style="margin-top:18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;">Ekonomik veri kaynakları</h2>
    <p class="ipucu" style="margin:0 0 10px;">
        Sayısal göstergeler artık sayfa okunarak değil, kaynağın kendi
        <strong>makine okunur ucundan</strong> alınıyor. Model devreye
        girmediği için okuma hatası olmuyor; değer yine onayınıza geliyor.
    </p>

    <table class="liste-tablo" style="margin:0 0 14px;">
        <tbody>
            <tr>
                <td>Dünya Bankası</td>
                <td>GSYH, kişi başına gelir, büyüme</td>
                <td><strong>Anahtar gerekmez</strong></td>
            </tr>
            <tr>
                <td>IMF — World Economic Outlook</td>
                <td>İşsizlik, kamu borcu / GSYH</td>
                <td><strong>Anahtar gerekmez</strong></td>
            </tr>
            <tr>
                <td>TCMB — EVDS</td>
                <td>Politika faizi, TÜFE</td>
                <td><?= ayar_oku('evds_anahtari') !== ''
                        ? 'Anahtar girildi'
                        : '<strong>Anahtar gerekli</strong>' ?></td>
            </tr>
        </tbody>
    </table>

    <p class="ipucu" style="margin:0 0 10px;">
        EVDS anahtarı ücretsizdir:
        <a href="https://evds2.tcmb.gov.tr/index.php?/evds/login" target="_blank"
           rel="noopener">evds2.tcmb.gov.tr</a> adresinden üye olun, giriş
        yaptıktan sonra <em>Profil &rarr; API Anahtarı</em> bölümünden kopyalayıp
        buraya yapıştırın. Anahtar girilene kadar politika faizi ve enflasyon
        eski yoldan, sayfa okunarak toplanmaya devam eder.
    </p>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
        <input type="hidden" name="islem" value="evds_kaydet">

        <div class="alan">
            <label for="evds">EVDS API anahtarı</label>
            <input type="text" id="evds" name="evds_anahtari"
                   value="<?= e(ayar_oku('evds_anahtari')) ?>"
                   placeholder="TCMB EVDS'den aldığınız anahtar">
        </div>

        <button type="submit" class="dugme">Kaydet</button>
    </form>
</div>

<?php
$oncekiGrup = null;

foreach ($satirlar as $satir):
    $grup       = (string) $satir['grup'];
    $adayDeger  = trim((string) ($satir['aday_deger'] ?? ''));
    $yayinDeger = trim((string) ($satir['deger'] ?? ''));
    $satirSeri  = ekonomi_serisi((string) $satir['anahtar']);
    $satirDeneme = ($apiDenenen === (string) $satir['anahtar']) ? $apiDeneme : null;

    if ($grup !== $oncekiGrup):
        $oncekiGrup = $grup;
        ?>
        <div class="bolum-basligi" style="margin-top:26px;">
            <h2><?= e(pratik_grup_adi($grup)) ?></h2>
            <span class="cizgi"></span>
        </div>
    <?php endif; ?>

    <div class="kutu pratik-satir <?= $adayDeger !== '' ? 'bekliyor' : '' ?>">
        <div class="pratik-ust">
            <div>
                <h3><?= e($satir['baslik']) ?></h3>
                <p class="ipucu"><?= e((string) $satir['aciklama']) ?></p>
            </div>

            <?php if (!empty($satir['kaynak_url'])): ?>
                <a class="pratik-kaynak" href="<?= e(guvenli_url((string) $satir['kaynak_url'])) ?>"
                   target="_blank" rel="noopener">
                    <?= e((string) $satir['kaynak_adi']) ?> &nearr;
                </a>
            <?php endif; ?>
        </div>

        <div class="pratik-degerler">
            <div class="pratik-kutu">
                <span class="etiketcik">Yayında</span>
                <?php if ($yayinDeger !== ''): ?>
                    <pre class="pratik-deger"><?= e($yayinDeger) ?></pre>
                    <span class="ipucu">
                        <?= e((string) ($satir['donem'] ?? '')) ?>
                        <?php if (!empty($satir['onay_tarihi'])): ?>
                            &middot; onay: <?= e(tarih_bicimle((string) $satir['onay_tarihi'], false)) ?>
                        <?php endif; ?>
                    </span>
                <?php else: ?>
                    <p class="ipucu" style="margin:0;">Henüz değer yok.</p>
                <?php endif; ?>
            </div>

            <?php if ($adayDeger !== ''): ?>
                <div class="pratik-kutu aday">
                    <span class="etiketcik">Onay bekliyor</span>
                    <pre class="pratik-deger"><?= e($adayDeger) ?></pre>
                    <span class="ipucu">
                        <?= e((string) ($satir['aday_donem'] ?? '')) ?>
                        <?php if ($satir['aday_guven'] !== null): ?>
                            &middot; güven %<?= (int) $satir['aday_guven'] ?>
                        <?php endif; ?>
                    </span>

                    <?php if (!empty($satir['aday_notu'])): ?>
                        <p class="ipucu" style="margin:8px 0 0;">
                            Ajan notu: <?= e((string) $satir['aday_notu']) ?>
                        </p>
                    <?php endif; ?>

                    <?php
                    /*
                     * Onaylanacak grafik ONCEDEN gosteriliyor. Seri degerle
                     * birlikte yayina giriyor; gorulmeden onaylanan bir
                     * grafik, rakami gorulmeden onaylanan bir deger kadar
                     * risklidir.
                     */
                    $adaySeri = pratik_seri_oku($satir['aday_seri'] ?? null);
                    ?>
                    <?php if ($satirSeri !== null && isset($satirSeri['grafik']) && $adaySeri !== []): ?>
                        <?= grafik_ciz($adaySeri, [
                            'tur'    => (string) $satirSeri['grafik']['tur'],
                            'baslik' => 'Onaylanınca yayına girecek grafik',
                            'birim'  => (string) $satirSeri['birim'],
                            'kaynak' => (string) ($satir['kaynak_adi'] ?? ''),
                            'ad'     => (string) $satir['baslik'],
                        ]) ?>
                    <?php endif; ?>

                    <div class="pratik-dugmeler">
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="onayla">
                            <input type="hidden" name="id" value="<?= (int) $satir['id'] ?>">
                            <button type="submit" class="dugme dugme-ana">Yayına al</button>
                        </form>

                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                            <input type="hidden" name="islem" value="reddet">
                            <input type="hidden" name="id" value="<?= (int) $satir['id'] ?>">
                            <button type="submit" class="dugme dugme-ret">Reddet</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($satirSeri !== null): ?>
            <div class="pratik-api">
                <p class="ipucu" style="margin:0 0 8px;">
                    Bu değer <strong><?= e((string) $satirSeri['kaynak_adi']) ?></strong>
                    kaynağından <code><?= e((string) $satirSeri['seri']) ?></code>
                    serisiyle okunuyor.
                    <?php if (ekonomi_anahtar_ister((string) $satirSeri['saglayici'])
                              && ayar_oku('evds_anahtari') === ''): ?>
                        <strong>EVDS anahtarı girilmediği için şu an kullanılamıyor.</strong>
                    <?php endif; ?>
                </p>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="islem" value="api_dene">
                    <input type="hidden" name="anahtar" value="<?= e((string) $satir['anahtar']) ?>">
                    <button type="submit" class="dugme">Kaynaktan şimdi çek</button>
                </form>

                <?php if ($satirDeneme !== null): ?>
                    <div class="uyari <?= $satirDeneme['tamam'] ? 'uyari-basari' : 'uyari-hata' ?>"
                         style="margin-top:12px;">
                        <?php if ($satirDeneme['tamam']): ?>
                            <strong><?= ($satirDeneme['durum'] ?? '') === 'degismedi'
                                ? 'Alındı — yayındaki değerle aynı, onay gerekmiyor.'
                                : 'Alındı — onay bekliyor.' ?></strong>
                            <?= nl2br(e($satirDeneme['deger'])) ?>
                            (<?= e($satirDeneme['donem']) ?>)
                        <?php else: ?>
                            <strong>Alınamadı.</strong> <?= e($satirDeneme['hata']) ?>
                        <?php endif; ?>
                    </div>

                    <details style="margin-top:8px;">
                        <summary class="ipucu">Teknik ayrıntı</summary>
                        <table class="liste-tablo" style="margin:10px 0 0;">
                            <tbody>
                                <tr><td>Adres</td>
                                    <td style="word-break:break-all;"><?=
                                        e(ekonomi_adres_gizle((string) $satirDeneme['adres'])) ?></td></tr>
                                <tr><td>HTTP durumu</td>
                                    <td><?= (int) $satirDeneme['kod'] ?></td></tr>
                                <tr><td>Yanıt boyutu</td>
                                    <td><?= number_format((int) $satirDeneme['boyut']) ?> bayt</td></tr>
                                <tr><td>Yanıtın başı</td>
                                    <td style="word-break:break-all;"><?=
                                        e((string) $satirDeneme['ham']) ?></td></tr>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <details class="pratik-elle">
            <summary>Elle düzenle</summary>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="islem" value="elle">
                <input type="hidden" name="id" value="<?= (int) $satir['id'] ?>">

                <div class="alan">
                    <label for="deger-<?= (int) $satir['id'] ?>">Değer</label>
                    <textarea id="deger-<?= (int) $satir['id'] ?>" name="deger"
                              rows="4"><?= e($yayinDeger) ?></textarea>
                </div>

                <div class="alan">
                    <label for="donem-<?= (int) $satir['id'] ?>">Geçerlilik dönemi</label>
                    <input type="text" id="donem-<?= (int) $satir['id'] ?>" name="donem"
                           value="<?= e((string) ($satir['donem'] ?? '')) ?>"
                           placeholder="2026 yılı">
                </div>

                <button type="submit" class="dugme">Kaydet</button>
            </form>
        </details>

        <details class="pratik-elle">
            <summary>Kaynak adresini değiştir</summary>

            <p class="ipucu" style="margin:12px 0 0;">
                Ajan değeri bu adresten okur. Bazı adresler yıla bağlıdır
                (örnek: <code>.../2026-pratik-bilgiler/</code>); yıl dönünce
                buradan güncelleyin.
            </p>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="islem" value="kaynak">
                <input type="hidden" name="id" value="<?= (int) $satir['id'] ?>">

                <div class="alan">
                    <label for="kurl-<?= (int) $satir['id'] ?>">Kaynak adresi</label>
                    <input type="text" id="kurl-<?= (int) $satir['id'] ?>" name="kaynak_url"
                           value="<?= e((string) ($satir['kaynak_url'] ?? '')) ?>"
                           placeholder="https://...">
                </div>

                <div class="alan">
                    <label for="kad-<?= (int) $satir['id'] ?>">Kaynak adı</label>
                    <input type="text" id="kad-<?= (int) $satir['id'] ?>" name="kaynak_adi"
                           value="<?= e((string) ($satir['kaynak_adi'] ?? '')) ?>">
                </div>

                <button type="submit" class="dugme">Kaydet</button>
            </form>
        </details>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/alt.php'; ?>
