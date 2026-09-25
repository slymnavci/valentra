<?php
declare(strict_types=1);

/**
 * İlk rehber TASLAKLARI.
 *
 * Kurulumda bir kez rehberler tablosuna "taslak" olarak ekleniyor
 * (rehber_tohumla). Sitede gorunmeleri icin panelde okunup onaylanmalari
 * gerekiyor. Ornekteki tutarlar varsayimsal; oran ve kurallar genel
 * bilgi duzeyinde. Onaylamadan once ozellikle mevzuat atiflarini
 * kontrol edin.
 *
 * Bicim: rehber_icerik_html() (## baslik, ### alt baslik, - madde,
 * 1. adim, | tablo |, > not, [[arac:...]]).
 *
 * @return list<array{baslik:string,slug:string,ozet:string,konu:string,arac:string,icerik:string}>
 */

return [

[
    'baslik' => 'Vade farkı nasıl hesaplanır? Formül, KDV ve muhasebe kaydı',
    'slug'   => 'vade-farki-nasil-hesaplanir',
    'ozet'   => 'Vade farkının formülü, adım adım hesap örneği, vade farkına uygulanan KDV ve satıcı ile alıcı tarafındaki muhasebe kayıtları.',
    'konu'   => 'Finansal yönetim',
    'arac'   => 'vade-farki',
    'icerik' => <<<'METIN'
Vade farkı, bir satışın bedeli peşin yerine ileri bir tarihte ödendiğinde satıcının paranın zaman değeri için talep ettiği ek tutardır. İki biçimde karşımıza çıkar: satış anında vadeli fiyatın içine eklenen fark ve ödeme geciktiğinde sonradan hesaplanıp faturalanan fark. Hesap mantığı ikisinde de aynıdır.

## Formül

Vade farkı çoğunlukla basit faizle hesaplanır:

> Vade farkı = Anapara × Yıllık oran × Gün sayısı / 365

Oran aylık verildiyse formül şöyle olur:

> Vade farkı = Anapara × Aylık oran × Gün sayısı / 30

Hangi oranın ve hangi gün esasının kullanılacağı sözleşmede yazılı olmalıdır. Taraflar "aylık yüzde 3" üzerinde anlaştıysa yıllık orana çevirip 365 gün esasıyla hesaplamak farklı bir sonuç verir; uyuşmazlığı önlemenin yolu hesap yöntemini sözleşmeye açıkça yazmaktır.

## Adım adım örnek

Varsayalım 250.000 TL tutarında (KDV hariç) bir mal satışı yaptınız. Ödeme 60 gün sonra yapılacak ve sözleşmedeki vade farkı oranı yıllık yüzde 36.

1. Anaparayı belirleyin: 250.000 TL (vade farkı KDV hariç tutar üzerinden hesaplanır).
2. Oranı ondalığa çevirin: yüzde 36 = 0,36.
3. Gün oranını bulun: 60 / 365 = 0,1644.
4. Çarpın: 250.000 × 0,36 × 60 / 365 = 14.794,52 TL.

Aynı işlemde oran "aylık yüzde 3" olarak belirlenmiş olsaydı: 250.000 × 0,03 × 60 / 30 = 15.000 TL. Aradaki 205,48 TL'lik fark yalnızca gün esasından doğar.

[[arac:vade-farki]]

## Vade farkında KDV

KDV Kanunu'nun 24/c maddesine göre vade farkı, fiyat farkı ve faiz gibi gelirler KDV matrahına dahildir. Vade farkı, asıl teslimin tabi olduğu oranda vergilendirilir.

Örnekteki mal yüzde 20 KDV'ye tabiyse:

| Kalem | Tutar (TL) |
|---|---|
| Vade farkı | 14.794,52 |
| Vade farkının KDV'si (%20) | 2.958,90 |
| Faturalanacak toplam | 17.753,42 |

Vade farkı satış faturasında yer almıyorsa ayrıca fatura düzenlenir.

> Dikkat: Asıl teslim KDV'den istisna ya da farklı bir orana tabiyse vade farkının vergilendirilmesi de buna göre değişir. İstisnalı işlemlerde uygulamayı mali müşavirinizle birlikte kontrol edin.

## Muhasebe kaydı

Satıcı tarafında vade farkı faiz geliri olarak kaydedilir:

| Hesap | Borç | Alacak |
|---|---|---|
| 120 Alıcılar | 17.753,42 | |
| 642 Faiz Gelirleri | | 14.794,52 |
| 391 Hesaplanan KDV | | 2.958,90 |

Alıcı tarafında vade farkı finansman gideridir:

| Hesap | Borç | Alacak |
|---|---|---|
| 780 Finansman Giderleri | 14.794,52 | |
| 191 İndirilecek KDV | 2.958,90 | |
| 320 Satıcılar | | 17.753,42 |

## Uygulamada sık yapılan hatalar

- Vade farkını KDV dahil tutar üzerinden hesaplamak. Anapara KDV hariç satış bedelidir.
- Oranı ve gün esasını sözleşmeye yazmamak. Sonradan "aylık mı yıllık mı" tartışması çıkar.
- Gecikme vade farkını hiç faturalamamak ya da gelir kaydını unutmak.
- Peşin fiyat ile vadeli fiyat arasındaki farkı ayrı izlememek. Vadeli fiyatın içine gömülen fark, müşteri bazında kârlılığı ölçmeyi zorlaştırır.

## Özet

Vade farkı = Anapara × Oran × Gün / Esas gün. Oranı ve gün esasını sözleşmeye yazın, farkı KDV hariç tutar üzerinden hesaplayın, KDV'sini asıl teslimin oranıyla ekleyin ve satıcıda faiz geliri, alıcıda finansman gideri olarak kaydedin.
METIN,
],

[
    'baslik' => 'Başabaş satış tutarı nasıl bulunur? Katkı payı yöntemiyle örnek',
    'slug'   => 'basabas-satis-tutari-nasil-bulunur',
    'ozet'   => 'Sabit ve değişken giderlerden başabaş adedine ve satış tutarına, hedef kâr için gereken satışa ve güvenlik marjına adım adım hesap.',
    'konu'   => 'Finansal yönetim',
    'arac'   => 'basabas',
    'icerik' => <<<'METIN'
Başabaş noktası, işletmenin ne kâr ne zarar ettiği satış seviyesidir. Bu noktanın altındaki her satış zarar, üstündeki her satış kâr demektir. Fiyat belirlerken, yeni bir şube ya da ürün kararında ve maliyet artışlarının etkisini ölçerken ilk bakılacak sayıdır.

## Önce giderleri ayırın

- Sabit giderler: satış miktarıyla değişmeyen giderler. Kira, sabit personel maliyeti, amortisman, yazılım abonelikleri, muhasebe ücreti.
- Değişken giderler: satılan her birimle birlikte artan giderler. Hammadde ya da ticari mal maliyeti, satış komisyonu, kargo, ödeme sistemi kesintisi.

Aynı gider iki grupta birden sayılmamalı. Dönem tutarlı olmalı: sabit giderleri aylık aldıysanız başabaş sonucu da aylık satış olur.

## Katkı payı

- Birim katkı payı = Satış fiyatı − Birim değişken gider
- Katkı payı oranı = Birim katkı payı / Satış fiyatı

Katkı payı, her satışın sabit giderleri karşılamak için bıraktığı tutardır.

## Formüller

> Başabaş adedi = Sabit giderler / Birim katkı payı
> Başabaş satış tutarı = Sabit giderler / Katkı payı oranı

## Adım adım örnek

Varsayalım bir işletmenin aylık sabit giderleri 180.000 TL. Ürünün KDV hariç satış fiyatı 1.200 TL, birim değişken gideri 750 TL.

1. Birim katkı payı: 1.200 − 750 = 450 TL.
2. Katkı payı oranı: 450 / 1.200 = yüzde 37,5.
3. Başabaş adedi: 180.000 / 450 = 400 adet.
4. Başabaş satış tutarı: 180.000 / 0,375 = 480.000 TL.

İşletme ayda 400 adet, yani 480.000 TL satış yaptığında giderlerini tam karşılar.

[[arac:basabas]]

## Hedef kâr için gereken satış

Aylık 90.000 TL kâr hedefleniyorsa hedef kâr sabit gidere eklenir:

- Gereken adet: (180.000 + 90.000) / 450 = 600 adet
- Gereken satış tutarı: (180.000 + 90.000) / 0,375 = 720.000 TL

## Güvenlik marjı

Güvenlik marjı, gerçekleşen satışın başabaş noktasının ne kadar üzerinde olduğunu gösterir:

> Güvenlik marjı = (Gerçekleşen satış − Başabaş satış) / Gerçekleşen satış

Aylık satış 560.000 TL ise: (560.000 − 480.000) / 560.000 = yüzde 14,3. Satışlar yüzde 14,3'ten fazla düşerse işletme zarara geçer.

## Birden fazla ürün satılıyorsa

Ürünlerin katkı payı oranları farklıysa ciro içindeki paylarıyla ağırlıklandırılmış ortalama oran kullanılır:

| Ürün | Ciro payı | Katkı payı oranı | Ağırlıklı katkı |
|---|---|---|---|
| A | %60 | %40 | %24 |
| B | %40 | %25 | %10 |
| Toplam | %100 | | %34 |

Başabaş satış tutarı: 180.000 / 0,34 = 529.412 TL. Satış karması B ürününe kayarsa ortalama oran düşer ve başabaş noktası yükselir.

## Dikkat edilecekler

- Hesabı KDV hariç tutarlarla yapın; KDV işletmenin geliri değildir.
- Enflasyon döneminde sabit giderler ve birim maliyet hızla değişir; başabaş noktasını aylık güncelleyin.
- Amortisman nakit çıkışı değildir. Nakit başabaş için amortismanı sabit giderlerden düşüp ayrıca hesaplayabilirsiniz.
- Vadeli satışlarda finansman maliyetini değişken gidere ekleyin; aksi halde katkı payı olduğundan yüksek görünür.
METIN,
],

[
    'baslik' => 'Nakit akış tablosu nasıl hazırlanır? Dolaylı yöntemle örnek',
    'slug'   => 'nakit-akis-tablosu-nasil-hazirlanir',
    'ozet'   => 'İşletme, yatırım ve finansman faaliyetleri; dolaylı yöntemle adım adım örnek tablo ve bilançoyla mutabakat.',
    'konu'   => 'Muhasebe ve raporlama',
    'arac'   => '',
    'icerik' => <<<'METIN'
Gelir tablosu kârı, nakit akış tablosu ise paranın nereden gelip nereye gittiğini gösterir. Kâr eden bir işletmenin nakitsiz kalması mümkündür: satışlar vadeliyse, stok artıyorsa ya da kâr yatırıma gidiyorsa kasa boşalır. Nakit akış tablosu bu farkı görünür kılar.

TFRS uygulayan işletmelerde tablo TMS 7'ye göre hazırlanır; BOBİ FRS de nakit akış tablosu ister. Tekdüzen Hesap Planı'ndaki nakit akım tablosu da aynı mantığa dayanır.

## Tablonun üç bölümü

- İşletme faaliyetleri: esas faaliyetten doğan nakit. Müşteri tahsilatları, tedarikçi ve personel ödemeleri, ödenen vergiler.
- Yatırım faaliyetleri: uzun vadeli varlık alım ve satımları. Makine, taşıt, bina, iştirak.
- Finansman faaliyetleri: borçlanma ve özkaynak hareketleri. Kredi kullanımı ve geri ödemesi, sermaye artırımı, kâr payı ödemesi.

## Dolaylı yöntem

Uygulamada en çok kullanılan yöntemdir. Dönem net kârından başlanır ve kâra etki edip nakit hareketi yaratmayan kalemler düzeltilir:

1. Dönem net kârını yazın.
2. Nakit çıkışı gerektirmeyen giderleri ekleyin (amortisman, karşılık giderleri).
3. Nakit girişi sağlamayan gelirleri çıkarın (gerçekleşmemiş kur farkı gelirleri, duran varlık satış kârları).
4. İşletme sermayesindeki değişimleri yansıtın: alacak ve stok artışı nakdi azaltır, borç artışı nakdi artırır.
5. Yatırım ve finansman hareketlerini ayrı bölümlere yazın.
6. Net değişimi dönem başı nakde ekleyin ve bilançoyla karşılaştırın.

## Örnek tablo

Varsayımsal bir işletmenin yıllık verileri (araç satışında kâr ya da zarar oluşmadığı varsayılmıştır):

| Kalem | Tutar (TL) |
|---|---|
| Dönem net kârı | 1.200.000 |
| Amortisman | 300.000 |
| Ticari alacaklardaki artış | −450.000 |
| Stoklardaki artış | −200.000 |
| Ticari borçlardaki artış | 150.000 |
| İşletme faaliyetlerinden nakit | 1.000.000 |
| Makine alımı | −800.000 |
| Taşıt satışı | 120.000 |
| Yatırım faaliyetlerinden nakit | −680.000 |
| Kredi kullanımı | 500.000 |
| Kredi geri ödemesi | −350.000 |
| Ödenen kâr payı | −200.000 |
| Finansman faaliyetlerinden nakit | −50.000 |
| Nakitteki net artış | 270.000 |
| Dönem başı nakit | 430.000 |
| Dönem sonu nakit | 700.000 |

Tablo şunu söylüyor: işletme 1,2 milyon TL kâr etti ama esas faaliyetinden 1 milyon TL nakit üretti. Aradaki farkın büyük kısmı müşterilerde bekleyen alacaktan kaynaklanıyor. Üretilen nakdin büyük bölümü yeni makineye gitti.

## Mutabakat

Dönem sonu nakit, bilançodaki "nakit ve nakit benzerleri" ile aynı olmalıdır. TMS 7'ye göre nakit benzerleri, vadesi kısa (genellikle üç ay veya daha kısa) ve değer değişim riski önemsiz olan yatırımlardır. Tutmuyorsa sırasıyla şunlara bakın:

- Duran varlık satışı hem yatırım bölümüne hem de kâra bağlı kalemlere iki kez girmiş olabilir.
- Gerçekleşmemiş kur farkları düzeltilmemiş olabilir; döviz nakdin kur etkisi ayrı satırda gösterilir.
- Kredi faizleri bir bölümde hem gider hem ödeme olarak çift sayılmış olabilir.

## Faiz ve kâr payı nerede gösterilir?

TMS 7, ödenen faiz ve kâr payının işletme ya da finansman faaliyetlerinde gösterilmesine izin verir. Hangisini seçtiyseniz her dönem aynı sınıflandırmayı kullanın ve dipnotta belirtin.

## Özet

Net kârdan başlayın, nakit hareketi yaratmayan kalemleri düzeltin, işletme sermayesi değişimlerini yansıtın, yatırım ve finansmanı ayrı gösterin ve sonucu bilançodaki nakitle mutabık hale getirin. Kâr ile işletme nakdi arasındaki fark, yönetimin ilk soracağı soru olmalıdır.
METIN,
],

[
    'baslik' => 'Müşteri bazında kârlılık nasıl hesaplanır? Adım adım model',
    'slug'   => 'musteri-bazinda-karlilik-nasil-hesaplanir',
    'ozet'   => 'Brüt kârdan müşteriye özgü hizmet ve finansman maliyetlerine; ciroya göre değil katkıya göre müşteri değerlendirmesi, sayısal karşılaştırma ile.',
    'konu'   => 'Finansal yönetim',
    'arac'   => '',
    'icerik' => <<<'METIN'
Ciro sıralaması ile kârlılık sıralaması çoğu zaman aynı değildir. En büyük müşteri; sık ve küçük siparişleri, uzun vadesi ve yüksek iskontosuyla işletmeye en az katkıyı bırakan müşteri olabilir. Müşteri bazında kârlılık, her müşterinin gerçekte ne kadar kazandırdığını gösterir.

## Hesabın adımları

1. Net satış: müşteriye kesilen faturalar eksi iskonto ve iadeler.
2. Satılan malın maliyeti: o müşteriye satılan ürünlerin maliyeti.
3. Brüt kâr: net satış eksi satılan malın maliyeti.
4. Hizmet maliyeti: müşterinin yarattığı iş yükü. Sipariş işleme, faturalama, sevkiyat, destek. Faaliyet başına maliyetle dağıtılır.
5. Müşteriye özgü doğrudan giderler: nakliye, özel ambalaj, satış komisyonu.
6. Finansman maliyeti: ortalama alacak bakiyesinin işletmeye maliyeti.
7. Müşteri katkısı: brüt kârdan 4, 5 ve 6'nın düşülmesiyle kalan tutar.

## Hizmet maliyetini dağıtmak

Genel giderleri ciroya göre dağıtmak en yaygın ve en yanıltıcı yöntemdir: çok sipariş veren küçük müşterinin yükünü büyük müşteriye yazar. Bunun yerine her faaliyetin birim maliyetini bulun:

> Sipariş başına maliyet = Sipariş işleme giderleri / Toplam sipariş sayısı

Örneğin sipariş işlemeyle uğraşan personel ve sistem gideri yılda 540.000 TL, toplam sipariş sayısı 1.200 ise sipariş başına maliyet 450 TL'dir.

## Finansman maliyeti

> Ortalama alacak = Yıllık net satış × Ortalama tahsil süresi (gün) / 365
> Finansman maliyeti = Ortalama alacak × Yıllık finansman oranı

## Sayısal karşılaştırma

İki müşteri, yıllık finansman oranı yüzde 40 ve sipariş başına maliyet 450 TL varsayımıyla:

| Kalem | Müşteri A | Müşteri B |
|---|---|---|
| Net satış | 2.000.000 | 1.500.000 |
| Satılan malın maliyeti | −1.400.000 | −1.080.000 |
| Brüt kâr | 600.000 | 420.000 |
| Sipariş sayısı | 240 | 36 |
| Sipariş işleme (×450 TL) | −108.000 | −16.200 |
| Nakliye | −90.000 | −30.000 |
| Ortalama tahsil süresi | 120 gün | 30 gün |
| Finansman maliyeti | −263.014 | −49.315 |
| Müşteri katkısı | 138.986 | 324.485 |
| Katkı / net satış | %6,9 | %21,6 |

A'nın cirosu B'den yüzde 33 fazla ama bıraktığı katkı yarısından az. Farkı yaratan brüt marj değil; sipariş sıklığı ve özellikle tahsil süresi.

## Sonuçla ne yapılır?

- Vade farkı: uzun vadeli müşteriye vade farkı uygulayın ya da peşin fiyatla vadeli fiyatı ayırın.
- Asgari sipariş tutarı: küçük ve sık siparişleri birleştirmeye yönlendirin.
- İskonto politikası: iskontoyu ciroya değil katkıya ve ödeme süresine bağlayın.
- Kaynak ayırma: satış ekibinin zamanını katkısı yüksek müşterilere yönlendirin.

## Veriyi nasıl toplarsınız?

- Satış hesaplarını ve satılan malın maliyetini müşteri kırılımında izleyin (cari kodu ya da masraf merkezi).
- Sipariş, sevkiyat ve destek taleplerini müşteri bazında sayın.
- Ortalama tahsil süresini cari hesap yaşlandırma raporundan alın.
- Hesabı üç ayda bir tekrarlayın; tek dönemlik sonuçlar mevsimsellikten etkilenebilir.

> Not: Tablodaki tutarlar ve oranlar varsayımsaldır. Kendi hesabınızda finansman oranı olarak işletmenizin fiili borçlanma maliyetini kullanın.
METIN,
],

[
    'baslik' => 'TFRS 15 örneklerle nasıl uygulanır? Beş adımlı model',
    'slug'   => 'tfrs-15-orneklerle-nasil-uygulanir',
    'ozet'   => 'Müşteri sözleşmelerinden hasılat: beş adım, işlem bedelinin dağıtımı, değişken bedel ve önemli finansman bileşeni için sayısal örnekler.',
    'konu'   => 'Muhasebe ve TFRS',
    'arac'   => '',
    'icerik' => <<<'METIN'
TFRS 15 "Müşteri Sözleşmelerinden Hasılat", hasılatın ne zaman ve hangi tutarda kaydedileceğini beş adımlı bir modelle belirler. Temel ilke şudur: işletme, müşteriye devrettiği mal ve hizmetler karşılığında hak etmeyi beklediği bedeli, devri yansıtacak şekilde hasılat olarak kaydeder.

Standart TFRS uygulayan işletmeler için geçerlidir. BOBİ FRS uygulayan işletmelerde hasılat bölümü benzer bir mantığı daha sade kurallarla uygular. Hangi çerçeveye tabi olduğunuzu KGK düzenlemelerine göre kontrol edin.

## Beş adım

1. Müşteriyle yapılan sözleşmeyi belirleyin.
2. Sözleşmedeki edim yükümlülüklerini (ayrı ayrı devredilecek mal ve hizmetleri) belirleyin.
3. İşlem bedelini belirleyin.
4. İşlem bedelini edim yükümlülüklerine dağıtın.
5. Her edim yükümlülüğü yerine getirildiğinde (ya da getirildikçe) hasılatı kaydedin.

## Örnek 1: Paket satış ve bedelin dağıtımı

Bir yazılım firması tek sözleşmeyle lisans, kurulum ve 12 aylık destek satıyor. Paketin fiyatı 240.000 TL (KDV hariç). Firmanın bu kalemleri tek başına sattığı fiyatlar şöyle:

| Edim yükümlülüğü | Tek başına satış fiyatı | Dağıtılan bedel |
|---|---|---|
| Yazılım lisansı | 180.000 | 166.153,85 |
| Kurulum | 20.000 | 18.461,53 |
| 12 aylık destek | 60.000 | 55.384,62 |
| Toplam | 260.000 | 240.000,00 |

Paket indirimi (20.000 TL) her kaleme tek başına satış fiyatı oranında dağıtılır. Örneğin lisans için: 240.000 × 180.000 / 260.000 = 166.153,85 TL.

Hasılatın zamanlaması:

- Lisans: müşteri yazılımı kullanabilir hale geldiğinde, bir defada. (Lisans, var olan yazılımı kullanma hakkı veriyorsa.)
- Kurulum: kurulum tamamlandığında. Kurulum yazılımı önemli ölçüde özelleştiriyorsa lisansla birlikte tek edim sayılabilir; bu bir yargı konusudur.
- Destek: 12 ay boyunca eşit olarak, ayda yaklaşık 4.615,38 TL.

Bedel sözleşme başında tamamen faturalandıysa henüz hak edilmeyen destek ve kurulum tutarı "sözleşme yükümlülüğü" olarak bekler. Tekdüzen hesap planında genellikle 380 Gelecek Aylara Ait Gelirler hesabında izlenir.

## Örnek 2: Değişken bedel (hacim iskontosu)

Bir üretici, müşterisine birim fiyatı 100 TL'den satış yapıyor. Sözleşmeye göre müşteri yıl içinde 10.000 adedin üzerinde alım yaparsa yılın tüm alımları için yüzde 5 iskonto geriye dönük olarak iade edilecek. Geçmiş verilere göre müşterinin yılda yaklaşık 12.000 adet alması bekleniyor.

İskonto büyük olasılıkla hak edileceği için hasılat baştan birim başına 95 TL üzerinden kaydedilir. İlk çeyrekte 3.000 adet satıldıysa:

| Hesap | Borç | Alacak |
|---|---|---|
| Ticari alacaklar | 300.000 | |
| Hasılat | | 285.000 |
| İade yükümlülüğü | | 15.000 |

(KDV kaydı sadelik için gösterilmemiştir.)

Değişken bedel ancak ileride önemli bir hasılat iptali olmayacağı yüksek olasılıkla öngörülebildiği ölçüde hasılata alınır. Tahmin her raporlama döneminde yeniden gözden geçirilir.

## Örnek 3: Önemli finansman bileşeni

Bir makinenin peşin fiyatı 100.000 TL. Müşteri makineyi bugün teslim alıp 24 ay sonra tek seferde 130.000 TL ödemeyi seçiyor.

- Teslimde hasılat: 100.000 TL, yani peşin fiyat.
- Aradaki 30.000 TL hasılat değil, faiz gelirinin konusudur. Vade boyunca etkin faiz yöntemiyle yayılır; bu örnekte yıllık yaklaşık yüzde 14.

Mal ya da hizmetin devri ile ödeme arasındaki süre bir yıl veya daha kısaysa standart, finansman bileşeninin dikkate alınmamasına izin verir.

## Vergi ile farklar

Vergi mevzuatında hasılat çoğunlukla teslim ve fatura esasına göre dikkate alınır. TFRS 15'e göre kayıt zamanı bundan farklıysa (örnekteki destek geliri ya da iade yükümlülüğü gibi) finansal tablolarda TMS 12 kapsamında ertelenmiş vergi hesaplanması gerekebilir.

## Uygulama kontrol listesi

- Sözleşmede birden fazla mal ya da hizmet var mı? Varsa her biri ayrı edim mi?
- Her edimin tek başına satış fiyatı belgelenmiş mi?
- Bedelde iskonto, prim, iade hakkı ya da ceza gibi değişken unsur var mı?
- Ödeme süresi bir yılı aşıyor mu?
- Hasılat bir anda mı, zamana yayılarak mı kaydedilmeli?
- Vergi kaydıyla fark oluşuyor mu, ertelenmiş vergi hesaplandı mı?
METIN,
],

];
