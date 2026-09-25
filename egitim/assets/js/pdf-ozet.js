/* =========================================================
   PDF'DEN YAPAY ZEKÂ İLE ÖZET SAYFASI OLUŞTUR
   ---------------------------------------------------------
   PDF.js ile tarayıcıda metin çıkarır, mevcut Ders Asistanı'ndaki
   ile aynı yapay zekâ sağlayıcısını (Üyelik ve Ayarlar'da tanımlı
   kendi API anahtarınız — sunucuda saklanır) kullanarak metni Özet
   Sayfası'na uygun, sınırlı HTML etiketleriyle özetletir. Sonuç
   yalnızca formu doldurur — kaydetmek için hâlâ "Kaydet" düğmesine
   basmanız gerekir.

   assistant.js'deki yapayZekayaSor ile AYNI sunucu proxy'sini
   (kuAiSohbet / ai_sohbet.php) kullanır, ama sohbet arayüzünden
   (botYaz/sohbet) tamamen bağımsızdır — tek seferlik "prompt
   gönder, metin al" fonksiyonu.
   ========================================================= */

if (typeof pdfjsLib !== "undefined") {
  pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.8.162/pdf.worker.min.js";
}

const PDF_YZ_KARAKTER_SINIRI = 50000;

/* PDF dosyasından sayfa sayfa metin çıkarır. */
async function pdfMetinCikar(dosya, ilerlemeCagir) {
  if (typeof pdfjsLib === "undefined") {
    throw new Error("PDF.js kütüphanesi yüklenemedi (internet bağlantınızı kontrol edin).");
  }
  const arrayBuffer = await dosya.arrayBuffer();
  const belge = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
  const sayfaMetinleri = [];
  for (let i = 1; i <= belge.numPages; i++) {
    if (ilerlemeCagir) ilerlemeCagir(i, belge.numPages);
    const sayfa = await belge.getPage(i);
    const icerik = await sayfa.getTextContent();
    sayfaMetinleri.push(icerik.items.map(o => o.str).join(" "));
  }
  return { toplamSayfa: belge.numPages, sayfaMetinleri };
}

/* Seçilen sayfa aralığındaki metni birleştirir, karakter sınırı uygular. */
function pdfMetniBirlestir(sayfaMetinleri, bas, bit, sinirKarakter) {
  const b = Math.max(1, bas || 1);
  const s = Math.min(sayfaMetinleri.length, bit || sayfaMetinleri.length);
  const parcalar = sayfaMetinleri.slice(b - 1, s);
  let metin = parcalar.join("\n\n").trim();
  let kesildi = false;
  const sinir = sinirKarakter || PDF_YZ_KARAKTER_SINIRI;
  if (metin.length > sinir) { metin = metin.slice(0, sinir); kesildi = true; }
  return { metin, kesildi };
}

function pdfYzSistemTalimati(dersAd, konuAd) {
  return `Sen bir Yeminli Mali Müşavirlik (YMM) sınavına hazırlık materyali hazırlayan bir editörsün.
Kullanıcı sana bir PDF'ten çıkarılmış ham metin verecek. Bu metni, "${dersAd || "-"}" dersinin
"${konuAd || "-"}" konusuna ait, sınava hazırlanan bir öğrenci için düzenli bir ÇALIŞMA SAYFASINA dönüştür.

KURALLAR
1. Yalnızca şu HTML etiketlerini kullan: <h2> <h3> <p> <ul> <li> <ol> <blockquote> <b> <i>. Başka hiçbir etiket kullanma.
2. Markdown kullanma (**, #, - gibi işaretler kullanma), yalnızca saf HTML üret.
3. Kod bloğu (\`\`\`) kullanma, açıklama/giriş cümlesi ekleme — doğrudan HTML ile başla.
4. Konuyu başlıklarla (h2/h3) bölümlere ayır, önemli noktaları madde işaretli listelerle (ul/li) vurgula.
5. Sınav tuzaklarını/kritik noktaları varsa <blockquote> içinde belirt.
6. Türkçe yaz, teknik terimleri (TFRS/TMS/VUK madde numaraları vb.) olduğu gibi koru.
7. Süslü paragraflar yazma, net ve teknik ol. Ham metindeki sayfa/satır kesintilerini görmezden gel, anlamlı bir bütün oluştur.`;
}

/* AI anahtarları sunucuda saklanır (kullanici_sir) — istek, kullanici-api.js'deki
   kuAiSohbet() ile ai_sohbet.php proxy'sine gider; UI'dan bağımsız: metni
   döndürür, hata varsa Error fırlatır. */
async function pdfYzOzetIste(saglayici, sistemMetni, kullaniciMetni) {
  const ad = { openai: "ChatGPT", anthropic: "Claude", gemini: "Gemini" }[saglayici] || saglayici;

  if (!["openai", "anthropic", "gemini"].includes(saglayici)) {
    throw new Error(`"${ad}" sağlayıcısı PDF özetleme için desteklenmiyor. Üyelik ve Ayarlar'dan ChatGPT, Claude veya Gemini seçin.`);
  }

  const u = Auth.aktif();
  if (!ppBagliMi() || !u) {
    throw new Error(`${ad} için sunucu bağlantısı kurulu değil. Önce Yönetim → Pratik Sistemi Bağlantısı'nı kurup, Üyelik ve Ayarlar'dan ${ad} API anahtarınızı kaydedin.`);
  }

  try {
    const cevap = await kuAiSohbet(u.kullaniciAdi, saglayici, sistemMetni, [{ rol: "kullanici", metin: kullaniciMetni }]);
    if (!cevap) throw new Error(`${ad} modelinden cevap alınamadı.`);
    return cevap;
  } catch (e) {
    const kotaMi = /quota|insufficient|billing|kredi|bakiye/i.test(e.message);
    throw new Error(`${ad} hatası: ${e.message}${kotaMi ? " (API bakiyenizi/faturalandırmanızı kontrol edin — web/uygulama aboneliği API kullanımını kapsamaz.)" : ""}`);
  }
}

/* Modelden gelen ham metni editöre güvenle basılabilecek HTML'e çevirir. */
function pdfYzHtmlTemizle(ham) {
  let metin = (ham || "").trim();
  metin = metin.replace(/^```(?:html)?\s*/i, "").replace(/```\s*$/i, "").trim();
  if (!/<[a-z][\s\S]*>/i.test(metin)) {
    const d = document.createElement("div");
    d.textContent = metin;
    metin = d.innerHTML.replace(/\n/g, "<br>");
  }
  return metin;
}
