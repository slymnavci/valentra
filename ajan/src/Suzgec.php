<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Vergi ön eleyicisi.
 *
 * Her besleme girdisini modele göndermek pahalı ve gereksiz; ekonomi
 * beslemelerinin büyük kısmı vergiyle ilgisiz. Burada ucuz bir anahtar
 * kelime taraması yapılır, yalnızca geçenler modele gider.
 *
 * Eleme kasten gevşek tutulur: kararı model verir, buradaki iş açıkça
 * alakasız olanları ayıklamaktır.
 */
final class Suzgec
{
    /** Tek başına güçlü sinyal veren terimler. */
    private const GUCLU = [
        'vergi', 'kdv', 'ötv', 'ösv', 'mtv', 'stopaj', 'tevkifat', 'matrah',
        'beyanname', 'mükellef', 'muhtasar', 'e-fatura', 'e-arşiv', 'e-defter',
        'gelir idaresi', 'gib', 'defter beyan', 'vergi dairesi', 'maliye',
        'kurumlar vergisi', 'gelir vergisi', 'damga vergisi',
        'vergi usul', 'vuk', 'transfer fiyatlandırması', 'vergi affı',
        'yapılandırma', 'matrah artırımı', 'asgari kurumlar',
        'yeminli mali müşavir', 'mali müşavir', 'türmob', 'tebliğ', 'sirküler',
        'amortisman', 'istisna', 'muafiyet', 'vergi incelemesi', 'vergi cezası',
    ];

    /** Tek başına zayıf; yalnızca bir başkasıyla birlikte sayılır. */
    private const ZAYIF = [
        'resmî gazete', 'resmi gazete', 'hazine ve maliye', 'bakanlık',
        'düzenleme', 'yürürlük', 'oran', 'had', 'tutar', 'beyan', 'iade',
        'mali', 'muhasebe', 'denetim', 'harç',
    ];

    /**
     * Girdi vergiyle ilgili olabilir mi?
     */
    public function gecer(string $baslik, string $ozet = ''): bool
    {
        return $this->puan($baslik, $ozet) > 0;
    }

    /**
     * Kaba ilgi puanı: güçlü terim 2, zayıf terim 1 sayılır.
     * Eşik 2 — tek bir güçlü terim ya da iki zayıf terim yeter.
     */
    public function puan(string $baslik, string $ozet = ''): int
    {
        $metin = $this->normalize($baslik . ' ' . $ozet);
        $puan  = 0;

        foreach (self::GUCLU as $terim) {
            if ($this->icerir($metin, $terim)) {
                $puan += 2;
            }
        }

        foreach (self::ZAYIF as $terim) {
            if ($this->icerir($metin, $terim)) {
                $puan += 1;
            }
        }

        return $puan >= 2 ? $puan : 0;
    }

    private function normalize(string $metin): string
    {
        // Türkçe'de mb_strtolower "I" harfini doğru indirger; strtolower indirgemez.
        $metin = mb_strtolower($metin, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', $metin);
    }

    /**
     * Türkçe eklere duyarlı arama.
     *
     * Terimin BAŞI kelime sınırında olmalı, ama sonuna ek gelebilir:
     * "vergi" araması "vergisi", "vergiden", "vergilendirme" kelimelerini
     * de yakalar. Türkçe sondan eklemeli olduğu için düz kelime sınırı
     * eşleşmelerin çoğunu kaçırırdı.
     *
     * Baştaki sınır korunur; böylece "vergi" araması "katmadeğervergi"
     * gibi bitişik yazımlara ya da alakasız bir kelimenin içine denk
     * gelmez.
     */
    private function icerir(string $metin, string $terim): bool
    {
        $desen = '/(?<![\p{L}\p{N}])' . preg_quote($terim, '/') . '\p{L}{0,8}(?![\p{L}])/u';

        return preg_match($desen, $metin) === 1;
    }
}
