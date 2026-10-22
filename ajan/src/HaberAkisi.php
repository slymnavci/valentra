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
        'yemek-tarifleri',
    ];

    /**
     * Bir calismada basliga dusen aday sayisi.
     *
     * 22.10.2026: site sahibi "her basligi ayri ayri toplayalim, butun
     * haberleri cekelim" dedi. Kontenjan 25'ten 60'a cikti ve her baslik
     * kendi payini aliyor; adaylar da baslik baslik (SIRA) modele gidiyor.
     * Politika ve dunya en buyuk pay (once Turkiye siyaseti; dunyanin
     * onde gelen haber kuruluslari). Bir baslikta aday kalmazsa payi
     * otekilere geciyor.
     *
     * @var array<string,int>
     */
    public const PAYLAR = [
        'politika'     => 10,
        'gundem'       => 8,
        'ekonomi'      => 8,
        'dunya'        => 10,
        'spor'         => 8,
        'teknoloji'    => 6,
        'saglik'       => 5,
        'kultur-sanat' => 5,
        // Tarifler haber degil; surekli. 4'ten 12'ye (22.10.2026, site
        // sahibinin istegi: "yemek tarifi sayisini artiralim"; 7 kaynak).
        'yemek-tarifleri' => 12,
    ];

    /**
     * Basliklarin modele gidis sirasi (site sahibinin istegi, 22.10.2026):
     * spor, politika, yabanci haber kuruluslari (dunya), sanat,
     * teknoloji, sonra digerleri. Her baslik kendi gruplariyla, digerine
     * karismadan isleniyor; model kotasi biterse sonda kalan basliklar
     * bir sonraki calismaya kaliyor.
     */
    public const SIRA = [
        'spor', 'politika', 'dunya', 'kultur-sanat',
        'teknoloji', 'gundem', 'saglik', 'ekonomi',
        // En sonda: kota biterse ilk tarifler kalsin, haberler degil.
        'yemek-tarifleri',
    ];

    /** Gunluge yazilacak baslik adi. */
    public const ADLAR = [
        'gundem'       => 'Gündem',
        'politika'     => 'Politika',
        'ekonomi'      => 'Ekonomi',
        'dunya'        => 'Dünya',
        'spor'         => 'Spor',
        'teknoloji'    => 'Teknoloji',
        'saglik'       => 'Sağlık',
        'kultur-sanat' => 'Kültür-Sanat',
        'yemek-tarifleri' => 'Yemek Tarifleri',
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
