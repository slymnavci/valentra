/* =========================================================
   YMM HAZIRLIK PLATFORMU — VERİ KATMANI
   ---------------------------------------------------------
   SINAV ve DERSLER artık burada SABİT KOD olarak değil,
   public/content/dersler.json dosyasından çalışma zamanında
   yükleniyor (bkz. assets/js/icerik-yukle.js). Böylece Yönetim
   panelinden yapılan değişiklikler kod düzenlemeden yayınlanabiliyor.
   Buradaki `let` bildirimleri yalnızca dosya yüklenene kadar
   kullanılan güvenli varsayılanlardır.
   ========================================================= */

let SINAV = {
  ad: "", baslangic: new Date().toISOString().slice(0, 10), bitis: "",
  yer: "", basariSarti: "", basvuru: [], basvuruSistemi: ""
};
let DERSLER = [];

/* Öncelik ağırlıkları: A=%55, B=%30, C=%15 zaman dağılımına göre */
const ONCELIK_AGIRLIK = { A: 55, B: 30, C: 15, D: 0, E: 0 };

const ONCELIK_ACIKLAMA = {
  A: "Zorunlu alan. Soruların yaklaşık üçte ikisi. Toplam sürenin %55'i.",
  B: "İkinci halka. Tanım + ana kural + temel kayıt. Toplam sürenin %30'u.",
  C: "Tarama düzeyi. Sadece tanım ve ana kural. Toplam sürenin %15'i.",
  D: "Çalışılmayacak. Sınavda çıkarsa kısmi puan yeter.",
  E: "Çalışılmayacak. Maliyeti getirisinden yüksek."
};

/* 16 haftalık şablon takvim. Kullanıcının seçtiği derslere göre
   app.js içinde dinamik olarak yeniden hesaplanır. */
const TAKVIM = [
  { hafta: 1,  konu: "Kavramsal Çerçeve + TMS 1", hedef: "Niteliksel özellikler ve unsur tanımları ezber; DKG ayrım kartı", konuId: ["kavramsal-cerceve", "tms-1"] },
  { hafta: 2,  konu: "TFRS 15 — Hasılat (1/2)", hedef: "5 adımlı model ezber; 2 çıkmış soru yazarak çözüm", konuId: ["tfrs-15"] },
  { hafta: 3,  konu: "TFRS 15 — Hasılat (2/2)", hedef: "Özellikli konular (garanti, asil-vekil, iade); 2 çıkmış soru", konuId: ["tfrs-15"] },
  { hafta: 4,  konu: "TMS 2 — Stoklar", hedef: "Normal kapasite sayısal problemi ×3; NGD hesabı ×2", konuId: ["tms-2"] },
  { hafta: 5,  konu: "TMS 16 — MDV (1/2)", hedef: "Maliyet unsurları + amortisman; kısım bazlı amortisman", konuId: ["tms-16"] },
  { hafta: 6,  konu: "TMS 16 — MDV (2/2)", hedef: "Yeniden değerleme kayıtları (artış/azalış/fon çözülmesi) ×4", konuId: ["tms-16"] },
  { hafta: 7,  konu: "TMS 36 — Değer Düşüklüğü", hedef: "NÜB dağıtım problemi ×2; iptal kuralları kartı", konuId: ["tms-36"] },
  { hafta: 8,  konu: "TFRS 16 — Kiralamalar", hedef: "Kiracı yükümlülük itfa cetveli ×2; muafiyetler", konuId: ["tfrs-16"] },
  { hafta: 9,  konu: "TMS 37 — Karşılıklar", hedef: "Üçlü olasılık tablosu; onerous + yeniden yapılandırma", konuId: ["tms-37"] },
  { hafta: 10, konu: "TMS 7 + TMS 10 + TMS 40", hedef: "Dolaylı yöntem nakit akış tablosu ×2; TMS 40 transfer kuralları", konuId: ["tms-7", "tms-10", "tms-40"] },
  { hafta: 11, konu: "TMS 28 + TMS 29", hedef: "Özkaynak yöntemi hareket tablosu; net parasal pozisyon", konuId: ["tms-28", "tms-29"] },
  { hafta: 12, konu: "C grubu tarama", hedef: "Her standart için 1 sayfa özet kartı", konuId: [] },
  { hafta: 13, konu: "Deneme 1–2", hedef: "2 tam dönem sınavı, gerçek sürede, elle yazarak", konuId: [] },
  { hafta: 14, konu: "Deneme 3 + zayıf noktalar", hedef: "1 deneme + hatalı konuların üzerinden geçme", konuId: [] },
  { hafta: 15, konu: "Genel tekrar", hedef: "Tuzak sorular listesi + A grubu hızlı tekrar", konuId: [] },
  { hafta: 16, konu: "Yedek / son tekrar", hedef: "Sadece ezber kartları. Yeni konu açma.", konuId: [] }
];

const STRATEJI = [
  { baslik: "Klasik yazılı — cömert puanlama", metin: "20 puanlık soruya cevap anahtarında 25 puanlık kalem dağıtılmış olabiliyor. BOŞ BIRAKMA, bildiğin her maddeyi yaz." },
  { baslik: "Yorum değil bilgi soruluyor", metin: "Süslü paragraf yazma. MADDE MADDE, BAŞLIKLANDIRARAK yaz. Net olmayan açıklama ve hesaplamalara puan verilmiyor." },
  { baslik: "Üç soru kalıbı", metin: "1) Standardı açıklayınız / şartlarını sayınız. 2) Sayısal olay + muhasebe kaydı. 3) Bu durumda nasıl muhasebeleştirilir, gerekçesiyle açıklayınız." },
  { baslik: "Ters çalışma yöntemi", metin: "Konu anlatımını baştan okuma. Çıkmış soruyu oku, cevabı ELLE YAZ, sonra ilgili standart maddesine bak." },
  { baslik: "İlk adım", metin: "A grubu için son 12 yılın tüm sorularını topla, tek PDF yap. Kaynak toplamayı ilk hafta bitir, sonra sadece çöz." }
];

const MATERYAL_DURUM = {
  "taslak":           { etiket: "Taslak",           renk: "#94a3b8" },
  "kontrol-edilecek": { etiket: "Kontrol edilecek", renk: "#f59e0b" },
  "dogrulandi":       { etiket: "Doğrulandı",       renk: "#10b981" },
  "yayimlandi":       { etiket: "Yayımlandı",       renk: "#2563eb" }
};

/* Tema seçenekleri — üyeler kendi temasını seçebilir (hızlı başlangıç
   presetleri; Görünüm panelinden bunların üzerine renk/font özelleştirmesi
   de yapılabilir — bkz. Ayar.*Ozel alanları ve temaUygula() app.js'te). */
const TEMALAR = {
  "lacivert":  { ad: "Lacivert",  brand: "#1e3a8a", light: "#2563eb", bg: "#f1f5f9" },
  "yesil":     { ad: "Yeşil",     brand: "#065f46", light: "#059669", bg: "#f0fdf4" },
  "bordo":     { ad: "Bordo",     brand: "#7f1d1d", light: "#dc2626", bg: "#fef2f2" },
  "mor":       { ad: "Mor",       brand: "#4c1d95", light: "#7c3aed", bg: "#faf5ff" },
  "antrasit":  { ad: "Antrasit",  brand: "#1e293b", light: "#475569", bg: "#f8fafc" },
  "kahve":     { ad: "Kahve",     brand: "#78350f", light: "#d97706", bg: "#fffbeb" },
  "turkuaz":   { ad: "Turkuaz",   brand: "#0e7490", light: "#06b6d4", bg: "#ecfeff" },
  "indigo":    { ad: "İndigo",    brand: "#3730a3", light: "#6366f1", bg: "#eef2ff" },
  "pembe":     { ad: "Pembe",     brand: "#9d174d", light: "#ec4899", bg: "#fdf2f8" },
  "turuncu":   { ad: "Turuncu",   brand: "#9a3412", light: "#ea580c", bg: "#fff7ed" },
  "gri":       { ad: "Gri",       brand: "#334155", light: "#64748b", bg: "#f8fafc" },
  "zeytin":    { ad: "Zeytin",    brand: "#3f6212", light: "#65a30d", bg: "#f7fee7" },
  "macos":     { ad: "macOS",     brand: "#0a84ff", light: "#409cff", bg: "#f5f5f7", stil: "macos" }
};

/* Görünüm panelindeki Yazı Tipi seçenekleri — hepsi sistemde zaten yüklü
   yazı tipi aileleri (dış kaynak/CDN yüklemesi gerekmez). */
const FONT_SECENEKLERI = {
  "sistem":   { ad: "Sistem (Varsayılan)", stack: `"Segoe UI", Inter, system-ui, -apple-system, sans-serif` },
  "serif":    { ad: "Klasik Serif",        stack: `Georgia, "Times New Roman", serif` },
  "yuvarlak": { ad: "Yuvarlak Modern",     stack: `Verdana, "Trebuchet MS", sans-serif` },
  "kod":      { ad: "Yazı Makinesi",       stack: `"Courier New", Consolas, monospace` },
  "zarif":    { ad: "Zarif",               stack: `"Palatino Linotype", Palatino, "Book Antiqua", serif` },
  "macos":    { ad: "SF Pro (macOS)",      stack: `-apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", Helvetica, Arial, sans-serif` }
};
