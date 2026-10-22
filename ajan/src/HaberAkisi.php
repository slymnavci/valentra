<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Haber akisinin konu basliklari ve modele giden aday kontenjani.
 *
 * Ekim 2026'da site vergi/muhasebe yayinindan genel haber ozetlerine
 * dondu. Ajanin konu listesi normalde siteden geliyor (api/kaynaklar.php);
 * buradaki liste site erisilemediginde depodaki semadan kurulan tohum
 * yapilandirmasini ayni basliklara indirmek ve kontenjani paylastirmak
 * icin. Sitedeki karsiligi includes/haberler.php'de
 * HABER_AKIS_KATEGORILERI; biri degisirse oteki de degismeli.
 */
final class HaberAkisi
{
    /** Menu sirasiyla. */
    public const KATEGORILER = [
        'gundem', 'politika', 'ekonomi', 'dunya',
        'spor', 'teknoloji', 'saglik', 'kultur-sanat',
    ];

    /**
     * Her turda basliga dusen aday sayisi.
     *
     * Politika en buyuk pay (site sahibinin karari: once Turkiye
     * siyaseti). Dunya da 3: dunyanin onde gelen haber kuruluslari
     * kaynak listesinde (site sahibinin istegi, 22.10.2026). Ekonomi
     * kaynaklari sayica cok (eski ekonomi listesi acik kaldi); pay
     * olmasa kontenjanin cogunu onlar yerdi. Bir baslikta aday kalmazsa
     * payi otekilere geciyor.
     *
     * @var array<string,int>
     */
    public const PAYLAR = [
        'politika'     => 3,
        'gundem'       => 2,
        'ekonomi'      => 2,
        'dunya'        => 3,
        'spor'         => 2,
        'teknoloji'    => 1,
        'saglik'       => 1,
        'kultur-sanat' => 1,
    ];

    /**
     * Adayin kontenjandaki basligi: kaynagin kategori ipucu.
     *
     * Ipucu yalnizca kontenjan icin; haberin asil basligini model
     * seciyor (bir spor beslemesinden gelen haber ekonomi de olabilir).
     * Ipucu bos ya da bilinmiyorsa ekonomi: kategori sutunu eklenmeden
     * once acik olan kaynaklarin hepsi ekonomi kaynagi.
     *
     * @param array<string,mixed> $kaynak
     */
    public static function kova(array $kaynak): string
    {
        $ipucu = trim((string) ($kaynak['kategori'] ?? ''));

        return isset(self::PAYLAR[$ipucu]) ? $ipucu : 'ekonomi';
    }
}
