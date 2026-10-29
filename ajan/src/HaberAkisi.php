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
        'magazin', 'yemek-tarifleri',
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
        // Sira kontenjan dagitiminin sirasi (bkz. SIRA): sinir dar
        // olursa kalan yer once bu sirayla dagiliyor.
        'gundem'       => 8,
        'spor'         => 8,
        'politika'     => 10,
        'ekonomi'      => 8,
        'saglik'       => 5,
        'kultur-sanat' => 5,
        // Magazin (29.10.2026, ValMagazin): olculu; sanatcilarin
        // aciklamalari, roportajlari, yeni isleri.
        'magazin'      => 5,
        // Tarifler haber degil; surekli. 4'ten 12'ye (22.10.2026, site
        // sahibinin istegi: "yemek tarifi sayisini artiralim"; 7 kaynak).
        'yemek-tarifleri' => 12,
        'dunya'        => 10,
        'teknoloji'    => 6,
    ];

    /**
     * Basliklarin modele gidis sirasi (site sahibinin karari, 23.10.2026:
     * "once gundem maddeleri, sonra spor, politika ve ekonomi; ama saglik,
     * kultur, yemek tarifi de her seferinde toplansin"). Her baslik kendi
     * gruplariyla, digerine karismadan isleniyor; model kotasi ya da sure
     * biterse sonda kalan basliklar bir sonraki calismaya kaliyor. Saglik,
     * kultur-sanat ve yemek tarifleri bu yuzden dunya ve teknolojiden
     * once: her calismada mutlaka islensinler.
     */
    public const SIRA = [
        'gundem', 'spor', 'politika', 'ekonomi',
        'saglik', 'kultur-sanat', 'magazin', 'yemek-tarifleri',
        'dunya', 'teknoloji',
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
        'magazin'      => 'Magazin',
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
