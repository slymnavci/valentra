<?php
declare(strict_types=1);

/**
 * Kurumsal sayfalar: Hakkımızda, Yayın İlkeleri, Künye, İletişim.
 *
 * Adresler /hakkimizda, /yayin-ilkeleri, /kunye, /iletisim (temiz adres)
 * ya da kurumsal.php?s=... Icerik bkz. includes/kurumsal.php.
 *
 * Metinler sitenin GERCEKTEN nasil calistigini anlatiyor (ajan, editor
 * onayi, yapay zeka destegi, kaynak baglantisi); Google'in ve okurun
 * gormek istedigi sey bu. Calisma bicimi degisirse metin de degismeli.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/kurumsal.php';
require_once __DIR__ . '/includes/haberler.php';

$sayfalar = kurumsal_sayfalar();
$sayfa    = (string) ($_GET['s'] ?? '');

if (!isset($sayfalar[$sayfa])) {
    http_response_code(404);
    $sayfaBasligi = 'Sayfa bulunamadı — Valentra';
    require __DIR__ . '/includes/sayfa_ust.php';
    echo '<div class="bos-durum"><strong>Sayfa bulunamadı.</strong></div>';
    require __DIR__ . '/includes/sayfa_alt.php';
    exit;
}

$kunye    = kunye_bilgileri();
$eposta   = $kunye['kunye_eposta'];
$otomatik = haber_otomatik_yayin_acik();

$aciklamalar = [
    'hakkimizda'     => 'Valentra nedir, haberler nasıl hazırlanır: kaynaklar, yapay zekâ desteği ve editör denetimi.',
    'yayin-ilkeleri' => 'Valentra yayın ilkeleri: kaynak gösterme, doğruluk, tarafsızlık, yapay zekâ kullanımı ve düzeltme politikası.',
    'kunye'          => 'Valentra künyesi: yayın sahibi, sorumlu müdür, yönetim yeri ve iletişim bilgileri.',
    'iletisim'       => 'Valentra ile iletişim: düzeltme, içerik kaldırma ve genel talepler.',
    'gizlilik'       => 'Valentra gizlilik ve çerez politikası: ziyaret kayıtları, çerezler, Google Analytics ve reklamlar, KVKK kapsamındaki haklarınız.',
];

$sayfaBasligi  = $sayfalar[$sayfa] . ' — Valentra';
$sayfaAciklama = $aciklamalar[$sayfa];
$seoAdres      = site_adresi() . kurumsal_yolu($sayfa);

$epostaBag = $eposta !== ''
    ? '<a href="mailto:' . e($eposta) . '">' . e($eposta) . '</a>'
    : '<a href="' . e(kurumsal_yolu('iletisim')) . '">İletişim</a> sayfasındaki adres';

require __DIR__ . '/includes/sayfa_ust.php';
?>

<div class="kategori-basligi">
    <h1><?= e($sayfalar[$sayfa]) ?></h1>
</div>

<div class="ana-duzen">
    <div class="ana-kolon">
        <nav class="kurumsal-sekmeler" aria-label="Kurumsal sayfalar">
            <?php foreach ($sayfalar as $anahtar => $ad): ?>
                <a href="<?= e(kurumsal_yolu($anahtar)) ?>"<?= $anahtar === $sayfa ? ' aria-current="page"' : '' ?>><?= e($ad) ?></a>
            <?php endforeach; ?>
        </nav>

        <article class="kurumsal-metin">

        <?php if ($sayfa === 'hakkimizda'): ?>

            <p><strong>Valentra</strong>, Türkiye'den ve dünyadan günün haberlerini tek yerde,
                kısa ve anlaşılır özetler halinde sunan bir internet haber sitesidir.
                Gündem, politika, ekonomi, dünya, spor, teknoloji, sağlık ve kültür-sanat
                başlıklarında yayın yapar.</p>

            <h2>Haberler nasıl hazırlanır?</h2>
            <ol>
                <li><strong>Kaynaklar.</strong> Haberler; haber ajanslarının, gazetelerin ve resmî kurumların
                    kamuya açık yayınlarından (RSS beslemeleri ve internet sayfaları) derlenir.</li>
                <li><strong>Seçim ve özet.</strong> Aynı olayı veren haberler birleştirilir; her haber
                    yapay zekâ desteğiyle Türkçe, tarafsız ve kısa bir özet olarak yeniden yazılır.
                    Yorum, tık tuzağı, reklam ve doğrulanmamış iddia içeren içerik elenir.</li>
                <li><strong>Denetim.</strong> <?= $otomatik
                    ? 'Özetler, editörlerin belirlediği kurallara göre yayına alınır ve yayından sonra editörlerce denetlenir.'
                    : 'Özetler yayına alınmadan önce Valentra editörlerinin onayından geçer.' ?></li>
                <li><strong>Kaynak gösterme.</strong> Her haberin altında kaynağın adı ve haberin aslına giden
                    bağlantı bulunur. Ayrıntı için okurlarımızı kaynağa yönlendiririz.</li>
            </ol>

            <h2>Köşe yazıları</h2>
            <p>"Valentra Diyor ki" bölümündeki köşe yazıları, günün haberlerinden yapay zekâ desteğiyle
                hazırlanır. Yazar adları Valentra'nın yapay zekâ destekli yazar karakterleridir; bu durum
                her yazının altında ve yazar sayfalarında belirtilir.</p>

            <h2>Veriler</h2>
            <p>Piyasa verileri Yahoo Finance ve İş Yatırım'dan, Süper Lig puan durumu ve fikstürü TFF'den,
                ekonomik göstergeler TCMB, Dünya Bankası ve IMF'den alınır; her bölümde kaynağı ve
                güncellenme zamanı yazılıdır. Piyasa bilgileri yatırım tavsiyesi değildir.</p>

            <p>Yayın anlayışımız için <a href="<?= e(kurumsal_yolu('yayin-ilkeleri')) ?>">Yayın İlkeleri</a>,
                yayıncı bilgileri için <a href="<?= e(kurumsal_yolu('kunye')) ?>">Künye</a> sayfasına bakabilirsiniz.</p>

        <?php elseif ($sayfa === 'yayin-ilkeleri'): ?>

            <h2 id="kaynak">1. Kaynak gösterme</h2>
            <p>Her haber, dayandığı kaynağın adıyla ve haberin aslına giden bağlantıyla yayımlanır.
                Başkasına ait metinler olduğu gibi kopyalanmaz; kısa özet olarak yeniden yazılır.
                İmzalı köşe yazıları ve makaleler haber olarak yeniden yayımlanmaz.</p>

            <h2 id="dogruluk">2. Doğruluk ve tarafsızlık</h2>
            <p>Yalnızca güvenilir kaynaklarda yer alan, olguya dayalı gelişmeler haberleştirilir.
                Doğrulanmamış iddialar, kulis bilgileri ve spekülasyonlar haber olarak sunulmaz.
                Taraflar eşit mesafeyle aktarılır; haber metinlerinde yorum ve övgü/yergi yer almaz.</p>

            <h2 id="yapay-zeka">3. Yapay zekâ kullanımı</h2>
            <p>Valentra'da haber özetleri ve köşe yazıları yapay zekâ desteğiyle hazırlanır.
                <?= $otomatik
                    ? 'İçerik, editörlerin belirlediği kurallara göre yayına alınır ve yayından sonra denetlenir.'
                    : 'Haber özetleri yayına alınmadan önce editör onayından geçer.' ?>
                Köşe yazılarının yazar adları yapay zekâ destekli yazar karakterleridir ve bu durum
                açıkça belirtilir. Özetler dayandığı haberin içeriğiyle sınırlı tutulur; kaynakta
                olmayan rakam, tarih ya da bilgi eklenmez.</p>

            <h2 id="ozel-hayat">4. Özel hayat ve hassas içerik</h2>
            <p>Kişilerin özel hayatına, sağlık verilerine ve çocuklara ilişkin bilgiler yayımlanmaz;
                suç haberlerinde masumiyet karinesine uyulur.</p>

            <h2 id="ayrim">5. Reklam ve tanıtım</h2>
            <p>Ürün tanıtımı, kampanya duyurusu ve reklam niteliğindeki içerik haber olarak yayımlanmaz.</p>

            <h2 id="duzeltme">6. Düzeltme ve kaldırma</h2>
            <p>Bir haberde hata olduğunu düşünüyorsanız ya da size ait bir içeriğin kaldırılmasını
                istiyorsanız <?= $epostaBag ?> üzerinden haberin bağlantısıyla bize yazın.
                Bildirimler incelenir; hata varsa haber düzeltilir ya da yayından kaldırılır.</p>

            <h2 id="yatirim">7. Piyasa bilgileri</h2>
            <p>Piyasa sayfalarındaki veriler, hesaplanan ölçüler ve aracı kurumların analist görüşleri
                kaynağıyla birlikte, bilgilendirme amacıyla verilir; yatırım tavsiyesi değildir.</p>

        <?php elseif ($sayfa === 'kunye'): ?>

            <dl class="kunye-listesi">
                <div><dt>Yayın adı</dt><dd>Valentra</dd></div>
                <div><dt>Yayın türü</dt><dd>İnternet haber sitesi (yaygın, süreli)</dd></div>
                <div><dt>Yayın dili</dt><dd>Türkçe</dd></div>
                <div><dt>Adres</dt><dd><a href="<?= e(site_adresi()) ?>/"><?= e(preg_replace('#^https?://#', '', site_adresi())) ?></a></dd></div>
                <?php foreach (kunye_alanlari() as $anahtar => $alan): ?>
                    <?php if ($kunye[$anahtar] !== ''): ?>
                        <div>
                            <dt><?= e($alan['etiket']) ?></dt>
                            <dd><?= $anahtar === 'kunye_eposta'
                                ? '<a href="mailto:' . e($kunye[$anahtar]) . '">' . e($kunye[$anahtar]) . '</a>'
                                : nl2br(e($kunye[$anahtar])) ?></dd>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dl>

            <p>Haberler, kaynaklarına atıf ve bağlantıyla özet olarak yayımlanır. Yayın anlayışımız için
                <a href="<?= e(kurumsal_yolu('yayin-ilkeleri')) ?>">Yayın İlkeleri</a> sayfasına bakabilirsiniz.</p>

        <?php elseif ($sayfa === 'gizlilik'): ?>
            <?php require_once __DIR__ . '/includes/olcum_reklam.php'; ?>

            <p>Bu sayfa, Valentra'yı ziyaret ettiğinizde hangi bilgilerin toplandığını, çerezlerin
                nasıl kullanıldığını ve 6698 sayılı Kişisel Verilerin Korunması Kanunu (KVKK)
                kapsamındaki haklarınızı açıklar.</p>

            <h2 id="ziyaret">Ziyaret kayıtları</h2>
            <p>Sitenin güvenliği ve hangi haberlerin okunduğunu ölçmek için her sayfa ziyaretinde
                IP adresi, ziyaret edilen sayfa, tarih ve saat, tarayıcı ve cihaz türü ile siteye
                hangi adresten gelindiği kaydedilir. Ayrıca tarayıcınızda, aynı okurun tekrar
                sayılmaması için rastgele bir kimlik saklanır. Bu kayıtlar 180 gün sonra kendiliğinden
                silinir ve üçüncü kişilerle paylaşılmaz.</p>

            <h2 id="cerezler">Çerezler</h2>
            <ul>
                <li><strong>Zorunlu:</strong> tema tercihi (açık/koyu) ve çerez tercihiniz tarayıcınızda
                    saklanır; sitenin çalışması için gereklidir.</li>
                <?php if (olcum_ga4() !== ''): ?>
                    <li><strong>Ölçüm (Google Analytics):</strong> sitenin nasıl kullanıldığını anlamak için
                        Google Analytics kullanılır. Çerezli ölçüm yalnızca çerez bildiriminde
                        "Kabul et" demeniz halinde yapılır.</li>
                <?php else: /* iletisim */ ?>

            <?php if ($eposta !== '' || $kunye['kunye_telefon'] !== '' || $kunye['kunye_adres'] !== ''): ?>
                <dl class="kunye-listesi">
                    <?php if ($eposta !== ''): ?>
                        <div><dt>E-posta</dt><dd><a href="mailto:<?= e($eposta) ?>"><?= e($eposta) ?></a></dd></div>
                    <?php endif; ?>
                    <?php if ($kunye['kunye_telefon'] !== ''): ?>
                        <div><dt>Telefon</dt><dd><?= e($kunye['kunye_telefon']) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($kunye['kunye_adres'] !== ''): ?>
                        <div><dt>Adres</dt><dd><?= nl2br(e($kunye['kunye_adres'])) ?></dd></div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>

            <h2>Ne için yazabilirsiniz?</h2>
            <ul>
                <li><strong>Düzeltme:</strong> bir haberde hata gördüyseniz, haberin bağlantısını ve
                    hatanın ne olduğunu yazın.</li>
                <li><strong>İçerik kaldırma:</strong> size ait bir içeriğin ya da kişisel bilginizin
                    kaldırılmasını istiyorsanız, ilgili bağlantıyı ve gerekçenizi yazın.</li>
                <li><strong>Öneri ve görüş:</strong> haber önerileri ve site hakkındaki görüşleriniz.</li>
            </ul>
            <p>Talepler <a href="<?= e(kurumsal_yolu('yayin-ilkeleri')) ?>#duzeltme">Yayın İlkeleri</a>'ne göre incelenir.</p>

        <?php endif; ?>
                <?php if (reklam_istemci() !== ''): ?>
                    <li><strong>Reklam (Google AdSense):</strong> sitede Google ve iş ortaklarının reklamları
                        gösterilir. Google gibi üçüncü taraf sağlayıcılar, önceki ziyaretlerinize göre reklam
                        göstermek için çerez kullanabilir. Kişiselleştirilmiş reklam yalnızca "Kabul et"
                        demeniz halinde gösterilir; aksi halde kişiselleştirilmemiş reklam gösterilir.
                        Google'ın verileri nasıl kullandığı:
                        <a href="https://policies.google.com/technologies/partner-sites" rel="noopener" target="_blank">policies.google.com/technologies/partner-sites</a>.
                        Kişiselleştirilmiş reklamları <a href="https://adssettings.google.com" rel="noopener" target="_blank">Google Reklam Ayarları</a>'ndan kapatabilirsiniz.</li>
                <?php endif; ?>
            </ul>
            <p><button type="button" class="dugme" onclick="try{localStorage.removeItem('vz_cerez')}catch(e){};location.reload()">Çerez tercihimi değiştir</button></p>

            <h2 id="haklar">Haklarınız</h2>
            <p>KVKK'nın 11. maddesi uyarınca kişisel verilerinizin işlenip işlenmediğini öğrenme,
                düzeltilmesini ya da silinmesini isteme ve işlemeye itiraz etme haklarına sahipsiniz.
                Talepleriniz için <?= $epostaBag ?> üzerinden bize yazabilirsiniz.</p>

        <?php endif; ?>

        </article>
    </div>

    <?php require __DIR__ . '/includes/yan_pencere.php'; ?>
</div>

<?php require __DIR__ . '/includes/sayfa_alt.php'; ?>
