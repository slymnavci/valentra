/* =========================================================
   İÇERİK YÜKLEME
   ---------------------------------------------------------
   SINAV / DERSLER / SORULAR artık sabit kod değil,
   public/content/dersler.json ve public/content/sorular.json
   dosyalarından çalışma zamanında yükleniyor. Yönetim panelinde
   yapılan her değişiklik önce yerel bir taslak olarak saklanır
   (kayıp önleme) ve kısa bir gecikmeyle arka planda doğrudan
   sunucuya (icerik_kaydet.php → public/content/*.json) yazılır —
   ayrı bir "Yayınla" adımına gerek yoktur.
   ========================================================= */

const IY_TASLAK_ANAHTAR = "ymm_taslak_v1";
const IY_TASLAK_ZAMAN_ANAHTAR = "ymm_taslak_zaman_v1";

let ICERIK_HAZIR = false;
let ICERIK_TASLAK_VAR = false;

/* MENÜ — sol menüdeki öğeler (sayfa bağlantısı/bölüm başlığı/özel
   sayfa bağlantısı/çıkış) ve yönetimde oluşturulan özel sayfalar.
   DERSLER/SORULAR ile aynı taslak+senkron akışını paylaşır. */
let MENU_OGELER = [];
let MENU_OZEL_SAYFALAR = [];

function menuVarsayilan() {
  return [
    { id: "panel", tur: "sayfa", route: "panel", ikon: "🏠", etiket: "Ana Sayfa" },
    { id: "dersler", tur: "sayfa", route: "dersler", ikon: "📚", etiket: "Dersler" },
    { id: "test-et", tur: "sayfa", route: "test-et", ikon: "🧠", etiket: "Beni Test Et" },
    { id: "plan", tur: "sayfa", route: "plan", ikon: "📅", etiket: "Çalışma Planı" },
    { id: "takvim", tur: "sayfa", route: "takvim", ikon: "🗓️", etiket: "Takvim" },
    { id: "rapor", tur: "sayfa", route: "rapor", ikon: "📊", etiket: "İlerleme Raporu" },
    { id: "uyelik", tur: "sayfa", route: "uyelik", ikon: "⚙️", etiket: "Üyelik ve Ayarlar" },
    { id: "yonetim", tur: "sayfa", route: "yonetim", ikon: "🛡️", etiket: "Yönetim", sadeceYonetici: true },
    { id: "cikis", tur: "cikis", ikon: "🚪", etiket: "Çıkış Yap" },
    { id: "diger-baslik", tur: "baslik", etiket: "Diğer" },
    { id: "strateji", tur: "sayfa", route: "strateji", ikon: "🎯", etiket: "Sınav Stratejisi" },
    { id: "forum", tur: "sayfa", route: "forum", ikon: "💬", etiket: "Forum" }
  ];
}

/* `cache:"no-store"` yalnızca TARAYICININ kendi önbelleğini devre dışı
   bırakır — IHS gibi paylaşımlı barındırmalarda araya giren bir ters
   proxy/CDN katmanı varsa bu isteği yine de önbellekleyebilir ve
   dosyayı güncelledikten sonra bile eski içeriği döndürebilir. Her
   yüklemede değişen bir sorgu parametresi ekleyerek böyle bir katmanın
   "aynı URL" varsayımını kırıyoruz — admin bir materyal/ders ekleyip
   sunucuya kaydettikten sonra sayfayı yenilediğinde eski/önbelleklenmiş
   bir kopyanın gösterilip "eklediğim şey kayboldu" izlenimi vermesini
   önler. */
function iyOnbellekKirici() { return (Date.now() + Math.random()).toString(36); }

async function icerikYukle() {
  let yayinVeri = null;
  try {
    const r = await fetch("content/dersler.json?_=" + iyOnbellekKirici(), { cache: "no-store" });
    yayinVeri = await r.json();
  } catch (e) {
    console.error("dersler.json yüklenemedi", e);
    yayinVeri = { sinav: SINAV, dersler: [] };
  }

  let sorularVeri = {};
  try {
    const rs = await fetch("content/sorular.json?_=" + iyOnbellekKirici(), { cache: "no-store" });
    sorularVeri = await rs.json();
  } catch (e) {
    console.error("sorular.json yüklenemedi", e);
  }

  let menuVeri = null;
  try {
    const rm = await fetch("content/menu.json?_=" + iyOnbellekKirici(), { cache: "no-store" });
    menuVeri = await rm.json();
  } catch (e) {
    console.error("menu.json yüklenemedi", e);
  }
  if (!menuVeri || !Array.isArray(menuVeri.ogeler)) menuVeri = { ogeler: menuVarsayilan(), ozelSayfalar: [] };

  const taslakRaw = localStorage.getItem(IY_TASLAK_ANAHTAR);
  let veri = yayinVeri, soru = sorularVeri, menu = menuVeri;
  if (taslakRaw) {
    try {
      const taslak = JSON.parse(taslakRaw);
      veri = { sinav: taslak.sinav, dersler: taslak.dersler };
      soru = taslak.sorular || sorularVeri;
      if (taslak.menu && Array.isArray(taslak.menu.ogeler)) menu = taslak.menu;
      ICERIK_TASLAK_VAR = true;
    } catch { /* taslak bozuksa yayınlanan sürüme düş */ }
  }
  SINAV = veri.sinav;
  DERSLER.length = 0; DERSLER.push(...veri.dersler);
  SORULAR = soru;
  MENU_OGELER = menu.ogeler;
  MENU_OZEL_SAYFALAR = menu.ozelSayfalar || [];

  DERSLER.forEach(d => {
    if (d.aktif === undefined) d.aktif = true;
    d.konular.forEach(k => {
      if (!k.materyaller) k.materyaller = [];
      if (!k.ozetSayfalari) k.ozetSayfalari = [];
      if (!k.gorseller) k.gorseller = [];
      if (!k.fotoromanlar) k.fotoromanlar = [];
      k.ozetSayfalari.forEach(iyOzetSayfaGocEttir);
    });
  });

  ICERIK_HAZIR = true;
}

/* Her admin düzenlemesinden sonra çağrılır: mevcut bellek durumunu
   önce yerel taslak olarak kaydeder (kayıp önleme), sonra kısa bir
   gecikmeyle arka planda sunucuya senkronlar. */
function icerikTaslakKaydet() {
  localStorage.setItem(IY_TASLAK_ANAHTAR, JSON.stringify({
    sinav: SINAV, dersler: DERSLER, sorular: SORULAR,
    menu: { ogeler: MENU_OGELER, ozelSayfalar: MENU_OZEL_SAYFALAR }
  }));
  localStorage.setItem(IY_TASLAK_ZAMAN_ANAHTAR, new Date().toISOString());
  ICERIK_TASLAK_VAR = true;
  icerikSunucuyaSenkronlaGecikmeli();
}

let IY_SENKRON_ZAMANLAYICI = null;
/* Son senkron denemesinin hatası — bağlantı VARKEN bile yazma başarısız
   olursa (ör. sunucuda dosya izni sorunu) sessizce konsola düşüp fark
   edilmeden kalmasın diye Yönetim panelinde görünür bir uyarı olarak
   gösterilir (bkz. sayfaYonetim()). Başarılı bir senkronda temizlenir. */
let ICERIK_SON_SENKRON_HATASI = null;

function icerikSunucuyaSenkronlaGecikmeli() {
  if (typeof ppBagliMi !== "function" || !ppBagliMi()) return;
  clearTimeout(IY_SENKRON_ZAMANLAYICI);
  IY_SENKRON_ZAMANLAYICI = setTimeout(() => {
    icerikSunucuyaSenkronla().catch(e => {
      console.warn("İçerik sunucuya kaydedilemedi (yerel taslak korunuyor):", e.message);
      ICERIK_SON_SENKRON_HATASI = e.message;
      if (typeof yonlendir === "function" && location.hash.replace("#", "").startsWith("yonetim")) yonlendir();
    });
  }, 900);
}

/* Bekleyen zamanlayıcıyı iptal edip hemen senkronlar (örn. sayfadan ayrılırken). */
async function icerikSunucuyaSenkronla() {
  clearTimeout(IY_SENKRON_ZAMANLAYICI);
  await kuIcerikKaydet({ sinav: SINAV, dersler: DERSLER }, SORULAR, { ogeler: MENU_OGELER, ozelSayfalar: MENU_OZEL_SAYFALAR });
  icerikTaslakTemizle();
  ICERIK_SON_SENKRON_HATASI = null;
}

function icerikTaslakZamani() {
  const z = localStorage.getItem(IY_TASLAK_ZAMAN_ANAHTAR);
  if (!z) return null;
  const d = new Date(z);
  return d.toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit" });
}

function icerikTaslakTemizle() {
  localStorage.removeItem(IY_TASLAK_ANAHTAR);
  localStorage.removeItem(IY_TASLAK_ZAMAN_ANAHTAR);
  ICERIK_TASLAK_VAR = false;
}

function iyBenzersizId(on) { return on + "-" + Date.now().toString(36) + Math.random().toString(36).slice(2, 7); }
function iySlugYap(metin) {
  const harita = { ç: "c", ğ: "g", ı: "i", ö: "o", ş: "s", ü: "u", İ: "i", Ç: "c", Ğ: "g", Ö: "o", Ş: "s", Ü: "u" };
  return metin.split("").map(c => harita[c] || c).join("").toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "") || iyBenzersizId("id");
}
/* Seçilen dosyayı doğrudan sunucuya (materyaller/) yükler ve gerçek
   sunucu yolunu geriCagir(yol, adi) ile döner. Eskiden dosyalar
   base64 olarak tarayıcı belleğine/localStorage'a gömülüyordu — bu,
   gerçek boyutlu bir PDF/görselde localStorage kotasını aşıp yüklemeyi
   sessizce bozuyordu. Artık gerçek dosya, gerçek bir yola kaydediliyor. */
async function iyDosyaYukle(inputEl, geriCagir, durumEl) {
  const f = inputEl.files && inputEl.files[0];
  if (!f) return;
  if (typeof ppBagliMi !== "function" || !ppBagliMi()) {
    alert("Dosya yüklemek için önce Üyelik ve Ayarlar sayfasından sunucu bağlantısını kurmanız gerekir.");
    inputEl.value = "";
    return;
  }
  if (durumEl) durumEl.textContent = "Yükleniyor…";
  try {
    const sonuc = await kuDosyaYukle(f);
    if (durumEl) durumEl.textContent = "✅ Yüklendi: " + sonuc.ad;
    geriCagir(sonuc.yol, sonuc.ad);
  } catch (e) {
    if (durumEl) durumEl.textContent = "";
    alert("Dosya yüklenemedi: " + e.message);
    inputEl.value = "";
  }
}

/* =========================================================
   İÇERİK DÜZENLEME — DERSLER/SORULAR üzerinde doğrudan mutasyon.
   Her fonksiyon işi bitince icerikTaslakKaydet() çağırır, böylece
   hiçbir değişiklik kaybolmaz (Yayınla'ya kadar yerel taslakta durur).
   ========================================================= */

/* ---------- DERS ---------- */
function iyDersEkle({ ad, ikon, renk, aciklama, hedefNot }) {
  const id = iySlugYap(ad);
  if (DERSLER.some(d => d.id === id)) return { ok: false, mesaj: "Bu isimde bir ders zaten var." };
  DERSLER.push({
    id, ad, ikon: ikon || "📘", renk: renk || "#2563eb", aciklama: aciklama || "",
    hedefNot: +hedefNot || 65, aktif: true, konular: []
  });
  icerikTaslakKaydet();
  return { ok: true, id };
}
function iyDersGuncelle(dersId, alanlar) {
  const d = DERSLER.find(x => x.id === dersId);
  if (!d) return;
  Object.assign(d, alanlar);
  icerikTaslakKaydet();
}
function iyDersSil(dersId) {
  const i = DERSLER.findIndex(x => x.id === dersId);
  if (i === -1) return;
  DERSLER.splice(i, 1);
  delete SORULAR[dersId];
  icerikTaslakKaydet();
}
function iyDersSirala(dersId, yon) {
  const i = DERSLER.findIndex(x => x.id === dersId);
  const hedef = i + yon;
  if (i === -1 || hedef < 0 || hedef >= DERSLER.length) return;
  [DERSLER[i], DERSLER[hedef]] = [DERSLER[hedef], DERSLER[i]];
  icerikTaslakKaydet();
}

/* ---------- KONU ---------- */
function iyKonuEkle(dersId, { ad, oncelik, ustKonuId }) {
  const d = DERSLER.find(x => x.id === dersId);
  if (!d) return { ok: false, mesaj: "Ders bulunamadı." };
  const id = iySlugYap(ad);
  if (d.konular.some(k => k.id === id)) return { ok: false, mesaj: "Bu isimde bir konu zaten var." };
  const konu = {
    id, ad, standart: ad, tamAd: ad, oncelik: oncelik || "B",
    cikmaOrani: 0, tahminiSoru: 0,
    materyaller: [], ozetSayfalari: [], gorseller: [], fotoromanlar: []
  };
  if (ustKonuId) konu.ustKonuId = ustKonuId;
  d.konular.push(konu);
  icerikTaslakKaydet();
  return { ok: true, id };
}
function iyKonuGuncelle(dersId, konuId, alanlar) {
  const d = DERSLER.find(x => x.id === dersId);
  const k = d && d.konular.find(x => x.id === konuId);
  if (!k) return;
  Object.assign(k, alanlar);
  icerikTaslakKaydet();
}
function iyKonuSil(dersId, konuId) {
  const d = DERSLER.find(x => x.id === dersId);
  if (!d) return;
  d.konular = d.konular.filter(k => k.id !== konuId && k.ustKonuId !== konuId);
  if (SORULAR[dersId]) delete SORULAR[dersId][konuId];
  icerikTaslakKaydet();
}
function iyKonuSirala(dersId, konuId, yon) {
  const d = DERSLER.find(x => x.id === dersId);
  if (!d) return;
  const i = d.konular.findIndex(k => k.id === konuId);
  const hedef = i + yon;
  if (i === -1 || hedef < 0 || hedef >= d.konular.length) return;
  [d.konular[i], d.konular[hedef]] = [d.konular[hedef], d.konular[i]];
  icerikTaslakKaydet();
}

function iyKonuBul(konuId) { return DERSLER.flatMap(d => d.konular).find(x => x.id === konuId) || null; }

/* ---------- MATERYAL (PDF) ---------- */
function iyMateryalEkle(konuId, { ad, dosya, toplamSayfa }) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  k.materyaller.push({
    id: iyBenzersizId("materyal"), ad, dosya, toplamSayfa: Math.max(1, +toplamSayfa || 1),
    tip: "pdf", durum: "kontrol-edilecek", bolumler: []
  });
  icerikTaslakKaydet();
}
function iyMateryalSil(konuId, materyalId) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  k.materyaller = k.materyaller.filter(m => m.id !== materyalId);
  icerikTaslakKaydet();
}

/* ---------- MATERYAL BÖLÜMLERİ (sayfa aralığı -> alt konu) ---------- */
function iyMateryalBolumEkle(konuId, materyalId, { ad, bas, bit }) {
  const k = iyKonuBul(konuId);
  const m = k && k.materyaller.find(x => x.id === materyalId);
  if (!m) return;
  if (!m.bolumler) m.bolumler = [];
  m.bolumler.push({ ad, bas: Math.max(1, +bas || 1), bit: Math.max(1, +bit || 1) });
  m.bolumler.sort((a, b) => a.bas - b.bas);
  icerikTaslakKaydet();
}
function iyMateryalBolumSil(konuId, materyalId, idx) {
  const k = iyKonuBul(konuId);
  const m = k && k.materyaller.find(x => x.id === materyalId);
  if (!m || !m.bolumler) return;
  m.bolumler.splice(idx, 1);
  icerikTaslakKaydet();
}

/* ---------- ÖZET SAYFALARI ---------- */
/* Eski şekil (düz metin `icerik` + tekil `gorsel`) yeni şekle
   (zengin HTML `icerik` + çoklu `gorseller`) tek seferlik göçürülür.
   `icerik` içinde etiket yoksa (eski düz metin) güvenle HTML'e çevrilir. */
function iyOzetSayfaGocEttir(s) {
  if (!s.gorseller) {
    s.gorseller = s.gorsel ? [{ id: iyBenzersizId("gorsel"), dosya: s.gorsel, baslik: "" }] : [];
    delete s.gorsel;
  }
  if (s.icerik && !/<[a-z][\s\S]*>/i.test(s.icerik)) {
    const d = document.createElement("div");
    d.textContent = s.icerik;
    s.icerik = d.innerHTML.replace(/\n/g, "<br>");
  }
  if (!s.duzen) s.duzen = "gorsel-ust";
  if (!s.tip) s.tip = "Özet";
  if (!s.oncelik) s.oncelik = "B";
  if (!s.durum) s.durum = "yayinda";
}
function iyOzetSayfaEkle(konuId, sayfa) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  k.ozetSayfalari.push({
    baslik: sayfa.baslik || "", icerik: sayfa.icerik || "",
    gorseller: sayfa.gorseller || [], duzen: sayfa.duzen || "gorsel-ust",
    tip: sayfa.tip || "Özet", oncelik: sayfa.oncelik || "B", durum: sayfa.durum || "yayinda"
  });
  icerikTaslakKaydet();
}
function iyOzetSayfaGuncelle(konuId, idx, sayfa) {
  const k = iyKonuBul(konuId);
  if (!k || !k.ozetSayfalari[idx]) return;
  k.ozetSayfalari[idx] = {
    baslik: sayfa.baslik || "", icerik: sayfa.icerik || "",
    gorseller: sayfa.gorseller || [], duzen: sayfa.duzen || "gorsel-ust",
    tip: sayfa.tip || "Özet", oncelik: sayfa.oncelik || "B", durum: sayfa.durum || "yayinda"
  };
  icerikTaslakKaydet();
}
function iyOzetSayfaSil(konuId, idx) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  k.ozetSayfalari.splice(idx, 1);
  icerikTaslakKaydet();
}
function iyOzetSayfaTasi(konuId, idx, yon) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  const hedef = idx + yon;
  if (hedef < 0 || hedef >= k.ozetSayfalari.length) return;
  [k.ozetSayfalari[idx], k.ozetSayfalari[hedef]] = [k.ozetSayfalari[hedef], k.ozetSayfalari[idx]];
  icerikTaslakKaydet();
}
/* ---------- GÖRSELLER ---------- */
function iyGorselEkle(konuId, { dosya, baslik }) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  k.gorseller.push({ id: iyBenzersizId("gorsel"), dosya, baslik: baslik || "" });
  icerikTaslakKaydet();
}
function iyGorselSil(konuId, gorselId) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  k.gorseller = k.gorseller.filter(g => g.id !== gorselId);
  icerikTaslakKaydet();
}

/* ---------- FOTOROMANLAR ---------- */
function iyFotoromanEkle(konuId, baslik) {
  const k = iyKonuBul(konuId);
  if (!k) return null;
  const fr = { id: iyBenzersizId("foto"), baslik: baslik || "Fotoroman", kareler: [] };
  k.fotoromanlar.push(fr);
  icerikTaslakKaydet();
  return fr.id;
}
function iyFotoromanSil(konuId, frId) {
  const k = iyKonuBul(konuId);
  if (!k) return;
  k.fotoromanlar = k.fotoromanlar.filter(f => f.id !== frId);
  icerikTaslakKaydet();
}
function iyFotoromanKareEkle(konuId, frId, kare) {
  const k = iyKonuBul(konuId);
  const fr = k && k.fotoromanlar.find(f => f.id === frId);
  if (!fr) return;
  fr.kareler.push({ gorsel: kare.gorsel || "", metin: kare.metin || "" });
  icerikTaslakKaydet();
}
function iyFotoromanKareSil(konuId, frId, kareIdx) {
  const k = iyKonuBul(konuId);
  const fr = k && k.fotoromanlar.find(f => f.id === frId);
  if (!fr) return;
  fr.kareler.splice(kareIdx, 1);
  icerikTaslakKaydet();
}

/* ---------- SORULAR / BİLGİ KARTLARI ----------
   tip: "cok_secmeli" (varsayılan, o+d) veya "bilgi_karti" (cevap). */
function iySoruNesnesiKur(soru) {
  const tip = soru.tip === "bilgi_karti" ? "bilgi_karti" : "cok_secmeli";
  const item = {
    id: iyBenzersizId("soru-ek"), bolum: soru.bolum || "Ek Sorular",
    s: soru.s, tip, aciklama: soru.aciklama || ""
  };
  if (tip === "bilgi_karti") item.cevap = soru.cevap;
  else { item.o = soru.o; item.d = soru.d; }
  return item;
}
function iySoruEkle(dersId, konuId, soru) {
  if (!SORULAR[dersId]) SORULAR[dersId] = {};
  if (!SORULAR[dersId][konuId]) SORULAR[dersId][konuId] = [];
  SORULAR[dersId][konuId].push(iySoruNesnesiKur(soru));
  icerikTaslakKaydet();
}
/* CSV içe aktarma gibi toplu eklemeler için — tek seferde senkronlar. */
function iySorularTopluEkle(dersId, konuId, sorular) {
  if (!SORULAR[dersId]) SORULAR[dersId] = {};
  if (!SORULAR[dersId][konuId]) SORULAR[dersId][konuId] = [];
  sorular.forEach(soru => SORULAR[dersId][konuId].push(iySoruNesnesiKur(soru)));
  icerikTaslakKaydet();
}
function iySoruSil(dersId, konuId, soruId) {
  if (SORULAR[dersId] && SORULAR[dersId][konuId])
    SORULAR[dersId][konuId] = SORULAR[dersId][konuId].filter(q => q.id !== soruId);
  icerikTaslakKaydet();
}

/* ---------- MENÜ ÖĞELERİ ---------- */
function iyMenuOgeEkle(oge) {
  MENU_OGELER.push({ id: iyBenzersizId("menuoge"), gorunur: true, ...oge });
  icerikTaslakKaydet();
}
function iyMenuOgeGuncelle(id, alanlar) {
  const o = MENU_OGELER.find(x => x.id === id);
  if (!o) return;
  Object.assign(o, alanlar);
  icerikTaslakKaydet();
}
function iyMenuOgeSil(id) {
  MENU_OGELER = MENU_OGELER.filter(x => x.id !== id);
  icerikTaslakKaydet();
}
function iyMenuOgeSirala(id, yon) {
  const i = MENU_OGELER.findIndex(x => x.id === id);
  const hedef = i + yon;
  if (i === -1 || hedef < 0 || hedef >= MENU_OGELER.length) return;
  [MENU_OGELER[i], MENU_OGELER[hedef]] = [MENU_OGELER[hedef], MENU_OGELER[i]];
  icerikTaslakKaydet();
}
/* ---------- ALT MENÜLER (bir menü öğesinin, üzerine gelindiğinde açılan
   açılır/flyout alt listesi — bkz. app.js: menuCiz/flyoutMenuHtml) ---------- */
function iyAltMenuEkle(anaId, altOge) {
  const o = MENU_OGELER.find(x => x.id === anaId);
  if (!o) return;
  if (!Array.isArray(o.altMenu)) o.altMenu = [];
  o.altMenu.push({ id: iyBenzersizId("altoge"), ...altOge });
  icerikTaslakKaydet();
}
function iyAltMenuGuncelle(anaId, altId, alanlar) {
  const o = MENU_OGELER.find(x => x.id === anaId);
  const a = o && o.altMenu && o.altMenu.find(x => x.id === altId);
  if (!a) return;
  Object.assign(a, alanlar);
  icerikTaslakKaydet();
}
function iyAltMenuSil(anaId, altId) {
  const o = MENU_OGELER.find(x => x.id === anaId);
  if (!o || !o.altMenu) return;
  o.altMenu = o.altMenu.filter(x => x.id !== altId);
  icerikTaslakKaydet();
}
function iyAltMenuSirala(anaId, altId, yon) {
  const o = MENU_OGELER.find(x => x.id === anaId);
  if (!o || !o.altMenu) return;
  const i = o.altMenu.findIndex(x => x.id === altId);
  const hedef = i + yon;
  if (i === -1 || hedef < 0 || hedef >= o.altMenu.length) return;
  [o.altMenu[i], o.altMenu[hedef]] = [o.altMenu[hedef], o.altMenu[i]];
  icerikTaslakKaydet();
}

function iyMenuOgeGorunurlukDegistir(id) {
  const o = MENU_OGELER.find(x => x.id === id);
  if (!o) return;
  o.gorunur = o.gorunur === false;
  icerikTaslakKaydet();
}

/* ---------- ÖZEL SAYFALAR ---------- */
function iyOzelSayfaEkle({ baslik, icerik }) {
  const slug = iySlugYap(baslik);
  if (MENU_OZEL_SAYFALAR.some(s => s.slug === slug)) return { ok: false, mesaj: "Bu başlıkta bir özel sayfa zaten var." };
  MENU_OZEL_SAYFALAR.push({ slug, baslik, icerik: icerik || "" });
  icerikTaslakKaydet();
  return { ok: true, slug };
}
function iyOzelSayfaGuncelle(slug, { baslik, icerik }) {
  const s = MENU_OZEL_SAYFALAR.find(x => x.slug === slug);
  if (!s) return;
  s.baslik = baslik || s.baslik;
  s.icerik = icerik || "";
  icerikTaslakKaydet();
}
function iyOzelSayfaSil(slug) {
  MENU_OZEL_SAYFALAR = MENU_OZEL_SAYFALAR.filter(x => x.slug !== slug);
  MENU_OGELER = MENU_OGELER.filter(o => !(o.tur === "ozel-sayfa" && o.slug === slug));
  icerikTaslakKaydet();
}
