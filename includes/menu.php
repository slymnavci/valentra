<?php
declare(strict_types=1);

/**
 * Ust menu.
 *
 *   Ana Sayfa · Gundem · Politika · Ekonomi · Dunya · Spor (acilir) ·
 *   Teknoloji · Saglik · Kultur-Sanat · Kose Yazilari · Yemek Tarifleri
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
    $menu = array_map(static fn (array $k): array => [
        'ad'      => $k['ad'],
        'anahtar' => $k['slug'],
        'href'    => kategori_yolu($k['slug']),
        'altlar'  => [],
    ], haber_akis_kategorileri());

    /*
     * Spor acilir menu (22.10.2026, site sahibinin istegi): puan durumu,
     * fikstur ve guncel spor haberleri. Ucu de Spor sayfasinin bolumleri
     * (kategori.php, includes/spor_panel.php); baglantilar o bolumlere
     * gidiyor.
     */
    foreach ($menu as $i => $oge) {
        /*
         * Ekonomi acilir menu (22.10.2026, site sahibinin istegi):
         * piyasalar (BIST, doviz, altin, grafikler), ekonomik
         * gostergeler, borsa sirketleri ve gunluk ekonomi haberleri ayri
         * ayri. Ilk ucu Piyasalar sayfasinin bolumleri.
         */
        if ($oge['anahtar'] === 'ekonomi') {
            $piyasa = piyasalar_yolu();
            $menu[$i]['href']   = null;
            $menu[$i]['altlar'] = [
                ['ad' => 'Piyasalar (BIST, Döviz, Altın)', 'anahtar' => 'piyasalar',        'href' => $piyasa,                          'adet' => 0],
                ['ad' => 'Ekonomik Göstergeler',          'anahtar' => 'ekonomi-gosterge', 'href' => $piyasa . '#gostergeler',         'adet' => 0],
                ['ad' => 'Borsa İstanbul Şirketleri',     'anahtar' => 'ekonomi-borsa',    'href' => $piyasa . '#borsa',               'adet' => 0],
                ['ad' => 'Günlük Ekonomi Haberleri',      'anahtar' => 'ekonomi-haberler', 'href' => (string) $oge['href'],           'adet' => 0],
            ];
        }

        if ($oge['anahtar'] === 'spor') {
            $spor = (string) $oge['href'];
            $menu[$i]['href']   = null;
            $menu[$i]['altlar'] = [
                ['ad' => 'Puan Durumu',           'anahtar' => 'spor-puan',     'href' => $spor . '#puan-durumu',    'adet' => 0],
                ['ad' => 'Fikstür',               'anahtar' => 'spor-fikstur',  'href' => $spor . '#fikstur',        'adet' => 0],
                ['ad' => 'Güncel Spor Haberleri', 'anahtar' => 'spor-haberler', 'href' => $spor . '#spor-haberleri', 'adet' => 0],
            ];
        }
    }

    // Kose yazilari: gunun en onemli konularinin yorumu (yapay zeka ile
    // yaziliyor, onaydan geciyor). Haber basliklarinin sonunda.
    $menu[] = ['ad' => 'Köşe Yazıları', 'anahtar' => 'kose', 'href' => kose_liste_yolu(), 'altlar' => []];

    // Yemek tarifleri menunun EN SONUNDA (site sahibinin istegi):
    // haber basliklarindan ve kose yazilarindan sonra.
    foreach ($menu as $i => $oge) {
        if (in_array($oge['anahtar'], HABER_AKIS_DISI, true)) {
            unset($menu[$i]);
            $menu[] = $oge;
        }
    }

    $menu = array_values($menu);

    return $menu;
}

/** Menu basligi ya da alt ogelerinden biri gecerli sayfa mi? */
function site_menu_aktif(array $oge, string $aktif): bool
{
    if ($aktif === '') {
        return false;
    }

    return $oge['anahtar'] === $aktif
        || in_array($aktif, array_column($oge['altlar'], 'anahtar'), true);
}
