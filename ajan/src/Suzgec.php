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
        // Muhasebe ve raporlama standartlari. "muhasebe" ve "denetim"
        // zayif listede tek puan aliyor; "TFRS 18 yayimlandi" gibi bir
        // baslik bu yuzden esigi gecemeyip modele hic ulasmiyordu.
        'tms', 'tfrs', 'ufrs', 'bobi frs', 'kums frs', 'kgk',
        'finansal raporlama', 'bağımsız denetim', 'denetim standardı',
        'muhasebe standardı', 'raporlama standardı', 'finansal tablo',
        'enflasyon muhasebesi', 'vergi bülteni',
        // Yabanci kaynaklar Ingilizce yayin yapiyor; Turkce terim listesi
        // bu basliklarin hicbirini yakalamiyordu, hepsi modele hic
        // ulasmadan eleniyordu. Kisa kisaltmalar (vat, tax) icerir()
        // icinde ek payi almaz, yoksa "vatandas" ve "taxi" yakalanirdi.
        'tax', 'taxation', 'vat', 'income tax', 'corporate tax',
        'tax rate', 'tax reform', 'tax treaty', 'withholding',
        'transfer pricing', 'pillar one', 'pillar two', 'beps',
        'minimum tax', 'tax avoidance', 'tax evasion', 'tax ruling',
        'ifrs', 'ias', 'accounting standard', 'auditing standard',
        'financial reporting', 'oecd', 'e-invoicing', 'customs duty',
        'excise duty', 'tax compliance', 'double taxation',
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
        // Ingilizce ekonomi terimleri.
        'inflation', 'interest rate', 'policy rate', 'rate cut',
        'rate hike', 'central bank', 'unemployment rate', 'gdp',
        'budget deficit', 'current account', 'trade deficit',
        'consumer price', 'producer price', 'industrial production',
        'economic growth', 'recession', 'fiscal policy', 'monetary policy',
        'sovereign rating', 'credit rating',
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
        return (string) preg_replace('/\s+/u', ' ', $this->katla($metin));
    }

    /**
     * Noktalı/noktasız i ayrımını kaldırarak küçültür.
     *
     * İki ayrı sorun var ve ikisi birbirini kesiyor:
     *
     * 1) mb_strtolower("İ") harfi "i" + U+0307 (birleşen üst nokta)
     *    olarak indiriyor; "İşsizlik" bu yüzden listedeki "işsizlik"
     *    terimiyle eşleşmiyordu.
     * 2) Türkçe'de "I" harfinin küçüğü "ı", İngilizce'de "i". Metin iki
     *    dilden de gelebildiği için tek bir kural yetmiyor: "I" harfini
     *    Türkçe kuralıyla indirmek "IFRS" kısaltmasını "ıfrs" yapıp
     *    yabancı kaynakların başlıklarını eleniyordu.
     *
     * Çözüm ikisini birden kapsıyor: noktalı ve noktasız i tek harfe
     * katlanıyor. Terimler de aynı işlemden geçtiği için karşılaştırma
     * tutarlı; "oranı" ile "orani" aynı şeye iniyor.
     */
    private function katla(string $metin): string
    {
        $metin = str_replace(['İ', 'I', 'ı'], 'i', $metin);
        $metin = mb_strtolower($metin, 'UTF-8');

        // mb_strtolower'in urettigi birlesen noktalar.
        return str_replace("\xCC\x87", '', $metin);
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
        // Kisa kisaltmalarda ek payi verilmez.
        //
        // Ek payi Turkce icin gerekli ("vergi" -> "vergisi"), ama uc
        // harfli bir kisaltmada felakete yol aciyor: "vat" araması
        // "vatandas" kelimesini, "tax" araması "taxi" kelimesini
        // yakalardi. Bu kisaltmalar zaten ek almadan yazilir, "KDV'nin"
        // gibi yazimlarda da kesme isareti harf olmadigi icin sinir
        // kurali tutuyor.
        $ekPayi = mb_strlen($terim, 'UTF-8') <= 3 ? '' : '\p{L}{0,8}';

        // Terim de metinle ayni katlamadan gecmeli, yoksa listedeki
        // "işsizlik oranı" ile katlanmis metindeki "issizlik orani"
        // hicbir zaman eslesmez.
        $terim = $this->katla($terim);

        $desen = '/(?<![\p{L}\p{N}])' . preg_quote($terim, '/') . $ekPayi . '(?![\p{L}])/u';

        return preg_match($desen, $metin) === 1;
    }
}
