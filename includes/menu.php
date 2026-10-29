<?php
declare(strict_types=1);

/**
 * Ust menu.
 *
 *   Ana Sayfa · Gundem · Politika · ValPapel · Dunya · ValSpor ·
 *   Teknoloji · Saglik · Kose Yazilari · ValMagazin
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
     * ValMagazin'in basliklari, menunun sonunda. Gazeteler dogrudan on
     * sayfaya gidiyor; basliklari sayfanin icindeki sekmelerde.
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
 * Gazetenin menu ogesi: dogrudan gazetenin on sayfasina gidiyor.
 *
 * Acilir menu YOK (site sahibinin istegi, 29.10.2026: "ValPapel'e
 * bastigimizda asagiya acilan menu olmasin; once sayfaya girelim, sayfanin
 * icinde kendi menuleri olsun"). Basliklar gazetenin sayfasindaki
 * sekmelerde (gazete_ust_html).
 *
 * @param list<string> $onekler Bu gazeteye sayilan sayfa anahtarlari: haber
 *        sayfasinda kategori ('ekonomi'), piyasa sayfalarinda 'ekonomi-doviz',
 *        burc sayfalarinda 'burc-koc'. Tam ya da "onek-" ile baslayan.
 */
function gazete_menu_ogesi(string $gazete, array $onekler): array
{
    return [
        'ad'      => gazete_bul($gazete)['ad'],
        'gazete'  => $gazete,
        'anahtar' => $gazete,
        'href'    => gazete_yolu($gazete),
        'altlar'  => [],
        'onekler' => array_merge([$gazete], $onekler),
    ];
}

/**
 * Menu ogesinin yazisi. Gazeteler sayfadaki kunyeleri gibi iki renkli
 * (site sahibinin istegi, 29.10.2026: "ana menude ValPapel yazarken Papel
 * yesil yazilsin; spor ve magazin de oyle"): "Val" menunun renginde,
 * ikinci yari gazetenin renginde.
 */
function site_menu_adi_html(array $oge): string
{
    $g = isset($oge['gazete']) ? gazete_bul((string) $oge['gazete']) : null;

    if ($g === null) {
        return e((string) $oge['ad']);
    }

    return '<span class="menu-gazete gazete-' . e((string) $oge['gazete']) . '">'
         . e($g['ust']) . '<span class="alt">' . e($g['alt']) . '</span></span>';
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
