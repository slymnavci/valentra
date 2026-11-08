<?php
declare(strict_types=1);

/**
 * Günün Kelimesi: gündemden seçilen kelimeyi tahmin oyunu.
 *
 * Site sahibinin istegi (08.11.2026, dis denetim raporunun "ValGames"
 * onerisinden): okur her gun geri gelsin diye gunluk kelime bulmacasi.
 * Kelime her gun o gunun gundeminden, en onemli haberlerin ETIKETLERINDEN
 * (konu terimleri: altin, borsa, derbi...) seciliyor; oyun bitince o
 * habere baglanti veriliyor. Uygun etiket yoksa yedek listeden.
 *
 * Cevap sayfanin kaynaginda yok: tahminler api/kelime.php'de sunucuda
 * karsilastiriliyor. Secim ayarlar tablosunda gun boyu sabit
 * ('gunun_kelimesi'); son 60 gunun kelimeleri tekrar secilmiyor.
 */

require_once __DIR__ . '/ayarlar.php';

const KELIME_UZUNLUK = 5;
const KELIME_HAK     = 6;

/** Uygun etiket bulunamazsa: gündemde sık geçen 5 harfli kelimeler. */
const KELIME_YEDEK = [
    'ALTIN', 'BORSA', 'DERBİ', 'SEÇİM', 'VERGİ', 'BÜTÇE', 'TARIM', 'KANUN', 'DOLAR', 'İHALE',
    'GÜMÜŞ', 'KUPON', 'KADRO', 'HASAT', 'KONUT', 'TATİL', 'ROKET', 'SAHNE', 'HAKEM', 'ZİRVE',
    'BARIŞ', 'GOLCÜ', 'KREDİ', 'BANKA', 'PAZAR', 'FİYAT', 'ÜCRET', 'SEFER', 'YAĞIŞ', 'KURUL',
    'DÜNYA', 'EKRAN', 'MÜZİK', 'ROMAN', 'LİMAN', 'KÖPRÜ', 'TÜNEL', 'METRO',
];

function kelime_oyunu_yolu(): string
{
    return temiz_adres_acik() ? '/gunun-kelimesi' : '/gunun-kelimesi.php';
}

/** Türkçe büyük harf ("i" -> "İ", "ı" -> "I"). */
function kelime_buyuk(string $metin): string
{
    return mb_strtoupper(strtr($metin, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
}

function kelime_gecerli_mi(string $kelime): bool
{
    return preg_match('/^[A-ZÇĞİÖŞÜ]{' . KELIME_UZUNLUK . '}$/u', $kelime) === 1;
}

/**
 * Bugünün kelimesi; yoksa seçip kaydeder.
 *
 * @return array{gun:string,kelime:string,haber_baslik:string,haber_slug:string}
 */
function kelime_bugun(): array
{
    $bugun = date('Y-m-d');
    $kayit = json_decode(ayar_oku('gunun_kelimesi'), true);

    if (is_array($kayit) && ($kayit['gun'] ?? '') === $bugun && kelime_gecerli_mi((string) ($kayit['kelime'] ?? ''))) {
        return $kayit + ['haber_baslik' => '', 'haber_slug' => ''];
    }

    $gecmis = json_decode(ayar_oku('gunun_kelimesi_gecmis'), true);
    $gecmis = is_array($gecmis) ? $gecmis : [];
    $kullanilan = array_column(array_filter($gecmis, static fn ($g): bool =>
        is_array($g) && strtotime((string) ($g['gun'] ?? '')) >= strtotime('-60 days')), 'kelime');

    $secim = ['gun' => $bugun, 'kelime' => '', 'haber_baslik' => '', 'haber_slug' => ''];

    try {
        $haberler = db()->query(
            "SELECT h.baslik, h.slug, h.etiketler
               FROM haberler h
              WHERE h.durum = 'yayinda' AND h.etiketler <> ''
                AND h.yayin_tarihi >= NOW() - INTERVAL 36 HOUR
              ORDER BY h.onem DESC, h.yayin_tarihi DESC
              LIMIT 120"
        )->fetchAll();
    } catch (Throwable $e) {
        $haberler = [];
    }

    foreach ($haberler as $h) {
        foreach (etiketleri_coz((string) $h['etiketler']) as $etiket) {
            $aday = kelime_buyuk(trim($etiket));

            if (kelime_gecerli_mi($aday) && !in_array($aday, $kullanilan, true)) {
                $secim['kelime']       = $aday;
                $secim['haber_baslik'] = (string) $h['baslik'];
                $secim['haber_slug']   = (string) $h['slug'];
                break 2;
            }
        }
    }

    if ($secim['kelime'] === '') {
        $yedek = array_values(array_diff(KELIME_YEDEK, $kullanilan)) ?: KELIME_YEDEK;
        $secim['kelime'] = $yedek[crc32($bugun) % count($yedek)];
    }

    $gecmis[] = ['gun' => $bugun, 'kelime' => $secim['kelime']];
    ayar_yaz('gunun_kelimesi_gecmis', (string) json_encode(array_slice($gecmis, -90), JSON_UNESCAPED_UNICODE));
    ayar_yaz('gunun_kelimesi', (string) json_encode($secim, JSON_UNESCAPED_UNICODE));

    return $secim;
}

/**
 * Tahmini cevapla karşılaştırır: her harf 2 (doğru yer), 1 (kelimede var,
 * başka yerde) ya da 0 (yok). Tekrarlanan harfler cevaptaki sayısı kadar
 * işaretleniyor (önce doğru yerler).
 *
 * @return list<int>
 */
function kelime_karsilastir(string $tahmin, string $cevap): array
{
    $t = mb_str_split($tahmin, 1, 'UTF-8');
    $c = mb_str_split($cevap, 1, 'UTF-8');
    $sonuc = array_fill(0, count($t), 0);
    $kalan = [];

    foreach ($t as $i => $harf) {
        if (($c[$i] ?? null) === $harf) {
            $sonuc[$i] = 2;
        } else {
            $kalan[$c[$i] ?? ''] = ($kalan[$c[$i] ?? ''] ?? 0) + 1;
        }
    }

    foreach ($t as $i => $harf) {
        if ($sonuc[$i] === 0 && ($kalan[$harf] ?? 0) > 0) {
            $sonuc[$i] = 1;
            $kalan[$harf]--;
        }
    }

    return $sonuc;
}
