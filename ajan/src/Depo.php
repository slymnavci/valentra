<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Çalışmalar arasında taşınan küçük dosya deposu.
 *
 * NEDEN VAR: ajan her seyi siteden aliyor ve siteye yaziyor. Site
 * ulasilamaz oldugunda calisma daha ILK adimda oluyordu — kaynaklar
 * taranmiyor, model cagrilmiyor, hicbir haber yazilmiyor. IHS 443'te
 * saatlerce baglanti kabul etmedigi bir gunde bu, ajanin tamamen
 * durmasi demek.
 *
 * Oysa siteye ulasilamamasi haber TOPLAMAYI engellemek zorunda degil.
 * Kaynak listesi nadiren degisiyor; son basarili calismadan kalan
 * kopyayla tarama pekala yapilabilir. Yazilan haberler de gonderim
 * dusunce kaybolmak yerine burada bekleyip bir sonraki calismada
 * gonderilebilir.
 *
 * Klasor GitHub Actions onbellegiyle calismalar arasinda tasiniyor;
 * depoya girmiyor (.gitignore).
 */
final class Depo
{
    public function __construct(private readonly string $klasor)
    {
    }

    /**
     * Veriyi diske yazar; başarısızlık sessizce yutulur.
     *
     * Depo bir kolaylik, zorunluluk degil: yazamamak calismayi
     * durdurmamali.
     */
    public function yaz(string $ad, mixed $veri): bool
    {
        try {
            // @ ile: basarisizligi zaten donus degeriyle ele aliyoruz,
            // uyarinin gunluge dusmesi yalnizca gurultu yapiyor.
            if (!is_dir($this->klasor) && !@mkdir($this->klasor, 0775, true) && !is_dir($this->klasor)) {
                return false;
            }

            $json = json_encode(
                ['zaman' => time(), 'veri' => $veri],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($json === false) {
                return false;
            }

            /*
             * Once gecici dosyaya, sonra tasima.
             *
             * Dogrudan yazarken calisma kesilirse dosya YARIM kalir ve
             * bir sonraki calisma onu bozuk JSON olarak bulur. Tasima
             * islemi ayni dosya sisteminde atomiktir.
             */
            $gecici = $this->yol($ad) . '.tmp';

            if (@file_put_contents($gecici, $json) === false) {
                return false;
            }

            return @rename($gecici, $this->yol($ad));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Veriyi okur; yoksa, bozuksa ya da çok eskiyse null döner.
     *
     * Yas siniri onemli: aylar oncesinden kalmis bir kaynak listesiyle
     * calismak, guncel listeyle calistigini sanmaktan kotudur.
     */
    public function oku(string $ad, int $enFazlaGun = 7): mixed
    {
        try {
            $yol = $this->yol($ad);

            if (!is_readable($yol)) {
                return null;
            }

            $veri = json_decode((string) file_get_contents($yol), true);

            if (!is_array($veri) || !array_key_exists('veri', $veri)) {
                return null;
            }

            if (time() - (int) ($veri['zaman'] ?? 0) > $enFazlaGun * 86400) {
                return null;
            }

            return $veri['veri'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Kayidin yasi (gun); yoksa null. */
    public function yas(string $ad): ?float
    {
        $yol = $this->yol($ad);

        if (!is_readable($yol)) {
            return null;
        }

        $veri = json_decode((string) file_get_contents($yol), true);
        $zaman = is_array($veri) ? (int) ($veri['zaman'] ?? 0) : 0;

        return $zaman > 0 ? round((time() - $zaman) / 86400, 1) : null;
    }

    public function sil(string $ad): void
    {
        $yol = $this->yol($ad);

        if (is_file($yol)) {
            @unlink($yol);
        }
    }

    private function yol(string $ad): string
    {
        // Ad disaridan gelmiyor ama yine de yol ayiricilarini temizle.
        $temiz = preg_replace('/[^a-z0-9_-]/i', '', $ad) ?? 'veri';

        return rtrim($this->klasor, '/') . '/' . $temiz . '.json';
    }
}
