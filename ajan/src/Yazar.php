<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Aday haberi Gemini'ye verip iki soruyu birden yanıtlatır:
 * yayın politikasına göre yayımlanabilir mi, ve yayımlanabilirse
 * tarafsız özeti nasıl yazılmalı.
 *
 * Telif: modele kaynak metin YALNIZCA anlaması için verilir. Çıktının
 * özgün olması, kaynaktan cümle kopyalanmaması ve kaynağa atıf verilmesi
 * sistem yönergesinde açıkça şart koşulur.
 */
final class Yazar implements SemaliIstemci
{
    /**
     * Varsayilan model. GEMINI_MODEL ortam degiskeniyle degistirilebilir;
     * model adi degisirse kod duzenlemeye gerek kalmaz.
     */
    private const VARSAYILAN_MODEL = 'gemini-3.8-flash';

    /**
     * Aday basina modele giden sayfa metni siniri (karakter).
     *
     * 3.000'den yukseltildi: haberin ayrintisi (yururluk tarihi, gecis
     * hukumleri, tutar tablolari) cogu kaynakta metnin asagisinda
     * duruyor ve eski sinirla modele hic ulasmiyordu.
     */
    private const SAYFA_METNI_SINIRI = 14000;

    /**
     * İsteme kaç tane "daha önce yayımlandı" başlığı yazılacağı.
     *
     * Ayni olayi yeniden yazmayi onlemek icin son basliklari gormek
     * yeterli; ayin basindaki bir haberin bugunku bir adayla ayni olay
     * olmasi cok nadir. Uzun liste istegi sisirir ve modelin dikkatini
     * adaylardan uzaklastirir.
     */
    private const ONCEKI_BASLIK_SINIRI = 60;
    private const API   = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /*
     * YONERGE — Ekim 2026'da yeniden yazildi.
     *
     * Site vergi/muhasebe yayinindan genel haber ozetlerine dondu (site
     * sahibinin karari): gundem, politika, ekonomi, dunya, spor,
     * teknoloji, saglik, kultur-sanat. Her haber kendi cumlelerimizle
     * ozetleniyor; sayfada kaynak adi ve haberin tamamina baglanti var.
     *
     * YAYIN POLITIKASI site sahibinin talimati: fanatik ve dogrulanmamis
     * haberden uzak durulacak; Valentra'yi devlete karsi yalanci konuma
     * dusurecek, davaya acik haber yayimlanmayacak; yorum yok, tarafsiz
     * aktarim var. Asagidaki "YAYIMLANMAZ" listesi bunun karsiligi. Onay
     * asamasi yine var; ama riskli haberi modelin daha bastan elemesi,
     * editorun onune hic gelmemesi demek.
     *
     * Eski vergi analizi alanlari (analiz_*, isletme_etkisi,
     * uygulama_ornegi, resmi_dayanak) semada duruyor, bos donduruluyor.
     */
    private const YONERGE = <<<'METIN'
        Sen Valentra adlı haber sitesinin editör yardımcısısın. Valentra,
        Türkiye ve dünyadan güncel haberleri güvenilir kaynaklardan
        derleyip TARAFSIZ ÖZETLER halinde yayımlar. Her haberin altında
        kaynağın adı ve haberin tamamına giden bağlantı yer alır.

        Konu başlıkları: gündem, politika, ekonomi, dünya, spor,
        teknoloji, sağlık, kültür-sanat. Politikada öncelik Türkiye
        siyasetidir. Ayrıca yemek tarifleri yayımlanır (aşağıda YEMEK
        TARİFLERİ bölümü; tarifler haber kurallarının dışındadır).

        Sana bir haber kaynağından başlık, özet ve sayfa metni verilecek.
        İki iş yapacaksın:

        1) KARAR VER: Bu haber Valentra'da yayımlanabilir mi?

           YAYIMLANIR: Konu başlıklarından birine giren, kamuoyunu
           ilgilendiren, OLGUYA dayanan haber. Örnekler:
           - Resmî kurumların açıklama ve kararları: TBMM'de kabul
             edilen kanunlar, Cumhurbaşkanlığı ve bakanlık kararları,
             Resmî Gazete düzenlemeleri, mahkemelerin açıklanmış
             kararları, kurumların resmî duyuruları.
           - Siyasi partilerin ve siyasetçilerin POLİTİKA içeriği
             taşıyan açıklamaları (bir kanun teklifi, bir program, bir
             ekonomik öneri), açıklamayı yapana atıfla aktarılarak.
           - Resmî ziyaretler, uluslararası görüşmeler ve anlaşmalar,
             seçim takvimi ve resmî sonuçlar.
           - Ekonomi verileri ve piyasalar, şirket haberleri.
           - Maç sonuçları, transferler, milli takımlar; bilim ve
             teknoloji gelişmeleri; sağlık araştırmaları ve kamu sağlığı
             duyuruları; kültür-sanat etkinlikleri ve ödüller.
           - Doğal afet, kaza ve benzeri olaylar: yalnızca resmî
             kurumların (AFAD, valilik, bakanlık) açıkladığı bilgilerle.

           YAYIMLANMAZ — aşağıdakilerden biri varsa ilgili=false yap ve
           red_nedeni'ni "yayın politikası: " ile başlat, ardından hangi
           maddeye takıldığını kısaca yaz. Bu kurallar konu ne kadar
           önemli olursa olsun geçerlidir; tereddütte kalırsan
           YAYIMLAMA:
           a) Doğrulanmamış iddia: "kulis", "iddia edildi", "öne
              sürüldü", isimsiz kaynak, sosyal medya paylaşımı ya da
              söylentiye dayanan ve resmî bir makam tarafından teyit
              edilmemiş haber.
           b) Kişi ya da kurumlara suç isnadı: resmî bir açıklamaya,
              iddianameye ya da mahkeme kararına dayanmayan suçlama.
              Dayansa bile haber suçlamayı kesinmiş gibi sunuyorsa
              yayımlama (masumiyet karinesi: yargılama sürerken "şüpheli"
              ya da "sanık" denir, "suçlu" denmez).
           c) Devlet kurumlarına, güvenlik güçlerine, yargıya ya da kamu
              görevlilerine yönelik, resmî bir kaynağa dayanmayan
              suçlayıcı ya da itibar zedeleyici iddialar.
           d) Hakaret, aşağılama ya da küfür içeren beyanlar; bunları
              aktaran haberler. Siyasetçilerin birbirine yönelik
              atışma, polemik ve "sert sözlerle yüklendi" türü
              haberler — politika içeriği taşımıyorsa yayımlanmaz.
           e) Yayın yasağı getirilmiş olaylar; terör örgütlerinin
              açıklama ve propagandasını aktaran içerik; şiddeti
              ayrıntılı anlatan ya da özendiren içerik.
           f) Kutuplaştırıcı ve provokatif içerik: bir kesimi hedef
              gösteren, toplumu kışkırtan, tek tarafın sloganlarını
              taşıyan, öfke ya da korku uyandırmak için yazılmış haber.
           g) Yorum ve görüş ağırlıklı içerik: köşe yazısı, analiz,
              "uzman görüşü", taraf tutan değerlendirme. Haberde olgu
              yerine yorum ağırlıklıysa yayımlama.
           h) Magazin ve dedikodu, özel hayat, tık tuzağı, burç,
              şans oyunu, reklam ve tanıtım içeriği, içeriği olmayan
              "şu yayına katılacak" duyuruları.
           i) Ölüm, yaralanma ya da suç olaylarında mağdurların ve
              çocukların kimliğini açık eden ayrıntılar (bunlar özete
              hiç girmez; haber başka açıdan uygunsa kimlik vermeden
              yaz).

           YAZAR MAKALESİ HABER DEĞİLDİR. Bir kişinin imzasını taşıyan
           makale, köşe yazısı ya da görüş yazısı (sayfada yazar adı,
           fotoğrafı; "yazarlar", "köşe yazıları" bölümünden geliyor)
           başkasının özgün emeğidir. ilgili=false yap ve red_nedeni'ni
           "yazar makalesi: " ile başlat. Bir kurumun resmî duyurusu ya
           da haber sitesinin muhabir haberi yazar makalesi sayılmaz.

           AYNI OLAY İKİNCİ KEZ YAZILMAZ. İstemde "DAHA ÖNCE
           YAYIMLANANLAR" başlığı altında başlıklar verilmişse, bunlardan
           biriyle AYNI OLAYI anlatan adayı ilgili sayma: ilgili=false yap
           ve red_nedeni'ni "yinelenen: " ile başlat, ardından hangi
           başlıkla çakıştığını yaz. Ölçüt kelime benzerliği değil,
           olayın aynılığıdır: aynı maç, aynı karar, aynı açıklama, aynı
           veri — başlıklar farklı kelimelerle kurulmuş, farklı kaynaktan
           gelmiş olsa bile. Aynı konuda YENİ ve somut bir gelişme ekleyen
           haber (maçın sonucu, kararın yürürlüğe girmesi) aynı olay
           değildir. Tereddütte kalırsan yinelenen SAYMA.

           AYNI PARTİ İÇİNDE DE GEÇERLİ: aşağıdaki adaylardan ikisi aynı
           olayı anlatıyorsa yalnızca BİRİNİ ilgili say. Kalanı seçerken
           haber ajansını ya da resmî kaynağı, o da yoksa sayfa metni en
           ayrıntılı olanı tut; diğerlerini "yinelenen: ADAY n ile aynı
           olay" diye ele.

        2) YAYIMLANABİLİRSE ÖZETİ YAZ. Kurallar:
           - Kaynak metni yalnızca anlamak için okursun. ASLA cümle
             kopyalamazsın; kendi cümlelerinle özgün bir özet yazarsın.
             Özet, haberin yerine geçmez; okuru ayrıntı için kaynağa
             yönlendirir.
           - TARAFSIZ DİL. Yorum, değerlendirme, tahmin ve tavsiye yok.
             Duygu yüklü sıfat ve zarf kullanma ("skandal", "şok",
             "tarihî", "büyük tepki", "acı", "müjde"). Olanı aktar.
           - ATIF. Bir kişinin ya da kurumun sözünü kendi bilgin gibi
             yazma: "... açıkladı", "... bildirdi", "... dedi" diye
             kimin söylediğini belirt. Resmî açıklamalarda kurumun adını
             ver. Rakam ve tarihleri kaynaktaki gibi aktar.
           - Siyasi haberde birden çok tarafın açıklaması kaynakta
             varsa, hiçbirini öne çıkarmadan aynı tonda aktar. Kaynakta
             olmayan tarafı kendin ekleme.
           - Kaynakta olmayan HİÇBİR bilgiyi ekleme. Rakam, oran, tarih,
             skor ve isimleri kaynaktaki gibi ver; kaynakta yoksa
             uydurma. Eksik ya da belirsiz bir ayrıntıyı ajan notunda
             editöre bildir.
           - Türkçe, sade ve kurumsal dil. Ünlem, abartı ve tıklama
             tuzağı başlık kullanma.
           - ÇIKTININ TAMAMI TÜRKÇE: başlık, özet, metin, etiketler, red
             nedeni ve ajan notu. Kaynak yabancı dildeyse de Türkçe
             yazarsın; cümle cümle çevirme, anlayıp aktar. Kurum ve kişi
             adları özgün biçimini korur.
           - Para birimlerini kaynaktaki birimiyle ver, TL'ye çevirme.
           - UZUNLUK: 3-5 paragraf. İlk paragraf haberin özünü tek
             başına versin: ne oldu, kim, nerede, ne zaman. Kaynak kısaysa
             kısa kal; boş cümleyle paragraf doldurma.
           - Paragrafları BOŞ SATIRLA ayır.
           - Başlık en fazla 90 karakter, olguyu bildirsin; soru ya da
             merak uyandırma kalıbı kullanma.
           - Özet (spot) tek cümle, en fazla 200 karakter.
           - Etiketler: 2-4 adet, konu terimleri (örnek: TBMM, Süper Lig,
             enflasyon, yapay zekâ). Kişi adlarını etiket yapma.

           Analiz alanları (analiz_degisen, analiz_etkilenen,
           analiz_zaman, analiz_islem, isletme_etkisi, uygulama_ornegi,
           resmi_dayanak) artık kullanılmıyor: HEPSİNİ BOŞ BIRAK.

        GÜVEN SKORU (0-100): Haberin doğruluğundan ve yayın politikasına
        uygunluğundan ne kadar eminsin. Haber ajansı ya da resmî kurum
        kaynaklı, olguya dayanan haber yüksek (85-100). Tek kaynaklı,
        ayrıntısı eksik ya da "bekleniyor/planlanıyor" gibi kesinleşmemiş
        ifadeler varsa düşür (50-80). Hassas bir konuya değiniyorsa
        (yargı süreci, güvenlik, siyasi gerginlik) 70'in üstüne çıkma ve
        ajan notunda belirt.

        ÖNEM PUANI (0-100): Haberin okur için ne kadar önemli olduğu.
        Sitede haberler önemden önemsize doğru sıralanır; bu puan o
        sıranın ölçüsüdür, güven skorundan ayrıdır. Ölçütler: kaç kişiyi
        etkilediği (ülke geneli > bölge > yerel), Türkiye'ye etkisi,
        aciliyeti ve kalıcılığı.
        - 85-100: Ülke gündemini belirleyen gelişmeler: kabul edilen
          önemli kanunlar, Cumhurbaşkanlığı kararları, Merkez Bankası faiz
          kararı, enflasyon verisi, büyük afetler, seçim sonuçları,
          Türkiye'yi doğrudan etkileyen büyük uluslararası olaylar.
        - 60-84: Geniş kesimi ilgilendiren gelişmeler: bakanlık kararları,
          önemli diplomatik görüşmeler, büyük kulüplerin ve milli
          takımların maçları, piyasalarda belirgin hareket, önemli bilim
          ve sağlık haberleri.
        - 30-59: Sınırlı etkili ya da rutin haberler: sektörel
          açıklamalar, alt lig ve küçük branş sonuçları, yerel olaylar,
          kültür-sanat etkinlikleri.
        - 0-29: Çok dar ilgi alanı.
        Başlığın çarpıcılığına değil, olayın etkisine bak.
        Her adayda "Bu olayı veren kaynak sayısı" yazar: aynı olayı birden
        çok haber sitesi veriyorsa olay gündemdedir ve çok okunuyordur.
        3 ve üzeri kaynakta geçen olayın puanını yükselt (en az 60); tek
        kaynakta geçen olayı kendi etkisine göre puanla.

        KONU GRUBU: Haberi, kullanıcı mesajında verilen gruplardan birine
        ata. Yanıtta grubun slug değerini TAM olarak yaz. Eşleme:
        - Türkiye siyaseti, TBMM, hükümet, partiler, seçimler, Türkiye'nin
          dış politikası -> "politika".
        - Türkiye'de siyaset dışı güncel olaylar (eğitim, ulaşım, hava,
          afet, yerel yönetim, toplumu ilgilendiren kararlar) -> "gundem".
        - Başka ülkelerin iç gelişmeleri ve uluslararası olaylar
          -> "dunya".
        - Ekonomi, piyasalar, şirketler (dünya ekonomisi dahil)
          -> "ekonomi".
        - Diğerleri kendi başlıklarına: "spor", "teknoloji", "saglik",
          "kultur-sanat".
        - Yemek tarifi -> "yemek-tarifleri" (yalnızca tarifler; yemek
          kültürü haberi, restoran ya da gıda sektörü haberi değil).

        AJAN NOTU: Haberi onaylayacak editöre tek cümlelik not. Neyi
        kontrol etmesi gerektiğini söyle; hassas bir yön varsa (isim,
        yargı süreci, siyasi açıklama) onu belirt; her şey netse bunu
        yaz. Örnek: "Tek kaynak; açıklamanın resmî metni kontrol
        edilmeli."

        Yayımlanamıyorsa baslik, ozet, icerik, etiketler boş kalsın ve
        red_nedeni'ni tek cümleyle doldur.

        YEMEK TARİFLERİ: Aday bir yemek tarifiyse (çoğunlukla tarif
        sitelerinden gelir) yukarıdaki haber kuralları yerine bunlar
        geçerlidir.
        - YAYIMLANIR: malzemesi ve yapılışı kaynakta belli olan gerçek bir
          tarif (ana yemek, çorba, tatlı, hamur işi, içecek...).
        - YAYIMLANMAZ: ürün ya da marka tanıtımı, kampanya, restoran
          reklamı; "en iyi tatlıcılar", "nerede yenir" gibi mekân
          listeleri; yalnızca yemek adlarını sıralayan günlük menüler;
          mutfak eşyası rehberleri; "zayıflatır, şifa verir, hastalığı
          önler" gibi sağlık iddiası taşıyan içerik; malzemesi ya da
          yapılışı eksik sayfa.
        - baslik: tarifin adı (ör. "Fırında Sebzeli Tavuk").
        - ozet: tek cümle; tarifin ne olduğu, kaynakta varsa kaç kişilik
          ve ne kadar sürede hazırlandığı.
        - icerik: kısa bir giriş paragrafı; boş satır; "Malzemeler"
          satırı ve her malzeme ayrı satırda "- " ile; boş satır;
          "Yapılışı" satırı ve numaralı adımlar (1., 2., ...) KENDİ
          CÜMLELERİNLE. Kaynakta olmayan malzeme ya da ölçü ekleme.
        - kategori "yemek-tarifleri"; onem 20-40 (tarifler haberlerin
          önüne geçmemeli); etiketler ana malzeme ve yemek türü (ör.
          tavuk, fırın yemeği, tatlı).
        METIN;

    /**
     * Toplu sema: tek istekte birden cok haber degerlendirilir.
     *
     * Ucretsiz katmanda istek sayisi sinirli oldugu icin her haber icin
     * ayri cagri yapmak kotayi hemen tuketiyor. Adaylar gruplanip tek
     * istekte gonderilir; "sira" alani sonuclari adaylarla eslestirir.
     *
     * @var array<string,mixed>
     */
    private const TOPLU_SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Her aday icin bir sonuc, sirasiyla',
                'items' => self::HABER_SEMA_ITEM,
            ],
        ],
        'required' => ['sonuclar'],
    ];

    /** @var array<string,mixed> */
    private const HABER_SEMA_ITEM = [
        'type' => 'object',
        'properties' => [
            'sira'        => ['type' => 'integer', 'description' => 'Adayin kullanici mesajindaki sira numarasi'],
            'ilgili'      => ['type' => 'boolean', 'description' => 'Haber yayın politikasına göre yayımlanabilir mi'],
            'red_nedeni'  => ['type' => 'string',  'description' => 'İlgili değilse tek cümlelik gerekçe, ilgiliyse boş'],
            'baslik'      => ['type' => 'string',  'description' => 'Haber başlığı, en fazla 90 karakter'],
            'ozet'        => ['type' => 'string',  'description' => 'Tek cümlelik spot, en fazla 200 karakter'],
            'icerik'      => ['type' => 'string',  'description' => '3-5 paragraflık tarafsız özet, paragraflar boş satırla ayrılmış; kaynakta olmayan bilgi yok. Yemek tarifinde: giriş, Malzemeler, Yapılışı (bkz. YEMEK TARİFLERİ)'],
            'etiketler'   => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
                'description' => '2-4 konu terimi (ör. TBMM, Süper Lig, enflasyon, yapay zekâ); kişi adı değil',
            ],
            'kategori'    => ['type' => 'string',  'description' => 'Verilen konu grubu listesinden TAM olarak bir slug'],
            'guven_skoru' => ['type' => 'integer', 'description' => '0-100 arası güven'],
            'onem'        => ['type' => 'integer', 'description' => '0-100 arası önem puanı: haberin kamuoyu için ne kadar önemli olduğu (bkz. ÖNEM PUANI)'],
            'ajan_notu'   => ['type' => 'string',  'description' => 'Onaylayacak editöre tek cümlelik not'],

            /*
             * Eski VALENTRA ANALIZ alanlari (vergi yayini donemi).
             *
             * Genel haber ozetlerinde kullanilmiyor; model bos donduruyor.
             * Semadan cikarilmadi: site tarafinda haber_taslak_ekle() bu
             * alanlari bekliyor ve eski haberlerde dolular.
             */
            'analiz_degisen'  => ['type' => 'string', 'description' => 'Kullanılmıyor (eski vergi analizi alanı): boş bırak.'],
            'analiz_etkilenen'=> ['type' => 'string', 'description' => 'Kullanılmıyor (eski vergi analizi alanı): boş bırak.'],
            'analiz_zaman'    => ['type' => 'string', 'description' => 'Kullanılmıyor (eski vergi analizi alanı): boş bırak.'],
            'analiz_islem'    => ['type' => 'string', 'description' => 'Kullanılmıyor (eski vergi analizi alanı): boş bırak.'],
            'isletme_etkisi'  => ['type' => 'string', 'description' => 'Kullanılmıyor (eski vergi analizi alanı): boş bırak.'],
            'uygulama_ornegi' => ['type' => 'string', 'description' => 'Kullanılmıyor (eski vergi analizi alanı): boş bırak.'],
            'resmi_dayanak'   => ['type' => 'string', 'description' => 'Kullanılmıyor (eski vergi analizi alanı): boş bırak.'],
        ],
        'required' => [
            'sira', 'ilgili', 'red_nedeni', 'baslik', 'ozet', 'icerik',
            'etiketler', 'kategori', 'guven_skoru', 'onem', 'ajan_notu',
            'analiz_degisen', 'analiz_etkilenen', 'analiz_zaman', 'analiz_islem',
            'isletme_etkisi', 'uygulama_ornegi', 'resmi_dayanak',
        ],
    ];

    /** @var array<string,mixed> */
    private const SEMA = [
        'type' => 'object',
        'properties' => [
            'ilgili'      => ['type' => 'boolean', 'description' => 'Haber yayın politikasına göre yayımlanabilir mi'],
            'red_nedeni'  => ['type' => 'string',  'description' => 'İlgili değilse tek cümlelik gerekçe, ilgiliyse boş'],
            'baslik'      => ['type' => 'string',  'description' => 'Haber başlığı, en fazla 90 karakter'],
            'ozet'        => ['type' => 'string',  'description' => 'Tek cümlelik spot, en fazla 200 karakter'],
            'icerik'      => ['type' => 'string',  'description' => '3-5 paragraflık tarafsız özet, paragraflar boş satırla ayrılmış; kaynakta olmayan bilgi yok. Yemek tarifinde: giriş, Malzemeler, Yapılışı (bkz. YEMEK TARİFLERİ)'],
            'etiketler'   => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
                'description' => '2-4 konu terimi (ör. TBMM, Süper Lig, enflasyon, yapay zekâ); kişi adı değil',
            ],
            'kategori'    => ['type' => 'string',  'description' => 'Verilen konu grubu listesinden TAM olarak bir slug'],
            'guven_skoru' => ['type' => 'integer', 'description' => '0-100 arası güven'],
            'onem'        => ['type' => 'integer', 'description' => '0-100 arası önem puanı: haberin kamuoyu için ne kadar önemli olduğu (bkz. ÖNEM PUANI)'],
            'ajan_notu'   => ['type' => 'string',  'description' => 'Onaylayacak editöre tek cümlelik not'],
        ],
        // Gemini'nin responseSchema'si OpenAPI alt kumesidir;
        // additionalProperties desteklenmez, gonderilirse istek 400 doner.
        'required' => [
            'ilgili', 'red_nedeni', 'baslik', 'ozet', 'icerik',
            'etiketler', 'kategori', 'guven_skoru', 'onem', 'ajan_notu',
        ],
    ];

    private readonly string $model;

    /** @var array{girdi:int,cikti:int,istek:int} Calisma boyunca biriken kullanim */
    private array $kullanim = ['girdi' => 0, 'cikti' => 0, 'istek' => 0];

    public function __construct(private readonly string $apiKey, string $model = '')
    {
        $ortam = (string) (getenv('GEMINI_MODEL') ?: '');

        $this->model = $model !== '' ? $model : ($ortam !== '' ? $ortam : self::VARSAYILAN_MODEL);
    }

    /**
     * @param array{baslik:string,ozet:string,baglanti:string} $aday
     * @return array<string,mixed>|null Model yanıtı; çözümlenemezse null
     */
    public function isle(
        array $aday,
        string $sayfaMetni,
        string $kaynakAdi,
        string $kaynakTuru,
        array $kategoriler,
    ): ?array {
        $istem = $this->istemHazirla($aday, $sayfaMetni, $kaynakAdi, $kaynakTuru, $kategoriler);

        $govde = [
            'systemInstruction' => [
                'parts' => [['text' => self::YONERGE]],
            ],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $istem]],
            ]],
            'generationConfig' => [
                'maxOutputTokens'  => 8000,
                // Gemini yapisal cikti icin bu iki alani kullanir.
                // "responseFormat" OpenAI'ya ait bir sekildir; Gemini
                // bilinmeyen alan olarak reddeder.
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::SEMA,
            ],
        ];

        $yanit = $this->geminiIstegi($govde);

        $adaylar = $yanit['candidates'] ?? [];
        if (!is_array($adaylar) || $adaylar === []) {
            return null;
        }

        $parcalar = $adaylar[0]['content']['parts'] ?? [];
        if (!is_array($parcalar)) {
            return null;
        }

        foreach ($parcalar as $parca) {
            if (!is_array($parca) || !isset($parca['text']) || !is_string($parca['text'])) {
                continue;
            }

            $veri = json_decode($parca['text'], true);

            if (is_array($veri) && array_key_exists('ilgili', $veri)) {
                return $veri;
            }
        }

        return null;
    }

    /**
     * Gemini generateContent çağrısı.
     *
     * Geçici 429/5xx durumlarında üç kez dener; kalıcı hatada Google'ın
     * döndürdüğü mesajı loga taşıyarak teşhisi kolaylaştırır.
     *
     * @param array<string,mixed> $govde
     * @return array<string,mixed>
     */
    private function geminiIstegi(array $govde): array
    {
        $adres = self::API . $this->model . ':generateContent';
        $json  = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new \RuntimeException('Gemini isteği JSON olarak hazırlanamadı.');
        }

        $sonMesaj = '';

        // Ucretsiz katmanda 503 ("yogun talep") sik gorulur; birkac
        // saniyelik bekleme yetmez. Artan bekleme ile daha uzun israr
        // edilir: 4, 10, 20, 35, 60 saniye.
        $beklemeler = [4, 10, 20, 35, 60];
        $enFazlaDeneme = count($beklemeler) + 1;

        for ($deneme = 1; $deneme <= $enFazlaDeneme; $deneme++) {
            $ch = curl_init($adres);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            $ham   = curl_exec($ch);
            $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hata  = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if (!is_string($ham)) {
                $sonMesaj = $hata !== '' ? $hata : 'bilinmeyen ağ hatası';

                if ($deneme < $enFazlaDeneme && in_array($errno, [
                    CURLE_COULDNT_RESOLVE_HOST,
                    CURLE_COULDNT_CONNECT,
                    CURLE_OPERATION_TIMEDOUT,
                ], true)) {
                    sleep($beklemeler[$deneme - 1]);
                    continue;
                }

                throw new \RuntimeException('Gemini API erişim hatası: ' . $sonMesaj);
            }

            $veri = json_decode($ham, true);

            if ($kod >= 200 && $kod < 300 && is_array($veri)) {
                $this->kullanimiTopla($veri);

                return $veri;
            }

            $sonMesaj = is_array($veri)
                ? (string) ($veri['error']['message'] ?? ('HTTP ' . $kod))
                : ('HTTP ' . $kod . ': ' . mb_substr($ham, 0, 300, 'UTF-8'));

            // Gunluk kota bittiyse beklemenin anlami yok: kalan adaylar
            // da ayni duvara carpar. Cagrani bilgilendirip cikiyoruz.
            if ($kod === 429 && $this->gunlukKotaBitti($sonMesaj)) {
                throw new KotaBittiException($sonMesaj);
            }

            // 429 (kota) ve 5xx (gecici sunucu sorunu) yeniden denenir.
            if ($deneme < $enFazlaDeneme && ($kod === 429 || $kod >= 500)) {
                // Google kac saniye beklenecegini soyluyorsa ona uy;
                // kendi tahminimizle erken denemek istegi bosa harcar.
                $onerilen = $this->onerilenBekleme($veri, $sonMesaj);
                sleep($onerilen ?? $beklemeler[$deneme - 1]);
                continue;
            }

            throw new \RuntimeException('Gemini API hatası (HTTP ' . $kod . '): ' . $sonMesaj);
        }

        throw new \RuntimeException('Gemini API hatası: ' . $sonMesaj);
    }

    /**
     * Birden çok adayı tek istekte değerlendirir.
     *
     * Ücretsiz katmanda istek sayısı sınırlı; her haber için ayrı çağrı
     * kotayı hemen tüketiyor. Beş adaylık bir grup tek istekte işlenince
     * aynı iş beşte bir istekle yapılır.
     *
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string,kapsam?:int}> $adaylar
     * @param list<array<string,mixed>> $kategoriler
     * @return array<int,array<string,mixed>> sira => sonuc
     */
    public function topluIsle(array $adaylar, array $kategoriler, array $onceki = []): array
    {
        if ($adaylar === []) {
            return [];
        }

        return $this->semaliIstek(
            self::YONERGE,
            $this->topluIstemHazirla($adaylar, $kategoriler, $onceki),
            self::TOPLU_SEMA,
            // Haber basina daha uzun metin istendigi icin butce
            // yukseltildi; bes adaylik bir grupta 24.000 token
            // yaziyi ortasindan kesiyordu.
            48000,
            count($adaylar),
            'ilgili'
        );
    }

    /**
     * Şema kısıtlı bir istek atar ve "sonuclar" dizisini sıraya göre döndürür.
     *
     * Haber yazimi ile pratik bilgi okuma ayni istek/yanit dokusunu
     * kullaniyor ama yonergeleri ve semalari tamamen farkli. Ortak olan
     * kismi burada topluyoruz; kopyalanmis bir istek kurulumu iki yerde
     * ayri ayri bozulurdu.
     *
     * @param array<string,mixed> $sema
     * @param int    $adayAdedi Sira numarasi denetimi icin
     * @param string $zorunluAlan Sonucta bulunmasi gereken alan ('' ise denetim yok)
     * @return array<int,array<string,mixed>> 0 tabanli sira => sonuc
     */
    public function semaliIstek(
        string $yonerge,
        string $istem,
        array $sema,
        int $enFazlaToken,
        int $adayAdedi = 0,
        string $zorunluAlan = ''
    ): array {
        $govde = [
            'systemInstruction' => [
                'parts' => [['text' => $yonerge]],
            ],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $istem]],
            ]],
            'generationConfig' => [
                'maxOutputTokens'  => $enFazlaToken,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $sema,
            ],
        ];

        $yanit    = $this->geminiIstegi($govde);
        $parcalar = $yanit['candidates'][0]['content']['parts'] ?? [];

        if (!is_array($parcalar)) {
            return [];
        }

        foreach ($parcalar as $parca) {
            if (!is_array($parca) || !isset($parca['text']) || !is_string($parca['text'])) {
                continue;
            }

            $veri = json_decode($parca['text'], true);

            if (!is_array($veri) || !isset($veri['sonuclar']) || !is_array($veri['sonuclar'])) {
                continue;
            }

            $sonuclar = [];

            foreach ($veri['sonuclar'] as $sonuc) {
                if (!is_array($sonuc)) {
                    continue;
                }

                if ($zorunluAlan !== '' && !array_key_exists($zorunluAlan, $sonuc)) {
                    continue;
                }

                // Sira numarasi 1'den baslar; dizi indisine cevriliyor.
                $sira = (int) ($sonuc['sira'] ?? 0) - 1;

                if ($sira < 0) {
                    continue;
                }

                if ($adayAdedi > 0 && $sira >= $adayAdedi) {
                    continue;
                }

                $sonuclar[$sira] = $sonuc;
            }

            return $sonuclar;
        }

        return [];
    }

    /**
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string,kapsam?:int}> $adaylar
     * @param list<array<string,mixed>> $kategoriler
     */
    private function topluIstemHazirla(array $adaylar, array $kategoriler, array $onceki = []): string
    {
        $gruplar = $this->gruplariYaz($kategoriler);
        $bloklar = [];

        foreach ($adaylar as $sira => $aday) {
            $no     = $sira + 1;
            $girdi  = $aday['girdi'];
            $tur    = $aday['kaynakTuru'] === 'resmi'
                ? 'Birincil/resmî kaynak.'
                : 'İkincil haber kaynağı.';

            /*
             * Sayfa metni sinirli ama GENIS.
             *
             * Onceki sinir 3.000 karakterdi ve haberler yuzeysel
             * kaliyordu: bir tebligin yururluk tarihi, gecis hukumleri
             * ve tutar tablosu cogu zaman metnin asagisinda duruyor,
             * yani modele hic gitmiyordu. Model kaynakta olmayan seyi
             * yazamadigi icin sonuc kacinilmaz olarak kisaydi.
             *
             * Sinir tamamen kalkmiyor: bes adaylik bir grupta cok uzun
             * metinler istegi sisirir ve modelin dikkati dagilir.
             */
            $metin = $aday['sayfaMetni'] !== ''
                ? mb_substr($aday['sayfaMetni'], 0, self::SAYFA_METNI_SINIRI, 'UTF-8')
                : '(Sayfa metni alınamadı; yalnızca başlık ve özet mevcut.)';

            $kapsam = max(1, (int) ($aday['kapsam'] ?? 1));

            $bloklar[] = <<<METIN
            ### ADAY {$no}
            Kaynak: {$aday['kaynakAdi']} — {$tur}
            Bu olayı veren kaynak sayısı (bu taramada): {$kapsam}
            Adres: {$girdi['baglanti']}
            Başlık: {$girdi['baslik']}
            Özet: {$girdi['ozet']}
            Sayfa metni:
            ---
            {$metin}
            ---
            METIN;
        }

        $adayMetni = implode("\n\n", $bloklar);
        $adet      = count($adaylar);

        /*
         * DAHA ONCE YAYIMLANANLAR.
         *
         * Mekanik kopya engeli ayni olayi FARKLI kelimelerle anlatan
         * basliklari yakalayamiyor; yapisi geregi yakalayamaz. Olculen
         * ornek: "Fed Politika Faizini Yuzde 3,75-4,00 Araligina
         * Cikardi" ile "Fed Faiz Artirdi: Ons Altin ve Tahvil
         * Getirilerinde Dalgalanma" arasinda ortak kelime orani 0,12 —
         * esik 0,80. Esigi dusurmek cozum degil: o zaman ardarda cikan
         * iki AYRI KDV tebligi de birlesir ve gercek haber kaybolur.
         *
         * Bu ayrimi yapabilen tek katman model: olayin ayni olup
         * olmadigina bakiyor, kelimelere degil. Bu yuzden son
         * basliklar istemde gosteriliyor.
         *
         * Liste kisa tutuluyor: cok uzun bir gecmis hem istegi sisirir
         * hem de modelin dikkatini dagitir.
         */
        $oncekiBlok = '';

        if ($onceki !== []) {
            $satirlar = [];

            foreach (array_slice($onceki, 0, self::ONCEKI_BASLIK_SINIRI) as $baslik) {
                $temiz = trim((string) $baslik);

                if ($temiz !== '') {
                    $satirlar[] = '- ' . mb_substr($temiz, 0, 160, 'UTF-8');
                }
            }

            if ($satirlar !== []) {
                $liste = implode("\n", $satirlar);

                $oncekiBlok = <<<ONCEKI

                DAHA ÖNCE YAYIMLANANLAR (bunlarla aynı olayı anlatan adayı
                ilgili sayma; red_nedeni'ni "yinelenen: " ile başlat):
                {$liste}

                ONCEKI;
            }
        }

        return <<<METIN
        Konu grupları (kategori alanına bunlardan birinin slug'ını yaz):
        {$gruplar}
        {$oncekiBlok}

        Aşağıda {$adet} haber adayı var. HER BİRİNİ ayrı ayrı değerlendir
        ve "sonuclar" dizisinde {$adet} sonuç döndür. Her sonucun "sira"
        alanına ilgili adayın numarasını yaz (1'den {$adet}'e kadar).
        Hiçbir adayı atlama; yayımlanamayanlar için de ilgili değerini
        false yapıp red_nedeni'ni doldur.

        {$adayMetni}
        METIN;
    }

    /** @param list<array<string,mixed>> $kategoriler */
    private function gruplariYaz(array $kategoriler): string
    {
        $satirlar = [];

        foreach ($kategoriler as $kategori) {
            $aciklama = trim((string) ($kategori['aciklama'] ?? ''));
            $satirlar[] = '- ' . $kategori['slug'] . ' (' . $kategori['ad'] . ')'
                . ($aciklama !== '' ? ': ' . $aciklama : '');
        }

        return implode("\n", $satirlar);
    }

    /**
     * Yanittaki token sayilarini biriktirir.
     *
     * Gunluk maliyeti tahmin etmek yerine olcmek icin: Gemini her
     * yanitta usageMetadata ile kac token harcandigini bildiriyor.
     *
     * @param array<string,mixed> $veri
     */
    private function kullanimiTopla(array $veri): void
    {
        $olcum = $veri['usageMetadata'] ?? [];

        if (!is_array($olcum)) {
            return;
        }

        // Dusunme tokenlari ayri alanda gelir ama cikti gibi faturalanir;
        // saymazsak maliyet oldugundan az gorunur.
        $cikti = (int) ($olcum['candidatesTokenCount'] ?? 0)
               + (int) ($olcum['thoughtsTokenCount'] ?? 0);

        $this->kullanim['istek']++;
        $this->kullanim['girdi'] += (int) ($olcum['promptTokenCount'] ?? 0);
        $this->kullanim['cikti'] += $cikti;
    }

    /**
     * Çalışma boyunca harcanan token sayıları.
     *
     * @return array{girdi:int,cikti:int,istek:int}
     */
    public function kullanim(): array
    {
        return $this->kullanim;
    }

    /**
     * Google'in onerdigi bekleme suresi (saniye), yoksa null.
     *
     * Hata govdesinde yapisal RetryInfo ya da mesaj icinde
     * "Please retry in 40.4s" bicimi gelir.
     *
     * @param array<string,mixed>|null $veri
     */
    private function onerilenBekleme(?array $veri, string $mesaj): ?int
    {
        if (is_array($veri)) {
            foreach ($veri['error']['details'] ?? [] as $ayrinti) {
                $gecikme = $ayrinti['retryDelay'] ?? null;

                if (is_string($gecikme) && preg_match('/([\d.]+)s/', $gecikme, $m) === 1) {
                    return min(120, (int) ceil((float) $m[1]) + 2);
                }
            }
        }

        if (preg_match('/retry in ([\d.]+)\s*s/i', $mesaj, $m) === 1) {
            return min(120, (int) ceil((float) $m[1]) + 2);
        }

        return null;
    }

    /** Gunluk kota tukenmesi mi, yoksa dakikalik hiz siniri mi? */
    private function gunlukKotaBitti(string $mesaj): bool
    {
        // Dakikalik sinirda Google kisa bir bekleme onerir; gunluk
        // kotada "per day" ifadesi gecer ya da bekleme onerilmez.
        if (stripos($mesaj, 'per day') !== false || stripos($mesaj, 'PerDay') !== false) {
            return true;
        }

        return stripos($mesaj, 'quota') !== false
            && preg_match('/retry in ([\d.]+)\s*s/i', $mesaj) !== 1;
    }

    /**
     * @param array{baslik:string,ozet:string,baglanti:string} $aday
     */
    private function istemHazirla(
        array $aday,
        string $sayfaMetni,
        string $kaynakAdi,
        string $kaynakTuru,
        array $kategoriler,
    ): string {
        $turAciklama = $kaynakTuru === 'resmi'
            ? 'Bu birincil/resmî bir kaynaktır.'
            : 'Bu ikincil bir haber kaynağıdır.';

        $metin = $sayfaMetni !== ''
            ? $sayfaMetni
            : '(Sayfa metni alınamadı; yalnızca başlık ve özet mevcut.)';

        $grupSatirlari = [];

        foreach ($kategoriler as $kategori) {
            $aciklama = trim((string) ($kategori['aciklama'] ?? ''));
            $grupSatirlari[] = '- ' . $kategori['slug'] . ' (' . $kategori['ad'] . ')'
                . ($aciklama !== '' ? ': ' . $aciklama : '');
        }

        $gruplar = implode("\n", $grupSatirlari);

        return <<<METIN
        Konu grupları (kategori alanına bunlardan birinin slug'ını yaz):
        {$gruplar}

        Kaynak: {$kaynakAdi}
        {$turAciklama}
        Adres: {$aday['baglanti']}

        Besleme başlığı:
        {$aday['baslik']}

        Besleme özeti:
        {$aday['ozet']}

        Sayfa metni:
        ---
        {$metin}
        ---

        Bu haberi değerlendir; yayın politikasına uygunsa Valentra için
        tarafsız bir özet yaz.
        METIN;
    }
}
