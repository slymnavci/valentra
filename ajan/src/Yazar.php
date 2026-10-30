<?php
declare(strict_types=1);

namespace Valentra\Ajan;

require_once __DIR__ . '/OpenAiErisimYokException.php';
require_once __DIR__ . '/OpenAiIstemci.php';
require_once __DIR__ . '/ClaudeErisimYokException.php';
require_once __DIR__ . '/ClaudeIstemci.php';

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
     * Haber toplamanin Gemini modeli. GEMINI_MODEL ortam degiskeniyle
     * degistirilebilir; model adi degisirse kod duzenlemeye gerek kalmaz.
     *
     * 23.10.2026 (site sahibinin karari): haberler once OpenAI (GPT-6
     * Luna) ile yaziliyor, Gemini YEDEK. Yedek olarak eskisi gibi 3.8
     * Flash: yalnizca Luna dustugunde calistigi icin maliyeti kucuk,
     * yazim kalitesi bilinen model. OpenAI anahtari yoksa butun is bunda.
     */
    private const VARSAYILAN_MODEL = 'gemini-3.8-flash';

    /**
     * Kose yazilarinin modeli; GEMINI_KOSE_MODEL ile degistirilebilir.
     * Gundem secip yorumlu, akici yazi uretmek daha guclu yazim istiyor;
     * gunde birkac yazi oldugu icin maliyeti kucuk.
     */
    private const KOSE_VARSAYILAN_MODEL = 'gemini-3.8-flash';

    /**
     * Aday basina modele giden sayfa metni siniri (karakter).
     *
     * 3.000'den yukseltildi: haberin ayrintisi (yururluk tarihi, gecis
     * hukumleri, tutar tablolari) cogu kaynakta metnin asagisinda
     * duruyor ve eski sinirla modele hic ulasmiyordu.
     */
    private const SAYFA_METNI_SINIRI = 14000;

    /** Ek kaynak (ayni olayi veren baska yayinci) basina metin siniri. */
    private const EK_KAYNAK_METNI_SINIRI = 6000;

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
     * 30.10.2026, site sahibi: "haberleri yaparken her zaman tum
     * detaylari alalim; haberin asli kaynakta kalsin ama kendi ozgun
     * cumlelerinle yeniden yorumla". ÖZGÜN YAZIM ve TÜM AYRINTILAR
     * kurallari bunun karsiligi; uzunluk tavani 8'den 10 paragrafa.
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
           h) Dedikodu ve özel hayat (ilişki, ayrılık, evlilik, kavga,
              aile meselesi, görünüş, tatil, sosyal medya atışması), tık
              tuzağı, burç, şans oyunu, reklam ve tanıtım içeriği,
              içeriği olmayan "şu yayına katılacak" duyuruları.
              ÖLÇÜLÜ MAGAZİN YAYIMLANIR: sanatçıların kendi açıklamaları
              ve röportajları, yeni albüm, şarkı, dizi, film, kitap,
              konser ve turneleri, ödüller, sanat hayatına dair
              gelişmeler. Bunlar "magazin" grubuna yazılır.
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
           - ÖZGÜN YAZIM: Kaynak metni yalnızca anlamak için okursun.
             ASLA cümle kopyalamazsın ve cümle cümle eş anlamlı
             kelimelerle değiştirerek de yazmazsın: haberi anla, sonra
             kendi kurgunla, kendi cümlelerinle baştan yaz (sıralamayı,
             cümle yapısını ve girişi kendin kur). Haberin aslı kaynakta
             kalır; sayfada kaynağa bağlantı verilir.
           - TÜM AYRINTILAR: Okur haberi bizden eksiksiz öğrenmeli.
             Kaynaktaki bütün somut bilgileri al: kim, ne, nerede, ne
             zaman, nasıl, neden; bütün rakamlar, oranlar, tarihler,
             yer ve kişi adları, kurumlar, açıklamalarda söylenenler
             (kendi cümlenle, kime ait olduğunu belirterek), olayın
             arka planı, gerekçesi ve sonraki adımlar. Haberle ilgili
             hiçbir olguyu atlama. Doğrudan alıntı gerekiyorsa tırnak
             içinde, kısa ve kimin söylediğiyle ver; uzun alıntı yapma.
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
           - UZUNLUK KAYNAĞA GÖRE: kaynak metin(ler) zengin ve
             ayrıntılıysa 5-10 paragraf yaz; ayrıntıyı (rakam, tarih, kim
             ne dedi, arka plan, sonraki adım) atma. Kaynak kısaysa 3
             paragrafta kal. Uzunluk için ASLA boş cümle, tekrar ya da
             kaynakta olmayan bilgi ekleme. İlk paragraf haberin özünü
             tek başına versin: ne oldu, kim, nerede, ne zaman.
           - EK KAYNAKLAR: adayda "Ek kaynaklar" varsa bunlar aynı olayı
             veren başka yayıncılardır. Haberi hepsini birlikte okuyarak
             yaz; birinde olmayan ayrıntıyı ötekinden al. Kaynaklar bir
             rakamda ya da olguda çelişiyorsa resmî/ajans kaynağını
             esas al ya da farkı açıkça belirt ("X'e göre ..., Y ise ...
             bildirdi") ve ajan notuna yaz. Yalnızca BİR kaynakta geçen
             iddiayı kesin bilgi gibi yazma, kime ait olduğunu söyle.
           - Paragrafları BOŞ SATIRLA ayır.
           - Başlık en fazla 90 karakter, olguyu bildirsin; soru ya da
             merak uyandırma kalıbı kullanma.
           - Özet (spot) tek cümle, en fazla 200 karakter.
           - Etiketler: 2-4 adet, konu terimleri (örnek: TBMM, Süper Lig,
             enflasyon, yapay zekâ). Kişi adlarını etiket yapma.

           Analiz alanları (analiz_degisen, analiz_etkilenen,
           analiz_zaman, analiz_islem, isletme_etkisi, uygulama_ornegi,
           resmi_dayanak) artık kullanılmıyor: HEPSİNİ BOŞ BIRAK.

        NEDEN ÖNEMLİ (neden_onemli): Haber metninden AYRI, 2-3 cümlelik
        bir değerlendirme: bu gelişme okur için ne anlama geliyor, kimi
        nasıl etkiliyor, hangi sürecin parçası. Haberin özetini tekrar
        etme; metinde olmayan bir açı ekle. KURALLAR:
           - Yalnızca kaynak metindeki olgulara dayan. Yeni rakam, tarih,
             isim, iddia ya da tahmin EKLEME; "artacak, düşecek" gibi
             kehanette bulunma.
           - Taraf tutma, siyasi değerlendirme yapma, kimseyi övme ya da
             eleştirme.
           - Kaynak bunu söylemeye yetmiyorsa (çok kısa haber, sonuç
             maçı, magazin) BOŞ BIRAK. Boş bırakmak, zorlama bir cümleden
             iyidir.
           - Yemek tariflerinde her zaman boş.

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
        - SPOR: Galatasaray, Fenerbahçe, Beşiktaş ve Trabzonspor'un
          maçları (sonuç, maç öncesi, kadro) ve önemli gelişmeleri
          (transfer, teknik direktör, sakatlık) 70-85; okurun en çok
          ilgilendiği spor haberleri bunlardır. Milli takımlar 70-85.
          Dünya sporunda büyük olaylar (Şampiyonlar Ligi, büyük
          Avrupa ligleri, NBA ve EuroLeague'de öne çıkan gelişmeler,
          büyük turnuvalar) 55-70. Diğer Süper Lig kulüpleri 55-70.
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
        - Sanatçıların açıklaması, röportajı, yeni işi, konseri, ödülü
          (ölçülü magazin) -> "magazin". Sergi, tiyatro, edebiyat,
          kültürel miras ve kurum haberi -> "kultur-sanat".
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
            'icerik'      => ['type' => 'string',  'description' => 'Tarafsız, özgün cümlelerle yeniden yazılmış haber metni: kaynaktaki bütün ayrıntılar (rakam, tarih, isim, açıklama, arka plan); kaynak zenginse 5-10, kısaysa 3 paragraf; paragraflar boş satırla ayrılmış; kaynakta olmayan bilgi yok. Yemek tarifinde: giriş, Malzemeler, Yapılışı (bkz. YEMEK TARİFLERİ)'],
            'etiketler'   => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
                'description' => '2-4 konu terimi (ör. TBMM, Süper Lig, enflasyon, yapay zekâ); kişi adı değil',
            ],
            'kategori'    => ['type' => 'string',  'description' => 'Verilen konu grubu listesinden TAM olarak bir slug'],
            'guven_skoru' => ['type' => 'integer', 'description' => '0-100 arası güven'],
            'onem'        => ['type' => 'integer', 'description' => '0-100 arası önem puanı: haberin kamuoyu için ne kadar önemli olduğu (bkz. ÖNEM PUANI)'],
            'ajan_notu'   => ['type' => 'string',  'description' => 'Onaylayacak editöre tek cümlelik not'],
            'neden_onemli'=> ['type' => 'string',  'description' => '2-3 cümle: gelişmenin okur için anlamı, yalnızca kaynaktaki olgulardan (bkz. NEDEN ÖNEMLİ). Yemek tarifinde boş.'],

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
            'etiketler', 'kategori', 'guven_skoru', 'onem', 'ajan_notu', 'neden_onemli',
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
            'icerik'      => ['type' => 'string',  'description' => 'Tarafsız, özgün cümlelerle yeniden yazılmış haber metni: kaynaktaki bütün ayrıntılar (rakam, tarih, isim, açıklama, arka plan); kaynak zenginse 5-10, kısaysa 3 paragraf; paragraflar boş satırla ayrılmış; kaynakta olmayan bilgi yok. Yemek tarifinde: giriş, Malzemeler, Yapılışı (bkz. YEMEK TARİFLERİ)'],
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

    /** Tek Gemini isteginin suresi (saniye); bkz. sureSiniri(). */
    private int $istekZamanAsimi = 90;

    /** @var list<int>|null Yeniden deneme beklemeleri; null: varsayilan liste */
    private ?array $beklemeler = null;

    /** Bu zamandan (Unix) sonra yeni istek ya da yeniden deneme yapilmaz. */
    private ?int $sonZaman = null;

    /** Gecici hatada denenecek ikinci Gemini modeli; bos: yok (bkz. yedekModel()). */
    private string $yedekModel = '';

    /** Gemini once, Claude/Luna yedek mi (bkz. geminiOnce())? */
    private bool $geminiOnce = false;

    /** Son Gemini hatasi gecici miydi (5xx, zaman asimi)? */
    private bool $sonGeciciHata = false;

    /**
     * Istek suresini ve yeniden denemeleri sinirlar.
     *
     * Kose yazisi adimi 26.10.2026'da 44 dakika surdu ve isin 45 dakikalik
     * sinirina takilip iptal edildi; yazilan hicbir yazi siteye gitmedi.
     * Sebep: uzun bir yazinin uretimi 90 saniyelik istek suresini
     * asabiliyor, zaman asimi ise "gecici ag hatasi" sayilip artan
     * beklemelerle alti kez deneniyordu (tek istek 11 dakikaya kadar).
     * Kose ajani istege daha uzun sure, ama daha az deneme veriyor; son
     * zamandan sonra yeni istek acilmiyor ve calisma butcesi asilmiyor.
     *
     * @param list<int> $beklemeler
     */
    public function sureSiniri(int $istekZamanAsimi, array $beklemeler, ?int $sonZaman = null): void
    {
        $this->istekZamanAsimi = max(10, $istekZamanAsimi);
        $this->beklemeler      = array_values(array_map('intval', $beklemeler));
        $this->sonZaman        = $sonZaman;
    }

    /** Kose yazisi ajaninin modeli: GEMINI_KOSE_MODEL, yoksa guclu varsayilan. */
    public static function koseModeli(): string
    {
        return self::ortamModeli('GEMINI_KOSE_MODEL', self::KOSE_VARSAYILAN_MODEL);
    }

    /**
     * Ortam degiskenindeki model adi; bos ya da gecersizse varsayilan.
     *
     * Model adi adrese yaziliyor (models/<ad>:generateContent). Gecersiz
     * bir ad ("gemini model", sonda bosluk, Turkce karakter) butun
     * istekleri "URL rejected: Malformed input" ile dusuruyordu
     * (23.10.2026: 72 adayin 72'si hata, 0 haber). Ad kirpiliyor,
     * "models/" oneki atiliyor; gecersizse varsayilana donuluyor ve
     * hangi ayarin duzeltilecegi gunluge yaziliyor.
     */
    private static function ortamModeli(string $degisken, string $varsayilan): string
    {
        $ad = preg_replace('#^models/#', '', trim((string) (getenv($degisken) ?: ''))) ?? '';

        if ($ad === '') {
            return $varsayilan;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,99}$/i', $ad)) {
            fwrite(STDERR, '[uyari] ' . $degisken . ' gecersiz ("' . mb_substr($ad, 0, 40) . '"); varsayilan '
                . $varsayilan . ' kullaniliyor. GitHub -> Settings -> Secrets -> ' . $degisken
                . ' degerini duzeltin ya da silin.' . PHP_EOL);

            return $varsayilan;
        }

        return $ad;
    }

    /**
     * Son istegi cevaplayan model (ornegin "gpt-6-luna" ya da
     * "gemini-3.8-flash"). Site guven esigini buna gore seciyor: Luna
     * guveni Gemini'den temkinli puanliyor (bkz. includes/haberler.php,
     * haber_guven_esigi).
     */
    public function sonYanitlayan(): string
    {
        return $this->sonYanitlayan;
    }

    /** Kullanilan model adi (gunluge yazmak icin). */
    public function model(): string
    {
        $sira = array_filter([$this->claude?->model(), $this->birincil?->model()]);

        return $sira !== []
            ? implode(' → ', $sira) . ' (yedek: ' . $this->model . ')'
            : $this->model;
    }

    /*
     * BIRINCIL MODEL: OpenAI (site sahibinin karari, 23.10.2026: "Luna
     * ile calissin, basarisiz olursa Gemini ile devam etsin").
     *
     * OpenAI anahtari verilmisse her istek once ona gidiyor; hata olursa
     * (ag, zaman asimi, kesik/bozuk yanit, bos sonuc) AYNI istek Gemini'ye
     * gidiyor. Anahtar gecersiz ya da kredi bittiyse ya da ust uste
     * BIRINCIL_HATA_SINIRI istek dustuyse OpenAI bu calismada bir daha
     * denenmiyor; her istekte once onu bekleyip zaman kaybetmeyelim.
     * Anahtar yoksa her sey eskisi gibi yalnizca Gemini.
     */
    private const OPENAI_VARSAYILAN_MODEL = 'gpt-6-luna';
    private const BIRINCIL_HATA_SINIRI    = 3;

    private ?OpenAiIstemci $birincil = null;
    private int $birincilArdisikHata = 0;

    /** @var array{birincil:int,yedek:int} Hangi saglayicinin kac istegi cevapladigi */
    private array $saglayiciSayaci = ['birincil' => 0, 'yedek' => 0];

    /** @var array{tur:string,mesaj:string}|null OpenAI'nin bu calismada neden kapandigi */
    private ?array $birincilErisimSorunu = null;

    /** Son istegi cevaplayan model; haberle birlikte siteye gidiyor. */
    private string $sonYanitlayan = '';

    /*
     * EN ONDE CLAUDE (site sahibinin karari, 23.10.2026: "once Haiku 5.5
     * baksin, sonra Luna, en son bunlar da yapamiyorsa Gemini").
     * ANTHROPIC_API_KEY verilmisse her istek once Claude'a gidiyor;
     * olmazsa ayni istek Luna'ya, o da olmazsa Gemini'ye. Kapanma kurali
     * Luna'daki gibi: anahtar/kredi sorunu ya da ust uste
     * BIRINCIL_HATA_SINIRI hata.
     */
    private const CLAUDE_VARSAYILAN_MODEL = 'claude-haiku-5-5';

    private ?ClaudeIstemci $claude = null;
    private bool $claudeKapali = false;
    private int $claudeArdisikHata = 0;
    private int $claudeSayac = 0;

    /** @var array{tur:string,mesaj:string}|null Claude'un bu calismada neden kapandigi */
    private ?array $claudeErisimSorunu = null;

    /** Claude'un son gecici hatasi (ozete yazmak icin). */
    private string $claudeSonHata = '';

    public function __construct(
        private readonly string $apiKey,
        string $model = '',
        string $openAiAnahtari = '',
        string $claudeAnahtari = '',
    ) {
        // Model verilmediyse haber modeli (GEMINI_MODEL, yoksa varsayilan).
        $this->model = $model !== '' ? $model : self::ortamModeli('GEMINI_MODEL', self::VARSAYILAN_MODEL);

        // Kurulamazsa (kutuphane eksik vb.) calisma dusmesin: Claude atlanir.
        if (trim($claudeAnahtari) !== '') {
            try {
                $this->claude = new ClaudeIstemci(
                    trim($claudeAnahtari),
                    self::ortamModeli('CLAUDE_MODEL', self::CLAUDE_VARSAYILAN_MODEL)
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, '[uyari] Claude istemcisi kurulamadı, atlanıyor: ' . $e->getMessage() . PHP_EOL);
            }
        }

        if (trim($openAiAnahtari) !== '') {
            $this->birincil = new OpenAiIstemci(
                trim($openAiAnahtari),
                self::ortamModeli('OPENAI_MODEL', self::OPENAI_VARSAYILAN_MODEL),
                trim((string) (getenv('OPENAI_API_TABAN') ?: ''))
            );
        }
    }

    /**
     * Önce birincil modele (OpenAI) sorar; olmazsa null döner ve çağıran
     * aynı isteği Gemini'ye gönderir.
     *
     * @param array<string,mixed> $sema
     * @param callable(array<string,mixed>):bool $gecerli yanit kullanilabilir mi
     * @return array<string,mixed>|null
     */
    private function birincildenIste(string $yonerge, string $istem, array $sema, int $enFazlaToken, callable $gecerli): ?array
    {
        $claudeVeri = $this->claudedenIste($yonerge, $istem, $sema, $enFazlaToken, $gecerli);

        if ($claudeVeri !== null) {
            return $claudeVeri;
        }

        if ($this->birincil === null || $this->birincilKapali) {
            return null;
        }

        try {
            $veri = $this->birincil->jsonIstek($yonerge, $istem, $sema, $enFazlaToken);

            if (!$gecerli($veri)) {
                throw new \RuntimeException('yanıt beklenen alanları içermiyor');
            }

            $this->birincilArdisikHata = 0;
            $this->saglayiciSayaci['birincil']++;
            $this->sonYanitlayan = $this->birincil->model();

            return $veri;
        } catch (OpenAiErisimYokException $e) {
            $this->birincilErisimSorunu = ['tur' => $e->tur, 'mesaj' => $e->getMessage()];
            $this->birincilKapat('erişim yok (' . $e->getMessage() . ')');
        } catch (\Throwable $e) {
            $this->birincilArdisikHata++;
            fwrite(STDERR, '[' . date('H:i:s') . '] ' . $this->birincil->model() . ' başarısız, Gemini ile devam: '
                . mb_substr($e->getMessage(), 0, 300, 'UTF-8') . PHP_EOL);

            if ($this->birincilArdisikHata >= self::BIRINCIL_HATA_SINIRI) {
                $this->birincilKapat(self::BIRINCIL_HATA_SINIRI . ' istek üst üste düştü');
            }
        }

        return null;
    }

    /**
     * Zincirin ilk halkasi: Claude (Haiku 5.5). Olmazsa null; cagiran
     * Luna'ya, o da olmazsa Gemini'ye gecer.
     *
     * @param array<string,mixed> $sema
     * @param callable(array<string,mixed>):bool $gecerli
     * @return array<string,mixed>|null
     */
    private function claudedenIste(string $yonerge, string $istem, array $sema, int $enFazlaToken, callable $gecerli): ?array
    {
        if ($this->claude === null || $this->claudeKapali) {
            return null;
        }

        try {
            $veri = $this->claude->jsonIstek($yonerge, $istem, $sema, $enFazlaToken);

            if (!$gecerli($veri)) {
                throw new \RuntimeException('yanıt beklenen alanları içermiyor');
            }

            $this->claudeArdisikHata = 0;
            $this->claudeSayac++;
            $this->sonYanitlayan = $this->claude->model();

            return $veri;
        } catch (ClaudeErisimYokException $e) {
            $this->claudeErisimSorunu = ['tur' => $e->tur, 'mesaj' => $e->getMessage()];
            $this->claudeKapat('erişim yok (' . $e->getMessage() . ')');
        } catch (\Throwable $e) {
            $this->claudeArdisikHata++;
            $this->claudeSonHata = $e->getMessage();
            fwrite(STDERR, '[' . date('H:i:s') . '] ' . $this->claude->model() . ' başarısız, sıradaki modelle devam: '
                . mb_substr($e->getMessage(), 0, 300, 'UTF-8') . PHP_EOL);

            if ($this->claudeArdisikHata >= self::BIRINCIL_HATA_SINIRI) {
                $this->claudeKapat(self::BIRINCIL_HATA_SINIRI . ' istek üst üste düştü');
            }
        }

        return null;
    }

    private function claudeKapat(string $neden): void
    {
        if ($this->claude === null) {
            return;
        }

        fwrite(STDERR, '[' . date('H:i:s') . '] ' . $this->claude->model() . ' bu çalışmada kapatıldı: '
            . mb_substr($neden, 0, 300, 'UTF-8') . '. Kalan istekler sıradaki modelle.' . PHP_EOL);

        $this->claudeKapali = true;
    }

    /**
     * Claude'un bu calismadaki durumu (birincilDurumu ile ayni bicim);
     * anahtar yoksa null.
     *
     * @return array{model:string,durum:string,mesaj:string}|null
     */
    public function claudeDurumu(): ?array
    {
        if ($this->claude === null) {
            return null;
        }

        return [
            'model' => $this->claude->model(),
            'durum' => $this->claudeErisimSorunu['tur'] ?? ($this->claudeSayac > 0 ? 'tamam' : 'bilinmiyor'),
            'mesaj' => mb_substr($this->claudeErisimSorunu['mesaj'] ?? $this->claudeSonHata, 0, 300, 'UTF-8'),
        ];
    }

    private function birincilKapat(string $neden): void
    {
        if ($this->birincil === null) {
            return;
        }

        fwrite(STDERR, '[' . date('H:i:s') . '] ' . $this->birincil->model() . ' bu çalışmada kapatıldı: '
            . mb_substr($neden, 0, 300, 'UTF-8') . '. Kalan istekler Gemini ile.' . PHP_EOL);

        // Kullanimi kaybetmemek icin istemci tutuluyor; yalnizca kullanilmiyor.
        $this->birincilKapali = true;
    }

    private bool $birincilKapali = false;


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

        $birincil = $this->birincildenIste(self::YONERGE, $istem, self::SEMA, 8000,
            static fn (array $v): bool => array_key_exists('ilgili', $v));

        if ($birincil !== null) {
            return $birincil;
        }

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
        $this->sonGeciciHata = false;

        try {
            $yanit = $this->geminiModelIstegi($govde, $this->model);
            $this->sonYanitlayan = $this->model;

            return $yanit;
        } catch (\RuntimeException $e) {
            if (!$this->sonGeciciHata || $this->yedekModel === '' || $this->yedekModel === $this->model
                || $e instanceof ModelErisimYokException || $e instanceof KotaBittiException) {
                throw $e;
            }

            fwrite(STDERR, '[uyari] ' . $this->model . ' yanit vermedi (' . mb_substr($e->getMessage(), 0, 160)
                . '); yedek model ' . $this->yedekModel . ' deneniyor.' . PHP_EOL);
        }

        $yanit = $this->geminiModelIstegi($govde, $this->yedekModel);
        $this->sonYanitlayan = $this->yedekModel;

        return $yanit;
    }

    /**
     * Gecici hatada (5xx, zaman asimi) denenecek ikinci Gemini modeli.
     *
     * Kose yazilari 26.10.2026'da yazilamadi: kose modeli butun yazarlarda
     * "HTTP 503: high demand" dondu ve yedek yoktu (haber toplamada OpenAI
     * ve Claude once geliyor, Gemini yedek). Yogunluk genellikle tek
     * modelde oluyor; ayni istek haber modeline gidiyor.
     */
    public function yedekModel(string $model): void
    {
        $this->yedekModel = $model;
    }

    /**
     * Gemini once, Claude ve Luna yedek (kose yazisi).
     *
     * Haber toplamada sira Claude -> Luna -> Gemini. Kose yazilari site
     * sahibinin karariyla Gemini'de (23.10.2026); 26.10.2026'da iki Gemini
     * modeli de "503 high demand" dondu ve hic yazi cikmadi. Site sahibinin
     * karari (26.10.2026): Gemini yine once, gecici hatada Claude ya da
     * Luna yaziyor. Kredi ve kota hatalari yedeklenmiyor.
     */
    public function geminiOnce(bool $acik = true): void
    {
        $this->geminiOnce = $acik;
    }

    /** Haber modeli (GEMINI_MODEL, yoksa varsayilan); kose yazisinin yedegi. */
    public static function haberModeli(): string
    {
        return self::ortamModeli('GEMINI_MODEL', self::VARSAYILAN_MODEL);
    }

    /**
     * @param array<string,mixed> $govde
     * @return array<string,mixed>
     */
    private function geminiModelIstegi(array $govde, string $model): array
    {
        $adres = self::API . $model . ':generateContent';
        $json  = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new \RuntimeException('Gemini isteği JSON olarak hazırlanamadı.');
        }

        $sonMesaj = '';

        // Ucretsiz katmanda 503 ("yogun talep") sik gorulur; birkac
        // saniyelik bekleme yetmez. Artan bekleme ile daha uzun israr
        // edilir: 4, 10, 20, 35, 60 saniye.
        $beklemeler = $this->beklemeler ?? [4, 10, 20, 35, 60];
        $enFazlaDeneme = count($beklemeler) + 1;

        for ($deneme = 1; $deneme <= $enFazlaDeneme; $deneme++) {
            // Calisma butcesi (bkz. sureSiniri): kalan sure yeni bir istege
            // yetmiyorsa acilmiyor; istek de butceyi asacak kadar surmuyor.
            $zamanAsimi = $this->istekZamanAsimi;

            if ($this->sonZaman !== null) {
                $kalan = $this->sonZaman - time();

                if ($kalan < 30) {
                    throw new \RuntimeException('çalışma için ayrılan süre doldu'
                        . ($sonMesaj !== '' ? ' (son hata: ' . $sonMesaj . ')' : ''));
                }

                $zamanAsimi = min($zamanAsimi, $kalan);
            }

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
                CURLOPT_TIMEOUT        => $zamanAsimi,
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

                $this->sonGeciciHata = in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT], true);

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

            // Kredi bitti / anahtar gecersiz: beklemekle duzelmez,
            // yonetici bir sey yapmali (bkz. ModelErisimYokException).
            $erisimYok = ModelErisimYokException::aciklama($kod, $sonMesaj);

            if ($erisimYok !== null) {
                throw new ModelErisimYokException($erisimYok);
            }

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

            $this->sonGeciciHata = $kod >= 500;

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
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string,kapsam?:int,ekKaynaklar?:list<array{kaynakAdi:string,baglanti:string,metin:string}>}> $adaylar
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
        $gecerli = fn (array $v): bool => $this->sonuclariAyikla($v, $adayAdedi, $zorunluAlan) !== [];

        // Gemini once (kose yazisi): Claude ve Luna yalnizca Gemini gecici
        // hatayla dustugunde (bkz. geminiOnce()).
        if (!$this->geminiOnce) {
            $birincil = $this->birincildenIste($yonerge, $istem, $sema, $enFazlaToken, $gecerli);

            if ($birincil !== null) {
                return $this->sonuclariAyikla($birincil, $adayAdedi, $zorunluAlan);
            }
        }

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

        try {
            $yanit = $this->geminiIstegi($govde);
        } catch (\RuntimeException $e) {
            if (!$this->geminiOnce || !$this->sonGeciciHata
                || $e instanceof ModelErisimYokException || $e instanceof KotaBittiException) {
                throw $e;
            }

            $birincil = $this->birincildenIste($yonerge, $istem, $sema, $enFazlaToken, $gecerli);

            if ($birincil === null) {
                throw $e;
            }

            fwrite(STDERR, '[uyari] Gemini yoğun; yanıtı ' . $this->sonYanitlayan . ' verdi.' . PHP_EOL);

            return $this->sonuclariAyikla($birincil, $adayAdedi, $zorunluAlan);
        }

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

            return $this->sonuclariAyikla($veri, $adayAdedi, $zorunluAlan);
        }

        return [];
    }

    /**
     * Yanıttaki "sonuclar" dizisini 0 tabanlı sıraya göre ayıklar.
     * Gemini ve OpenAI yanıtı aynı denetimden geçiyor.
     *
     * @param array<string,mixed> $veri
     * @return array<int,array<string,mixed>>
     */
    private function sonuclariAyikla(array $veri, int $adayAdedi, string $zorunluAlan): array
    {
        if (!isset($veri['sonuclar']) || !is_array($veri['sonuclar'])) {
            return [];
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

    /**
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string,kapsam?:int,ekKaynaklar?:list<array{kaynakAdi:string,baglanti:string,metin:string}>}> $adaylar
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

            // Ayni olayi veren baska yayincilarin metni (ajan/topla.php,
            // EK_KAYNAK_TAVANI); her biri ayri sinirla.
            $ekler = '';

            foreach ($aday['ekKaynaklar'] ?? [] as $i => $ek) {
                $ekler .= "\nEk kaynak " . ($i + 1) . ': ' . $ek['kaynakAdi'] . ' (' . $ek['baglanti'] . ")\n---\n"
                    . mb_substr((string) $ek['metin'], 0, self::EK_KAYNAK_METNI_SINIRI, 'UTF-8') . "\n---";
            }

            if ($ekler !== '') {
                $ekler = "\nEk kaynaklar (aynı olay, başka yayıncılar):" . $ekler;
            }

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
            ---{$ekler}
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
        $toplam = $this->kullanim;

        foreach ([$this->claude, $this->birincil] as $istemci) {
            foreach ($istemci?->kullanim() ?? [] as $alan => $deger) {
                $toplam[$alan] += $deger;
            }
        }

        return $toplam;
    }

    /**
     * Sağlayıcı dökümü, zincir sırasıyla (Claude, OpenAI, Gemini): kaç
     * isteği yanıtladılar, kaç token harcadılar. Yalnızca Gemini varsa boş.
     *
     * @return list<array{model:string,istek:int,kullanim:array{girdi:int,cikti:int,istek:int}}>
     */
    public function saglayicilar(): array
    {
        if ($this->claude === null && $this->birincil === null) {
            return [];
        }

        $liste = [];

        if ($this->claude !== null) {
            $liste[] = ['model' => $this->claude->model(), 'istek' => $this->claudeSayac, 'kullanim' => $this->claude->kullanim()];
        }

        if ($this->birincil !== null) {
            $liste[] = ['model' => $this->birincil->model(), 'istek' => $this->saglayiciSayaci['birincil'], 'kullanim' => $this->birincil->kullanim()];
        }

        $liste[] = ['model' => $this->model, 'istek' => $this->kullanim['istek'], 'kullanim' => $this->kullanim];

        return $liste;
    }

    /**
     * OpenAI'nin bu calismadaki durumu; panel uyarisi icin siteye gidiyor
     * (site sahibinin istegi, 23.10.2026: "kredi biterse bana haber ver").
     *
     *   kredi     kredi/kota bitti: OpenAI'ye kredi yuklenmeli
     *   anahtar   anahtar gecersiz ya da yetkisiz
     *   tamam     en az bir istegi cevapladi
     *   bilinmiyor hic istek atilmadi ya da gecici hatalarla dustu
     *
     * OpenAI anahtari yoksa null.
     *
     * @return array{model:string,durum:string,mesaj:string}|null
     */
    public function birincilDurumu(): ?array
    {
        if ($this->birincil === null) {
            return null;
        }

        $durum = $this->birincilErisimSorunu['tur']
            ?? ($this->saglayiciSayaci['birincil'] > 0 ? 'tamam' : 'bilinmiyor');

        return [
            'model' => $this->birincil->model(),
            'durum' => $durum,
            'mesaj' => mb_substr($this->birincilErisimSorunu['mesaj'] ?? '', 0, 300, 'UTF-8'),
        ];
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
