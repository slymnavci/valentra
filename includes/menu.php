<?php
declare(strict_types=1);

/**
 * Ust menu.
 *
 *   Ana Sayfa · Gundem · Politika · Ekonomi · Dunya · Spor ·
 *   Teknoloji · Saglik · Kultur-Sanat
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
    return array_map(static fn (array $k): array => [
        'ad'      => $k['ad'],
        'anahtar' => $k['slug'],
        'href'    => kategori_yolu($k['slug']),
        'altlar'  => [],
    ], haber_akis_kategorileri());
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
