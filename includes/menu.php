<?php
declare(strict_types=1);

/**
 * Ust menu.
 *
 *   Ana Sayfa · Gundem · Politika · ValPapel (acilir) · Dunya ·
 *   ValSpor (acilir) · Teknoloji · Saglik · Kose Yazilari ·
 *   ValMagazin (acilir)
 *
 * Ekim 2026'da site vergi/muhasebe yayinindan genel haber ozetlerine
 * dondu (site sahibinin karari). Eski menu (Vergi ve Mevzuat, Muhasebe
 * ve TFRS, Rehberler, Egitim, Araclar) GIZLENDI, silinmedi: sayfalari
 * acik, adresleri calisiyor, yalnizca menude yoklar. Eski menu kodu
 * git gecmisinde (includes/menu.php, Ekim 2026 oncesi).
 *
 * Basliklar HABER_AKIS_KATEGORILERI sirasiyla, adlari kategori
 * tablosundan. Haberi henuz olmayan baslik da menude: menu her gun
 * degismesin, okuyucu yerini ezberlesin.
 */

/**
 * @return list<array{ad:string,anahtar:string,href:?string,
 *                    altlar:list<array{ad:string,anahtar:string,href:string,adet:int}>}>
 */
function site_menusu(): array
{
    require_once __DIR__ . '/gazeteler.php';

    /*
     * ValPapel, ValSpor, ValMagazin (29.10.2026, site sahibinin istegi; bkz.
     * includes/gazeteler.php). Ekonomi'nin yerinde ValPapel, Spor'un
     * yerinde ValSpor; Kultur-Sanat, Burclar ve Yemek Tarifleri
     * ValMagazin'in basliklari, o menunun sonunda. Acilir menude gazetenin
     * on sayfasi ve basliklari.
     *
     * Onceki acilir menuler (22-29.10.2026): Ekonomi'de piyasa sayfalari,
     * Spor'da puan durumu ve fikstur, Burclar'da 12 burc. Hepsi gazetelerin
     * basliklarinda duruyor.
     */
    $menu = [];

    foreach (haber_akis_kategorileri() as $k) {
        $slug = (string) $k['slug'];

        if ($slug === 'ekonomi') {
            $menu[] = gazete_menu_ogesi('valpapel', ['ekonomi', 'piyasalar']);
        } elseif ($slug === 'spor') {
            $menu[] = gazete_menu_ogesi('valspor', ['spor']);
        } elseif (!isset(KATEGORI_GAZETESI[$slug])) {
            $menu[] = ['ad' => $k['ad'], 'anahtar' => $slug, 'href' => kategori_yolu($slug), 'altlar' => []];
        }
    }

    // Kose yazilari: gunun en onemli konularinin yorumu (yapay zeka ile
    // yaziliyor, onaydan geciyor). Haber basliklarinin sonunda.
    $menu[] = ['ad' => 'Köşe Yazıları', 'anahtar' => 'kose', 'href' => kose_liste_yolu(), 'altlar' => []];

    $menu[] = gazete_menu_ogesi('valmagazin', ['kultur-sanat', 'magazin', 'yemek-tarifleri', 'burc', 'burclar']);

    return $menu;
}

/**
 * Gazetenin acilir menusu: on sayfa ve basliklari.
 *
 * @param list<string> $onekler Bu gazeteye sayilan sayfa anahtarlari: haber
 *        sayfasinda kategori ('ekonomi'), piyasa sayfalarinda 'ekonomi-doviz',
 *        burc sayfalarinda 'burc-koc'. Tam ya da "onek-" ile baslayan.
 */
function gazete_menu_ogesi(string $gazete, array $onekler): array
{
    $g      = gazete_bul($gazete);
    $altlar = [['ad' => $g['ad'] . ' Ön Sayfa', 'anahtar' => $gazete, 'href' => gazete_yolu($gazete), 'adet' => 0]];

    foreach ($g['bolumler'] as $bolum => $b) {
        $altlar[] = [
            'ad'      => $b['ad'],
            'anahtar' => gazete_bolum_anahtari($gazete, $bolum),
            'href'    => gazete_bolum_yolu($gazete, $bolum),
            'adet'    => 0,
        ];
    }

    return [
        'ad'      => $g['ad'],
        'anahtar' => $gazete,
        'href'    => null,
        'altlar'  => $altlar,
        'onekler' => array_merge([$gazete], $onekler),
    ];
}

/** Menu basligi ya da alt ogelerinden biri gecerli sayfa mi? */
function site_menu_aktif(array $oge, string $aktif): bool
{
    if ($aktif === '') {
        return false;
    }

    foreach ($oge['onekler'] ?? [] as $onek) {
        if ($aktif === $onek || str_starts_with($aktif, $onek . '-')) {
            return true;
        }
    }

    return $oge['anahtar'] === $aktif
        || in_array($aktif, array_column($oge['altlar'], 'anahtar'), true);
}
