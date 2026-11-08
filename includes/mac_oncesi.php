<?php
declare(strict_types=1);

/**
 * Maçı bitmiş "maç öncesi" haberleri ana sayfadan düşürür.
 *
 * Site sahibinin istegi (08.11.2026): "ana sayfada 'Trabzonspor'un ilk
 * 11'i belli oldu' haberi var; mac zaten bittiyse bu haberi ana sayfadan
 * kaldiralim." Ilk 11, muhtemel kadro, "hangi kanalda / saat kacta" gibi
 * haberlerin degeri mac baslayana kadar; mac bitince okuru yaniltiyor.
 *
 * Haber silinmiyor ve yayindan kalkmiyor: yalnizca ana akista (ana sayfa,
 * kaydirak) gorunmuyor; konu sayfasinda ve adresinde duruyor.
 *
 * Mac verisi canli skor bandiyla ayni: BUYUK_MAC_LIGLER (Super Lig,
 * Sampiyonlar, Avrupa ve Konferans Ligi), bugun ve dun, ESPN onbellegi
 * (30 dakika; bkz. buyuk_maclar). ESPN kapaliysa hicbir haber dusmuyor.
 */

require_once __DIR__ . '/buyuk_mac.php';

/** Maç öncesi haber başlığı: ilk 11, muhtemel kadro, yayın saati/kanalı. */
const MAC_ONCESI_KALIBI = '/\bilk\s*(?:11|on\s*bir)\b|\bmuhtemel\s*(?:11|kadro|ilk)|\b11\'?(?:i|ı|leri|ları)?\s+belli\s+oldu|\bkadros[iuı]\s+belli\s+oldu'
    . '|\bhangi\s+kanalda|\bsaat\s+ka[cç]ta|\bcanl[ıi]\s+izle|\bşifresiz\b|\bma[cç]\s+[öo]n[üu]\b|\bmaç[ıi]\s+ne\s+zaman\b/iu';

/** Takım adında ayırt edici olmayan kelimeler. */
const MAC_ONCESI_GENEL_KELIMELER = ['istanbul', 'kulubu', 'spor', 'futbol', 'genclik', 'belediyespor', 'fk', 'sk', 'as'];

/** Başlık maç öncesi haber mi? */
function mac_oncesi_basligi_mi(string $baslik): bool
{
    return preg_match(MAC_ONCESI_KALIBI, $baslik) === 1;
}

/** Türkçe harfleri sadeleştirip küçültür ("Beşiktaş" → "besiktas"). */
function mac_oncesi_katla(string $metin): string
{
    $metin = mb_strtolower(strtr($metin, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

    return strtr($metin, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i']);
}

/**
 * Takımın başlıkta aranacak anahtarı: en uzun ayırt edici kelime
 * ("İstanbul Başakşehir" → "basaksehir", "Trabzonspor" → "trabzonspor").
 */
function mac_oncesi_takim_anahtari(string $ad): string
{
    $en = '';

    foreach (preg_split('/[^a-z0-9]+/', mac_oncesi_katla(futbol_kisa_takim($ad))) ?: [] as $kelime) {
        if (strlen($kelime) >= 4 && !in_array($kelime, MAC_ONCESI_GENEL_KELIMELER, true) && strlen($kelime) > strlen($en)) {
            $en = $kelime;
        }
    }

    return $en;
}

/**
 * Bugün ve dün biten maçlar: [takim anahtari => en son biten macin baslama zamani].
 *
 * @return array<string,int>
 */
function mac_oncesi_biten_maclar(): array
{
    if (!futbol_api_acik()) {
        return [];
    }

    $istanbul = new DateTimeZone('Europe/Istanbul');
    $gunler   = [
        (new DateTimeImmutable('now', $istanbul))->format('Ymd'),
        (new DateTimeImmutable('-1 day', $istanbul))->format('Ymd'),
    ];

    $biten = [];

    foreach (BUYUK_MAC_LIGLER as $lig) {
        foreach ($gunler as $gun) {
            $kayit = futbol_gun_maclari($lig, $gun, BUYUK_MAC_FIKSTUR);

            foreach ($kayit['veri']['maclar'] ?? [] as $m) {
                if (!in_array($m['durum'] ?? '', FUTBOL_BITTI, true)) {
                    continue;
                }

                foreach ([(string) ($m['ev'] ?? ''), (string) ($m['dep'] ?? '')] as $takim) {
                    $anahtar = mac_oncesi_takim_anahtari($takim);

                    if ($anahtar !== '') {
                        $biten[$anahtar] = max($biten[$anahtar] ?? 0, (int) ($m['zaman'] ?? 0));
                    }
                }
            }
        }
    }

    return $biten;
}

/**
 * Ana akıştan düşecek haberler: maç öncesi başlık + o takımın maçı bitmiş
 * + haber o maçtan önceki 36 saatte yayımlanmış (eski bir maçın değil,
 * bu maçın öncesi).
 *
 * @return list<int>
 */
function mac_oncesi_bayat_idler(): array
{
    static $idler = null;

    if ($idler !== null) {
        return $idler;
    }

    $idler = [];

    try {
        $biten = mac_oncesi_biten_maclar();

        if ($biten === []) {
            return $idler;
        }

        $satirlar = db()->query(
            "SELECT id, baslik, UNIX_TIMESTAMP(COALESCE(kaynak_tarihi, olusturuldu)) AS zaman
               FROM haberler
              WHERE durum = " . db()->quote(HABER_YAYINDA) . "
                AND olusturuldu >= NOW() - INTERVAL 3 DAY"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[valentra] mac oncesi suzgeci: ' . $e->getMessage());

        return $idler;
    }

    foreach ($satirlar as $s) {
        $baslik = (string) $s['baslik'];

        if (!mac_oncesi_basligi_mi($baslik)) {
            continue;
        }

        $katli = mac_oncesi_katla($baslik);
        $zaman = (int) $s['zaman'];

        foreach ($biten as $anahtar => $basladi) {
            if (str_contains($katli, $anahtar) && $zaman <= $basladi + 3 * 3600 && $zaman >= $basladi - 36 * 3600) {
                $idler[] = (int) $s['id'];
                break;
            }
        }
    }

    return $idler;
}
