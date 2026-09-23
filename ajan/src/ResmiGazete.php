<?php
declare(strict_types=1);

namespace Valentra\Ajan;

require_once __DIR__ . '/Kodlama.php';

/**
 * Resmî Gazete'nin günlük fihristini okur.
 *
 * NEDEN AYRI BIR OKUYUCU: Resmi Gazete sitenin birincil mevzuat
 * kaynagi. Genel kazima onu ana sayfasindan okuyordu; bu iki sebeple
 * yetmiyordu:
 *
 * 1. Ana sayfa yalnizca BUGUNUN sayisini gosteriyor. GitHub zamanli
 *    calismalarin bir kismini dusuruyor ya da saatlerce geciktiriyor
 *    (22.09'da bes calismanin ikisi hic kosmadi). Gece yarisindan
 *    sonra ilk calisma gecikirse dunun sayisi ana sayfadan cikmis
 *    oluyor ve o gunun mevzuati hic okunmuyor.
 *
 * 2. Mukerrer (ek) sayilar ayri fihristte yayimlaniyor.
 *
 * Bu okuyucu bugunun ve dunun fihristini tarih adresinden dogrudan
 * okuyor: /eskiler/YYYY/AA/YYYYAAGG.htm ve mukerrerler icin
 * YYYYAAGGM1.htm, M2 ... Adres bicimi yillardir degismedi.
 *
 * Donen girdiler Kazima ile ayni bicimde; topla.php onlari diger
 * kaynaklarin girdileri gibi isliyor.
 */
final class ResmiGazete
{
    public const ANA_ADRES = 'https://www.resmigazete.gov.tr';

    /**
     * Mevzuat sayfasi adresi: normal sayi (20260923-4.htm) ya da
     * mukerrer sayi (20260923M1-2.htm), HTML ya da PDF.
     */
    public const MADDE_KALIBI = '#/eskiler/(\d{4})/(\d{2})/(\d{8})(M\d+)?-\d+\.(?:htm|html|pdf)$#i';

    /**
     * Basliginda bunlar gecen maddeler alinmiyor.
     *
     * Universite yonetmelikleri Resmi Gazete'nin en kalabalik kalemi
     * (gunde onlarca olabiliyor) ve vergi okuyucusunu ilgilendirmiyor.
     * Model de onlari elerdi, ama her biri modele giden kontenjandan
     * bir yer yiyordu.
     */
    private const ATLANAN_BASLIKLAR = ['üniversitesi', 'yükseköğretim kurumları'];

    /*
     * Bolum basliklarinin okunur hali — ELLE YAZILDI.
     *
     * mb_strtolower Turkce buyuk harfi yanlis cevirir: "İ" -> "i̇"
     * (noktali i ile ustune bir nokta daha), "I" -> "i" (dogrusu "ı").
     * Olculdu: "TEBLİĞLER" "Tebli̇ğler", "CUMHURBAŞKANI KARARLARI"
     * "Cumhurbaşkani Kararlari" oluyordu. Sabit tablo bu hatayi
     * imkansiz kiliyor.
     */
    private const BOLUM_ADLARI = [
        'KANUNLAR' => 'Kanunlar', 'KANUN' => 'Kanun',
        'CUMHURBAŞKANI KARARLARI' => 'Cumhurbaşkanı Kararları',
        'CUMHURBAŞKANI KARARI' => 'Cumhurbaşkanı Kararı',
        'CUMHURBAŞKANLIĞI KARARNAMELERİ' => 'Cumhurbaşkanlığı Kararnameleri',
        'CUMHURBAŞKANLIĞI GENELGELERİ' => 'Cumhurbaşkanlığı Genelgeleri',
        'YÖNETMELİKLER' => 'Yönetmelikler', 'YÖNETMELİK' => 'Yönetmelik',
        'TEBLİĞLER' => 'Tebliğler', 'TEBLİĞ' => 'Tebliğ',
        'KURUL KARARLARI' => 'Kurul Kararları', 'KURUL KARARI' => 'Kurul Kararı',
        'ATAMA KARARLARI' => 'Atama Kararları', 'GENELGELER' => 'Genelgeler',
        'ANAYASA MAHKEMESİ KARARLARI' => 'Anayasa Mahkemesi Kararları',
        'ANAYASA MAHKEMESİ KARARI' => 'Anayasa Mahkemesi Kararı',
        'YARGITAY KARARLARI' => 'Yargıtay Kararları',
        'DANIŞTAY KARARLARI' => 'Danıştay Kararları',
        'MİLLETLERARASI ANDLAŞMALAR' => 'Milletlerarası Andlaşmalar',
    ];

    /** Aynı günde en fazla kaç mükerrer sayıya bakılır. */
    private const EN_FAZLA_MUKERRER = 3;

    public function __construct(private readonly Indirici $http)
    {
    }

    /**
     * Son $gun günün (bugün dahil) fihristlerindeki maddeler.
     *
     * @return list<array{baslik:string,baglanti:string,ozet:string,tarih:?string,gorsel:string}>
     */
    public function oku(int $gun = 2, ?\DateTimeImmutable $simdi = null): array
    {
        $simdi ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul'));
        $girdiler = [];
        $gorulen  = [];

        for ($i = 0; $i < max(1, $gun); $i++) {
            $tarih = $simdi->modify('-' . $i . ' day');

            foreach ($this->fihristAdresleri($tarih) as $sira => $adres) {
                $html = $this->http->indir($adres);

                // Mukerrer yoksa 404 doner; siradaki mukerrere bakmaya gerek yok.
                if ($html === null) {
                    if ($sira > 0) {
                        break;
                    }

                    continue;
                }

                foreach (self::fihristiCoz(Kodlama::utf8($html), $adres) as $girdi) {
                    if (isset($gorulen[$girdi['baglanti']])) {
                        continue;
                    }

                    $gorulen[$girdi['baglanti']] = true;
                    $girdiler[] = $girdi;
                }
            }
        }

        return $girdiler;
    }

    /**
     * Bir günün fihrist adresleri: önce normal sayı, sonra mükerrerler.
     *
     * @return list<string>
     */
    public function fihristAdresleri(\DateTimeImmutable $tarih): array
    {
        $yol = self::ANA_ADRES . '/eskiler/' . $tarih->format('Y') . '/' . $tarih->format('m')
             . '/' . $tarih->format('Ymd');

        $adresler = [$yol . '.htm'];

        for ($m = 1; $m <= self::EN_FAZLA_MUKERRER; $m++) {
            $adresler[] = $yol . 'M' . $m . '.htm';
        }

        return $adresler;
    }

    /**
     * Fihrist HTML'inden mevzuat maddelerini çıkarır.
     *
     * Bolum basliklari da izleniyor ("YONETMELIKLER", "TEBLIGLER" ...):
     * ozete yaziliyor ki model maddenin turunu bilsin, ilan bolumu de
     * tamamen atlaniyor.
     *
     * @return list<array{baslik:string,baglanti:string,ozet:string,tarih:?string,gorsel:string}>
     */
    public static function fihristiCoz(string $html, string $fihristAdresi): array
    {
        /*
         * Belge sirasiyla hem bolum basliklarini hem baglantilari
         * yakalamak icin tek bir tarama: her eslesme ya bir <a> ya da
         * bir bolum basligi. Konumuna gore siralanip sirayla isleniyor.
         */
        $olaylar = [];

        if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $bag, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($bag as $e) {
                $olaylar[] = ['konum' => $e[0][1], 'tur' => 'a', 'href' => $e[1][0], 'metin' => $e[2][0]];
            }
        }

        $basliklar = array_merge(
            ['YÜRÜTME VE İDARE BÖLÜMÜ', 'YASAMA BÖLÜMÜ', 'YARGI BÖLÜMÜ', 'İLÂN BÖLÜMÜ', 'İLAN BÖLÜMÜ'],
            array_keys(self::BOLUM_ADLARI)
        );

        // Uzun olanlar once: "KANUNLAR" varken "KANUN" ayrica eslesmesin.
        usort($basliklar, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $desen = '#>\s*(' . implode('|', array_map(static fn ($b) => preg_quote($b, '#'), $basliklar)) . ')\s*<#u';

        if (preg_match_all($desen, $html, $bas, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($bas as $e) {
                $olaylar[] = ['konum' => $e[1][1], 'tur' => 'bolum', 'ad' => $e[1][0]];
            }
        }

        usort($olaylar, static fn (array $a, array $b): int => $a['konum'] <=> $b['konum']);

        $girdiler = [];
        $bolum    = '';
        $ustBolum = '';

        foreach ($olaylar as $olay) {
            if ($olay['tur'] === 'bolum') {
                if (str_ends_with($olay['ad'], 'BÖLÜMÜ')) {
                    $ustBolum = $olay['ad'];
                    $bolum    = '';
                } else {
                    $bolum = $olay['ad'];
                }

                continue;
            }

            // Ilan bolumu mevzuat degil: ihale, tebligat, kayip ilanlari.
            if (in_array($ustBolum, ['İLÂN BÖLÜMÜ', 'İLAN BÖLÜMÜ'], true)) {
                continue;
            }

            $adres = self::mutlakAdres(html_entity_decode($olay['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $fihristAdresi);

            if ($adres === null || !preg_match(self::MADDE_KALIBI, (string) parse_url($adres, PHP_URL_PATH), $m)) {
                continue;
            }

            $baslik = self::baslikTemizle($olay['metin']);

            if (mb_strlen($baslik, 'UTF-8') < 10) {
                continue;
            }

            $kucuk = mb_strtolower($baslik, 'UTF-8');

            foreach (self::ATLANAN_BASLIKLAR as $atlanan) {
                if (str_contains($kucuk, $atlanan)) {
                    continue 2;
                }
            }

            $gun = \DateTimeImmutable::createFromFormat('!Ymd', $m[3], new \DateTimeZone('Europe/Istanbul'));

            $girdiler[] = [
                'baslik'   => $baslik,
                'baglanti' => $adres,
                'ozet'     => self::ozetYaz($gun ?: null, $m[4] ?? '', $bolum),
                'tarih'    => $gun ? $gun->format('Y-m-d H:i:s') : null,
                'gorsel'   => '',
            ];
        }

        return $girdiler;
    }

    /**
     * Bir adres Resmî Gazete mevzuat sayfası mı?
     *
     * Site tarafi da ayni kurali kullaniyor (tam metin kutusu).
     */
    public static function maddeMi(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return ($host === 'www.resmigazete.gov.tr' || $host === 'resmigazete.gov.tr')
            && preg_match(self::MADDE_KALIBI, (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    private static function baslikTemizle(string $ham): string
    {
        $metin = html_entity_decode(strip_tags($ham), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $metin = trim((string) preg_replace('/\s+/u', ' ', $metin));

        // Fihristte her madde "–– " ile basliyor.
        return trim((string) preg_replace('/^[\s\-–—]+/u', '', $metin));
    }

    private static function ozetYaz(?\DateTimeImmutable $gun, string $mukerrer, string $bolum): string
    {
        $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz',
                  'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

        $ozet = 'Resmî Gazete';

        if ($gun !== null) {
            $ozet .= ', ' . (int) $gun->format('j') . ' ' . $aylar[(int) $gun->format('n')] . ' ' . $gun->format('Y');
        }

        if ($mukerrer !== '') {
            $ozet .= ' (' . substr($mukerrer, 1) . '. Mükerrer)';
        }

        if ($bolum !== '') {
            $ozet .= ' — ' . (self::BOLUM_ADLARI[$bolum] ?? $bolum);
        }

        return $ozet . '.';
    }

    private static function mutlakAdres(string $href, string $taban): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $sema = (string) parse_url($taban, PHP_URL_SCHEME);
        $host = (string) parse_url($taban, PHP_URL_HOST);

        if (str_starts_with($href, '//')) {
            return $sema . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $sema . '://' . $host . $href;
        }

        $dizin = (string) parse_url($taban, PHP_URL_PATH);
        $dizin = substr($dizin, 0, (int) strrpos($dizin, '/') + 1);

        return $sema . '://' . $host . $dizin . $href;
    }
}
