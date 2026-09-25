<?php
declare(strict_types=1);

/**
 * Ana sayfa: "Bugün bilmeniz gerekenler" ve kısayollar.
 *
 * Maddeler model yazisi DEGIL, sitenin kendi verisinden cikan olgular:
 * yaklasan son gunler (vergi takvimi), gunun Resmi Gazete'si, son iki
 * gunde yayimlanan onemli duzenleme ve yeni onaylanan pratik bilgi.
 * Hicbiri yoksa liste basilmiyor; kisayollar her zaman duruyor.
 */

require_once __DIR__ . '/takvim.php';
require_once __DIR__ . '/resmi_gazete.php';

const BUGUN_EN_FAZLA = 4;

/**
 * @param list<int> $haricHaberler mansette zaten gorunen haberler
 * @return list<array{etiket:string,metin:string,href:string,vurgu:bool}>
 */
function bugun_bilmeniz_gerekenler(array $haricHaberler = []): array
{
    $maddeler = [];

    // 1) Bir hafta icindeki son gunler; en fazla iki.
    foreach (takvim_yaklasanlar(7, 2) as $olay) {
        $kalan = (int) $olay['kalan'];
        $ne    = match (true) {
            $kalan === 0 => 'bugün son gün',
            $kalan === 1 => 'yarın son gün',
            default      => $kalan . ' gün kaldı',
        };

        $maddeler[] = [
            'etiket' => 'Son gün',
            'metin'  => $olay['baslik'] . ' — ' . (int) substr($olay['tarih'], 8, 2) . ' '
                      . takvim_ay_adi((int) substr($olay['tarih'], 5, 2)) . ', ' . $ne,
            'href'   => takvim_yolu(),
            'vurgu'  => $kalan <= 1,
        ];
    }

    // 2) Bugunun Resmi Gazete'si (fihrist okunduysa).
    try {
        $bugun = (new DateTimeImmutable('now', rg_saat_dilimi()))->format('Y-m-d');

        if ((rg_gunler(1)[0] ?? '') === $bugun) {
            $sayi = count(rg_gun_maddeleri($bugun));

            if ($sayi > 0) {
                $maddeler[] = [
                    'etiket' => 'Resmî Gazete',
                    'metin'  => 'Bugünkü sayıda ' . $sayi . ' madde yayımlandı',
                    'href'   => rg_yolu($bugun),
                    'vurgu'  => false,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('[valentra] bugun: resmi gazete okunamadi: ' . $e->getMessage());
    }

    // 3) Son 48 saatte yayimlanan onemli duzenleme (mansette degilse).
    try {
        foreach (haber_onemli_duzenlemeler(8) as $haber) {
            if (in_array((int) $haber['id'], $haricHaberler, true)) {
                continue;
            }

            if (strtotime((string) $haber['yayin_tarihi']) < time() - 48 * 3600) {
                continue;
            }

            $maddeler[] = [
                'etiket' => haber_belge_turu($haber) ?? 'Düzenleme',
                'metin'  => (string) $haber['baslik'],
                'href'   => haber_yolu((string) $haber['slug']),
                'vurgu'  => false,
            ];
            break;
        }
    } catch (PDOException $e) {
        error_log('[valentra] bugun: duzenlemeler okunamadi: ' . $e->getMessage());
    }

    // 4) Son bir haftada degeri onaylanan pratik bilgi.
    try {
        $pratik = db()->query(
            'SELECT anahtar, baslik, donem FROM pratik_bilgiler
              WHERE aktif = 1 AND deger IS NOT NULL
                AND onay_tarihi >= NOW() - INTERVAL 7 DAY
              ORDER BY onay_tarihi DESC LIMIT 1'
        )->fetch();

        if ($pratik) {
            $maddeler[] = [
                'etiket' => 'Güncellendi',
                'metin'  => $pratik['baslik'] . ((string) $pratik['donem'] !== '' ? ' (' . $pratik['donem'] . ')' : ''),
                'href'   => pratik_bilgi_yolu((string) $pratik['anahtar']),
                'vurgu'  => false,
            ];
        }
    } catch (PDOException $e) {
        error_log('[valentra] bugun: pratik bilgi okunamadi: ' . $e->getMessage());
    }

    return array_slice($maddeler, 0, BUGUN_EN_FAZLA);
}
