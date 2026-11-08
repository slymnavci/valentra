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
 * "Son gelişme" olarak öne çıkacak haber: yeni haberler içinde
 * BAŞLIĞINDA dosyanın kelimesi geçen ilk haber. Yalnızca etiketinde
 * geçen genel bir haber (enflasyon haberinin etiketinde "kira artışı")
 * dosyanın asıl konusunu anlatan haberin önüne geçmesin.
 *
 * @param list<array<string,mixed>> $haberler yeniden eskiye
 * @return int dizideki sıra
 */
function konu_one_cikan_sira(string $slug, array $haberler): int
{
    $kelimeler = array_map(
        static fn (string $k): string => mb_strtolower(strtr($k, ['I' => 'ı', 'İ' => 'i']), 'UTF-8'),
        KONULAR[$slug]['kelimeler'] ?? []
    );

    foreach (array_slice($haberler, 0, 10, true) as $i => $h) {
        $baslik = mb_strtolower(strtr((string) $h['baslik'], ['I' => 'ı', 'İ' => 'i']), 'UTF-8');

        foreach ($kelimeler as $k) {
            if (str_contains($baslik, $k)) {
                return (int) $i;
            }
        }
    }

    return 0;
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
            . konu_veri_kaynak_html($bilgi);
    } else {
        $html .= '<h2 id="konu-veri-baslik">Kira artış hesaplayıcı</h2>'
            . '<p class="konu-veri-kaynak">Güncel oran TÜİK verisinden hesaplanıp editör onayından geçince burada yayımlanır.</p>';
    }

    $html .= '<p class="konu-veri-not">Konut kiralarında yenilenen kira yılındaki artış, bir önceki kira yılının '
        . 'TÜFE on iki aylık ortalama değişimini geçemez (Türk Borçlar Kanunu md. 344). Bu bir üst sınırdır; taraflar daha '
        . 'düşük oranda anlaşabilir. Beş yılı dolduran sözleşmelerde kira, hâkim tarafından emsal kiralara göre de belirlenebilir.</p>';

    $html .= '<form class="konu-hesap" data-konu-hesap novalidate>'
        . '<label><span>Şu anki aylık kira (TL)</span><input type="text" name="kira" inputmode="decimal" placeholder="Örn. 20.000" autocomplete="off"></label>'
        . '<label><span>Artış oranı (%)</span><input type="text" name="oran" inputmode="decimal" value="'
        . ($oran !== null ? e(number_format($oran, 2, ',', '')) : '') . '" placeholder="Örn. 35,5"></label>'
        . '<dl aria-live="polite"><div><dt>Artış tutarı</dt><dd data-alan="artis">—</dd></div>'
        . '<div class="konu-hesap-toplam"><dt>Yeni aylık kira (en fazla)</dt><dd data-alan="yeni">—</dd></div></dl>'
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
    function hesapla() {
        var kira = sayi(f.kira.value), oran = sayi(f.oran.value);
        var ok = kira > 0 && oran >= 0;
        f.querySelector('[data-alan="artis"]').textContent = ok ? tl.format(kira * oran / 100) + ' TL' : '—';
        f.querySelector('[data-alan="yeni"]').textContent = ok ? tl.format(kira * (1 + oran / 100)) + ' TL' : '—';
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
