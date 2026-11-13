<?php
declare(strict_types=1);

/**
 * HABER KAYNAGI BAGLAYICILARI (13.11.2026).
 *
 * Site sahibinin istegi: "AA, IHA ve DHA gibi ajanslara ileride abonelik
 * baglamaya hazir bir altyapi kur. RSS, API veya ozel besleme
 * baglantilari ayri baglayicilar seklinde eklenebilsin. Simdilik ucretli
 * abonelik baslatma. API adresi veya erisim bilgisi uydurma. Abonelik
 * gerektiren baglantilari yapilandirilmamis olarak goster."
 *
 * Her baglayici bir kaynak TURUDUR. Ajan bugun uc baglayiciyla calisiyor
 * (rss, web, resmi; ajan/src/Besleme.php, Kazima.php, ResmiGazete.php).
 * Ajans baglayicilari burada TANIMLI ama YAPILANDIRILMAMIS: abone
 * olunmadan ne adres ne erisim bilgisi bilinebilir, o yuzden kodda ikisi
 * de yok. Abonelik alindiginda yapilacaklar:
 *
 *   1. Ajansin verdigi teslim bicimi (API, FTP, ozel besleme) ve adres
 *      'gereken' alanlarina karsilik gelen GitHub gizli anahtarlarina
 *      yazilir (erisim bilgisi depoya ve veritabanina YAZILMAZ).
 *   2. ajan/src altina o bicimi okuyan sinif yazilir; cikti Besleme ile
 *      ayni: [baslik, baglanti, ozet, tarih, gorsel, yazar].
 *   3. Asagidaki kayitta 'durum_ayari' anahtari panelden 'hazir' yapilir;
 *      kaynak, kaynaklar tablosuna o turle eklenir.
 *
 * Sozlesme, tam metin ve fotograf kullanim hakkini da belirler: RSS
 * erisimi boyle bir izin DEGILDIR (bkz. 'kullanim').
 */
const BAGLAYICILAR = [
    'rss' => [
        'ad'       => 'RSS / Atom beslemesi',
        'abonelik' => false,
        'aciklama' => 'Yayıncının herkese açık beslemesi. Başlık, özet, bağlantı ve yayın saati alınır.',
        'kullanim' => 'RSS erişimi tam metin ve fotoğraf yayımlama izni değildir: haber kaynağın bağlantısı ve adıyla, özgün cümlelerle özetlenir.',
    ],
    'web' => [
        'ad'       => 'Duyuru sayfası (kazıma)',
        'abonelik' => false,
        'aciklama' => 'Beslemesi olmayan kurumların duyuru listesi.',
        'kullanim' => 'Yalnızca başlık ve bağlantı listesi okunur.',
    ],
    'resmi' => [
        'ad'       => 'Resmî kaynak (Resmî Gazete fihristi, kurum duyuruları)',
        'abonelik' => false,
        'aciklama' => 'Resmî Gazete günlük fihristi ve kamu kurumlarının duyuruları.',
        'kullanim' => 'Resmî metinler kamuya açıktır; kaynak gösterilir.',
    ],
    'aa' => [
        'ad'       => 'Anadolu Ajansı (AA) abone servisi',
        'abonelik' => true,
        'aciklama' => 'Ajansın abonelere verdiği haber ve fotoğraf servisi.',
        'gereken'  => ['Abonelik sözleşmesi', 'Ajansın vereceği servis adresi', 'Ajansın vereceği kullanıcı adı ve erişim anahtarı'],
        'kullanim' => 'Tam metin ve fotoğraf kullanımı yalnızca sözleşmenin tanıdığı kapsamda.',
    ],
    'iha' => [
        'ad'       => 'İhlas Haber Ajansı (İHA) abone servisi',
        'abonelik' => true,
        'aciklama' => 'Ajansın abonelere verdiği haber, fotoğraf ve video servisi.',
        'gereken'  => ['Abonelik sözleşmesi', 'Ajansın vereceği servis adresi', 'Ajansın vereceği kullanıcı adı ve erişim anahtarı'],
        'kullanim' => 'Tam metin ve fotoğraf kullanımı yalnızca sözleşmenin tanıdığı kapsamda.',
    ],
    'dha' => [
        'ad'       => 'Demirören Haber Ajansı (DHA) abone servisi',
        'abonelik' => true,
        'aciklama' => 'Ajansın abonelere verdiği haber, fotoğraf ve video servisi.',
        'gereken'  => ['Abonelik sözleşmesi', 'Ajansın vereceği servis adresi', 'Ajansın vereceği kullanıcı adı ve erişim anahtarı'],
        'kullanim' => 'Tam metin ve fotoğraf kullanımı yalnızca sözleşmenin tanıdığı kapsamda.',
    ],
];

/**
 * Baglayicinin durumu: 'calisiyor' (ajan bu turu okuyor) ya da
 * 'yapilandirilmamis' (abonelik ve baglanti bilgisi yok). Abonelikli bir
 * baglayici, okuyan sinif yazilip panelden isaretlenmeden "calisiyor"
 * sayilmaz.
 */
function baglayici_durumu(string $tur): string
{
    $b = BAGLAYICILAR[$tur] ?? null;

    if ($b === null) {
        return 'bilinmiyor';
    }

    if (!$b['abonelik']) {
        return 'calisiyor';
    }

    require_once __DIR__ . '/ayarlar.php';

    return ayar_oku('baglayici_' . $tur, '') === 'hazir' ? 'calisiyor' : 'yapilandirilmamis';
}

/** Panel listesi: her baglayici durumu ve bu turdeki aktif kaynak sayisiyla. */
function baglayici_listesi(): array
{
    $sayilar = [];

    try {
        foreach (db()->query('SELECT tur, COUNT(*) AS adet FROM kaynaklar WHERE aktif = 1 GROUP BY tur')->fetchAll() as $s) {
            $sayilar[(string) $s['tur']] = (int) $s['adet'];
        }
    } catch (Throwable $e) {
        error_log('[valentra] baglayici listesi: ' . $e->getMessage());
    }

    $liste = [];

    foreach (BAGLAYICILAR as $tur => $b) {
        $liste[] = $b + ['tur' => $tur, 'durum' => baglayici_durumu($tur), 'kaynak' => $sayilar[$tur] ?? 0];
    }

    return $liste;
}
