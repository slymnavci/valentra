<?php
declare(strict_types=1);

/**
 * Dosya sayfalarının üstündeki veri kutuları.
 *
 * Site sahibinin istegi (08.11.2026): okur dosyaya rakam icin geliyor;
 * sayfanin ustu haberden once o rakami vermeli.
 *
 *   kira-artisi   dogrulanmis kira artis siniri, hesaplayici, gecmis aylar
 *   enflasyon     aylik, yillik ve 12 aylik ortalama ayri ayri
 *   emekli-zammi  kesinlesen enflasyon farki / tahmin ayrimi, aciklanma takvimi
 *
 * Rakamlar pratik bilgilerden geliyor (pratik_bilgiler): TUFE endeksinden
 * hesaplanip PANELDEN ONAYLANMIS degerler (bkz. includes/ekonomi.php).
 * Onayli deger yoksa kutu rakam uydurmuyor; hesaplayici bos oranla
 * duruyor.
 */

require_once __DIR__ . '/pratik.php';
require_once __DIR__ . '/ekonomi.php';

/** Haber başlığı tahmin/beklenti mi anlatıyor? */
function konu_tahmin_mi(string $baslik): bool
{
    $baslik = mb_strtolower(strtr($baslik, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');

    foreach (['tahmin', 'beklenti', 'beklenen', 'olası', 'muhtemel', 'ne kadar olacak', 'kaç lira olacak',
              'kaç tl olacak', 'hesaplandı', 'senaryo', 'kulis', 'masada', 'iddia', 'öngörü'] as $k) {
        if (str_contains($baslik, $k)) {
            return true;
        }
    }

    return false;
}

/**
 * "Son gelişme" olarak öne çıkacak haber.
 *
 * 1. Editörün panelden seçtiği haber (panel → Gündem dosyaları;
 *    08.11.2026). Listede yoksa (40 haberden eski) yayındaysa ve hâlâ
 *    dosyaya giriyorsa listenin sonuna eklenir.
 * 2. Yoksa: yeni haberler içinde BAŞLIĞINDA dosyanın kelimesi geçen ilk
 *    haber. Yalnızca spotta/etikette geçen genel bir haber dosyanın asıl
 *    konusunu anlatan haberin önüne geçmesin.
 *
 * @param list<array<string,mixed>> $haberler yeniden eskiye; editörün
 *        seçtiği eski haber gerekirse sona eklenir
 * @return array{0:int,1:bool} dizideki sıra, editör seçimi mi
 */
function konu_one_cikan(string $slug, array &$haberler): array
{
    $secim = konu_editor_secimi($slug);

    if ($secim > 0) {
        foreach ($haberler as $i => $h) {
            if ((int) $h['id'] === $secim) {
                return [(int) $i, true];
            }
        }

        try {
            $ifade = db()->prepare(
                "SELECT h.id, h.baslik, h.slug, h.ozet, h.neden_onemli, h.gorsel_url, h.yayin_tarihi, h.kaynak_adi,
                        h.kaynak_baslik, h.kaynak_url, h.onem, h.etiketler, k.slug AS kategori_slug
                   FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
                  WHERE h.id = :id AND h.durum = 'yayinda' LIMIT 1"
            );
            $ifade->execute(['id' => $secim]);
            $h = $ifade->fetch();

            if ($h && konu_eslesir($slug, $h)) {
                $haberler[] = $h;

                return [count($haberler) - 1, true];
            }
        } catch (PDOException $e) {
            error_log('[valentra] konu editor secimi: ' . $e->getMessage());
        }
    }

    foreach (array_slice($haberler, 0, 10, true) as $i => $h) {
        if (konu_kelimesi_var($slug, (string) $h['baslik'])) {
            return [(int) $i, false];
        }
    }

    return [0, false];
}

/**
 * Onaylı değerin "Ad: değer" satırları.
 *
 * @return array<string,string>
 */
function konu_veri_satirlari(?array $bilgi): array
{
    $satirlar = [];

    foreach (preg_split('/\R/u', trim((string) ($bilgi['deger'] ?? ''))) ?: [] as $satir) {
        if (preg_match('/^([^:]+):\s*(.+)$/u', trim($satir), $m)) {
            $satirlar[trim($m[1])] = trim($m[2]);
        }
    }

    return $satirlar;
}

/** "%32,87" -> 32.87 */
function konu_yuzde_sayi(string $metin): ?float
{
    if (!preg_match('/%\s*(-?[\d.]+,\d+|-?\d+)/u', $metin, $m)) {
        return null;
    }

    return (float) str_replace(',', '.', str_replace('.', '', $m[1]));
}

/** Kaynak ve doğrulama satırı. */
function konu_veri_kaynak_html(array $bilgi): string
{
    $onay = strtotime((string) ($bilgi['onay_tarihi'] ?? ''));

    return '<p class="konu-veri-kaynak">Kaynak: ' . e((string) ($bilgi['kaynak_adi'] ?? 'TÜİK'))
        . ($bilgi['donem'] ? ' · ' . e((string) $bilgi['donem']) : '')
        . ($onay ? ' · editör onayı: ' . e(date('d.m.Y', $onay)) : '')
        . ' · <a href="' . e(pratik_bilgi_yolu((string) $bilgi['anahtar'])) . '">hesap ve grafik</a></p>';
}

/**
 * Veri kutusundaki onaylı değerin TÜFE dönemi ("Y-m"): serinin son
 * noktası, yoksa "Eylül 2026" gibi dönem metni. Bilinmiyorsa null.
 */
function konu_veri_donem_ayi(?string $donem, ?string $seriJson): ?string
{
    $seri = pratik_seri_oku($seriJson);

    if ($seri !== []) {
        $son = end($seri);

        if (preg_match('/^\d{4}-\d{2}/', (string) ($son[0] ?? ''), $m)) {
            return $m[0];
        }
    }

    $aylar = ['ocak' => 1, 'şubat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'haziran' => 6, 'temmuz' => 7,
              'ağustos' => 8, 'eylül' => 9, 'ekim' => 10, 'kasım' => 11, 'aralık' => 12];

    if (preg_match('/(\p{L}+)\s+(\d{4})/u', mb_strtolower(strtr((string) $donem, ['I' => 'ı', 'İ' => 'i']), 'UTF-8'), $m)
        && isset($aylar[$m[1]])) {
        return sprintf('%04d-%02d', (int) $m[2], $aylar[$m[1]]);
    }

    return null;
}

/**
 * Yayın sonrası denetim (08.11.2026, okur denetimi: enflasyon dosyasının
 * kutusu Eylül'ü gösterirken altındaki haberler Ekim verisini anlatıyordu).
 *
 * TÜİK bir ayın TÜFE'sini sonraki ayın 3'ünde açıklıyor. Ayın 5'inden
 * sonra kutu hâlâ iki ay önceki dönemdeyse yeni veri açıklanmış ama
 * panelde onaylanmamış demektir: okura kutunun hangi döneme ait olduğu ve
 * yeni verinin onay beklediği açıkça yazılır. Rakam uydurulmaz.
 */
function konu_veri_eski_mi_html(?array $bilgi, ?DateTimeImmutable $simdi = null): string
{
    if ($bilgi === null) {
        return '';
    }

    $ay = konu_veri_donem_ayi((string) ($bilgi['donem'] ?? ''), $bilgi['seri'] ?? null);

    if ($ay === null) {
        return '';
    }

    $simdi  ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
    $beklenen = ekonomi_ay_kaydir($simdi->format('Y-m'), (int) $simdi->format('j') >= 5 ? -1 : -2);

    if ($ay >= $beklenen) {
        return '';
    }

    $aday = trim((string) ($bilgi['aday_donem'] ?? '')) !== '' && trim((string) ($bilgi['aday_deger'] ?? '')) !== '';

    return '<p class="konu-veri-eski" role="note">Bu kutudaki veri <strong>' . e(ekonomi_ay_adi($ay)) . '</strong> dönemine ait. '
        . e(ekonomi_ay_adi($beklenen)) . ' verisi TÜİK tarafından açıklandı'
        . ($aday ? ' ve editör onayı bekliyor' : '; editör kontrolünden sonra burada güncellenecek')
        . '. Aşağıdaki haberler daha yeni dönemi anlatıyor olabilir.</p>';
}

/** Dosyanın veri kutusu; bu dosyanın kutusu yoksa boş. */
function konu_veri_html(string $slug): string
{
    try {
        return match ($slug) {
            'kira-artisi'  => konu_kira_html(pratik_bul('kira-artis-orani')),
            'enflasyon'    => konu_enflasyon_html(pratik_bul('enflasyon-orani')),
            'emekli-zammi' => konu_emekli_html(pratik_bul('emekli-enflasyon-farki')),
            default        => '',
        };
    } catch (Throwable $e) {
        error_log('[valentra] konu veri: ' . $e->getMessage());

        return '';
    }
}

/* ---------------------------------------------------------------- Kira */

function konu_kira_html(?array $bilgi): string
{
    $satir = konu_veri_satirlari($bilgi);
    $oran  = isset($satir['Kira artış sınırı']) ? konu_yuzde_sayi($satir['Kira artış sınırı']) : null;
    $html  = '<section class="konu-veri" aria-labelledby="konu-veri-baslik">';

    if ($bilgi !== null && $oran !== null) {
        $html .= '<h2 id="konu-veri-baslik">Güncel kira artış sınırı</h2>'
            . '<div class="konu-veri-buyuk"><strong>' . e($satir['Kira artış sınırı']) . '</strong>'
            . (isset($satir['Uygulandığı ay']) ? '<span>' . e($satir['Uygulandığı ay']) . '</span>' : '')
            . '</div>'
            . konu_veri_eski_mi_html($bilgi)
            . konu_veri_kaynak_html($bilgi);
    } else {
        // Okur denetimi (08.11.2026): "editor onayindan gecince yayimlanir"
        // yazarken hemen altindaki haber kesin bir oran veriyordu. Kutu
        // yalnizca onayli veriyi gosterir; haberle celiskili gorunmemesi
        // icin okura oranin nereden teyit edilecegi soyleniyor.
        $html .= '<h2 id="konu-veri-baslik">Kira artış hesaplayıcı</h2>'
            . '<p class="konu-veri-kaynak">Bu ayın sınır oranı henüz editör onaylı veri olarak girilmedi. '
            . 'Haberlerde geçen oranı TÜİK\'in açıkladığı TÜFE on iki aylık ortalamasından teyit ederek aşağıya kendiniz yazabilirsiniz.</p>';
    }

    $html .= '<p class="konu-veri-not">Konut kiralarında yenilenen kira yılındaki artış, bir önceki kira yılının '
        . 'TÜFE on iki aylık ortalama değişimini geçemez (Türk Borçlar Kanunu md. 344). Bu bir üst sınırdır; taraflar daha '
        . 'düşük oranda anlaşabilir. Beş yılı dolduran sözleşmelerde kira, hâkim tarafından emsal kiralara göre de belirlenebilir.</p>';

    /*
     * Sonuc etiketi girilen orana gore (08.11.2026, okur denetimi): okur
     * %50 yazinca da "Yeni aylik kira (en fazla)" yaziyordu. "En fazla"
     * yalnizca onayli yasal sinirla hesaplanan tutar icin dogru; serbest
     * oranda "girdiginiz orana gore". Onayli oran varsa ve okur baska oran
     * girdiyse sinirla hesaplanan tutar ayrica gosteriliyor.
     */
    $sinirMetni = $oran !== null ? number_format($oran, 2, ',', '') : '';
    $html .= '<form class="konu-hesap" data-konu-hesap' . ($oran !== null ? ' data-sinir="' . e((string) $oran) . '"' : '') . ' novalidate>'
        . '<label><span>Şu anki aylık kira (TL)</span><input type="text" name="kira" inputmode="decimal" placeholder="Örn. 20.000" autocomplete="off"></label>'
        . '<label><span>Artış oranı (%)</span><input type="text" name="oran" inputmode="decimal" value="'
        . e($sinirMetni) . '" placeholder="Örn. 35,5"></label>'
        . '<dl aria-live="polite"><div><dt>Artış tutarı</dt><dd data-alan="artis">—</dd></div>'
        . '<div class="konu-hesap-toplam"><dt data-alan="yeni-etiket">' . ($oran !== null ? 'Yasal sınıra göre en fazla yeni kira' : 'Girdiğiniz orana göre yeni aylık kira')
        . '</dt><dd data-alan="yeni">—</dd></div>'
        . ($oran !== null ? '<div data-alan="sinir-satir" hidden><dt>Yasal sınırla (%' . e($sinirMetni) . ') en fazla</dt><dd data-alan="sinir">—</dd></div>' : '')
        . '</dl><p class="konu-hesap-uyari" data-alan="uyari" hidden></p>'
        . '</form>';

    $seri = array_reverse(array_slice(pratik_seri_oku($bilgi['seri'] ?? null), -12));

    if ($seri !== []) {
        $html .= '<details class="konu-gecmis" open><summary>Geçmiş aylar</summary><table><thead><tr>'
            . '<th>TÜFE verisi</th><th>12 aylık ortalama</th><th>Uygulandığı yenileme ayı</th></tr></thead><tbody>';

        foreach ($seri as [$tarih, $deger]) {
            $ay = substr((string) $tarih, 0, 7);
            $html .= '<tr><td>' . e(ekonomi_ay_adi($ay)) . '</td><td>%' . e(number_format((float) $deger, 2, ',', '.'))
                . '</td><td>' . e(ekonomi_ay_adi(ekonomi_ay_kaydir($ay, 1))) . '</td></tr>';
        }

        $html .= '</tbody></table></details>';
    }

    return $html . '</section>' . konu_hesap_betigi();
}

/** Kira hesaplayıcının betiği (sayfada bir kez). */
function konu_hesap_betigi(): string
{
    return <<<'JS'
<script>
(function () {
    var f = document.querySelector('[data-konu-hesap]');
    if (!f) { return; }
    var tl = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function sayi(m) {
        var s = String(m || '').replace(/\s|TL|%/gi, '');
        if (s === '') { return NaN; }
        if (s.indexOf(',') !== -1) { s = s.replace(/\./g, '').replace(',', '.'); }
        else if (/^\d{1,3}(\.\d{3})+$/.test(s)) { s = s.replace(/\./g, ''); }
        return /^-?\d+(\.\d+)?$/.test(s) ? parseFloat(s) : NaN;
    }
    var sinir = f.dataset.sinir ? parseFloat(f.dataset.sinir) : NaN;
    function alan(a) { return f.querySelector('[data-alan="' + a + '"]'); }
    function hesapla() {
        var kira = sayi(f.kira.value), oran = sayi(f.oran.value);
        var ok = kira > 0 && oran >= 0;
        var sinirla = !isNaN(sinir) && ok && Math.abs(oran - sinir) < 0.005;
        alan('artis').textContent = ok ? tl.format(kira * oran / 100) + ' TL' : '—';
        alan('yeni').textContent = ok ? tl.format(kira * (1 + oran / 100)) + ' TL' : '—';
        // "En fazla" yalnizca onayli yasal sinirla hesaplanan tutar icin.
        alan('yeni-etiket').textContent = (sinirla || (!ok && !isNaN(sinir)))
            ? 'Yasal sınıra göre en fazla yeni kira' : 'Girdiğiniz orana göre yeni aylık kira';
        var satir = alan('sinir-satir');
        if (satir) {
            satir.hidden = !ok || sinirla;
            alan('sinir').textContent = ok ? tl.format(kira * (1 + sinir / 100)) + ' TL' : '—';
        }
        var uyari = alan('uyari');
        uyari.hidden = !(ok && !isNaN(sinir) && oran > sinir + 0.005);
        uyari.textContent = uyari.hidden ? '' : 'Girdiğiniz oran onaylı yasal sınırın (%'
            + sinir.toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ') üzerinde. Konut kirasında yenileme artışı bu sınırı geçemez.';
    }
    f.addEventListener('input', hesapla);
    f.addEventListener('submit', function (e) { e.preventDefault(); });
})();
</script>
JS;
}

/* ----------------------------------------------------------- Enflasyon */

function konu_enflasyon_html(?array $bilgi): string
{
    $satir = konu_veri_satirlari($bilgi);

    if ($bilgi === null || $satir === []) {
        return '';
    }

    $kutular = [
        'Aylık'             => 'Bir önceki aya göre',
        'Yıllık'            => 'Geçen yılın aynı ayına göre',
        '12 aylık ortalama' => 'Kira artış sınırı bu orandır',
    ];
    $html = '';

    foreach ($kutular as $ad => $aciklama) {
        if (isset($satir[$ad])) {
            $html .= '<div><dt>' . e($ad) . '</dt><dd>' . e($satir[$ad]) . '</dd><small>' . e($aciklama) . '</small></div>';
        }
    }

    if ($html === '') {
        return '';
    }

    return '<section class="konu-veri" aria-labelledby="konu-veri-baslik">'
        . '<h2 id="konu-veri-baslik">Son açıklanan enflasyon</h2>'
        . konu_veri_eski_mi_html($bilgi)
        . '<dl class="konu-veri-kutular">' . $html . '</dl>'
        . konu_veri_kaynak_html($bilgi)
        . '</section>';
}

/* -------------------------------------------------------------- Emekli */

function konu_emekli_html(?array $bilgi): string
{
    $satir = konu_veri_satirlari($bilgi);
    $kesinAd = isset($satir['Kesinleşen enflasyon farkı']) ? 'Kesinleşen enflasyon farkı'
        : (isset($satir['Şimdiye kadar biriken']) ? 'Şimdiye kadar biriken' : null);
    $html  = '<section class="konu-veri" aria-labelledby="konu-veri-baslik">'
        . '<h2 id="konu-veri-baslik">' . ($kesinAd === 'Şimdiye kadar biriken'
            ? 'Kesinleşen veri: açıklanan aylardaki enflasyon farkı' : 'Kesinleşen bilgi') . '</h2>';

    if ($bilgi !== null && $kesinAd !== null) {
        $html .= '<div class="konu-veri-buyuk"><strong>' . e($satir[$kesinAd]) . '</strong><span>'
            . e($kesinAd === 'Şimdiye kadar biriken' ? 'Şimdiye kadar biriken enflasyon farkı' : 'Kesinleşen enflasyon farkı')
            . (isset($satir['Dönem']) ? ' · ' . e($satir['Dönem']) : '')
            . (isset($satir['Uygulanacağı ay']) ? ' · zam ayı: ' . e($satir['Uygulanacağı ay']) : '')
            . '</span></div>'
            . konu_veri_kaynak_html($bilgi);
    }

    $html .= '<p class="konu-veri-not">SSK ve Bağ-Kur aylıkları ocak ve temmuzda, önceki altı ayın TÜFE değişimi kadar '
        . 'artırılır (5510 sayılı Kanun md. 55). Altı ayın verisi açıklanınca oran kesinleşir; en düşük emekli aylığı ve '
        . 'refah payı gibi ek artışlar ise ayrıca yasa ya da karar gerektirir. Aşağıdaki haberlerde '
        . '<span class="konu-rozet tahmin">Tahmin</span> işaretliler kesinleşmiş rakam değildir.</p>';

    $takvim = konu_emekli_takvimi($bilgi);

    if ($takvim !== []) {
        $html .= '<h3>Açıklanma takvimi</h3><ul class="konu-takvim">';

        foreach ($takvim as $t) {
            $html .= '<li><strong>' . e($t['veri']) . ' enflasyonu</strong> · ' . e($t['zaman'])
                . ($t['son'] ? ' · <em>zam oranı kesinleşir</em>' : '') . '</li>';
        }

        $html .= '</ul><p class="konu-veri-kaynak">TÜİK enflasyonu genellikle ayın 3\'ünde saat 10.00\'da açıklar; kesin gün TÜİK takviminde.</p>';
    }

    $seri = array_reverse(array_slice(pratik_seri_oku($bilgi['seri'] ?? null), -6));

    if ($seri !== []) {
        $html .= '<details class="konu-gecmis"><summary>Geçmiş dönemler</summary><table><thead><tr>'
            . '<th>Dönem</th><th>Altı aylık TÜFE</th><th>Zam ayı</th></tr></thead><tbody>';

        foreach ($seri as [$tarih, $deger]) {
            $ay = substr((string) $tarih, 0, 7);
            $html .= '<tr><td>' . e(ekonomi_ay_adi(ekonomi_ay_kaydir($ay, -5)) . ' – ' . ekonomi_ay_adi($ay))
                . '</td><td>%' . e(number_format((float) $deger, 2, ',', '.'))
                . '</td><td>' . e(ekonomi_ay_adi(ekonomi_ay_kaydir($ay, 1))) . '</td></tr>';
        }

        $html .= '</tbody></table></details>';
    }

    return $html . '</section>';
}

/**
 * Dönem kapanana kadar açıklanacak TÜFE verileri.
 *
 * Son onayli veri ayindan (yoksa bugunden) donemin son ayina (Haziran ya
 * da Aralik) kadar her ay. Aciklama ayi verinin bir sonraki ayi.
 *
 * @return list<array{veri:string,zaman:string,son:bool}>
 */
function konu_emekli_takvimi(?array $bilgi): array
{
    $sonVeri = null;

    if ($bilgi !== null && preg_match('/^(\S+) (\d{4}) TÜFE verisi$/u', (string) ($bilgi['donem'] ?? ''), $m)) {
        $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        $no    = array_search($m[1], $aylar, true);

        if ($no !== false) {
            $sonVeri = sprintf('%s-%02d', $m[2], $no);
        }
    }

    // Onayli veri yoksa: bugun aciklanmis olabilecek en son veri gecen ayinki.
    $sonVeri ??= ekonomi_ay_kaydir(date('Y-m'), -2);

    $liste = [];
    $ay    = ekonomi_ay_kaydir($sonVeri, 1);

    for ($i = 0; $i < 6; $i++) {
        $no = (int) substr($ay, 5, 2);
        $liste[] = [
            'veri'  => ekonomi_ay_adi($ay),
            'zaman' => ekonomi_ay_adi(ekonomi_ay_kaydir($ay, 1)) . ' başı',
            'son'   => in_array($no, [6, 12], true),
        ];

        if (in_array($no, [6, 12], true)) {
            break;
        }

        $ay = ekonomi_ay_kaydir($ay, 1);
    }

    return $liste;
}
