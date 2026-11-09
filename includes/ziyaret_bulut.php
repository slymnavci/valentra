<?php
declare(strict_types=1);

/**
 * Bulut sunucu (veri merkezi) adreslerinden gelen ziyaretler.
 *
 * Site sahibinin sorusu (09.11.2026): "ziyaretcilerin cogu Cin'den veya
 * Singapur'dan gelmis gorunuyor, bir hata olabilir mi?" Gelenler Tencent
 * Cloud (43.128-43.175, 101.32, 124.156, 150.109...), Huawei Cloud
 * (119.8, 124.243, 159.138, 116.204, 94.74...), Alibaba Cloud ve China
 * Mobile bulutundan; her adres tek sayfa, yonlendiren yok, hep
 * "bilgisayar", Uye Ol / Giris Yap sayfalarina sik. Sayfayi gercek bir
 * tarayici motoruyla acip betigi calistiriyorlar, bu yuzden tarayici
 * dogrulamasini (api/gorunum.php) geciyor ve insan sayiliyorlardi.
 *
 * Bir Turkce haber sitesine bu bulutlardan gercek okur gelmesi
 * neredeyse imkansiz: adres bu araliklardaysa ziyaret kaydedilmiyor.
 * Liste tam degil (saglayicilarin butun bloklari degil, trafikte gorulen
 * ve bilinen buyuk bloklari); kacanlari Accept-Language ve saat dilimi
 * denetimi yakaliyor (bkz. ziyaret_bot_mu, api/gorunum.php).
 */

const ZIYARET_BULUT_ARALIKLARI = [
    // Tencent Cloud
    '43.128.0.0/11', '43.160.0.0/12', '42.192.0.0/15', '49.232.0.0/14', '62.234.0.0/16',
    '81.68.0.0/14', '101.32.0.0/14', '106.52.0.0/14', '111.229.0.0/16', '111.230.0.0/16',
    '118.89.0.0/16', '119.28.0.0/15', '122.51.0.0/16', '123.206.0.0/16', '124.156.0.0/16',
    '129.226.0.0/16', '132.232.0.0/16', '139.155.0.0/16', '140.143.0.0/16', '150.109.0.0/16',
    '152.136.0.0/16', '162.62.0.0/16', '170.106.0.0/16', '175.24.0.0/14', '193.112.0.0/16',
    '1.12.0.0/14',
    // Huawei Cloud
    '1.92.0.0/14', '94.74.64.0/18', '101.44.0.0/16', '101.46.0.0/16', '110.238.64.0/18',
    '111.119.192.0/18', '114.119.128.0/19', '116.204.0.0/17', '119.8.0.0/16', '119.12.0.0/15',
    '121.36.0.0/15', '123.60.0.0/16', '124.70.0.0/15', '124.243.128.0/17', '139.9.0.0/16',
    '150.40.0.0/16', '159.138.0.0/16', '188.239.0.0/18', '190.92.192.0/19',
    // Alibaba Cloud
    '8.208.0.0/12', '39.96.0.0/13', '39.104.0.0/14', '47.74.0.0/15', '47.76.0.0/14',
    '47.80.0.0/13', '47.88.0.0/14', '47.92.0.0/14', '47.96.0.0/11', '101.132.0.0/15',
    '106.14.0.0/15', '112.124.0.0/16', '114.55.0.0/16', '116.62.0.0/16', '118.31.0.0/16',
    '118.178.0.0/16', '120.24.0.0/14', '120.76.0.0/14', '121.40.0.0/14', '139.224.0.0/16',
    // China Mobile (bulut ve gecit adresleri)
    '36.128.0.0/10',
];

/** Adres (IPv4) bulut aralıklarından birinde mi? */
function ziyaret_bulut_adresi_mi(string $ip): bool
{
    $sayi = ip2long($ip);

    if ($sayi === false) {
        return false;
    }

    foreach (ZIYARET_BULUT_ARALIKLARI as $aralik) {
        [$ag, $bit] = explode('/', $aralik);
        $maske = $bit === '0' ? 0 : (~0 << (32 - (int) $bit)) & 0xFFFFFFFF;

        if (($sayi & $maske) === (ip2long($ag) & $maske)) {
            return true;
        }
    }

    return false;
}

/**
 * Raporlar için SQL koşulu: satırın adresi bulut aralıklarından birinde.
 * Tek seferlik temizlikte kullanılıyor (bkz. ziyaret_bulut_eskileri_isaretle).
 */
function ziyaret_bulut_sql_kosulu(string $sutun = 'ip'): string
{
    $parcalar = [];

    foreach (ZIYARET_BULUT_ARALIKLARI as $aralik) {
        [$ag, $bit] = explode('/', $aralik);
        $maske = (~0 << (32 - (int) $bit)) & 0xFFFFFFFF;
        $parcalar[] = '(INET_ATON(' . $sutun . ') & ' . $maske . ') = ' . (ip2long($ag) & $maske);
    }

    return '(' . implode(' OR ', $parcalar) . ')';
}

/**
 * Bir kez: önceden kaydedilmiş bulut ziyaretleri "insan değil" işaretlenir;
 * raporlar (ziyaret_insan_kosulu) onları artık saymaz. Silinmiyor.
 */
function ziyaret_bulut_eskileri_isaretle(): void
{
    try {
        if (ayar_oku('ziyaret_bulut_temizlendi') !== '') {
            return;
        }

        ayar_yaz('ziyaret_bulut_temizlendi', date('Y-m-d H:i:s'));
        $n = db()->exec('UPDATE ziyaretler SET insan = 0 WHERE insan = 1 AND ' . ziyaret_bulut_sql_kosulu());
        error_log('[valentra] bulut ziyaretleri isaretlendi: ' . (int) $n);
    } catch (Throwable $e) {
        error_log('[valentra] bulut ziyaret temizligi: ' . $e->getMessage());
    }
}
