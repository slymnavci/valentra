/* =========================================================
   DERS ASİSTANI
   ---------------------------------------------------------
   Dört sağlayıcı desteklenir:
   1) Yerel     — internet gerekmez, aşağıdaki bilgi tabanı
   2) OpenAI    — kullanıcının kendi API anahtarı (ChatGPT)
   3) Anthropic — kullanıcının kendi API anahtarı (Claude)
   4) Gemini    — kullanıcının kendi API anahtarı (Google)

   API ANAHTARLARI (openai/anthropic/gemini) yalnızca sunucuda
   (public/api/kullanici_sir tablosu) saklanır — tarayıcıya asla
   dönmez. İstekler kullanici-api.js'deki kuAiSohbet() ile
   public/api/ai_sohbet.php proxy'sine gider; anahtar sunucudan
   çıkmadan sağlayıcıya iletilir. Bu yüzden bu özellik, Pratik
   sistemiyle aynı backend bağlantısını (Yönetim → Pratik Sistemi
   Bağlantısı) gerektirir.
   ========================================================= */

const BILGI = {
  "TFRS 15": {
    ozet: `TFRS 15 — MÜŞTERİ SÖZLEŞMELERİNDEN HASILAT

TEMEL FELSEFE
Eski standartlarda (TMS 11 ve TMS 18) ölçüt "risk ve getirilerin devri" idi.
TFRS 15 ile ölçüt "KONTROLÜN DEVRİ" oldu.

Kontrolün üç ayağı:
1. Kullanımı yönetme gücü
2. Faydayı elde etme gücü
3. Başkalarını engelleme gücü

BEŞ AŞAMALI MODEL
1. Sözleşmenin belirlenmesi
2. Edim yükümlülüklerinin belirlenmesi
3. İşlem fiyatının belirlenmesi
4. Fiyatın edim yükümlülüklerine dağıtılması
5. Hasılatın muhasebeleştirilmesi

Sözleşmenin yazılı olması şart değildir. Sözlü veya ticari
teamüle dayalı da olabilir. Önemli olan hukuken icra
edilebilir hak ve yükümlülük doğurmasıdır.`,
    sayfa: "1-54",
    tuzaklar: `TFRS 15 SINAV TUZAKLARI

1. Güvence tipi garanti AYRI EDİM YÜKÜMLÜLÜĞÜ DEĞİLDİR.
   TMS 37 kapsamında karşılık ayrılır.
   Ayrı edim yükümlülüğü olan HİZMET TİPİ garantidir.

2. KDV işlem fiyatına DAHİL EDİLMEZ.
   Üçüncü şahıslar adına tahsil edilir.

3. Fatura kesilmesi hasılat için yeterli DEĞİLDİR.
   Kontrolün devri esastır.

4. Zilyetlik tek başına kontrolü ispatlamaz.
   Konsinye satışta mal bayide olsa bile kontrol satıcıdadır.

5. "Bedava" diye bir şey yoktur.
   Hediye edilen ürünün müstakil satış fiyatı bulunur ve
   indirim tüm edimlere nispi olarak dağıtılır.

6. Sözleşme Varlığı, Ticari Alacak DEĞİLDİR.
   Sözleşme varlığında tahsil hakkı KOŞULLUDUR.
   Ticari alacakta hak KOŞULSUZDUR, sadece vade beklenir.

7. Değişken bedelde SINIRLANDIRMA kuralı uygulanır.
   Sadece önemli bir iptal olmayacağı kuvvetle muhtemel
   olan tutar yazılır.

8. Zamana yayılı hasılat için üç kriterden BİRİ yeterlidir.
   Üçü birden aranmaz.`,
    kayit: `TFRS 15 TEMEL YEVMİYE KAYITLARI

1) Edim yerine getirildi, tahsil hakkı henüz koşullu:
   ______________________ / ______________________
   18X Sözleşme Varlıkları        XXX
        60X Hasılat                      XXX

2) Koşulsuz hak doğduğunda (fatura kesildi):
   ______________________ / ______________________
   120 Ticari Alacaklar           XXX
        18X Sözleşme Varlıkları          XXX

3) Tahsilat yapıldı ama edim yerine getirilmedi:
   ______________________ / ______________________
   100/102 Kasa/Banka             XXX
        38X Sözleşme Yükümlülükleri      XXX

4) Edim yerine getirildikçe:
   ______________________ / ______________________
   38X Sözleşme Yükümlülükleri    XXX
        60X Hasılat                      XXX

5) İade beklenen kısım için:
   ______________________ / ______________________
   60X Hasılat                    XXX
        39X İade Yükümlülüğü             XXX`,
    vuk: `TFRS 15 — VUK KARŞILAŞTIRMASI

HASILATI TETİKLEYEN OLAY
VUK: Faturanın kesilmesi veya tahsilat
TFRS 15: Kontrolün devri (edimin yerine getirilmesi)

YILLARA SARİ İNŞAAT
VUK: Kâr/zarar işin bittiği yıl kesinleşir (GVK md. 42)
TFRS 15: İlerleme oranına göre her yıl hasılat kaydedilir

SÖZLEŞME ŞEKLİ
VUK: Yazılı belge ve fatura esastır
TFRS 15: Yazılı, sözlü veya ticari teamül olabilir

TAHSİLATIN ETKİSİ
VUK: Peşin tahsilat bazen matrahı tetikler
TFRS 15: Edim yerine getirilmediyse tahsilat sözleşme
yükümlülüğüdür, hasılat değildir

ERTELENMİŞ VERGİ
TFRS'ye göre erken yazılan hasılat ticari kârı yükseltir,
mali kâr düşük kalır. Vergiye tabi geçici fark oluşur ve
ertelenmiş vergi YÜKÜMLÜLÜĞÜ hesaplanır.`
  },

  "TMS 2": {
    ozet: `TMS 2 — STOKLAR

TEMEL ÖLÇÜM KURALI
Stoklar, MALİYET ile NET GERÇEKLEŞEBİLİR DEĞERİN
DÜŞÜK OLANIYLA ölçülür.

EN KRİTİK NOKTA — NORMAL KAPASİTE
Sabit genel üretim giderleri NORMAL KAPASİTEYE göre dağıtılır.
Düşük kapasitede çalışılırsa dağıtılmayan sabit GÜG stok
maliyetine EKLENMEZ, dönem gideri yazılır.

Örnek: Normal kapasite 100.000 birim, fiili üretim 70.000 birim,
sabit GÜG 500.000 TL ise:
  Stoka giden    : 500.000 × (70.000/100.000) = 350.000 TL
  Dönem gideri   : 150.000 TL

NET GERÇEKLEŞEBİLİR DEĞER
NGD = Tahmini satış fiyatı
      − Tahmini tamamlanma maliyeti
      − Satış için gerekli maliyetler

Kural olarak HER BİR STOK KALEMİ BAZINDA değerlendirilir.`,
    tuzaklar: `TMS 2 TUZAKLARI

1. LİFO KULLANILAMAZ.
   Sadece gerçek parti maliyeti, FİFO ve ağırlıklı ortalama.
2. Değer düşüklüğü tüm stoklar toplu değerlendirilerek
   belirlenmez; kalem bazında bakılır.
3. Düşük kapasitede sabit GÜG'ün tamamı stok maliyetine girmez.
4. Anormal fire, depolama, genel yönetim ve satış giderleri
   maliyete girmez.
5. Değer düşüklüğü iptali, satılan malın maliyetinden
   düşülerek kaydedilir.`
  },

  "TMS 16": {
    ozet: `TMS 16 — MADDİ DURAN VARLIKLAR

MALİYET UNSURLARI
Satın alma fiyatı, ithalat vergileri, iade edilmeyen alış
vergileri, varlığı yerine ve çalışır duruma getirmeye ilişkin
doğrudan maliyetler, sökme-kaldırma-restorasyon yükümlülüğünün
tahmini maliyeti.

YENİDEN DEĞERLEME
Artış: Diğer kapsamlı gelire, özkaynakta değer artış fonuna.
       Ancak daha önce gider yazılmış azalış varsa o tutar
       kadarı önce kâr/zarara.
Azalış: Kâr/zarara. Ancak fon varsa önce fondan düşülür.

FONUN AKIBETİ
Varlık bilanço dışı bırakıldığında doğrudan GEÇMİŞ YIL
KÂRLARINA aktarılır. HİÇBİR DURUMDA kâr veya zarara aktarılmaz.

AMORTİSMAN
Amortismana tabi tutar = Maliyet − Kalıntı değer
Hasılata dayalı amortisman yöntemi KULLANILAMAZ.
Atıl kalma amortismanı durdurmaz.
Amortisman, varlık kullanılabilir duruma geldiğinde başlar.`,
    tuzaklar: `TMS 16 TUZAKLARI

1. Değer artış fonu satışta gelir yazılmaz,
   geçmiş yıl kârlarına aktarılır.
2. Hasılata dayalı amortisman yasaktır.
3. Atıl kalma amortismanı durdurmaz.
4. Personel eğitim gideri maliyete eklenmez.
5. MDV satış kârı hasılat olarak gösterilmez.
6. Yararlı ömür değişikliği muhasebe TAHMİNİ
   değişikliğidir, politika değişikliği değildir.`
  },

  "TMS 36": {
    ozet: `TMS 36 — VARLIKLARDA DEĞER DÜŞÜKLÜĞÜ

GERİ KAZANILABİLİR TUTAR
max(Gerçeğe uygun değer − elden çıkarma maliyetleri ;
    Kullanım değeri)

GÖSTERGE OLMASA DA YILLIK TEST GEREKENLER
1. Şerefiye
2. Sınırsız yararlı ömürlü maddi olmayan duran varlıklar
3. Henüz kullanıma hazır olmayan maddi olmayan duran varlıklar

KULLANIM DEĞERİ
Varlığın MEVCUT DURUMU esas alınır.
Gelecekteki yeniden yapılandırma ve performans artırıcı
iyileştirmeler dahil edilmez.
VERGİ ÖNCESİ iskonto oranı kullanılır.

NÜB'DE ZARAR DAĞITIM SIRASI
1. Önce şerefiye
2. Kalan tutar diğer varlıklara defter değerleri oranında
3. Hiçbir varlık şu üçünün en yükseğinin altına indirilemez:
   GUD − elden çıkarma maliyeti, kullanım değeri, sıfır`,
    tuzaklar: `TMS 36 TUZAKLARI

1. ŞEREFİYEDE DEĞER DÜŞÜKLÜĞÜ HİÇBİR ŞEKİLDE İPTAL EDİLMEZ.
   Sınavın en klasik sorusudur.
2. Kullanım değerinde planlanan iyileştirmeler
   dikkate alınmaz.
3. Vergi ÖNCESİ iskonto oranı kullanılır.
4. İptal sonrası defter değeri, hiç değer düşüklüğü
   ayrılmasaydı oluşacak amortismanlı defter değerini aşamaz.`
  },

  "Kavramsal Çerçeve": {
    ozet: `FİNANSAL RAPORLAMAYA İLİŞKİN KAVRAMSAL ÇERÇEVE

BİRİNCİL KULLANICILAR
Mevcut ve potansiyel yatırımcılar, borç verenler ve diğer
kredi veren taraflar.
Yönetim ve düzenleyici kurumlar birincil kullanıcı DEĞİLDİR.

TEMEL NİTELİKSEL ÖZELLİKLER (2 adet)
1. İhtiyaca uygunluk: tahmin değeri, teyit değeri, önemlilik
2. Gerçeğe uygun sunum: tam olma, tarafsız olma, hatasız olma

DESTEKLEYİCİ NİTELİKSEL ÖZELLİKLER (4 adet)
Karşılaştırılabilirlik, doğrulanabilirlik, zamanında sunum,
anlaşılabilirlik
Kısıt: Maliyet

VARLIK TANIMI (2018)
Geçmiş olaylar sonucunda işletmenin kontrolündeki mevcut
ekonomik kaynak.
"Fayda girişi beklenen" ifadesi kalktı, olasılık eşiği
tanımdan çıkarıldı.`,
    tuzaklar: `KAVRAMSAL ÇERÇEVE TUZAKLARI

1. Finansal tablolara alma için "olasılık" ve "güvenilir
   ölçüm" kriterleri ARTIK ARANMAZ.
   2018 revizyonunda kaldırıldı.
2. İhtiyatlılık Çerçeveden ÇIKARILMADI.
   2018'de tarafsızlığı destekleyen unsur olarak geri
   getirildi. Ancak ayrı bir niteliksel özellik değildir.`
  }
};

/* --------------------------------------------------------- */
let sohbet = [];

function asistanBaslat() {
  sohbet = [];
  const c = window.__ctx || {};
  const ay = Ayar.oku();
  const rozet = document.getElementById("aiMode");
  if (rozet) {
    const etiket = { yerel: "Yerel", openai: "ChatGPT", anthropic: "Claude", gemini: "Gemini", sunucu: "Sunucu" };
    rozet.textContent = etiket[ay.aiSaglayici] || "Yerel";
    rozet.className = ay.aiSaglayici === "yerel" ? "badge badge-gray" : "badge badge-ok";
  }
  botYaz(`Merhaba. ${c.standart || c.konu || "Bu konu"} üzerinde çalışıyorsunuz.

Şu anda ${c.sayfa}. sayfadasınız${c.bolum ? ` (${c.bolum})` : ""}.

Aşağıdaki hızlı butonları kullanabilir veya doğrudan soru yazabilirsiniz.`);
}

function botYaz(metin, kaynak) {
  const b = document.getElementById("chatBody");
  if (!b) return null;
  const d = document.createElement("div");
  d.className = "msg bot";
  d.textContent = metin;
  if (kaynak) {
    const s = document.createElement("span");
    s.className = "src";
    s.textContent = "Kaynak: " + kaynak;
    d.appendChild(s);
  }
  b.appendChild(d);
  b.scrollTop = b.scrollHeight;
  return d;
}

function kullaniciYaz(metin) {
  const b = document.getElementById("chatBody");
  if (!b) return;
  const d = document.createElement("div");
  d.className = "msg user";
  d.textContent = metin;
  b.appendChild(d);
  b.scrollTop = b.scrollHeight;
}

function hizliSor(metin) {
  const i = document.getElementById("chatInput");
  if (i) { i.value = metin; mesajGonder(); }
}

function mesajGonder() {
  const inp = document.getElementById("chatInput");
  const soru = inp.value.trim();
  if (!soru) return;
  kullaniciYaz(soru);
  inp.value = "";
  sohbet.push({ rol: "kullanici", metin: soru });

  const ay = Ayar.oku();
  if (ay.aiSaglayici === "openai")         yapayZekayaSor("openai", soru);
  else if (ay.aiSaglayici === "anthropic") yapayZekayaSor("anthropic", soru);
  else if (ay.aiSaglayici === "gemini")    yapayZekayaSor("gemini", soru);
  else if (ay.aiSaglayici === "sunucu")    sunucuyaSor(soru);
  else setTimeout(() => yerelCevap(soru), 200);
}

/* --------------------------------------------------------- */
/* YEREL CEVAPLAMA                                            */
/* --------------------------------------------------------- */
function yerelCevap(soru) {
  const c = window.__ctx || {};
  const std = c.standart || "";
  const bilgi = BILGI[std];
  const s = soru.toLowerCase();

  if (!bilgi) {
    botYaz(`Bu konu için yerel bilgi tabanında henüz kayıt bulunmuyor.

İki seçeneğiniz var:

1) Bilgi ekleyin
   assets/js/assistant.js dosyasındaki BILGI nesnesine
   "${std || c.konu}" başlığıyla yeni kayıt ekleyin.

2) Yapay zekâya bağlanın
   Üyelik sayfasından ChatGPT, Claude veya Gemini API
   anahtarınızı tanımlayarak serbest soru sorabilirsiniz.`);
    return;
  }

  if (/test|soru sor|sına|beni test/.test(s)) {
    const konuId = c.konuId, dersId = c.dersId;
    const havuz = (typeof sinavSorulariGetir === "function") ? sinavSorulariGetir(dersId, konuId) : [];
    if (!havuz.length) { botYaz("Bu konu için soru bankası bulunmuyor."); return; }
    const q = havuz[Math.floor(Math.random() * havuz.length)];
    const harf = ["A", "B", "C", "D"];
    botYaz(`SORU\n\n${q.s}\n\n${q.o.map((x, i) => harf[i] + ") " + x).join("\n")}\n\nCevabınızı düşünün. Hazır olduğunuzda "cevap" yazın.`,
      `${q.bolum}`);
    window.__bekleyenCevap = `DOĞRU CEVAP: ${harf[q.d]}) ${q.o[q.d]}\n\n${q.aciklama}`;
    return;
  }

  if (/^cevap|cevabı göster|doğrusu ne/.test(s) && window.__bekleyenCevap) {
    botYaz(window.__bekleyenCevap, c.materyal);
    window.__bekleyenCevap = null;
    return;
  }

  if (/tuzak|hata|dikkat|karıştır/.test(s)) {
    botYaz(bilgi.tuzaklar || "Bu konu için tuzak listesi tanımlanmamış.", `${c.materyal}, sayfa ${c.sayfa}`);
    return;
  }
  if (/kayıt|yevmiye|muhasebeleş/.test(s)) {
    botYaz(bilgi.kayit || "Bu konu için yevmiye kaydı örneği tanımlanmamış.", c.materyal);
    return;
  }
  if (/vuk|vergi usul|karşılaştır/.test(s)) {
    botYaz(bilgi.vuk || "Bu konu için VUK karşılaştırması tanımlanmamış.", c.materyal);
    return;
  }
  if (/özet|anlat|nedir|açıkla|konu/.test(s)) {
    botYaz(bilgi.ozet, `${c.materyal}, sayfa ${bilgi.sayfa || c.sayfa}`);
    return;
  }

  const parcalar = [];
  Object.entries(bilgi).forEach(([k, v]) => {
    if (typeof v === "string") {
      v.split("\n\n").forEach(p => {
        if (s.split(" ").filter(w => w.length > 3).some(w => p.toLowerCase().includes(w)))
          parcalar.push(p);
      });
    }
  });

  if (parcalar.length) {
    botYaz(parcalar.slice(0, 3).join("\n\n"), `${c.materyal}, sayfa ${c.sayfa}`);
  } else {
    botYaz(`Bu soruya yerel bilgi tabanında doğrudan karşılık bulamadım.

Şunları deneyebilirsiniz:
· Konuyu özetle
· Sınav tuzakları
· Yevmiye kaydı
· VUK karşılaştırması
· Beni test et

Serbest sorular için Üyelik sayfasından ChatGPT, Claude
veya Gemini API anahtarınızı tanımlayın.`);
  }
}

/* --------------------------------------------------------- */
/* SİSTEM TALİMATI                                            */
/* --------------------------------------------------------- */
function sistemTalimati() {
  const c = window.__ctx || {};
  const bilgi = BILGI[c.standart];
  return `Sen bir Yeminli Mali Müşavirlik (YMM) sınavı hazırlık asistanısın.
Kullanıcı Türkiye'de YMM sınavına hazırlanan deneyimli bir finans yöneticisidir.

MEVCUT ÇALIŞMA BAĞLAMI
Ders: ${c.ders || "-"}
Konu: ${c.konu || "-"}
Standart: ${c.standart || "-"}
Materyal: ${c.materyal || "-"}
Sayfa: ${c.sayfa || "-"}
Bölüm: ${c.bolum || "-"}

KURALLAR
1. Öncelikle aşağıdaki ders materyali özetine dayan.
2. Materyalde olmayan bilgi veriyorsan bunu açıkça belirt.
3. Cevaplarını YMM sınavı formatında ver: madde madde, başlıklandırarak.
4. Süslü paragraflar yazma. Net ve teknik ol.
5. Sayısal örneklerde hesaplama adımlarını göster.
6. Türkçe cevap ver. Standart adlarını (TFRS 15, TMS 16) doğru kullan.
7. Emin olmadığın konularda tahmin yürütme, bilmediğini söyle.
8. Muhasebe kaydı istenirse borç/alacak formatında yaz.

DERS MATERYALİ ÖZETİ
${bilgi ? JSON.stringify(bilgi).slice(0, 5000) : "Bu konu için özet sağlanmadı. Genel YMM bilginle cevap ver ancak bunu belirt."}`;
}

/* --------------------------------------------------------- */
/* OPENAI / ANTHROPIC / GEMİNİ                                */
/* --------------------------------------------------------- */
const AI_SAGLAYICI_AD = { openai: "ChatGPT", anthropic: "Claude", gemini: "Gemini" };

async function yapayZekayaSor(saglayici, soru) {
  const ad = AI_SAGLAYICI_AD[saglayici] || saglayici;
  const u = Auth.aktif();

  if (!ppBagliMi() || !u) {
    botYaz(`${ad} için sunucu bağlantısı kurulu değil.

AI anahtarları artık yalnızca sunucuda saklanır — bu özelliği
kullanmak için önce Yönetim → Pratik Sistemi Bağlantısı'nı kurup,
ardından Üyelik ve Ayarlar sayfasından ${ad} API anahtarınızı
(yeniden) kaydetmeniz gerekir.

Şimdilik yerel moddan cevaplıyorum.`);
    yerelCevap(soru);
    return;
  }

  const bekleyen = botYaz("Yanıt hazırlanıyor...");
  const c = window.__ctx || {};
  /* soru zaten sohbet dizisine eklenmiş olarak gelir (bkz. sohbet gönderme
     handler'ı) — burada tekrar eklemek çift mesaj gönderilmesine yol açar. */
  const mesajlar = sohbet.slice(-6);

  try {
    const cevap = await kuAiSohbet(u.kullaniciAdi, saglayici, sistemTalimati(), mesajlar);
    if (bekleyen) bekleyen.remove();
    botYaz(cevap || "Modelden cevap alınamadı.", `${ad} · ${c.materyal || c.konu || ""}`);
    sohbet.push({ rol: "asistan", metin: cevap });

  } catch (e) {
    if (bekleyen) bekleyen.remove();
    const kotaMi = /quota|insufficient|billing|kredi|bakiye/i.test(e.message);
    botYaz(`Yapay zekâ servisine bağlanılamadı.

Hata: ${e.message}
${kotaMi ? `
⚠️ ÖNEMLİ: ${ad} web/uygulama aboneliğiniz (varsa) API kullanımını
KAPSAMAZ. API erişimi ayrı, kullandıkça ödenen bir bakiye
gerektirir — sağlayıcının kendi sitesinden bakiye/faturalandırma
ayarlarınızı kontrol edin.
` : ""}
Olası nedenler:
· API anahtarı hatalı veya süresi dolmuş
· Hesabınızda API bakiyesi/kredisi kalmamış
· Kullanım limitiniz aşılmış
· Seçtiğiniz model adı hesabınıza tanımlı/erişilebilir değil
· Bu sağlayıcı için Üyelik ve Ayarlar'da anahtar kayıtlı değil

Şimdilik yerel moddan cevaplıyorum.`);
    yerelCevap(soru);
  }
}

/* --------------------------------------------------------- */
/* SUNUCU MODU                                                */
/* --------------------------------------------------------- */
async function sunucuyaSor(soru) {
  const ay = Ayar.oku();
  const url = ay.sunucuAdres;
  if (!url) { botYaz("Sunucu adresi tanımlı değil."); yerelCevap(soru); return; }

  const bekleyen = botYaz("Yanıt hazırlanıyor...");
  const c = window.__ctx || {};

  try {
    const r = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        soru,
        baglam: c,
        bilgi: BILGI[c.standart] || null,
        gecmis: sohbet.slice(-6)
      })
    });
    const d = await r.json();
    if (bekleyen) bekleyen.remove();
    botYaz(d.cevap || "Sunucudan cevap alınamadı.", d.kaynak || c.materyal);
    sohbet.push({ rol: "asistan", metin: d.cevap });
  } catch (e) {
    if (bekleyen) bekleyen.remove();
    botYaz(`Sunucuya bağlanılamadı.

Kontrol edilecekler:
1. server/ klasöründeki sunucu çalışıyor mu?
2. Üyelik sayfasındaki adres doğru mu?
3. Sunucuda API anahtarı tanımlı mı?

Şimdilik yerel moddan cevaplıyorum.`);
    yerelCevap(soru);
  }
}
