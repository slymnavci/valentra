<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * İndirilen içeriği UTF-8'e ve PDF'i düz metne çevirir.
 *
 * NEDEN VAR: ajan sayfalari ham bayt olarak indirip etiketleri
 * soyuyordu; ne karakter kodlamasina ne de icerigin PDF olup
 * olmadigina bakiyordu. Iki gercek kayip bundan cikti:
 *
 * 1. PDF. Resmi Gazete'de ekli tablolu Cumhurbaskani Kararlari ve
 *    bazi tebligler PDF yayimlaniyor. PDF'in etiket soyulmus hali
 *    anlamsiz bayt yigini; model "sayfa metni alinamadi" deyip
 *    eliyordu. Tam da okuyucunun aradigi mevzuat buydu.
 *
 * 2. Windows-1254. Eski Turkce kamu sayfalarinin bir kismi UTF-8
 *    degil; ğ, ş, ı, İ bozuk geliyordu ("DesteÄi").
 */
final class Kodlama
{
    /** İçerik bir PDF mi? (Sunucunun ne dediğine değil baytlara bakılır.) */
    public static function pdfMi(string $govde): bool
    {
        return str_starts_with(ltrim(substr($govde, 0, 1024)), '%PDF-');
    }

    /**
     * Metni UTF-8'e getirir.
     *
     * Gecerli UTF-8 ise dokunulmuyor. Degilse Windows-1254 varsayiliyor:
     * ajanin okudugu UTF-8 olmayan sayfalarin neredeyse tamami Turkce
     * kamu siteleri ve bu kodlamayla yazilmis.
     */
    public static function utf8(string $metin): string
    {
        if ($metin === '' || mb_check_encoding($metin, 'UTF-8')) {
            return $metin;
        }

        $cevrilen = mb_convert_encoding($metin, 'UTF-8', 'Windows-1254');

        return is_string($cevrilen) ? $cevrilen : $metin;
    }

    /**
     * PDF'ten düz metin çıkarır; araç yoksa ya da başarısızsa boş dizge.
     *
     * pdftotext (poppler) kullaniliyor. Saf PHP ile PDF okumak Turkce
     * karakterlerde guvenilir degil: yazi tipleri cogu zaman kendi
     * karakter eslemelerini (ToUnicode) tasiyor ve kaba bir okuyucu
     * bunlari yanlis cozer. Ajan GitHub Actions'ta calisiyor; arac
     * orada is akisi tarafindan kuruluyor. Yerelde yoksa bos donuluyor
     * ve aday eskisi gibi baslik ve ozetle degerlendiriliyor.
     *
     * -layout: tablolarin sutunlari korunsun. Tarife ve had tablolari
     * haberin en degerli kismi; duzen korunmazsa sayilar birbirine
     * yapisiyor.
     */
    public static function pdfMetni(string $pdf, int $zamanAsimi = 30): string
    {
        $arac = self::pdftotextYolu();

        if ($arac === null) {
            return '';
        }

        $gecici = tempnam(sys_get_temp_dir(), 'vltpdf');

        if ($gecici === false) {
            return '';
        }

        try {
            if (file_put_contents($gecici, $pdf) === false) {
                return '';
            }

            $komut = escapeshellarg($arac) . ' -layout -enc UTF-8 -q '
                   . escapeshellarg($gecici) . ' -';

            $surec = proc_open($komut, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $borular);

            if (!is_resource($surec)) {
                return '';
            }

            stream_set_blocking($borular[1], false);
            $cikti = '';
            $bitis = time() + $zamanAsimi;

            while (time() < $bitis) {
                $parca = stream_get_contents($borular[1]);

                if (is_string($parca) && $parca !== '') {
                    $cikti .= $parca;
                }

                $durum = proc_get_status($surec);

                if (!$durum['running']) {
                    $kalan = stream_get_contents($borular[1]);
                    $cikti .= is_string($kalan) ? $kalan : '';
                    break;
                }

                usleep(50_000);
            }

            $durum = proc_get_status($surec);

            if ($durum['running']) {
                // Takilan bir PDF calismayi durdurmasin.
                proc_terminate($surec);
            }

            fclose($borular[1]);
            fclose($borular[2]);
            proc_close($surec);

            return self::utf8($cikti);
        } finally {
            @unlink($gecici);
        }
    }

    private static ?string $aracYolu = null;
    private static bool $arandi = false;

    private static function pdftotextYolu(): ?string
    {
        if (self::$arandi) {
            return self::$aracYolu;
        }

        self::$arandi = true;

        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'] as $yol) {
            if (is_file($yol) && is_executable($yol)) {
                return self::$aracYolu = $yol;
            }
        }

        return self::$aracYolu = null;
    }
}
