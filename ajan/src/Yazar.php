<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Aday haberi Gemini'ye verip iki soruyu birden yanıtlatır:
 * vergi/mevzuat ya da ekonomi gündemine giriyor mu, ve giriyorsa haber
 * metni nasıl yazılmalı.
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

    private const YONERGE = <<<'METIN'
        Sen Valentra adlı vergi haberleri sitesinin editör yardımcısısın.
        Valentra vergi, muhasebe ve finans yayınıdır; okuyucuları mali
        müşavirler, muhasebeciler, şirketlerin mali işler birimleri ve
        mükelleflerdir.

        Sana bir haber kaynağından başlık, özet ve sayfa metni verilecek.
        İki iş yapacaksın:

        1) SINIFLANDIR. Haber iki başlıktan birine giriyor mu?

           A) VERGİ VE MALİ MEVZUAT (asıl odak). Vergi kanunu
              değişiklikleri, tebliğ ve sirkülerler, oran/had/tutar
              güncellemeleri, beyanname süreçleri, e-belge düzenlemeleri,
              vergi cezaları ve incelemeleri, yapılandırma ve af
              düzenlemeleri, muhasebe ve denetim yükümlülükleri.

           B) EKONOMİ GÜNDEMİ. Site bir ekonomi sayfası gözüyle de
              bakar; yalnızca muhasebe değildir. Şunların hepsi bu gruba
              girer:
              - Makro veri ve kararlar: enflasyon, faiz kararları, büyüme,
                istihdam, bütçe ve kamu maliyesi, cari denge, asgari
                ücret, memur ve emekli zamları.
              - Hükümetin ekonomi politikası: alınan ya da alınması
                planlanan önlemler (enflasyonla mücadele, tasarruf,
                teşvik ve destek paketleri, Orta Vadeli Program), bunların
                beklenen etkileri.
              - Piyasalar: borsa, döviz, altın, petrol ve emtia, tahvil,
                kredi notu ve CDS; piyasayı hareket ettiren gelişmeler.
              - Beklentiler: piyasa katılımcıları anketi, kurum ve banka
                tahminleri, enflasyon ve kur beklentileri.
              - Dünya ekonomisi: Fed ve ECB kararları, ABD-Çin ticaret
                ilişkileri ve gümrük tarifeleri, petrol ve enerji
                fiyatları, büyük ekonomilerin verileri — Türkiye'ye ya da
                küresel piyasalara etkisi olan gelişmeler.
              - Şirketler ve yatırımcılar: büyük şirketlerin sonuçları,
                halka arz, birleşme ve satın almalar, iflas ve
                konkordatolar, yatırımcıları geniş ölçekte etkileyen
                olaylar (ör. yatırım fonlarının tasfiyesi).

           Şunlar İKİSİ DE DEĞİLDİR ve ilgili sayılmaz: ekonomik boyutu
           olmayan siyaset, magazin, spor, kültür-sanat, asayiş, hava
           durumu, tanıtım ve reklam içerikleri, içeriği olmayan
           "şu yayına katılacak" türü duyurular.

           YAZAR MAKALESİ HABER DEĞİLDİR. Bir kişinin imzasını taşıyan
           makale, köşe yazısı, görüş yazısı ya da uzman değerlendirmesi
           (sayfada yazar adı, unvanı ya da fotoğrafı var; "yazarlar",
           "makaleler", "köşe yazıları" bölümünden geliyor; "bu yazımızda
           ... ele alacağız" diye başlıyor) başkasının özgün emeğidir.
           Valentra onu yeniden yazıp kendi haberi gibi yayımlamaz —
           konusu vergiyle ne kadar ilgili olursa olsun. ilgili=false
           yap ve red_nedeni'ni "yazar makalesi: " ile başlat. Valentra'nın
           kendi yorum yazıları ayrı bir bölümde, kendi kaleminden yazılıyor.
           Bir kurumun resmî duyurusu ya da haber sitesinin muhabir
           haberi yazar makalesi sayılmaz.

           Emin değilsen ilgili sayma. B grubunda ölçüt SOMUTLUKTUR:
           - Beklenti ve tahmin haberini, kaynağı belliyse (kurum, anket,
             adı verilen banka ya da ekonomist) ve somut bir rakam ya da
             gerekçe içeriyorsa al. Kaynaksız, tık tuzağı başlıklı
             tahminleri ("şok zam geliyor!") alma.
           - Toplantı ve görüşme haberini, geniş kesimi etkileyen bir
             sorunla ilgili somut bir süreç ya da rakam içeriyorsa al
             (ör. yüz binlerce yatırımcıyı ilgilendiren fon tasfiyesi
             için ödeme takvimi). Yalnızca "toplantı yapılacak" diyen,
             içeriği olmayan duyuruyu alma.
           - Yabancı ülke verisini, küresel piyasaları ya da Türkiye'yi
             etkileyecek ölçekteyse al (Fed kararı, ABD enflasyonu, Çin
             büyümesi); küçük ve yerel etkili yabancı veriyi alma.

           AYNI OLAY İKİNCİ KEZ YAZILMAZ. İstemde "DAHA ÖNCE
           YAYIMLANANLAR" başlığı altında başlıklar verilmişse, bunlardan
           biriyle AYNI OLAYI anlatan adayı ilgili sayma: ilgili=false yap
           ve red_nedeni'ni "yinelenen: " ile başlat, ardından hangi
           başlıkla çakıştığını yaz.

           Ölçüt kelime benzerliği DEĞİL, olayın aynılığıdır. Şunlar aynı
           olaydır: aynı faiz kararı, aynı tebliğ, aynı kanun değişikliği,
           aynı veri açıklaması — başlıklar bambaşka kelimelerle kurulmuş,
           farklı kaynaktan gelmiş ve farklı ayrıntıyı öne çıkarmış olsa
           bile. "Fed politika faizini yüzde 3,75-4,00 aralığına çıkardı"
           ile "Fed faiz artırdı: ons altın ve tahvilde dalgalanma" AYNI
           olaydır.

           Şunlar aynı olay DEĞİLDİR: aynı konuda ardarda çıkan farklı
           düzenlemeler (iki ayrı KDV tebliği), aynı kurumun farklı
           tarihlerdeki iki ayrı kararı, bir önceki haberin üzerine yeni
           ve somut bir gelişme ekleyen haber. Tereddütte kalırsan
           yinelenen SAYMA; yeni bir haberi kaçırmak, aynı haberi iki kez
           yayımlamaktan daha az zararlıdır ve onay aşaması zaten var.

           AYNI PARTİ İÇİNDE DE GEÇERLİ: aşağıdaki adaylardan ikisi aynı
           olayı anlatıyorsa yalnızca BİRİNİ ilgili say. Kalanı seçerken
           resmî/birincil kaynağı, o da yoksa sayfa metni en ayrıntılı
           olanı tut; diğerlerini "yinelenen: ADAY n ile aynı olay" diye
           ele.

        2b) VALENTRA ANALİZ — dört alan (ardından 2c'deki üç alan).

           Okuyucu mali müşavir, muhasebe çalışanı ya da işletme
           yöneticisi. Haber "ne oldu"yu anlatıyor; bu dört alan
           "bana ne"yi anlatmalı. Her biri kısa: bir-iki cümle.

           analiz_degisen   — Önceki durum neydi, yenisi ne? Rakam
                              varsa ikisini de yaz ("%18'den %20'ye").
           analiz_etkilenen — Hangi mükellef grubu, sektör ya da
                              büyüklük? "Herkes" deme; kaynakta bir
                              kapsam varsa onu yaz.
           analiz_zaman     — Yürürlük tarihi, ilk uygulanacak dönem,
                              geçiş hükmü. Tarih kaynakta geçiyorsa
                              aynen aktar.
           analiz_islem     — NE YAPILMALI: kaynağın ZORUNLU KILDIĞI
                              somut adım (beyanname, bildirim, kayıt,
                              başvuru ve süresi) ve yeni kuralın
                              DOĞRUDAN gerektirdiği kontroller. Birden
                              fazlaysa kontrol listesi yaz: her madde
                              ayrı satırda, "- " ile başlar, en fazla 5
                              madde. Örnek: "- 1 Ekim'den itibaren
                              kesilen faturalarda %20 oranını uygulayın".

           EN ÖNEMLİ KURAL — BİLMEDİĞİNİ YAZMA. Bir alanın karşılığı
           kaynak metinde yoksa o alanı BOŞ BIRAK. Dördünü de
           doldurmak zorunda değilsin; boş alan gösterilmiyor.

           analiz_islem'de bu kural daha da katı: yalnızca kaynağın
           açıkça yüklediği yükümlülüğü yaz. Kaynak bir işlem
           zorunluluğu getirmiyorsa BOŞ BIRAK. "Mükelleflerin durumu
           gözden geçirmesi yerinde olur", "uzmana danışılmalı" gibi
           genel tavsiyeler ÜRETME — bunlar bilgi değil doldurma
           metnidir ve mesleki bir yayında yanlış yönlendirme
           olur.

           Ekonomi haberlerinde (faiz kararı, enflasyon verisi, kur)
           çoğu zaman yapılacak bir işlem yoktur; analiz_islem orada
           boş kalır, bu normaldir.

        2c) İŞLETMEYE ETKİSİ, UYGULAMA ÖRNEĞİ, RESMÎ DAYANAK.

           isletme_etkisi — Gelişmenin işletmelere hangi yoldan
             yansıyacağı: maliyet, nakit akışı, fiyatlama, finansman,
             vergi yükü. 1-3 cümle. ÖZELLİKLE EKONOMİ HABERLERİNDE
             doldur: faiz kararı -> vadeli satış yapan ve krediyle
             çalışan işletmenin finansman maliyeti, vade farkı; kur ->
             ithalatçının maliyeti, ihracatçının geliri; enflasyon ->
             fiyatlama ve stok değerlemesi. MEKANİZMAYI anlat, kesinlik
             iddia etme ("artabilir", "yansıyabilir"). Kaynakta olmayan
             rakam verme. Belirgin bir işletme etkisi yoksa BOŞ BIRAK.

           uygulama_ornegi — Okuyucunun konuyu kavraması için örnek
             hesaplama ya da muhasebe kaydı. YALNIZCA haberde
             uygulanabilir bir oran, tutar, had ya da hesaplama kuralı
             varsa yaz; yoksa BOŞ BIRAK. Kurallar:
             - Oran ve kural KAYNAKTAKİ olmalı; rakamlar ise açıkça
               varsayımsal ve yuvarlak ("Örneğin 100.000 TL'lik bir
               satışta ..."). İlk cümle bunun bir örnek olduğunu söylesin.
             - Hesabı adım adım yaz ve sonucu İKİ KEZ kontrol et.
             - Muhasebe kaydı gerekiyorsa Tekdüzen Hesap Planı kodlarıyla,
               her satır ayrı: "- 120 Alıcılar — Borç 120.000 TL". Borç
               ve alacak toplamı eşit olmalı.
             - Ekonomi haberinde basit bir etki hesabı olabilir (kaynakta
               geçen faiz oranıyla örnek bir kredinin yıllık maliyeti).
             - Emin değilsen BOŞ BIRAK: yanlış bir kayıt, hiç kayıt
               olmamasından çok daha zararlıdır.
             - Paragraflar boş satırla ayrılır; liste satırları "- " ile.

           resmi_dayanak — Kanun ve madde numarası, tebliğ ya da
             sirküler sıra numarası, karar sayısı, Resmî Gazete tarihi
             ve sayısı. YALNIZCA kaynakta geçenleri yaz; yoksa BOŞ BIRAK.

        2) İLGİLİYSE HABERİ YAZ. Kurallar:
           - Kaynak metni yalnızca anlamak için okursun. ASLA cümle
             kopyalamazsın, yeniden ifade edersin. Özgün bir metin yaz.
           - Türkçe, sade ve kurumsal bir dille yaz. Tabloid üslup,
             abartı, ünlem ve tıklama tuzağı başlık kullanma.
           - ÇIKTININ TAMAMI TÜRKÇE olmalı: başlık, özet, haber metni,
             etiketler, red nedeni ve ajan notu. Yalnızca kurum adları,
             kişi adları ve standart kodları (OECD, IASB, IFRS 18 gibi)
             özgün biçimini koruyabilir.
           - KAYNAK YABANCI DİLDE OLABİLİR. Bu durumda da haberi
             TÜRKÇE yazarsın; cümle cümle çeviri yapma, içeriği anlayıp
             Türk okura kendi cümlelerinle aktar. Mesleki terimleri
             Türkçe karşılığıyla kullan (corporate tax -> kurumlar
             vergisi, VAT -> KDV, withholding -> stopaj, transfer
             pricing -> transfer fiyatlandırması, IFRS -> UFRS/TFRS).
             Kurum ve standart adlarını olduğu gibi bırak, gerekiyorsa
             parantezle açıkla (OECD, IFRS 18).
           - Yabancı bir düzenleme haberinde Türkiye bağlantısı varsa
             (Türkiye'nin taraf olduğu anlaşma, TFRS'ye yansıyacak bir
             standart) bunu kaynakta yazılı olduğu ölçüde belirt.
             Kaynakta yoksa kendin çıkarım yapma.
           - Para birimlerini kaynaktaki birimiyle ver, TL'ye çevirme.
           - UZUNLUK: 5-9 paragraf. Kaynak metin zenginse uzun yaz,
             kısaysa kısa kal. Kaynakta olmayanı yazmak pahasına
             uzatma — boş cümleyle paragraf doldurmak, kısa ama dolu
             bir haberden kötüdür.
           - Paragrafları BOŞ SATIRLA ayır.
           - AYRINTIYI ATLAMA. Kaynakta varsa şunların hepsi habere
             girmeli; okuyucu haberi okuduktan sonra kaynağa gitmek
             zorunda kalmamalı:
               * Neyin değiştiği ve ÖNCEKİ durumun ne olduğu
               * Bütün sayısal değerler: oranlar, tutarlar, hadler,
                 limitler, ceza tutarları
               * Tarihler: Resmî Gazete yayım tarihi ve sayısı,
                 yürürlük tarihi, başvuru/beyan son tarihi
               * Dayanak: kanun ve madde numarası, tebliğ/sirküler
                 sıra numarası, karar sayısı
               * Kimin kapsama girdiği ve kimin girmediği (istisnalar)
               * Geçiş hükümleri ve varsa önceki düzenlemenin durumu
           - Kaynakta bir TABLO varsa (tarife dilimleri, oran listesi,
             had tablosu) satırlarını metin içinde tek tek aktar;
             "tabloda belirtilmiştir" deyip geçme.
           - İLK PARAGRAF haberin özünü tek başına versin: ne oldu,
             kimi ilgilendiriyor, ne zaman yürürlüğe giriyor. Okuyucu
             yalnızca ilk paragrafı okusa bile ana bilgiyi almalı.
           - Kaynakta olmayan hiçbir bilgiyi ekleme. Rakam, oran, tarih ve
             tutarları kaynaktaki gibi ver; kaynakta yoksa uydurma.
             Eksik bir ayrıntı varsa (örneğin yürürlük tarihi kaynakta
             yazmıyorsa) onu ajan notunda editöre bildir.
           - Yorum ve tavsiye verme; olanı aktar. "Yapmalısınız" deme.
           - Başlık en fazla 90 karakter, olguyu bildirsin.
           - Özet tek cümle, en fazla 200 karakter.
           - Etiketler: 2-4 adet, vergi terimleri (örnek: KDV, Tebliğ,
             Gelir Vergisi, e-Fatura).

        GÜVEN SKORU (0-100): Haberin doğruluğundan ve site için
        değerinden ne kadar eminsin. Vergi mevzuatı haberleri ekonomi
        haberlerinden daha değerli; aynı güvenilirlikteki bir ekonomi
        haberini vergi haberinden birkaç puan aşağıda tut. Resmî kaynak
        (Resmî Gazete, GİB, Bakanlık) ve net mevzuat bilgisi varsa
        yüksek (85-100). İkincil kaynak, eksik
        ayrıntı veya "bekleniyor/planlanıyor" gibi kesinleşmemiş ifadeler
        varsa düşür (50-80). Şüpheliyse 50'nin altı.

        KONU GRUBU: Haberi, kullanıcı mesajında verilen gruplardan birine
        ata. Yanıtta grubun slug değerini TAM olarak yaz. Haber birden çok
        grubu ilgilendiriyorsa ağırlıklı olanı seç.

        Kategori eşlemesi KESİNDİR:
        - IFRS, IAS, IASB, IFRIC, ISSB, TMS, TFRS, BOBİ FRS, KÜMİ FRS,
          finansal raporlama standardı, muhasebe standardı ve bu
          standartlardaki değişiklikler -> "tms-tfrs".
        - Bağımsız denetim, denetim standardı, güvence standardı ve denetçi
          düzenlemeleri -> "denetim".
        - Makroekonomi, enflasyon, merkez bankası, faiz, büyüme, istihdam,
          bütçe ve dış ticaret verileri -> "ekonomi".
        - Vergi mevzuatı -> ilgili vergi alt grubuna.
        - Site kapsamına giren fakat yukarıdaki gruplardan hiçbirine
          oturmayan mali, muhasebesel veya düzenleyici haber -> "genel".

        "vergi-kanunlari" ve "muhasebe-denetim" yalnızca menü üst
        başlıklarıdır; kategori olarak ASLA seçme.

        AJAN NOTU: Haberi onaylayacak editöre tek cümlelik not. Neyi
        doğrulaması gerektiğini söyle; her şey netse bunu belirt.
        Örnek: "İkincil kaynak; tebliğ Resmî Gazete'de teyit edilmeli."

        İlgili değilse baslik, ozet, icerik, etiketler boş kalsın ve
        red_nedeni'ni tek cümleyle doldur.
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
            'ilgili'      => ['type' => 'boolean', 'description' => 'Haber A (vergi ve mali mevzuat) ya da B (ekonomi gündemi) grubuna giriyor mu'],
            'red_nedeni'  => ['type' => 'string',  'description' => 'İlgili değilse tek cümlelik gerekçe, ilgiliyse boş'],
            'baslik'      => ['type' => 'string',  'description' => 'Haber başlığı, en fazla 90 karakter'],
            'ozet'        => ['type' => 'string',  'description' => 'Tek cümlelik spot, en fazla 200 karakter'],
            'icerik'      => ['type' => 'string',  'description' => '5-9 paragraf, paragraflar boş satırla ayrılmış; kaynaktaki tüm rakam, tarih, dayanak ve istisnalar dahil'],
            'etiketler'   => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
                'description' => '2-4 konu terimi (vergi haberinde vergi terimi; ekonomi haberinde ör. altın, borsa, enflasyon beklentisi, Fed)',
            ],
            'kategori'    => ['type' => 'string',  'description' => 'Verilen konu grubu listesinden TAM olarak bir slug'],
            'guven_skoru' => ['type' => 'integer', 'description' => '0-100 arası güven'],
            'ajan_notu'   => ['type' => 'string',  'description' => 'Onaylayacak editöre tek cümlelik not'],

            /*
             * VALENTRA ANALIZ — dort soru.
             *
             * Okuyucu (mali musavir, muhasebe calisani, isletme
             * yoneticisi) haberi kendi isine uyarlayabilsin diye.
             * Haber "ne oldu"yu anlatir; bu dort alan "bana ne"yi.
             *
             * Bilgi kaynakta yoksa alan BOS birakiliyor. Yarim bilgiyle
             * tamamlamak, ozellikle "hangi islem yapilmali" alaninda,
             * okuyucuyu yanlis isleme sevk eder.
             */
            'analiz_degisen'  => ['type' => 'string', 'description' => 'NE DEĞİŞTİ: önceki durum ve yeni durum, tek-iki cümle. Kaynakta yoksa boş bırak.'],
            'analiz_etkilenen'=> ['type' => 'string', 'description' => 'KİMLERİ ETKİLİYOR: hangi mükellef grubu, sektör ya da büyüklük. Kaynakta yoksa boş bırak.'],
            'analiz_zaman'    => ['type' => 'string', 'description' => 'NE ZAMAN UYGULANACAK: yürürlük tarihi, ilk uygulanacak dönem, geçiş hükmü. Kaynakta yoksa boş bırak.'],
            'analiz_islem'    => ['type' => 'string', 'description' => 'NE YAPILMALI: kaynağın ZORUNLU KILDIĞI somut adım ve kuralın doğrudan gerektirdiği kontroller; birden fazlaysa "- " ile başlayan satırlar, en fazla 5. Yoksa BOŞ BIRAK; genel tavsiye üretme.'],
            'isletme_etkisi'  => ['type' => 'string', 'description' => 'İŞLETMEYE ETKİSİ: maliyet, nakit akışı, fiyatlama, finansman ya da vergi yüküne hangi yoldan yansıdığı; 1-3 cümle. Özellikle ekonomi haberlerinde. Belirgin etki yoksa boş.'],
            'uygulama_ornegi' => ['type' => 'string', 'description' => 'UYGULAMA ÖRNEĞİ: kaynaktaki oran/kuralla, varsayımsal yuvarlak rakamlarla adım adım hesaplama ya da Tekdüzen Hesap Planı ile muhasebe kaydı. Uygulanabilir kural yoksa ya da emin değilsen boş.'],
            'resmi_dayanak'   => ['type' => 'string', 'description' => 'RESMÎ DAYANAK: kanun/madde, tebliğ sıra no, karar sayısı, Resmî Gazete tarih ve sayısı; yalnızca kaynakta geçenler, yoksa boş.'],
        ],
        'required' => [
            'sira', 'ilgili', 'red_nedeni', 'baslik', 'ozet', 'icerik',
            'etiketler', 'kategori', 'guven_skoru', 'ajan_notu',
            'analiz_degisen', 'analiz_etkilenen', 'analiz_zaman', 'analiz_islem',
            'isletme_etkisi', 'uygulama_ornegi', 'resmi_dayanak',
        ],
    ];

    /** @var array<string,mixed> */
    private const SEMA = [
        'type' => 'object',
        'properties' => [
            'ilgili'      => ['type' => 'boolean', 'description' => 'Haber A (vergi ve mali mevzuat) ya da B (ekonomi gündemi) grubuna giriyor mu'],
            'red_nedeni'  => ['type' => 'string',  'description' => 'İlgili değilse tek cümlelik gerekçe, ilgiliyse boş'],
            'baslik'      => ['type' => 'string',  'description' => 'Haber başlığı, en fazla 90 karakter'],
            'ozet'        => ['type' => 'string',  'description' => 'Tek cümlelik spot, en fazla 200 karakter'],
            'icerik'      => ['type' => 'string',  'description' => '5-9 paragraf, paragraflar boş satırla ayrılmış; kaynaktaki tüm rakam, tarih, dayanak ve istisnalar dahil'],
            'etiketler'   => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
                'description' => '2-4 konu terimi (vergi haberinde vergi terimi; ekonomi haberinde ör. altın, borsa, enflasyon beklentisi, Fed)',
            ],
            'kategori'    => ['type' => 'string',  'description' => 'Verilen konu grubu listesinden TAM olarak bir slug'],
            'guven_skoru' => ['type' => 'integer', 'description' => '0-100 arası güven'],
            'ajan_notu'   => ['type' => 'string',  'description' => 'Onaylayacak editöre tek cümlelik not'],
        ],
        // Gemini'nin responseSchema'si OpenAPI alt kumesidir;
        // additionalProperties desteklenmez, gonderilirse istek 400 doner.
        'required' => [
            'ilgili', 'red_nedeni', 'baslik', 'ozet', 'icerik',
            'etiketler', 'kategori', 'guven_skoru', 'ajan_notu',
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
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string}> $adaylar
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
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string}> $adaylar
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

            $bloklar[] = <<<METIN
            ### ADAY {$no}
            Kaynak: {$aday['kaynakAdi']} — {$tur}
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
        Hiçbir adayı atlama; A ya da B grubuna girmeyenler için de ilgili
        değerini false yapıp red_nedeni'ni doldur.

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

        Bu haberi sınıflandır; A ya da B grubuna giriyorsa Valentra için
        özgün bir haber metni yaz.
        METIN;
    }
}
