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
     * Site vergi odakli ama ekonomi gundemi de sitenin asli bir parcasi:
     * makro veri ve kararlarin yaninda piyasalar, beklentiler, dunya
     * ekonomisi ve sirket haberleri de aliniyor. "Ekonomi", "piyasa" gibi
     * TEK BASINA genel kelimeler yine yok; her haberi aday yapar ve
     * kontenjani doldururdu. Son karari model veriyor.
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
        // Piyasalar, beklentiler, dunya ekonomisi ve sirketler (24.09.2026).
        //
        // Site "ekonomi sayfasi gozuyle" de bakiyor: borsa, doviz, altin,
        // petrol, beklenti anketleri, Fed/ECB, ABD-Cin ticareti, sirket
        // sonuclari. Eski dar liste bu haberleri modele hic gondermiyordu.
        // Belirsiz kisa kelimeler kasten yok: "altın" tek basina
        // "altında"yi, "Çin" ve "ABD" tek basina siyaset haberlerini
        // yakalardi; bu yuzden ikili ifadeler kullaniliyor.
        'borsa', 'bist', 'hisse senedi', 'hisseler', 'endeks',
        'dolar', 'döviz', 'avro', 'euro/tl', 'dolar/tl', 'kur farkı',
        'ons altın', 'gram altın', 'çeyrek altın', 'altın fiyat', 'altın piyasa',
        'petrol', 'brent', 'doğalgaz fiyat', 'emtia', 'opec',
        'akaryakıt', 'elektrik zammı', 'doğalgaz zammı',
        'tahvil', 'eurobond', 'kredi notu', 'cds', 'rezerv', 'swap',
        'yatırım fonu', 'fonların', 'fon tasfiye', 'yatırımcı',
        'halka arz', 'temettü', 'bilanço', 'net kar', 'net kâr', 'konkordato', 'iflas',
        'birleşme', 'satın alma',
        'piyasa beklenti', 'piyasa katılımcı', 'piyasalarda', 'küresel piyasa',
        'beklenti anketi', 'enflasyon beklenti', 'dezenflasyon',
        'orta vadeli program', 'ovp', 'kamu harcama', 'tasarruf tedbir',
        'fed', 'federal reserve', 'ecb', 'avrupa merkez bankası',
        'ticaret savaş', 'gümrük tarife', 'ek gümrük', 'abd-çin', 'çin ekonomi', 'abd ekonomi',
        'konut fiyat', 'konut satış', 'kira artış',
        'memur zammı', 'emekli zammı', 'maaş zammı',
        // Ingilizce ekonomi terimleri.
        'inflation', 'interest rate', 'policy rate', 'rate cut',
        'rate hike', 'central bank', 'unemployment rate', 'gdp',
        'budget deficit', 'current account', 'trade deficit',
        'consumer price', 'producer price', 'industrial production',
        'economic growth', 'recession', 'fiscal policy', 'monetary policy',
        'sovereign rating', 'credit rating',
        'stock market', 'stocks', 'equities', 'wall street', 'bond yield',
        'treasury yield', 'oil price', 'crude', 'gold price', 'commodit',
        'currency', 'tariff', 'trade war', 'earnings', 'ipo', 'merger',
        'acquisition', 'federal reserve', 'opec',
    ];

    /** Tek başına zayıf; yalnızca bir başkasıyla birlikte sayılır. */
    private const ZAYIF = [
        'resmî gazete', 'resmi gazete', 'hazine ve maliye', 'bakanlık',
        'düzenleme', 'yürürlük', 'oran', 'had', 'tutar', 'beyan', 'iade',
        'mali', 'muhasebe', 'denetim', 'harç',
    ];

    /**
     * Mevzuat değişikliği sinyali veren terimler.
     *
     * Ayri tutuluyorlar cunku bu basliklar puanlamada sistematik
     * olarak dusuk kaliyor: "7530 sayili Kanun ile bazi kanunlarda
     * degisiklik yapildi" cumlesi tek bir vergi terimi icermiyor ve
     * esigin hemen ustunde, 2 puanla geciyor. Ayni anda "KDV tevkifat
     * oranlarinda degisiklik" basligi 17 puan aliyor. Aday sayisi
     * sinirli oldugu icin mevzuat haberleri listeye hic giremiyordu —
     * oysa bir YMM sitesinde en degerli haber turu bu.
     */
    private const MEVZUAT = [
        'kanun', 'kanun teklifi', 'kanun tasarısı', 'torba yasa',
        'resmî gazete', 'resmi gazete', 'tebliğ', 'yönetmelik', 'sirküler',
        'cumhurbaşkanı kararı', 'bakanlar kurulu kararı', 'genelge',
        'yürürlüğe girdi', 'yürürlük tarihi', 'değişiklik yapılmasına dair',
        'mevzuat değişikliği', 'düzenleme yürürlükte', 'kararname',
        'regulation', 'directive', 'legislation', 'enacted', 'gazetted',
    ];

    /**
     * Muhasebe ve denetim standartlarını işaret eden terimler.
     *
     * Bunlar da puanlamada dusuk kaliyor: "TMS 12 Gelir Vergileri
     * standardinda guncelleme" 7 puan, "IASB issues amendments to
     * IFRS 9" 4 puan aliyor ve vergi basliklarinin arkasinda kaliyor.
     */
    private const STANDART = [
        'tms', 'tfrs', 'ufrs', 'ifrs', 'ias', 'bobi frs', 'kums frs', 'kgk',
        'muhasebe standardı', 'raporlama standardı', 'denetim standardı',
        'finansal raporlama', 'bağımsız denetim', 'finansal tablo',
        'enflasyon muhasebesi', 'accounting standard', 'auditing standard',
        'financial reporting', 'iasb', 'efrag',
    ];

    /**
     * Girdinin hangi konu kümesine girdiğini söyler.
     *
     * Aday secimi bu kumeye gore kontenjan dagitiyor. Sira onemli:
     * "VUK'ta degisiklik yapan kanun Resmi Gazete'de" basligi hem
     * mevzuat hem vergi terimleri tasiyor ve MEVZUAT sayilmali —
     * eksikligi hissedilen tur o.
     *
     * @return 'standart'|'mevzuat'|'vergi'|'ekonomi'
     */
    public function konu(string $baslik, string $ozet = ''): string
    {
        $metin = $this->normalize($baslik . ' ' . $ozet);

        foreach (self::STANDART as $terim) {
            if ($this->icerir($metin, $terim)) {
                return 'standart';
            }
        }

        foreach (self::MEVZUAT as $terim) {
            if ($this->icerir($metin, $terim)) {
                return 'mevzuat';
            }
        }

        $puanlar = $this->puanlar($baslik, $ozet);

        return $puanlar['vergi'] > 0 ? 'vergi' : 'ekonomi';
    }

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
