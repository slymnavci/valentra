<?php
declare(strict_types=1);

/**
 * KAYNAK DURUMU: tarama araligi ve hata bekletmesi (13.11.2026).
 *
 * Site sahibinin istegi: "tarama araligi ayarlanabilir olsun; hata
 * durumunda bekleme uygula; basarisiz olan adresi calisiyor kabul etme".
 *
 *   - aralik_dk: kaynak en erken kac dakikada bir taranir (0: ajanin her
 *     calismasinda). Panelden (Kaynaklar) kaynak basina ayarlanir.
 *   - Ajan her taramadan sonra kaynaklarin durumunu bildirir
 *     (api/kaynak-durum.php): tamam / bos (adrese ulasildi, yeni girdi yok)
 *     / hata (adres alinamadi ya da besleme cozulemedi).
 *   - Hata: sayac artar; kaynak 15, 30, 60, 120, 240, 360 dakika bekletilir
 *     (sunucuyu zorlamamak icin). Ilk basarili okumada sayac sifirlanir.
 *   - Bekleyen kaynak ajana verilmez (kaynak_durum_kosulu); panelde "son
 *     hata" ve "yeniden denenecegi an" gorunur.
 */

/** Ilk hatada bekleme (dakika); her hatada ikiye katlanir. */
const KAYNAK_HATA_ILK_BEKLEME_DK = 15;

/** Bekleme en fazla bu kadar (dakika). */
const KAYNAK_HATA_EN_FAZLA_DK = 360;

/** Ajan zamanlamasindaki kayma payi (dakika): 15 dk'lik aralik 15. dakikada kacmasin. */
const KAYNAK_ARALIK_PAYI_DK = 2;

/** Sutunlar veritabaninda var mi. */
function kaynak_durum_hazir(): bool
{
    static $hazir = null;

    if ($hazir === null) {
        try {
            $hazir = (bool) db()->query("SHOW COLUMNS FROM kaynaklar LIKE 'sonraki_deneme'")->fetch();
        } catch (Throwable $e) {
            $hazir = false;
        }
    }

    return $hazir;
}

/** Ajana verilecek kaynaklarin ek kosulu (SQL, basinda AND). */
function kaynak_durum_kosulu(): string
{
    return kaynak_durum_hazir() ? ' AND (sonraki_deneme IS NULL OR sonraki_deneme <= NOW())' : '';
}

/** N. ust uste hatadan sonraki bekleme (dakika): 15, 30, 60 ... en fazla 360. */
function kaynak_hata_beklemesi(int $hataSayisi): int
{
    $hataSayisi = max(1, min(12, $hataSayisi));

    return (int) min(KAYNAK_HATA_EN_FAZLA_DK, KAYNAK_HATA_ILK_BEKLEME_DK * (2 ** ($hataSayisi - 1)));
}

/**
 * Bir kaynagin tarama sonucunu yazar.
 *
 * @param string $durum 'tamam' | 'bos' | 'hata'
 * @return array{hata_sayisi:int,bekleme_dk:int}|null  kaynak yoksa null
 */
function kaynak_durum_yaz(int $id, string $durum, string $neden = ''): ?array
{
    if ($id <= 0 || !kaynak_durum_hazir() || !in_array($durum, ['tamam', 'bos', 'hata'], true)) {
        return null;
    }

    $sorgu = db()->prepare('SELECT aralik_dk, hata_sayisi FROM kaynaklar WHERE id = :id');
    $sorgu->execute(['id' => $id]);
    $k = $sorgu->fetch();

    if ($k === false) {
        return null;
    }

    if ($durum === 'hata') {
        $sayi    = (int) $k['hata_sayisi'] + 1;
        $bekleme = max(kaynak_hata_beklemesi($sayi), (int) $k['aralik_dk']);

        db()->prepare(
            'UPDATE kaynaklar
                SET son_tarama = NOW(), hata_sayisi = :s, son_hata = :n,
                    sonraki_deneme = NOW() + INTERVAL :dk MINUTE
              WHERE id = :id'
        )->execute(['s' => min(65000, $sayi), 'n' => mb_substr(trim($neden), 0, 300, 'UTF-8'), 'dk' => $bekleme, 'id' => $id]);

        return ['hata_sayisi' => $sayi, 'bekleme_dk' => $bekleme];
    }

    // Adrese ulasildi (yeni girdi olsun olmasin): sayac sifir, aralik kadar bekle.
    $bekleme = max(0, (int) $k['aralik_dk'] - KAYNAK_ARALIK_PAYI_DK);

    db()->prepare(
        'UPDATE kaynaklar
            SET son_tarama = NOW(), son_basari = NOW(), hata_sayisi = 0, son_hata = \'\',
                sonraki_deneme = IF(:dk1 > 0, NOW() + INTERVAL :dk2 MINUTE, NULL)
          WHERE id = :id'
    )->execute(['dk1' => $bekleme, 'dk2' => $bekleme, 'id' => $id]);

    return ['hata_sayisi' => 0, 'bekleme_dk' => $bekleme];
}

/** Editor kaynagi hemen yeniden denetmek isterse beklemeyi kaldirir. */
function kaynak_bekleme_kaldir(int $id): void
{
    if (kaynak_durum_hazir()) {
        db()->prepare('UPDATE kaynaklar SET sonraki_deneme = NULL, hata_sayisi = 0 WHERE id = :id')->execute(['id' => $id]);
    }
}

/** Kaynagin panelde gorunen durumu. */
function kaynak_durum_ozeti(array $k): array
{
    $hata    = (int) ($k['hata_sayisi'] ?? 0);
    $sonraki = strtotime((string) ($k['sonraki_deneme'] ?? '')) ?: 0;

    if ($hata > 0) {
        return [
            'sinif' => 'hata',
            'yazi'  => $hata . ' kez üst üste alınamadı' . ($sonraki > time() ? '; ' . date('d.m H:i', $sonraki) . '\'de yeniden denenecek' : ''),
            'neden' => (string) ($k['son_hata'] ?? ''),
        ];
    }

    if (($k['son_basari'] ?? null) !== null) {
        return ['sinif' => 'tamam', 'yazi' => 'çalışıyor (son okuma ' . date('d.m H:i', (int) strtotime((string) $k['son_basari'])) . ')', 'neden' => ''];
    }

    return ['sinif' => 'bilinmiyor', 'yazi' => 'henüz taranmadı', 'neden' => ''];
}
