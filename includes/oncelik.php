<?php
declare(strict_types=1);

/**
 * HABER ONCELIGI (12.11.2026).
 *
 * Site sahibinin istegi: "haber onceliklendirme sistemini Turkiye merkezli
 * bir yayin kurulusunun haber muduru mantigiyla yeniden duzenle. Gundem
 * kategorisindeki onemli haberler ana sayfada one cikmiyor; asker ucagi
 * dusmesi gibi ulusal olcekte kritik bir haber ilk haber olmadi."
 *
 * SORUN NEYDI (12.11.2026 aksami, Malatya'daki askeri helikopter kazasi):
 * ajan habere toplamanin en yuksek onemini verdi (92) ve haber yayimlandi;
 * ama ana sayfa sirasi once TOPLAMA DALGASINA bakiyordu (haber_dalga_ifadesi:
 * son 2 saatte gelenler once). Iki saat sonra gelen her onemi 60 ve ustu
 * haber (emekli maasi aciklamasi 85, benzin indirimi 90) kazanin onune gecti.
 * Onem tek bir sayiydi: "Cumhurbaskani konustu" (88) ile "dort sehit" (92)
 * ayni terazideydi. Kaynak 19.11'de duyurdu, ajan 21.01'de taradi (iki
 * saatte bir calisiyor) ve haberi calismanin sonunda toplu gonderdi.
 *
 * COZUM: bes ayri alan, tek karar yeri.
 *
 *   1. Kategori            (kategori_id; siralamayi belirlemez)
 *   2. Editoryal onem      (oncelik: 3 kritik, 2 yuksek, 1 normal, 0 dusuk)
 *   3. Yayin aciliyeti     (aciliyet: 2 dogrulaninca hemen, 1 oncelikli
 *                           kuyruk, 0 normal kuyruk)
 *   4. Ana sayfa konumu    (hesaplanir: manset adayi / ust haberler / akis)
 *   5. Dogrulama           (dogrulama: resmi, bagimsiz, tek_kaynak, iddia;
 *                           can_kaybi: dogrulandi, dogrulanmadi, yok)
 *
 * Yapay zeka haberi OKUYUP yapili alanlari dolduruyor (ajan/src/Yazar.php,
 * EDITORYAL ONCELIK); son karari bu dosya veriyor (oncelik_karar). Baslikta
 * "sehit", "deprem", "flas" gecmesi hicbir seyi tetiklemiyor: kritik olmak
 * icin olayin TURU politika listesinde, olay GUNCEL (yildonumu, anma,
 * benzetme degil) ve Turkiye'yi DOGRUDAN ilgilendiriyor olmali.
 *
 * Onem ile dogrulama ayri: kritik ama dogrulanmamis haber yayimlanmiyor,
 * panelde "acil inceleme"ye dusuyor. Dogrulanmis kritik haber toplamanin
 * bitmesini beklemeden gonderiliyor, kopya/konu bekletmesine takilmiyor,
 * sayfa kopyalari gecersiz kiliniyor (oncelik_onbellek_gecersiz).
 *
 * Ana sayfa once sinifa, sonra sinif ici puana bakiyor (haber_sirasi_ust).
 * Kritik haber SON ONEMLI GELISMEDEN itibaren ONCELIK_KRITIK_SAAT saat
 * manset adayi; sonra yuksege, sonra olagan siraya iner. Metin duzeltmesi
 * ya da ayni bilginin baska kaynaktan gelmesi bu sureyi yenilemiyor.
 */

require_once __DIR__ . '/haberler.php';

const ONCELIK_DUSUK  = 0;
const ONCELIK_NORMAL = 1;
const ONCELIK_YUKSEK = 2;
const ONCELIK_KRITIK = 3;

const ONCELIK_ADLAR = [
    ONCELIK_DUSUK  => 'düşük',
    ONCELIK_NORMAL => 'normal',
    ONCELIK_YUKSEK => 'yüksek',
    ONCELIK_KRITIK => 'kritik',
];

const ACILIYET_NORMAL    = 0;
const ACILIYET_ONCELIKLI = 1;
const ACILIYET_HEMEN     = 2;

const ACILIYET_ADLAR = [
    ACILIYET_NORMAL    => 'normal kuyruk',
    ACILIYET_ONCELIKLI => 'öncelikli kuyruk',
    ACILIYET_HEMEN     => 'doğrulanınca hemen',
];

/*
 * TURKIYE ONCELIK POLITIKASI: kritik sayilabilecek olay turleri. Yapay zeka
 * haberi okuyup bunlardan birini seciyor ya da "diger" diyor. Tur tek basina
 * yetmiyor (bkz. oncelik_karar).
 */
const ONCELIK_POLITIKA_TURLERI = [
    'sehit'                    => 'şehit haberi',
    'askeri_hava_araci_kazasi' => 'askerî uçak ya da helikopter kazası',
    'buyuk_can_kaybi'          => 'büyük can kaybı olan olay',
    'yikici_afet'              => 'yıkıcı deprem, tsunami ya da büyük afet',
    'buyuk_teror'              => 'büyük terör saldırısı',
    'cumhurbaskani_acil_karar' => 'Cumhurbaşkanı\'nın acil ya da geniş etkili kararı',
    'ulke_capi_olaganustu'     => 'ülke çapında olağanüstü gelişme',
    // 13.11.2026, site sahibinin Türkiye odaklı öncelik listesi.
    'buyuk_kaza'               => 'büyük kaza, patlama ya da yangın',
    'kamu_guvenligi_uyarisi'   => 'kamu güvenliği uyarısı (tahliye, fırtına, sel, salgın)',
    'genis_etkili_ekonomi'     => 'geniş kesimleri etkileyen ücret, emeklilik, vergi ya da ekonomi kararı',
    'turkiye_guvenligi_dis'    => 'Türkiye\'nin güvenliğini ilgilendiren uluslararası gelişme',
];

/**
 * Haberi ilk veren ajans: beslemedeki yazar alanindan (TRT Haber "AA",
 * "DHA" yaziyor). Ayni ajansin iki sitedeki kopyasi iki ayri dogrulama
 * degildir (site sahibi, 13.11.2026). Taninan ajans degilse bos doner:
 * muhabir adi ya da sitenin kendi adi ajans sayilmaz.
 */
const ONCELIK_AJANSLAR = [
    'aa'      => '/^(aa|anadolu ajans[ıi])$/u',
    'dha'     => '/^(dha|demirören haber ajans[ıi])$/u',
    'iha'     => '/^([iİı]ha|[iİ]hlas haber ajans[ıi])$/u',
    'anka'    => '/^(anka|anka haber ajans[ıi])$/u',
    'reuters' => '/^reuters$/u',
    'afp'     => '/^afp$/u',
    'ap'      => '/^(ap|associated press)$/u',
];

function oncelik_ajans(string $yazar): string
{
    // "TRT Haber-AA", "TRT Haber, AA": parcalardan biri ajanssa haber ajansin.
    $parcalar = preg_split('/\s*[,\/–-]\s*/u', mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $yazar), 'UTF-8')) ?: [];

    foreach ($parcalar as $y) {
        $y = trim($y);

        foreach (ONCELIK_AJANSLAR as $ajans => $kalip) {
            if ($y !== '' && preg_match($kalip, $y) === 1) {
                return $ajans;
            }
        }
    }

    return '';
}

/** Son dakika bandinin ayari: 'onizleme' (yalnizca panelde) ya da 'yayin'. */
const ONCELIK_BANT_AYARI = 'son_dakika_bandi';

/** Kritik haber son onemli gelismeden bu kadar saat bantta. */
const ONCELIK_BANT_KRITIK_SAAT = 6;

/** Yuksek oncelikli haber bu kadar saat bantta. */
const ONCELIK_BANT_YUKSEK_SAAT = 3;

const DOGRULAMA_ADLAR = [
    'resmi'      => 'resmî açıklama',
    'bagimsiz'   => 'birbirinden bağımsız kaynaklar',
    'tek_kaynak' => 'tek kaynak',
    'iddia'      => 'doğrulanmamış iddia',
    ''           => 'bilinmiyor',
];

/** Kritik haber son onemli gelismeden itibaren bu kadar saat manset adayi. */
const ONCELIK_KRITIK_SAAT = 6;

/** Sonra bu kadar saat daha "yuksek" sinifinda (ust haberler). */
const ONCELIK_KRITIK_SONRASI_SAAT = 18;

/** Yuksek oncelikli haber bu kadar saat ust haberlerde. */
const ONCELIK_YUKSEK_SAAT = 4;

/** Olay eslestirmede geriye bakilan sure (saat). */
const ONCELIK_OLAY_SAAT = 72;

/** Editor mudahalesi en fazla bu kadar saat (kosulsuz kilit olmasin). */
const ONCELIK_EDITOR_EN_FAZLA_SAAT = 48;

/** Sayfa kopyalarini gecersiz kilan damga (bkz. sayfa_onbellek). */
const ONCELIK_ONBELLEK_DAMGA = __DIR__ . '/../onbellek/sayfa/.gecersiz';

/** Oncelik sutunlari veritabaninda var mi (yukseltme inmeden site dusmesin). */
function oncelik_hazir(): bool
{
    static $hazir = null;

    return $hazir ??= haber_sutun_var('oncelik') && haber_sutun_var('editor_bitis');
}

/* -------------------------------------------------------------------------
 * KARAR
 * ---------------------------------------------------------------------- */

/**
 * Baslik ve spot can kaybini KESIN bilgi gibi veriyor mu?
 *
 * Tetikleyici DEGIL, emniyet: can kaybi dogrulanmamis bir olayda "sehit
 * oldu", "hayatini kaybetti" yazan haber otomatik yayimlanmasin. Yalnizca
 * baslik ve spota bakiliyor; "can kaybi olup olmadigi bilinmiyor" cumlesi
 * takilmasin diye "can kaybi" sozu aranmiyor.
 */
function oncelik_can_kaybi_iddiasi(array $veri): bool
{
    $metin = mb_strtolower(
        str_replace(['İ', 'I'], ['i', 'ı'], (string) ($veri['baslik'] ?? '') . ' ' . (string) ($veri['ozet'] ?? '')),
        'UTF-8'
    );

    return preg_match('/şehit (oldu|düştü|verdi)|\bşehi(t|d)imiz\b|hayatını kaybet|yaşamını yitir|\böldü\b|\bölü\b|can verdi/u', $metin) === 1;
}

/**
 * Birbirinden bagimsiz kaynak sayisi: ayni yayinin iki bolumu ("NTV Spor",
 * "NTV") tek kaynak. Ajansin haberini aynen veren siteleri yapay zeka
 * ayikliyor (bagimsiz_kaynak_sayisi); ikisinin kucugu gecerli.
 *
 * @param array<string,mixed> $veri
 */
function oncelik_bagimsiz_kaynak_sayisi(array $veri): int
{
    $adlar = [];
    $ilk   = ['ad' => $veri['kaynak_adi'] ?? '', 'ajans' => $veri['kaynak_ajans'] ?? ''];

    foreach (array_merge([$ilk], (array) ($veri['ek_kaynaklar'] ?? [])) as $k) {
        $ad = is_array($k) ? trim((string) ($k['ad'] ?? '')) : '';

        if ($ad === '') {
            continue;
        }

        /*
         * Haber bir ajanstan aliniyorsa (beslemedeki yazar alani) kaynak o
         * ajanstir: AA'nin haberini veren TRT Haber ile ayni haberi veren
         * baska bir site tek kaynak (13.11.2026).
         */
        $ajans = oncelik_ajans(is_array($k) ? (string) ($k['ajans'] ?? '') : '');

        $adlar[$ajans !== '' ? 'ajans:' . $ajans : mb_strtolower(kaynak_gorunen_ad($ad), 'UTF-8')] = true;
    }

    $farkli = count($adlar);
    $model  = (int) ($veri['bagimsiz_kaynak_sayisi'] ?? 0);

    return $model > 0 ? min($farkli, $model) : min($farkli, 1);
}

/**
 * Haberin oncelik karari. Veritabanina dokunmaz; testler dogrudan cagirir.
 *
 * Girdi yapay zekanin haberi okuyup doldurdugu alanlar:
 *   oncelik (kritik|yuksek|normal|dusuk), olay_turu, olay_guncel (bool),
 *   turkiye_dogrudan (bool), dogrulama (resmi|bagimsiz|tek_kaynak|iddia),
 *   resmi_kurum, bagimsiz_kaynak_sayisi, can_kaybi (dogrulandi|
 *   dogrulanmadi|yok), gelisme (ilk|yeni_gelisme|ayni_bilgi).
 * Alanlar yoksa (eski ajan) haber normal sinifta, olagan kuyrukta.
 *
 * @param array<string,mixed> $veri
 * @return array{sinif:int,aciliyet:int,dogrulama:string,can_kaybi:string,olay_turu:string,
 *               yayin:string,konum:string,dogrulandi:bool,gerekce:list<string>}
 *         yayin: 'hemen' | 'oncelikli' | 'kuyruk' | 'editor_acil' | 'editor'
 */
function oncelik_karar(array $veri): array
{
    $istenen   = mb_strtolower(trim((string) ($veri['oncelik'] ?? '')), 'UTF-8');
    $istenen   = strtr($istenen, ['ü' => 'u', 'ş' => 's', 'ı' => 'i']);
    $tur       = trim((string) ($veri['olay_turu'] ?? ''));
    $tur       = isset(ONCELIK_POLITIKA_TURLERI[$tur]) ? $tur : ($tur === '' ? '' : 'diger');
    $guncel    = !array_key_exists('olay_guncel', $veri) || !empty($veri['olay_guncel']);
    $turkiye   = !empty($veri['turkiye_dogrudan']);
    $canKaybi  = in_array($veri['can_kaybi'] ?? '', ['dogrulandi', 'dogrulanmadi', 'yok'], true) ? (string) $veri['can_kaybi'] : '';
    $gerekce   = [];

    // --- Dogrulama (onemden ayri) -----------------------------------------
    $dogrulama = (string) ($veri['dogrulama'] ?? '');
    $dogrulama = in_array($dogrulama, ['resmi', 'bagimsiz', 'tek_kaynak', 'iddia'], true) ? $dogrulama : '';

    if ($dogrulama === 'resmi' && trim((string) ($veri['resmi_kurum'] ?? '')) === '') {
        $dogrulama = 'tek_kaynak';
        $gerekce[] = 'Resmî açıklama denmiş ama açıklayan kurum belirtilmemiş: tek kaynak sayıldı.';
    }

    if ($dogrulama === 'bagimsiz' && oncelik_bagimsiz_kaynak_sayisi($veri) < 2) {
        $dogrulama = 'tek_kaynak';
        $gerekce[] = 'Kaynaklar birbirinden bağımsız değil (aynı yayın ya da aynı ajansın kopyaları): tek kaynak sayıldı.';
    }

    $dogrulandi = $dogrulama === 'resmi' || $dogrulama === 'bagimsiz';

    // --- Sinif ------------------------------------------------------------
    $politika = $tur !== '' && $tur !== 'diger';

    if ($istenen === 'kritik') {
        if ($politika && $guncel && $turkiye) {
            $sinif     = ONCELIK_KRITIK;
            $gerekce[] = 'Kritik: ' . ONCELIK_POLITIKA_TURLERI[$tur] . ', olay güncel ve Türkiye\'yi doğrudan ilgilendiriyor.';
        } elseif (!$guncel) {
            $sinif     = ONCELIK_NORMAL;
            $gerekce[] = 'Kritik değil: olay güncel değil (yıldönümü, anma ya da eski olayın ayrıntısı).';
        } else {
            $sinif     = ONCELIK_YUKSEK;
            $gerekce[] = 'Kritik değil, yüksek: ' . (!$politika
                ? 'olay türü Türkiye öncelik politikasındaki türlerden değil.'
                : 'olay Türkiye\'yi doğrudan ilgilendirmiyor.');
        }
    } elseif ($istenen === 'yuksek') {
        $sinif     = $guncel ? ONCELIK_YUKSEK : ONCELIK_NORMAL;
        $gerekce[] = $guncel ? 'Yüksek öncelik: ülke gündeminde önemli ve güncel gelişme.'
                             : 'Yüksek değil: olay güncel değil.';
    } elseif ($istenen === 'dusuk') {
        $sinif     = ONCELIK_DUSUK;
        $gerekce[] = 'Düşük öncelik: dar ilgi alanı.';
    } else {
        $sinif     = ONCELIK_NORMAL;
        $gerekce[] = $istenen === '' ? 'Öncelik alanı gelmedi: normal sayıldı.' : 'Normal öncelik.';
    }

    // --- Yayin yolu -------------------------------------------------------
    $canIddiasi = $politika && $canKaybi === 'dogrulanmadi' && oncelik_can_kaybi_iddiasi($veri);

    if ($dogrulama === 'iddia') {
        $yayin     = $sinif >= ONCELIK_YUKSEK ? 'editor_acil' : 'editor';
        $gerekce[] = 'Doğrulanmamış iddia (sosyal medya ya da kaynağı belirsiz): otomatik yayımlanmadı.';
    } elseif ($canIddiasi) {
        $yayin     = 'editor_acil';
        $gerekce[] = 'Can kaybı doğrulanmamış ama başlık ya da spot can kaybını kesin bilgi gibi veriyor: otomatik yayımlanmadı.';
    } elseif ($sinif === ONCELIK_KRITIK && !$dogrulandi) {
        $yayin     = 'editor_acil';
        $gerekce[] = 'Kritik ama doğrulanmadı (' . DOGRULAMA_ADLAR[$dogrulama] . '): acil editör incelemesine alındı.';
    } elseif ($sinif === ONCELIK_KRITIK) {
        $yayin     = 'hemen';
        $gerekce[] = 'Doğrulandı (' . DOGRULAMA_ADLAR[$dogrulama]
            . (trim((string) ($veri['resmi_kurum'] ?? '')) !== '' ? ': ' . trim((string) $veri['resmi_kurum']) : '')
            . '): olağan kuyruk beklenmeden yayımlanır.';
    } elseif ($sinif === ONCELIK_YUKSEK) {
        $yayin = 'oncelikli';
    } else {
        $yayin = 'kuyruk';
    }

    if ($politika && $dogrulandi && $canKaybi === 'dogrulanmadi' && !$canIddiasi) {
        $gerekce[] = 'Olay doğrulandı, can kaybı doğrulanmadı: haber can kaybı bildirmiyor.';
    }

    $aciliyet = match (true) {
        $sinif === ONCELIK_KRITIK => ACILIYET_HEMEN,
        $sinif === ONCELIK_YUKSEK => ACILIYET_ONCELIKLI,
        default                   => ACILIYET_NORMAL,
    };

    $konum = match (true) {
        $yayin === 'editor_acil' || $yayin === 'editor' => 'editör incelemesinde',
        $sinif === ONCELIK_KRITIK                        => 'ana manşet adayı',
        $sinif === ONCELIK_YUKSEK                        => 'üst haberler',
        default                                          => 'normal akış',
    };

    return [
        'sinif'      => $sinif,
        'aciliyet'   => $aciliyet,
        'dogrulama'  => $dogrulama,
        'can_kaybi'  => $canKaybi,
        'olay_turu'  => $tur,
        'yayin'      => $yayin,
        'konum'      => $konum,
        'dogrulandi' => $dogrulandi,
        'gerekce'    => $gerekce,
    ];
}

/* -------------------------------------------------------------------------
 * OLAY KIMLIGI: ayni olayin haberleri tek olayda birlesir.
 * ---------------------------------------------------------------------- */

/** Yer adini karsilastirma icin sadelestirir ("Malatya'da" / "MALATYA" ayni). */
function oncelik_yer_sade(string $yer): string
{
    $yer = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], trim($yer)), 'UTF-8');
    $yer = (string) preg_replace('/[\'’].*$/u', '', $yer);

    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $yer));
}

/**
 * Son ONCELIK_OLAY_SAAT saatin haberleri (olay eslestirme icin; istek basina
 * bir kez, yeni eklenen basa eklenir).
 *
 * @param array<string,mixed>|null $ekle
 * @return list<array<string,mixed>>
 */
function oncelik_son_olaylar(?array $ekle = null): array
{
    static $liste = null;

    if ($liste === null) {
        $liste = [];

        try {
            $ifade = db()->prepare(
                'SELECT id, baslik, ozet, durum, onem, oncelik, olay_kimlik, olay_turu, olay_yeri, inceleme,
                        UNIX_TIMESTAMP(COALESCE(olay_zamani, kaynak_tarihi, olusturuldu)) AS zaman,
                        UNIX_TIMESTAMP(COALESCE(gelisme_zamani, kaynak_tarihi, olusturuldu)) AS gelisme
                   FROM haberler
                  WHERE olusturuldu >= NOW() - INTERVAL ' . ONCELIK_OLAY_SAAT . ' HOUR AND durum <> :r
                  ORDER BY id DESC
                  LIMIT 800'
            );
            $ifade->execute(['r' => HABER_REDDEDILDI]);
            $liste = $ifade->fetchAll();
        } catch (Throwable $e) {
            error_log('[valentra] olay listesi: ' . $e->getMessage());
        }
    }

    if ($ekle !== null) {
        $liste = array_values(array_filter($liste, static fn (array $h): bool => (int) $h['id'] !== (int) $ekle['id']));
        array_unshift($liste, $ekle);
    }

    return $liste;
}

/**
 * Haberin olayini bulur: once olay TURU + YERI + ZAMANI (yapay zekanin
 * okudugu alanlar), sonra baslik ve spotun kokleriyle ozel adlar
 * (konu_ayni_olay_mi: kisi, kurum, yer adlari tutmuyorsa farkli olay).
 * Yalnizca baslik benzerligine bakilmiyor.
 *
 * @param array<string,mixed> $veri baslik, ozet, olay_turu, olay_yeri, zaman (unix)
 * @return list<array<string,mixed>> ayni olayin onceki haberleri (yeniden eskiye)
 */
function oncelik_olay_esleri(array $veri, int $haricId = 0): array
{
    require_once __DIR__ . '/konu_siniri.php';

    $tur   = (string) ($veri['olay_turu'] ?? '');
    $yer   = oncelik_yer_sade((string) ($veri['olay_yeri'] ?? ''));
    $zaman = (int) ($veri['zaman'] ?? time());
    $imza  = konu_olay_imzasi($veri);
    $esler = [];
    $kimlikler = [];

    foreach (oncelik_son_olaylar() as $h) {
        if ((int) $h['id'] === $haricId) {
            continue;
        }

        $hYer = oncelik_yer_sade((string) ($h['olay_yeri'] ?? ''));
        $hTur = (string) ($h['olay_turu'] ?? '');

        // Iki tarafta da yer biliniyor ve farkliysa farkli olay.
        if ($yer !== '' && $hYer !== '' && $yer !== $hYer) {
            continue;
        }

        $yapili = $tur !== '' && $tur !== 'diger' && $tur === $hTur && $yer !== '' && $yer === $hYer
            && abs($zaman - (int) $h['zaman']) <= 36 * 3600;

        if ($yapili || konu_ayni_olay_mi($imza, konu_olay_imzasi($h))) {
            $esler[] = $h;

            if ((string) ($h['olay_kimlik'] ?? '') !== '') {
                $kimlikler[(string) $h['olay_kimlik']] = true;
            }
        }
    }

    // Eslesen haberlerin olayindaki diger haberler de ayni olay.
    if ($kimlikler !== []) {
        $idler = array_flip(array_map(static fn (array $h): int => (int) $h['id'], $esler));

        foreach (oncelik_son_olaylar() as $h) {
            if ((int) $h['id'] !== $haricId && !isset($idler[(int) $h['id']])
                && isset($kimlikler[(string) ($h['olay_kimlik'] ?? '')])) {
                $esler[] = $h;
            }
        }
    }

    return $esler;
}

/** Olay gecmisine satir (kaynak ve guncelleme gecmisi). */
function oncelik_gecmis_yaz(string $olay, int $haberId, string $tur, string $aciklama, string $kaynak = ''): void
{
    try {
        db()->prepare(
            'INSERT INTO haber_olay_gecmisi (olay_kimlik, haber_id, tur, aciklama, kaynak)
             VALUES (:o, :h, :t, :a, :k)'
        )->execute([
            'o' => mb_substr($olay, 0, 40, 'UTF-8'),
            'h' => $haberId,
            't' => $tur,
            'a' => mb_substr($aciklama, 0, 600, 'UTF-8'),
            'k' => mb_substr($kaynak, 0, 200, 'UTF-8'),
        ]);
    } catch (Throwable $e) {
        error_log('[valentra] olay gecmisi: ' . $e->getMessage());
    }
}

/**
 * Olayin gecmisi (yeniden eskiye).
 *
 * @return list<array<string,mixed>>
 */
function oncelik_gecmis(string $olay, int $adet = 40): array
{
    if ($olay === '') {
        return [];
    }

    try {
        $ifade = db()->prepare(
            'SELECT g.*, h.baslik FROM haber_olay_gecmisi g LEFT JOIN haberler h ON h.id = g.haber_id
              WHERE g.olay_kimlik = :o ORDER BY g.id DESC LIMIT ' . max(1, min(200, $adet))
        );
        $ifade->execute(['o' => $olay]);

        return $ifade->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Yeni eklenen haberin oncelik alanlarini, olay kimligini ve karar kaydini
 * yazar (haber_taslak_ekle cagirir). Karari dondurur; 'ayni_olay_yayinda':
 * olayin yayinda ayni ya da ustun sinifta haberi var.
 *
 * @param array<string,mixed> $veri ajanin gonderdigi haber
 * @return array<string,mixed>|null
 */
function oncelik_kaydet(int $id, array $veri): ?array
{
    if (!oncelik_hazir()) {
        return null;
    }

    $karar = oncelik_karar($veri);
    $simdi = time();

    $kaynakZamani = (int) ($veri['kaynak_zamani'] ?? 0);
    $kaynakZamani = $kaynakZamani > 0 && $kaynakZamani <= $simdi && $kaynakZamani > $simdi - 30 * 86400 ? $kaynakZamani : 0;
    $olayZamani   = strtotime((string) ($veri['olay_tarihi'] ?? '')) ?: 0;
    $olayZamani   = $olayZamani > $simdi - 30 * 86400 && $olayZamani <= $simdi + 86400 ? $olayZamani : 0;
    $zaman        = $kaynakZamani ?: $simdi;

    $esler = oncelik_olay_esleri([
        'baslik'    => (string) ($veri['baslik'] ?? ''),
        'ozet'      => (string) ($veri['ozet'] ?? ''),
        'olay_turu' => $karar['olay_turu'],
        'olay_yeri' => (string) ($veri['olay_yeri'] ?? ''),
        'zaman'     => $zaman,
    ], $id);

    $olay = '';

    foreach ($esler as $es) {
        if ((string) ($es['olay_kimlik'] ?? '') !== '') {
            $olay = (string) $es['olay_kimlik'];
            break;
        }
    }

    if ($olay === '') {
        $olay = 'olay-' . date('ymd', $simdi) . '-' . substr(sha1($id . '|' . ($veri['baslik'] ?? '') . '|' . microtime()), 0, 10);
    }

    /*
     * SON ONEMLI GELISME. Olayin ilk haberi: kaynagin yayin ani. Olayin
     * sonraki haberi yeni onemli bilgi getiriyorsa (dogrulanmis can kaybi,
     * kurtarma, resmi aciklama; yapay zeka "yeni_gelisme" dedi) kendi ani;
     * getirmiyorsa olayin onceki gelisme ani: ayni bilginin baska kaynaktan
     * gelmesi tazelik saglamiyor.
     */
    $gelismeTuru = (string) ($veri['gelisme'] ?? '');
    $onceki      = $esler === [] ? 0 : max(array_map(static fn (array $e): int => (int) $e['gelisme'], $esler));
    $yeniGelisme = $esler === [] || $gelismeTuru === 'yeni_gelisme';
    $gelisme     = $yeniGelisme ? $zaman : min($zaman, $onceki);

    // Editor bu olayda otomatik yayini durdurduysa yeni haber de beklesin.
    $durduruldu = false;
    $ustunYayinda = false;

    foreach ($esler as $es) {
        if ((string) ($es['inceleme'] ?? '') === 'durduruldu') {
            $durduruldu = true;
        }

        if ((string) $es['durum'] === HABER_YAYINDA && (int) $es['oncelik'] >= $karar['sinif']) {
            $ustunYayinda = true;
        }
    }

    if ($durduruldu && !in_array($karar['yayin'], ['editor_acil', 'editor'], true)) {
        $karar['yayin']     = $karar['sinif'] >= ONCELIK_YUKSEK ? 'editor_acil' : 'editor';
        $karar['konum']     = 'editör incelemesinde';
        $karar['gerekce'][] = 'Editör bu olayda otomatik yayını durdurdu: haber incelemeye alındı.';
    }

    $karar['olay_kimlik']       = $olay;
    $karar['olay_haber_sayisi'] = count($esler) + 1;
    $karar['yeni_gelisme']      = $yeniGelisme;
    $karar['ayni_olay_yayinda'] = $ustunYayinda && !$yeniGelisme;

    if ($esler !== []) {
        $karar['gerekce'][] = $yeniGelisme
            ? 'Olayın ' . (count($esler) + 1) . '. haberi; yeni önemli gelişme getiriyor, olayın güncellik süresi yenilendi.'
            : 'Olayın ' . (count($esler) + 1) . '. haberi; yeni önemli bilgi yok, güncellik süresi yenilenmedi.';
    }

    /*
     * Olcum: kaynagin duyurmasindan ajanin taramasina, ajanin
     * dogrulamasindan sitedeki kayda gecen sure (saniye).
     */
    $dogrulamaAni = (int) ($veri['dogrulama_ani'] ?? 0);
    $dogrulamaAni = $dogrulamaAni > $simdi - 86400 && $dogrulamaAni <= $simdi + 60 ? $dogrulamaAni : 0;

    $kayit = [
        'surum'        => 1,
        'sinif'        => ONCELIK_ADLAR[$karar['sinif']],
        'yapay_zeka'   => [
            'oncelik'          => (string) ($veri['oncelik'] ?? ''),
            'olay_turu'        => (string) ($veri['olay_turu'] ?? ''),
            'olay_guncel'      => array_key_exists('olay_guncel', $veri) ? !empty($veri['olay_guncel']) : null,
            'turkiye_dogrudan' => array_key_exists('turkiye_dogrudan', $veri) ? !empty($veri['turkiye_dogrudan']) : null,
            'dogrulama'        => (string) ($veri['dogrulama'] ?? ''),
            'resmi_kurum'      => mb_substr((string) ($veri['resmi_kurum'] ?? ''), 0, 120, 'UTF-8'),
            'can_kaybi'        => (string) ($veri['can_kaybi'] ?? ''),
            'gelisme'          => $gelismeTuru,
            'gerekce'          => mb_substr((string) ($veri['oncelik_gerekce'] ?? ''), 0, 300, 'UTF-8'),
        ],
        'onem'         => max(0, min(100, (int) ($veri['onem'] ?? 50))),
        'aciliyet'     => ACILIYET_ADLAR[$karar['aciliyet']],
        'dogrulama'    => $karar['dogrulama'],
        'can_kaybi'    => $karar['can_kaybi'],
        'ajans'        => oncelik_ajans((string) ($veri['kaynak_ajans'] ?? '')),
        'bagimsiz_kaynak' => oncelik_bagimsiz_kaynak_sayisi($veri),
        'kaynaklar'    => array_values(array_filter(array_merge(
            [trim((string) ($veri['kaynak_adi'] ?? ''))],
            array_map(static fn ($k): string => is_array($k) ? trim((string) ($k['ad'] ?? '')) : '', (array) ($veri['ek_kaynaklar'] ?? []))
        ))),
        'yayin_yolu'   => $karar['yayin'],
        'gerekce'      => $karar['gerekce'],
        'zaman'        => [
            'kaynak'    => $kaynakZamani ?: null,
            'olay'      => $olayZamani ?: null,
            'dogrulama' => $dogrulamaAni ?: null,
            'kayit'     => $simdi,
            'gelisme'   => $gelisme,
        ],
        'gecikme_sn'   => [
            'kaynak_tarama'   => $kaynakZamani && $dogrulamaAni ? max(0, $dogrulamaAni - $kaynakZamani) : null,
            'dogrulama_kayit' => $dogrulamaAni ? max(0, $simdi - $dogrulamaAni) : null,
        ],
    ];

    $inceleme = match ($karar['yayin']) {
        'editor_acil' => 'acil',
        'editor'      => 'bekliyor',
        default       => '',
    };

    try {
        db()->prepare(
            'UPDATE haberler
                SET oncelik = :oncelik, aciliyet = :aciliyet, dogrulama = :dogrulama, can_kaybi = :can_kaybi,
                    olay_turu = :olay_turu, olay_kimlik = :olay, olay_yeri = :yer,
                    olay_zamani = IF(:oz > 0, NOW() - INTERVAL GREATEST(0, :simdi1 - :oz2) SECOND, NULL),
                    gelisme_zamani = NOW() - INTERVAL GREATEST(0, :simdi2 - :gelisme) SECOND,
                    inceleme = :inceleme, karar_kaydi = :kayit, guncellendi = guncellendi' . (oncelik_bant_hazir() ? ',
                    turkiye = ' . (!empty($veri['turkiye_dogrudan']) ? 1 : 0) . ',
                    kaynak_ajans = ' . db()->quote(mb_substr(trim((string) ($veri['kaynak_ajans'] ?? '')), 0, 40, 'UTF-8')) : '') . '
              WHERE id = :id'
        )->execute([
            'oncelik'   => $karar['sinif'],
            'aciliyet'  => $karar['aciliyet'],
            'dogrulama' => $karar['dogrulama'],
            'can_kaybi' => $karar['can_kaybi'],
            'olay_turu' => mb_substr((string) ($veri['olay_turu'] ?? ''), 0, 32, 'UTF-8'),
            'olay'      => $olay,
            'yer'       => mb_substr(trim((string) ($veri['olay_yeri'] ?? '')), 0, 80, 'UTF-8'),
            'oz'        => $olayZamani,
            'oz2'       => $olayZamani,
            'simdi1'    => $simdi,
            'simdi2'    => $simdi,
            'gelisme'   => $gelisme,
            'inceleme'  => $inceleme,
            'kayit'     => json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id'        => $id,
        ]);

        // Eslesen eski haberlerin olay kimligi yoksa ayni olaya baglanir.
        foreach ($esler as $es) {
            if ((string) ($es['olay_kimlik'] ?? '') === '') {
                db()->prepare('UPDATE haberler SET olay_kimlik = :o, guncellendi = guncellendi WHERE id = :id AND (olay_kimlik IS NULL OR olay_kimlik = \'\')')
                    ->execute(['o' => $olay, 'id' => (int) $es['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('[valentra] oncelik kaydi: ' . $e->getMessage());

        return null;
    }

    oncelik_gecmis_yaz(
        $olay,
        $id,
        $esler === [] ? 'ilk' : ($yeniGelisme ? 'gelisme' : 'kaynak'),
        ONCELIK_ADLAR[$karar['sinif']] . ' / ' . DOGRULAMA_ADLAR[$karar['dogrulama']] . ' — ' . (string) ($veri['baslik'] ?? ''),
        trim((string) ($veri['kaynak_adi'] ?? ''))
    );

    oncelik_son_olaylar([
        'id' => $id, 'baslik' => (string) ($veri['baslik'] ?? ''), 'ozet' => (string) ($veri['ozet'] ?? ''),
        'durum' => HABER_TASLAK, 'onem' => (int) ($veri['onem'] ?? 50), 'oncelik' => $karar['sinif'],
        'olay_kimlik' => $olay, 'olay_turu' => (string) ($veri['olay_turu'] ?? ''),
        'olay_yeri' => (string) ($veri['olay_yeri'] ?? ''), 'inceleme' => $inceleme,
        'zaman' => $zaman, 'gelisme' => $gelisme,
    ]);

    return $karar;
}

/**
 * Derleme, kaynak haberlerinin onceligini devralir: en yuksek sinif, ayni
 * olay kimligi, en son ONEMLI gelisme ani (derlemenin yazildigi an degil).
 *
 * @param list<int> $kaynakIdler
 */
function oncelik_derleme_devral(int $derlemeId, array $kaynakIdler): void
{
    $kaynakIdler = array_values(array_filter(array_map('intval', $kaynakIdler)));

    if (!oncelik_hazir() || $kaynakIdler === []) {
        return;
    }

    try {
        $yer = implode(',', $kaynakIdler);
        $k   = db()->query(
            "SELECT MAX(oncelik) AS oncelik, MAX(aciliyet) AS aciliyet, MAX(COALESCE(gelisme_zamani, kaynak_tarihi, olusturuldu)) AS gelisme,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(olay_kimlik, '') ORDER BY oncelik DESC, id DESC), ',', 1) AS olay,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(olay_turu, '') ORDER BY oncelik DESC, id DESC), ',', 1) AS tur,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(olay_yeri, '') ORDER BY oncelik DESC, id DESC), ',', 1) AS yer,
                    SUM(dogrulama = 'resmi') AS resmi, MAX(can_kaybi = 'dogrulandi') AS can
               FROM haberler WHERE id IN ($yer) AND inceleme = ''"
        )->fetch();

        if (!$k || $k['oncelik'] === null) {
            return;
        }

        $kayit = [
            'surum'      => 1,
            'sinif'      => ONCELIK_ADLAR[(int) $k['oncelik']],
            'dogrulama'  => (int) $k['resmi'] > 0 ? 'resmi' : 'bagimsiz',
            'yayin_yolu' => 'derleme',
            'gerekce'    => ['Derleme: önceliği, olayı ve son önemli gelişme zamanını kaynak haberlerinden (#' . implode(', #', $kaynakIdler) . ') devraldı; derlemenin yazılması güncellik süresini yenilemez.'],
            'zaman'      => ['kayit' => time()],
        ];

        db()->prepare(
            'UPDATE haberler
                SET oncelik = :o, aciliyet = :a, dogrulama = :d, can_kaybi = :c, olay_kimlik = :olay,
                    olay_turu = :tur, olay_yeri = :yer, gelisme_zamani = :g, karar_kaydi = :k, guncellendi = guncellendi
              WHERE id = :id'
        )->execute([
            'o' => (int) $k['oncelik'], 'a' => (int) $k['aciliyet'],
            'd' => (int) $k['resmi'] > 0 ? 'resmi' : 'bagimsiz',
            'c' => (int) $k['can'] === 1 ? 'dogrulandi' : '',
            'olay' => (string) ($k['olay'] ?? '') !== '' ? (string) $k['olay'] : null,
            'tur' => (string) ($k['tur'] ?? ''), 'yer' => (string) ($k['yer'] ?? ''),
            'g' => $k['gelisme'], 'k' => json_encode($kayit, JSON_UNESCAPED_UNICODE), 'id' => $derlemeId,
        ]);

        if ((string) ($k['olay'] ?? '') !== '') {
            oncelik_gecmis_yaz((string) $k['olay'], $derlemeId, 'derleme', 'Olayın derlemesi yazıldı (' . count($kaynakIdler) . ' kaynak haber).');
        }
    } catch (Throwable $e) {
        error_log('[valentra] derleme onceligi: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * HIZLI YAYIN VE ONBELLEK
 * ---------------------------------------------------------------------- */

/**
 * Sayfa kopyalarini gecersiz kilar: ana sayfa ve haber sayfalari bir
 * sonraki istekte bastan uretilir (bkz. sayfa_onbellek). Kritik haber
 * yayina girince ve editor mudahalesinde cagrilir.
 */
function oncelik_onbellek_gecersiz(): void
{
    $dizin = dirname(ONCELIK_ONBELLEK_DAMGA);

    if (!is_dir($dizin)) {
        @mkdir($dizin, 0775, true);
    }

    @file_put_contents(ONCELIK_ONBELLEK_DAMGA, (string) time());
    @touch(ONCELIK_ONBELLEK_DAMGA);
    clearstatcache(true, ONCELIK_ONBELLEK_DAMGA);
}

/** Karar kaydina yayin anini ve dogrulamadan yayina gecen sureyi yazar. */
function oncelik_yayin_olcumu(int $id): void
{
    if (!oncelik_hazir()) {
        return;
    }

    try {
        $ifade = db()->prepare('SELECT karar_kaydi FROM haberler WHERE id = :id');
        $ifade->execute(['id' => $id]);
        $kayit = json_decode((string) $ifade->fetchColumn(), true);

        if (!is_array($kayit) || isset($kayit['zaman']['yayin'])) {
            return;
        }

        $simdi = time();
        $kayit['zaman']['yayin'] = $simdi;

        if (!empty($kayit['zaman']['dogrulama'])) {
            $kayit['gecikme_sn']['dogrulama_yayin'] = max(0, $simdi - (int) $kayit['zaman']['dogrulama']);
        }

        db()->prepare('UPDATE haberler SET karar_kaydi = :k, guncellendi = guncellendi WHERE id = :id')
            ->execute(['k' => json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $id]);
    } catch (Throwable $e) {
        error_log('[valentra] yayin olcumu: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * SIRALAMA (SQL)
 * ---------------------------------------------------------------------- */

/** Editor mudahalesi yururlukte mi (SQL). */
function oncelik_editor_gecerli_ifadesi(): string
{
    return '(h.editor_bitis IS NOT NULL AND h.editor_bitis > NOW())';
}

/**
 * ETKIN SINIF (SQL): haberin su anki sinifi. Her sorguda yeniden
 * hesaplanir; kritik haber kosulsuz kilitlenmez:
 *
 *   - editor onceligi degistirdiyse ve suresi dolmadiysa: editorun verdigi;
 *   - kritik, son onemli gelismeden ONCELIK_KRITIK_SAAT saat: 3;
 *   - sonraki ONCELIK_KRITIK_SONRASI_SAAT saat: 2 (ust haberler);
 *   - yuksek, son onemli gelismeden ONCELIK_YUKSEK_SAAT saat: 2;
 *   - digerleri 1 (dusuk: 0).
 *
 * Yayinda olmayan ya da incelemedeki haber zaten ana sayfa sorgusunda yok.
 */
function oncelik_etkin_sinif_ifadesi(): string
{
    if (!oncelik_hazir()) {
        return '1';
    }

    $gz = 'COALESCE(h.gelisme_zamani, h.kaynak_tarihi, h.olusturuldu)';

    return 'CASE
        WHEN ' . oncelik_editor_gecerli_ifadesi() . ' AND h.editor_oncelik IS NOT NULL THEN h.editor_oncelik
        WHEN h.oncelik = ' . ONCELIK_KRITIK . ' AND ' . $gz . ' >= NOW() - INTERVAL ' . ONCELIK_KRITIK_SAAT . ' HOUR THEN 3
        WHEN h.oncelik = ' . ONCELIK_KRITIK . ' AND ' . $gz . ' >= NOW() - INTERVAL ' . (ONCELIK_KRITIK_SAAT + ONCELIK_KRITIK_SONRASI_SAAT) . ' HOUR THEN 2
        WHEN h.oncelik = ' . ONCELIK_YUKSEK . ' AND ' . $gz . ' >= NOW() - INTERVAL ' . ONCELIK_YUKSEK_SAAT . ' HOUR THEN 2
        WHEN h.oncelik = ' . ONCELIK_DUSUK . ' THEN 0
        ELSE 1 END';
}

/**
 * SINIF ICI PUAN (SQL), kritik ve yuksek icin. Sirasiyla agirlik:
 * dogrulanmis can kaybi (+15), olayin onemi (yapay zekanin 0-100 puani:
 * Turkiye'ye etki, etkilenen nufus, kamuoyunun bilmesi gerekliligi), son
 * onemli gelismenin tazeligi (15 dakikada 1 puan). Okunma, sosyal medya,
 * gorsel bu puana girmiyor.
 */
function oncelik_sinif_puani_ifadesi(): string
{
    $gz = 'COALESCE(h.gelisme_zamani, h.kaynak_tarihi, h.olusturuldu)';

    return "(h.onem + (h.can_kaybi = 'dogrulandi') * 15 - GREATEST(0, TIMESTAMPDIFF(MINUTE, $gz, NOW())) / 15)";
}

/**
 * Ana sayfa sirasinin BASI (SQL, sonunda virgul): editorun mansetten
 * cikardiklari en sonda; editorun mansete aldiklari en basta; sonra etkin
 * sinif (kritik, yuksek) ve sinif ici puan. Normal ve dusuk haberler bu
 * anahtarlarda esit kalir, eski duzenle (gun, onem esigi, toplama dalgasi,
 * puan) siralanir: olagan yeni haber kritik haberin onune gecemez.
 */
function oncelik_sira_ust(): string
{
    if (!oncelik_hazir()) {
        return '';
    }

    $gecerli = oncelik_editor_gecerli_ifadesi();
    $sinif   = oncelik_etkin_sinif_ifadesi();

    return '(' . $gecerli . ' AND h.editor_manset = -1) ASC,
        (' . $gecerli . ' AND h.editor_manset = 1) DESC,
        (CASE WHEN ' . $sinif . ' >= 2 THEN ' . $sinif . ' ELSE 1 END) DESC,
        (CASE WHEN ' . $sinif . ' >= 2 THEN ' . oncelik_sinif_puani_ifadesi() . ' ELSE 0 END) DESC,
        ';
}

/* -------------------------------------------------------------------------
 * SON DAKIKA BANDI (13.11.2026)
 *
 * Site sahibinin istegi: "Son haberler akisi ile son dakika bandini
 * birbirinden ayir. Son haberler: kronolojik. Son dakika bandi: yalnizca
 * acil, onemli, dogrulanmis ve Turkiye acisindan yuksek etkili haberler."
 *
 * Bantta olmak icin dort kosul birden:
 *   1. ACIL: kritik haber son onemli gelismeden ONCELIK_BANT_KRITIK_SAAT,
 *      yuksek oncelikli haber ONCELIK_BANT_YUKSEK_SAAT saat icinde;
 *   2. ONEMLI: sinifi kritik ya da yuksek (kelimeye gore degil; bkz.
 *      oncelik_karar);
 *   3. DOGRULANMIS: resmi aciklama ya da birbirinden bagimsiz kaynaklar;
 *   4. TURKIYE: olay Turkiye'yi dogrudan ilgilendiriyor.
 * Yayinda olmayan, incelemedeki ya da ana akis disindaki haber zaten yok.
 * Editor bir haberi sureli olarak banda alabilir ya da banttan cikarabilir
 * (oncelik_bant_ayarla); sure dolunca otomatik karara doner.
 *
 * Ayar 'onizleme' iken (varsayilan) bant yalnizca panelde, mevcut seritle
 * yan yana gorunur; sitede bir sey degismez. 'yayin' yapilinca sitedeki
 * "Son Dakika" seridi bu bandi, altindaki kayan serit "Son Haberler"
 * adiyla kronolojik akisi gosterir.
 * ---------------------------------------------------------------------- */

/** Bant sutunlari veritabaninda var mi. */
function oncelik_bant_hazir(): bool
{
    static $hazir = null;

    if ($hazir === null) {
        try {
            $hazir = oncelik_hazir() && (bool) db()->query("SHOW COLUMNS FROM haberler LIKE 'bant_bitis'")->fetch();
        } catch (Throwable $e) {
            $hazir = false;
        }
    }

    return $hazir;
}

/** Bant sitede yayinda mi (degilse yalnizca panelde onizleme). */
function oncelik_bant_yayinda(): bool
{
    require_once __DIR__ . '/ayarlar.php';

    return oncelik_bant_hazir() && ayar_oku(ONCELIK_BANT_AYARI, 'onizleme') === 'yayin';
}

/** Editorun bant karari yururlukte mi (SQL). */
function oncelik_bant_editor_ifadesi(int $yon): string
{
    return '(h.bant_editor = ' . ($yon >= 0 ? 1 : -1) . ' AND h.bant_bitis IS NOT NULL AND h.bant_bitis > NOW())';
}

/** Haber otomatik olarak bantta mi (SQL; editor karari haric). */
function oncelik_bant_otomatik_ifadesi(): string
{
    $gz = 'COALESCE(h.gelisme_zamani, h.kaynak_tarihi, h.olusturuldu)';

    return "(h.dogrulama IN ('resmi', 'bagimsiz') AND h.turkiye = 1 AND h.inceleme = '' AND (
        (h.oncelik = " . ONCELIK_KRITIK . " AND $gz >= NOW() - INTERVAL " . ONCELIK_BANT_KRITIK_SAAT . " HOUR)
        OR (h.oncelik = " . ONCELIK_YUKSEK . " AND $gz >= NOW() - INTERVAL " . ONCELIK_BANT_YUKSEK_SAAT . ' HOUR)))';
}

/** Bandin kosulu (SQL). */
function oncelik_bant_kosulu(): string
{
    return '(' . oncelik_bant_editor_ifadesi(1) . ' OR (NOT ' . oncelik_bant_editor_ifadesi(-1) . ' AND ' . oncelik_bant_otomatik_ifadesi() . '))';
}

/**
 * Bir haberin bant karari ve gerekcesi (panel, "Neden bu oncelik verildi?"
 * ve testler). Veritabanina dokunmaz.
 *
 * @param array<string,mixed> $h haberler satiri
 * @return array{bantta:bool,gerekce:list<string>}
 */
function oncelik_bant_karari(array $h, ?int $simdi = null): array
{
    $simdi   = $simdi ?? time();
    $bitis   = strtotime((string) ($h['bant_bitis'] ?? '')) ?: 0;
    $editor  = $bitis > $simdi ? (int) ($h['bant_editor'] ?? 0) : 0;

    if ($editor === 1) {
        return ['bantta' => true, 'gerekce' => ['Editör banda aldı (' . date('d.m H:i', $bitis) . '\'e kadar).']];
    }

    if ($editor === -1) {
        return ['bantta' => false, 'gerekce' => ['Editör banttan çıkardı (' . date('d.m H:i', $bitis) . '\'e kadar).']];
    }

    $sinif     = (int) ($h['oncelik'] ?? ONCELIK_NORMAL);
    $dogrulama = (string) ($h['dogrulama'] ?? '');
    $gelisme   = strtotime((string) ($h['gelisme_zamani'] ?? '')) ?: (strtotime((string) ($h['kaynak_tarihi'] ?? '')) ?: (strtotime((string) ($h['olusturuldu'] ?? '')) ?: $simdi));
    $saat      = $sinif === ONCELIK_KRITIK ? ONCELIK_BANT_KRITIK_SAAT : ONCELIK_BANT_YUKSEK_SAAT;
    $eksik     = [];

    if ($sinif < ONCELIK_YUKSEK) {
        $eksik[] = 'önceliği ' . ONCELIK_ADLAR[$sinif] . ' (bant için yüksek ya da kritik gerekir)';
    }

    if (!in_array($dogrulama, ['resmi', 'bagimsiz'], true)) {
        $eksik[] = 'doğrulanmadı (' . (DOGRULAMA_ADLAR[$dogrulama] ?? 'bilinmiyor') . ')';
    }

    if (empty($h['turkiye'])) {
        $eksik[] = 'Türkiye\'yi doğrudan ilgilendirmiyor';
    }

    if ((string) ($h['inceleme'] ?? '') !== '') {
        $eksik[] = 'editör incelemesinde';
    }

    if ($sinif >= ONCELIK_YUKSEK && $gelisme < $simdi - $saat * 3600) {
        $eksik[] = 'son önemli gelişmenin üzerinden ' . $saat . ' saatten fazla geçti';
    }

    if ($eksik !== []) {
        return ['bantta' => false, 'gerekce' => ['Son dakika bandında değil: ' . implode('; ', $eksik) . '.']];
    }

    return ['bantta' => true, 'gerekce' => [
        'Son dakika bandında: ' . ONCELIK_ADLAR[$sinif] . ' öncelikli, ' . DOGRULAMA_ADLAR[$dogrulama]
        . ' ile doğrulandı, Türkiye\'yi doğrudan ilgilendiriyor, son önemli gelişme ' . $saat . ' saat içinde.',
    ]];
}

/**
 * Son dakika bandi: her olaydan tek haber (en son gelismesi), once kritik.
 *
 * @return list<array<string,mixed>>
 */
function oncelik_bant(int $adet = 6): array
{
    if (!oncelik_bant_hazir()) {
        return [];
    }

    $gz = 'COALESCE(h.gelisme_zamani, h.kaynak_tarihi, h.olusturuldu)';

    try {
        $satirlar = db()->query(
            'SELECT h.id, h.baslik, h.slug, h.oncelik, h.dogrulama, h.turkiye, h.inceleme, h.olay_kimlik,
                    h.gelisme_zamani, h.kaynak_tarihi, h.olusturuldu, h.bant_editor, h.bant_bitis, h.kaynak_adi,
                    DATE_FORMAT(' . $gz . ', \'%H:%i\') AS zaman
               FROM haberler h
              WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . ' AND ' . haber_ana_akis_kosulu(false, false) . '
                AND ' . oncelik_bant_kosulu() . '
              ORDER BY ' . oncelik_bant_editor_ifadesi(1) . ' DESC, h.oncelik DESC, ' . $gz . ' DESC, h.id DESC
              LIMIT ' . max(1, min(12, $adet)) * 4
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] son dakika bandi: ' . $e->getMessage());

        return [];
    }

    // Ayni olay bantta bir kez: on kaynaktan gelen tek olay on satir olmasin.
    $olaylar = [];
    $bant    = [];

    foreach ($satirlar as $s) {
        $olay = (string) ($s['olay_kimlik'] ?? '');

        if ($olay !== '' && isset($olaylar[$olay])) {
            continue;
        }

        $olaylar[$olay !== '' ? $olay : 'h' . $s['id']] = true;
        $bant[] = $s;

        if (count($bant) >= $adet) {
            break;
        }
    }

    return $bant;
}

/**
 * Editor bir haberi sureli olarak banda alir (1) ya da banttan cikarir (-1).
 * Gerekce ve bitis zorunlu; sure dolunca haber otomatik karara doner.
 */
function oncelik_bant_ayarla(int $id, int $yon, float $saat, string $gerekce): void
{
    $gerekce = trim($gerekce);

    if (!oncelik_bant_hazir()) {
        throw new InvalidArgumentException('Veritabanı güncellenmeden bant ayarlanamaz.');
    }

    if ($gerekce === '') {
        throw new InvalidArgumentException('Gerekçe zorunlu.');
    }

    if ($saat <= 0 || $saat > ONCELIK_EDITOR_EN_FAZLA_SAAT) {
        throw new InvalidArgumentException('Bitiş süresi 0-' . ONCELIK_EDITOR_EN_FAZLA_SAAT . ' saat arasında olmalı.');
    }

    $h = haber_bul($id);

    db()->prepare(
        'UPDATE haberler SET bant_editor = :y, bant_bitis = NOW() + INTERVAL :dk MINUTE, guncellendi = guncellendi WHERE id = :id'
    )->execute(['y' => $yon >= 0 ? 1 : -1, 'dk' => (int) round($saat * 60), 'id' => $id]);

    oncelik_gecmis_yaz(
        (string) ($h['olay_kimlik'] ?? '') ?: 'haber-' . $id,
        $id,
        'editor',
        'Editör: son dakika ' . ($yon >= 0 ? 'bandına aldı' : 'bandından çıkardı') . ' ('
        . rtrim(rtrim(number_format($saat, 1, ',', ''), '0'), ',') . ' saat). Gerekçe: ' . $gerekce
    );
    oncelik_onbellek_gecersiz();
}

/** Editorun bant kararini kaldirir: haber otomatik karara doner. */
function oncelik_bant_kaldir(int $id): void
{
    if (!oncelik_bant_hazir()) {
        return;
    }

    $h = haber_bul($id);

    db()->prepare('UPDATE haberler SET bant_editor = 0, bant_bitis = NULL, guncellendi = guncellendi WHERE id = :id')
        ->execute(['id' => $id]);

    oncelik_gecmis_yaz((string) ($h['olay_kimlik'] ?? '') ?: 'haber-' . $id, $id, 'editor', 'Editör bant kararını kaldırdı; haber otomatik karara döndü.');
    oncelik_onbellek_gecersiz();
}

/* -------------------------------------------------------------------------
 * EDITOR MUDAHALESI
 * ---------------------------------------------------------------------- */

/**
 * Editor mudahalesi: mansete al / mansetten cikar / onceligi degistir.
 * Bitis zamani ve gerekce zorunlu; otomasyon suresi dolana kadar bu karari
 * degistirmez, dolunca haber kendiliginden yeniden degerlendirilir.
 *
 * @param array{manset?:int,oncelik?:?int,saat?:float,gerekce?:string} $ayar
 */
function oncelik_editor_ayarla(int $id, array $ayar, int $yoneticiId): void
{
    $gerekce = trim((string) ($ayar['gerekce'] ?? ''));
    $saat    = (float) ($ayar['saat'] ?? 0);
    $manset  = max(-1, min(1, (int) ($ayar['manset'] ?? 0)));
    $oncelik = ($ayar['oncelik'] ?? null) === null || $ayar['oncelik'] === '' ? null : max(0, min(3, (int) $ayar['oncelik']));

    if ($gerekce === '') {
        throw new InvalidArgumentException('Gerekçe zorunlu.');
    }

    if ($saat <= 0 || $saat > ONCELIK_EDITOR_EN_FAZLA_SAAT) {
        throw new InvalidArgumentException('Bitiş süresi 0-' . ONCELIK_EDITOR_EN_FAZLA_SAAT . ' saat arasında olmalı.');
    }

    if ($manset === 0 && $oncelik === null) {
        throw new InvalidArgumentException('Manşet ya da öncelik seçilmeli.');
    }

    $h = haber_bul($id);

    db()->prepare(
        'UPDATE haberler
            SET editor_manset = :m, editor_oncelik = :o, editor_bitis = NOW() + INTERVAL :dk MINUTE,
                editor_gerekce = :g, editor_id = :y, editor_zaman = NOW(), guncellendi = guncellendi
          WHERE id = :id'
    )->execute([
        'm' => $manset, 'o' => $oncelik, 'dk' => (int) round($saat * 60),
        'g' => mb_substr($gerekce, 0, 300, 'UTF-8'), 'y' => $yoneticiId, 'id' => $id,
    ]);

    $ne = [];

    if ($manset === 1) {
        $ne[] = 'manşete alındı';
    } elseif ($manset === -1) {
        $ne[] = 'manşetten çıkarıldı';
    }

    if ($oncelik !== null) {
        $ne[] = 'öncelik ' . ONCELIK_ADLAR[$oncelik] . ' yapıldı';
    }

    oncelik_gecmis_yaz(
        (string) ($h['olay_kimlik'] ?? '') ?: 'haber-' . $id,
        $id,
        'editor',
        'Editör: ' . implode(', ', $ne) . ' (' . rtrim(rtrim(number_format($saat, 1, ',', ''), '0'), ',') . ' saat). Gerekçe: ' . $gerekce
    );
    oncelik_onbellek_gecersiz();
}

/** Editor mudahalesini kaldirir: haber otomatik siraya doner. */
function oncelik_editor_kaldir(int $id): void
{
    $h = haber_bul($id);

    db()->prepare(
        'UPDATE haberler
            SET editor_manset = 0, editor_oncelik = NULL, editor_bitis = NULL, guncellendi = guncellendi
          WHERE id = :id'
    )->execute(['id' => $id]);

    oncelik_gecmis_yaz((string) ($h['olay_kimlik'] ?? '') ?: 'haber-' . $id, $id, 'editor', 'Editör müdahalesi kaldırıldı; haber otomatik sıraya döndü.');
    oncelik_onbellek_gecersiz();
}

/**
 * Otomatik yayini durdurur: haber yayindaysa geri cekilir ve otomatik yayin
 * onu yeniden yayimlamaz; ayni olayin sonradan gelen haberleri de editor
 * incelemesine duser (bkz. oncelik_kaydet).
 */
function oncelik_yayin_durdur(int $id, string $gerekce): void
{
    $gerekce = trim($gerekce);

    if ($gerekce === '') {
        throw new InvalidArgumentException('Gerekçe zorunlu.');
    }

    $h = haber_bul($id);

    db()->prepare(
        "UPDATE haberler SET durum = :t, inceleme = 'durduruldu', guncellendi = guncellendi WHERE id = :id"
    )->execute(['t' => HABER_TASLAK, 'id' => $id]);

    oncelik_gecmis_yaz((string) ($h['olay_kimlik'] ?? '') ?: 'haber-' . $id, $id, 'geri_cekme', 'Editör otomatik yayını durdurdu / haberi geri çekti. Gerekçe: ' . $gerekce);
    oncelik_onbellek_gecersiz();
}

/** Haberin altina dusulen duzeltme notu (bos metin notu kaldirir). */
function oncelik_duzeltme_yaz(int $id, string $not): void
{
    $h   = haber_bul($id);
    $not = trim($not);

    db()->prepare('UPDATE haberler SET duzeltme = :d WHERE id = :id')
        ->execute(['d' => mb_substr($not, 0, 600, 'UTF-8'), 'id' => $id]);

    oncelik_gecmis_yaz(
        (string) ($h['olay_kimlik'] ?? '') ?: 'haber-' . $id,
        $id,
        'duzeltme',
        $not !== '' ? 'Düzeltme notu: ' . $not : 'Düzeltme notu kaldırıldı.'
    );
    oncelik_onbellek_gecersiz();
}

/**
 * Editor incelemesi bekleyen haberler (acil olanlar once).
 *
 * @return list<array<string,mixed>>
 */
function oncelik_inceleme_bekleyenler(int $adet = 50): array
{
    if (!oncelik_hazir()) {
        return [];
    }

    try {
        $ifade = db()->prepare(
            "SELECT h.*, k.ad AS kategori_adi FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = :t AND h.inceleme <> ''
              ORDER BY (h.inceleme = 'acil') DESC, h.oncelik DESC, h.id DESC
              LIMIT " . max(1, min(200, $adet))
        );
        $ifade->execute(['t' => HABER_TASLAK]);

        return $ifade->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Acil inceleme bekleyen haber sayisi (panel uyarisi). */
function oncelik_acil_sayisi(): int
{
    if (!oncelik_hazir()) {
        return 0;
    }

    try {
        $ifade = db()->prepare("SELECT COUNT(*) FROM haberler WHERE durum = :t AND inceleme = 'acil'");
        $ifade->execute(['t' => HABER_TASLAK]);

        return (int) $ifade->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/* -------------------------------------------------------------------------
 * ACIKLAMA: "Bu haber neden bu oncelikte ve neden ana sayfada bu sirada?"
 * ---------------------------------------------------------------------- */

/**
 * Ana sayfanin ilk $adet haberi, siralamada kullanilan degerlerle.
 *
 * @return list<array<string,mixed>>
 */
function oncelik_ana_sayfa_sirasi(int $adet = 20): array
{
    $ek = oncelik_hazir()
        ? ', ' . oncelik_etkin_sinif_ifadesi() . ' AS etkin_sinif, ' . oncelik_sinif_puani_ifadesi() . ' AS sinif_puani,
           ' . oncelik_editor_gecerli_ifadesi() . ' AS editor_gecerli,
           TIMESTAMPDIFF(MINUTE, COALESCE(h.gelisme_zamani, h.kaynak_tarihi, h.olusturuldu), NOW()) AS gelisme_dakika,
           TIMESTAMPDIFF(MINUTE, NOW(), h.editor_bitis) AS editor_kalan_dakika'
        : ', 1 AS etkin_sinif, 0 AS sinif_puani, 0 AS editor_gecerli, 0 AS gelisme_dakika, NULL AS editor_kalan_dakika';

    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug,
                ' . haber_dalga_ifadesi() . ' AS dalga, ' . haber_etkin_onem_ifadesi() . ' AS etkin_onem,
                ' . haber_yas_dakika_ifadesi() . ' AS yas_dakika' . $ek . '
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
           ' . haber_okunma_birlesimi() . '
          WHERE h.durum = :durum AND ' . haber_ana_akis_kosulu() . '
          ORDER BY ' . haber_sirasi(true) . '
          LIMIT ' . max(1, min(60, $adet))
    );
    $ifade->execute(['durum' => HABER_YAYINDA]);

    return $ifade->fetchAll();
}

/**
 * Haberin sirasinin aciklamasi (oncelik_ana_sayfa_sirasi satiri icin).
 *
 * @param array<string,mixed> $h
 * @return array{sinif:string,neden:string,ayrinti:list<string>,uyari:string}
 */
function oncelik_aciklama(array $h): array
{
    $kayit   = json_decode((string) ($h['karar_kaydi'] ?? ''), true);
    $kayit   = is_array($kayit) ? $kayit : [];
    $sinif   = (int) ($h['oncelik'] ?? ONCELIK_NORMAL);
    $etkin   = (int) ($h['etkin_sinif'] ?? $sinif);
    $gecerli = (int) ($h['editor_gecerli'] ?? 0) === 1;
    $gDakika = (int) ($h['gelisme_dakika'] ?? 0);
    $sure    = static fn (int $dk): string => $dk < 60 ? $dk . ' dk' : intdiv($dk, 60) . ' sa ' . ($dk % 60) . ' dk';
    $uyari   = '';

    if ($gecerli && (int) ($h['editor_manset'] ?? 0) === 1) {
        $neden = 'Editör manşete aldı (' . $sure(max(0, (int) $h['editor_kalan_dakika'])) . ' daha): ' . (string) $h['editor_gerekce'];
    } elseif ($gecerli && (int) ($h['editor_manset'] ?? 0) === -1) {
        $neden = 'Editör manşetten çıkardı (' . $sure(max(0, (int) $h['editor_kalan_dakika'])) . ' daha): ' . (string) $h['editor_gerekce'];
    } elseif ($gecerli && $h['editor_oncelik'] !== null) {
        $neden = 'Editör önceliği ' . ONCELIK_ADLAR[(int) $h['editor_oncelik']] . ' yaptı (' . $sure(max(0, (int) $h['editor_kalan_dakika'])) . ' daha): ' . (string) $h['editor_gerekce'];
    } elseif ($etkin === ONCELIK_KRITIK) {
        $neden = 'Kritik sınıf: son önemli gelişme ' . $sure($gDakika) . ' önce (kritik süre ' . ONCELIK_KRITIK_SAAT . ' saat). Olağan haberler önüne geçemez; sınıf içi puan ' . round((float) $h['sinif_puani'], 1) . '.';
    } elseif ($etkin === ONCELIK_YUKSEK && $sinif === ONCELIK_KRITIK) {
        $neden = 'Kritik haberdi; son önemli gelişmenin üzerinden ' . $sure($gDakika) . ' geçti, üst haberlere indi (sınıf içi puan ' . round((float) $h['sinif_puani'], 1) . ').';
    } elseif ($etkin === ONCELIK_YUKSEK) {
        $neden = 'Yüksek öncelik: son önemli gelişme ' . $sure($gDakika) . ' önce (süre ' . ONCELIK_YUKSEK_SAAT . ' saat); sınıf içi puan ' . round((float) $h['sinif_puani'], 1) . '.';
    } else {
        $neden = ($sinif >= ONCELIK_YUKSEK ? 'Öncelik süresi doldu; olağan sırada. ' : 'Olağan sıra: ')
            . ((int) ($h['one_cikan'] ?? 0) === 1 ? 'öne çıkarılmış; ' : '')
            . 'önem ' . (int) $h['onem'] . ((int) $h['onem'] >= ANA_SAYFA_ONEM_ESIGI ? ' (eşik üstü)' : ' (eşik altı)')
            . ', ' . ((int) $h['dalga'] + 1) . '. toplama dalgası, şimdiki önem ' . round((float) $h['etkin_onem'], 1) . '.';
    }

    if ($gecerli && (int) $h['editor_kalan_dakika'] <= 30) {
        $uyari = 'Editör müdahalesinin süresi ' . max(0, (int) $h['editor_kalan_dakika']) . ' dakika sonra doluyor; haber otomatik sıraya dönecek.';
    } elseif (!$gecerli && !empty($h['editor_bitis'])) {
        $uyari = 'Editör müdahalesinin süresi doldu (' . (string) $h['editor_bitis'] . '); haber otomatik olarak yeniden değerlendirildi.';
    } elseif ($sinif === ONCELIK_KRITIK && $etkin === ONCELIK_KRITIK && $gDakika >= (ONCELIK_KRITIK_SAAT - 1) * 60) {
        $uyari = 'Kritik süre bir saat içinde doluyor; yeni önemli gelişme yoksa haber üst haberlere inecek.';
    }

    $ayrinti = [
        'Editoryal önem: ' . ONCELIK_ADLAR[$sinif] . ($etkin !== $sinif ? ' (şu an ' . (ONCELIK_ADLAR[$etkin] ?? $etkin) . ' gibi sıralanıyor)' : ''),
        'Önem puanı: ' . (int) $h['onem'] . ' (şimdiki: ' . round((float) ($h['etkin_onem'] ?? $h['onem']), 1) . ')',
        'Yayın aciliyeti: ' . ACILIYET_ADLAR[(int) ($h['aciliyet'] ?? 0)],
        'Doğrulama: ' . (DOGRULAMA_ADLAR[(string) ($h['dogrulama'] ?? '')] ?? '—')
            . ((string) ($h['can_kaybi'] ?? '') !== '' ? '; can kaybı: ' . ['dogrulandi' => 'doğrulandı', 'dogrulanmadi' => 'doğrulanmadı', 'yok' => 'yok'][(string) $h['can_kaybi']] : ''),
        'Kaynaklar: ' . (implode(', ', (array) ($kayit['kaynaklar'] ?? [])) ?: (string) ($h['kaynak_adi'] ?? '—')),
        'Olay: ' . ((string) ($h['olay_kimlik'] ?? '') ?: '—')
            . ((string) ($h['olay_turu'] ?? '') !== '' ? ' (' . (ONCELIK_POLITIKA_TURLERI[(string) $h['olay_turu']] ?? (string) $h['olay_turu']) . ((string) ($h['olay_yeri'] ?? '') !== '' ? ', ' . (string) $h['olay_yeri'] : '') . ')' : ''),
        'Olay zamanı: ' . ((string) ($h['olay_zamani'] ?? '') ?: (string) ($h['kaynak_tarihi'] ?? '—')),
        'Son önemli gelişme: ' . ((string) ($h['gelisme_zamani'] ?? '') ?: '—') . ' (' . $sure($gDakika) . ' önce)',
        'Toplanma: ' . (string) ($h['olusturuldu'] ?? ''),
    ];

    if (!empty($kayit['ajans'])) {
        $ayrinti[] = 'Haberi ilk veren ajans: ' . mb_strtoupper((string) $kayit['ajans'], 'UTF-8')
            . ' (aynı ajansın başka sitedeki kopyası ayrı doğrulama sayılmaz; bağımsız kaynak: ' . (int) ($kayit['bagimsiz_kaynak'] ?? 1) . ')';
    }

    foreach ((array) ($kayit['gerekce'] ?? []) as $g) {
        $ayrinti[] = 'Karar: ' . (string) $g;
    }

    if (array_key_exists('bant_editor', $h)) {
        foreach (oncelik_bant_karari($h)['gerekce'] as $g) {
            $ayrinti[] = $g;
        }
    }

    if (!empty($kayit['yapay_zeka']['gerekce'])) {
        $ayrinti[] = 'Yapay zekânın gerekçesi: ' . (string) $kayit['yapay_zeka']['gerekce'];
    }

    $gec = (array) ($kayit['gecikme_sn'] ?? []);

    if (isset($gec['kaynak_tarama'])) {
        $ayrinti[] = 'Kaynağın duyurmasından taramaya: ' . $sure(intdiv((int) $gec['kaynak_tarama'], 60));
    }

    if (isset($gec['dogrulama_yayin'])) {
        $ayrinti[] = 'Doğrulamadan yayına: ' . (int) $gec['dogrulama_yayin'] . ' sn';
    }

    return ['sinif' => ONCELIK_ADLAR[$etkin] ?? (string) $etkin, 'neden' => $neden, 'ayrinti' => $ayrinti, 'uyari' => $uyari];
}
