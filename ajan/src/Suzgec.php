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

    /**
     * Ekonomi gündemi terimleri.
     *
     * Site vergi odakli ama ekonomi de izleniyor. Bu liste kasten dar:
     * somut veri ya da karar iceren haberleri yakalamak icin. "Ekonomi",
     * "piyasa" gibi genel kelimeler yok, cunku her ekonomi haberi degerli
     * degil.
     */
    private const EKONOMI = [
        'enflasyon', 'tüfe', 'üfe', 'merkez bankası', 'tcmb', 'politika faizi',
        'faiz kararı', 'faiz indirimi', 'faiz artırımı', 'asgari ücret',
        'büyüme oranı', 'gayri safi yurt içi hasıla', 'gsyh', 'cari açık',
        'bütçe açığı', 'bütçe dengesi', 'kamu maliyesi', 'hazine ihalesi',
        'işsizlik oranı', 'istihdam verisi', 'sanayi üretimi',
        'kapasite kullanım', 'tüketici güven', 'dış ticaret açığı',
        'ihracat rakamları', 'ithalat rakamları', 'teşvik paketi',
        'destek paketi', 'kredi garanti', 'yeniden değerleme oranı',
    ];

    /** Tek başına zayıf; yalnızca bir başkasıyla birlikte sayılır. */
    private const ZAYIF = [
        'resmî gazete', 'resmi gazete', 'hazine ve maliye', 'bakanlık',
        'düzenleme', 'yürürlük', 'oran', 'had', 'tutar', 'beyan', 'iade',
        'mali', 'muhasebe', 'denetim', 'harç',
    ];

    /**
     * Girdi vergi ya da ekonomi başlığına giriyor olabilir mi?
     */
    public function gecer(string $baslik, string $ozet = ''): bool
    {
        $puanlar = $this->puanlar($baslik, $ozet);

        return $puanlar['vergi'] > 0 || $puanlar['ekonomi'] > 0;
    }

    /**
     * Geriye dönük uyumluluk: toplam puan.
     */
    public function puan(string $baslik, string $ozet = ''): int
    {
        $puanlar = $this->puanlar($baslik, $ozet);

        return $puanlar['vergi'] + $puanlar['ekonomi'];
    }

    /**
     * Vergi ve ekonomi puanlarını ayrı döndürür.
     *
     * Ayrı tutulması gerekiyor: aday sayısı sınırlandığında vergi
     * haberleri önce işlenmeli, ekonomi haberleri onların yerini
     * almamalı.
     *
     * @return array{vergi:int,ekonomi:int}
     */
    public function puanlar(string $baslik, string $ozet = ''): array
    {
        $metin = $this->normalize($baslik . ' ' . $ozet);

        $vergi = 0;

        foreach (self::GUCLU as $terim) {
            if ($this->icerir($metin, $terim)) {
                $vergi += 2;
            }
        }

        foreach (self::ZAYIF as $terim) {
            if ($this->icerir($metin, $terim)) {
                $vergi += 1;
            }
        }

        $ekonomi = 0;

        foreach (self::EKONOMI as $terim) {
            if ($this->icerir($metin, $terim)) {
                $ekonomi += 2;
            }
        }

        return [
            // Esik 2: tek guclu terim ya da iki zayif terim yeter.
            'vergi'   => $vergi >= 2 ? $vergi : 0,
            'ekonomi' => $ekonomi >= 2 ? $ekonomi : 0,
        ];
    }

    private function normalize(string $metin): string
    {
        // Türkçe küçültme mb_strtolower ile tam doğru değil: "İ" harfi
        // "i" + U+0307 (birleşen üst nokta) olarak iner, böylece
        // "İşsizlik" -> "i̇şsizlik" olur ve listedeki "işsizlik"
        // terimiyle eşleşmez. Noktalı/noktasız I çiftini önce elle
        // eşleyip, kalan birleşen noktaları da temizliyoruz.
        $metin = str_replace(['İ', 'I'], ['i', 'ı'], $metin);
        $metin = mb_strtolower($metin, 'UTF-8');
        $metin = str_replace("\xCC\x87", '', $metin);

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
