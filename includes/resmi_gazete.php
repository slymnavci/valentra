<?php
declare(strict_types=1);

/**
 * Resmî Gazete günlük fihristi: site tarafı.
 *
 * Sitenin "Resmi Gazete" sayfasi gunun BUTUN maddelerini listeliyor;
 * ajanin haber icin yaptigi model elemesi burada yok. Baslik ve adres
 * resmi fihristten aynen aliniyor.
 *
 * AYRISTIRICI AJANLA ORTAK. Fihristi cozen kod ajan/src/ResmiGazete.php;
 * GitHub'da kaynak testiyle gercek fihristte sinandi. Ikinci bir kopya
 * yazmak, biri duzeltildiginde digerinin eski kalmasi demekti.
 *
 * Veriyi SITE cekiyor: site Turkiye'de ve resmigazete.gov.tr'ye dogrudan
 * ulasiyor. Iki tetik var:
 *   - ajan her calismada api/resmi-gazete-tazele.php'yi durtuyor;
 *   - Resmi Gazete sayfasi acildiginda son cekim bir saatten eskiyse
 *     yalnizca BUGUN kisa sure sinirla tazeleniyor. GitHub zamanli
 *     calismalari atlayabildigi icin gece yarisindan sonraki ilk ziyaret
 *     ajani beklemeden bugunun sayisini gorsun diye.
 *
 * ANA SAYFA bu dosyadaki hicbir cekimi tetiklemez.
 */

require_once __DIR__ . '/http_ortak.php';
require_once __DIR__ . '/ayarlar.php';
require_once dirname(__DIR__) . '/ajan/src/ResmiGazete.php';

use Valentra\Ajan\{Kodlama, ResmiGazete};

/** Sayfa açılışında tazeleme: son başarılı çekim bundan eskiyse (dakika). */
const RG_TAZELIK_DAKIKA = 60;

/*
 * Iki deneme arasi en az bu kadar (saniye). Gece yarisindan sonra ilk
 * ziyaretciler ayni anda gelirse hepsi ayni istegi atmasin; kaynak da
 * dustuyse her ziyaret beklemesin.
 */
const RG_DENEME_ARALIGI = 600;

function rg_saat_dilimi(): DateTimeZone
{
    return new DateTimeZone('Europe/Istanbul');
}

/**
 * Bir günün fihristini çeker ve çözer. VERİTABANINA YAZMAZ.
 *
 * Normal sayi alinamazsa gun "yok" sayiliyor (henuz yayimlanmamis ya da
 * kaynak erisilemiyor). Mukerrer alinamazsa siradakilere bakilmiyor:
 * mukerrerler sirayla cikiyor, M1 yoksa M2 de yok.
 *
 * @return array{bulundu:bool,sayi:?int,maddeler:list<array<string,mixed>>,hata:string}
 */
function rg_gunu_cek(DateTimeImmutable $gun, int $zamanAsimi = 10): array
{
    $sonuc   = ['bulundu' => false, 'sayi' => null, 'maddeler' => [], 'hata' => ''];
    $gorulen = [];

    foreach (ResmiGazete::fihristAdresleri($gun) as $sira => $adres) {
        $yanit = http_getir($adres, $zamanAsimi);

        if (!$yanit['tamam']) {
            if ($sira === 0) {
                $sonuc['hata'] = $gun->format('d.m.Y') . ' fihristi alınamadı: ' . $yanit['neden'];

                return $sonuc;
            }

            break;
        }

        $html = Kodlama::utf8((string) $yanit['govde']);

        if ($sira === 0) {
            $sonuc['bulundu'] = true;
            $sonuc['sayi']    = ResmiGazete::sayiBul($html);
        }

        foreach (ResmiGazete::fihristiCoz($html, $adres) as $madde) {
            if (isset($gorulen[$madde['baglanti']])) {
                continue;
            }

            $gorulen[$madde['baglanti']] = true;
            $sonuc['maddeler'][] = $madde;
        }
    }

    /*
     * Sayi numarasi fihristte YOK (canli sinamada goruldu); madde
     * sayfalarinin basliginda var. O gunun ilk HTML maddesinden okunuyor.
     * PDF maddelerde baslik metni yok, atlaniyor. Bulunamazsa numara bos
     * kalir; tarihten hesaplanmiyor — resmi bir numara tahminle yazilmaz.
     */
    if ($sonuc['sayi'] === null) {
        foreach ($sonuc['maddeler'] as $madde) {
            if ((int) ($madde['mukerrer'] ?? 0) !== 0
                || !preg_match('#\.html?$#i', (string) $madde['baglanti'])) {
                continue;
            }

            $sayfa = http_getir((string) $madde['baglanti'], $zamanAsimi);

            if ($sayfa['tamam']) {
                $sonuc['sayi'] = ResmiGazete::sayiBul(Kodlama::utf8((string) $sayfa['govde']));
            }

            break;
        }
    }

    return $sonuc;
}

/**
 * Çözülen maddeleri kaydeder; olan güncellenir, olmayan eklenir.
 *
 * Tasinabilir yazildi (SELECT + UPDATE/INSERT): MySQL'e ozgu "ON
 * DUPLICATE KEY" kullanilmadi ki uctan uca sinama SQLite'ta kossun.
 *
 * @param list<array<string,mixed>> $maddeler
 */
function rg_kaydet(DateTimeImmutable $gun, ?int $sayi, array $maddeler): int
{
    $bul    = db()->prepare('SELECT id FROM resmi_gazete WHERE url = :u');
    $guncel = db()->prepare(
        'UPDATE resmi_gazete SET tarih = :tarih, sayi = :sayi, mukerrer = :mukerrer,
                ust_bolum = :ust, bolum = :bolum, sira = :sira, baslik = :baslik
          WHERE id = :id'
    );
    $ekle   = db()->prepare(
        'INSERT INTO resmi_gazete (tarih, sayi, mukerrer, ust_bolum, bolum, sira, baslik, url)
         VALUES (:tarih, :sayi, :mukerrer, :ust, :bolum, :sira, :baslik, :url)'
    );

    $adet = 0;

    foreach (array_values($maddeler) as $sira => $m) {
        $url = mb_substr((string) $m['baglanti'], 0, 190, 'UTF-8');

        $alanlar = [
            // Maddenin kendi tarihi (adresten); yoksa fihristin gunu.
            'tarih'    => !empty($m['tarih']) ? substr((string) $m['tarih'], 0, 10) : $gun->format('Y-m-d'),
            'sayi'     => $sayi,
            'mukerrer' => (int) ($m['mukerrer'] ?? 0),
            'ust'      => mb_substr((string) ($m['ust_bolum'] ?? ''), 0, 60, 'UTF-8'),
            'bolum'    => mb_substr((string) ($m['bolum'] ?? ''), 0, 80, 'UTF-8'),
            'sira'     => $sira,
            'baslik'   => mb_substr((string) $m['baslik'], 0, 700, 'UTF-8'),
        ];

        $bul->execute(['u' => $url]);
        $id = $bul->fetchColumn();

        if ($id !== false) {
            $guncel->execute($alanlar + ['id' => (int) $id]);
        } else {
            $ekle->execute($alanlar + ['url' => $url]);
        }

        $adet++;
    }

    return $adet;
}

/**
 * Son $gunSayisi günü (bugün dahil) çeker ve kaydeder.
 *
 * @return array{gunler:int,madde:int,hatalar:list<string>}
 */
function rg_tazele(int $gunSayisi = 2, int $zamanAsimi = 10): array
{
    ayar_yaz('rg_son_deneme', (string) time());

    $bugun = new DateTimeImmutable('now', rg_saat_dilimi());
    $ozet  = ['gunler' => 0, 'madde' => 0, 'hatalar' => []];

    for ($i = 0; $i < max(1, $gunSayisi); $i++) {
        $gun   = $bugun->modify('-' . $i . ' day');
        $cekim = rg_gunu_cek($gun, $zamanAsimi);

        if (!$cekim['bulundu']) {
            $ozet['hatalar'][] = $cekim['hata'];
            continue;
        }

        $ozet['gunler']++;
        $ozet['madde'] += rg_kaydet($gun, $cekim['sayi'], $cekim['maddeler']);
    }

    if ($ozet['gunler'] > 0) {
        ayar_yaz('rg_son_cekim', (string) time());
    }

    ayar_yaz('rg_son_hata', mb_substr(implode(' ', $ozet['hatalar']), 0, 500, 'UTF-8'));

    return $ozet;
}

/**
 * Sayfa açılışında gerekirse bugünü tazeler. ASLA HATA FIRLATMAZ.
 *
 * Yalnizca Resmi Gazete sayfasi cagiriyor. Kisa sure siniri (6 sn) ve
 * yalnizca bugun: ziyaretci en kotu ihtimalle birkac saniye bekler,
 * kaynak dustuyse de sonraki on dakika hic beklemez.
 */
function rg_gerekirse_tazele(): void
{
    try {
        $simdi     = time();
        $sonCekim  = (int) ayar_oku('rg_son_cekim', '0');
        $sonDeneme = (int) ayar_oku('rg_son_deneme', '0');

        if ($simdi - $sonCekim < RG_TAZELIK_DAKIKA * 60 || $simdi - $sonDeneme < RG_DENEME_ARALIGI) {
            return;
        }

        rg_tazele(1, 6);
    } catch (Throwable $e) {
        error_log('[valentra] Resmi Gazete tazelenemedi: ' . $e->getMessage());
    }
}

/**
 * Kayıtlı günler, en yeni önce.
 *
 * @return list<string> 'Y-m-d'
 */
function rg_gunler(int $limit = 14): array
{
    $ifade = db()->prepare(
        'SELECT DISTINCT tarih FROM resmi_gazete ORDER BY tarih DESC LIMIT ' . max(1, $limit)
    );
    $ifade->execute();

    return array_map(static fn ($t): string => substr((string) $t, 0, 10), $ifade->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Bir günün maddeleri, fihristteki sırayla (mükerrerler sonda).
 *
 * @return list<array<string,mixed>>
 */
function rg_gun_maddeleri(string $tarih): array
{
    $ifade = db()->prepare(
        'SELECT * FROM resmi_gazete WHERE tarih = :t ORDER BY mukerrer, sira, id'
    );
    $ifade->execute(['t' => $tarih]);

    return $ifade->fetchAll();
}

/**
 * Bölüm sayıları, fihristteki sırayla: ['Tebliğler' => 3, ...].
 *
 * @param list<array<string,mixed>> $maddeler
 * @return array<string,int>
 */
function rg_bolum_sayilari(array $maddeler): array
{
    $sayilar = [];

    foreach ($maddeler as $m) {
        $bolum = (string) $m['bolum'] !== '' ? (string) $m['bolum'] : 'Diğer';
        $sayilar[$bolum] = ($sayilar[$bolum] ?? 0) + 1;
    }

    return $sayilar;
}

/**
 * Resmî Gazete kaynaklı yayındaki haberler, en yeni önce.
 *
 * "Resmi Gazete kaynakli" = ajan haberi Resmi Gazete'nin kendi
 * metninden yazmis (kaynak adresi resmigazete.gov.tr). Baska bir sitenin
 * ayni teblig hakkindaki haberi buraya girmez; o ana sayfada kalir.
 *
 * @return list<array<string,mixed>>
 */
function rg_haberleri(int $limit = 10): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum AND ' . HABER_RG_KOSULU . '
          ORDER BY h.yayin_tarihi DESC
          LIMIT ' . max(1, $limit)
    );
    $ifade->execute(['durum' => HABER_YAYINDA]);

    return $ifade->fetchAll();
}

/**
 * Maddelerin Valentra haberleri: madde adresi => haber satırı.
 *
 * Eslesme adresle: ajanin haberinin kaynak adresi maddenin adresi. "www."
 * farki yok sayiliyor.
 *
 * @param list<array<string,mixed>> $maddeler
 * @return array<string,array<string,mixed>>
 */
function rg_madde_haberleri(array $maddeler): array
{
    if ($maddeler === []) {
        return [];
    }

    $sade = static fn (string $u): string => (string) preg_replace('#^https?://(www\.)?#i', '', $u);

    $haberler = rg_haberleri(200);
    $adreseGore = [];

    foreach ($haberler as $h) {
        $adreseGore[$sade((string) $h['kaynak_url'])] = $h;
    }

    $eslesme = [];

    foreach ($maddeler as $m) {
        $anahtar = $sade((string) $m['url']);

        if (isset($adreseGore[$anahtar])) {
            $eslesme[(string) $m['url']] = $adreseGore[$anahtar];
        }
    }

    return $eslesme;
}
