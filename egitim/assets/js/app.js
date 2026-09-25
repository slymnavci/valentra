/* =========================================================
   YMM HAZIRLIK PLATFORMU — UYGULAMA ÇEKİRDEĞİ
   ========================================================= */

/* ---------------------------------------------------------
   1. KULLANICI BAZLI DEPOLAMA
   Her kullanıcının verisi ayrı anahtarda tutulur.
   --------------------------------------------------------- */
function veriAnahtari() {
  const u = Auth.aktif();
  return "ymm_veri_" + (u ? u.kullaniciAdi : "misafir");
}

const DB = {
  bos() {
    return {
      sayfalar: {},      // materyalId -> { sayfaNo: tarih }
      gecmis: {},        // "2026-08-27" -> okunan sayfa
      sinavlar: {},      // konuId -> [ {tarih, puan, dogru, toplam, gecti} ]
      sonCalisma: null,
      planSifirlama: {}  // konuId -> tarih (başarısız sınav sonrası)
    };
  },
  yukle() {
    try { return Object.assign(DB.bos(), JSON.parse(localStorage.getItem(veriAnahtari())) || {}); }
    catch { return DB.bos(); }
  },
  kaydet(d) { localStorage.setItem(veriAnahtari(), JSON.stringify(d)); },
  sifirla() { localStorage.removeItem(veriAnahtari()); }
};

let state = DB.bos();

/* ---------------------------------------------------------
   2. KULLANICI AYARLARI
   --------------------------------------------------------- */
function ayarAnahtari() {
  const u = Auth.aktif();
  return "ymm_ayar_" + (u ? u.kullaniciAdi : "misafir");
}

const Ayar = {
  varsayilan() {
    return {
      tema: "lacivert",
      /* Görünüm panelinden yapılan ince ayar — dolu olduğunda ilgili
         tema alanının (brand/light/bg) yerine geçer, boşsa (null) seçili
         preset (tema) aynen uygulanır. Bir preset seçildiğinde bu dört
         alan sıfırlanır (temaPresetUygula). */
      vurguOzel: null,
      vurguAcikOzel: null,
      menuArkaPlanOzel: null,
      sayfaArkaPlanOzel: null,
      fontOzel: null,          // FONT_SECENEKLERI anahtarı ya da null (sistem varsayılanı)
      okumaGenisligi: 65,      // yüzde — okuma alanının payı
      yaziBoyutu: 15,
      menuGizli: false,
      ozetMenuGizli: false,
      aiGizli: false,
      /* Yalnızca "macos" teması seçiliyken Görünüm panelinde gösterilen ek
         seçenek — kenar çubuğunu alt Dock'a, "Dersler" gezinmesini üzerine
         gelince/tıklayınca açılan katmanlı pencerelere çevirir (bkz.
         temaUygula(), menuCiz(), macDockCiz/macPencereAc). Varsayılan
         kapalı — macOS teması seçmek bunu otomatik açmaz. */
      macMasaustu: false,
      /* Dock varsayılan olarak sürekli görünür kalır; açıldığında eski
         "imleç üzerine gelince göster" davranışına döner (bkz. temaUygula(),
         macos-theme.css [data-mac-dock-oto-gizle]). */
      macDockOtomatikGizle: false,
      /* Ana Sayfa'da (masaüstü modu) arkada gösterilen duvar kağıdı —
         MAC_DUVAR_KAGITLARI'ndaki bir anahtar. bkz. temaSecimHtml(). */
      macDuvarKagidi: "mavi",
      aiSaglayici: "yerel",    // yerel | openai | anthropic | gemini | sunucu
      /* NOT: API anahtarları burada TUTULMAZ — sunucuda (kullanici_sir),
         yalnızca yazılabilir, istemciye asla geri okunmaz. Model adları
         gizli değildir, kolay erişim için burada da (sunucuyla senkron)
         tutulur — bkz. aiSirDurumYukle(). */
      openaiModel: "gpt-4o-mini",
      anthropicModel: "claude-sonnet-5",
      geminiModel: "gemini-3.1-flash-lite",
      sunucuAdres: "",
      secilenDersler: ["ileri-finansal-muhasebe"],
      sinavTarihi: SINAV.baslangic,
      /* Kullanıcının Takvim sayfasından kendi eliyle kurduğu çalışma
         planı — { id, baslangic, bitis (ISO tarih), dersId, konuId }.
         Otomatik `planOlustur()` algoritmasının yanında, isteğe bağlı
         bir kişisel/manuel katman; Ayar'ın parçası olduğu için mevcut
         sunucu senkronu (kullanici_ayar) üzerinden otomatik kalıcı olur. */
      ellePlan: []
    };
  },
  oku() {
    try { return Object.assign(Ayar.varsayilan(), JSON.parse(localStorage.getItem(ayarAnahtari())) || {}); }
    catch { return Ayar.varsayilan(); }
  },
  yaz(a) { localStorage.setItem(ayarAnahtari(), JSON.stringify(a)); temaUygula(); kuAyarSenkronla(a); },
  guncelle(alan, deger) { const a = Ayar.oku(); a[alan] = deger; Ayar.yaz(a); }
};

/* ---------------------------------------------------------
   2b. BACKEND SENKRONU (FAZ 1)
   ---------------------------------------------------------
   localStorage her zaman anında ve önce yazılır — backend
   erişilemez/tanımsız olsa bile arayüz hiçbir şekilde etkilenmez.
   Backend'e yazma "en iyi çaba" (fire-and-forget) ile arkaplanda
   olur. Bağlantı, Pratik sistemiyle AYNI anahtardır (ppBagliMi) —
   Yönetim → Pratik Sistemi Bağlantısı'nda anahtar tanımlıysa bu
   senkron da otomatik çalışır, ayrı bir ayar gerekmez.
   --------------------------------------------------------- */
function kuIlerlemeSenkronla(materyalId, sayfa, deger) {
  if (!ppBagliMi()) return;
  const u = Auth.aktif();
  if (!u) return;
  kuIlerlemeIsaretle(u.kullaniciAdi, materyalId, sayfa, deger)
    .catch(e => console.warn("İlerleme senkronu başarısız (yerelde kayıtlı kaldı):", e.message));
}

function kuKonuSinavSenkronla(konuId, istemciId, dogru, toplam, puan, gecti) {
  if (!ppBagliMi()) return;
  const u = Auth.aktif();
  if (!u) return;
  kuKonuSinavKaydet(u.kullaniciAdi, konuId, istemciId, dogru, toplam, puan, gecti)
    .catch(e => console.warn("Sınav senkronu başarısız (yerelde kayıtlı kaldı):", e.message));
}

/* İki sınav kaydının aynı denemeye ait olup olmadığını belirler.
   İkisinde de istemciId varsa (yeni kayıtlar) kesin eşleşme; yoksa
   (eski/göç edilmemiş kayıtlar) tarih+puan ile en iyi çaba eşleşmesi. */
function sinavKaydiAyni(a, b) {
  if (a.istemciId && b.istemciId) return a.istemciId === b.istemciId;
  return a.tarih === b.tarih && a.puan === b.puan;
}

function kuAyarSenkronla(a) {
  if (!ppBagliMi()) return;
  const u = Auth.aktif();
  if (!u) return;
  kuAyarKaydet(u.kullaniciAdi, a)
    .catch(e => console.warn("Ayar senkronu başarısız (yerelde kayıtlı kaldı):", e.message));
}

/* AI anahtarları artık sunucuda (kullanici_sir) — hangi sağlayıcının
   tanımlı olduğunu (anahtarın kendisini DEĞİL) önbelleğe alır. sayfaUyelik
   ve pdfYzSaglayiciVarMi eşzamanlı render sırasında bu önbelleği okur;
   ilk çağrıda arka planda yüklenir ve geldiğinde sayfa yeniden çizilir. */
let AI_SIR_DURUM = null;
function aiSirDurumYukle() {
  if (AI_SIR_DURUM !== null) return;
  const u = Auth.aktif();
  if (!ppBagliMi() || !u) { AI_SIR_DURUM = {}; return; }
  AI_SIR_DURUM = {};
  kuSirDurum(u.kullaniciAdi).then(d => {
    AI_SIR_DURUM = d;
    const rota = location.hash.replace("#", "").split("/")[0];
    if (rota === "uyelik" || rota === "yonetim") yonlendir();
  }).catch(() => { AI_SIR_DURUM = {}; });
}

/* Girişten/oturum devamından sonra ÇAĞRI BAŞINA BİR KEZ çalışır:
   backend'deki ilerleme/sınav/ayar verisini yerelle birleştirir
   (iki cihazda da işaretlenen sayfa/sınav kaybolmaz), sonra yerelde
   olup backend'de henüz olmayan kayıtları backend'e iter (ilk
   bağlantıda tek seferlik geçmiş veri taşıma). */
let KU_SENKRON_YAPILDI = false;
async function kullaniciSenkronBaslat() {
  if (KU_SENKRON_YAPILDI || !ppBagliMi()) return;
  const u = Auth.aktif();
  if (!u) return;
  KU_SENKRON_YAPILDI = true;

  try {
    const [uzakSayfalar, uzakSinavlar, uzakAyar] = await Promise.all([
      kuIlerlemeGetir(u.kullaniciAdi), kuKonuSinavGetir(u.kullaniciAdi), kuAyarGetir(u.kullaniciAdi)
    ]);

    const yerelSayfalarYedek = JSON.parse(JSON.stringify(state.sayfalar));
    Object.keys(uzakSayfalar).forEach(mid => {
      if (!state.sayfalar[mid]) state.sayfalar[mid] = {};
      Object.keys(uzakSayfalar[mid]).forEach(sayfaNo => {
        const zatenYereldeVarMi = yerelSayfalarYedek[mid] && yerelSayfalarYedek[mid][sayfaNo] !== undefined;
        state.sayfalar[mid][sayfaNo] = uzakSayfalar[mid][sayfaNo];
        if (!zatenYereldeVarMi) {
          /* Başka bir cihazda okunmuş sayfa: günlük çalışma sayacına (state.gecmis) da işlensin */
          const gun = String(uzakSayfalar[mid][sayfaNo]).slice(0, 10);
          state.gecmis[gun] = (state.gecmis[gun] || 0) + 1;
        }
      });
    });

    /* Eski (senkron öncesi) kayıtların hepsine kalıcı bir istemci kimliği ata,
       böylece bundan sonraki her senkron kesin eşleşmeyle çift kayıt oluşturmaz. */
    Object.keys(state.sinavlar).forEach(konuId => {
      state.sinavlar[konuId].forEach(k => { if (!k.istemciId) k.istemciId = iyBenzersizId("sinav"); });
    });

    const yerelSinavlarYedek = JSON.parse(JSON.stringify(state.sinavlar));
    Object.keys(uzakSinavlar).forEach(konuId => {
      const birlesik = [...(state.sinavlar[konuId] || [])];
      uzakSinavlar[konuId].forEach(k => {
        if (!birlesik.some(y => sinavKaydiAyni(y, k))) birlesik.push(k);
      });
      birlesik.sort((a, b) => new Date(a.tarih) - new Date(b.tarih));
      state.sinavlar[konuId] = birlesik;
    });

    DB.kaydet(state);

    /* Yereldeki, backend'de henüz olmayan kayıtları geçmişe dönük it */
    Object.keys(yerelSayfalarYedek).forEach(mid => {
      Object.keys(yerelSayfalarYedek[mid]).forEach(sayfaNo => {
        if (!(uzakSayfalar[mid] && uzakSayfalar[mid][sayfaNo] !== undefined)) {
          kuIlerlemeIsaretle(u.kullaniciAdi, mid, +sayfaNo, true).catch(() => {});
        }
      });
    });
    Object.keys(yerelSinavlarYedek).forEach(konuId => {
      const uzak = uzakSinavlar[konuId] || [];
      yerelSinavlarYedek[konuId].forEach(k => {
        if (!uzak.some(u2 => sinavKaydiAyni(u2, k))) {
          kuKonuSinavKaydet(u.kullaniciAdi, konuId, k.istemciId, k.dogru, k.toplam, k.puan, k.gecti).catch(() => {});
        }
      });
    });

    /* Ayarlar: backend'de kayıt varsa uygula, yoksa yereldekini ilk kez yaz */
    if (uzakAyar) {
      const birlesikAyar = Object.assign(Ayar.varsayilan(), Ayar.oku(), uzakAyar);
      localStorage.setItem(ayarAnahtari(), JSON.stringify(birlesikAyar));
      temaUygula();
    } else {
      kuAyarKaydet(u.kullaniciAdi, Ayar.oku()).catch(() => {});
    }

    yonlendir();
  } catch (e) {
    console.warn("Kullanıcı verisi senkronizasyonu başarısız, yerel veriyle devam ediliyor:", e.message);
  }
}

function temaUygula() {
  const a = Ayar.oku();
  const t = TEMALAR[a.tema] || TEMALAR["lacivert"];
  const f = FONT_SECENEKLERI[a.fontOzel] || FONT_SECENEKLERI["sistem"];
  const r = document.documentElement;
  r.style.setProperty("--brand", a.vurguOzel || t.brand);
  r.style.setProperty("--brand-light", a.vurguAcikOzel || t.light);
  r.style.setProperty("--bg", a.sayfaArkaPlanOzel || t.bg);
  r.style.setProperty("--menu-bg", a.menuArkaPlanOzel || "#ffffff");
  r.style.setProperty("--font", f.stack);
  r.style.setProperty("--govde-boyut", a.yaziBoyutu + "px");
  document.body.classList.toggle("menu-gizli", a.menuGizli);
  /* Bazı temalar (ör. macOS) yalnızca renk değil, tüm görsel dili (pencere
     çerçevesi, açılır pencere/menü şekli) değiştirir — bu, macos-theme.css'in
     bağlandığı bir öznitelik. Preset "stil" alanı yoksa kaldırılır. */
  if (t.stil) r.setAttribute("data-tema-stil", t.stil); else r.removeAttribute("data-tema-stil");
  /* Masaüstü modu yalnızca macOS teması seçiliyken anlamlıdır — bkz.
     macDockCiz()/macPencereAc() (menuCiz() sonunda çağrılır). */
  if (t.stil === "macos" && a.macMasaustu) r.setAttribute("data-mac-masaustu", "");
  else r.removeAttribute("data-mac-masaustu");
  /* Dock varsayılan olarak sürekli görünür; bu işaretlendiğinde (Yönetim
     panelindeki "Dock'u otomatik gizle" seçeneği) imleç üzerine gelene
     kadar ekranın altında gizlenir — bkz. macos-theme.css. */
  if (t.stil === "macos" && a.macMasaustu && a.macDockOtomatikGizle) r.setAttribute("data-mac-dock-oto-gizle", "");
  else r.removeAttribute("data-mac-dock-oto-gizle");
  const duvar = $("#macMasaustuDuvar");
  if (duvar) {
    const dk = MAC_DUVAR_KAGITLARI[a.macDuvarKagidi] || MAC_DUVAR_KAGITLARI.mavi;
    duvar.style.backgroundImage = `url("${dk.dosya}")`;
  }
}

/* Ana Sayfa'da (macOS masaüstü modu) arka plan olarak seçilebilen duvar
   kağıtları — ilk dört tanesi sade/gradyan üslupta hand-authored SVG,
   sonraki dördü kullanıcının yüklediği fotoğraf/illüstrasyonlar. */
const MAC_DUVAR_KAGITLARI = {
  mavi: { ad: "Mavi Tepeler", dosya: "assets/img/mac-masaustu-duvar.svg" },
  gunbatimi: { ad: "Gün Batımı", dosya: "assets/img/mac-masaustu-duvar-gunbatimi.svg" },
  gece: { ad: "Gece", dosya: "assets/img/mac-masaustu-duvar-gece.svg" },
  yesil: { ad: "Orman Yeşili", dosya: "assets/img/mac-masaustu-duvar-yesil.svg" },
  tablo: { ad: "Klasik Tablo", dosya: "assets/img/mac-masaustu-duvar-tablo.webp" },
  efes: { ad: "Efes Antik Kenti", dosya: "assets/img/mac-masaustu-duvar-efes.webp" },
  sakinlik: { ad: "Sakinlik", dosya: "assets/img/mac-masaustu-duvar-sakinlik.webp" },
  huzur: { ad: "Gün Batımı Silueti", dosya: "assets/img/mac-masaustu-duvar-huzur.webp" }
};

/* ---------------------------------------------------------
   3. İLERLEME MOTORU
   --------------------------------------------------------- */
const okunanSayfa = mid => state.sayfalar[mid] ? Object.keys(state.sayfalar[mid]).length : 0;
const sayfaOkundu = (mid, s) => !!(state.sayfalar[mid] && state.sayfalar[mid][s]);

function sayfaIsaretle(mid, sayfa, deger) {
  if (!state.sayfalar[mid]) state.sayfalar[mid] = {};
  if (deger) {
    if (!state.sayfalar[mid][sayfa]) {
      state.sayfalar[mid][sayfa] = new Date().toISOString();
      const b = new Date().toISOString().slice(0, 10);
      state.gecmis[b] = (state.gecmis[b] || 0) + 1;
    }
  } else delete state.sayfalar[mid][sayfa];
  DB.kaydet(state);
  kuIlerlemeSenkronla(mid, sayfa, deger);
}

function materyalIlerleme(m) {
  return m.toplamSayfa ? Math.round(okunanSayfa(m.id) / m.toplamSayfa * 100) : 0;
}

/* Son okunan sayfanın hemen sonrası — "Çalışmaya Başla" son kaldığı yerden devam eder. */
function materyalDevamSayfasi(m) {
  const sayfalar = state.sayfalar[m.id];
  if (!sayfalar) return 1;
  const enBuyuk = Math.max(0, ...Object.keys(sayfalar).map(Number));
  return Math.min(m.toplamSayfa, Math.max(1, enBuyuk + 1));
}

function konuIlerleme(k) {
  if (!k.materyaller || !k.materyaller.length) return 0;
  let o = 0, t = 0;
  k.materyaller.forEach(m => { o += okunanSayfa(m.id); t += m.toplamSayfa || 0; });
  return t ? Math.round(o / t * 100) : 0;
}

const konuHazir = k => k.materyaller && k.materyaller.length > 0;

function dersIlerleme(d) {
  if (!d.konular || !d.konular.length) return 0;
  let p = 0, a = 0;
  d.konular.forEach(k => {
    const w = ONCELIK_AGIRLIK[k.oncelik] ?? 10;
    a += w; p += konuIlerleme(k) / 100 * w;
  });
  return a ? Math.round(p / a * 100) : 0;
}

/* Genel ilerleme — YALNIZCA seçilen dersler üzerinden */
function genelIlerleme() {
  const secili = seciliDersler();
  if (!secili.length) return 0;
  return Math.round(secili.reduce((s, d) => s + dersIlerleme(d), 0) / secili.length);
}

function seciliDersler() {
  const a = Ayar.oku();
  return DERSLER.filter(d => d.aktif !== false && a.secilenDersler.includes(d.id));
}

function toplamOkunanSayfa() {
  return Object.values(state.sayfalar).reduce((s, o) => s + Object.keys(o).length, 0);
}

function toplamSayfa() {
  let t = 0;
  seciliDersler().forEach(d => d.konular.forEach(k =>
    (k.materyaller || []).forEach(m => t += m.toplamSayfa || 0)));
  return t;
}

function kalanGun() {
  const a = Ayar.oku();
  return Math.max(0, Math.ceil((new Date(a.sinavTarihi) - new Date()) / 86400000));
}

function gunlukTempo() {
  const kalan = toplamSayfa() - toplamOkunanSayfa();
  return Math.max(0, Math.ceil(kalan / Math.max(1, kalanGun())));
}

function konuBul(dersId, konuId) {
  const d = DERSLER.find(x => x.id === dersId);
  return d ? d.konular.find(k => k.id === konuId) : null;
}

/* ---------------------------------------------------------
   4. DİNAMİK ÇALIŞMA PLANI
   Seçilen derslerin konularını kalan güne dağıtır.
   Başarısız sınav sonrası ilgili konu tekrar öne alınır.
   --------------------------------------------------------- */
function planOlustur() {
  const secili = seciliDersler();
  const bugun = new Date();
  const kalan = kalanGun();

  /* Çalışılacak konuları topla, öncelik ve tekrar durumuna göre sırala */
  let konular = [];
  secili.forEach(d => d.konular.forEach(k => {
    if (!konuHazir(k)) return;
    konular.push({
      dersId: d.id, dersAd: d.ad, dersIkon: d.ikon,
      konuId: k.id, konuAd: k.ad, oncelik: k.oncelik,
      sayfa: (k.materyaller || []).reduce((s, m) => s + (m.toplamSayfa || 0), 0),
      ilerleme: konuIlerleme(k),
      tekrar: !!state.planSifirlama[k.id],
      agirlik: ONCELIK_AGIRLIK[k.oncelik] ?? 10
    });
  }));

  /* Sıralama: önce tekrar gerekenler, sonra öncelik, sonra ilerleme az olan */
  konular.sort((a, b) => {
    if (a.tekrar !== b.tekrar) return a.tekrar ? -1 : 1;
    if (a.agirlik !== b.agirlik) return b.agirlik - a.agirlik;
    return a.ilerleme - b.ilerleme;
  });

  if (!konular.length || kalan < 1) return [];

  /* Kalan sayfaları güne dağıt */
  const toplamKalanSayfa = konular.reduce((s, k) =>
    s + Math.ceil(k.sayfa * (100 - k.ilerleme) / 100), 0);
  if (toplamKalanSayfa === 0) return [];

  /* Son %20'yi tekrar ve denemeye ayır */
  const calismaGunu = Math.max(1, Math.floor(kalan * 0.8));
  const gunlukSayfa = Math.ceil(toplamKalanSayfa / calismaGunu);

  const plan = [];
  let gun = 0, biriken = 0;
  let mevcut = { ...konular[0], bas: 0 };
  let idx = 0;
  let kalanSayfaKonu = Math.ceil(konular[0].sayfa * (100 - konular[0].ilerleme) / 100);

  while (idx < konular.length && gun < calismaGunu) {
    const tarih = new Date(bugun);
    tarih.setDate(tarih.getDate() + gun);
    const gunAdi = ["Pazar", "Pazartesi", "Salı", "Çarşamba", "Perşembe", "Cuma", "Cumartesi"][tarih.getDay()];

    /* Pazar dinlenme günü */
    if (tarih.getDay() === 0) {
      plan.push({ tarih: tarih.toISOString().slice(0, 10), gunAdi, dinlenme: true });
      gun++; continue;
    }

    const hedef = tarih.getDay() === 6 ? gunlukSayfa * 2 : gunlukSayfa;
    const gorevler = [];
    let kalanHedef = hedef;

    while (kalanHedef > 0 && idx < konular.length) {
      const al = Math.min(kalanHedef, kalanSayfaKonu);
      if (al > 0) {
        gorevler.push({
          dersId: konular[idx].dersId, dersIkon: konular[idx].dersIkon,
          konuId: konular[idx].konuId, konuAd: konular[idx].konuAd,
          oncelik: konular[idx].oncelik, sayfa: al,
          tekrar: konular[idx].tekrar
        });
        kalanHedef -= al; kalanSayfaKonu -= al;
      }
      if (kalanSayfaKonu <= 0) {
        idx++;
        if (idx < konular.length)
          kalanSayfaKonu = Math.ceil(konular[idx].sayfa * (100 - konular[idx].ilerleme) / 100);
      }
    }

    plan.push({ tarih: tarih.toISOString().slice(0, 10), gunAdi, gorevler, hedefSayfa: hedef });
    gun++;
  }

  /* Kalan günler tekrar ve deneme */
  for (let g = gun; g < kalan; g++) {
    const tarih = new Date(bugun);
    tarih.setDate(tarih.getDate() + g);
    const gunAdi = ["Pazar", "Pazartesi", "Salı", "Çarşamba", "Perşembe", "Cuma", "Cumartesi"][tarih.getDay()];
    plan.push({ tarih: tarih.toISOString().slice(0, 10), gunAdi, tekrarDonemi: true });
  }

  return plan;
}

/* ---------------------------------------------------------
   5. SINAV MOTORU
   --------------------------------------------------------- */
function sinavGecmisi(konuId) { return state.sinavlar[konuId] || []; }

function sonSinav(konuId) {
  const g = sinavGecmisi(konuId);
  return g.length ? g[g.length - 1] : null;
}

function enIyiPuan(konuId) {
  const g = sinavGecmisi(konuId);
  return g.length ? Math.max(...g.map(x => x.puan)) : null;
}

function sinavKaydet(konuId, puan, dogru, toplam) {
  if (!state.sinavlar[konuId]) state.sinavlar[konuId] = [];
  const gecti = puan >= GECME_NOTU;
  const istemciId = iyBenzersizId("sinav");
  state.sinavlar[konuId].push({
    istemciId, tarih: new Date().toISOString(), puan, dogru, toplam, gecti
  });

  if (!gecti) {
    /* Başarısız: konunun okuma ilerlemesi sıfırlanır, plan yeniden kurulur */
    const t = DERSLER.flatMap(d => d.konular).find(k => k.id === konuId);
    if (t) (t.materyaller || []).forEach(m => { delete state.sayfalar[m.id]; });
    state.planSifirlama[konuId] = new Date().toISOString();
  } else {
    delete state.planSifirlama[konuId];
  }

  DB.kaydet(state);
  kuKonuSinavSenkronla(konuId, istemciId, dogru, toplam, puan, gecti);
  return gecti;
}

/* Şıkları karıştır — doğru cevabın hep aynı harfte olmasını önler */
function siklariKaristir(soru) {
  const idx = soru.o.map((_, i) => i);
  for (let i = idx.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [idx[i], idx[j]] = [idx[j], idx[i]];
  }
  return {
    ...soru,
    o: idx.map(i => soru.o[i]),
    d: idx.indexOf(soru.d)
  };
}

function soruKaristir(liste) {
  const a = [...liste];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a.map(siklariKaristir);
}

/* ---------------------------------------------------------
   6. YARDIMCILAR
   --------------------------------------------------------- */
const $ = s => document.querySelector(s);
const esc = t => String(t ?? "").replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
const ozetDuzMetin = html => String(html ?? "").replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();

function bar(p, cls = "") {
  const k = p >= 100 ? "ok" : p >= 50 ? "" : p > 0 ? "warn" : "";
  return `<div class="bar ${k} ${cls}"><i style="width:${Math.min(100, p)}%"></i></div>`;
}
const badge = o => o ? `<span class="badge badge-${o}">${o}</span>` : "";

function tarihTR(iso) {
  if (!iso) return "";
  const [y, m, d] = iso.slice(0, 10).split("-");
  const A = ["Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran", "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık"];
  return `${+d} ${A[+m - 1]} ${y}`;
}

/* ---------------------------------------------------------
   7. YÖNLENDİRME
   --------------------------------------------------------- */
/* location.hash'i aynı değere yeniden atamak hashchange tetiklemez
   (ör. zaten Finansallar'dayken Dock'tan tekrar Finansallar'a
   tıklamak). Bu durumda menuCiz()'in rota-değişti kontrolü de devreye
   girmez (rota zaten aynı), yani masaüstü modunda üzerine yığılmış
   pencereler hiç küçülme fırsatı bulamaz — kullanıcı Dock'tan aynı
   sayfaya tekrar tıklayınca "sayfa görünmüyor" izlenimi verir. Burada
   hem elle küçültüp hem yonlendir()'i çağırarak o sayfayı öne çıkarıyoruz. */
function git(h) {
  if (location.hash.replace("#", "") === h) {
    if (h.split("/")[0] !== "panel" && typeof macTumPencereleriKucult === "function") macTumPencereleriKucult();
    yonlendir();
  } else location.hash = h;
}

function yonlendir() {
  const u = Auth.aktif();
  if (!u) { girisEkraniGoster(); return; }

  $("#girisEkrani").style.display = "none";
  $("#uygulama").style.display = "flex";

  state = DB.yukle();
  temaUygula();
  kullaniciBilgisiYaz();
  kullaniciSenkronBaslat();

  const h = location.hash.replace("#", "") || "panel";
  const p = h.split("/");
  const el = $("#content");

  $(".sidebar").classList.remove("acik");
  window.scrollTo(0, 0);

  if (!ICERIK_HAZIR) { el.innerHTML = `<div class="empty"><div class="icon">⏳</div>Yükleniyor…</div>`; return; }

  /* Menü izinlerine göre ROTA koruması — yalnızca sidebar linkini gizlemek
     yetmez: bir üye kısıtlanmışsa (menuIzin bir dizi) ama Ana Sayfa gibi
     kısıtlanmamış-varsayılan rotalar linki görünmese de doğrudan hash ile
     (ya da varsayılan iniş sayfası olarak) erişilebilir kalıyordu. Burada
     her rota, izinli değilse üyenin sahip olduğu ilk menüye yönlendirilir;
     hiçbir izni yoksa nötr bir "erişim yok" ekranı gösterilir. */
  const yoneticiMi = Auth.yonetici();
  const uyeIzinDizisi = (!yoneticiMi && Array.isArray(u.menuIzin)) ? u.menuIzin : null;
  if (uyeIzinDizisi && !menuIzinRotaIzinliMi(p, uyeIzinDizisi)) {
    const ilkIzinliOge = MENU_OGELER.find(o => o.route && uyeIzinDizisi.includes(o.id));
    if (ilkIzinliOge && h !== ilkIzinliOge.route) { location.hash = ilkIzinliOge.route; return; }
    if (!ilkIzinliOge) {
      menuCiz(p);
      el.innerHTML = `<div class="empty"><div class="icon">🔒</div><h3>Erişim izniniz yok</h3>
        <p class="muted mt">Hiçbir menüye erişim izniniz bulunmuyor. Yöneticinizle iletişime geçin.</p></div>`;
      return;
    }
  }

  menuCiz(p);

  try {
    if (p[0] === "panel") el.innerHTML = sayfaPanel();
    else if (p[0] === "dersler") {
      if (p[1] && p[2]) el.innerHTML = sayfaKonu(p[1], p[2]);
      else if (p[1]) el.innerHTML = sayfaDers(p[1]);
      else el.innerHTML = sayfaDersler();
    }
    else if (p[0] === "oku") { el.innerHTML = sayfaOku(p[1], p[2], p[3], p[4]); okuyucuBaslat(); }
    else if (p[0] === "ozetoku") { el.innerHTML = sayfaOzetOku(p[1], p[2], p[3]); okuyucuBaslat(); }
    else if (p[0] === "sinav") { sinavBaslat(p[1], p[2]); }
    else if (p[0] === "test-et") {
      if (p[1] && p[2]) el.innerHTML = testEtKonu(p[1], p[2]);
      else if (p[1]) el.innerHTML = testEtKonular(p[1]);
      else el.innerHTML = testEtDersler();
    }
    else if (p[0] === "forum") {
      if (p[1] && p[2]) el.innerHTML = sayfaForumKonu(p[1], p[2]);
      else if (p[1]) el.innerHTML = sayfaForumKategori(p[1]);
      else el.innerHTML = sayfaForum();
    }
    else if (p[0] === "plan") el.innerHTML = sayfaPlan();
    else if (p[0] === "takvim") el.innerHTML = sayfaTakvim();
    else if (p[0] === "rapor") el.innerHTML = sayfaRapor();
    else if (p[0] === "strateji") el.innerHTML = sayfaStrateji();
    else if (p[0] === "uyelik") el.innerHTML = sayfaUyelik();
    else if (p[0] === "yonetim") el.innerHTML = sayfaYonetim(p[1], p[2], p[3]);
    else if (p[0] === "ozel") el.innerHTML = sayfaOzel(p[1]);
    else if (p[0] === "ayarlar") { git("uyelik"); return; }
    else if (p[0] === "sorular") { git("dersler"); return; }
    else el.innerHTML = `<div class="empty"><div class="icon">🔍</div>Sayfa bulunamadı.</div>`;

    /* Masaüstü modunda, Ana Sayfa dışındaki HER tam sayfa görünümün
       üstüne trafik ışıklı bir pencere başlık çubuğu ekler (bkz.
       macRotaBaslikBilgisi) — Finansallar hariç, o kendi başlık
       çubuğunu finance-native.js içinde zaten ekliyor. */
    if (p[0] !== "panel" && p[0] !== "finansallar" && macTamSayfaBaslikCubuguGerekli()) {
      const bilgi = macRotaBaslikBilgisi(p);
      if (bilgi) el.insertAdjacentHTML("afterbegin", macTamSayfaBaslikCubugu(bilgi.baslik, bilgi.ikon, bilgi.geri));
    }
  } catch (e) {
    el.innerHTML = `<div class="empty"><div class="icon">⚠️</div>
      <h3>Sayfa yüklenirken hata oluştu</h3>
      <p class="muted mt">${esc(e.message)}</p></div>`;
    console.error(e);
  }
}

function kullaniciBilgisiYaz() {
  const u = Auth.aktif();
  if (!u) return;
  $("#kullaniciAd").textContent = u.ad;
  $("#kullaniciRol").textContent = u.rol === "yonetici" ? "Yönetici" : "Üye";
}

/* p[0] rotasının, üyenin menuIzin listesindeki bir menü öğesine ait olup
   olmadığını kontrol eder (bkz. yonlendir()). "oku"/"ozetoku"/"sinav" gibi
   alt rotalar kendi menü öğeleri yoktur — ait oldukları ana öğeyle
   (Dersler/Beni Test Et) aynı izne tabidir. "ozel" sayfalar slug'a göre
   kendi menü öğesiyle eşleştirilir. */
const MENU_ROTA_AILESI = { oku: "dersler", ozetoku: "dersler", sinav: "test-et" };
function menuIzinRotaIzinliMi(p, uyeIzinDizisi) {
  if (p[0] === "ozel") {
    const oge = MENU_OGELER.find(o => o.tur === "ozel-sayfa" && o.slug === p[1]);
    return !!(oge && uyeIzinDizisi.includes(oge.id));
  }
  const rota = MENU_ROTA_AILESI[p[0]] || p[0];
  const oge = MENU_OGELER.find(o => o.route === rota);
  return !!(oge && uyeIzinDizisi.includes(oge.id));
}

/* Kenar çubuğu marka başlığını çizer — normalde "YMM Hazırlık Platformu";
   yalnızca Finansallar'a erişimi olan (başka hiçbir menüye izni olmayan)
   bir üye için "Ersem · Faaliyet Raporu" olarak yeniden markalanır (bkz.
   menuCiz() içindeki sadeceFinansallar tespiti). */
function markaCiz(sadeceFinansallar) {
  const el = document.querySelector(".sidebar-brand");
  if (!el) return;
  el.innerHTML = sadeceFinansallar
    ? `<div class="brand-lockup"><img src="assets/img/ersem-logo.svg" alt="Ersem" class="brand-ikon brand-logo-img"><h1>Ersem<br>Faaliyet Raporu</h1></div><span>Finansal Raporlama Portalı</span>`
    : `<div class="brand-lockup"><span class="brand-ikon">📘</span><h1>YMM Hazırlık<br>Platformu</h1></div><span>Kişisel Çalışma Platformu</span>`;
}

/* "Yönetim" menüsü, alt sekmelerini kenar çubuğunda üzerine gelince
   (hover) açılan bir pencere (flyout) olarak gösterir — sayfaYonetim()'deki
   sekmeler ile aynıdır. Tema bağımsızdır; admin, başka menü öğelerine de
   "Menü Yönetimi → Alt Menüler" sekmesinden aynı türde alt liste
   ekleyebilir (bkz. o.altMenu, icerik-yukle.js: iyAltMenu*).

   Panel, kenar çubuğunun kendi kaydırma alanının (.sidebar-nav) DIŞINDA,
   <body>'ye eklenmiş TEK bir paylaşılan pencerede (#navFlyoutHost) çizilir
   ve konumu JS ile hesaplanır — çünkü .sidebar-nav'ın overflow-y:auto'su,
   tarayıcı tarafından overflow-x'i de "auto" saymaya zorlar (CSS'in bilinen
   davranışı) ve saf CSS ile mutlak konumlandırılan bir panel bu yüzden
   kırpılırdı. */
const YONETIM_ACILIR_SEKMELER = [
  ["dashboard", "Dashboard"], ["dersler", "Dersler"], ["konular", "Konular"],
  ["icerik", "İçerik Yönetimi"], ["sayfaekle", "Özet Sayfaları"], ["sorular", "Soru Bankası"],
  ["materyaller", "PDF / Materyaller"], ["medya", "Medya Kütüphanesi"], ["uyeler", "Üyeler"],
  ["forum", "Forum"], ["menu", "Menü Yönetimi"], ["sistem", "Yayınlama / Sistem"]
];
let NAV_FLYOUT_ICERIK = {};
let NAV_FLYOUT_KAPAT_ZAMANLAYICI = null;

function navFlyoutHostEl() {
  let host = document.getElementById("navFlyoutHost");
  if (!host) {
    host = document.createElement("div");
    host.id = "navFlyoutHost";
    host.className = "nav-flyout-host";
    host.addEventListener("mouseenter", () => clearTimeout(NAV_FLYOUT_KAPAT_ZAMANLAYICI));
    host.addEventListener("mouseleave", navFlyoutKapatGecikmeli);
    document.body.appendChild(host);
  }
  return host;
}
function navFlyoutAc(triggerEl, ogeId) {
  clearTimeout(NAV_FLYOUT_KAPAT_ZAMANLAYICI);
  const host = navFlyoutHostEl();
  host.innerHTML = NAV_FLYOUT_ICERIK[ogeId] || "";
  host.style.left = "-9999px";
  host.classList.add("acik");
  const rect = triggerEl.getBoundingClientRect();
  const yukseklik = host.offsetHeight;
  const top = Math.min(rect.top, Math.max(8, window.innerHeight - yukseklik - 8));
  host.style.top = top + "px";
  host.style.left = (rect.right + 6) + "px";
}
function navFlyoutKapatGecikmeli() {
  clearTimeout(NAV_FLYOUT_KAPAT_ZAMANLAYICI);
  NAV_FLYOUT_KAPAT_ZAMANLAYICI = setTimeout(navFlyoutKapat, 180);
}
function navFlyoutKapat() {
  const host = document.getElementById("navFlyoutHost");
  if (host) host.classList.remove("acik");
}
function navFlyoutHtml(o, aktifAna, altlarHtml) {
  NAV_FLYOUT_ICERIK[o.id] = altlarHtml;
  return `<div class="nav-item nav-flyout-baslik${aktifAna ? " active" : ""}"
    onmouseenter="navFlyoutAc(this,'${o.id}')" onmouseleave="navFlyoutKapatGecikmeli()"
    onclick="git('${o.route}')">
    ${o.ikon || ""} <span>${esc(o.etiket)}</span>
    <span class="nav-flyout-ok">▸</span>
  </div>`;
}
function yonetimFlyoutHtml(o, p) {
  const aktifAna = p[0] === "yonetim";
  const altlar = YONETIM_ACILIR_SEKMELER.map(([sekme, etiket]) => {
    const aktif = aktifAna && (p[1] || "dashboard") === sekme;
    return `<div class="nav-flyout-item${aktif ? " active" : ""}" onclick="git('yonetim/${sekme}')">${esc(etiket)}</div>`;
  }).join("");
  return navFlyoutHtml(o, aktifAna, altlar);
}
function altMenuFlyoutHtml(o, p) {
  const aktifAna = p[0] === o.route;
  const altlar = o.altMenu.map(a => {
    const aktif = p[0] === a.route;
    return `<div class="nav-flyout-item${aktif ? " active" : ""}" onclick="git('${a.route}')">${esc(a.etiket)}</div>`;
  }).join("");
  return navFlyoutHtml(o, aktifAna, altlar);
}

/* Sol menüyü MENU_OGELER'den çizer — yönetimde düzenlenebilir hâle
   gelmeden önce index.html'de sabit kodluydu (bkz. git geçmişi). */
let MAC_SON_ROTA = null;
function menuCiz(p) {
  const nav = $("#navAlan");
  if (!nav) return;
  navFlyoutKapat();
  /* Masaüstü modunda Ana Sayfa (panel) rotası "masaüstü" gibi davranır —
     kontrol paneli yerine sade bir duvar kağıdı gösterilir (bkz.
     macos-theme.css: .mac-masaustu-anasayfa). Diğer rotalar (henüz
     pencereye taşınmadıkları için) normal tam sayfa olarak kalır. */
  document.body.classList.toggle("mac-masaustu-anasayfa", p[0] === "panel");
  /* Bir tam sayfaya YENİ geçildiğinde (rota değiştiğinde) açık popup
     pencereler onu görünmez şekilde kaplayabilir — "Finansallar'a
     tıkladım ama ekran görünmüyor" tam olarak buydu. Pencereler
     kapatılmıyor (kullanıcı bunların kalmasını istedi) — yalnızca
     küçültülüp sekme çubuğuna alınıyor, bir tıkla geri getirilebilir.
     Ana Sayfa'ya dönmek istisna: orası zaten "masaüstü", pencerelerin
     üzerinde durması beklenen bir görünüm. Aynı rotada kalan (route
     değişmeyen) yeniden çizimlerde pencereler kıpırdamaz. */
  const rotaAnahtari = p.join("/");
  if (macTamSayfaBaslikCubuguGerekli() && p[0] !== "panel" && rotaAnahtari !== MAC_SON_ROTA) macTumPencereleriKucult();
  MAC_SON_ROTA = rotaAnahtari;
  const yonetici = Auth.yonetici();
  const aktifUye = Auth.aktif();
  const uyeIzinDizisi = (!yonetici && aktifUye && Array.isArray(aktifUye.menuIzin)) ? aktifUye.menuIzin : null;

  /* Yalnızca Finansallar'a izinli üye için tüm menü kaldırılır ve kenar
     çubuğu Ersem markasıyla değiştirilir — bkz. yonlendir()'deki rota
     koruması, bu üyeleri zaten Finansallar dışına çıkarmaz. */
  const finansallarOge = MENU_OGELER.find(o => o.route === "finansallar");
  const sadeceFinansallar = !!(uyeIzinDizisi && finansallarOge && uyeIzinDizisi.length === 1 && uyeIzinDizisi[0] === finansallarOge.id);
  markaCiz(sadeceFinansallar);
  if (sadeceFinansallar) {
    /* Menü tamamen kaldırılır, yalnızca çıkış eylemi kalır — üyenin oturumu
       kapatabilmesi için. */
    const cikisOge = MENU_OGELER.find(o => o.tur === "cikis");
    nav.innerHTML = cikisOge ? `<div class="nav-item" onclick="cikisYap()">${cikisOge.ikon || ""} <span>${esc(cikisOge.etiket)}</span></div>` : "";
    macDockCiz(cikisOge ? [cikisOge] : [], p);
    return;
  }

  /* Sıradan üyenin menu_izin'i açıkça bir diziyse (yönetici tarafından
     kısıtlanmışsa) yalnızca o dizideki menüler görünür — "sadeceYonetici"
     olsa bile (yönetici bunu bilinçli olarak açmış demektir). Dizi yoksa
     (kısıtlanmamış eski davranış) önceki mantık aynen uygulanır: yalnızca
     sadeceYonetici OLMAYAN menüler görünür. */
  const gorunurOgeler = MENU_OGELER.filter(o => o.gorunur !== false && (uyeIzinDizisi
    ? (o.tur === "baslik" || o.tur === "cikis" || uyeIzinDizisi.includes(o.id))
    : (!o.sadeceYonetici || yonetici)));

  nav.innerHTML = gorunurOgeler.map(o => {
    if (o.tur === "baslik") return `<div class="nav-bolum-baslik">${esc(o.etiket)}</div>`;
    if (o.tur === "cikis") return `<div class="nav-item" onclick="cikisYap()">${o.ikon || ""} <span>${esc(o.etiket)}</span></div>`;
    if (o.tur === "ozel-sayfa") {
      const aktif = p[0] === "ozel" && p[1] === o.slug;
      return `<div class="nav-item${aktif ? " active" : ""}" onclick="git('ozel/${o.slug}')">${o.ikon || ""} <span>${esc(o.etiket)}</span></div>`;
    }
    if (o.route === "yonetim" && yonetici) return yonetimFlyoutHtml(o, p);
    if (Array.isArray(o.altMenu) && o.altMenu.length) return altMenuFlyoutHtml(o, p);
    const aktif = p[0] === o.route;
    return `<div class="nav-item${aktif ? " active" : ""}" data-route="${o.route}" onclick="git('${o.route}')">${o.ikon || ""} <span>${esc(o.etiket)}</span></div>`;
  }).join("");

  macDockCiz(gorunurOgeler, p, yonetici);
}

/* ---------------------------------------------------------
   MASAÜSTÜ MODU — ALT DOCK + AÇILIR MENÜLER + POPUP PENCERELER
   ---------------------------------------------------------
   Yalnızca "macOS masaüstü modu" (Ayar.macMasaustu) açıkken devreye
   girer (bkz. temaUygula() — html[data-mac-masaustu] özniteliği, ve
   macos-theme.css). Kenar çubuğu CSS ile gizlenir, yerine ekranın
   altında bir Dock çizilir. Dock'ta "Dersler"/"Yönetim"/altMenu'lü
   öğeler tıklanınca (nav-flyout-host'u yeniden kullanan) yukarı açılan
   bir açılır menü gösterir; bir ders seçmek gerçek, sürüklenebilir,
   küçültülebilir/büyütülebilir bir popup pencere açar (macPencereAc).
   Pencere içinde bir konuya tıklamak AYNI pencerenin içeriğini o
   konunun mevcut çalışma ekranına (sayfaKonu()) çevirir — "geri" ile
   konu listesine dönülür. Konu içindeki "PDF oku"/"Beni Sına" gibi
   tam sayfa bağlantılar tıklanırsa (git() üzerinden) normal şekilde
   tüm uygulamayı o sayfaya götürür — açık pencereler KAPANMAZ, o tam
   sayfanın üzerinde açık kalmaya devam eder (bkz. sekme çubuğu,
   macSekmeCubuguCiz). Materyal okuma ekranı da kendi trafik ışıklı
   başlık çubuğunu gösterir (bkz. macOkuBaslikCubugu). */

/* Dock otomatik gizlenir — ekranın en altında ince, görünmez bir
   "algılayıcı" şerit (macDockAlgilayici) fareyi bekler; üzerine (ya da
   doğrudan Dock'un kendisine) gelince Dock yukarı kayar, ikisinden de
   tamamen ayrılınca gizlenir (macOS'un otomatik gizlenen Dock'u gibi).
   İkisi de gerçek pointer-events'e sahip normal kardeş elemanlar
   olmalı — bir sarmalayıcıya pointer-events:none vermek CSS :hover'ın
   hiç eşleşmemesine yol açar, bu yüzden sarmalayıcı YERİNE CSS'te genel
   kardeş seçici (~) kullanılıyor (bkz. macos-theme.css). Açık bir Dock
   açılır menüsü varken de (.acik-tut) görünür tutulur. */
function macDockHostEl() {
  let host = document.getElementById("macDockAlani");
  if (!host) {
    let algilayici = document.getElementById("macDockAlgilayici");
    if (!algilayici) {
      algilayici = document.createElement("div");
      algilayici.id = "macDockAlgilayici";
      algilayici.className = "mac-dock-algilayici";
      document.body.appendChild(algilayici);
    }
    host = document.createElement("div");
    host.id = "macDockAlani";
    host.className = "mac-dock";
    document.body.appendChild(host);
    macDockBuyutmeBagla(host);
  }
  return host;
}
/* macOS Dock "magnification" efekti — fare dock üzerindeyken, imlece
   yakın ikonlar uzaktakilerden daha büyük görünür (komşu ikonlar da
   kademeli olarak büyür). Saf CSS ile yapılamaz (komşu elemanın
   büyüklüğü imlecin mesafesine bağlı) — bu yüzden tek bir mousemove
   dinleyicisi Dock'a (host'a) bir kez bağlanır, her hareket her ikonun
   transform'unu günceller. host yeniden çizildiğinde (innerHTML
   değişse de) element referansı aynı kaldığı için dinleyici bir kez
   bağlanması yeterlidir. */
function macDockBuyutmeBagla(host) {
  host.addEventListener("mousemove", (e) => {
    host.querySelectorAll(".mac-dock-oge").forEach(el => {
      const r = el.getBoundingClientRect();
      const merkezX = r.left + r.width / 2;
      const uzaklik = Math.abs(e.clientX - merkezX);
      const yaricap = 110;
      const olcek = uzaklik < yaricap ? 1 + 0.55 * (1 - uzaklik / yaricap) : 1;
      el.style.transform = olcek > 1.01 ? `scale(${olcek.toFixed(3)}) translateY(${(-(olcek - 1) * 22).toFixed(1)}px)` : "";
    });
  });
  host.addEventListener("mouseleave", () => {
    host.querySelectorAll(".mac-dock-oge").forEach(el => { el.style.transform = ""; });
  });
}
function macDockCiz(ogeler, p) {
  const masaustu = document.documentElement.getAttribute("data-tema-stil") === "macos" && document.documentElement.hasAttribute("data-mac-masaustu");
  const host = macDockHostEl();
  if (!masaustu) { host.classList.remove("gorunur"); host.innerHTML = ""; macDockDropdownKapat(); macSekmeCubuguCiz(); return; }
  host.classList.add("gorunur");
  host.innerHTML = ogeler.filter(o => o.tur !== "baslik").map(o => {
    if (o.tur === "cikis") return `<div class="mac-dock-oge" title="${esc(o.etiket)}" onclick="cikisYap()">${o.ikon || "🚪"}</div>`;
    if (o.tur === "ozel-sayfa") {
      const aktif = p[0] === "ozel" && p[1] === o.slug;
      return `<div class="mac-dock-oge${aktif ? " aktif" : ""}" title="${esc(o.etiket)}" onclick="git('ozel/${o.slug}')">${o.ikon || "📄"}</div>`;
    }
    if (o.route === "dersler") {
      return `<div class="mac-dock-oge" title="${esc(o.etiket)}" onclick="macDockDropdownAc(this,'dersler',null)">${o.ikon || "📚"}</div>`;
    }
    if (o.route === "yonetim") {
      return `<div class="mac-dock-oge${p[0] === "yonetim" ? " aktif" : ""}" title="${esc(o.etiket)}" onclick="macDockDropdownAc(this,'yonetim',null)">${o.ikon || "🛡️"}</div>`;
    }
    if (Array.isArray(o.altMenu) && o.altMenu.length) {
      return `<div class="mac-dock-oge${p[0] === o.route ? " aktif" : ""}" title="${esc(o.etiket)}" onclick="macDockDropdownAc(this,'altmenu','${o.id}')">${o.ikon || ""}</div>`;
    }
    if (MAC_DOCK_PENCERE_ROTALARI.includes(o.route)) {
      return `<div class="mac-dock-oge" title="${esc(o.etiket)}" onclick="macPencereSayfaAc('${o.route}')">${o.ikon || ""}</div>`;
    }
    const aktif = p[0] === o.route;
    return `<div class="mac-dock-oge${aktif ? " aktif" : ""}" title="${esc(o.etiket)}" onclick="git('${o.route}')">${o.ikon || ""}</div>`;
  }).join("");
  macSekmeCubuguCiz();
}
/* Bu rotalar Dock'ta tıklanınca tam sayfa gezinme yerine bir popup
   pencere açar (bkz. macPencereSayfaAc). "Dersler" ve "Yönetim" ayrı
   ele alınır (kendi açılır menüleri var). */
const MAC_DOCK_PENCERE_ROTALARI = ["test-et", "takvim", "plan", "uyelik", "strateji"];

/* ---------- DOCK'TAN AÇILAN AÇILIR MENÜLER (dropdown) ----------
   nav-flyout-host'u (bkz. yukarıdaki navFlyoutAc) yeniden kullanır —
   tek fark, Dock ekranın altında olduğu için panel tetikleyicinin
   ÜZERİNDE açılır. */
function macDockDropdownHostEl() {
  let host = document.getElementById("macDockDropdownHost");
  if (!host) {
    host = document.createElement("div");
    host.id = "macDockDropdownHost";
    host.className = "nav-flyout-host";
    document.body.appendChild(host);
  }
  return host;
}
function macDockDropdownAc(triggerEl, tur, ogeId) {
  const host = macDockDropdownHostEl();
  const anahtar = tur + ":" + (ogeId || "");
  if (host.classList.contains("acik") && host.dataset.anahtar === anahtar) { macDockDropdownKapat(); return; }

  let icHtml = "";
  if (tur === "dersler") {
    icHtml = DERSLER.filter(d => d.aktif !== false).map(d =>
      `<div class="nav-flyout-item" onclick="macDockDropdownKapat();macPencereAc('${d.id}')">${d.ikon || "📘"} ${esc(d.ad)}</div>`
    ).join("");
  } else if (tur === "yonetim") {
    icHtml = YONETIM_ACILIR_SEKMELER.map(([sekme, etiket]) =>
      `<div class="nav-flyout-item" onclick="macDockDropdownKapat();macPencereSayfaAc('yonetim','${sekme}')">${esc(etiket)}</div>`
    ).join("");
  } else if (tur === "altmenu") {
    const o = MENU_OGELER.find(x => x.id === ogeId);
    icHtml = (o && o.altMenu ? o.altMenu : []).map(a =>
      `<div class="nav-flyout-item" onclick="macDockDropdownKapat();git('${a.route}')">${esc(a.etiket)}</div>`
    ).join("");
  }
  if (!icHtml) return;

  host.dataset.anahtar = anahtar;
  host.innerHTML = icHtml;
  host.style.left = "-9999px";
  host.classList.add("acik");
  const rect = triggerEl.getBoundingClientRect();
  const genislik = host.offsetWidth, yukseklik = host.offsetHeight;
  const sol = Math.max(8, Math.min(window.innerWidth - genislik - 8, rect.left + rect.width / 2 - genislik / 2));
  host.style.left = sol + "px";
  host.style.top = Math.max(8, rect.top - yukseklik - 10) + "px";
  const dockEl = document.getElementById("macDockAlani");
  if (dockEl) dockEl.classList.add("acik-tut");
  setTimeout(() => document.addEventListener("click", macDockDropdownDisTikla), 0);
}
function macDockDropdownDisTikla(e) {
  const host = document.getElementById("macDockDropdownHost");
  const dock = document.getElementById("macDockAlani");
  if (host && !host.contains(e.target) && !(dock && dock.contains(e.target))) macDockDropdownKapat();
}
function macDockDropdownKapat() {
  const host = document.getElementById("macDockDropdownHost");
  if (host) host.classList.remove("acik");
  const dockEl = document.getElementById("macDockAlani");
  if (dockEl) dockEl.classList.remove("acik-tut");
}

/* ---------- POPUP PENCERELER (Dersler → konu listesi → çalışma) ---------- */
let MAC_PENCERELER = []; // { id, dersId, konuId, baslik, ikon, sol, ust, genislik, yukseklik, z, simge, buyutulmus }
let MAC_Z_SAYAC = 400;

function macPencereBul(id) { return MAC_PENCERELER.find(x => x.id === id); }

function macPencereAc(dersId) {
  const var_ = MAC_PENCERELER.find(x => x.tur === "ders" && x.dersId === dersId);
  if (var_) { var_.simge = false; macPencereOnePlanaAl(var_.id); return; }
  const d = DERSLER.find(x => x.id === dersId);
  if (!d) return;
  const kademe = MAC_PENCERELER.length;
  MAC_PENCERELER.push({
    id: "mp" + (++MAC_Z_SAYAC), tur: "ders", dersId, konuId: null, baslik: d.ad, ikon: d.ikon || "📘",
    sol: 130 + (kademe % 6) * 32, ust: 60 + (kademe % 6) * 28, genislik: 440, yukseklik: 520,
    z: ++MAC_Z_SAYAC, simge: false, buyutulmus: false
  });
  macPencerelerCiz();
}

/* "Ders" dışındaki (Beni Test Et, Takvim, Çalışma Planı, Yönetim, Üyelik
   vb.) tek pencerelik bölümler için genel amaçlı pencere — içerik,
   mevcut tam sayfa render fonksiyonları doğrudan çağrılarak üretilir
   (bkz. macPencereSayfaIcerik). Bu sayfaların KENDİ içindeki
   bağlantılar (onclick="git(...)") hâlâ tüm uygulamayı o rotaya
   götürür — yalnızca Dersler'in konu listesi gibi zaten pencere-farkında
   yapılan akışlar pencere içinde kalır. */
function macPencereSayfaAc(route, altRota) {
  const anahtar = "sayfa:" + route + ":" + (altRota || "");
  const var_ = MAC_PENCERELER.find(x => x.anahtar === anahtar);
  if (var_) { var_.simge = false; macPencereOnePlanaAl(var_.id); return; }
  const oge = MENU_OGELER.find(x => x.route === route);
  const kademe = MAC_PENCERELER.length;
  MAC_PENCERELER.push({
    id: "mp" + (++MAC_Z_SAYAC), tur: "sayfa", anahtar, route, altRota: altRota || null,
    baslik: oge ? oge.etiket : route, ikon: oge ? oge.ikon : "",
    sol: 110 + (kademe % 6) * 32, ust: 50 + (kademe % 6) * 28, genislik: 640, yukseklik: 560,
    z: ++MAC_Z_SAYAC, simge: false, buyutulmus: false
  });
  macPencerelerCiz();
}
function macPencereKapat(id) {
  const el = document.getElementById(id);
  if (el && document.fullscreenElement === el) document.exitFullscreen().catch(() => {});
  MAC_PENCERELER = MAC_PENCERELER.filter(x => x.id !== id);
  macPencerelerCiz();
}
function macPencereKuculttur(id) { const p = macPencereBul(id); if (p) p.simge = true; macPencerelerCiz(); }
/* Yeni bir tam sayfaya geçilirken açık (küçültülmemiş) tüm pencereleri
   küçültür — kapatmaz. bkz. menuCiz(). */
function macTumPencereleriKucult() {
  let degisti = false;
  MAC_PENCERELER.forEach(p => { if (!p.simge) { p.simge = true; degisti = true; } });
  if (degisti) macPencerelerCiz();
}
function macPencereBuyult(id) { const p = macPencereBul(id); if (p) p.buyutulmus = !p.buyutulmus; macPencerelerCiz(); }
/* Mavi nokta — gerçek tarayıcı Tam Ekran API'si (yeşilin "sayfayı
   kaplayan" büyütmesinden farklı olarak tarayıcı arayüzünü de gizler). */
function macPencereTamEkran(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (document.fullscreenElement === el) document.exitFullscreen().catch(() => {});
  else if (el.requestFullscreen) el.requestFullscreen().catch(() => {});
}

/* ---------- TAM SAYFA GÖRÜNÜMLER İÇİN DEKORATİF PENCERE BAŞLIĞI ----------
   Popup pencere OLMAYAN, ama masaüstü modunda "pencere gibi" görünmesi
   istenen tam sayfa ekranlar için (materyal okuma — sayfaOku/yonlendir,
   Finansallar — finance-native.js). Gerçek bir pencere DEĞİLDİR
   (sürüklenemez/taşınamaz); yalnızca görsel tutarlılık için trafik
   ışıkları + mavi tam ekran düğmesi gösterir. */
function macTamSayfaBaslikCubuguGerekli() {
  return document.documentElement.getAttribute("data-tema-stil") === "macos" && document.documentElement.hasAttribute("data-mac-masaustu");
}
function macTamSayfaBaslikCubugu(baslik, ikon, geriRota) {
  return `<div class="mac-tam-baslik" id="macTamBaslik">
    <span class="mac-pencere-trafik">
      <span class="mac-nokta kirmizi" onclick="git('${geriRota}')" title="Geri"></span>
      <span class="mac-nokta sari" onclick="macTamBaslikKucult()" title="Küçült"></span>
      <span class="mac-nokta yesil" onclick="macTamGenisModAcKapa()" title="Geniş Mod"></span>
      <span class="mac-nokta mavi" onclick="macTamEkranAcKapa()" title="Tam Ekran"></span>
    </span>
    <span class="mac-pencere-etiket">${ikon || ""} ${esc(baslik)}</span>
  </div>`;
}
function macTamBaslikKucult() {
  const el = document.getElementById("macTamBaslik");
  if (el) el.classList.toggle("kucuk");
}
function macTamGenisModAcKapa() { document.body.classList.toggle("mac-genis-mod"); }
function macTamEkranAcKapa() {
  const el = document.querySelector(".main") || document.documentElement;
  if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
  else if (el.requestFullscreen) el.requestFullscreen().catch(() => {});
}
/* p rotasına göre başlık/ikon/"geri" hedefi üretir — yonlendir()'in
   sonunda, masaüstü modunda Ana Sayfa dışındaki HER tam sayfaya
   tutarlı bir pencere başlık çubuğu eklemek için kullanılır. Bulamazsa
   (bozuk/bulunamayan içerik) null döner, o zaman çubuk eklenmez. */
function macRotaBaslikBilgisi(p) {
  if (p[0] === "dersler") {
    if (p[1] && p[2]) { const k = konuBul(p[1], p[2]); return k ? { baslik: k.ad, ikon: "📘", geri: `dersler/${p[1]}` } : null; }
    if (p[1]) { const d = DERSLER.find(x => x.id === p[1]); return d ? { baslik: d.ad, ikon: d.ikon || "📚", geri: "dersler" } : null; }
    return { baslik: "Dersler", ikon: "📚", geri: "panel" };
  }
  if (p[0] === "oku") {
    const k = konuBul(p[1], p[2]);
    const m = k && (k.materyaller || []).find(x => x.id === p[3]);
    return { baslik: m ? m.ad : (k ? k.ad : "Materyal"), ikon: "📘", geri: `dersler/${p[1]}/${p[2]}` };
  }
  if (p[0] === "ozetoku") {
    const k = konuBul(p[1], p[2]);
    return { baslik: (k ? k.ad + " — " : "") + "Özet", ikon: "📄", geri: `dersler/${p[1]}/${p[2]}` };
  }
  if (p[0] === "sinav") {
    const k = konuBul(p[1], p[2]);
    return { baslik: (k ? k.ad + " — " : "") + "Sınav", ikon: "🧠", geri: `dersler/${p[1]}/${p[2]}` };
  }
  if (p[0] === "test-et") {
    if (p[1] && p[2]) { const k = konuBul(p[1], p[2]); return k ? { baslik: k.ad, ikon: "🧠", geri: `test-et/${p[1]}` } : null; }
    if (p[1]) { const d = DERSLER.find(x => x.id === p[1]); return d ? { baslik: d.ad, ikon: "🧠", geri: "test-et" } : null; }
    return { baslik: "Beni Test Et", ikon: "🧠", geri: "panel" };
  }
  if (p[0] === "forum") {
    if (p[1] && p[2]) return { baslik: "Forum", ikon: "💬", geri: `forum/${p[1]}` };
    if (p[1]) return { baslik: "Forum", ikon: "💬", geri: "forum" };
    return { baslik: "Forum", ikon: "💬", geri: "panel" };
  }
  if (p[0] === "yonetim") {
    const sekme = YONETIM_ACILIR_SEKMELER.find(s => s[0] === (p[1] || "dashboard"));
    return { baslik: "Yönetim" + (sekme ? " — " + sekme[1] : ""), ikon: "🛡️", geri: "panel" };
  }
  if (p[0] === "ozel") {
    const s = MENU_OZEL_SAYFALAR.find(x => x.slug === p[1]);
    return { baslik: s ? s.baslik : "Sayfa", ikon: "📄", geri: "panel" };
  }
  const oge = MENU_OGELER.find(o => o.route === p[0]);
  if (oge) return { baslik: oge.etiket, ikon: oge.ikon || "", geri: "panel" };
  return { baslik: "Sayfa", ikon: "", geri: "panel" };
}
function macPencereOnePlanaAl(id) {
  const p = macPencereBul(id);
  if (!p) return;
  p.z = ++MAC_Z_SAYAC;
  if (p.simge) p.simge = false;
  macPencerelerCiz();
}
/* Pencere gövdesindeki bir öğeye tıklarken (mousedown, click'ten önce
   dışa doğru köpürür) tam macPencerelerCiz() çağırmak, tıklanan öğeyi
   DOM'dan silip yeniden oluşturur — bu da asıl click olayının hiç
   ateşlenmemesine yol açar (bkz. mac-pencere'nin onmousedown'ı). Bu
   yüzden öne alma yalnızca z-index'i günceller, tam yeniden çizim
   yapmaz. */
function macPencereOnePlanaAlHafif(id) {
  const p = macPencereBul(id);
  if (!p) return;
  p.z = ++MAC_Z_SAYAC;
  const el = document.getElementById(id);
  if (el) el.style.zIndex = p.z;
}
function macPencereGeriGit(id) { const p = macPencereBul(id); if (p) { p.konuId = null; macPencerelerCiz(); } }
function macKonuAc(id, konuId) { const p = macPencereBul(id); if (p) { p.konuId = konuId; macPencerelerCiz(); } }

function macPencereSuruklemeBaslat(e, id) {
  if (e.button !== 0) return;
  e.stopPropagation();
  macPencereOnePlanaAl(id);
  const p = macPencereBul(id);
  if (!p || p.buyutulmus) return;
  const el0 = document.getElementById(id);
  if (el0) el0.style.transition = "none"; // gecisli class'ının sürüklemeyi geciktirmesini önle
  const baslangicX = e.clientX, baslangicY = e.clientY;
  const solBaslangic = p.sol, ustBaslangic = p.ust;
  function hareket(ev) {
    p.sol = solBaslangic + (ev.clientX - baslangicX);
    p.ust = Math.max(0, ustBaslangic + (ev.clientY - baslangicY));
    const el = document.getElementById(p.id);
    if (el) { el.style.left = p.sol + "px"; el.style.top = p.ust + "px"; }
  }
  function birak() {
    document.removeEventListener("mousemove", hareket);
    document.removeEventListener("mouseup", birak);
  }
  document.addEventListener("mousemove", hareket);
  document.addEventListener("mouseup", birak);
}

/* Kenarlardan/köşelerden yeniden boyutlandırma — yon: "n","s","e","w" ve
   köşeler için bunların ikili birleşimi ("ne","nw","se","sw"). */
const MAC_PENCERE_MIN_G = 300, MAC_PENCERE_MIN_Y = 240;
function macPencereYenidenBoyutlandirBaslat(e, id, yon) {
  if (e.button !== 0) return;
  e.preventDefault();
  e.stopPropagation();
  macPencereOnePlanaAlHafif(id);
  const p = macPencereBul(id);
  if (!p || p.buyutulmus) return;
  const el0 = document.getElementById(id);
  if (el0) el0.style.transition = "none";
  const baslangicX = e.clientX, baslangicY = e.clientY;
  const { sol, ust, genislik, yukseklik } = p;
  function hareket(ev) {
    const dx = ev.clientX - baslangicX, dy = ev.clientY - baslangicY;
    let yeniSol = sol, yeniUst = ust, yeniG = genislik, yeniY = yukseklik;
    if (yon.includes("e")) yeniG = Math.max(MAC_PENCERE_MIN_G, genislik + dx);
    if (yon.includes("s")) yeniY = Math.max(MAC_PENCERE_MIN_Y, yukseklik + dy);
    if (yon.includes("w")) { yeniG = Math.max(MAC_PENCERE_MIN_G, genislik - dx); yeniSol = sol + (genislik - yeniG); }
    if (yon.includes("n")) { yeniY = Math.max(MAC_PENCERE_MIN_Y, yukseklik - dy); yeniUst = Math.max(0, ust + (yukseklik - yeniY)); }
    p.sol = yeniSol; p.ust = yeniUst; p.genislik = yeniG; p.yukseklik = yeniY;
    const el = document.getElementById(id);
    if (el) { el.style.left = p.sol + "px"; el.style.top = p.ust + "px"; el.style.width = p.genislik + "px"; el.style.height = p.yukseklik + "px"; }
  }
  function birak() {
    document.removeEventListener("mousemove", hareket);
    document.removeEventListener("mouseup", birak);
  }
  document.addEventListener("mousemove", hareket);
  document.addEventListener("mouseup", birak);
}
const MAC_RESIZE_TUTAMAC = ["n", "s", "e", "w", "ne", "nw", "se", "sw"];

function macPencereBaslik(p) {
  if (p.konuId) {
    const d = DERSLER.find(x => x.id === p.dersId);
    const k = d && d.konular.find(x => x.id === p.konuId);
    if (k) return k.ad;
  }
  return p.baslik;
}
function macPencereIcerik(p) {
  if (p.tur === "sayfa") return macPencereSayfaIcerik(p);
  if (p.konuId) {
    return `<div class="mac-pencere-geri" onclick="macPencereGeriGit('${p.id}')">‹ ${esc(p.baslik)}</div>${sayfaKonu(p.dersId, p.konuId)}`;
  }
  const d = DERSLER.find(x => x.id === p.dersId);
  if (!d) return `<div class="empty">Ders bulunamadı.</div>`;
  if (!d.konular.length) return `<div class="empty"><div class="icon">📂</div>Bu ders için henüz içerik eklenmedi.</div>`;
  const satirlar = d.konular.map(k => {
    const ilerleme = konuIlerleme(k);
    return `<div class="mac-liste-satir" onclick="macKonuAc('${p.id}','${k.id}')">
      ${badge(k.oncelik)}
      <div class="grow"><strong>${esc(k.ad)}</strong><div class="muted">%${ilerleme} tamamlandı</div></div>
      <span class="mac-liste-ok">›</span>
    </div>`;
  }).join("");
  return `<div class="mac-liste">${satirlar}</div>`;
}
/* "sayfa" türü pencereler — Beni Test Et, Takvim, Çalışma Planı,
   Yönetim, Üyelik. İçerik, o rotanın var olan tam sayfa render
   fonksiyonu doğrudan çağrılarak üretilir. */
function macPencereSayfaIcerik(p) {
  if (p.route === "test-et") return testEtDersler();
  if (p.route === "takvim") return sayfaTakvim();
  if (p.route === "plan") return sayfaPlan();
  if (p.route === "uyelik") return sayfaUyelik();
  if (p.route === "strateji") return sayfaStrateji();
  if (p.route === "yonetim") return sayfaYonetim(p.altRota || "dashboard");
  return `<div class="empty">İçerik bulunamadı.</div>`;
}

function macPencerelerCiz() {
  let host = document.getElementById("macPencereAlani");
  if (!host) { host = document.createElement("div"); host.id = "macPencereAlani"; document.body.appendChild(host); }
  const gorunurler = MAC_PENCERELER.filter(p => !p.simge);
  host.innerHTML = gorunurler.map(p => {
    const konum = p.buyutulmus
      ? `left:0;top:0;width:100vw;height:100vh;border-radius:0;`
      : `left:${p.sol}px;top:${p.ust}px;width:${p.genislik}px;height:${p.yukseklik}px;`;
    return `<div class="mac-pencere gecisli" id="${p.id}" style="${konum}z-index:${p.z}" onmousedown="macPencereOnePlanaAlHafif('${p.id}')">
      <div class="mac-pencere-baslik" onmousedown="macPencereSuruklemeBaslat(event,'${p.id}')">
        <span class="mac-pencere-trafik" onmousedown="event.stopPropagation()">
          <span class="mac-nokta kirmizi" onclick="macPencereKapat('${p.id}')" title="Kapat"></span>
          <span class="mac-nokta sari" onclick="macPencereKuculttur('${p.id}')" title="Küçült"></span>
          <span class="mac-nokta yesil" onclick="macPencereBuyult('${p.id}')" title="Büyüt/Küçült"></span>
          <span class="mac-nokta mavi" onclick="macPencereTamEkran('${p.id}')" title="Tam Ekran"></span>
        </span>
        <span class="mac-pencere-etiket">${p.ikon || ""} ${esc(macPencereBaslik(p))}</span>
      </div>
      <div class="mac-pencere-govde">${macPencereIcerik(p)}</div>
      ${p.buyutulmus ? "" : MAC_RESIZE_TUTAMAC.map(yon =>
        `<div class="mac-resize mac-resize-${yon}" onmousedown="macPencereYenidenBoyutlandirBaslat(event,'${p.id}','${yon}')"></div>`
      ).join("")}
    </div>`;
  }).join("");
  macSekmeCubuguCiz();
}
/* Ekranın en üstünde, açık TÜM pencereleri (görünür + küçültülmüş)
   sekme olarak listeler — tarayıcı sekmeleri gibi. Bir sekmeye
   tıklamak o pencereyi öne alır (küçültülmüşse geri getirir);
   sekmenin kendi "×"i o pencereyi kapatır. En üstteki (odaktaki,
   küçültülmemiş) pencerenin sekmesi vurgulanır.
   Altındaki tam sayfa (Finansallar, Dersler vb.) bir "pencere"
   olmadığı için MAC_PENCERELER'de yer almaz — ama pencereler onun
   üzerine yığılınca geri dönecek bir yer bulunamıyordu ("Finansallar
   sekmede yok"). Bu yüzden en az bir pencere açıkken, altındaki tam
   sayfa için de kapatılamayan (× yok) bir sekme ekleniyor; tıklanınca
   tüm pencereler küçültülüp o sayfa görünür hale geliyor. */
function macSekmeCubuguCiz() {
  let cubuk = document.getElementById("macSekmeCubugu");
  if (!cubuk) { cubuk = document.createElement("div"); cubuk.id = "macSekmeCubugu"; cubuk.className = "mac-sekme-cubugu"; document.body.appendChild(cubuk); }
  if (!MAC_PENCERELER.length) { cubuk.classList.remove("gorunur"); cubuk.innerHTML = ""; return; }
  cubuk.classList.add("gorunur");
  const gorunurler = MAC_PENCERELER.filter(p => !p.simge);
  const enUsttekiZ = gorunurler.length ? Math.max(...gorunurler.map(p => p.z)) : null;
  const sayfaBilgi = (macTamSayfaBaslikCubuguGerekli() && MAC_SON_ROTA && MAC_SON_ROTA !== "panel")
    ? macRotaBaslikBilgisi(MAC_SON_ROTA.split("/")) : null;
  const sayfaSekmesi = sayfaBilgi ? `<div class="mac-sekme sayfa-sekmesi${gorunurler.length ? "" : " aktif"}" onclick="macTumPencereleriKucult()">
    <span class="mac-sekme-etiket">${sayfaBilgi.ikon || ""} ${esc(sayfaBilgi.baslik)}</span>
    <span class="mac-sekme-kapat" onclick="event.stopPropagation();git('panel')" title="Kapat (Ana Sayfa'ya dön)">×</span>
  </div>` : "";
  cubuk.innerHTML = sayfaSekmesi + MAC_PENCERELER.map(p => {
    const aktif = !p.simge && p.z === enUsttekiZ;
    return `<div class="mac-sekme${aktif ? " aktif" : ""}${p.simge ? " kucultulmus" : ""}" onclick="macPencereOnePlanaAl('${p.id}')">
      <span class="mac-sekme-etiket">${p.ikon || ""} ${esc(macPencereBaslik(p))}</span>
      <span class="mac-sekme-kapat" onclick="event.stopPropagation();macPencereKapat('${p.id}')" title="Kapat">×</span>
    </div>`;
  }).join("");
}

/* Yönetimde oluşturulan özel sayfalar — slug ile menu.json'da saklanır. */
function sayfaOzel(slug) {
  const s = MENU_OZEL_SAYFALAR.find(x => x.slug === slug);
  if (!s) return `<div class="empty"><div class="icon">🔍</div>Sayfa bulunamadı.</div>`;
  return `<div class="page-title">${esc(s.baslik)}</div>
    <div class="ozet-oku-govde"><div class="icerik">${s.icerik || ""}</div></div>`;
}

/* ---------------------------------------------------------
   8. GİRİŞ EKRANI
   --------------------------------------------------------- */
function girisEkraniGoster() {
  $("#uygulama").style.display = "none";
  const g = $("#girisEkrani");
  g.style.display = "flex";
  g.innerHTML = `
    <div class="giris-kart">
      <div class="giris-logo">📘</div>
      <h1>YMM Hazırlık Platformu</h1>
      <p class="muted">Yeminli Mali Müşavirlik sınavı çalışma sistemi</p>

      <form id="formGiris" onsubmit="girisYap(event)" class="mt">
        <label>Kullanıcı adı</label>
        <input id="gKullanici" autocomplete="username" required>
        <label class="mt">Şifre</label>
        <input id="gSifre" type="password" autocomplete="current-password" required>
        <div id="gHata" class="hata"></div>
        <button class="btn btn-primary genis mt" type="submit" id="gGonderBtn">Giriş Yap</button>
      </form>

      <div class="giris-uyari">
        Bu platforma yalnızca yöneticinin eklediği üyeler giriş yapabilir.
        Hesabınız yoksa yöneticinizle iletişime geçin.
      </div>
    </div>`;
}

async function girisYap(e) {
  e.preventDefault();
  const btn = $("#gGonderBtn");
  const hataEl = $("#gHata");
  hataEl.textContent = "";
  btn.disabled = true;
  btn.textContent = "Giriş yapılıyor…";
  const r = await Auth.giris($("#gKullanici").value, $("#gSifre").value);
  btn.disabled = false;
  btn.textContent = "Giriş Yap";
  if (!r.ok) { hataEl.textContent = r.mesaj; return; }
  location.hash = "panel";
  yonlendir();
}

function cikisYap() {
  if (!confirm("Çıkış yapmak istediğinize emin misiniz?")) return;
  Auth.cikis();
  KU_SENKRON_YAPILDI = false;
  AI_SIR_DURUM = null;
  location.hash = "";
  girisEkraniGoster();
}

/* ---------------------------------------------------------
   8b. ANA SAYFA — HEDEF DERSTEN DÖNEN HATIRLATMA KARTI
   Sınava gireceğiniz derslerin mevcut bilgi kartlarından (🃏 Bilgi
   Kartı Hazırla ile eklenmiş soru+cevap) her Ana Sayfa açılışında
   rastgele biri seçilir — aynı içerik değil, düzenli aralıklarla
   farklı bir hatırlatma görülsün diye. Ayrı bir veri kaynağı gerekmez,
   mevcut soru bankasının bilgi_karti türündeki kayıtları kullanılır.
   --------------------------------------------------------- */
let PANEL_BILGI_KARTI = null;   // { dersAd, dersIkon, konuAd, soru, cevap } | null
let PANEL_BILGI_KARTI_ACIK = false;

function panelBilgiKartiSec() {
  const havuz = [];
  seciliDersler().forEach(d => d.konular.forEach(k => {
    sorulariGetir(d.id, k.id).filter(q => q.tip === "bilgi_karti").forEach(q => {
      havuz.push({ dersAd: d.ad, dersIkon: d.ikon, konuAd: k.ad, soru: q.s, cevap: q.cevap });
    });
  }));
  PANEL_BILGI_KARTI = havuz.length ? havuz[Math.floor(Math.random() * havuz.length)] : null;
  PANEL_BILGI_KARTI_ACIK = false;
}

function panelBilgiKartiCevapGosterToggle() {
  PANEL_BILGI_KARTI_ACIK = !PANEL_BILGI_KARTI_ACIK;
  const el = document.getElementById("panelBilgiKartiAlan");
  if (el) el.innerHTML = panelBilgiKartiHtml();
}

function panelBilgiKartiHtml() {
  const k = PANEL_BILGI_KARTI;
  if (!k) return "";
  return `<div class="card mb">
    <div class="row between wrap mb">
      <h3>💡 Hedef Dersinizden Bir Hatırlatma</h3>
      <span class="muted">${k.dersIkon} ${esc(k.dersAd)} — ${esc(k.konuAd)}</span>
    </div>
    <p style="font-size:15px">${esc(k.soru)}</p>
    ${PANEL_BILGI_KARTI_ACIK
      ? `<div class="notice basari-notice mt">${esc(k.cevap)}</div>`
      : `<button class="btn btn-sm mt" onclick="panelBilgiKartiCevapGosterToggle()">Cevabı Gör</button>`}
  </div>`;
}

/* ---------------------------------------------------------
   9. SAYFA — GÖSTERGE PANELİ
   --------------------------------------------------------- */
function sayfaPanel() {
  const u = Auth.aktif();
  const ay = Ayar.oku();
  const genel = genelIlerleme();
  const secili = seciliDersler();
  const plan = planOlustur();
  const bugunPlan = plan.find(p => p.tarih === new Date().toISOString().slice(0, 10));
  const son = state.sonCalisma;
  panelBilgiKartiSec();

  const dersKartlari = secili.map(d => {
    const p = dersIlerleme(d);
    return `<div class="card course-card" style="border-left-color:${d.renk}" onclick="git('dersler/${d.id}')">
      <div class="course-head">
        <span class="course-icon">${d.ikon}</span>
        <div class="grow"><h3>${esc(d.ad)}</h3></div>
        <strong style="font-size:19px">${p}%</strong>
      </div>${bar(p)}
      <div class="course-meta">
        <span>${d.konular.length} konu</span>
        <span>${d.konular.filter(konuHazir).length} materyalli</span>
        <span>Hedef ${d.hedefNot}+</span>
      </div></div>`;
  }).join("");

  let bugunHtml = "";
  if (bugunPlan && bugunPlan.dinlenme) {
    bugunHtml = `<div class="card"><h3>🌤 Dinlenme günü</h3>
      <p class="muted mt">Bugün plan yok. Dinlenmek de çalışmanın parçasıdır.</p></div>`;
  } else if (bugunPlan && bugunPlan.gorevler && bugunPlan.gorevler.length) {
    bugunHtml = `<div class="card">
      <div class="row between wrap mb">
        <h3>Bugünün hedefi — ${bugunPlan.hedefSayfa} sayfa</h3>
        <button class="btn btn-sm" onclick="git('plan')">Tüm plan</button>
      </div>
      ${bugunPlan.gorevler.map(g => `
        <div class="gorev">
          <span>${g.dersIkon}</span>
          <div class="grow">
            <strong>${esc(g.konuAd)}</strong> ${badge(g.oncelik)}
            ${g.tekrar ? `<span class="badge badge-A">Tekrar</span>` : ""}
            <div class="muted">${g.sayfa} sayfa</div>
          </div>
          <button class="btn btn-sm btn-primary" onclick="git('dersler/${g.dersId}/${g.konuId}')">Aç</button>
        </div>`).join("")}
    </div>`;
  } else if (bugunPlan && bugunPlan.tekrarDonemi) {
    bugunHtml = `<div class="card"><h3>🔁 Tekrar ve deneme dönemi</h3>
      <p class="muted mt">Yeni konu açma. Tuzak kartları ve soru bankası üzerinden tekrar yap.</p></div>`;
  } else {
    const materyalliKonu = secili.flatMap(d => d.konular).filter(konuHazir);
    const hepsiBitti = materyalliKonu.length > 0 && materyalliKonu.every(k => konuIlerleme(k) >= 100);
    bugunHtml = hepsiBitti
      ? `<div class="card"><h3>🎉 Tüm konuları tamamladınız</h3>
         <p class="muted mt">Artık soru bankası üzerinden kendinizi test edin.</p>
         <button class="btn btn-primary mt" onclick="git('dersler')">Soru Bankası</button></div>`
      : `<div class="card"><h3>Plan henüz kurulmadı</h3>
         <p class="muted mt">${!secili.length
        ? "Sınava gireceğiniz dersleri seçin."
        : "Seçtiğiniz derslere materyal ekleyin."}</p>
         <button class="btn btn-primary mt" onclick="git('${!secili.length ? "uyelik" : "dersler"}')">
           ${!secili.length ? "Ders Seç" : "Derslere Git"}</button></div>`;
  }

  /* Kullanıcının Takvim sayfasından kendi eliyle işaretlediği plan —
     bugünü kapsayan bir kayıt varsa otomatik plandan önce, ayrı bir
     kartla gösterilir. */
  const takvimBugun = takvimGunPlani(takvimIso(new Date()));
  const takvimBugunHtml = !takvimBugun.length ? "" : `<div class="card mb">
    <div class="row between wrap mb">
      <h3>🗓️ Takvim Planınıza Göre Bugün</h3>
      <button class="btn btn-sm" onclick="git('takvim')">Takvimi Aç</button>
    </div>
    ${takvimBugun.map(p => {
    const d = DERSLER.find(x => x.id === p.dersId);
    const k = d && d.konular.find(x => x.id === p.konuId);
    return `<div class="gorev">
        <span>${d ? d.ikon : "📘"}</span>
        <div class="grow"><strong>${esc(k ? k.ad : "?")}</strong><div class="muted">${esc(d ? d.ad : "")}</div></div>
        ${d && k ? `<button class="btn btn-sm btn-primary" onclick="git('dersler/${d.id}/${k.id}')">Aç</button>` : ""}
      </div>`;
  }).join("")}
  </div>`;

  /* Başarısız sınav uyarıları */
  const uyarilar = Object.keys(state.planSifirlama).map(kid => {
    const k = DERSLER.flatMap(d => d.konular).find(x => x.id === kid);
    if (!k) return "";
    const s = sonSinav(kid);
    return `<div class="notice hata-notice">
      <strong>${esc(k.ad)}</strong> sınavında ${s ? s.puan : 0} puan aldınız.
      Geçme notu ${GECME_NOTU}. Bu konunun okuma ilerlemesi sıfırlandı ve
      plana tekrar eklendi.</div>`;
  }).join("");

  return `
    <div class="page-title">Merhaba ${esc(u.ad.split(" ")[0])} 👋</div>
    <div class="page-sub">${SINAV.ad} — ${tarihTR(ay.sinavTarihi)}, ${SINAV.yer}</div>
    ${uyarilar}
    ${takvimBugunHtml}
    <div id="panelBilgiKartiAlan">${panelBilgiKartiHtml()}</div>

    <div class="grid grid-4">
      <div class="stat"><div class="stat-label">Sınava Kalan</div>
        <div class="stat-value">${kalanGun()}</div><div class="stat-note">gün</div></div>
      <div class="stat"><div class="stat-label">Genel İlerleme</div>
        <div class="stat-value">${genel}%</div>${bar(genel)}</div>
      <div class="stat"><div class="stat-label">Okunan Sayfa</div>
        <div class="stat-value">${toplamOkunanSayfa()}</div>
        <div class="stat-note">toplam ${toplamSayfa()} sayfa içinde</div></div>
      <div class="stat"><div class="stat-label">Günlük Tempo</div>
        <div class="stat-value">${gunlukTempo()}</div>
        <div class="stat-note">sayfa/gün gerekiyor</div></div>
    </div>

    <h2 class="section">Bugün</h2>
    ${bugunHtml}

    ${son ? `<h2 class="section">Kaldığın Yerden Devam Et</h2>
    <div class="card"><div class="row between wrap">
      <div><h3>${esc(son.materyalAd)}</h3>
        <div class="muted">${esc(son.konuAd)} — Sayfa ${son.sayfa}</div></div>
      <button class="btn btn-primary" onclick="git('oku/${son.dersId}/${son.konuId}/${son.materyalId}/${son.sayfa}')">Devam Et</button>
    </div></div>` : ""}

    <h2 class="section">Sınava Gireceğim Dersler (${secili.length})</h2>
    <div class="grid grid-2">${dersKartlari || `<div class="empty">
      <div class="icon">📋</div><h3>Ders seçilmedi</h3>
      <p class="muted mt">Üyelik sayfasından sınava gireceğiniz dersleri seçin.</p>
      <button class="btn btn-primary mt" onclick="git('uyelik')">Ders Seç</button></div>`}</div>`;
}

/* ---------------------------------------------------------
   10. SAYFA — DERSLER
   --------------------------------------------------------- */
function sayfaDersler() {
  const ay = Ayar.oku();
  const kartlar = DERSLER.filter(d => d.aktif !== false).map(d => {
    const p = dersIlerleme(d);
    const secili = ay.secilenDersler.includes(d.id);
    const a = d.konular.filter(k => k.oncelik === "A").length;
    const b = d.konular.filter(k => k.oncelik === "B").length;
    const c = d.konular.filter(k => k.oncelik === "C").length;
    return `<div class="card course-card ${secili ? "" : "soluk"}" style="border-left-color:${d.renk}"
        onclick="git('dersler/${d.id}')">
      <div class="course-head">
        <span class="course-icon">${d.ikon}</span>
        <div class="grow"><h3>${esc(d.ad)} ${secili ? `<span class="badge badge-ok">Sınavda</span>` : ""}</h3>
          <div class="muted">${esc(d.aciklama)}</div></div>
        <strong style="font-size:19px">${p}%</strong>
      </div>${bar(p)}
      <div class="course-meta">
        ${a ? `<span>${badge("A")} ${a}</span>` : ""}
        ${b ? `<span>${badge("B")} ${b}</span>` : ""}
        ${c ? `<span>${badge("C")} ${c}</span>` : ""}
        ${!d.konular.length ? `<span class="badge badge-gray">İçerik bekleniyor</span>` : ""}
      </div></div>`;
  }).join("");

  return `<div class="page-title">Dersler</div>
    <div class="page-sub">${SINAV.basariSarti}</div>
    <div class="grid grid-2">${kartlar}</div>`;
}

/* ---------------------------------------------------------
   11. SAYFA — DERS DETAYI
   --------------------------------------------------------- */
function sayfaDers(dersId) {
  const d = DERSLER.find(x => x.id === dersId);
  if (!d) return `<div class="empty">Ders bulunamadı.</div>`;

  if (!d.konular.length) {
    return `<div class="breadcrumb"><a onclick="git('dersler')">Dersler</a> / ${esc(d.ad)}</div>
      <div class="page-title">${d.ikon} ${esc(d.ad)}</div>
      <div class="empty"><div class="icon">📂</div>
        <h3>Bu ders için henüz içerik eklenmedi</h3>
        <p class="muted mt">Konuları eklemek için <code>assets/js/data.js</code> dosyasındaki
        bu dersin <code>konular</code> dizisini doldurun.</p></div>`;
  }

  const gruplar = ["A", "B", "C"].map(o => {
    const ks = d.konular.filter(k => k.oncelik === o);
    if (!ks.length) return "";
    const satirlar = ks.map(k => {
      const p = konuIlerleme(k), hazir = konuHazir(k);
      const sayfa = (k.materyaller || []).reduce((s, m) => s + (m.toplamSayfa || 0), 0);
      const sn = soruSayisi(d.id, k.id);
      const iyi = enIyiPuan(k.id);
      const tekrar = !!state.planSifirlama[k.id];
      return `<div class="topic-row ${tekrar ? "tekrar-satir" : ""}" onclick="git('dersler/${d.id}/${k.id}')">
        ${badge(k.oncelik)}
        <div class="grow"><strong>${esc(k.ad)}</strong>
          ${tekrar ? `<span class="badge badge-A">Tekrar gerekli</span>` : ""}
          ${iyi !== null ? `<span class="badge ${iyi >= GECME_NOTU ? "badge-ok" : "badge-A"}">Sınav ${iyi}</span>` : ""}
          <div class="muted">Çıkma oranı %${(k.cikmaOrani * 100).toFixed(1)} · ${k.tahminiSoru} kez soruldu
            ${hazir ? ` · ${sayfa} sayfa` : ` · <span class="badge badge-gray">materyal yok</span>`}
            ${sn ? ` · ${sn} soru` : ""}</div>
          ${hazir ? bar(p) : ""}</div>
        <div class="topic-pct">${hazir ? p + "%" : "—"}</div></div>`;
    }).join("");
    return `<h2 class="section">${badge(o)} &nbsp;${o} Önceliği</h2>
      <div class="muted mb">${ONCELIK_ACIKLAMA[o]}</div>${satirlar}`;
  }).join("");

  return `<div class="breadcrumb"><a onclick="git('dersler')">Dersler</a> / ${esc(d.ad)}</div>
    <div class="page-title">${d.ikon} ${esc(d.ad)}</div>
    <div class="page-sub">${esc(d.aciklama)}</div>
    <div class="grid grid-3 mb">
      <div class="stat"><div class="stat-label">Ders İlerlemesi</div>
        <div class="stat-value">${dersIlerleme(d)}%</div>${bar(dersIlerleme(d))}
        <div class="stat-note">Öncelik ağırlıklı</div></div>
      <div class="stat"><div class="stat-label">Çalışılacak Konu</div>
        <div class="stat-value">${d.konular.length}</div><div class="stat-note">A + B + C grubu</div></div>
      <div class="stat"><div class="stat-label">Hedef Not</div>
        <div class="stat-value">${d.hedefNot}+</div><div class="stat-note">Geçme sınırı 50</div></div>
    </div>${gruplar}`;
}

/* ---------------------------------------------------------
   11b. BENİ TEST ET — dersler/konular üzerinden bilgi kartı +
   test akışı. Mevcut SORULAR/sınav altyapısını yeniden kullanır,
   yeni bir veri modeli gerekmez.
   --------------------------------------------------------- */
function dersTestDurumu(d) {
  const testliKonular = d.konular.filter(k => soruSayisi(d.id, k.id) > 0);
  if (!testliKonular.length) return { durum: "yok", toplam: 0, tamam: 0 };
  const tamam = testliKonular.filter(k => {
    const p = enIyiPuan(k.id);
    return p !== null && p >= GECME_NOTU;
  }).length;
  return { durum: tamam === testliKonular.length ? "tamamlandi" : "devam", toplam: testliKonular.length, tamam };
}

function testEtDersler() {
  const kartlar = DERSLER.filter(d => d.aktif !== false).map(d => {
    const durum = dersTestDurumu(d);
    const rozet = durum.durum === "tamamlandi" ? `<span class="badge badge-ok">✓ Tamamlandı</span>`
      : durum.durum === "devam" ? `<span class="badge badge-A">${durum.tamam}/${durum.toplam} konu geçildi</span>`
      : `<span class="badge badge-gray">Henüz soru yok</span>`;
    return `<div class="card course-card" style="border-left-color:${d.renk}" onclick="git('test-et/${d.id}')">
      <div class="course-head">
        <span class="course-icon">${d.ikon}</span>
        <div class="grow"><h3>${esc(d.ad)}</h3><div class="muted">${esc(d.aciklama)}</div></div>
        ${rozet}
      </div></div>`;
  }).join("");
  return `<div class="page-title">🧠 Beni Test Et</div>
    <div class="page-sub">Bir ders seçin — konu konu bilgi kartları ve testlerle kendinizi sınayın.</div>
    <div class="grid grid-2">${kartlar}</div>`;
}

function testEtKonular(dersId) {
  const d = DERSLER.find(x => x.id === dersId);
  if (!d) return `<div class="empty">Ders bulunamadı.</div>`;
  const satirlar = d.konular.map(k => {
    const sn = soruSayisi(dersId, k.id);
    const iyi = enIyiPuan(k.id);
    const gecti = iyi !== null && iyi >= GECME_NOTU;
    if (!sn) return `<div class="topic-row soluk">
      ${badge(k.oncelik)}<div class="grow"><strong>${esc(k.ad)}</strong><div class="muted">Henüz soru eklenmedi</div></div>
      <span class="badge badge-gray">Soru yok</span></div>`;
    return `<div class="topic-row" onclick="git('test-et/${dersId}/${k.id}')">
      ${badge(k.oncelik)}
      <div class="grow"><strong>${esc(k.ad)}</strong>
        <div class="muted">${sn} soru ${iyi !== null ? `· En iyi puan ${iyi}` : "· Henüz çözülmedi"}</div></div>
      <span class="badge ${gecti ? "badge-ok" : iyi !== null ? "badge-A" : "badge-gray"}">${gecti ? "✓ Geçti" : iyi !== null ? "Tekrar gerekli" : "Başlanmadı"}</span>
    </div>`;
  }).join("");
  return `<div class="breadcrumb"><a onclick="git('test-et')">Beni Test Et</a> / ${esc(d.ad)}</div>
    <div class="page-title">${d.ikon} ${esc(d.ad)}</div>
    <div class="page-sub">Bir konu seçin.</div>
    ${satirlar || `<div class="empty"><div class="icon">📂</div>Bu derste henüz konu yok.</div>`}`;
}

function testEtKonu(dersId, konuId) {
  const d = DERSLER.find(x => x.id === dersId);
  const k = konuBul(dersId, konuId);
  if (!d || !k) return `<div class="empty">Konu bulunamadı.</div>`;
  const sorular = sorulariGetir(dersId, konuId);
  const sinavSorular = sinavSorulariGetir(dersId, konuId);
  const gecmis = sinavGecmisi(konuId);
  const iyi = enIyiPuan(konuId);
  const gecti = iyi !== null && iyi >= GECME_NOTU;

  if (!sorular.length) {
    return `<div class="breadcrumb"><a onclick="git('test-et')">Beni Test Et</a> /
      <a onclick="git('test-et/${dersId}')">${esc(d.ad)}</a> / ${esc(k.ad)}</div>
      <div class="empty"><div class="icon">❓</div>Bu konu için henüz soru eklenmedi.</div>`;
  }

  BILGI_KART_DURUM = { sorular, index: 0, cevrili: false, sonYon: 0 };

  return `<div class="breadcrumb"><a onclick="git('test-et')">Beni Test Et</a> /
      <a onclick="git('test-et/${dersId}')">${esc(d.ad)}</a> / ${esc(k.ad)}</div>
    <div class="page-title">${esc(k.ad)}</div>
    <div class="page-sub">${gecti ? "✓ Bu konuyu başarıyla tamamladınız." : "Önce bilgi kartlarıyla çalışın, sonra testi çözün."}</div>

    <div class="grid grid-3 mb">
      <div class="stat"><div class="stat-label">Bilgi Kartı</div><div class="stat-value">${sorular.length}</div></div>
      <div class="stat"><div class="stat-label">En İyi Puan</div><div class="stat-value">${iyi ?? "—"}</div></div>
      <div class="stat"><div class="stat-label">Durum</div>
        <div class="stat-value" style="font-size:16px">${gecti ? "✅ Tamamlandı" : "📚 Devam Ediyor"}</div></div>
    </div>

    <h2 class="section">🃏 Bilgi Kartları</h2>
    <div id="bilgiKartAlan" class="mb">${bilgiKartAlaniHtml()}</div>

    <h2 class="section">✏️ Test Çöz</h2>
    <div class="card mb">
      ${sinavSorular.length ? `
      <p class="muted mb">Geçme notu ${GECME_NOTU}. Testten geçerseniz bu konu "tamamlandı" sayılır,
        geçemezseniz konuyu tekrar etmeniz önerilir.</p>
      <div class="row wrap" style="gap:8px">
        <button class="btn btn-primary" onclick="git('sinav/${dersId}/${konuId}')">Tam Test (${sinavSorular.length} soru)</button>
        ${sinavSorular.length > 10 ? `<button class="btn" onclick="hizliSinav('${dersId}','${konuId}',10)">Hızlı Test (10 soru)</button>` : ""}
      </div>` : `<p class="muted">Bu konu için henüz çoktan seçmeli soru eklenmedi — şimdilik yalnızca bilgi kartlarıyla çalışabilirsiniz.</p>`}
    </div>

    ${gecmis.length ? `<div class="card">
      <h3 class="mb">Geçmiş Sonuçlar</h3>
      <table><thead><tr><th>Tarih</th><th>Doğru</th><th>Puan</th><th>Sonuç</th></tr></thead><tbody>
      ${[...gecmis].reverse().slice(0, 10).map(s => `<tr>
        <td>${tarihTR(s.tarih)}</td><td>${s.dogru}/${s.toplam}</td><td><strong>${s.puan}</strong></td>
        <td>${s.gecti ? `<span class="badge badge-ok">Geçti</span>` : `<span class="badge badge-A">Kaldı</span>`}</td>
      </tr>`).join("")}
      </tbody></table></div>` : ""}`;
}

/* Bilgi kartları — tek seferde bir kart, üstte kart numarası, altta
   Önceki/Sonraki gezinme. Karta dokunmak sayfa çevirir gibi 3B flip
   animasyonuyla arka yüzü (cevabı) gösterir. */
let BILGI_KART_DURUM = null; // { sorular, index, cevrili, sonYon }

function bilgiKartAlaniHtml() {
  const d = BILGI_KART_DURUM;
  if (!d || !d.sorular.length) return `<div class="empty"><div class="icon">🃏</div>Bu konu için soru yok.</div>`;
  const q = d.sorular[d.index];
  const cevapMetni = q.tip === "bilgi_karti" ? q.cevap : q.o[q.d];
  const slaytSinif = d.sonYon === 1 ? "slayt-sag" : d.sonYon === -1 ? "slayt-sol" : "";
  return `
    <div class="bilgi-kart-sayac">Kart ${d.index + 1} / ${d.sorular.length}</div>
    <div class="bilgi-kart-tekli ${d.cevrili ? "cevrili" : ""} ${slaytSinif}" onclick="bilgiKartTekliCevir()">
      <div class="bilgi-kart-inner">
        <div class="bilgi-kart-yuz bilgi-kart-on">
          <span class="badge badge-gray mb">${esc(q.bolum)}</span>
          <p>${esc(q.s)}</p>
          <div class="muted mt" style="font-size:12px">Cevabı görmek için karta dokunun</div>
        </div>
        <div class="bilgi-kart-yuz bilgi-kart-arka">
          <div class="badge badge-ok mb">✓ ${esc(cevapMetni)}</div>
          <p class="muted" style="font-size:13px">${esc(q.aciklama || "")}</p>
          <div class="muted mt" style="font-size:12px">Soruya dönmek için karta dokunun</div>
        </div>
      </div>
    </div>
    <div class="row between mt">
      <button class="btn" onclick="bilgiKartGec(-1)" ${d.index === 0 ? "disabled" : ""}>◀ Önceki</button>
      <button class="btn" onclick="bilgiKartGec(1)" ${d.index === d.sorular.length - 1 ? "disabled" : ""}>Sonraki ▶</button>
    </div>`;
}

/* Yalnızca .cevrili sınıfını mevcut DOM düğümünde değiştirir (innerHTML'i
   yeniden yazmaz) — böylece CSS transition, gerçek bir flip animasyonu
   olarak oynar; yeniden oluşturulan bir düğümde transition tetiklenmez. */
function bilgiKartTekliCevir() {
  if (!BILGI_KART_DURUM) return;
  BILGI_KART_DURUM.cevrili = !BILGI_KART_DURUM.cevrili;
  const kart = document.querySelector(".bilgi-kart-tekli");
  if (kart) kart.classList.toggle("cevrili", BILGI_KART_DURUM.cevrili);
}
function bilgiKartGec(yon) {
  const d = BILGI_KART_DURUM;
  if (!d) return;
  const hedef = d.index + yon;
  if (hedef < 0 || hedef >= d.sorular.length) return;
  d.index = hedef;
  d.cevrili = false;
  d.sonYon = yon;
  const alan = document.getElementById("bilgiKartAlan");
  if (alan) alan.innerHTML = bilgiKartAlaniHtml();
}

/* ---------------------------------------------------------
   11c. FORUM — kategori (yönetici açar) → konu (üye açar) →
   mesaj (üye yazar). Okuma herkese açık; konu/mesaj eklemek
   sunucu bağlantısı + kayıtlı üyelik gerektirir (uygulama
   anahtarı değil — bkz. forum_yardimci.php).
   --------------------------------------------------------- */
let FORUM_KATEGORILER = null;
let FORUM_YUKLEME_HATASI = false;
function forumKategorilerYukle() {
  kuForumKategorilerGetir()
    .then(liste => { FORUM_KATEGORILER = liste; FORUM_YUKLEME_HATASI = false; yonlendir(); })
    .catch(e => { FORUM_KATEGORILER = []; FORUM_YUKLEME_HATASI = true; console.error("Forum başlıkları yüklenemedi", e); yonlendir(); });
}

/* Not: forum okuma herkese açıktır (content/dersler.json gibi) — bir üye
   ppBagliMi() ile ölçülen uygulama anahtarına sahip olmasa bile forumu
   görebilir/kullanabilir. Uygulama anahtarı yalnızca yönetici işlemlerini
   (başlık ekleme/silme) korur, bkz. yonetimForumHtml(). */
function sayfaForum() {
  if (FORUM_KATEGORILER === null) { setTimeout(forumKategorilerYukle, 0); return `<div class="muted">Yükleniyor…</div>`; }

  if (FORUM_YUKLEME_HATASI) {
    return `<div class="page-title">💬 Forum</div>
      <div class="empty"><div class="icon">⚠️</div>
        <h3>Forum yüklenemedi</h3>
        <p class="muted mt">Sunucuya şu anda ulaşılamıyor. Birazdan tekrar deneyin.</p></div>`;
  }

  const kartlar = FORUM_KATEGORILER.map(k => `
    <div class="card course-card mb" onclick="git('forum/${k.id}')">
      <div class="course-head">
        <span class="course-icon">💬</span>
        <div class="grow"><h3>${esc(k.ad)}</h3><div class="muted">${esc(k.aciklama || "")}</div></div>
        <span class="badge badge-gray">${k.konuSayisi} konu</span>
      </div>
    </div>`).join("");

  return `<div class="page-title">💬 Forum</div>
    <div class="page-sub">Bir başlık seçin, konu açın ya da mevcut konulara cevap yazın.</div>
    ${kartlar || `<div class="empty"><div class="icon">💬</div>Henüz forum başlığı eklenmedi.</div>`}`;
}

let FORUM_KONULAR = null;
let FORUM_KONULAR_KATEGORI = null;
let FORUM_KONU_AC_ACIK = false;

function forumKonularYukle(kategoriId) {
  kuForumKonularGetir(kategoriId)
    .then(liste => { FORUM_KONULAR = liste; FORUM_KONULAR_KATEGORI = kategoriId; yonlendir(); })
    .catch(e => { FORUM_KONULAR = []; FORUM_KONULAR_KATEGORI = kategoriId; console.error("Konular yüklenemedi", e); yonlendir(); });
}

function sayfaForumKategori(kategoriIdStr) {
  const kategoriId = +kategoriIdStr;
  if (FORUM_KATEGORILER === null) { setTimeout(forumKategorilerYukle, 0); return `<div class="muted">Yükleniyor…</div>`; }
  const kategori = FORUM_KATEGORILER.find(k => k.id === kategoriId);
  if (!kategori) return `<div class="empty">Başlık bulunamadı.</div>`;

  if (FORUM_KONULAR === null || FORUM_KONULAR_KATEGORI !== kategoriId) {
    setTimeout(() => forumKonularYukle(kategoriId), 0);
    return `<div class="breadcrumb"><a onclick="git('forum')">Forum</a> / ${esc(kategori.ad)}</div>
      <div class="muted">Yükleniyor…</div>`;
  }

  const u = Auth.aktif();
  const satirlar = FORUM_KONULAR.map(kn => `
    <div class="icerik-satir" style="cursor:pointer">
      <div class="grow" onclick="git('forum/${kategoriId}/${kn.id}')">
        <strong>${esc(kn.baslik)}</strong>
        <div class="muted">@${esc(kn.kullaniciAdi)} · ${tarihTR(kn.olusturmaTarihi)}</div>
      </div>
      <span class="badge badge-gray">${kn.mesajSayisi} mesaj</span>
      ${Auth.yonetici() ? `<button class="btn btn-sm tehlike" onclick="event.stopPropagation();forumKonuSilGonder(${kn.id},${kategoriId})">Sil</button>` : ""}
    </div>`).join("");

  return `<div class="breadcrumb"><a onclick="git('forum')">Forum</a> / ${esc(kategori.ad)}</div>
    <div class="page-title">${esc(kategori.ad)}</div>
    <div class="page-sub">${esc(kategori.aciklama || "")}</div>

    <div class="card mb">
      ${!u ? `<div class="muted">Konu açmak için giriş yapın.</div>` :
        FORUM_KONU_AC_ACIK ? forumKonuAcForm(kategoriId) :
        `<button class="btn btn-primary" onclick="forumKonuAcAcGonder()">+ Yeni Konu Aç</button>`}
    </div>

    ${satirlar || `<div class="empty"><div class="icon">💬</div>Bu başlıkta henüz konu açılmadı — ilk siz açın.</div>`}`;
}

function forumKonuAcForm(kategoriId) {
  return `
    <label class="ayar-baslik">Başlık</label>
    <input class="genis-input" id="forumKonuBaslik" placeholder="Örn. TFRS 15 - Değişken bedel sorusu">
    <label class="ayar-baslik mt">Mesajınız</label>
    ${editorHtml("forumKonuIcerik", "")}
    <div class="row mt">
      <button class="btn btn-primary" onclick="forumKonuAcGonder(${kategoriId})">Konuyu Aç</button>
      <button class="btn" onclick="forumKonuAcKapatGonder()">Vazgeç</button>
    </div>
    <div class="hata" id="forumKonuAcHata"></div>`;
}
function forumKonuAcAcGonder() { FORUM_KONU_AC_ACIK = true; yonlendir(); }
function forumKonuAcKapatGonder() { FORUM_KONU_AC_ACIK = false; yonlendir(); }
async function forumKonuAcGonder(kategoriId) {
  const u = Auth.aktif();
  const baslik = $("#forumKonuBaslik").value.trim();
  /* editorGuvenliHtml: forum herhangi bir üyenin yazabildiği, başka
     üyelerin okuduğu bir alan — contenteditable'a devtools/panodan keyfi
     HTML sokulabileceğinden, göndermeden önce izinli etiket/öznitelik
     alt kümesine indirgenir (bkz. editor.js). Yönetimin tek başına
     yazdığı özet/özel sayfalarda bu adım gerekmez. */
  const icerik = editorGuvenliHtml(editorIcerikAl("forumKonuIcerik"));
  if (!baslik || !icerik || icerik === "<br>") { $("#forumKonuAcHata").textContent = "Başlık ve mesaj zorunludur."; return; }
  try {
    const id = await kuForumKonuAc(kategoriId, u.kullaniciAdi, baslik, icerik);
    FORUM_KONU_AC_ACIK = false;
    FORUM_KONULAR = null;
    FORUM_KATEGORILER = null;
    git(`forum/${kategoriId}/${id}`);
  } catch (e) {
    $("#forumKonuAcHata").textContent = e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "");
  }
}
function forumKonuSilGonder(konuId, kategoriId) {
  if (!confirm("Bu konu ve tüm mesajları silinecek. Emin misiniz?")) return;
  kuForumKonuSil(konuId)
    .then(() => { FORUM_KONULAR = null; FORUM_KATEGORILER = null; yonlendir(); })
    .catch(e => alert("Silinemedi: " + e.message));
}

let FORUM_KONU_DETAY = null;
let FORUM_KONU_DETAY_ID = null;

function forumKonuYukle(konuId) {
  kuForumKonuGetir(konuId)
    .then(sonuc => { FORUM_KONU_DETAY = sonuc; FORUM_KONU_DETAY_ID = konuId; yonlendir(); })
    .catch(e => { FORUM_KONU_DETAY = { konu: null, mesajlar: [] }; FORUM_KONU_DETAY_ID = konuId; console.error("Konu yüklenemedi", e); yonlendir(); });
}

function sayfaForumKonu(kategoriIdStr, konuIdStr) {
  const kategoriId = +kategoriIdStr, konuId = +konuIdStr;
  if (FORUM_KONU_DETAY === null || FORUM_KONU_DETAY_ID !== konuId) {
    setTimeout(() => forumKonuYukle(konuId), 0);
    return `<div class="muted">Yükleniyor…</div>`;
  }
  const { konu, mesajlar } = FORUM_KONU_DETAY;
  if (!konu) return `<div class="empty">Konu bulunamadı.</div>`;

  const u = Auth.aktif();
  const kategoriAd = (FORUM_KATEGORILER || []).find(k => k.id === kategoriId)?.ad || "Başlık";
  const mesajHtml = mesajlar.map(m => `
    <div class="card mb">
      <div class="row between">
        <strong>@${esc(m.kullaniciAdi)}</strong>
        <span class="muted" style="font-size:12px">${tarihTR(m.olusturmaTarihi)}</span>
      </div>
      <div class="ozet-oku-govde" style="margin-top:8px"><div class="icerik">${editorGuvenliHtml(m.icerik)}</div></div>
      ${Auth.yonetici() ? `<div class="row mt"><button class="btn btn-sm tehlike" onclick="forumMesajSilGonder(${m.id})">Sil</button></div>` : ""}
    </div>`).join("");

  return `<div class="breadcrumb">
      <a onclick="git('forum')">Forum</a> /
      <a onclick="git('forum/${kategoriId}')">${esc(kategoriAd)}</a> / ${esc(konu.baslik)}</div>
    <div class="page-title">${esc(konu.baslik)}</div>
    <div class="page-sub">@${esc(konu.kullaniciAdi)} tarafından ${tarihTR(konu.olusturmaTarihi)} tarihinde açıldı</div>

    ${mesajHtml}

    <div class="card">
      ${!u ? `<div class="muted">Cevap yazmak için giriş yapın.</div>` : `
      <label class="ayar-baslik">Cevabınız</label>
      ${editorHtml("forumMesajIcerik", "")}
      <button class="btn btn-primary mt" onclick="forumMesajEkleGonder(${konuId})">Gönder</button>
      <div class="hata" id="forumMesajHata"></div>`}
    </div>`;
}
async function forumMesajEkleGonder(konuId) {
  const u = Auth.aktif();
  const icerik = editorGuvenliHtml(editorIcerikAl("forumMesajIcerik"));
  if (!icerik || icerik === "<br>") return;
  try {
    await kuForumMesajEkle(konuId, u.kullaniciAdi, icerik);
    FORUM_KONU_DETAY = null;
    yonlendir();
  } catch (e) {
    $("#forumMesajHata").textContent = e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "");
  }
}
function forumMesajSilGonder(mesajId) {
  if (!confirm("Bu mesajı silmek istediğinize emin misiniz?")) return;
  kuForumMesajSil(mesajId)
    .then(() => { FORUM_KONU_DETAY = null; yonlendir(); })
    .catch(e => alert("Silinemedi: " + e.message));
}

/* ---------------------------------------------------------
   12. SAYFA — KONU DETAYI
   --------------------------------------------------------- */
function sayfaKonu(dersId, konuId) {
  const d = DERSLER.find(x => x.id === dersId);
  const k = konuBul(dersId, konuId);
  if (!d || !k) return `<div class="empty">Konu bulunamadı.</div>`;

  const sn = sinavSorulariGetir(dersId, konuId).length;
  const gecmis = sinavGecmisi(konuId);
  const iyi = enIyiPuan(konuId);
  const son = sonSinav(konuId);
  const tekrar = !!state.planSifirlama[konuId];

  const materyaller = (k.materyaller || []).map(m => {
    const p = materyalIlerleme(m);
    const dur = MATERYAL_DURUM[m.durum] || MATERYAL_DURUM["taslak"];
    const bolumler = (m.bolumler || []).map(b => {
      let ok = 0;
      for (let i = b.bas; i <= b.bit; i++) if (sayfaOkundu(m.id, i)) ok++;
      const bp = Math.round(ok / (b.bit - b.bas + 1) * 100);
      return `<tr onclick="git('oku/${d.id}/${k.id}/${m.id}/${b.bas}')" style="cursor:pointer">
        <td><strong>${esc(b.ad)}</strong></td>
        <td class="muted">Sayfa ${b.bas}–${b.bit}</td>
        <td style="width:150px">${bar(bp)}</td>
        <td style="text-align:right"><strong>${bp}%</strong></td></tr>`;
    }).join("");

    return `<div class="card mb">
      <div class="row between wrap mb">
        <div><h3>${esc(m.ad)}</h3>
          <div class="muted">${m.toplamSayfa} sayfa · ${okunanSayfa(m.id)} okundu ·
            <span class="badge" style="background:${dur.renk}22;color:${dur.renk}">${dur.etiket}</span></div></div>
        <button class="btn btn-primary" onclick="git('oku/${d.id}/${k.id}/${m.id}/${materyalDevamSayfasi(m)}')">${okunanSayfa(m.id) > 0 ? "Kaldığın Yerden Devam Et" : "Çalışmaya Başla"}</button>
      </div>${bar(p)}
      <div class="row between mt"><span class="muted">Materyal ilerlemesi</span><strong>${p}%</strong></div>
      ${bolumler ? `<table class="mt"><thead><tr><th>Bölüm</th><th>Sayfa</th><th>İlerleme</th><th></th></tr></thead>
        <tbody>${bolumler}</tbody></table>` : ""}</div>`;
  }).join("");

  /* Sınav bölümü */
  let sinavHtml = "";
  if (sn > 0) {
    const bolumler = sinavSorulariBolumle(dersId, konuId);
    sinavHtml = `
      <h2 class="section">Soru Bankası — ${sn} Soru</h2>
      ${tekrar ? `<div class="notice hata-notice mb">
        Son sınavda ${son.puan} puan aldınız. Geçme notu ${GECME_NOTU}.
        Bu konunun okuma ilerlemesi sıfırlandı, konuyu yeniden çalışmanız gerekiyor.</div>` : ""}
      ${iyi !== null && iyi >= GECME_NOTU ? `<div class="notice basari-notice mb">
        🎉 <strong>Tebrikler, başardınız!</strong> Bu konudaki en iyi puanınız ${iyi}.</div>` : ""}

      <div class="card mb">
        <div class="row between wrap mb">
          <div><h3>Konu Sınavı</h3>
            <div class="muted">Geçme notu ${GECME_NOTU}. ${GECME_NOTU} altında kalırsanız konu
            ilerlemeniz sıfırlanır ve çalışma planı yeniden kurulur.</div></div>
        </div>
        <div class="row wrap mt">
          <button class="btn btn-primary" onclick="git('sinav/${dersId}/${konuId}')">Tam Sınav (${sn} soru)</button>
          <button class="btn" onclick="hizliSinav('${dersId}','${konuId}',20)">Hızlı Test (20 soru)</button>
          <button class="btn" onclick="hizliSinav('${dersId}','${konuId}',10)">Mini Test (10 soru)</button>
        </div>
        <table class="mt"><thead><tr><th>Bölüm</th><th>Soru</th><th></th></tr></thead><tbody>
          ${Object.entries(bolumler).map(([b, qs]) => `<tr>
            <td><strong>${esc(b)}</strong></td><td>${qs.length}</td>
            <td style="text-align:right"><button class="btn btn-sm"
              onclick="bolumSinavi('${dersId}','${konuId}','${esc(b).replace(/'/g, "\\'")}')">Bu bölümü çöz</button></td>
          </tr>`).join("")}
        </tbody></table>
      </div>

      ${gecmis.length ? `<div class="card mb"><h3>Sınav Geçmişi</h3>
        <table class="mt"><thead><tr><th>Tarih</th><th>Doğru</th><th>Puan</th><th>Sonuç</th></tr></thead><tbody>
        ${[...gecmis].reverse().slice(0, 10).map(s => `<tr>
          <td>${tarihTR(s.tarih)}</td><td>${s.dogru}/${s.toplam}</td>
          <td><strong>${s.puan}</strong></td>
          <td>${s.gecti ? `<span class="badge badge-ok">Geçti</span>` : `<span class="badge badge-A">Kaldı</span>`}</td>
        </tr>`).join("")}</tbody></table></div>` : ""}`;
  }

  return `<div class="breadcrumb">
      <a onclick="git('dersler')">Dersler</a> /
      <a onclick="git('dersler/${d.id}')">${esc(d.ad)}</a> / ${esc(k.ad)}</div>
    <div class="page-title">${esc(k.ad)}</div>
    <div class="page-sub">${esc(k.tamAd || "")}</div>

    <div class="grid grid-4 mb">
      <div class="stat"><div class="stat-label">Öncelik</div><div class="stat-value">${k.oncelik}</div></div>
      <div class="stat"><div class="stat-label">Çıkma Oranı</div>
        <div class="stat-value">%${(k.cikmaOrani * 100).toFixed(1)}</div></div>
      <div class="stat"><div class="stat-label">Konu İlerlemesi</div>
        <div class="stat-value">${konuIlerleme(k)}%</div>${bar(konuIlerleme(k))}</div>
      <div class="stat"><div class="stat-label">En İyi Sınav</div>
        <div class="stat-value">${iyi !== null ? iyi : "—"}</div>
        <div class="stat-note">${gecmis.length} deneme</div></div>
    </div>

    ${k.not ? `<div class="notice mb">${esc(k.not)}</div>` : ""}

    ${Auth.yonetici() ? `<div class="row mb"><button class="btn btn-sm" onclick="git('yonetim/icerik/${d.id}/${k.id}')">🛡️ Bu konuya içerik ekle (Yönetim)</button></div>` : ""}

    <h2 class="section">Materyaller</h2>
    ${materyaller || `<div class="empty"><div class="icon">📄</div>
      <h3>Bu konu için materyal eklenmedi</h3>
      <p class="muted mt">PDF dosyanızı <code>materyaller/</code> klasörüne koyun ve
      <code>data.js</code> içindeki bu konunun <code>materyaller</code> dizisine ekleyin,
      ya da Yönetim panelinden dosya yükleyin/yolunu girin.</p></div>`}

    <h2 class="section">📄 Özet</h2>
    ${konuOzetHtml(k, d.id)}

    <h2 class="section">🖼️ Görseller</h2>
    ${konuGorselHtml(k)}

    <h2 class="section">🎞️ Fotoromanlar</h2>
    ${konuFotoHtml(k)}

    ${sinavHtml}
    ${pratikHtml(dersId, konuId)}`;
}

/* ---------- PRATİK (bilgi kartı / anlık geri bildirimli çoktan seçmeli) ---------- */
let PRATIK_DURUM = null;

function pratikHtml(dersId, konuId) {
  if (!ppBagliMi()) return "";
  setTimeout(() => pratikAlaniCiz(dersId, konuId), 0);
  return `<h2 class="section">🧠 Pratik</h2><div id="pratikAlan_${konuId}" class="muted">Yükleniyor…</div>`;
}

async function pratikAlaniCiz(dersId, konuId) {
  const alan = document.getElementById("pratikAlan_" + konuId);
  if (!alan) return;
  if (PRATIK_DURUM && PRATIK_DURUM.konuId === konuId) { pratikOturumRenderEt(); return; }
  try {
    const setler = await ppSetListele(dersId, konuId);
    if (!setler.length) {
      alan.innerHTML = `<div class="empty"><div class="icon">🧠</div>Bu konu için henüz pratik seti eklenmedi.</div>`;
      return;
    }
    alan.innerHTML = `
      <div class="card">
        <label class="ayar-baslik">Set seçin</label>
        <select class="genis-input" id="pratikSetSecim_${konuId}" onchange="pratikGecmisGoster(this.value,'${konuId}')">
          ${setler.map(s => `<option value="${s.id}">${esc(s.ad)} (${s.soru_sayisi} soru)</option>`).join("")}
        </select>
        <p class="muted mt" style="font-size:12.5px">Her soru kendi türüne göre gösterilir: çoktan
          seçmeli sorularda bir şık seçince anında doğru/yanlış görürsünüz; bilgi kartı sorularında
          "Cevabı Göster" ile cevabı açarsınız.</p>
        <button class="btn btn-primary mt" onclick="pratikBaslat('${dersId}','${konuId}')">Pratiğe Başla</button>
      </div>
      <div id="pratikGecmisAlan_${konuId}" class="mt"></div>`;
    if (setler.length) pratikGecmisGoster(setler[0].id, konuId);
  } catch (e) {
    alan.innerHTML = `<div class="hata">Pratik setleri yüklenemedi: ${esc(e.message)}</div>`;
  }
}

async function pratikGecmisGoster(setId, konuId) {
  const alan = document.getElementById("pratikGecmisAlan_" + konuId);
  if (!alan) return;
  alan.innerHTML = `<div class="muted" style="font-size:13px">Geçmiş yükleniyor…</div>`;
  try {
    const kullaniciAdi = (Auth.aktif() || {}).kullaniciAdi || "";
    const denemeler = await ppDenemelerGetir(setId, kullaniciAdi);
    if (!denemeler.length) {
      alan.innerHTML = `<div class="muted" style="font-size:13px">Bu set için henüz deneme kaydınız yok.</div>`;
      return;
    }
    alan.innerHTML = `<h3 style="font-size:15px">Deneme Geçmişiniz</h3>
      <table class="mt"><thead><tr><th>Tarih</th><th>Doğru</th><th>Puan</th></tr></thead><tbody>
      ${denemeler.slice(0, 10).map(d => `<tr>
        <td>${tarihTR(d.tarih)}</td><td>${d.dogru}/${d.toplam}</td><td><strong>${d.puan}</strong></td>
      </tr>`).join("")}</tbody></table>`;
  } catch (e) {
    alan.innerHTML = `<div class="hata" style="font-size:13px">Geçmiş yüklenemedi: ${esc(e.message)}</div>`;
  }
}

async function pratikBaslat(dersId, konuId) {
  const setId = +document.getElementById("pratikSetSecim_" + konuId).value;
  const alan = document.getElementById("pratikAlan_" + konuId);
  if (alan) alan.innerHTML = `<div class="muted">Sorular yükleniyor…</div>`;
  try {
    const sorular = await ppSorularGetir(setId);
    if (!sorular.length) {
      if (alan) alan.innerHTML = `<div class="empty"><div class="icon">🧠</div>Bu sette henüz soru yok.</div>`;
      return;
    }
    const karisik = sorular.slice().sort(() => Math.random() - 0.5);
    PRATIK_DURUM = {
      dersId, konuId, setId, sorular: karisik, index: 0, dogru: 0,
      cevapVerildi: false, verilenCevap: null, kartAcik: false
    };
    pratikOturumRenderEt();
  } catch (e) {
    if (alan) alan.innerHTML = `<div class="hata">Sorular yüklenemedi: ${esc(e.message)}</div>`;
  }
}

function pratikOturumRenderEt() {
  const p = PRATIK_DURUM;
  if (!p) return;
  const alan = document.getElementById("pratikAlan_" + p.konuId);
  if (!alan) return;

  if (p.index >= p.sorular.length) {
    const puan = Math.round((p.dogru / p.sorular.length) * 100);
    alan.innerHTML = `
      <div class="sonuc-kart ${puan >= GECME_NOTU ? "basarili" : "basarisiz"}">
        <div class="sonuc-ikon">${puan >= GECME_NOTU ? "🎉" : "📚"}</div>
        <h1>${puan} Puan</h1>
        <p>${p.dogru} / ${p.sorular.length} doğru</p>
      </div>
      <div class="row"><button class="btn btn-primary" onclick="pratikBitir()">Tamam</button></div>`;
    pratikSonucKaydet(p);
    return;
  }

  const s = p.sorular[p.index];
  const bilgiKartiMi = s.tip === "bilgi_karti" || !s.secenekler || s.secenekler.length < 2;
  const ilerleme = `<div class="muted mb">Soru ${p.index + 1} / ${p.sorular.length} · Doğru: ${p.dogru}
    · <span class="badge badge-gray">${bilgiKartiMi ? "🗂️ Bilgi Kartı" : "✅ Çoktan Seçmeli"}</span></div>`;

  if (bilgiKartiMi) {
    alan.innerHTML = `
      ${ilerleme}
      <div class="card mb">
        <div style="font-size:16px;line-height:1.7">${esc(s.soru)}</div>
        ${p.kartAcik ? `<div class="notice basari-notice mt">${esc(s.dogru_cevap)}</div>
          ${s.aciklama ? `<div class="muted mt">${esc(s.aciklama)}</div>` : ""}` : ""}
      </div>
      <div class="row wrap">
        ${!p.kartAcik
          ? `<button class="btn btn-primary" onclick="pratikKartAc()">Cevabı Göster</button>`
          : `<button class="btn btn-ok" onclick="pratikBilgiKartiIsaretle(true)">✓ Biliyordum</button>
             <button class="btn tehlike" onclick="pratikBilgiKartiIsaretle(false)">✗ Bilmiyordum</button>`}
      </div>`;
  } else {
    const secenekler = s.secenekler || [];
    alan.innerHTML = `
      ${ilerleme}
      <div class="card mb"><div style="font-size:16px;line-height:1.7">${esc(s.soru)}</div></div>
      ${secenekler.map((sec, i) => {
        let sinif = "secenek";
        if (p.cevapVerildi) {
          if (sec === s.dogru_cevap) sinif += " dogru";
          else if (sec === p.verilenCevap) sinif += " yanlis";
        }
        return `<div class="${sinif}" ${p.cevapVerildi ? "" : `onclick="pratikCevapVer('${esc(sec).replace(/'/g, "\\'")}')"`}>
          <span class="secenek-harf">${String.fromCharCode(65 + i)}</span> ${esc(sec)}</div>`;
      }).join("")}
      ${p.cevapVerildi ? `
        ${s.aciklama ? `<div class="muted mt">${esc(s.aciklama)}</div>` : ""}
        <button class="btn btn-primary mt" onclick="pratikSonrakiSoru()">Sonraki ›</button>` : ""}`;
  }
}

function pratikKartAc() { PRATIK_DURUM.kartAcik = true; pratikOturumRenderEt(); }
function pratikBilgiKartiIsaretle(biliyordu) {
  if (biliyordu) PRATIK_DURUM.dogru++;
  PRATIK_DURUM.index++;
  PRATIK_DURUM.kartAcik = false;
  pratikOturumRenderEt();
}
function pratikCevapVer(secilen) {
  if (PRATIK_DURUM.cevapVerildi) return;
  const s = PRATIK_DURUM.sorular[PRATIK_DURUM.index];
  PRATIK_DURUM.verilenCevap = secilen;
  PRATIK_DURUM.cevapVerildi = true;
  if (secilen === s.dogru_cevap) PRATIK_DURUM.dogru++;
  pratikOturumRenderEt();
}
function pratikSonrakiSoru() {
  PRATIK_DURUM.index++;
  PRATIK_DURUM.cevapVerildi = false;
  PRATIK_DURUM.verilenCevap = null;
  pratikOturumRenderEt();
}
function pratikBitir() {
  const { dersId, konuId } = PRATIK_DURUM;
  PRATIK_DURUM = null;
  pratikAlaniCiz(dersId, konuId);
}
async function pratikSonucKaydet(p) {
  try {
    const kullaniciAdi = (Auth.aktif() || {}).kullaniciAdi || "misafir";
    await ppDenemeKaydet(p.setId, kullaniciAdi, p.dogru, p.sorular.length, Math.round((p.dogru / p.sorular.length) * 100));
  } catch (e) {
    console.error("Deneme kaydedilemedi", e);
  }
}

/* ---------- EK İÇERİK — ÖZET (özet sayfaları özeti + tam sayfa okuyucu) ---------- */
function konuOzetHtml(k, dersId) {
  const sayfalar = (k.ozetSayfalari || []).filter(s => Auth.yonetici() || (s.durum || "yayinda") === "yayinda");
  if (!sayfalar.length)
    return `<div class="empty"><div class="icon">📄</div>Bu konu için henüz özet eklenmedi.</div>`;
  const ilkGorselIdxRaw = (k.ozetSayfalari || []).indexOf(sayfalar[0]);
  const ilkGorsel = sayfalar.map(s => (s.gorseller || [])[0]).find(Boolean);
  return `<div class="card mb">
    <div class="row between wrap">
      <div class="row" style="gap:12px">
        ${ilkGorsel ? `<img src="${ilkGorsel.dosya}" style="width:64px;height:64px;object-fit:cover;border-radius:8px">` : ""}
        <div><h3>${sayfalar.length} sayfa</h3>
          <div class="muted">Notlar ve yüklediğiniz PDF sayfaları</div></div>
      </div>
      <button class="btn btn-primary" onclick="git('ozetoku/${dersId}/${k.id}/${Math.max(0, ilkGorselIdxRaw)}')">Çalışmaya Başla</button>
    </div>
  </div>`;
}

/* ---------- ÖZET — TAM SAYFA OKUYUCU (menü + yapay zekâ paneli, PDF okuyucuyla aynı iskelet) ---------- */
function sayfaOzetOku(dersId, konuId, idxStr) {
  const d = DERSLER.find(x => x.id === dersId);
  const k = konuBul(dersId, konuId);
  const sayfalar = k && k.ozetSayfalari || [];
  if (!d || !k || !sayfalar.length) return `<div class="empty">Sayfa bulunamadı.</div>`;
  const idx = Math.min(Math.max(0, parseInt(idxStr) || 0), sayfalar.length - 1);
  const s = sayfalar[idx];

  const ay = Ayar.oku();
  const aiGizli = ay.aiGizli;
  const listeGizli = ay.ozetMenuGizli;
  const gen = aiGizli ? 100 : ay.okumaGenisligi;

  window.__ctx = { ders: d.ad, konu: k.ad, standart: k.standart, sayfa: idx + 1, bolum: s.baslik || "", dersId, konuId };
  window.__pdfOkuyucuBaglam = null;

  const menuSatirlari = sayfalar.map((sf, i) => {
    const oniz = (sf.gorseller || [])[0];
    return `<div class="pdf-thumb ${i === idx ? "aktif" : ""}" onclick="git('ozetoku/${d.id}/${k.id}/${i}')">
      ${oniz ? `<img src="${oniz.dosya}" style="width:100%;border-radius:3px;display:block">` : `<div style="height:60px;background:#1e293b;border-radius:3px"></div>`}
      <span>${esc(sf.baslik || ("Sayfa " + (i + 1)))}</span>
    </div>`;
  }).join("");

  return `<div class="breadcrumb">
      <a onclick="git('dersler')">Dersler</a> /
      <a onclick="git('dersler/${d.id}')">${esc(d.ad)}</a> /
      <a onclick="git('dersler/${d.id}/${k.id}')">${esc(k.ad)}</a> / Özet</div>

    <div class="reader" id="reader" style="grid-template-columns:${aiGizli ? "100%" : gen + "% 10px 1fr"}">
      <div class="reader-main" id="readerMain">
        <div class="reader-toolbar">
          <button class="btn btn-sm" onclick="git('ozetoku/${d.id}/${k.id}/${idx - 1}')" ${idx <= 0 ? "disabled" : ""}>◀</button>
          <span class="muted">Sayfa ${idx + 1} / ${sayfalar.length}</span>
          <button class="btn btn-sm" onclick="git('ozetoku/${d.id}/${k.id}/${idx + 1}')" ${idx >= sayfalar.length - 1 ? "disabled" : ""}>▶</button>

          <div style="flex:1"></div>

          <button class="btn btn-sm" onclick="ozetMenuGizle()" title="Sayfa listesini göster/gizle">${listeGizli ? "📑 Listeyi Göster" : "📑 Listeyi Gizle"}</button>
          <button class="btn btn-sm" onclick="menuGizle()" title="Menüyü göster/gizle">☰</button>
          <button class="btn btn-sm" onclick="aiGizle()" title="Asistanı göster/gizle">${aiGizli ? "🤖 Göster" : "🤖 Gizle"}</button>
          <button class="btn btn-sm" onclick="tamEkran()" title="Tam ekran (F11 veya Esc ile çıkın)">⛶</button>
        </div>

        <div class="reader-pdf-alan">
          ${listeGizli ? "" : `
          <div class="pdf-thumb-rail" id="pdfThumbRail">
            <div class="row between" style="padding:6px 8px;position:sticky;top:-8px;background:#334155;margin:-8px -8px 4px;z-index:1">
              <strong style="color:#e2e8f0;font-size:12px">Sayfalar</strong>
              <button class="ikon-btn" style="color:#e2e8f0" onclick="ozetMenuGizle()" title="Bu listeyi gizle">«</button>
            </div>
            ${menuSatirlari}
          </div>`}
          <div class="pdf-canvas-wrap">
            <div class="pdf-canvas-kaydirma" style="display:block;padding:22px;overflow-y:auto">
              <div class="ozet-oku-govde">
                <h3>${esc(s.baslik || "Başlıksız")}</h3>
                ${ozetGovdeHtml(s)}
              </div>
            </div>
          </div>
        </div>
      </div>

      ${aiGizli ? "" : `
      <div class="ayirici" id="ayirici" title="Sürükleyerek genişliği ayarlayın"></div>

      <div class="chat" id="chatPanel">
        <div class="chat-head">
          <div class="row between">
            <strong>🤖 Ders Asistanı</strong>
            <div class="row" style="gap:6px">
              <span class="badge badge-gray" id="aiMode">Yerel</span>
              <button class="ikon-btn" onclick="aiGizle()" title="Gizle">✕</button>
            </div>
          </div>
          <div class="muted" style="font-size:12px">${esc(k.standart || k.ad)} · Sayfa ${idx + 1}</div>
        </div>
        <div class="chat-body" id="chatBody"></div>
        <div class="chat-quick">
          <span class="chip" onclick="hizliSor('Bu sayfayı özetle')">Özetle</span>
          <span class="chip" onclick="hizliSor('Bu konudaki sınav tuzakları neler')">Tuzaklar</span>
          <span class="chip" onclick="hizliSor('Beni test et')">Beni test et</span>
        </div>
        <div class="chat-input">
          <input id="chatInput" placeholder="Sorunuzu yazın..."
                 onkeydown="if(event.key==='Enter')mesajGonder()">
          <button class="btn btn-primary btn-sm" onclick="mesajGonder()">Gönder</button>
        </div>
      </div>`}
    </div>`;
}
function ozetMenuGizle() { Ayar.guncelle("ozetMenuGizli", !Ayar.oku().ozetMenuGizli); yonlendir(); }
/* Özet sayfasının içerik+görsel gövdesini, seçilen düzene göre üretir.
   sayfaOzetOku() (tam sayfa okuyucu) ve önizleme amaçlı başka yerlerde kullanılabilir. */
function ozetGovdeHtml(s) {
  const gorseller = s.gorseller || [];
  const duzen = s.duzen || "gorsel-ust";
  const metinHtml = `<div class="icerik">${s.icerik || ""}</div>`;
  const galeriHtml = gorseller.length ? `<div class="ozet-gorsel-galeri">${gorseller.map(g => `
    <figure><img src="${g.dosya}" alt="${esc(g.baslik || "")}">${g.baslik ? `<figcaption>${esc(g.baslik)}</figcaption>` : ""}</figure>`).join("")}</div>` : "";

  if (duzen === "metin") return metinHtml;
  if (duzen === "sadece-gorsel") return galeriHtml || `<div class="empty"><div class="icon">🌆</div>Bu düzen için henüz görsel eklenmedi.</div>`;
  if (duzen === "iki-sutun") return `<div class="ozet-iki-sutun">${metinHtml}</div>`;
  if (duzen === "gorsel-sag") return `<div class="ozet-yan-yana">${metinHtml}${galeriHtml}</div>`;
  if (duzen === "gorsel-sol") return `<div class="ozet-yan-yana">${galeriHtml}${metinHtml}</div>`;
  return `${galeriHtml}${metinHtml}`; /* gorsel-ust (varsayılan) */
}
/* ---------- EK İÇERİK — GÖRSEL GALERİSİ ---------- */
function konuGorselHtml(k) {
  if (!k.gorseller || !k.gorseller.length)
    return `<div class="empty"><div class="icon">🖼️</div>Bu konu için henüz görsel eklenmedi.</div>`;
  window.__gorselKonu = k;
  return `<div class="galeri">${k.gorseller.map(g => `
    <figure onclick="gorselModalAc('${g.dosya}', '${esc(g.baslik).replace(/'/g, "\\'")}')">
      <img src="${g.dosya}" alt="${esc(g.baslik)}"><figcaption>${esc(g.baslik || "")}</figcaption>
    </figure>`).join("")}</div>`;
}
function gorselModalAc(dosya, baslik) {
  $("#gorselModalImg").src = dosya;
  $("#gorselModalBaslik").textContent = baslik;
  $("#gorselModal").classList.add("acik");
}
function gorselModalKapat() { $("#gorselModal").classList.remove("acik"); }

/* ---------- EK İÇERİK — FOTOROMAN ---------- */
function konuFotoHtml(k) {
  if (!k.fotoromanlar || !k.fotoromanlar.length)
    return `<div class="empty"><div class="icon">🎞️</div>Bu konu için henüz fotoroman eklenmedi.</div>`;
  window.__fotoKonu = k;
  return `<div class="foto-liste">${k.fotoromanlar.map(f => `
    <div class="card foto-kart" onclick="fotoModalAc('${f.id}')">
      <div class="kapak" style="${f.kareler[0] ? `background-image:url('${f.kareler[0].gorsel}')` : ""}">${f.kareler[0] ? "" : "🎞️"}</div>
      <div class="baslik">${esc(f.baslik)}</div>
    </div>`).join("")}</div>`;
}
let FOTO_AKTIF = null, FOTO_KARE = 0;
function fotoModalAc(frId) {
  const fr = window.__fotoKonu.fotoromanlar.find(f => f.id === frId);
  if (!fr || !fr.kareler.length) return;
  FOTO_AKTIF = fr; FOTO_KARE = 0;
  fotoModalCiz();
  $("#fotoModal").classList.add("acik");
}
function fotoModalCiz() {
  const kare = FOTO_AKTIF.kareler[FOTO_KARE];
  $("#fotoModalImg").src = kare.gorsel || "";
  $("#fotoModalMetin").textContent = kare.metin || "";
  $("#fotoModalSayac").textContent = `${FOTO_KARE + 1} / ${FOTO_AKTIF.kareler.length}`;
}
function fotoModalOnceki() { FOTO_KARE = (FOTO_KARE - 1 + FOTO_AKTIF.kareler.length) % FOTO_AKTIF.kareler.length; fotoModalCiz(); }
function fotoModalSonraki() { FOTO_KARE = (FOTO_KARE + 1) % FOTO_AKTIF.kareler.length; fotoModalCiz(); }
function fotoModalKapat() { $("#fotoModal").classList.remove("acik"); }

/* ---------------------------------------------------------
   13. SAYFA — OKUYUCU
   --------------------------------------------------------- */
function sayfaOku(dersId, konuId, materyalId, sayfaStr) {
  const d = DERSLER.find(x => x.id === dersId);
  const k = konuBul(dersId, konuId);
  const m = k && (k.materyaller || []).find(x => x.id === materyalId);
  if (!d || !k || !m) return `<div class="empty">Materyal bulunamadı.</div>`;

  const ay = Ayar.oku();
  const sayfa = Math.min(Math.max(1, parseInt(sayfaStr) || 1), m.toplamSayfa);
  state.sonCalisma = { dersId, konuId, materyalId, sayfa, materyalAd: m.ad, konuAd: k.ad };
  DB.kaydet(state);

  const okundu = sayfaOkundu(m.id, sayfa);
  const p = materyalIlerleme(m);
  const bolum = (m.bolumler || []).find(b => sayfa >= b.bas && sayfa <= b.bit);

  window.__ctx = {
    ders: d.ad, konu: k.ad, standart: k.standart, materyal: m.ad,
    sayfa, bolum: bolum ? bolum.ad : "", dersId, konuId
  };
  window.__pdfOkuyucuBaglam = { materyalId: m.id, dosya: m.dosya, sayfa, toplamSayfa: m.toplamSayfa };

  const aiGizli = ay.aiGizli;
  const gen = aiGizli ? 100 : ay.okumaGenisligi;

  return `<div class="breadcrumb">
      <a onclick="git('dersler')">Dersler</a> /
      <a onclick="git('dersler/${d.id}')">${esc(d.ad)}</a> /
      <a onclick="git('dersler/${d.id}/${k.id}')">${esc(k.ad)}</a> / Okuma</div>

    <div class="reader" id="reader" style="grid-template-columns:${aiGizli ? "100%" : gen + "% 10px 1fr"}">
      <div class="reader-main" id="readerMain">
        <div class="reader-toolbar">
          <button class="btn btn-sm" onclick="sayfaGit(${sayfa - 1})" ${sayfa <= 1 ? "disabled" : ""}>◀</button>
          <input class="page-input" id="pageInput" type="number" value="${sayfa}" min="1"
                 max="${m.toplamSayfa}" onchange="sayfaGit(this.value)">
          <span class="muted">/ ${m.toplamSayfa}</span>
          <button class="btn btn-sm" onclick="sayfaGit(${sayfa + 1})" ${sayfa >= m.toplamSayfa ? "disabled" : ""}>▶</button>

          <div style="flex:1"></div>

          <button class="btn btn-sm ${okundu ? "btn-ok" : ""}" onclick="okunduDegistir('${m.id}',${sayfa})">
            ${okundu ? "✓ Okundu" : "Okundu işaretle"}</button>
          <button class="btn btn-sm" onclick="bolumuOkunduYap('${m.id}',${bolum ? bolum.bas : 1},${bolum ? bolum.bit : m.toplamSayfa})"
            title="Bu bölümün tüm sayfalarını okundu işaretler">Bölümü tamamla</button>
          <button class="btn btn-sm" onclick="menuGizle()" title="Menüyü göster/gizle">☰</button>
          <button class="btn btn-sm" onclick="aiGizle()" title="Asistanı göster/gizle">${aiGizli ? "🤖 Göster" : "🤖 Gizle"}</button>
          <button class="btn btn-sm" onclick="tamEkran()" title="Tam ekran (F11 veya Esc ile çıkın)">⛶</button>
        </div>

        <div class="reader-pdf-alan">
          <div class="pdf-thumb-rail" id="pdfThumbRail">
            <div class="muted" style="padding:10px;font-size:12px;color:#94a3b8">Sayfalar yükleniyor…</div>
          </div>
          <div class="pdf-canvas-wrap" id="pdfCanvasWrap">
            <div class="pdf-zoom-cubuk">
              <button class="btn btn-sm" onclick="pdfZoom(-0.15)">−</button>
              <span id="pdfZoomEtiket" class="muted">100%</span>
              <button class="btn btn-sm" onclick="pdfZoom(0.15)">+</button>
              <button class="btn btn-sm" onclick="pdfSigdir()" title="Sayfayı pencereye sığdır">⤢ Sığdır</button>
              <span id="pdfOkuyucuDurum" class="muted" style="margin-left:8px"></span>
            </div>
            <div class="pdf-canvas-kaydirma" id="pdfCanvasKaydirma"><canvas id="pdfCanvas"></canvas></div>
          </div>
          <iframe class="reader-frame" id="pdfFrameYedek" style="display:none"></iframe>
        </div>

        <div class="reader-toolbar" style="border-top:1px solid var(--border);border-bottom:0">
          <div style="flex:1">
            <div class="row between">
              <span class="muted">${esc(bolum ? bolum.ad : m.ad)}</span>
              <strong>${p}% · ${okunanSayfa(m.id)}/${m.toplamSayfa}</strong>
            </div>${bar(p)}
          </div>
        </div>
      </div>

      ${aiGizli ? "" : `
      <div class="ayirici" id="ayirici" title="Sürükleyerek genişliği ayarlayın"></div>

      <div class="chat" id="chatPanel">
        <div class="chat-head">
          <div class="row between">
            <strong>🤖 Ders Asistanı</strong>
            <div class="row" style="gap:6px">
              <span class="badge badge-gray" id="aiMode">Yerel</span>
              <button class="ikon-btn" onclick="aiGizle()" title="Gizle">✕</button>
            </div>
          </div>
          <div class="muted" style="font-size:12px">${esc(k.standart || k.ad)} · Sayfa ${sayfa}</div>
        </div>
        <div class="chat-body" id="chatBody"></div>
        <div class="chat-quick">
          <span class="chip" onclick="hizliSor('Bu konuyu özetle')">Özetle</span>
          <span class="chip" onclick="hizliSor('Bu konudaki sınav tuzakları neler')">Tuzaklar</span>
          <span class="chip" onclick="hizliSor('Beni test et')">Beni test et</span>
          <span class="chip" onclick="hizliSor('Yevmiye kaydını göster')">Yevmiye kaydı</span>
          <span class="chip" onclick="hizliSor('VUK ile karşılaştır')">VUK farkı</span>
        </div>
        <div class="chat-input">
          <input id="chatInput" placeholder="Sorunuzu yazın..."
                 onkeydown="if(event.key==='Enter')mesajGonder()">
          <button class="btn btn-primary btn-sm" onclick="mesajGonder()">Gönder</button>
        </div>
      </div>`}
    </div>`;
}

function sayfaGit(n) {
  const c = state.sonCalisma;
  git(`oku/${c.dersId}/${c.konuId}/${c.materyalId}/${n}`);
}
function okunduDegistir(mid, s) { sayfaIsaretle(mid, s, !sayfaOkundu(mid, s)); yonlendir(); }
function bolumuOkunduYap(mid, bas, bit) {
  for (let i = bas; i <= bit; i++) sayfaIsaretle(mid, i, true);
  yonlendir();
}
function menuGizle() { const a = Ayar.oku(); a.menuGizli = !a.menuGizli; Ayar.yaz(a); }
function aiGizle() { const a = Ayar.oku(); a.aiGizli = !a.aiGizli; Ayar.yaz(a); yonlendir(); }

/* #content'i tam ekran yapıyoruz (#readerMain'i değil) — sayfa/küçük
   resim gezinmesi #content'in innerHTML'ini yeniden yazdığı için,
   fullscreen edilen düğüm #readerMain olsaydı her gezinmede DOM'dan
   kopar ve tarayıcı otomatik tam ekrandan çıkardı. #content hiçbir
   zaman yeniden oluşturulmadığından tam ekran kesintisiz kalır. */
function tamEkran() {
  const el = document.getElementById("content");
  if (!document.fullscreenElement) {
    (el.requestFullscreen || el.webkitRequestFullscreen || (() => {})).call(el);
  } else {
    (document.exitFullscreen || document.webkitExitFullscreen || (() => {})).call(document);
  }
}

/* Sürükleyerek genişlik ayarlama */
function okuyucuBaslat() {
  if (typeof asistanBaslat === "function") asistanBaslat();
  pdfOkuyucuYukle();

  const ayr = document.getElementById("ayirici");
  const rd = document.getElementById("reader");
  if (!ayr || !rd) return;

  let suruklu = false;
  ayr.addEventListener("mousedown", e => { suruklu = true; e.preventDefault(); document.body.style.userSelect = "none"; });
  document.addEventListener("mousemove", e => {
    if (!suruklu) return;
    const r = rd.getBoundingClientRect();
    let pct = ((e.clientX - r.left) / r.width) * 100;
    pct = Math.max(30, Math.min(85, pct));
    rd.style.gridTemplateColumns = pct + "% 10px 1fr";
    Ayar.guncelle("okumaGenisligi", Math.round(pct));
  });
  document.addEventListener("mouseup", () => { suruklu = false; document.body.style.userSelect = ""; });
}

/* ---------------------------------------------------------
   13b. PDF GÖRÜNTÜLEYİCİ (PDF.js) — küçük resim şeridi + ana
   sayfa + yakınlaştırma. PDF.js yüklenemez/başarısız olursa
   tarayıcının yerleşik PDF görüntüleyicisine (iframe) düşer.
   --------------------------------------------------------- */
let PDF_OKUYUCU_ONBELLEK = { dosya: null, belge: null };
let PDF_OLCEK = 1.3; // 1.3 = "%100" tabanı; PDF_OLCEK_OTOMATIK açıkken pdfSayfaCiz tarafından hesaplanır
let PDF_OLCEK_OTOMATIK = true;
let PDF_SIGDIR_DINLEYICI_KURULDU = false;

/* Sayfayı pencereye sığdıran ölçek hesabı — kullanıcı her seferinde
   elle yakınlaştırmasın diye varsayılan davranış budur. */
function pdfSigdirOlcekHesapla(taban, kaydirmaEl) {
  const bosluk = 32; // .pdf-canvas-kaydirma padding (16px x 2)
  const availW = Math.max(80, kaydirmaEl.clientWidth - bosluk);
  const availH = Math.max(80, kaydirmaEl.clientHeight - bosluk);
  return Math.max(0.3, Math.min(4, Math.min(availW / taban.width, availH / taban.height)));
}

function pdfZoomEtiketGuncelle() {
  const etiket = document.getElementById("pdfZoomEtiket");
  if (etiket) etiket.textContent = Math.round((PDF_OLCEK / 1.3) * 100) + "%" + (PDF_OLCEK_OTOMATIK ? " · Sığdır" : "");
}

/* Kapsayıcı boyutu her değiştiğinde (pencere yeniden boyutlandırma,
   tam ekrana giriş/çıkış) otomatik sığdır modundaysa sayfayı yeniden
   çizer. Bir kere kurulur, tüm okuyucu oturumları için geçerlidir. */
function pdfSigdirDinleyicileriKur() {
  if (PDF_SIGDIR_DINLEYICI_KURULDU) return;
  PDF_SIGDIR_DINLEYICI_KURULDU = true;
  const yenidenCiz = () => {
    const baglam = window.__pdfOkuyucuBaglam;
    if (baglam && PDF_OLCEK_OTOMATIK && PDF_OKUYUCU_ONBELLEK.dosya === baglam.dosya && PDF_OKUYUCU_ONBELLEK.belge) {
      pdfSayfaCiz(PDF_OKUYUCU_ONBELLEK.belge, baglam.sayfa);
    }
  };
  document.addEventListener("fullscreenchange", () => setTimeout(yenidenCiz, 60));
  window.addEventListener("resize", () => {
    clearTimeout(window.__pdfBoyutZamanlayici);
    window.__pdfBoyutZamanlayici = setTimeout(yenidenCiz, 150);
  });
}

async function pdfOkuyucuYukle() {
  const baglam = window.__pdfOkuyucuBaglam;
  const canvas = document.getElementById("pdfCanvas");
  if (!baglam || !canvas) return;
  pdfSigdirDinleyicileriKur();

  if (typeof pdfjsLib === "undefined") { pdfYedekIframeGoster(baglam); return; }

  const durumEl = document.getElementById("pdfOkuyucuDurum");
  try {
    let belge;
    if (PDF_OKUYUCU_ONBELLEK.dosya === baglam.dosya && PDF_OKUYUCU_ONBELLEK.belge) {
      belge = PDF_OKUYUCU_ONBELLEK.belge;
    } else {
      if (durumEl) durumEl.textContent = "PDF yükleniyor…";
      const yanit = await fetch(baglam.dosya);
      const veri = await yanit.arrayBuffer();
      belge = await pdfjsLib.getDocument({ data: veri }).promise;
      PDF_OKUYUCU_ONBELLEK = { dosya: baglam.dosya, belge };
    }

    /* Materyal eklenirken "toplam sayfa sayısı" elle girilir ve yanlış/eksik
       olabilir (örn. varsayılan "1" ile bırakılmış olabilir) — bu durumda
       sayfa gezinme, gerçek sayfa sayısından daha düşük bir sınıra kilitlenip
       "1. sayfada takılı kalma" hissi yaratır. PDF gerçekten yüklendiğinde,
       gerçek sayfa sayısını öğrenip veriyi kalıcı olarak düzeltiyoruz. */
    if (belge.numPages) {
      const konu = DERSLER.flatMap(d => d.konular).find(k => (k.materyaller || []).some(m => m.id === baglam.materyalId));
      const m = konu && konu.materyaller.find(x => x.id === baglam.materyalId);
      if (m && m.toplamSayfa !== belge.numPages) {
        m.toplamSayfa = belge.numPages;
        icerikTaslakKaydet();
        yonlendir();
        return;
      }
    }

    if (durumEl) durumEl.textContent = "";
    await pdfSayfaCiz(belge, baglam.sayfa);
    /* Sayfa geçişinde DOM (dolayısıyla küçük resim şeridi) her seferinde
       yeniden oluşturulduğu için, belge önbellekte olsa bile burada tazelenir
       — ağır iş yalnızca ilk yüklemede (yukarıda) yapılır, bu yalnızca
       zaten ayrıştırılmış sayfaları küçük canvas'lara yeniden çizer. */
    pdfThumbnailleriCiz(belge);
  } catch (e) {
    console.warn("PDF.js ile görüntülenemedi, yerleşik görüntüleyiciye düşülüyor:", e.message);
    pdfYedekIframeGoster(baglam);
  }
}

async function pdfSayfaCiz(belge, sayfaNo) {
  const canvas = document.getElementById("pdfCanvas");
  const kaydirma = document.getElementById("pdfCanvasKaydirma");
  if (!canvas || window.__pdfOkuyucuBaglam?.sayfa !== sayfaNo) return;
  const sayfa = await belge.getPage(sayfaNo);
  if (PDF_OLCEK_OTOMATIK && kaydirma) {
    PDF_OLCEK = pdfSigdirOlcekHesapla(sayfa.getViewport({ scale: 1 }), kaydirma);
  }
  const viewport = sayfa.getViewport({ scale: PDF_OLCEK });
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  await sayfa.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
  pdfAktifThumbIsaretle();
  pdfZoomEtiketGuncelle();
}

/* Küçük resim önbelleği — dosya başına, sayfa numarasına göre önceden
   çizilmiş canvas'ları tutar. DÜZELTME: bu fonksiyon her sayfa
   gezinmesinde yeniden çağrılıyor (küçük resim şeridi her seferinde
   DOM'dan yeniden oluşturulduğu için), ama önbellek olmadan bu, pdf.js'in
   TÜM sayfaları YENİDEN RASTERİZE etmesi anlamına geliyordu — 50-100
   sayfalık bir PDF'te her tek sayfa geçişinde onlarca sayfanın yeniden
   çizilmesi ciddi bir yavaşlığa yol açıyordu. Artık her sayfa yalnızca
   BİR KEZ pdf.js ile çizilip küçük bir önbellek canvas'ına kopyalanıyor;
   sonraki gezinmelerde DOM'daki canvas'a ucuz bir drawImage kopyası
   yapılıyor (pdf.js'e tekrar hiç gidilmiyor). */
let PDF_KUCUK_RESIM_ONBELLEGI = { dosya: null, canvaslar: [] };

async function pdfThumbnailleriCiz(belge) {
  const rail = document.getElementById("pdfThumbRail");
  const baglam = window.__pdfOkuyucuBaglam;
  if (!rail || !baglam) return;

  if (PDF_KUCUK_RESIM_ONBELLEGI.dosya !== baglam.dosya || PDF_KUCUK_RESIM_ONBELLEGI.canvaslar.length !== belge.numPages) {
    PDF_KUCUK_RESIM_ONBELLEGI = { dosya: baglam.dosya, canvaslar: new Array(belge.numPages).fill(null) };
  }

  rail.innerHTML = "";
  for (let i = 1; i <= belge.numPages; i++) {
    if (document.getElementById("pdfThumbRail") !== rail) return; // sayfa degisti/ayrildi, birak
    const kutu = document.createElement("div");
    kutu.className = "pdf-thumb";
    kutu.dataset.sayfa = i;
    kutu.onclick = () => sayfaGit(i);
    const canvas = document.createElement("canvas");
    const etiket = document.createElement("span");
    etiket.textContent = i;
    kutu.append(canvas, etiket);
    rail.appendChild(kutu);

    const onbellekli = PDF_KUCUK_RESIM_ONBELLEGI.canvaslar[i - 1];
    if (onbellekli) {
      canvas.width = onbellekli.width;
      canvas.height = onbellekli.height;
      canvas.getContext("2d").drawImage(onbellekli, 0, 0);
      continue;
    }
    try {
      const sayfa = await belge.getPage(i);
      const viewport = sayfa.getViewport({ scale: 0.18 });
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      await sayfa.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
      const onbellekCanvas = document.createElement("canvas");
      onbellekCanvas.width = canvas.width;
      onbellekCanvas.height = canvas.height;
      onbellekCanvas.getContext("2d").drawImage(canvas, 0, 0);
      PDF_KUCUK_RESIM_ONBELLEGI.canvaslar[i - 1] = onbellekCanvas;
    } catch { /* tek bir sayfa küçük resmi başarısız olursa listeyi bozma */ }
  }
  pdfAktifThumbIsaretle();
}

function pdfAktifThumbIsaretle() {
  const baglam = window.__pdfOkuyucuBaglam;
  const aktif = document.querySelector(".pdf-thumb.aktif");
  if (aktif) aktif.classList.remove("aktif");
  if (!baglam) return;
  const el = document.querySelector(`.pdf-thumb[data-sayfa="${baglam.sayfa}"]`);
  if (el) { el.classList.add("aktif"); el.scrollIntoView({ block: "nearest" }); }
}

function pdfZoom(delta) {
  PDF_OLCEK_OTOMATIK = false;
  PDF_OLCEK = Math.max(0.3, Math.min(4, +(PDF_OLCEK + delta).toFixed(2)));
  const baglam = window.__pdfOkuyucuBaglam;
  if (baglam && PDF_OKUYUCU_ONBELLEK.dosya === baglam.dosya && PDF_OKUYUCU_ONBELLEK.belge) {
    pdfSayfaCiz(PDF_OKUYUCU_ONBELLEK.belge, baglam.sayfa);
  } else {
    pdfZoomEtiketGuncelle();
  }
}

/* Sayfayı yeniden pencereye sığdırır — manuel yakınlaştırmadan sonra
   varsayılan otomatik moda dönmek için. */
function pdfSigdir() {
  PDF_OLCEK_OTOMATIK = true;
  const baglam = window.__pdfOkuyucuBaglam;
  if (baglam && PDF_OKUYUCU_ONBELLEK.dosya === baglam.dosya && PDF_OKUYUCU_ONBELLEK.belge) {
    pdfSayfaCiz(PDF_OKUYUCU_ONBELLEK.belge, baglam.sayfa);
  }
}

function pdfYedekIframeGoster(baglam) {
  const wrap = document.getElementById("pdfCanvasWrap");
  const rail = document.getElementById("pdfThumbRail");
  const frame = document.getElementById("pdfFrameYedek");
  if (wrap) wrap.style.display = "none";
  if (rail) rail.style.display = "none";
  if (frame) { frame.style.display = ""; frame.src = `${baglam.dosya}#page=${baglam.sayfa}&view=FitH`; }
}

/* ---------------------------------------------------------
   14. SINAV EKRANI
   --------------------------------------------------------- */
let aktifSinav = null;

function sinavBaslat(dersId, konuId, liste, baslik) {
  const k = konuBul(dersId, konuId);
  const sorular = liste || soruKaristir(sinavSorulariGetir(dersId, konuId));
  if (!sorular.length) { $("#content").innerHTML = `<div class="empty">Bu konu için soru bulunamadı.</div>`; return; }

  aktifSinav = {
    dersId, konuId, konuAd: k ? k.ad : "", sorular,
    cevaplar: {}, aktif: 0, bitti: false,
    baslik: baslik || `${k ? k.ad : ""} — Tam Sınav`,
    baslangic: Date.now()
  };
  sinavCiz();
}

function hizliSinav(dersId, konuId, adet) {
  const hepsi = soruKaristir(sinavSorulariGetir(dersId, konuId));
  const k = konuBul(dersId, konuId);
  sinavBaslat(dersId, konuId, hepsi.slice(0, adet), `${k.ad} — ${adet} Soruluk Test`);
}

function bolumSinavi(dersId, konuId, bolum) {
  const hepsi = sinavSorulariGetir(dersId, konuId).filter(q => q.bolum === bolum);
  const k = konuBul(dersId, konuId);
  sinavBaslat(dersId, konuId, soruKaristir(hepsi), `${k.ad} — ${bolum}`);
}

function sinavCiz() {
  const s = aktifSinav;
  const q = s.sorular[s.aktif];
  const secili = s.cevaplar[q.id];
  const harf = ["A", "B", "C", "D"];
  const cevaplanan = Object.keys(s.cevaplar).length;

  $("#content").innerHTML = `
    <div class="breadcrumb"><a onclick="sinavCik()">← Sınavdan çık</a></div>
    <div class="page-title">${esc(s.baslik)}</div>
    <div class="page-sub">Geçme notu ${GECME_NOTU}. ${GECME_NOTU} altında kalırsanız
      bu konunun ilerlemesi sıfırlanır.</div>

    <div class="card mb">
      <div class="row between wrap">
        <strong>Soru ${s.aktif + 1} / ${s.sorular.length}</strong>
        <span class="muted">${cevaplanan} soru cevaplandı</span>
      </div>
      ${bar(Math.round(cevaplanan / s.sorular.length * 100))}
      <div class="soru-nokta-serit mt">
        ${s.sorular.map((x, i) => `<span class="soru-nokta ${i === s.aktif ? "aktif" : ""} ${s.cevaplar[x.id] !== undefined ? "dolu" : ""}"
          onclick="soruAtla(${i})">${i + 1}</span>`).join("")}
      </div>
    </div>

    <div class="card">
      <div class="badge badge-gray mb">${esc(q.bolum)}</div>
      <h3 style="font-size:17px;line-height:1.6">${esc(q.s)}</h3>
      <div class="mt">
        ${q.o.map((o, i) => `
          <div class="secenek ${secili === i ? "secili" : ""}" onclick="cevapVer(${i})">
            <span class="secenek-harf">${harf[i]}</span>
            <span>${esc(o)}</span>
          </div>`).join("")}
      </div>
    </div>

    <div class="soru-nav-alt mt">
      <button class="btn" onclick="soruGec(-1)" ${s.aktif === 0 ? "disabled" : ""}>◀ Önceki</button>
      <strong class="soru-nav-sayac">Soru ${s.aktif + 1} / ${s.sorular.length}</strong>
      ${s.aktif === s.sorular.length - 1
      ? `<button class="btn btn-primary" onclick="sinaviBitir()">Sınavı Bitir ✓</button>`
      : `<button class="btn" onclick="soruGec(1)">Sonraki ▶</button>`}
    </div>

    ${s.aktif !== s.sorular.length - 1 ? `<div class="row mt" style="justify-content:center">
      <button class="btn btn-sm" onclick="sinaviBitir()">Sınavı şimdi bitir ve sonucu gör</button></div>` : ""}`;

  /* sinavCiz() her soru geçişinde #content'i baştan yazıyor —
     yonlendir()'in sonundaki genel başlık çubuğu ekleme adımını
     atlıyor, bu yüzden burada da aynı çubuk yeniden ekleniyor. */
  if (s.dersId && s.konuId && macTamSayfaBaslikCubuguGerekli()) {
    $("#content").insertAdjacentHTML("afterbegin", macTamSayfaBaslikCubugu(s.konuAd || s.baslik, "🧠", `dersler/${s.dersId}/${s.konuId}`));
  }
}

function cevapVer(i) {
  const s = aktifSinav;
  s.cevaplar[s.sorular[s.aktif].id] = i;
  if (s.aktif < s.sorular.length - 1) setTimeout(() => { s.aktif++; sinavCiz(); }, 180);
  else sinavCiz();
}
function soruGec(y) { aktifSinav.aktif = Math.max(0, Math.min(aktifSinav.sorular.length - 1, aktifSinav.aktif + y)); sinavCiz(); }
function soruAtla(i) { aktifSinav.aktif = i; sinavCiz(); }
function sinavCik() { if (confirm("Sınavdan çıkılsın mı? Cevaplarınız kaydedilmeyecek.")) { const s = aktifSinav; aktifSinav = null; git(`dersler/${s.dersId}/${s.konuId}`); } }

function sinaviBitir() {
  const s = aktifSinav;
  const bos = s.sorular.length - Object.keys(s.cevaplar).length;
  if (bos > 0 && !confirm(`${bos} soru boş. Yine de bitirilsin mi?`)) return;

  let dogru = 0;
  s.sorular.forEach(q => { if (s.cevaplar[q.id] === q.d) dogru++; });
  const puan = Math.round(dogru / s.sorular.length * 100);
  const sure = Math.round((Date.now() - s.baslangic) / 60000);

  /* Tam sınavsa kaydet; kısa testler kayda girmez */
  const tamSinav = s.sorular.length === sinavSorulariGetir(s.dersId, s.konuId).length;
  let gecti = puan >= GECME_NOTU;
  if (tamSinav) gecti = sinavKaydet(s.konuId, puan, dogru, s.sorular.length);

  const harf = ["A", "B", "C", "D"];
  const yanlislar = s.sorular.filter(q => s.cevaplar[q.id] !== q.d);

  $("#content").innerHTML = `
    <div class="sonuc-kart ${gecti ? "basarili" : "basarisiz"}">
      <div class="sonuc-ikon">${gecti ? "🎉" : "📚"}</div>
      <h1>${gecti ? "Tebrikler, başardınız!" : "Bu konuyu yeniden çalışmanız gerekiyor"}</h1>
      <div class="sonuc-puan">${puan}</div>
      <p>${dogru} doğru · ${s.sorular.length - dogru} yanlış · ${s.sorular.length} soru · ${sure} dakika</p>
      <p class="sonuc-mesaj">${gecti
      ? `Geçme notu ${GECME_NOTU}. Bu konuyu başarıyla tamamladınız.`
      : `Geçme notu ${GECME_NOTU}. ${tamSinav
        ? "Bu konunun okuma ilerlemesi sıfırlandı ve çalışma planınız yeniden kuruldu."
        : "Bu bir deneme testiydi, ilerlemeniz etkilenmedi."}`}</p>
      <div class="row" style="justify-content:center;gap:10px;margin-top:18px">
        <button class="btn" onclick="git('dersler/${s.dersId}/${s.konuId}')">Konuya Dön</button>
        ${!gecti ? `<button class="btn btn-primary" onclick="git('plan')">Yeni Planı Gör</button>` : ""}
      </div>
    </div>

    ${yanlislar.length ? `<h2 class="section">Yanlış Cevaplar (${yanlislar.length})</h2>
      ${yanlislar.map(q => {
        const v = s.cevaplar[q.id];
        return `<div class="card mb">
          <div class="badge badge-gray mb">${esc(q.bolum)}</div>
          <h3 style="line-height:1.6">${esc(q.s)}</h3>
          <div class="mt yanlis-kutu">
            <strong>Sizin cevabınız:</strong> ${v !== undefined ? harf[v] + ") " + esc(q.o[v]) : "Boş bırakıldı"}</div>
          <div class="mt dogru-kutu">
            <strong>Doğru cevap:</strong> ${harf[q.d]}) ${esc(q.o[q.d])}</div>
          <p class="muted mt">${esc(q.aciklama)}</p>
        </div>`;
      }).join("")}` : `<div class="card mt"><h3>Tüm soruları doğru cevapladınız 👏</h3></div>`}`;

  aktifSinav = null;
}

/* ---------------------------------------------------------
   15. SAYFA — ÇALIŞMA PLANI
   --------------------------------------------------------- */
function sayfaPlan() {
  const plan = planOlustur();
  const ay = Ayar.oku();
  const secili = seciliDersler();

  if (!plan.length) {
    /* Neden plan yok? Ayrı ayrı teşhis et. */
    const materyalliKonu = secili.flatMap(d => d.konular).filter(konuHazir);
    const hepsiBitti = materyalliKonu.length > 0 && materyalliKonu.every(k => konuIlerleme(k) >= 100);

    if (hepsiBitti) {
      return `<div class="page-title">Çalışma Planı</div>
        <div class="empty"><div class="icon">🎉</div>
          <h3>Tüm konuları tamamladınız</h3>
          <p class="muted mt">Seçtiğiniz derslerdeki bütün materyalleri bitirdiniz.
          Artık soru bankası ve tekrar üzerinden çalışabilirsiniz.</p>
          <button class="btn btn-primary mt" onclick="git('dersler')">Soru Bankasına Git</button></div>`;
    }
    if (!secili.length) {
      return `<div class="page-title">Çalışma Planı</div>
        <div class="empty"><div class="icon">📋</div>
          <h3>Henüz ders seçmediniz</h3>
          <p class="muted mt">Plan oluşturmak için önce sınava gireceğiniz dersleri seçin.</p>
          <button class="btn btn-primary mt" onclick="git('uyelik')">Ders Seç</button></div>`;
    }
    if (!materyalliKonu.length) {
      return `<div class="page-title">Çalışma Planı</div>
        <div class="empty"><div class="icon">📄</div>
          <h3>Seçtiğiniz derslerde materyal yok</h3>
          <p class="muted mt">Plan, materyal sayfalarını günlere bölerek kurulur.
          PDF dosyanızı <code>materyaller/</code> klasörüne koyup <code>data.js</code>
          içinde tanımlayın.</p>
          <button class="btn btn-primary mt" onclick="git('dersler')">Derslere Git</button></div>`;
    }
    return `<div class="page-title">Çalışma Planı</div>
      <div class="empty"><div class="icon">📅</div>
        <h3>Sınav tarihi geçmiş görünüyor</h3>
        <p class="muted mt">Plan kurulabilmesi için sınav tarihinin ileri bir tarih olması gerekir.</p>
        <button class="btn btn-primary mt" onclick="git('uyelik')">Sınav Tarihini Güncelle</button></div>`;
  }

  const bugun = new Date().toISOString().slice(0, 10);
  const satirlar = plan.slice(0, 60).map(g => {
    if (g.dinlenme) return `<tr class="${g.tarih === bugun ? "bugun" : ""}">
      <td class="muted">${tarihTR(g.tarih)}<br><small>${g.gunAdi}</small></td>
      <td colspan="2" class="muted">🌤 Dinlenme günü</td><td></td></tr>`;
    if (g.tekrarDonemi) return `<tr class="${g.tarih === bugun ? "bugun" : ""}">
      <td class="muted">${tarihTR(g.tarih)}<br><small>${g.gunAdi}</small></td>
      <td colspan="2">🔁 Tekrar ve deneme dönemi</td><td></td></tr>`;
    return `<tr class="${g.tarih === bugun ? "bugun" : ""}">
      <td class="muted" style="white-space:nowrap">${tarihTR(g.tarih)}<br><small>${g.gunAdi}</small>
        ${g.tarih === bugun ? `<br><span class="badge badge-ok">Bugün</span>` : ""}</td>
      <td>${g.gorevler.map(x => `<div class="row" style="gap:6px;margin-bottom:4px">
        ${badge(x.oncelik)} <strong>${esc(x.konuAd)}</strong>
        ${x.tekrar ? `<span class="badge badge-A">Tekrar</span>` : ""}</div>`).join("")}</td>
      <td style="white-space:nowrap">${g.gorevler.map(x => `<div>${x.sayfa} sayfa</div>`).join("")}</td>
      <td style="text-align:right"><strong>${g.hedefSayfa}</strong><div class="muted" style="font-size:11px">hedef</div></td>
    </tr>`;
  }).join("");

  const calismaGunu = plan.filter(g => g.gorevler).length;
  const dinlenme = plan.filter(g => g.dinlenme).length;
  const tekrarG = plan.filter(g => g.tekrarDonemi).length;

  return `<div class="page-title">Çalışma Planı</div>
    <div class="page-sub">Seçtiğiniz ${secili.length} ders ve kalan ${kalanGun()} güne göre otomatik hesaplandı.
      Cumartesi çift tempo, Pazar dinlenme.</div>

    <div class="grid grid-4 mb">
      <div class="stat"><div class="stat-label">Sınava Kalan</div>
        <div class="stat-value">${kalanGun()}</div><div class="stat-note">gün</div></div>
      <div class="stat"><div class="stat-label">Çalışma Günü</div>
        <div class="stat-value">${calismaGunu}</div><div class="stat-note">${dinlenme} dinlenme</div></div>
      <div class="stat"><div class="stat-label">Tekrar Dönemi</div>
        <div class="stat-value">${tekrarG}</div><div class="stat-note">son gün sayısı</div></div>
      <div class="stat"><div class="stat-label">Günlük Tempo</div>
        <div class="stat-value">${gunlukTempo()}</div><div class="stat-note">sayfa/gün</div></div>
    </div>

    <div class="notice mb">
      <strong>Plan nasıl kurulur?</strong> Konular önce tekrar gerektirenler, sonra öncelik
      (A→B→C), sonra ilerlemesi az olanlar sırasıyla dizilir. Kalan sayfalar günlere bölünür.
      Son %20'lik süre tekrar ve denemeye ayrılır. Bir konu sınavından ${GECME_NOTU} altında
      puan alırsanız o konu plana yeniden ve en başa eklenir.
    </div>

    <div class="card" style="padding:0;overflow:hidden">
      <table><thead><tr><th>Tarih</th><th>Konu</th><th>Sayfa</th><th>Hedef</th></tr></thead>
      <tbody>${satirlar}</tbody></table>
    </div>

    <h2 class="section">Başvuru Takvimi</h2>
    <div class="card">
      ${SINAV.basvuru.map(b => `<div class="row between" style="padding:7px 0;border-bottom:1px solid var(--cizgi)">
        <span>${esc(b.tip)}</span><strong>${esc(b.aralik)}</strong></div>`).join("")}
      <div class="muted mt">Başvuru ${SINAV.basvuruSistemi} üzerinden yapılır.</div>
    </div>`;
}

/* ---------------------------------------------------------
   15b. SAYFA — TAKVİM (kullanıcının kendi eliyle kurduğu plan)
   Otomatik planOlustur()'un yanında, kullanıcının bir tarih aralığı
   seçip "bu aralıkta şu dersten şu konuyu bitireceğim" diye kendi
   işaretlediği, Ayar().ellePlan içinde (dolayısıyla mevcut
   kullanici_ayar sunucu senkronuyla) kalıcı olan ayrı bir katman.
   --------------------------------------------------------- */
let TAKVIM_AY = null;        // Date — gösterilen ayın 1'i (null = bu ay)
let TAKVIM_SECILI = null;    // "YYYY-MM-DD" — form açıksa hangi güne göre
let TAKVIM_DUZENLE_ID = null; // null = yeni kayıt formu, aksi hâlde düzenlenen kaydın id'si

function takvimBugununAyi() { const b = new Date(); return new Date(b.getFullYear(), b.getMonth(), 1); }
/* toISOString() UTC'ye çevirir — Türkiye gibi pozitif UTC ofsetinde
   gece yarısına yakın saatlerde günü bir gün geriye kaydırabilir.
   Yerel tarih bileşenlerinden elle biçimlendirip bu kaymayı önler. */
function takvimIso(tarih) {
  return tarih.getFullYear() + "-" + String(tarih.getMonth() + 1).padStart(2, "0") + "-" + String(tarih.getDate()).padStart(2, "0");
}
function takvimPlanListesi() { return Ayar.oku().ellePlan || []; }
function takvimGunPlani(iso) { return takvimPlanListesi().filter(p => iso >= p.baslangic && iso <= p.bitis); }

function takvimAyDegistir(delta) {
  const ay = TAKVIM_AY || takvimBugununAyi();
  TAKVIM_AY = new Date(ay.getFullYear(), ay.getMonth() + delta, 1);
  yonlendir();
}

function takvimGunSec(iso) {
  TAKVIM_SECILI = iso;
  const kapsayan = takvimGunPlani(iso)[0];
  TAKVIM_DUZENLE_ID = kapsayan ? kapsayan.id : null;
  yonlendir();
}

function takvimYeni() {
  TAKVIM_SECILI = takvimIso(new Date());
  TAKVIM_DUZENLE_ID = null;
  yonlendir();
}

function takvimDuzenle(id) {
  const p = takvimPlanListesi().find(x => x.id === id);
  if (!p) return;
  TAKVIM_SECILI = p.baslangic;
  TAKVIM_DUZENLE_ID = id;
  yonlendir();
}

function takvimFormIptal() {
  TAKVIM_SECILI = null;
  TAKVIM_DUZENLE_ID = null;
  yonlendir();
}

/* Ders <select> değiştiğinde Konu <select>'i sayfayı yeniden çizmeden
   (yonlendir() çağırmadan) günceller — aksi hâlde kullanıcının henüz
   kaydetmediği tarih alanları sıfırlanırdı. */
function takvimDersSecildi(dersId) {
  const d = DERSLER.find(x => x.id === dersId);
  const konuSec = $("#takvimKonuSec");
  if (!konuSec) return;
  konuSec.disabled = !d;
  konuSec.innerHTML = !d
    ? `<option value="">— Önce ders seçin —</option>`
    : `<option value="">— Konu seçin —</option>` +
      d.konular.map(k => `<option value="${k.id}">${esc(k.ad)}</option>`).join("");
}

function takvimKaydet() {
  const bas = $("#takvimBaslangic").value;
  const bit = $("#takvimBitis").value;
  const dersId = $("#takvimDersSec").value;
  const konuId = $("#takvimKonuSec").value;
  if (!bas || !bit) { alert("Başlangıç ve bitiş tarihini seçin."); return; }
  if (bit < bas) { alert("Bitiş tarihi başlangıç tarihinden önce olamaz."); return; }
  if (!dersId) { alert("Bir ders seçin."); return; }
  if (!konuId) { alert("Bir konu seçin."); return; }

  const ay = Ayar.oku();
  if (!ay.ellePlan) ay.ellePlan = [];
  if (TAKVIM_DUZENLE_ID) {
    const idx = ay.ellePlan.findIndex(x => x.id === TAKVIM_DUZENLE_ID);
    if (idx > -1) ay.ellePlan[idx] = { id: TAKVIM_DUZENLE_ID, baslangic: bas, bitis: bit, dersId, konuId };
  } else {
    ay.ellePlan.push({ id: iyBenzersizId("takvim"), baslangic: bas, bitis: bit, dersId, konuId });
  }
  Ayar.yaz(ay);
  TAKVIM_SECILI = null;
  TAKVIM_DUZENLE_ID = null;
  yonlendir();
}

function takvimSil(id) {
  if (!confirm("Bu plan kaydını silmek istediğinize emin misiniz?")) return;
  const ay = Ayar.oku();
  ay.ellePlan = (ay.ellePlan || []).filter(x => x.id !== id);
  Ayar.yaz(ay);
  TAKVIM_SECILI = null;
  TAKVIM_DUZENLE_ID = null;
  yonlendir();
}

function sayfaTakvim() {
  const bugunISO = takvimIso(new Date());
  const ayGoruntu = TAKVIM_AY || takvimBugununAyi();
  const yil = ayGoruntu.getFullYear(), ayNo = ayGoruntu.getMonth();
  const ayAdlari = ["Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran", "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık"];
  const ilkGun = new Date(yil, ayNo, 1);
  const sonGun = new Date(yil, ayNo + 1, 0);
  const haftaBasiOfset = (ilkGun.getDay() + 6) % 7; // Pazartesi=0 ... Pazar=6
  const secili = seciliDersler();

  const gunler = [];
  for (let i = 0; i < haftaBasiOfset; i++) gunler.push(null);
  for (let g = 1; g <= sonGun.getDate(); g++) gunler.push(new Date(yil, ayNo, g));

  const hucreler = gunler.map(tarih => {
    if (!tarih) return `<div class="takvim-gun bos"></div>`;
    const iso = takvimIso(tarih);
    const planlar = takvimGunPlani(iso);
    const etiketler = planlar.map(p => {
      const d = DERSLER.find(x => x.id === p.dersId);
      const k = d && d.konular.find(x => x.id === p.konuId);
      return `<div class="takvim-etiket" style="background:${d ? d.renk : "#64748b"}"
        title="${esc((d ? d.ad : "?") + " — " + (k ? k.ad : "?"))}">${esc(k ? k.ad : "?")}</div>`;
    }).join("");
    return `<div class="takvim-gun ${iso === bugunISO ? "bugun" : ""} ${iso === TAKVIM_SECILI ? "secili" : ""}"
      onclick="takvimGunSec('${iso}')">
      <span class="takvim-gun-no">${tarih.getDate()}</span>
      ${etiketler}
    </div>`;
  }).join("");

  const haftaBaslik = ["Pzt", "Sal", "Çar", "Per", "Cum", "Cmt", "Paz"]
    .map(g => `<div class="takvim-hafta-gun">${g}</div>`).join("");

  const duzenlenen = TAKVIM_DUZENLE_ID ? takvimPlanListesi().find(x => x.id === TAKVIM_DUZENLE_ID) : null;
  const formGoster = !!TAKVIM_SECILI;
  const formBas = duzenlenen ? duzenlenen.baslangic : TAKVIM_SECILI;
  const formBit = duzenlenen ? duzenlenen.bitis : TAKVIM_SECILI;
  const formDersId = duzenlenen ? duzenlenen.dersId : "";
  const formDers = DERSLER.find(x => x.id === formDersId);

  const formHtml = !formGoster ? "" : `
    <div class="card mb">
      <div class="row between wrap mb">
        <h3>${duzenlenen ? "Plan Kaydını Düzenle" : "Yeni Çalışma Planı Kaydı"}</h3>
        ${duzenlenen ? `<button class="btn btn-sm" style="color:#b91c1c" onclick="takvimSil('${duzenlenen.id}')">Kaydı Sil</button>` : ""}
      </div>
      ${!secili.length ? `<p class="muted">Önce Üyelik ve Ayarlar'dan sınava gireceğiniz dersleri seçin.</p>` : `
      <div class="grid grid-2">
        <div><label>Başlangıç Tarihi</label>
          <input type="date" class="genis-input" id="takvimBaslangic" value="${formBas}"></div>
        <div><label>Bitiş Tarihi</label>
          <input type="date" class="genis-input" id="takvimBitis" value="${formBit}"></div>
      </div>
      <div class="grid grid-2 mt">
        <div><label>Ders</label>
          <select class="genis-input" id="takvimDersSec" onchange="takvimDersSecildi(this.value)">
            <option value="">— Ders seçin —</option>
            ${secili.map(d => `<option value="${d.id}" ${d.id === formDersId ? "selected" : ""}>${d.ikon} ${esc(d.ad)}</option>`).join("")}
          </select></div>
        <div><label>Konu</label>
          <select class="genis-input" id="takvimKonuSec" ${!formDers ? "disabled" : ""}>
            <option value="">${formDers ? "— Konu seçin —" : "— Önce ders seçin —"}</option>
            ${formDers ? formDers.konular.map(k => `<option value="${k.id}" ${duzenlenen && duzenlenen.konuId === k.id ? "selected" : ""}>${esc(k.ad)}</option>`).join("") : ""}
          </select></div>
      </div>
      <div class="row mt" style="gap:10px">
        <button class="btn btn-primary" onclick="takvimKaydet()">Kaydet</button>
        <button class="btn btn-sm" onclick="takvimFormIptal()">Kapat</button>
      </div>`}
    </div>`;

  const tumKayitlar = [...takvimPlanListesi()].sort((a, b) => a.baslangic.localeCompare(b.baslangic));
  const listeHtml = !tumKayitlar.length ? `
    <div class="empty"><div class="icon">🗓️</div><h3>Henüz kayıtlı bir planınız yok</h3>
      <p class="muted mt">Takvimde bir güne tıklayıp ders/konu seçerek kendi çalışma planınızı oluşturun.</p></div>` : `
    <div class="card" style="padding:0;overflow:hidden">
      <table><thead><tr><th>Tarih Aralığı</th><th>Ders</th><th>Konu</th><th></th></tr></thead>
      <tbody>${tumKayitlar.map(p => {
    const d = DERSLER.find(x => x.id === p.dersId);
    const k = d && d.konular.find(x => x.id === p.konuId);
    return `<tr>
          <td style="white-space:nowrap">${tarihTR(p.baslangic)}${p.baslangic !== p.bitis ? " → " + tarihTR(p.bitis) : ""}</td>
          <td>${d ? d.ikon + " " + esc(d.ad) : `<span class="muted">(silinmiş ders)</span>`}</td>
          <td>${esc(k ? k.ad : "—")}</td>
          <td style="text-align:right"><button class="btn btn-sm" onclick="takvimDuzenle('${p.id}')">Düzenle</button></td>
        </tr>`;
  }).join("")}</tbody></table>
    </div>`;

  return `
    <div class="page-title">Takvim</div>
    <div class="page-sub">Bir tarih aralığı seçip o aralıkta hangi ders ve konuyu bitirmeniz gerektiğini
      kendiniz işaretleyin. İstediğiniz zaman değiştirebilir veya silebilirsiniz.</div>

    <div class="card mb">
      <div class="row between mb">
        <button class="btn btn-sm" onclick="takvimAyDegistir(-1)">‹ Önceki</button>
        <strong>${ayAdlari[ayNo]} ${yil}</strong>
        <button class="btn btn-sm" onclick="takvimAyDegistir(1)">Sonraki ›</button>
      </div>
      <div class="takvim-grid">
        ${haftaBaslik}
        ${hucreler}
      </div>
    </div>

    ${formHtml}

    <div class="row between wrap mb">
      <h3>Tüm Plan Kayıtlarınız</h3>
      ${!formGoster ? `<button class="btn btn-sm btn-primary" onclick="takvimYeni()">+ Yeni Kayıt</button>` : ""}
    </div>
    ${listeHtml}`;
}

/* ---------------------------------------------------------
   16. SAYFA — RAPOR
   --------------------------------------------------------- */
/* Bir dersin TÜM konularındaki TÜM sınav denemelerinin ortalama puanı —
   "en üstte tüm sınavların ortalaması dersin ortalaması olsun" isteğine
   karşılık gelir; deneme sayısı arttıkça istikrarı da yansıtır. */
function dersSinavOrtalama(d) {
  const puanlar = [];
  d.konular.forEach(k => sinavGecmisi(k.id).forEach(s => puanlar.push(s.puan)));
  return puanlar.length ? Math.round(puanlar.reduce((a, b) => a + b, 0) / puanlar.length) : null;
}

/* Hangi derslerin İlerleme Raporu'nda açık (konuları gösterilen) olduğu
   — sayfa daha sade görünsün diye varsayılan kapalı, "+" ile açılır. */
let RAPOR_ACIK_DERSLER = new Set();
function raporDersToggle(dersId) {
  if (RAPOR_ACIK_DERSLER.has(dersId)) RAPOR_ACIK_DERSLER.delete(dersId);
  else RAPOR_ACIK_DERSLER.add(dersId);
  yonlendir();
}

function sayfaRapor() {
  const secili = seciliDersler();

  const gunler = Object.entries(state.gecmis).sort().slice(-14);
  const maks = Math.max(1, ...gunler.map(g => g[1]));
  const grafik = gunler.map(([g, n]) => `<div style="flex:1;text-align:center">
    <div style="height:${n / maks * 90}px;background:var(--brand-light);border-radius:4px 4px 0 0;min-height:3px"></div>
    <div class="muted" style="font-size:10px;margin-top:4px">${g.slice(8)}.${g.slice(5, 7)}</div>
    <div style="font-size:11px;font-weight:700">${n}</div></div>`).join("");

  /* Ders → konu → her sınav denemesi ve puanı, en üstte ders ortalaması */
  const dersRaporlari = secili.map(d => {
    const acik = RAPOR_ACIK_DERSLER.has(d.id);
    const ortalama = dersSinavOrtalama(d);
    const konuBloklari = d.konular.map(k => {
      const gecmis = [...sinavGecmisi(k.id)].sort((a, b) => new Date(b.tarih) - new Date(a.tarih));
      const enIyi = enIyiPuan(k.id);
      return `<div class="rapor-konu">
        <div class="rapor-konu-baslik">
          <div><strong>${esc(k.ad)}</strong> ${badge(k.oncelik)}</div>
          <div class="muted">${konuIlerleme(k)}% okundu${enIyi !== null ? ` · En iyi ${enIyi}` : ""}</div>
        </div>
        ${!gecmis.length ? `<p class="muted mt" style="margin-bottom:0">Bu konudan henüz sınav çözülmedi.</p>` : `
        <table class="mt"><thead><tr><th>Tarih</th><th>Doğru/Toplam</th><th>Puan</th><th>Durum</th></tr></thead>
          <tbody>${gecmis.map(s => `<tr>
            <td>${tarihTR(s.tarih)}</td>
            <td>${s.dogru}/${s.toplam}</td>
            <td><strong>${s.puan}</strong></td>
            <td>${s.gecti ? `<span class="badge badge-ok">Geçti</span>` : `<span class="badge badge-A">Kaldı</span>`}</td>
          </tr>`).join("")}</tbody></table>`}
      </div>`;
    }).join("");

    return `<div class="card mb">
      <div class="row between wrap rapor-ders-baslik" onclick="raporDersToggle('${d.id}')">
        <div class="row" style="gap:10px">
          <span class="rapor-ders-toggle">${acik ? "−" : "+"}</span>
          <span style="font-size:24px">${d.ikon}</span>
          <div><h3 style="margin:0">${esc(d.ad)}</h3><div class="muted">${d.konular.length} konu</div></div>
        </div>
        <div style="text-align:right">
          <div class="stat-label">Sınav Ortalaması</div>
          <div class="stat-value" style="font-size:22px">${ortalama !== null ? ortalama : "—"}</div>
        </div>
      </div>
      ${bar(dersIlerleme(d))}
      <div class="muted mt${acik ? " mb" : ""}">Okuma ilerlemesi: ${dersIlerleme(d)}%</div>
      ${acik ? (konuBloklari || `<p class="muted">Bu derse henüz konu eklenmedi.</p>`) : ""}
    </div>`;
  }).join("");

  return `<div class="page-title">İlerleme Raporu</div>
    <div class="page-sub">Ders bazlı tamamlanma, sınav sonuçları ve çalışma temposu</div>

    <div class="grid grid-4 mb">
      <div class="stat"><div class="stat-label">Genel</div>
        <div class="stat-value">${genelIlerleme()}%</div>${bar(genelIlerleme())}</div>
      <div class="stat"><div class="stat-label">Okunan Sayfa</div>
        <div class="stat-value">${toplamOkunanSayfa()}</div></div>
      <div class="stat"><div class="stat-label">Çalışılan Gün</div>
        <div class="stat-value">${Object.keys(state.gecmis).length}</div></div>
      <div class="stat"><div class="stat-label">Çözülen Sınav</div>
        <div class="stat-value">${Object.values(state.sinavlar).reduce((s, x) => s + x.length, 0)}</div></div>
    </div>

    ${gunler.length ? `<h2 class="section">Son 14 Gün — Okunan Sayfa</h2>
      <div class="card"><div style="display:flex;gap:6px;align-items:flex-end;height:130px">${grafik}</div></div>` : ""}

    <h2 class="section">Ders Bazlı İlerleme Raporu</h2>
    ${dersRaporlari || `<div class="empty"><div class="icon">📋</div><h3>Ders seçilmedi</h3>
      <p class="muted mt">Üyelik ve Ayarlar'dan sınava gireceğiniz dersleri seçin.</p>
      <button class="btn btn-primary mt" onclick="git('uyelik')">Ders Seç</button></div>`}

    <div class="notice mt"><strong>Hesaplama:</strong> Ders ilerlemesi öncelik ağırlıklıdır.
      A grubu ${ONCELIK_AGIRLIK.A}, B grubu ${ONCELIK_AGIRLIK.B}, C grubu ${ONCELIK_AGIRLIK.C} ağırlık taşır.
      Böylece düşük öncelikli konuları bitirmek yüzdeyi yapay şekilde yükseltmez. Sınav ortalaması,
      o dersteki tüm konularda çözülen bütün denemelerin ortalama puanıdır.</div>`;
}

/* ---------------------------------------------------------
   17. SAYFA — STRATEJİ
   --------------------------------------------------------- */
function sayfaStrateji() {
  return `<div class="page-title">Sınav Stratejisi</div>
    <div class="page-sub">${SINAV.basariSarti}</div>
    <div class="grid grid-2">
      ${STRATEJI.map(s => `<div class="card"><h3>${esc(s.baslik)}</h3><p class="mt">${esc(s.metin)}</p></div>`).join("")}
    </div>
    <h2 class="section">Öncelik Lejandı</h2>
    <div class="card">${Object.entries(ONCELIK_ACIKLAMA).map(([o, a]) =>
    `<div class="row" style="padding:9px 0;border-bottom:1px solid var(--cizgi)">${badge(o)}<span>${esc(a)}</span></div>`).join("")}</div>`;
}

/* ---------------------------------------------------------
   18. SAYFA — ÜYELİK VE AYARLAR
   --------------------------------------------------------- */
function sayfaUyelik() {
  const u = Auth.aktif();
  const a = Ayar.oku();
  aiSirDurumYukle();
  const sirDurum = AI_SIR_DURUM || {};

  const dersSecim = DERSLER.filter(d => d.aktif !== false).map(d => `
    <label class="ders-secim ${a.secilenDersler.includes(d.id) ? "secili" : ""}">
      <input type="checkbox" ${a.secilenDersler.includes(d.id) ? "checked" : ""}
             onchange="dersSec('${d.id}',this.checked)">
      <span>${d.ikon}</span>
      <div class="grow"><strong>${esc(d.ad)}</strong>
        <div class="muted">${d.konular.length} konu · ${d.konular.filter(konuHazir).length} materyalli</div></div>
    </label>`).join("");

  return `<div class="page-title">Üyelik ve Ayarlar</div>
    <div class="page-sub">${esc(u.ad)} · ${u.rol === "yonetici" ? "Yönetici" : "Üye"} ·
      Üyelik tarihi ${tarihTR(u.kayitTarihi)}</div>

    <h2 class="section">🎯 Sınava Gireceğim Dersler</h2>
    <div class="card">
      <p class="muted mb">Bu dönem sınava gireceğiniz dersleri seçin. Çalışma planı,
        ilerleme yüzdesi ve günlük tempo yalnızca seçtiğiniz derslere göre hesaplanır.</p>
      ${dersSecim}
      <div class="row between mt">
        <span class="muted">${a.secilenDersler.length} ders seçili</span>
        <button class="btn btn-primary" onclick="git('plan')">Planı Yeniden Hesapla</button>
      </div>
    </div>

    <h2 class="section">📅 Sınav Tarihi</h2>
    <div class="card">
      <div class="row wrap">
        <input type="date" class="page-input" style="width:190px;text-align:left"
               id="sinavTarih" value="${a.sinavTarihi}" onchange="Ayar.guncelle('sinavTarihi',this.value);yonlendir()">
        <span class="muted">Sınava ${kalanGun()} gün kaldı</span>
      </div>
    </div>

    <h2 class="section">🎨 Görünüm</h2>
    <div class="card">
      <label class="ayar-baslik">Renk teması</label>
      ${temaSecimHtml()}

      <label class="ayar-baslik mt">Okuma alanı genişliği — <span id="genEtiket">${a.okumaGenisligi}%</span></label>
      <input type="range" min="30" max="85" value="${a.okumaGenisligi}" class="kaydirac"
             oninput="document.getElementById('genEtiket').textContent=this.value+'%'"
             onchange="Ayar.guncelle('okumaGenisligi',+this.value)">
      <div class="muted">Okuma ekranındaki ayırıcıyı sürükleyerek de değiştirebilirsiniz.</div>

      <label class="ayar-baslik mt">Yazı boyutu — <span id="yaziEtiket">${a.yaziBoyutu}px</span></label>
      <input type="range" min="13" max="20" value="${a.yaziBoyutu}" class="kaydirac"
             oninput="document.getElementById('yaziEtiket').textContent=this.value+'px'"
             onchange="Ayar.guncelle('yaziBoyutu',+this.value);temaUygula()">

      <div class="row wrap mt" style="gap:10px">
        <label class="onay"><input type="checkbox" ${a.menuGizli ? "checked" : ""}
          onchange="Ayar.guncelle('menuGizli',this.checked);temaUygula()"> Menüyü varsayılan olarak gizle</label>
        <label class="onay"><input type="checkbox" ${a.aiGizli ? "checked" : ""}
          onchange="Ayar.guncelle('aiGizli',this.checked)"> Asistanı varsayılan olarak gizle</label>
      </div>
    </div>

    <h2 class="section">🤖 Yapay Zekâ Asistanı</h2>
    <div class="card">
      <label class="ayar-baslik">Sağlayıcı</label>
      <div class="saglayici-liste">
        ${[["yerel", "Yerel", "İnternet gerekmez. Uygulamadaki bilgi tabanı."],
      ["openai", "ChatGPT", "OpenAI API anahtarınız (ChatGPT web/Plus hesabından farklıdır)."],
      ["anthropic", "Claude", "Anthropic API anahtarınız (claude.ai hesabından farklıdır)."],
      ["gemini", "Gemini", "Google AI Studio API anahtarınız."],
      ["sunucu", "Kendi Sunucum", "server/ klasöründeki proxy."]].map(([id, ad, ac]) =>
        `<div class="saglayici ${a.aiSaglayici === id ? "secili" : ""}" onclick="saglayiciSec('${id}')">
            <strong>${ad}</strong><div class="muted">${ac}</div></div>`).join("")}
      </div>

      <div id="aiAyar" class="mt">
        ${["openai", "anthropic", "gemini"].includes(a.aiSaglayici) && !ppBagliMi() ? `
          <div class="notice uyari-notice">AI anahtarları artık yalnızca sunucuda saklanır — bu özelliği
            kullanmak için önce Yönetim → Pratik Sistemi Bağlantısı'nı kurmanız gerekir.</div>
        ` : a.aiSaglayici === "openai" ? `
          ${sirDurum.openai && sirDurum.openai.tanimli ? `<div class="notice basari-notice mb" style="font-size:13px">✅ Anahtar sunucuda kayıtlı. Değiştirmek için yeni bir anahtar girip kaydedin — boş bırakırsanız mevcut anahtar aynı kalır, yalnızca model güncellenir.</div>` : ""}
          <label class="ayar-baslik">OpenAI API anahtarı</label>
          <input type="password" class="genis-input" id="oaKey" placeholder="sk-...">
          <div class="muted" style="font-size:12px">Bu, ChatGPT'ye giriş yaptığınız e-posta/şifre DEĞİLDİR —
            ayrı, ücretli-kullanım (pay-as-you-go) bir API anahtarıdır. ChatGPT Plus/Pro aboneliği API
            erişimini kapsamaz; <a href="https://platform.openai.com/settings/organization/billing"
            target="_blank" rel="noreferrer">platform.openai.com/.../billing</a> üzerinden ayrıca
            bakiye yüklemeniz gerekir.</div>
          <label class="ayar-baslik mt">Model</label>
          <input class="genis-input" id="oaModel" list="oaModelListe"
                 value="${esc((sirDurum.openai && sirDurum.openai.model) || a.openaiModel)}"
                 placeholder="gpt-4o-mini">
          <datalist id="oaModelListe">
            <option value="gpt-5.6-sol"><option value="gpt-5.6-terra"><option value="gpt-5.6-luna">
            <option value="gpt-5.5"><option value="gpt-5.1"><option value="gpt-4o-mini">
            <option value="gpt-4o"><option value="o3-mini">
          </datalist>
          <div class="muted mt" style="font-size:12px">İlk sıradakiler bu yazı itibarıyla (Ağustos 2026)
            en güncel GPT-5.6 ailesi: Sol (en güçlü/karmaşık akıl yürütme), Terra (dengeli günlük
            kullanım), Luna (ucuz/hızlı). Hesabınızın erişimi olan herhangi bir modeli (ücretli olanlar
            dahil) buraya elle de yazabilirsiniz — liste yalnızca öneridir ve zamanla eskiyebilir.
            Kutu şu anda sunucuda kayıtlı olan gerçek modeli gösterir.</div>
          <button class="btn btn-primary mt" onclick="aiKaydet('openai')">Kaydet</button>
          <div class="muted mt">Anahtarınızı <a href="https://platform.openai.com/api-keys"
            target="_blank" rel="noreferrer">platform.openai.com/api-keys</a> adresinden alabilirsiniz.</div>
        ` : a.aiSaglayici === "anthropic" ? `
          ${sirDurum.anthropic && sirDurum.anthropic.tanimli ? `<div class="notice basari-notice mb" style="font-size:13px">✅ Anahtar sunucuda kayıtlı. Değiştirmek için yeni bir anahtar girip kaydedin — boş bırakırsanız mevcut anahtar aynı kalır, yalnızca model güncellenir.</div>` : ""}
          <label class="ayar-baslik">Anthropic API anahtarı</label>
          <input type="password" class="genis-input" id="anKey" placeholder="sk-ant-...">
          <div class="muted" style="font-size:12px">Bu, claude.ai'a giriş yaptığınız hesap DEĞİLDİR —
            ayrı, ücretli-kullanım bir API anahtarıdır. Claude Pro aboneliği API erişimini kapsamaz;
            <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noreferrer">
            console.anthropic.com/.../billing</a> üzerinden ayrıca bakiye yüklemeniz gerekir.</div>
          <label class="ayar-baslik mt">Model</label>
          <input class="genis-input" id="anModel" list="anModelListe"
                 value="${esc((sirDurum.anthropic && sirDurum.anthropic.model) || a.anthropicModel)}"
                 placeholder="claude-sonnet-5">
          <datalist id="anModelListe">
            <option value="claude-opus-5"><option value="claude-sonnet-5"><option value="claude-haiku-4-5-20251001">
            <option value="claude-fable-5"><option value="claude-opus-4-8">
          </datalist>
          <div class="muted mt" style="font-size:12px">İlk sıradakiler bu yazı itibarıyla (Ağustos 2026)
            en güncel modeller. Hesabınızın erişimi olan herhangi bir modeli (ücretli olanlar dahil)
            buraya elle de yazabilirsiniz — liste yalnızca öneridir ve zamanla eskiyebilir. Kutu şu anda
            sunucuda kayıtlı olan gerçek modeli gösterir.</div>
          <button class="btn btn-primary mt" onclick="aiKaydet('anthropic')">Kaydet</button>
          <div class="muted mt">Anahtarınızı <a href="https://console.anthropic.com/settings/keys"
            target="_blank" rel="noreferrer">console.anthropic.com</a> adresinden alabilirsiniz.</div>
        ` : a.aiSaglayici === "gemini" ? `
          ${sirDurum.gemini && sirDurum.gemini.tanimli ? `<div class="notice basari-notice mb" style="font-size:13px">✅ Anahtar sunucuda kayıtlı. Değiştirmek için yeni bir anahtar girip kaydedin — boş bırakırsanız mevcut anahtar aynı kalır, yalnızca model güncellenir.</div>` : ""}
          <label class="ayar-baslik">Google AI Studio API anahtarı</label>
          <input type="password" class="genis-input" id="gmKey" placeholder="AIza...">
          <div class="muted" style="font-size:12px">Bu, Google hesap şifreniz DEĞİLDİR — Google AI
            Studio'dan alınan ayrı bir API anahtarıdır.</div>
          <label class="ayar-baslik mt">Model</label>
          <input class="genis-input" id="gmModel" list="gmModelListe"
                 value="${esc((sirDurum.gemini && sirDurum.gemini.model) || a.geminiModel)}"
                 placeholder="gemini-3.1-flash-lite">
          <datalist id="gmModelListe">
            <option value="gemini-3.1-pro-preview"><option value="gemini-3.7-flash"><option value="gemini-3.5-flash">
            <option value="gemini-3.1-flash-lite">
          </datalist>
          <div class="muted mt" style="font-size:12px">İlk sıradakiler bu yazı itibarıyla (Ağustos 2026)
            en güncel modeller (gemini-2.0-flash artık desteklenmiyor). Hesabınızın erişimi olan
            herhangi bir modeli buraya elle de yazabilirsiniz — liste yalnızca öneridir ve zamanla
            eskiyebilir. Kutu şu anda sunucuda kayıtlı olan gerçek modeli gösterir.</div>
          <button class="btn btn-primary mt" onclick="aiKaydet('gemini')">Kaydet</button>
          <div class="muted mt">Anahtarınızı <a href="https://aistudio.google.com/apikey"
            target="_blank" rel="noreferrer">aistudio.google.com/apikey</a> adresinden alabilirsiniz.</div>
        ` : a.aiSaglayici === "sunucu" ? `
          <label class="ayar-baslik">Sunucu adresi</label>
          <input class="genis-input" id="svAdres" value="${esc(a.sunucuAdres)}"
                 placeholder="http://localhost:3001/api/sor">
          <button class="btn btn-primary mt" onclick="aiKaydet('sunucu')">Kaydet</button>
          <div class="muted mt">server/ klasöründeki sunucuyu çalıştırın. Anahtarınız sunucuda kalır.</div>
        ` : `<div class="muted">Yerel mod seçili. İnternet bağlantısı ve API anahtarı gerekmez.
             Uygulamadaki bilgi tabanından cevap verir.</div>`}
      </div>

      ${(a.aiSaglayici === "openai" || a.aiSaglayici === "anthropic" || a.aiSaglayici === "gemini") && ppBagliMi() ? `
      <div class="notice basari-notice mt" style="font-size:13px">
        🔒 API anahtarınız yalnızca sunucuda saklanır — bu tarayıcıda, başka bir cihazda veya sayfa
        kaynağında görüntülenmez. Tüm istekler sunucu üzerinden (proxy) yapılır.
      </div>` : ""}
    </div>

    <h2 class="section">🔐 Hesap</h2>
    <div class="card">
      <label class="ayar-baslik">Şifre değiştir</label>
      <input type="password" class="genis-input" id="eskiSifre" placeholder="Mevcut şifre">
      <input type="password" class="genis-input mt" id="yeniSifre" placeholder="Yeni şifre (en az 6 karakter)">
      <input type="password" class="genis-input mt" id="yeniSifre2" placeholder="Yeni şifre tekrar">
      <div id="sifreHata" class="hata"></div>
      <button class="btn btn-primary mt" onclick="sifreGuncelle()">Şifreyi Güncelle</button>
    </div>

    <h2 class="section">💾 Veri Yönetimi</h2>
    <div class="card">
      <p class="muted">İlerlemeniz bu tarayıcıda saklanır. Tarayıcı verilerini temizlerseniz silinir.</p>
      <div class="row wrap mt">
        <button class="btn" onclick="veriDisaAktar()">📥 Yedek İndir</button>
        <button class="btn" onclick="document.getElementById('importFile').click()">📤 Yedek Yükle</button>
        <button class="btn tehlike" onclick="veriSifirla()">🗑 İlerlemeyi Sıfırla</button>
        <input type="file" id="importFile" accept=".json" style="display:none" onchange="veriIceAktar(this)">
      </div>
      <div class="muted mt">${toplamOkunanSayfa()} sayfa okundu ·
        ${Object.keys(state.gecmis).length} gün çalışıldı ·
        ${Object.values(state.sinavlar).reduce((s, x) => s + x.length, 0)} sınav çözüldü</div>
    </div>`;
}

/* Bir preset seçildiğinde önceki özel renk ayarları temizlenir — preset
   olduğu gibi uygulanır. Yazı tipi tercihi (fontOzel) preset'ten bağımsız
   kalır, korunur. */
function temaPresetUygula(id) {
  const a = Ayar.oku();
  a.tema = id;
  a.vurguOzel = null; a.vurguAcikOzel = null; a.menuArkaPlanOzel = null; a.sayfaArkaPlanOzel = null;
  /* macOS teması seçilince masaüstü modu (Dock + pencereler) doğrudan
     devreye girer — ayrıca işaretlemeye gerek yok. Görünüm panelindeki
     onay kutusu yine de kalır, isterlerse yalnızca renk temasını
     kullanmak için kapatabilirler. */
  if (id === "macos") a.macMasaustu = true;
  Ayar.yaz(a);
  yonlendir();
}

function temaOzellestirmeSifirla() {
  const a = Ayar.oku();
  a.vurguOzel = null; a.vurguAcikOzel = null; a.menuArkaPlanOzel = null; a.sayfaArkaPlanOzel = null; a.fontOzel = null;
  Ayar.yaz(a);
  yonlendir();
}

/* Görünüm paneli — hem Üyelik ve Ayarlar'da hem Yönetim panelinde
   (Dashboard) aynı biçimde kullanılır: preset temalar, üzerine renk
   özelleştirmesi (input[type=color], anında canlı önizleme — her
   değişiklik doğrudan Ayar.guncelle üzerinden temaUygula()'yı tetikler)
   ve yazı tipi seçimi. */
function temaSecimHtml() {
  const a = Ayar.oku();
  const t = TEMALAR[a.tema] || TEMALAR["lacivert"];
  const efektif = {
    vurgu: a.vurguOzel || t.brand,
    vurguAcik: a.vurguAcikOzel || t.light,
    menuArkaPlan: a.menuArkaPlanOzel || "#ffffff",
    sayfaArkaPlan: a.sayfaArkaPlanOzel || t.bg
  };
  const renkOzellestirildi = !!(a.vurguOzel || a.vurguAcikOzel || a.menuArkaPlanOzel || a.sayfaArkaPlanOzel);
  const herhangiOzellestirme = renkOzellestirildi || !!a.fontOzel;

  const kutular = Object.entries(TEMALAR).map(([id, tm]) =>
    `<div class="tema-kutu ${a.tema === id && !renkOzellestirildi ? "secili" : ""}" onclick="temaPresetUygula('${id}')">
      <div class="tema-onizleme"><span style="background:${tm.brand}"></span><span style="background:${tm.light}"></span><span style="background:${tm.bg}"></span></div>
      <div>${tm.ad}</div></div>`).join("");

  const fontKutular = Object.entries(FONT_SECENEKLERI).map(([id, f]) =>
    `<div class="tema-kutu font-kutu ${(a.fontOzel || "sistem") === id ? "secili" : ""}"
         style="font-family:${f.stack}" onclick="Ayar.guncelle('fontOzel','${id}');yonlendir()">
      <div style="font-size:17px;font-weight:700">Aa</div>
      <div>${f.ad}</div></div>`).join("");

  return `
    <label class="ayar-baslik">Hazır temalar</label>
    <div class="tema-liste">${kutular}</div>
    ${herhangiOzellestirme ? `<div class="muted mt" style="font-size:12px">
      Bu temanın üzerine özel renk/yazı tipi uygulanmış.
      <a onclick="temaOzellestirmeSifirla()" style="cursor:pointer">Özelleştirmeyi sıfırla</a></div>` : ""}

    <label class="ayar-baslik mt">Renkleri özelleştir</label>
    <div class="ozel-renk-izgara">
      <label class="ozel-renk-satir"><input type="color" value="${efektif.vurgu}"
        oninput="document.documentElement.style.setProperty('--brand',this.value)"
        onchange="Ayar.guncelle('vurguOzel',this.value)"><span>Vurgu rengi</span></label>
      <label class="ozel-renk-satir"><input type="color" value="${efektif.vurguAcik}"
        oninput="document.documentElement.style.setProperty('--brand-light',this.value)"
        onchange="Ayar.guncelle('vurguAcikOzel',this.value)"><span>Vurgu (açık)</span></label>
      <label class="ozel-renk-satir"><input type="color" value="${efektif.menuArkaPlan}"
        oninput="document.documentElement.style.setProperty('--menu-bg',this.value)"
        onchange="Ayar.guncelle('menuArkaPlanOzel',this.value)"><span>Menü arka planı</span></label>
      <label class="ozel-renk-satir"><input type="color" value="${efektif.sayfaArkaPlan}"
        oninput="document.documentElement.style.setProperty('--bg',this.value)"
        onchange="Ayar.guncelle('sayfaArkaPlanOzel',this.value)"><span>Sayfa arka planı</span></label>
    </div>

    <label class="ayar-baslik mt">Yazı tipi</label>
    <div class="tema-liste">${fontKutular}</div>

    ${a.tema === "macos" && !renkOzellestirildi ? `
    <label class="ayar-baslik mt">macOS masaüstü modu</label>
    <label class="row mt" style="gap:8px">
      <input type="checkbox" ${a.macMasaustu ? "checked" : ""} onchange="Ayar.guncelle('macMasaustu',this.checked);yonlendir()">
      Kenar çubuğu yerine alt Dock'u, "Dersler" için üzerine gelince açılan pencereleri kullan
    </label>
    <div class="muted" style="font-size:12px;margin-top:4px">Kapalıyken macOS teması yalnızca görünümü değiştirir, menü yapısı aynı kalır.</div>
    ${a.macMasaustu ? `<label class="row mt" style="gap:8px">
      <input type="checkbox" ${a.macDockOtomatikGizle ? "checked" : ""} onchange="Ayar.guncelle('macDockOtomatikGizle',this.checked);yonlendir()">
      Dock'u otomatik gizle (yalnızca imleç üzerine gelince göster)
    </label>
    <div class="muted" style="font-size:12px;margin-top:4px">Kapalıyken Dock ekranın altında her zaman görünür durur.</div>
    <label class="ayar-baslik mt">Ana Sayfa duvar kağıdı</label>
    <div class="tema-liste">${Object.entries(MAC_DUVAR_KAGITLARI).map(([id, dk]) =>
      `<div class="tema-kutu ${a.macDuvarKagidi === id ? "secili" : ""}" onclick="Ayar.guncelle('macDuvarKagidi','${id}');yonlendir()">
        <div class="tema-onizleme" style="background:url('${dk.dosya}') center/cover no-repeat;height:44px;border-radius:6px"></div>
        <div>${dk.ad}</div></div>`).join("")}</div>` : ""}` : ""}`;
}
function dersSec(id, sec) {
  const a = Ayar.oku();
  a.secilenDersler = sec ? [...new Set([...a.secilenDersler, id])] : a.secilenDersler.filter(x => x !== id);
  Ayar.yaz(a); yonlendir();
}
function saglayiciSec(id) { Ayar.guncelle("aiSaglayici", id); yonlendir(); }

const AI_ALAN_ESLESTIRME = {
  openai: { keyId: "oaKey", modelId: "oaModel", varsayilanModel: "gpt-4o-mini" },
  anthropic: { keyId: "anKey", modelId: "anModel", varsayilanModel: "claude-sonnet-5" },
  gemini: { keyId: "gmKey", modelId: "gmModel", varsayilanModel: "gemini-3.1-flash-lite" }
};

async function aiKaydet(tip) {
  const a = Ayar.oku();
  if (tip === "sunucu") { a.sunucuAdres = $("#svAdres").value.trim(); Ayar.yaz(a); yonlendir(); return; }

  const alan = AI_ALAN_ESLESTIRME[tip];
  if (!alan) { Ayar.yaz(a); yonlendir(); return; }

  const u = Auth.aktif();
  if (!ppBagliMi() || !u) {
    alert("Bu özellik için önce Yönetim → Pratik Sistemi Bağlantısı'nı kurmanız gerekir.");
    return;
  }

  const anahtar = $("#" + alan.keyId).value.trim();
  const model = $("#" + alan.modelId).value.trim() || alan.varsayilanModel;
  a[tip + "Model"] = model;
  Ayar.yaz(a);

  const anahtarZatenTanimli = !!(AI_SIR_DURUM && AI_SIR_DURUM[tip] && AI_SIR_DURUM[tip].tanimli);

  try {
    if (anahtar) {
      /* Yeni/değişen anahtar + model birlikte kaydedilir. */
      await kuSirKaydet(u.kullaniciAdi, tip, anahtar, model);
    } else if (anahtarZatenTanimli) {
      /* Anahtar alanı boş bırakıldı — mevcut anahtara dokunmadan yalnızca
         modeli güncelle. Önceden bu dal hiç sunucuya istek atmıyordu, bu
         yüzden model seçimi arayüzde değişmiş gibi görünüp sunucuda hiç
         güncellenmiyordu (AI hep ilk kaydedilen modeli kullanmaya devam
         ediyordu). */
      await kuSirModelGuncelle(u.kullaniciAdi, tip, model);
    } else {
      alert("Modeli kaydetmek için önce bir API anahtarı girmeniz gerekir.");
      return;
    }
    AI_SIR_DURUM = null; // yeniden yüklensin, tanımlı rozeti + gerçek model güncel gelsin
  } catch (e) {
    alert("Kaydedilemedi: " + e.message);
    return;
  }
  yonlendir();
}

async function sifreGuncelle() {
  const u = Auth.aktif();
  const s1 = $("#yeniSifre").value, s2 = $("#yeniSifre2").value;
  if (s1 !== s2) { $("#sifreHata").textContent = "Yeni şifreler eşleşmiyor."; return; }
  const r = await Auth.sifreDegistir(u.kullaniciAdi, $("#eskiSifre").value, s1);
  $("#sifreHata").textContent = r.ok ? "" : r.mesaj;
  if (r.ok) { alert(r.mesaj); yonlendir(); }
}

function veriDisaAktar() {
  const u = Auth.aktif();
  const paket = { veri: state, ayar: Ayar.oku(), kullanici: u.kullaniciAdi, tarih: new Date().toISOString() };
  const b = new Blob([JSON.stringify(paket, null, 2)], { type: "application/json" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(b);
  a.download = `ymm-yedek-${u.kullaniciAdi}-${new Date().toISOString().slice(0, 10)}.json`;
  a.click();
}

function veriIceAktar(inp) {
  const f = inp.files[0]; if (!f) return;
  const r = new FileReader();
  r.onload = e => {
    try {
      const p = JSON.parse(e.target.result);
      if (p.veri) { state = p.veri; DB.kaydet(state); }
      if (p.ayar) Ayar.yaz(p.ayar);
      alert("Yedek yüklendi."); yonlendir();
    } catch { alert("Dosya okunamadı."); }
  };
  r.readAsText(f);
}

function veriSifirla() {
  if (!confirm("Tüm ilerleme ve sınav kayıtlarınız silinecek. Emin misiniz?")) return;
  DB.sifirla(); state = DB.bos(); yonlendir();
}

/* ---------------------------------------------------------
   19. SAYFA — YÖNETİM (yalnızca yönetici)
   --------------------------------------------------------- */
function sayfaYonetim(sekme, dersId, konuId) {
  if (!Auth.yonetici()) return `<div class="empty"><div class="icon">🔒</div>
    <h3>Bu sayfaya erişim yetkiniz yok</h3></div>`;
  sekme = sekme || "dashboard";

  const sekmeler = [
    ["dashboard", "Dashboard"], ["dersler", "Dersler"], ["konular", "Konular"],
    ["icerik", "İçerik Yönetimi"], ["sayfaekle", "Özet Sayfaları"], ["sorular", "Soru Bankası"],
    ["materyaller", "PDF / Materyaller"], ["medya", "Medya Kütüphanesi"], ["uyeler", "Üyeler"],
    ["forum", "Forum"], ["menu", "Menü Yönetimi"], ["sistem", "Yayınlama / Sistem"]
  ];
  const govde = sekme === "dersler" ? yonetimDerslerHtml()
    : sekme === "konular" ? yonetimKonularHtml(dersId)
    : sekme === "icerik" ? (konuId ? yonetimIcerikHtml(dersId, konuId) : yonetimIcerikListesiHtml(dersId))
    : sekme === "sayfaekle" ? sayfaEkleHtml(dersId, konuId)
    : sekme === "sorular" ? yonetimSoruBankasiHtml(dersId, konuId)
    : sekme === "materyaller" ? yonetimMateryallerHtml(dersId)
    : sekme === "medya" ? yonetimMedyaHtml(dersId)
    : sekme === "uyeler" ? yonetimUyelerHtml()
    : sekme === "forum" ? yonetimForumHtml()
    : sekme === "menu" ? yonetimMenuHtml(dersId)
    : sekme === "sistem" ? yonetimSistemHtml()
    : yonetimDashboardHtml();

  return `<div class="page-title">Yönetim Paneli</div>
    <div class="page-sub">Dersler, içerikler, üyeler ve yayınlama — tek yerden</div>

    <div class="yonetim-nav mb">
      ${sekmeler.map(([id, ad]) => `<button class="sekme-btn ${sekme === id ? "aktif" : ""}" onclick="git('yonetim/${id}')">${ad}</button>`).join("")}
    </div>

    ${!ppBagliMi() ? `<div class="notice uyari-notice mb">⚠️ Sunucu bağlantısı kurulu değil —
        buradaki değişiklikler yalnızca bu tarayıcıda kalır, sunucuya yazılmaz ve sayfayı başka bir
        cihazda/tarayıcıda açtığınızda görünmez. <a onclick="git('yonetim/sistem')"
        style="cursor:pointer">Sunucu Bağlantısı'nı buradan kurun</a>.</div>`
      : ICERIK_SON_SENKRON_HATASI ? `<div class="notice hata-notice mb">⚠️ Son değişiklik sunucuya
        kaydedilemedi: ${esc(ICERIK_SON_SENKRON_HATASI)} — değişiklik bu tarayıcıda taslak olarak
        duruyor, ancak sunucudaki dosyaya henüz yazılmadı. İnternet bağlantınızı kontrol edip
        tekrar deneyin.</div>` : ""}

    ${govde}`;
}

/* ---------------------------------------------------------
   19a. YÖNETİM — DASHBOARD
   --------------------------------------------------------- */
function yonetimDashboardHtml() {
  const toplamKonu = DERSLER.reduce((s, d) => s + d.konular.length, 0);
  const toplamMateryal = DERSLER.reduce((s, d) => s + d.konular.reduce((t, k) => t + (k.materyaller || []).length, 0), 0);
  const toplamOzet = DERSLER.reduce((s, d) => s + d.konular.reduce((t, k) => t + (k.ozetSayfalari || []).length, 0), 0);
  const toplamGorsel = DERSLER.reduce((s, d) => s + d.konular.reduce((t, k) => t + (k.gorseller || []).length, 0), 0);
  const toplamFoto = DERSLER.reduce((s, d) => s + d.konular.reduce((t, k) => t + (k.fotoromanlar || []).length, 0), 0);
  const toplamSoru = Object.values(SORULAR).reduce((s, konular) => s + Object.values(konular).reduce((t, arr) => t + arr.length, 0), 0);
  if (YONETIM_UYELER_LISTE === null && ppBagliMi()) setTimeout(uyelerYukle, 0);
  const toplamUye = YONETIM_UYELER_LISTE ? YONETIM_UYELER_LISTE.length : "…";
  const pasifSayisi = DERSLER.filter(d => d.aktif === false).length;

  return `
    ${!ppBagliMi()
      ? `<div class="notice uyari-notice mb">
          <strong>Sunucu bağlantısı kurulu değil.</strong> Değişiklikleriniz yalnızca bu tarayıcıda saklanıyor;
          sunucuya kaydedilmesi (ve dosya yükleyebilmeniz) için <a onclick="git('yonetim/sistem')"
          style="cursor:pointer">Yayınlama / Sistem</a> sekmesinden bağlanın.
        </div>`
      : ICERIK_TASLAK_VAR
      ? `<div class="notice uyari-notice mb"><strong>Kaydediliyor…</strong> (son yerel kayıt: ${icerikTaslakZamani() || "—"})</div>`
      : `<div class="notice basari-notice mb">Tüm değişiklikleriniz sunucuya kaydedildi.</div>`}

    <div class="grid grid-4 mb">
      <div class="stat"><div class="stat-label">Ders</div><div class="stat-value">${DERSLER.length}</div>
        ${pasifSayisi ? `<div class="stat-note">${pasifSayisi} pasif</div>` : ""}</div>
      <div class="stat"><div class="stat-label">Konu</div><div class="stat-value">${toplamKonu}</div></div>
      <div class="stat"><div class="stat-label">İçerik</div><div class="stat-value">${toplamMateryal + toplamOzet + toplamGorsel + toplamFoto}</div></div>
      <div class="stat"><div class="stat-label">Özet Sayfası</div><div class="stat-value">${toplamOzet}</div></div>
    </div>
    <div class="grid grid-4 mb">
      <div class="stat"><div class="stat-label">Soru</div><div class="stat-value">${toplamSoru}</div></div>
      <div class="stat"><div class="stat-label">PDF</div><div class="stat-value">${toplamMateryal}</div></div>
      <div class="stat"><div class="stat-label">Görsel</div><div class="stat-value">${toplamGorsel}</div></div>
      <div class="stat"><div class="stat-label">Kullanıcı</div><div class="stat-value">${toplamUye}</div></div>
    </div>

    <div class="card mb">
      <h3 class="mb">Hızlı İşlemler</h3>
      <div class="row wrap" style="gap:8px">
        <button class="btn" onclick="yonetimHizliDersEkle()">Yeni Ders</button>
        <button class="btn" onclick="yonetimHizliKonuEkle()">Yeni Konu</button>
        <button class="btn" onclick="git('yonetim/sayfaekle')">Yeni İçerik</button>
        <button class="btn btn-primary" onclick="git('yonetim/sayfaekle')">Yeni Özet</button>
        <button class="btn" onclick="yonetimHizliSoruEkle()">Yeni Soru</button>
        <button class="btn" onclick="yonetimHizliPdfYukle()">PDF Yükle</button>
      </div>
    </div>

    <div class="card mb">
      <h3 class="mb">🎨 Görünüm — Renk Teması</h3>
      <p class="muted mb">Menü ve vurgu renklerini değiştirir. Aynı seçim Üyelik ve Ayarlar'da da görünür —
        her kullanıcı kendi temasını buradan veya oradan seçebilir.</p>
      ${temaSecimHtml()}
    </div>`;
}

function yonetimHizliDersEkle() { YONETIM_DERS_YENI_ACIK = true; git("yonetim/dersler"); }
function yonetimHizliKonuEkle() { git("yonetim/konular" + (DERSLER[0] ? "/" + DERSLER[0].id : "")); }
function yonetimHizliSoruEkle() {
  const d = DERSLER[0]; const k = d && d.konular[0];
  if (d && k) git(`yonetim/icerik/${d.id}/${k.id}#soruHazirla`);
  else git("yonetim/sorular");
}
function yonetimHizliPdfYukle() {
  const d = DERSLER[0]; const k = d && d.konular[0];
  if (d && k) git(`yonetim/icerik/${d.id}/${k.id}`);
  else git("yonetim/materyaller");
}

/* ---------------------------------------------------------
   19a2. YÖNETİM — DERSLER (ekle/düzenle/sil/pasif/sürükle-bırak)
   --------------------------------------------------------- */
let YONETIM_DERS_DUZENLE = null;
let YONETIM_DERS_YENI_ACIK = false;
let YONETIM_SURUKLENEN_DERS = null;

function dersSurukleBasla(e, id) { YONETIM_SURUKLENEN_DERS = id; e.dataTransfer.effectAllowed = "move"; }
function dersSurukleBirak(e, hedefId) {
  e.preventDefault();
  if (!YONETIM_SURUKLENEN_DERS || YONETIM_SURUKLENEN_DERS === hedefId) return;
  const kaynakIdx = DERSLER.findIndex(d => d.id === YONETIM_SURUKLENEN_DERS);
  const hedefIdx = DERSLER.findIndex(d => d.id === hedefId);
  if (kaynakIdx === -1 || hedefIdx === -1) return;
  const [tasinan] = DERSLER.splice(kaynakIdx, 1);
  DERSLER.splice(hedefIdx, 0, tasinan);
  YONETIM_SURUKLENEN_DERS = null;
  icerikTaslakKaydet();
  yonlendir();
}

function yonetimDerslerHtml() {
  const satirlar = DERSLER.map(d => `
    <div class="icerik-satir" draggable="true"
         ondragstart="dersSurukleBasla(event,'${d.id}')"
         ondragover="event.preventDefault()"
         ondrop="dersSurukleBirak(event,'${d.id}')"
         style="${d.aktif === false ? "opacity:.55" : ""}">
      <span style="cursor:grab;font-size:16px" title="Sürükleyerek sırala">⠿</span>
      <span style="font-size:20px">${d.ikon || "📘"}</span>
      <div class="grow">
        <strong>${esc(d.ad)}</strong> ${d.aktif === false ? `<span class="badge badge-gray">Pasif</span>` : ""}
        <div class="muted">${d.konular.length} konu · Hedef ${d.hedefNot}+</div>
      </div>
      <button class="btn btn-sm" onclick="dersDuzenleAcGonder('${d.id}')">Düzenle</button>
      <button class="btn btn-sm tehlike" onclick="dersSilGonder('${d.id}')">Sil</button>
    </div>
    ${YONETIM_DERS_DUZENLE === d.id ? dersDuzenleForm(d) : ""}`).join("");

  return `
    <div class="card mb">
      ${YONETIM_DERS_YENI_ACIK ? dersYeniForm() : `<button class="btn btn-primary" onclick="dersYeniAcGonder()">+ Yeni Ders Ekle</button>`}
    </div>
    <h2 class="section">Dersler (${DERSLER.length}) — sürükleyerek sıralayın</h2>
    ${satirlar || `<div class="empty"><div class="icon">📚</div>Henüz ders eklenmedi.</div>`}`;
}

function dersYeniForm() {
  return `
    <label class="ayar-baslik">Ders adı</label>
    <input class="genis-input" id="dersYeniAd" placeholder="Örn. Uluslararası Vergi Hukuku">
    <div class="row mt wrap">
      <div><label class="ayar-baslik">İkon</label><input class="genis-input" id="dersYeniIkon" value="📘" style="max-width:80px"></div>
      <div><label class="ayar-baslik">Renk</label><input type="color" id="dersYeniRenk" value="#2563eb" style="height:42px;width:70px"></div>
      <div><label class="ayar-baslik">Hedef puan</label><input class="genis-input" id="dersYeniHedef" type="number" value="65" style="max-width:100px"></div>
    </div>
    <label class="ayar-baslik mt">Açıklama</label>
    <textarea class="genis-input" id="dersYeniAciklama" style="width:100%"></textarea>
    <div class="row mt">
      <button class="btn btn-primary" onclick="dersEkleGonder()">Kaydet</button>
      <button class="btn" onclick="dersYeniKapatGonder()">Vazgeç</button>
    </div>
    <div class="hata" id="dersYeniHata"></div>`;
}
function dersYeniAcGonder() { YONETIM_DERS_YENI_ACIK = true; YONETIM_DERS_DUZENLE = null; yonlendir(); }
function dersYeniKapatGonder() { YONETIM_DERS_YENI_ACIK = false; yonlendir(); }
function dersEkleGonder() {
  const ad = $("#dersYeniAd").value.trim();
  if (!ad) { $("#dersYeniHata").textContent = "Ders adı zorunludur."; return; }
  const sonuc = iyDersEkle({
    ad, ikon: $("#dersYeniIkon").value.trim(), renk: $("#dersYeniRenk").value,
    aciklama: $("#dersYeniAciklama").value.trim(), hedefNot: $("#dersYeniHedef").value
  });
  if (!sonuc.ok) { $("#dersYeniHata").textContent = sonuc.mesaj; return; }
  YONETIM_DERS_YENI_ACIK = false;
  yonlendir();
}

function dersDuzenleForm(d) {
  return `
    <div class="card mb" style="border-color:var(--brand-light)">
      <label class="ayar-baslik">Ders adı</label>
      <input class="genis-input" id="dersDuzenleAd" value="${esc(d.ad)}">
      <div class="row mt wrap">
        <div><label class="ayar-baslik">İkon</label><input class="genis-input" id="dersDuzenleIkon" value="${esc(d.ikon || "")}" style="max-width:80px"></div>
        <div><label class="ayar-baslik">Renk</label><input type="color" id="dersDuzenleRenk" value="${d.renk || "#2563eb"}" style="height:42px;width:70px"></div>
        <div><label class="ayar-baslik">Hedef puan</label><input class="genis-input" id="dersDuzenleHedef" type="number" value="${d.hedefNot}" style="max-width:100px"></div>
      </div>
      <label class="ayar-baslik mt">Açıklama</label>
      <textarea class="genis-input" id="dersDuzenleAciklama" style="width:100%">${esc(d.aciklama || "")}</textarea>
      <label class="onay mt"><input type="checkbox" id="dersDuzenleAktif" ${d.aktif !== false ? "checked" : ""}> Aktif (öğrencilere görünür)</label>
      <div class="row mt">
        <button class="btn btn-primary" onclick="dersDuzenleKaydetGonder('${d.id}')">Kaydet</button>
        <button class="btn" onclick="dersDuzenleKapatGonder()">Vazgeç</button>
      </div>
    </div>`;
}
function dersDuzenleAcGonder(id) { YONETIM_DERS_DUZENLE = id; YONETIM_DERS_YENI_ACIK = false; yonlendir(); }
function dersDuzenleKapatGonder() { YONETIM_DERS_DUZENLE = null; yonlendir(); }
function dersDuzenleKaydetGonder(id) {
  iyDersGuncelle(id, {
    ad: $("#dersDuzenleAd").value.trim(), ikon: $("#dersDuzenleIkon").value.trim(),
    renk: $("#dersDuzenleRenk").value, aciklama: $("#dersDuzenleAciklama").value.trim(),
    hedefNot: +$("#dersDuzenleHedef").value || 65, aktif: $("#dersDuzenleAktif").checked
  });
  YONETIM_DERS_DUZENLE = null;
  yonlendir();
}
function dersSilGonder(id) {
  const d = DERSLER.find(x => x.id === id);
  if (!confirm(`"${d.ad}" dersini ve içindeki tüm konu/materyal/soruları silmek istediğinize emin misiniz?`)) return;
  iyDersSil(id);
  yonlendir();
}

let YONETIM_UYELER_LISTE = null;
let YONETIM_UYE_EKLE_ACIK = false;
let YONETIM_UYE_IZIN_DUZENLE = null;

/* Menü izin listesi için işaretlenebilir menü öğeleri — başlık/çıkış
   gibi gerçek bir sayfaya karşılık gelmeyen türler hariç tutulur. */
function menuIzinSecilebilirOgeler() {
  return (typeof MENU_OGELER !== "undefined" ? MENU_OGELER : [])
    .filter(o => o.tur !== "baslik" && o.tur !== "cikis" && o.id);
}
function menuIzinCheckboxlari(secililer) {
  const set = new Set(secililer || []);
  return menuIzinSecilebilirOgeler().map(o => `
    <label class="row" style="gap:6px;align-items:center;font-weight:400;font-size:13px">
      <input type="checkbox" class="uye-menu-izin" value="${esc(o.id)}" ${set.has(o.id) ? "checked" : ""}>
      <span>${o.ikon || ""} ${esc(o.etiket)}</span>
    </label>`).join("");
}

function uyelerYukle() {
  Auth.listele()
    .then(liste => { YONETIM_UYELER_LISTE = liste; yonlendir(); })
    .catch(e => { YONETIM_UYELER_LISTE = []; console.error("Üyeler yüklenemedi", e); yonlendir(); });
}

function yonetimUyelerHtml() {
  if (!ppBagliMi()) {
    return `<div class="notice uyari-notice">Üyeleri yönetmek için önce
      <a onclick="git('yonetim/dashboard')" style="cursor:pointer">Sunucu Bağlantısı</a> kurulmalı —
      üyelik hesapları artık sunucuda saklanıyor.</div>`;
  }
  if (YONETIM_UYELER_LISTE === null) { setTimeout(uyelerYukle, 0); return `<div class="muted">Yükleniyor…</div>`; }

  const k = YONETIM_UYELER_LISTE;
  const satirlar = k.map(u => `<tr>
    <td><strong>${esc(u.ad)}</strong><div class="muted">@${esc(u.kullaniciAdi)}</div></td>
    <td class="muted">${esc(u.eposta || "—")}</td>
    <td>${u.rol === "yonetici" ? `<span class="badge badge-ok">Yönetici</span>`
    : `<span class="badge badge-gray">Üye</span>`}</td>
    <td class="muted">${u.rol === "yonetici" ? "—" : Array.isArray(u.menuIzin)
      ? (u.menuIzin.length ? `${u.menuIzin.length} menü` : `<span class="hata">Erişimi yok</span>`)
      : `<span class="muted">Kısıtlama yok</span>`}</td>
    <td class="muted">${tarihTR(u.kayitTarihi)}</td>
    <td class="muted">${u.sonGiris ? tarihTR(u.sonGiris) : "—"}</td>
    <td style="text-align:right">
      ${u.kullaniciAdi !== "savci" ? `
        ${u.rol === "uye" ? `<button class="btn btn-sm" onclick="uyeIzinDuzenleAc('${u.kullaniciAdi}')">İzinler</button>` : ""}
        <button class="btn btn-sm" onclick="rolDegis('${u.kullaniciAdi}','${u.rol === "yonetici" ? "uye" : "yonetici"}')">
          ${u.rol === "yonetici" ? "Üye yap" : "Yönetici yap"}</button>
        <button class="btn btn-sm tehlike" onclick="uyeSil('${u.kullaniciAdi}')">Sil</button>`
    : `<span class="muted">—</span>`}
    </td></tr>${YONETIM_UYE_IZIN_DUZENLE === u.kullaniciAdi ? `<tr><td colspan="7" style="padding:0">${uyeIzinDuzenleForm(u)}</td></tr>` : ""}`).join("");

  return `
    <div class="grid grid-3 mb">
      <div class="stat"><div class="stat-label">Toplam Üye</div>
        <div class="stat-value">${k.length}</div></div>
      <div class="stat"><div class="stat-label">Yönetici</div>
        <div class="stat-value">${k.filter(x => x.rol === "yonetici").length}</div></div>
      <div class="stat"><div class="stat-label">Üye</div>
        <div class="stat-value">${k.filter(x => x.rol === "uye").length}</div></div>
    </div>

    <div class="card mb">
      ${YONETIM_UYE_EKLE_ACIK ? uyeEkleForm() :
        `<button class="btn btn-primary" onclick="uyeEkleAcGonder()">+ Üye Ekle</button>`}
    </div>

    <div class="card" style="padding:0;overflow:hidden">
      <table><thead><tr><th>Kullanıcı</th><th>E-posta</th><th>Rol</th><th>Menü Erişimi</th>
        <th>Kayıt</th><th>Son Giriş</th><th></th></tr></thead>
      <tbody>${satirlar || `<tr><td colspan="7" class="muted" style="text-align:center;padding:20px">Henüz üye yok.</td></tr>`}</tbody></table>
    </div>

    <div class="notice mt">
      Bu sayfada yalnızca yöneticinin eklediği üyeler giriş yapabilir — herkese açık üyelik
      kaydı yoktur. Eklediğiniz kullanıcı adı/şifreyi ilgili kişiyle siz paylaşırsınız.
    </div>`;
}

function uyeEkleForm() {
  return `
    <label class="ayar-baslik">Ad soyad</label>
    <input class="genis-input" id="uyeEkleAd" placeholder="Örn. Ayşe Yılmaz">
    <div class="row mt wrap">
      <div style="flex:1;min-width:160px">
        <label class="ayar-baslik">Kullanıcı adı</label>
        <input class="genis-input" id="uyeEkleKullanici" placeholder="Örn. ayse">
      </div>
      <div style="flex:1;min-width:160px">
        <label class="ayar-baslik">Şifre (en az 6 karakter)</label>
        <input class="genis-input" id="uyeEkleSifre" type="password">
      </div>
    </div>
    <label class="ayar-baslik mt">E-posta (isteğe bağlı)</label>
    <input class="genis-input" id="uyeEkleEposta" type="email">
    <label class="ayar-baslik mt">Rol</label>
    <select class="genis-input" id="uyeEkleRol" style="max-width:200px">
      <option value="uye" selected>Üye</option>
      <option value="yonetici">Yönetici</option>
    </select>
    <label class="ayar-baslik mt">Erişebileceği menüler</label>
    <div class="muted" style="font-size:12px;margin-bottom:6px">
      Yalnızca "Üye" rolü için geçerlidir (Yönetici zaten her şeye erişir). Hiçbiri seçilmezse
      bu üye hiçbir menüye erişemez — sonradan üye listesinden "İzinler" ile değiştirebilirsiniz.
    </div>
    <div class="uye-menu-izin-liste" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:4px 10px">
      ${menuIzinCheckboxlari([])}
    </div>
    <div class="row mt">
      <button class="btn btn-primary" onclick="uyeEkleGonder()">Üye Ekle</button>
      <button class="btn" onclick="uyeEkleKapatGonder()">Vazgeç</button>
    </div>
    <div class="hata" id="uyeEkleHata"></div>`;
}
function uyeEkleAcGonder() { YONETIM_UYE_EKLE_ACIK = true; yonlendir(); }
function uyeEkleKapatGonder() { YONETIM_UYE_EKLE_ACIK = false; yonlendir(); }
async function uyeEkleGonder() {
  const menuIzin = [...document.querySelectorAll(".uye-menu-izin-liste .uye-menu-izin:checked")].map(el => el.value);
  const r = await Auth.ekle({
    kullaniciAdi: $("#uyeEkleKullanici").value.trim().toLowerCase(),
    ad: $("#uyeEkleAd").value.trim(),
    eposta: $("#uyeEkleEposta").value.trim(),
    sifre: $("#uyeEkleSifre").value,
    rol: $("#uyeEkleRol").value,
    menuIzin
  });
  if (!r.ok) { $("#uyeEkleHata").textContent = r.mesaj; return; }
  YONETIM_UYE_EKLE_ACIK = false;
  YONETIM_UYELER_LISTE = null;
  yonlendir();
}

function uyeIzinDuzenleAc(ka) { YONETIM_UYE_IZIN_DUZENLE = ka; yonlendir(); }
function uyeIzinDuzenleKapat() { YONETIM_UYE_IZIN_DUZENLE = null; yonlendir(); }
function uyeIzinDuzenleForm(u) {
  const kisitli = Array.isArray(u.menuIzin);
  return `
    <div class="card" style="margin:8px 0;background:var(--yzt-panel-2,#f8fafc)">
      <h4 style="margin-top:0">@${esc(u.kullaniciAdi)} — erişebileceği menüler</h4>
      <div class="muted" style="font-size:12px;margin-bottom:6px">
        ${kisitli ? "Bu üye yalnızca işaretli menülere erişebilir." : "Bu üyenin şu an bir kısıtlaması yok — herkese açık tüm menülere erişir. İşaretleyip kaydederseniz yalnızca seçtiklerinizle sınırlanır."}
      </div>
      <div class="uye-izin-duzenle-liste" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:4px 10px">
        ${menuIzinCheckboxlari(u.menuIzin || [])}
      </div>
      <div class="row mt wrap">
        <button class="btn btn-primary btn-sm" onclick="uyeIzinDuzenleGonder('${u.kullaniciAdi}')">Kaydet</button>
        ${kisitli ? `<button class="btn btn-sm" onclick="uyeIzinSinirsizYapGonder('${u.kullaniciAdi}')">Kısıtlamayı kaldır</button>` : ""}
        <button class="btn btn-sm" onclick="uyeIzinDuzenleKapat()">Vazgeç</button>
      </div>
      <div class="hata" id="uyeIzinDuzenleHata"></div>
    </div>`;
}
async function uyeIzinDuzenleGonder(ka) {
  const menuIzin = [...document.querySelectorAll(".uye-izin-duzenle-liste .uye-menu-izin:checked")].map(el => el.value);
  const r = await Auth.menuIzinGuncelle(ka, { menuIzin });
  if (!r.ok) { const e = $("#uyeIzinDuzenleHata"); if (e) e.textContent = r.mesaj; return; }
  YONETIM_UYE_IZIN_DUZENLE = null;
  YONETIM_UYELER_LISTE = null;
  yonlendir();
}
async function uyeIzinSinirsizYapGonder(ka) {
  if (!confirm(`@${ka} kullanıcısının menü kısıtlaması tamamen kaldırılsın mı? (Herkese açık tüm menülere erişebilir hâle gelir.)`)) return;
  const r = await Auth.menuIzinGuncelle(ka, { sinirsiz: true });
  if (!r.ok) { alert(r.mesaj); return; }
  YONETIM_UYE_IZIN_DUZENLE = null;
  YONETIM_UYELER_LISTE = null;
  yonlendir();
}

async function rolDegis(ka, rol) {
  if (!confirm(`@${ka} kullanıcısının rolü değiştirilsin mi?`)) return;
  const r = await Auth.rolDegistir(ka, rol);
  if (!r.ok) { alert(r.mesaj); return; }
  YONETIM_UYELER_LISTE = null; yonlendir();
}
async function uyeSil(ka) {
  if (!confirm(`@${ka} kullanıcısı silinecek. Emin misiniz?`)) return;
  const r = await Auth.sil(ka);
  if (!r.ok) { alert(r.mesaj); return; }
  YONETIM_UYELER_LISTE = null; yonlendir();
}

/* ---------------------------------------------------------
   19c2. YÖNETİM — FORUM (başlık/kategori ekleme-silme)
   --------------------------------------------------------- */
let YONETIM_FORUM_YENI_ACIK = false;
function yonetimForumHtml() {
  if (!ppBagliMi()) {
    return `<div class="notice uyari-notice">Forum başlıkları için önce
      <a onclick="git('yonetim/dashboard')" style="cursor:pointer">Sunucu Bağlantısı</a> kurulmalı.</div>`;
  }
  if (FORUM_KATEGORILER === null) { setTimeout(forumKategorilerYukle, 0); return `<div class="muted">Yükleniyor…</div>`; }

  const satirlar = FORUM_KATEGORILER.map(k => `
    <div class="icerik-satir">
      <div class="grow" style="cursor:pointer" onclick="git('forum/${k.id}')">
        <strong>${esc(k.ad)}</strong>
        <div class="muted">${esc(k.aciklama || "")} · ${k.konuSayisi} konu</div>
      </div>
      <button class="btn btn-sm tehlike" onclick="forumKategoriSilGonder(${k.id})">Sil</button>
    </div>`).join("");

  return `
    <div class="card mb">
      ${YONETIM_FORUM_YENI_ACIK ? forumKategoriYeniForm() :
        `<button class="btn btn-primary" onclick="forumKategoriYeniAcGonder()">+ Yeni Başlık Ekle</button>`}
    </div>
    <h2 class="section">Forum Başlıkları (${FORUM_KATEGORILER.length})</h2>
    ${satirlar || `<div class="empty"><div class="icon">💬</div>Henüz forum başlığı eklenmedi.</div>`}`;
}
function forumKategoriYeniForm() {
  return `
    <label class="ayar-baslik">Başlık adı</label>
    <input class="genis-input" id="forumKategoriAd" placeholder="Örn. Sınav Soruları">
    <label class="ayar-baslik mt">Açıklama (isteğe bağlı)</label>
    <input class="genis-input" id="forumKategoriAciklama" placeholder="Örn. Çıkmış sınav sorularını burada tartışalım">
    <div class="row mt">
      <button class="btn btn-primary" onclick="forumKategoriEkleGonder()">Kaydet</button>
      <button class="btn" onclick="forumKategoriYeniKapatGonder()">Vazgeç</button>
    </div>
    <div class="hata" id="forumKategoriHata"></div>`;
}
function forumKategoriYeniAcGonder() { YONETIM_FORUM_YENI_ACIK = true; yonlendir(); }
function forumKategoriYeniKapatGonder() { YONETIM_FORUM_YENI_ACIK = false; yonlendir(); }
async function forumKategoriEkleGonder() {
  const ad = $("#forumKategoriAd").value.trim();
  if (!ad) { $("#forumKategoriHata").textContent = "Başlık adı zorunludur."; return; }
  try {
    await kuForumKategoriEkle(ad, $("#forumKategoriAciklama").value.trim());
    YONETIM_FORUM_YENI_ACIK = false;
    FORUM_KATEGORILER = null;
    yonlendir();
  } catch (e) {
    $("#forumKategoriHata").textContent = e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "");
  }
}
function forumKategoriSilGonder(id) {
  if (!confirm("Bu başlığı ve içindeki tüm konu/mesajları silmek istediğinize emin misiniz?")) return;
  kuForumKategoriSil(id)
    .then(() => { FORUM_KATEGORILER = null; yonlendir(); })
    .catch(e => alert("Silinemedi: " + e.message));
}

/* ---------------------------------------------------------
   19a2. YÖNETİM — MENÜ DÜZENLEME + ÖZEL SAYFALAR
   MENU_OGELER/MENU_OZEL_SAYFALAR üzerinde doğrudan mutasyon
   (icerik-yukle.js'teki iyMenuOge/iyOzelSayfa yardımcıları), aynı
   taslak+arka plan senkron akışını (icerikTaslakKaydet) kullanır.
   --------------------------------------------------------- */
let YONETIM_MENU_OGE_FORM = null;   // null | "yeni" | düzenlenen ögenin id'si
let YONETIM_OZEL_SAYFA_FORM = null; // null | "yeni" | düzenlenen sayfanın slug'ı
let YONETIM_ALTMENU_FORM = null;    // null | alt öğe eklenecek ana ögenin id'si

const MENU_ROTA_SECENEKLERI = [
  ["panel", "Ana Sayfa"], ["dersler", "Dersler"], ["test-et", "Beni Test Et"],
  ["plan", "Çalışma Planı"], ["takvim", "Takvim"], ["rapor", "İlerleme Raporu"], ["uyelik", "Üyelik ve Ayarlar"],
  ["yonetim", "Yönetim"], ["strateji", "Sınav Stratejisi"], ["forum", "Forum"]
];

function yonetimMenuHtml(altSekme) {
  altSekme = ["ogeler", "sayfalar", "altmenu"].includes(altSekme) ? altSekme : "ogeler";
  return `
    <div class="sekme mb wrap">
      <button class="sekme-btn ${altSekme === "ogeler" ? "aktif" : ""}" onclick="git('yonetim/menu/ogeler')">Menü Öğeleri</button>
      <button class="sekme-btn ${altSekme === "sayfalar" ? "aktif" : ""}" onclick="git('yonetim/menu/sayfalar')">Özel Sayfalar</button>
      <button class="sekme-btn ${altSekme === "altmenu" ? "aktif" : ""}" onclick="git('yonetim/menu/altmenu')">Alt Menüler</button>
    </div>
    ${altSekme === "sayfalar" ? yonetimOzelSayfalarHtml() : altSekme === "altmenu" ? yonetimAltMenuHtml() : yonetimMenuOgeleriHtml()}`;
}

function menuOgeAciklama(o) {
  if (o.tur === "baslik") return `${esc(o.etiket)} <span class="muted">(bölüm başlığı)</span>`;
  if (o.tur === "cikis") return `${esc(o.etiket)} <span class="muted">(çıkış eylemi)</span>`;
  if (o.tur === "ozel-sayfa") {
    const s = MENU_OZEL_SAYFALAR.find(x => x.slug === o.slug);
    return `${esc(o.etiket)} <span class="muted">→ özel sayfa: ${esc(s ? s.baslik : o.slug || "?")}</span>`;
  }
  const rota = MENU_ROTA_SECENEKLERI.find(r => r[0] === o.route);
  return `${esc(o.etiket)} <span class="muted">→ ${esc(rota ? rota[1] : o.route || "?")}</span>`;
}

function yonetimMenuOgeleriHtml() {
  const satirlar = MENU_OGELER.map((o, i) => `
    <div class="icerik-satir">
      <div class="grow">
        <strong>${o.ikon ? o.ikon + " " : ""}${menuOgeAciklama(o)}</strong>
        ${o.sadeceYonetici ? `<div class="muted">Yalnızca yönetici görür</div>` : ""}
        ${o.gorunur === false ? `<div class="muted">Gizli</div>` : ""}
      </div>
      <button class="btn btn-sm" onclick="menuOgeSiraGonder('${o.id}',-1)" ${i === 0 ? "disabled" : ""}>↑</button>
      <button class="btn btn-sm" onclick="menuOgeSiraGonder('${o.id}',1)" ${i === MENU_OGELER.length - 1 ? "disabled" : ""}>↓</button>
      <button class="btn btn-sm" onclick="menuOgeGorunurlukGonder('${o.id}')">${o.gorunur === false ? "Göster" : "Gizle"}</button>
      <button class="btn btn-sm" onclick="menuOgeDuzenleAcGonder('${o.id}')">Düzenle</button>
      <button class="btn btn-sm tehlike" onclick="menuOgeSilGonder('${o.id}')">Sil</button>
    </div>`).join("");

  return `
    <div class="card mb">
      ${YONETIM_MENU_OGE_FORM ? menuOgeForm() : `<button class="btn btn-primary" onclick="menuOgeDuzenleAcGonder('yeni')">+ Menü Öğesi Ekle</button>`}
    </div>
    <h2 class="section">Menü Öğeleri (${MENU_OGELER.length})</h2>
    ${satirlar}`;
}

function menuOgeForm() {
  const duzenleId = YONETIM_MENU_OGE_FORM === "yeni" ? null : YONETIM_MENU_OGE_FORM;
  const o = duzenleId ? MENU_OGELER.find(x => x.id === duzenleId) : null;

  if (o && o.tur === "cikis") {
    return `
      <label class="ayar-baslik">Etiket</label>
      <input class="genis-input" id="menuOgeEtiket" value="${esc(o.etiket)}">
      <label class="ayar-baslik mt">İkon (emoji)</label>
      <input class="genis-input" id="menuOgeIkon" value="${esc(o.ikon || "")}">
      <div class="row mt">
        <button class="btn btn-primary" onclick="menuOgeKaydetGonder()">Kaydet</button>
        <button class="btn" onclick="menuOgeDuzenleKapatGonder()">Vazgeç</button>
      </div>
      <div class="hata" id="menuOgeHata"></div>`;
  }

  const tur = o ? o.tur : "sayfa";
  return `
    <label class="ayar-baslik">Öğe türü</label>
    <select class="genis-input" id="menuOgeTur">
      <option value="sayfa" ${tur === "sayfa" ? "selected" : ""}>Sayfa bağlantısı</option>
      <option value="ozel-sayfa" ${tur === "ozel-sayfa" ? "selected" : ""}>Özel sayfa bağlantısı</option>
      <option value="baslik" ${tur === "baslik" ? "selected" : ""}>Bölüm başlığı</option>
    </select>
    <label class="ayar-baslik mt">Etiket (menüde görünecek yazı)</label>
    <input class="genis-input" id="menuOgeEtiket" value="${esc(o ? o.etiket : "")}" placeholder="Örn. Duyurular">
    <label class="ayar-baslik mt">İkon (emoji, isteğe bağlı)</label>
    <input class="genis-input" id="menuOgeIkon" value="${esc(o ? (o.ikon || "") : "")}" placeholder="📌">
    <label class="ayar-baslik mt">Bağlantı — sayfa <span class="muted">(tür "Sayfa bağlantısı" ise)</span></label>
    <select class="genis-input" id="menuOgeRoute">
      ${MENU_ROTA_SECENEKLERI.map(([r, ad]) => `<option value="${r}" ${o && o.route === r ? "selected" : ""}>${esc(ad)}</option>`).join("")}
    </select>
    <label class="ayar-baslik mt">Bağlantı — özel sayfa <span class="muted">(tür "Özel sayfa bağlantısı" ise)</span></label>
    <select class="genis-input" id="menuOgeOzelSayfa">
      ${MENU_OZEL_SAYFALAR.length ? MENU_OZEL_SAYFALAR.map(s => `<option value="${esc(s.slug)}" ${o && o.slug === s.slug ? "selected" : ""}>${esc(s.baslik)}</option>`).join("")
        : `<option value="">Önce "Özel Sayfalar" sekmesinden bir sayfa oluşturun</option>`}
    </select>
    <label class="row mt" style="gap:8px">
      <input type="checkbox" id="menuOgeSadeceYonetici" ${o && o.sadeceYonetici ? "checked" : ""}>
      Yalnızca yönetici görsün
    </label>
    <div class="row mt">
      <button class="btn btn-primary" onclick="menuOgeKaydetGonder()">Kaydet</button>
      <button class="btn" onclick="menuOgeDuzenleKapatGonder()">Vazgeç</button>
    </div>
    <div class="hata" id="menuOgeHata"></div>`;
}

function menuOgeDuzenleAcGonder(id) { YONETIM_MENU_OGE_FORM = id; yonlendir(); }
function menuOgeDuzenleKapatGonder() { YONETIM_MENU_OGE_FORM = null; yonlendir(); }
function menuOgeSiraGonder(id, yon) { iyMenuOgeSirala(id, yon); yonlendir(); }
function menuOgeGorunurlukGonder(id) { iyMenuOgeGorunurlukDegistir(id); yonlendir(); }
function menuOgeSilGonder(id) {
  if (!confirm("Bu menü öğesini silmek istediğinize emin misiniz?")) return;
  iyMenuOgeSil(id);
  yonlendir();
}
function menuOgeKaydetGonder() {
  const duzenleId = YONETIM_MENU_OGE_FORM === "yeni" ? null : YONETIM_MENU_OGE_FORM;
  const mevcut = duzenleId ? MENU_OGELER.find(x => x.id === duzenleId) : null;
  const etiket = $("#menuOgeEtiket").value.trim();
  if (!etiket) { $("#menuOgeHata").textContent = "Etiket zorunludur."; return; }
  const ikon = $("#menuOgeIkon").value.trim();

  if (mevcut && mevcut.tur === "cikis") {
    iyMenuOgeGuncelle(mevcut.id, { etiket, ikon });
    YONETIM_MENU_OGE_FORM = null;
    yonlendir();
    return;
  }

  const tur = $("#menuOgeTur").value;
  const alanlar = { tur, etiket, ikon, sadeceYonetici: $("#menuOgeSadeceYonetici").checked };
  if (tur === "sayfa") alanlar.route = $("#menuOgeRoute").value;
  if (tur === "ozel-sayfa") {
    const slug = $("#menuOgeOzelSayfa").value;
    if (!slug) { $("#menuOgeHata").textContent = "Önce \"Özel Sayfalar\" sekmesinden bir sayfa oluşturun."; return; }
    alanlar.slug = slug;
  }

  if (mevcut) iyMenuOgeGuncelle(mevcut.id, alanlar);
  else iyMenuOgeEkle(alanlar);

  YONETIM_MENU_OGE_FORM = null;
  yonlendir();
}

/* ---------- ALT MENÜLER — kenar çubuğunda bir öğenin üzerine gelince
   (hover) açılan alt listeyi yönetir (bkz. app.js: menuCiz/altMenuFlyoutHtml,
   icerik-yukle.js: iyAltMenuEkle/Guncelle/Sil/Sirala). "Yönetim" öğesinin
   alt sekmeleri koddan gelir (YONETIM_ACILIR_SEKMELER) ve burada
   düzenlenemez; burada yalnızca diğer "sayfa" türü öğelere serbest alt
   öğe eklenir. ---------- */
function yonetimAltMenuHtml() {
  const ogeler = MENU_OGELER.filter(o => o.tur === "sayfa" && o.route !== "yonetim");
  const satirlar = ogeler.map(o => {
    const altMenu = o.altMenu || [];
    const altSatirlar = altMenu.map((a, i) => {
      const rota = MENU_ROTA_SECENEKLERI.find(r => r[0] === a.route);
      return `
      <div class="icerik-satir">
        <div class="grow">${esc(a.etiket)} <span class="muted">→ ${esc(rota ? rota[1] : a.route)}</span></div>
        <button class="btn btn-sm" onclick="altMenuSiraGonder('${o.id}','${a.id}',-1)" ${i === 0 ? "disabled" : ""}>↑</button>
        <button class="btn btn-sm" onclick="altMenuSiraGonder('${o.id}','${a.id}',1)" ${i === altMenu.length - 1 ? "disabled" : ""}>↓</button>
        <button class="btn btn-sm tehlike" onclick="altMenuSilGonder('${o.id}','${a.id}')">Sil</button>
      </div>`;
    }).join("");
    return `
      <div class="card mb">
        <strong>${o.ikon ? o.ikon + " " : ""}${esc(o.etiket)}</strong>
        <div class="muted" style="margin:4px 0 10px">Kenar çubuğunda üzerine gelindiğinde açılan alt liste</div>
        ${altSatirlar || `<div class="muted mb">Henüz alt öğe eklenmedi.</div>`}
        ${YONETIM_ALTMENU_FORM === o.id ? altMenuForm(o.id) : `<button class="btn btn-sm mt" onclick="altMenuFormAcGonder('${o.id}')">+ Alt Öğe Ekle</button>`}
      </div>`;
  }).join("");

  return `
    <div class="notice mb">Bir menü öğesine alt öğe eklerseniz, kenar çubuğunda o öğenin üzerine gelindiğinde (fare ile durulduğunda) alt öğeleri gösteren bir pencere açılır — "Yönetim" menüsündeki gibi.</div>
    ${satirlar || `<div class="empty"><div class="icon">🗂️</div>Alt menü eklenebilecek bir menü öğesi yok.</div>`}`;
}

function altMenuForm(anaId) {
  return `
    <div class="mt">
      <label class="ayar-baslik">Etiket</label>
      <input class="genis-input" id="altMenuEtiket" placeholder="Örn. Aylık Rapor">
      <label class="ayar-baslik mt">Bağlantı</label>
      <select class="genis-input" id="altMenuRoute">
        ${MENU_ROTA_SECENEKLERI.map(([r, ad]) => `<option value="${r}">${esc(ad)}</option>`).join("")}
      </select>
      <div class="row mt">
        <button class="btn btn-primary" onclick="altMenuKaydetGonder('${anaId}')">Ekle</button>
        <button class="btn" onclick="altMenuFormKapatGonder()">Vazgeç</button>
      </div>
      <div class="hata" id="altMenuHata"></div>
    </div>`;
}

function altMenuFormAcGonder(anaId) { YONETIM_ALTMENU_FORM = anaId; yonlendir(); }
function altMenuFormKapatGonder() { YONETIM_ALTMENU_FORM = null; yonlendir(); }
function altMenuKaydetGonder(anaId) {
  const etiket = $("#altMenuEtiket").value.trim();
  if (!etiket) { $("#altMenuHata").textContent = "Etiket zorunludur."; return; }
  const route = $("#altMenuRoute").value;
  iyAltMenuEkle(anaId, { etiket, route });
  YONETIM_ALTMENU_FORM = null;
  yonlendir();
}
function altMenuSiraGonder(anaId, altId, yon) { iyAltMenuSirala(anaId, altId, yon); yonlendir(); }
function altMenuSilGonder(anaId, altId) {
  if (!confirm("Bu alt öğeyi silmek istediğinize emin misiniz?")) return;
  iyAltMenuSil(anaId, altId);
  yonlendir();
}

function yonetimOzelSayfalarHtml() {
  const satirlar = MENU_OZEL_SAYFALAR.map(s => `
    <div class="icerik-satir">
      <div class="grow">
        <strong>${esc(s.baslik)}</strong>
        <div class="muted">/ozel/${esc(s.slug)}</div>
      </div>
      <button class="btn btn-sm" onclick="ozelSayfaDuzenleAcGonder('${s.slug}')">Düzenle</button>
      <button class="btn btn-sm tehlike" onclick="ozelSayfaSilGonder('${s.slug}')">Sil</button>
    </div>`).join("");

  return `
    <div class="card mb">
      ${YONETIM_OZEL_SAYFA_FORM ? ozelSayfaForm() : `<button class="btn btn-primary" onclick="ozelSayfaDuzenleAcGonder('yeni')">+ Yeni Özel Sayfa Ekle</button>`}
    </div>
    <h2 class="section">Özel Sayfalar (${MENU_OZEL_SAYFALAR.length})</h2>
    ${satirlar || `<div class="empty"><div class="icon">📄</div>Henüz özel sayfa eklenmedi.</div>`}`;
}

function ozelSayfaForm() {
  const duzenleSlug = YONETIM_OZEL_SAYFA_FORM === "yeni" ? null : YONETIM_OZEL_SAYFA_FORM;
  const s = duzenleSlug ? MENU_OZEL_SAYFALAR.find(x => x.slug === duzenleSlug) : null;
  return `
    <label class="ayar-baslik">Başlık</label>
    <input class="genis-input" id="ozelSayfaBaslik" value="${esc(s ? s.baslik : "")}" placeholder="Örn. Sıkça Sorulan Sorular">
    <label class="ayar-baslik mt">İçerik</label>
    ${editorHtml("ozelSayfaEditor", s ? s.icerik : "")}
    <div class="row mt">
      <button class="btn btn-primary" onclick="ozelSayfaKaydetGonder('${duzenleSlug || ""}')">Kaydet</button>
      <button class="btn" onclick="ozelSayfaDuzenleKapatGonder()">Vazgeç</button>
    </div>
    <div class="hata" id="ozelSayfaHata"></div>`;
}

function ozelSayfaDuzenleAcGonder(slug) { YONETIM_OZEL_SAYFA_FORM = slug; yonlendir(); }
function ozelSayfaDuzenleKapatGonder() { YONETIM_OZEL_SAYFA_FORM = null; yonlendir(); }
function ozelSayfaKaydetGonder(duzenleSlug) {
  const baslik = $("#ozelSayfaBaslik").value.trim();
  if (!baslik) { $("#ozelSayfaHata").textContent = "Başlık zorunludur."; return; }
  const icerik = editorIcerikAl("ozelSayfaEditor");
  if (duzenleSlug) {
    iyOzelSayfaGuncelle(duzenleSlug, { baslik, icerik });
  } else {
    const sonuc = iyOzelSayfaEkle({ baslik, icerik });
    if (!sonuc.ok) { $("#ozelSayfaHata").textContent = sonuc.mesaj; return; }
  }
  YONETIM_OZEL_SAYFA_FORM = null;
  yonlendir();
}
function ozelSayfaSilGonder(slug) {
  if (!confirm("Bu özel sayfayı silmek istediğinize emin misiniz? Menüde buna bağlı öğeler de kaldırılacak.")) return;
  iyOzelSayfaSil(slug);
  yonlendir();
}

/* ---------------------------------------------------------
   19b. YÖNETİM — İÇERİK EKLEME (konu / materyal / özet /
   görsel / fotoroman / soru). DERSLER ve SORULAR üzerine
   icerik-depo.js aracılığıyla ekleme yapar.
   --------------------------------------------------------- */
function icerikYenile(dersId, konuId) {
  const h = "yonetim/icerik" + (dersId ? "/" + dersId : "") + (konuId ? "/" + konuId : "");
  git(h);
}

function yonetimIcerikHtml(dersId, konuId) {
  const d = DERSLER.find(x => x.id === dersId) || DERSLER[0];
  if (!d) return `<div class="empty"><div class="icon">📚</div>Önce bir ders ekleyin.</div>`;
  const k = konuId ? konuBul(d.id, konuId) : null;
  if (!k) { git("yonetim/konular/" + d.id); return `<div class="muted">Yönlendiriliyor…</div>`; }

  const dersSecici = `
    <label class="ayar-baslik">Ders</label>
    <select class="genis-input" onchange="git('yonetim/konular/' + this.value)">
      ${DERSLER.map(x => `<option value="${x.id}" ${x.id === d.id ? "selected" : ""}>${x.ikon} ${esc(x.ad)}</option>`).join("")}
    </select>`;

  return `
    <div class="breadcrumb"><a onclick="git('yonetim/konular/${d.id}')">${esc(d.ad)}</a> / ${esc(k.ad)}</div>
    <div class="card mb">${dersSecici}</div>
    <div class="page-title" style="font-size:20px">${esc(k.ad)}</div>
    <div class="page-sub"><a onclick="git('dersler/${d.id}/${k.id}')" style="cursor:pointer">Konu sayfasını görüntüle ↗</a></div>

    ${materyalYonetimHtml(k)}
    ${ozetYonetimHtml(k, d.id)}
    ${gorselYonetimHtml(k)}
    ${fotoYonetimHtml(k)}
    ${bilgiKartiYonetimHtml(d.id, k)}
    ${cokSecmeliSoruYonetimHtml(d.id, k)}
    ${pratikSetYonetimHtml(d, k)}`;
}

/* ---------------------------------------------------------
   19b0. YÖNETİM — KONULAR (ders başına konu/alt konu yönetimi)
   --------------------------------------------------------- */
let YONETIM_KONU_DUZENLE = null;
let YONETIM_KONU_YENI_UST = null; // null → yeni üst konu formu, konuId → o konunun altına alt konu ekleme formu

function yonetimKonularHtml(dersId) {
  const d = DERSLER.find(x => x.id === dersId) || DERSLER[0];
  if (!d) return `<div class="empty"><div class="icon">📚</div>Önce bir ders ekleyin.</div>`;

  const dersSecici = `
    <label class="ayar-baslik">Ders</label>
    <select class="genis-input" onchange="git('yonetim/konular/' + this.value)">
      ${DERSLER.map(x => `<option value="${x.id}" ${x.id === d.id ? "selected" : ""}>${x.ikon} ${esc(x.ad)}</option>`).join("")}
    </select>`;

  const ustKonular = d.konular.filter(k => !k.ustKonuId);
  const satirHtml = (x, i, altMi) => `
    <div class="icerik-satir" style="${altMi ? "margin-left:26px;border-left:3px solid var(--border)" : ""}">
      ${badge(x.oncelik)}
      <div class="grow">
        <strong>${esc(x.ad)}</strong>
        <div class="muted">${(x.materyaller || []).length} materyal ·
          ${(x.ozetSayfalari || []).length} özet sayfası ·
          ${(x.gorseller || []).length} görsel ·
          ${(x.fotoromanlar || []).length} fotoroman ·
          ${soruSayisi(d.id, x.id)} soru</div>
      </div>
      <button class="btn btn-sm" onclick="konuSiralaGonder('${d.id}','${x.id}',-1)" ${i === 0 ? "disabled" : ""}>↑</button>
      <button class="btn btn-sm" onclick="konuSiralaGonder('${d.id}','${x.id}',1)" ${i === d.konular.length - 1 ? "disabled" : ""}>↓</button>
      ${!altMi ? `<button class="btn btn-sm" onclick="konuAltEkleAcGonder('${x.id}')">Alt Konu Ekle</button>` : ""}
      <button class="btn btn-sm" onclick="konuDuzenleAcGonder('${x.id}')">Düzenle</button>
      <button class="btn btn-sm" onclick="git('yonetim/icerik/${d.id}/${x.id}')">İçerik Ekle ›</button>
      <button class="btn btn-sm tehlike" onclick="konuSilGonder('${d.id}','${x.id}')">Sil</button>
    </div>
    ${YONETIM_KONU_DUZENLE === x.id ? konuDuzenleForm(d.id, x) : ""}
    ${YONETIM_KONU_YENI_UST === x.id ? konuEkleForm(d.id, x.id) : ""}`;

  const konuSatirlari = ustKonular.map((x, i) => {
    const altlar = d.konular.filter(a => a.ustKonuId === x.id);
    return satirHtml(x, d.konular.indexOf(x), false)
      + altlar.map(a => satirHtml(a, d.konular.indexOf(a), true)).join("");
  }).join("");

  return `
    <div class="card mb">${dersSecici}</div>
    <div class="card mb">
      <h3 class="mb">Bu Derse Yeni Konu Ekle</h3>
      ${konuEkleForm(d.id, null)}
    </div>
    <h2 class="section">Konular (${d.konular.length})</h2>
    ${d.konular.length ? konuSatirlari : `<div class="empty"><div class="icon">📂</div>Bu derste henüz konu yok. Yukarıdan ekleyin.</div>`}`;
}

function konuEkleForm(dersId, ustKonuId) {
  const id = ustKonuId || "yeni";
  return `
    <div class="row wrap mt" style="gap:8px;align-items:flex-end">
      <div class="grow"><label class="ayar-baslik">${ustKonuId ? "Alt konu adı" : "Konu adı"}</label>
        <input class="genis-input" id="konuEkleAd_${id}" placeholder="Örn. TMS 41 — Tarımsal Faaliyetler"></div>
      <div><label class="ayar-baslik">Öncelik</label>
        <select class="genis-input" id="konuEkleOncelik_${id}">
          <option value="A">A — Zorunlu alan</option>
          <option value="B" selected>B — İkinci halka</option>
          <option value="C">C — Tarama düzeyi</option>
        </select></div>
      <button class="btn btn-primary" onclick="konuEkleGonder('${dersId}',${ustKonuId ? `'${ustKonuId}'` : "null"})">${ustKonuId ? "Alt Konu Ekle" : "Konu Ekle"}</button>
      ${ustKonuId ? `<button class="btn" onclick="konuAltEkleKapatGonder()">Vazgeç</button>` : ""}
    </div>
    <div class="hata" id="konuEkleHata_${id}"></div>`;
}
function konuAltEkleAcGonder(konuId) { YONETIM_KONU_YENI_UST = konuId; YONETIM_KONU_DUZENLE = null; yonlendir(); }
function konuAltEkleKapatGonder() { YONETIM_KONU_YENI_UST = null; yonlendir(); }

function konuDuzenleForm(dersId, k) {
  return `
    <div class="card mb" style="border-color:var(--brand-light);margin-left:${k.ustKonuId ? "26px" : "0"}">
      <label class="ayar-baslik">Konu adı</label>
      <input class="genis-input" id="konuDuzenleAd_${k.id}" value="${esc(k.ad)}">
      <label class="ayar-baslik mt">Öncelik</label>
      <select class="genis-input" id="konuDuzenleOncelik_${k.id}">
        <option value="A" ${k.oncelik === "A" ? "selected" : ""}>A — Zorunlu alan</option>
        <option value="B" ${k.oncelik === "B" ? "selected" : ""}>B — İkinci halka</option>
        <option value="C" ${k.oncelik === "C" ? "selected" : ""}>C — Tarama düzeyi</option>
      </select>
      <div class="row mt">
        <button class="btn btn-primary" onclick="konuDuzenleKaydetGonder('${dersId}','${k.id}')">Kaydet</button>
        <button class="btn" onclick="konuDuzenleKapatGonder()">Vazgeç</button>
      </div>
    </div>`;
}
function konuDuzenleAcGonder(konuId) { YONETIM_KONU_DUZENLE = konuId; YONETIM_KONU_YENI_UST = null; yonlendir(); }
function konuDuzenleKapatGonder() { YONETIM_KONU_DUZENLE = null; yonlendir(); }
function konuDuzenleKaydetGonder(dersId, konuId) {
  const ad = $("#konuDuzenleAd_" + konuId).value.trim();
  if (!ad) return;
  iyKonuGuncelle(dersId, konuId, { ad, oncelik: $("#konuDuzenleOncelik_" + konuId).value });
  YONETIM_KONU_DUZENLE = null;
  git("yonetim/konular/" + dersId);
}

function konuEkleGonder(dersId, ustKonuId) {
  const id = ustKonuId || "yeni";
  const ad = $("#konuEkleAd_" + id).value.trim();
  if (!ad) { $("#konuEkleHata_" + id).textContent = "Konu adı zorunludur."; return; }
  const sonuc = iyKonuEkle(dersId, { ad, oncelik: $("#konuEkleOncelik_" + id).value, ustKonuId: ustKonuId || undefined });
  if (!sonuc.ok) { $("#konuEkleHata_" + id).textContent = sonuc.mesaj; return; }
  YONETIM_KONU_YENI_UST = null;
  git("yonetim/konular/" + dersId);
}
function konuSiralaGonder(dersId, konuId, yon) { iyKonuSirala(dersId, konuId, yon); git("yonetim/konular/" + dersId); }
function konuSilGonder(dersId, konuId) {
  if (!confirm("Bu konuyu ve içindeki tüm materyal/özet/görsel/fotoroman/soruları silmek istediğinize emin misiniz?")) return;
  iyKonuSil(dersId, konuId);
  git("yonetim/konular/" + dersId);
}

/* ---------------------------------------------------------
   19b1. YÖNETİM — İÇERİK YÖNETİMİ (merkezi liste: tüm dersler/konular
   üzerindeki özet sayfaları — filtrelenebilir, aranabilir)
   --------------------------------------------------------- */
const ICERIK_TIPLERI = ["Ders Notu", "Detaylı Anlatım", "Özet", "Kritik Bilgi", "Formül", "Mevzuat Notu", "Özel Sayfa"];
const ICERIK_DURUMLARI = [["taslak", "Taslak"], ["yayinda", "Yayında"], ["arsiv", "Arşiv"]];
let YONETIM_ICERIK_FILTRE = { ders: "", tip: "", durum: "", arama: "" };

function icerikListesiTumSayfalar() {
  const liste = [];
  DERSLER.forEach(d => d.konular.forEach(k => (k.ozetSayfalari || []).forEach((s, idx) => {
    liste.push({ d, k, s, idx });
  })));
  return liste;
}
function icerikListesiFiltrele(liste) {
  const f = YONETIM_ICERIK_FILTRE;
  const arama = (f.arama || "").trim().toLowerCase();
  return liste.filter(x =>
    (!f.ders || x.d.id === f.ders) &&
    (!f.tip || (x.s.tip || "Özet") === f.tip) &&
    (!f.durum || (x.s.durum || "yayinda") === f.durum) &&
    (!arama || (x.s.baslik || "").toLowerCase().includes(arama)));
}
function icerikDurumBadge(durum) {
  const d = durum || "yayinda";
  const sinif = d === "taslak" ? "badge-gray" : d === "arsiv" ? "badge-C" : "badge-ok";
  const ad = d === "taslak" ? "Taslak" : d === "arsiv" ? "Arşiv" : "Yayında";
  return `<span class="badge ${sinif}">${ad}</span>`;
}

function yonetimIcerikListesiHtml(dersId) {
  const f = YONETIM_ICERIK_FILTRE;
  if (dersId && !f.ders) f.ders = dersId;
  const tumSayfalar = icerikListesiTumSayfalar();
  const gorunenler = icerikListesiFiltrele(tumSayfalar);

  const satirlar = gorunenler.map(x => `
    <div class="icerik-satir">
      <div class="grow">
        <strong>${esc(x.s.baslik || "(başlıksız)")}</strong>
        <div class="muted">${esc(x.d.ad)} · ${esc(x.k.ad)} · ${esc(x.s.tip || "Özet")} · ${icerikDurumBadge(x.s.durum)}</div>
      </div>
      <button class="btn btn-sm" onclick="icerikListesiDuzenleGonder('${x.d.id}','${x.k.id}',${x.idx})">Düzenle</button>
      <button class="btn btn-sm" onclick="icerikListesiOnizleGonder('${x.d.id}','${x.k.id}',${x.idx})">Önizle</button>
      <button class="btn btn-sm" onclick="icerikListesiKopyalaGonder('${x.k.id}',${x.idx})">Kopyala</button>
      <button class="btn btn-sm tehlike" onclick="icerikListesiSilGonder('${x.k.id}',${x.idx})">Sil</button>
    </div>`).join("");

  return `
    <div class="card mb">
      <div class="row wrap" style="gap:10px">
        <div><label class="ayar-baslik">Ders</label>
          <select class="genis-input" onchange="icerikListesiFiltreDegistir('ders', this.value)">
            <option value="">Tümü</option>
            ${DERSLER.map(x => `<option value="${x.id}" ${f.ders === x.id ? "selected" : ""}>${esc(x.ad)}</option>`).join("")}
          </select></div>
        <div><label class="ayar-baslik">İçerik Tipi</label>
          <select class="genis-input" onchange="icerikListesiFiltreDegistir('tip', this.value)">
            <option value="">Tümü</option>
            ${ICERIK_TIPLERI.map(t => `<option value="${t}" ${f.tip === t ? "selected" : ""}>${t}</option>`).join("")}
          </select></div>
        <div><label class="ayar-baslik">Durum</label>
          <select class="genis-input" onchange="icerikListesiFiltreDegistir('durum', this.value)">
            <option value="">Tümü</option>
            ${ICERIK_DURUMLARI.map(([v, ad]) => `<option value="${v}" ${f.durum === v ? "selected" : ""}>${ad}</option>`).join("")}
          </select></div>
        <div class="grow"><label class="ayar-baslik">Ara</label>
          <input class="genis-input" value="${esc(f.arama)}" placeholder="Başlığa göre ara…"
            oninput="icerikListesiFiltreDegistir('arama', this.value)"></div>
      </div>
    </div>
    <div class="row between mb">
      <h2 class="section" style="margin:0">İçerikler (${gorunenler.length}/${tumSayfalar.length})</h2>
      <button class="btn btn-primary" onclick="git('yonetim/sayfaekle')">+ Yeni İçerik</button>
    </div>
    ${satirlar || `<div class="empty"><div class="icon">📄</div>Kriterlere uyan içerik bulunamadı.</div>`}`;
}
function icerikListesiFiltreDegistir(alan, deger) { YONETIM_ICERIK_FILTRE[alan] = deger; yonlendir(); }
function icerikListesiDuzenleGonder(dersId, konuId, idx) {
  YONETIM_OZET_FORM_ACIK = idx;
  git(`yonetim/icerik/${dersId}/${konuId}`);
}
function icerikListesiOnizleGonder(dersId, konuId, idx) { git(`ozetoku/${dersId}/${konuId}/${idx}`); }
function icerikListesiKopyalaGonder(konuId, idx) {
  const k = iyKonuBul(konuId);
  if (!k || !k.ozetSayfalari[idx]) return;
  const kopya = JSON.parse(JSON.stringify(k.ozetSayfalari[idx]));
  kopya.baslik = (kopya.baslik || "") + " (kopya)";
  kopya.durum = "taslak";
  iyOzetSayfaEkle(konuId, kopya);
  yonlendir();
}
function icerikListesiSilGonder(konuId, idx) {
  if (!confirm("Bu içeriği silmek istediğinize emin misiniz?")) return;
  iyOzetSayfaSil(konuId, idx);
  yonlendir();
}

/* ---------------------------------------------------------
   19b3. YÖNETİM — SORU BANKASI (merkezi liste — mevcut soru
   sistemini değiştirmez, yalnızca üzerinde filtrelenebilir bir
   yönetim ekranı sağlar)
   --------------------------------------------------------- */
let YONETIM_SORU_FILTRE = { ders: "", konu: "", arama: "" };

function yonetimSoruBankasiHtml(dersId, konuId) {
  const f = YONETIM_SORU_FILTRE;
  if (dersId && !f.ders) f.ders = dersId;
  const tumSorular = [];
  DERSLER.forEach(d => (d.konular || []).forEach(k => sorulariGetir(d.id, k.id).forEach(s => tumSorular.push({ d, k, s }))));
  const arama = (f.arama || "").trim().toLowerCase();
  const gorunenler = tumSorular.filter(x =>
    (!f.ders || x.d.id === f.ders) && (!f.konu || x.k.id === f.konu) &&
    (!arama || (x.s.s || "").toLowerCase().includes(arama)));
  const konularSecenek = f.ders ? (DERSLER.find(d => d.id === f.ders) || {}).konular || [] : [];

  const satirlar = gorunenler.map(x => `
    <div class="icerik-satir">
      <div class="grow">
        <strong>${esc((x.s.s || "").slice(0, 90))}${(x.s.s || "").length > 90 ? "…" : ""}</strong>
        <div class="muted">${esc(x.d.ad)} · ${esc(x.k.ad)} ·
          ${x.s.tip === "bilgi_karti" ? "Bilgi Kartı" : "Çoktan Seçmeli"} · Yıl: — · Zorluk: — · Durum: —</div>
      </div>
      <button class="btn btn-sm" onclick="git('yonetim/icerik/${x.d.id}/${x.k.id}')">Konuya Git</button>
      <button class="btn btn-sm tehlike" onclick="yonetimSoruSilGonder('${x.d.id}','${x.k.id}','${x.s.id}')">Sil</button>
    </div>`).join("");

  return `
    <div class="card mb">
      <div class="row wrap" style="gap:10px">
        <div><label class="ayar-baslik">Ders</label>
          <select class="genis-input" onchange="yonetimSoruFiltreDegistir('ders', this.value)">
            <option value="">Tümü</option>
            ${DERSLER.map(x => `<option value="${x.id}" ${f.ders === x.id ? "selected" : ""}>${esc(x.ad)}</option>`).join("")}
          </select></div>
        <div><label class="ayar-baslik">Konu</label>
          <select class="genis-input" onchange="yonetimSoruFiltreDegistir('konu', this.value)" ${!f.ders ? "disabled" : ""}>
            <option value="">Tümü</option>
            ${konularSecenek.map(x => `<option value="${x.id}" ${f.konu === x.id ? "selected" : ""}>${esc(x.ad)}</option>`).join("")}
          </select></div>
        <div class="grow"><label class="ayar-baslik">Ara</label>
          <input class="genis-input" value="${esc(f.arama)}" placeholder="Soru metnine göre ara…"
            oninput="yonetimSoruFiltreDegistir('arama', this.value)"></div>
      </div>
    </div>
    <div class="row between mb">
      <h2 class="section" style="margin:0">Sorular (${gorunenler.length}/${tumSorular.length})</h2>
      <button class="btn btn-primary" onclick="yonetimHizliSoruEkle()">+ Yeni Soru</button>
    </div>
    ${satirlar || `<div class="empty"><div class="icon">❓</div>Kriterlere uyan soru bulunamadı.</div>`}`;
}
function yonetimSoruFiltreDegistir(alan, deger) {
  YONETIM_SORU_FILTRE[alan] = deger;
  if (alan === "ders") YONETIM_SORU_FILTRE.konu = "";
  yonlendir();
}
function yonetimSoruSilGonder(dersId, konuId, soruId) {
  if (!confirm("Bu soruyu silmek istediğinize emin misiniz?")) return;
  iySoruSil(dersId, konuId, soruId);
  yonlendir();
}

/* ---------------------------------------------------------
   19b4. YÖNETİM — PDF / MATERYALLER (merkezi liste)
   --------------------------------------------------------- */
let YONETIM_MATERYAL_FILTRE = { ders: "", arama: "" };

function yonetimMateryallerHtml(dersId) {
  const f = YONETIM_MATERYAL_FILTRE;
  if (dersId && !f.ders) f.ders = dersId;
  const tumMateryaller = [];
  DERSLER.forEach(d => (d.konular || []).forEach(k => (k.materyaller || []).forEach(m => tumMateryaller.push({ d, k, m }))));
  const arama = (f.arama || "").trim().toLowerCase();
  const gorunenler = tumMateryaller.filter(x =>
    (!f.ders || x.d.id === f.ders) && (!arama || (x.m.ad || "").toLowerCase().includes(arama)));

  const satirlar = gorunenler.map(x => `
    <div class="icerik-satir">
      <div class="grow">
        <strong>${esc(x.m.ad)}</strong>
        <div class="muted">${esc(x.d.ad)} · ${esc(x.k.ad)} · ${(x.m.tip || "pdf").toUpperCase()} ·
          ${x.m.toplamSayfa} sayfa</div>
      </div>
      <button class="btn btn-sm" onclick="git('yonetim/icerik/${x.d.id}/${x.k.id}')">İncele</button>
      <button class="btn btn-sm tehlike" onclick="yonetimMateryalSilGonder('${x.k.id}','${x.m.id}')">Sil</button>
    </div>`).join("");

  return `
    <div class="card mb">
      <div class="row wrap" style="gap:10px">
        <div><label class="ayar-baslik">Ders</label>
          <select class="genis-input" onchange="yonetimMateryalFiltreDegistir('ders', this.value)">
            <option value="">Tümü</option>
            ${DERSLER.map(x => `<option value="${x.id}" ${f.ders === x.id ? "selected" : ""}>${esc(x.ad)}</option>`).join("")}
          </select></div>
        <div class="grow"><label class="ayar-baslik">Ara</label>
          <input class="genis-input" value="${esc(f.arama)}" placeholder="Dosya adına göre ara…"
            oninput="yonetimMateryalFiltreDegistir('arama', this.value)"></div>
      </div>
    </div>
    <div class="row between mb">
      <h2 class="section" style="margin:0">Materyaller (${gorunenler.length}/${tumMateryaller.length})</h2>
      <button class="btn btn-primary" onclick="yonetimHizliPdfYukle()">PDF Yükle</button>
    </div>
    ${satirlar || `<div class="empty"><div class="icon">🖥️</div>Kriterlere uyan materyal bulunamadı.</div>`}`;
}
function yonetimMateryalFiltreDegistir(alan, deger) { YONETIM_MATERYAL_FILTRE[alan] = deger; yonlendir(); }
function yonetimMateryalSilGonder(konuId, materyalId) {
  if (!confirm("Bu materyali silmek istediğinize emin misiniz?")) return;
  iyMateryalSil(konuId, materyalId);
  yonlendir();
}

/* ---------------------------------------------------------
   19b5. YÖNETİM — MEDYA KÜTÜPHANESİ (görseller — konu görselleri
   + özet sayfalarındaki görseller, salt merkezi görünüm)
   --------------------------------------------------------- */
function yonetimMedyaHtml() {
  const dogrudan = [];
  const gomulu = [];
  DERSLER.forEach(d => (d.konular || []).forEach(k => {
    (k.gorseller || []).forEach(g => dogrudan.push({ d, k, g }));
    (k.ozetSayfalari || []).forEach(s => (s.gorseller || []).forEach(g => gomulu.push({ d, k, s, g })));
  }));

  const satir = (x, silinebilir) => `
    <div class="icerik-satir">
      <img src="${x.g.dosya}" style="width:44px;height:44px;object-fit:cover;border-radius:6px">
      <div class="grow">
        <strong>${esc(x.g.baslik || "(başlıksız)")}</strong>
        <div class="muted">${esc(x.d.ad)} · ${esc(x.k.ad)}${x.s ? " · " + esc(x.s.baslik || "özet sayfası") : ""}</div>
      </div>
      ${silinebilir ? `<button class="btn btn-sm tehlike" onclick="yonetimGorselSilGonder('${x.k.id}','${x.g.id}')">Sil</button>`
        : `<span class="badge badge-gray">İçerikte kullanılıyor</span>`}
    </div>`;

  return `
    <div class="row between mb">
      <h2 class="section" style="margin:0">Medya Kütüphanesi (${dogrudan.length + gomulu.length})</h2>
    </div>
    ${dogrudan.length || gomulu.length
      ? dogrudan.map(x => satir(x, true)).join("") + gomulu.map(x => satir(x, false)).join("")
      : `<div class="empty"><div class="icon">🖼️</div>Henüz görsel yüklenmedi.</div>`}`;
}
function yonetimGorselSilGonder(konuId, gorselId) {
  if (!confirm("Bu görseli silmek istediğinize emin misiniz?")) return;
  iyGorselSil(konuId, gorselId);
  yonlendir();
}

/* --- Materyal (PDF) --- */
function materyalYonetimHtml(k) {
  const satirlar = (k.materyaller || []).map(m => `
    <div class="icerik-satir" style="align-items:flex-start">
      <span style="font-size:19px">📽️</span>
      <div class="grow">
        <div class="row between">
          <div><strong>${esc(m.ad)}</strong><div class="muted">${m.toplamSayfa} sayfa</div></div>
          <button class="btn btn-sm tehlike" onclick="materyalSilOnayla('${k.id}','${m.id}')">Sil</button>
        </div>
        <div class="mt" style="font-size:12.5px">
          <label class="ayar-baslik">Bölümler (sayfa aralığı → alt konu)</label>
          ${(m.bolumler || []).length ? (m.bolumler || []).map((b, i) => `
            <div class="row between" style="padding:3px 0">
              <span>${esc(b.ad)} <span class="muted">(sayfa ${b.bas}–${b.bit})</span></span>
              <button class="btn btn-sm tehlike" onclick="materyalBolumSilOnayla('${k.id}','${m.id}',${i})">Sil</button>
            </div>`).join("") : `<div class="muted">Henüz bölüm tanımlanmadı — PDF'in tamamı tek parça görünür.</div>`}
          <div class="row wrap mt" style="gap:6px">
            <input class="genis-input" id="bolumAd_${m.id}" placeholder="Bölüm adı (örn. Giriş)" style="flex:1;min-width:140px">
            <input type="number" min="1" max="${m.toplamSayfa}" id="bolumBas_${m.id}" placeholder="Baş." style="width:64px">
            <input type="number" min="1" max="${m.toplamSayfa}" id="bolumBit_${m.id}" placeholder="Bit." style="width:64px">
            <button class="btn btn-sm" onclick="materyalBolumEkleGonder('${k.id}','${m.id}')">Bölüm Ekle</button>
          </div>
        </div>
      </div>
    </div>`).join("");
  return `
    <h2 class="section">🖥️ Materyaller (PDF)</h2>
    <div class="card mb">
      <label class="ayar-baslik">Başlık</label>
      <input class="genis-input" id="materyalAd" placeholder="Örn. ${esc(k.ad)} Sunumu">
      <label class="ayar-baslik mt">Toplam sayfa sayısı</label>
      <input class="genis-input" id="materyalSayfa" type="number" min="1" value="1" style="max-width:140px">
      <label class="ayar-baslik mt">Kaynak</label>
      <div class="sekme">
        <button class="sekme-btn aktif" type="button" onclick="materyalKaynakSec(this,'yukle')">Dosya Yükle</button>
        <button class="sekme-btn" type="button" onclick="materyalKaynakSec(this,'yol')">Dosya Yolu Gir</button>
      </div>
      <div id="materyalKaynakYukle" class="mt">
        <input type="file" accept="application/pdf" onchange="iyDosyaYukle(this, url => document.getElementById('materyalDosyaDeger').value = url, document.getElementById('materyalYuklemeDurum'))">
        <div class="muted mt" id="materyalYuklemeDurum"></div>
      </div>
      <div id="materyalKaynakYol" class="mt" style="display:none">
        <input class="genis-input" id="materyalYolGirdi" placeholder="materyaller/${k.id}.pdf"
               oninput="document.getElementById('materyalDosyaDeger').value=this.value">
        <div class="muted mt">Büyük PDF'ler için: dosyayı <code>materyaller/</code> klasörüne koyup yolunu yazın.</div>
      </div>
      <input type="hidden" id="materyalDosyaDeger">
      <button class="btn btn-primary mt" onclick="materyalEkleGonder('${k.id}')">Materyal Ekle</button>
      <div class="hata" id="materyalHata"></div>
    </div>
    ${satirlar || `<div class="empty mb"><div class="icon">🖥️</div>Henüz materyal eklenmedi.</div>`}`;
}
function materyalKaynakSec(btn, mod) {
  btn.parentElement.querySelectorAll(".sekme-btn").forEach(b => b.classList.remove("aktif"));
  btn.classList.add("aktif");
  $("#materyalKaynakYukle").style.display = mod === "yukle" ? "" : "none";
  $("#materyalKaynakYol").style.display = mod === "yol" ? "" : "none";
}
function materyalEkleGonder(konuId) {
  const ad = $("#materyalAd").value.trim();
  const dosya = $("#materyalDosyaDeger").value;
  const toplamSayfa = $("#materyalSayfa").value;
  if (!ad || !dosya) { $("#materyalHata").textContent = "Başlık ve dosya (yükleme veya yol) zorunludur."; return; }
  iyMateryalEkle(konuId, { ad, dosya, toplamSayfa });
  icerikYenile(DERSLER.find(d => d.konular.some(x => x.id === konuId)).id, konuId);
}
function materyalSilOnayla(konuId, materyalId) {
  if (!confirm("Bu materyali silmek istediğinize emin misiniz?")) return;
  iyMateryalSil(konuId, materyalId);
  icerikYenile(DERSLER.find(d => d.konular.some(x => x.id === konuId)).id, konuId);
}
function materyalBolumEkleGonder(konuId, materyalId) {
  const ad = $("#bolumAd_" + materyalId).value.trim();
  const bas = +$("#bolumBas_" + materyalId).value;
  const bit = +$("#bolumBit_" + materyalId).value;
  if (!ad || !bas || !bit || bas > bit) {
    alert("Bölüm adı ve geçerli bir sayfa aralığı (başlangıç sayfası bitiş sayfasından büyük olamaz) girin.");
    return;
  }
  iyMateryalBolumEkle(konuId, materyalId, { ad, bas, bit });
  icerikYenile(DERSLER.find(d => d.konular.some(x => x.id === konuId)).id, konuId);
}
function materyalBolumSilOnayla(konuId, materyalId, idx) {
  if (!confirm("Bu bölümü silmek istediğinize emin misiniz?")) return;
  iyMateryalBolumSil(konuId, materyalId, idx);
  icerikYenile(DERSLER.find(d => d.konular.some(x => x.id === konuId)).id, konuId);
}

/* --- Özet (çok sayfalı ders notu) --- */
let YONETIM_OZET_FORM_ACIK = null;
function ozetYonetimHtml(k, dersId) {
  const satirlar = (k.ozetSayfalari || []).map((s, i) => YONETIM_OZET_FORM_ACIK === i ? ozetYonetimForm(k, i, s) : `
    <div class="icerik-satir">
      <span class="badge badge-gray">${i + 1}</span>
      <div class="grow"><strong>${esc(s.baslik || "(başlıksız)")}</strong>
        <div class="muted">${esc(s.tip || "Özet")} · ${icerikDurumBadge(s.durum)} ·
        ${esc(ozetDuzMetin(s.icerik).slice(0, 70))}${ozetDuzMetin(s.icerik).length > 70 ? "…" : ""}
        ${(s.gorseller || []).length ? ` · 🖼️ ${s.gorseller.length} görsel` : ""}</div></div>
      <button class="btn btn-sm" onclick="ozetTasiGonder('${k.id}',${i},-1)" ${i === 0 ? "disabled" : ""}>↑</button>
      <button class="btn btn-sm" onclick="ozetTasiGonder('${k.id}',${i},1)" ${i === k.ozetSayfalari.length - 1 ? "disabled" : ""}>↓</button>
      <button class="btn btn-sm" onclick="ozetDuzenleAc('${k.id}',${i})">Düzenle</button>
      <button class="btn btn-sm tehlike" onclick="ozetSilOnayla('${k.id}',${i})">Sil</button>
    </div>`).join("");

  return `
    <h2 class="section">📄 Sayfalar</h2>
    <div class="card mb">
      <button class="btn btn-primary" onclick="git('yonetim/sayfaekle/${dersId}/${k.id}')">+ Sayfa Ekle</button>
    </div>
    ${satirlar || `<div class="empty mb"><div class="icon">📄</div>Henüz sayfa eklenmedi.</div>`}`;
}
let YONETIM_OZET_GORSEL_ANAHTAR = null;
let YONETIM_OZET_GORSEL_LISTE = [];
const OZET_DUZEN_SECENEKLERI = [
  ["metin", "Sadece Metin", "📝"],
  ["gorsel-ust", "Görsel Üstte", "🖼️"],
  ["gorsel-sag", "Görsel Sağda", "➡️"],
  ["gorsel-sol", "Görsel Solda", "⬅️"],
  ["sadece-gorsel", "Sadece Görsel", "🌆"],
  ["iki-sutun", "İki Sütun", "▥"]
];
function ozetYonetimForm(k, idx, s) {
  const id = idx === null ? "yeni" : idx;
  const editorId = `ozetIcerikEditor_${id}`;
  const anahtar = k.id + "|" + id;
  if (YONETIM_OZET_GORSEL_ANAHTAR !== anahtar) {
    YONETIM_OZET_GORSEL_ANAHTAR = anahtar;
    YONETIM_OZET_GORSEL_LISTE = (s.gorseller || []).slice();
  }
  const seciliDuzen = s.duzen || "gorsel-ust";
  const dersBul = DERSLER.find(d => d.konular.some(x => x.id === k.id));
  window.__pdfYzBaglam = { dersAd: dersBul ? dersBul.ad : "", konuAd: k.ad };
  return `
    <div class="card mb">
      <h3 class="mb">🤖 PDF'den Yapay Zekâ ile Oluştur</h3>
      ${!pdfYzSaglayiciVarMi() ? `<div class="notice uyari-notice">Bunun için bir yapay zekâ sağlayıcısı
        (ChatGPT/Claude/Gemini) bağlı olmalı — Üyelik ve Ayarlar sayfasından kendi API anahtarınızı girin.</div>` : `
        <p class="muted mb" style="font-size:12.5px">Bir PDF seçip metnini çıkarın, ardından yapay zekâ ile
          bu metni düzenli bir çalışma sayfasına dönüştürün. Sonuç aşağıdaki içerik alanına yazılır —
          kaydetmeden önce gözden geçirip düzenleyebilirsiniz.</p>
        <input type="file" accept="application/pdf" id="pdfYzDosya_${id}">
        <button class="btn mt" onclick="pdfYzMetinCikarGonder('${id}')">Metni Çıkar</button>
        <div id="pdfYzDurum_${id}" class="muted mt"></div>
        <div id="pdfYzAralikAlan_${id}"></div>`}
    </div>
    <div class="card mb" style="border-color:var(--brand-light)">
      <label class="ayar-baslik">Sayfa başlığı</label>
      <input class="genis-input" id="ozetBaslik" value="${esc(s.baslik)}" placeholder="Örn. 1. Kapsam ve Uygulama Alanı">
      <div class="row wrap mt" style="gap:10px">
        <div><label class="ayar-baslik">İçerik türü</label>
          <select class="genis-input" id="ozetTip_${id}">
            ${ICERIK_TIPLERI.map(t => `<option value="${t}" ${(s.tip || "Özet") === t ? "selected" : ""}>${t}</option>`).join("")}
          </select></div>
        <div><label class="ayar-baslik">Öncelik</label>
          <select class="genis-input" id="ozetOncelik_${id}">
            <option value="A" ${s.oncelik === "A" ? "selected" : ""}>A</option>
            <option value="B" ${!s.oncelik || s.oncelik === "B" ? "selected" : ""}>B</option>
            <option value="C" ${s.oncelik === "C" ? "selected" : ""}>C</option>
          </select></div>
        <div><label class="ayar-baslik">Durum</label>
          <select class="genis-input" id="ozetDurum_${id}">
            ${ICERIK_DURUMLARI.map(([v, ad]) => `<option value="${v}" ${(s.durum || "yayinda") === v ? "selected" : ""}>${ad}</option>`).join("")}
          </select></div>
      </div>
      <label class="ayar-baslik mt">İçerik</label>
      ${editorHtml(editorId, s.icerik)}
      <label class="ayar-baslik mt">Görseller (birden çok ekleyebilirsiniz)</label>
      <input type="file" accept="image/*" onchange="ozetGorselEkleGonder(this,'${k.id}',${idx === null ? "null" : idx})">
      <div class="muted mt" id="ozetGorselYuklemeDurum"></div>
      <div id="ozetGorselGaleri" class="ozet-gorsel-onizle-liste mt">${ozetGorselGaleriHtml()}</div>
      <label class="ayar-baslik mt">Düzen (PowerPoint'teki "slayt düzeni" gibi)</label>
      <div class="saglayici-liste" id="ozetDuzenListe_${id}">
        ${OZET_DUZEN_SECENEKLERI.map(([val, ad, ikon]) => `
          <div class="saglayici ${seciliDuzen === val ? "secili" : ""}" onclick="ozetDuzenSec(this,'${id}','${val}')">
            <strong>${ikon} ${ad}</strong></div>`).join("")}
      </div>
      <input type="hidden" id="ozetDuzenDeger_${id}" value="${seciliDuzen}">
      <div class="row mt">
        <button class="btn btn-primary" onclick="ozetKaydetGonder('${k.id}',${idx === null ? "null" : idx},'${editorId}','${id}')">Kaydet</button>
        <button class="btn" onclick="ozetDuzenleKapat('${k.id}')">Vazgeç</button>
      </div>
    </div>`;
}
let PDF_YZ_CIKARILAN = {};
function pdfYzSaglayiciVarMi() {
  const ay = Ayar.oku();
  if (!["openai", "anthropic", "gemini"].includes(ay.aiSaglayici)) return false;
  if (!ppBagliMi()) return false;
  aiSirDurumYukle();
  return !!(AI_SIR_DURUM && AI_SIR_DURUM[ay.aiSaglayici] && AI_SIR_DURUM[ay.aiSaglayici].tanimli);
}
async function pdfYzMetinCikarGonder(id) {
  const dosyaEl = $("#pdfYzDosya_" + id);
  const durumEl = $("#pdfYzDurum_" + id);
  const dosya = dosyaEl.files[0];
  if (!dosya) { durumEl.textContent = "Önce bir PDF dosyası seçin."; return; }
  durumEl.textContent = "Metin çıkarılıyor…";
  try {
    const sonuc = await pdfMetinCikar(dosya, (i, n) => { durumEl.textContent = `Sayfa ${i}/${n} okunuyor…`; });
    PDF_YZ_CIKARILAN[id] = sonuc;
    durumEl.textContent = `${sonuc.toplamSayfa} sayfa okundu.`;
    const alan = $("#pdfYzAralikAlan_" + id);
    alan.innerHTML = `
      <div class="row mt" style="gap:8px;align-items:flex-end">
        <div>
          <label class="ayar-baslik">Baş. sayfa</label>
          <input type="number" min="1" max="${sonuc.toplamSayfa}" value="1" id="pdfYzBas_${id}" style="width:90px">
        </div>
        <div>
          <label class="ayar-baslik">Bit. sayfa</label>
          <input type="number" min="1" max="${sonuc.toplamSayfa}" value="${sonuc.toplamSayfa}" id="pdfYzBit_${id}" style="width:90px">
        </div>
        <button class="btn btn-primary" onclick="pdfYzOzetleGonder('${id}')">Yapay Zekâ ile Özetle</button>
      </div>`;
  } catch (e) {
    durumEl.textContent = "Hata: " + e.message;
  }
}
async function pdfYzOzetleGonder(id) {
  const durumEl = $("#pdfYzDurum_" + id);
  const veri = PDF_YZ_CIKARILAN[id];
  if (!veri) { durumEl.textContent = "Önce PDF metnini çıkarın."; return; }
  const basEl = $("#pdfYzBas_" + id), bitEl = $("#pdfYzBit_" + id);
  const bas = basEl ? +basEl.value || 1 : 1;
  const bit = bitEl ? +bitEl.value || veri.toplamSayfa : veri.toplamSayfa;
  const { metin, kesildi } = pdfMetniBirlestir(veri.sayfaMetinleri, bas, bit);
  if (!metin) { durumEl.textContent = "Seçilen sayfa aralığında metin bulunamadı."; return; }
  durumEl.textContent = "Yapay zekâ metni düzenliyor, lütfen bekleyin…";
  try {
    const ay = Ayar.oku();
    const baglam = window.__pdfYzBaglam || {};
    const sistemMetni = pdfYzSistemTalimati(baglam.dersAd, baglam.konuAd);
    const cevap = await pdfYzOzetIste(ay.aiSaglayici, sistemMetni, metin);
    const html = pdfYzHtmlTemizle(cevap);
    const editorEl = document.getElementById("ozetIcerikEditor_" + id);
    if (editorEl) editorEl.innerHTML = html;
    const baslikEl = $("#ozetBaslik");
    if (baslikEl && !baslikEl.value.trim() && baglam.konuAd) baslikEl.value = baglam.konuAd;
    durumEl.textContent = "Hazır — içeriği gözden geçirip Kaydet'e basın."
      + (kesildi ? " (Metin uzun olduğu için kısaltıldı, gerekirse sayfa aralığını daraltın.)" : "");
  } catch (e) {
    durumEl.textContent = "Hata: " + e.message;
  }
}
function ozetDuzenSec(el, id, val) {
  $("#ozetDuzenDeger_" + id).value = val;
  el.parentElement.querySelectorAll(".saglayici").forEach(x => x.classList.remove("secili"));
  el.classList.add("secili");
}
function ozetGorselGaleriHtml() {
  if (!YONETIM_OZET_GORSEL_LISTE.length) return `<div class="muted" style="font-size:12px">Henüz görsel eklenmedi.</div>`;
  return YONETIM_OZET_GORSEL_LISTE.map(g => `
    <div class="ozet-gorsel-onizle">
      <img src="${g.dosya}" alt="">
      <button type="button" class="btn btn-sm tehlike" onclick="ozetGorselSilGonder('${g.id}')">Kaldır</button>
    </div>`).join("");
}
function ozetGorselEkleGonder(inputEl, konuId, idx) {
  iyDosyaYukle(inputEl, (url, adi) => {
    YONETIM_OZET_GORSEL_LISTE.push({ id: iyBenzersizId("gorsel"), dosya: url, baslik: adi || "" });
    const alan = $("#ozetGorselGaleri");
    if (alan) alan.innerHTML = ozetGorselGaleriHtml();
  }, $("#ozetGorselYuklemeDurum"));
}
function ozetGorselSilGonder(gorselId) {
  YONETIM_OZET_GORSEL_LISTE = YONETIM_OZET_GORSEL_LISTE.filter(g => g.id !== gorselId);
  const alan = $("#ozetGorselGaleri");
  if (alan) alan.innerHTML = ozetGorselGaleriHtml();
}
function ozetDuzenleAc(konuId, idx) { YONETIM_OZET_FORM_ACIK = idx; icerikYenileKonu(konuId); }
function ozetDuzenleKapat(konuId) { YONETIM_OZET_FORM_ACIK = null; YONETIM_OZET_GORSEL_ANAHTAR = null; icerikYenileKonu(konuId); }
function ozetKaydetGonder(konuId, idx, editorId, duzenId) {
  const duzenEl = $("#ozetDuzenDeger_" + duzenId);
  const tipEl = $("#ozetTip_" + duzenId), oncelikEl = $("#ozetOncelik_" + duzenId), durumEl = $("#ozetDurum_" + duzenId);
  const sayfa = {
    baslik: $("#ozetBaslik").value.trim(), icerik: editorIcerikAl(editorId),
    gorseller: YONETIM_OZET_GORSEL_LISTE, duzen: duzenEl ? duzenEl.value : "gorsel-ust",
    tip: tipEl ? tipEl.value : "Özet", oncelik: oncelikEl ? oncelikEl.value : "B",
    durum: durumEl ? durumEl.value : "yayinda"
  };
  if (idx === null) iyOzetSayfaEkle(konuId, sayfa); else iyOzetSayfaGuncelle(konuId, idx, sayfa);
  YONETIM_OZET_FORM_ACIK = null;
  YONETIM_OZET_GORSEL_ANAHTAR = null;
  icerikYenileKonu(konuId);
}
function ozetSilOnayla(konuId, idx) {
  if (!confirm("Bu özet sayfasını silmek istediğinize emin misiniz?")) return;
  iyOzetSayfaSil(konuId, idx);
  icerikYenileKonu(konuId);
}
function ozetTasiGonder(konuId, idx, yon) { iyOzetSayfaTasi(konuId, idx, yon); icerikYenileKonu(konuId); }
function icerikYenileKonu(konuId) {
  icerikYenile(DERSLER.find(d => d.konular.some(x => x.id === konuId)).id, konuId);
}

/* ---------------------------------------------------------
   19b2. YÖNETİM — SAYFA EKLE (sadeleştirilmiş tek akış)
   ---------------------------------------------------------
   Tek ekranda ders/konu seçilir, ardından iki basit yol vardır:
   PDF (her PDF sayfası otomatik görsele çevrilip ayrı bir sayfa
   olarak eklenir) veya Yazı (mevcut zengin metin editörü). Her
   ikisi de mevcut ozetSayfalari veri modeline yazar — ayrıca bir
   "yayınla" adımı yok, kaydedilince otomatik sunucuya senkronlanır. */
let SAYFA_EKLE_MOD = "pdf";
function sayfaEkleModSec(mod) { SAYFA_EKLE_MOD = mod; yonlendir(); }

function sayfaEkleHtml(dersId, konuId) {
  const d = DERSLER.find(x => x.id === dersId) || DERSLER[0];
  if (!d) return `<div class="empty"><div class="icon">📚</div>Önce bir ders ekleyin.</div>`;
  const k = (konuId && konuBul(d.id, konuId)) || d.konular[0];
  if (!k) return `<div class="empty"><div class="icon">📂</div>Bu derste henüz konu yok. Önce
    <a onclick="git('yonetim/konular/${d.id}')" style="cursor:pointer">konu ekleyin</a>.</div>`;

  return `
    <div class="page-title" style="font-size:20px">Yeni İçerik / Özet Sayfası</div>
    <div class="card mb">
      <label class="ayar-baslik">Ders</label>
      <select class="genis-input" onchange="git('yonetim/sayfaekle/' + this.value)">
        ${DERSLER.map(x => `<option value="${x.id}" ${x.id === d.id ? "selected" : ""}>${x.ikon} ${esc(x.ad)}</option>`).join("")}
      </select>
      <label class="ayar-baslik mt">Konu</label>
      <select class="genis-input" onchange="git('yonetim/sayfaekle/${d.id}/' + this.value)">
        ${d.konular.map(x => `<option value="${x.id}" ${x.id === k.id ? "selected" : ""}>${esc(x.ad)}</option>`).join("")}
      </select>
    </div>

    <div class="sekme mb">
      <button class="sekme-btn ${SAYFA_EKLE_MOD === "pdf" ? "aktif" : ""}" onclick="sayfaEkleModSec('pdf')">PDF</button>
      <button class="sekme-btn ${SAYFA_EKLE_MOD === "pptx" ? "aktif" : ""}" onclick="sayfaEkleModSec('pptx')">PowerPoint</button>
      <button class="sekme-btn ${SAYFA_EKLE_MOD === "yazi" ? "aktif" : ""}" onclick="sayfaEkleModSec('yazi')">Yazı</button>
    </div>

    ${SAYFA_EKLE_MOD === "pdf" ? sayfaEklePdfFormu(k.id)
      : SAYFA_EKLE_MOD === "pptx" ? sayfaEklePptxFormu(k.id)
      : sayfaEkleYaziFormu(k.id, d.id)}`;
}

function sayfaEklePdfFormu(konuId) {
  return `
    <div class="card">
      <p class="muted mb">Bir PDF seçin — kaç sayfaysa, her biri ayrı bir sayfa olacak şekilde,
        tüm görselleriyle birlikte resim olarak eklenir.</p>
      <input type="file" accept="application/pdf" id="sayfaEklePdfDosya">
      <button class="btn btn-primary mt" onclick="sayfaEklePdfGonder('${konuId}')">Sayfaları Ekle</button>
      <div class="muted mt" id="sayfaEkleDurum"></div>
    </div>`;
}

function sayfaEkleYaziFormu(konuId, dersId) {
  const editorId = "sayfaEkleEditor";
  return `
    <div class="card">
      <label class="ayar-baslik">Sayfa başlığı</label>
      <input class="genis-input" id="sayfaEkleBaslik" placeholder="Örn. 1. Kapsam ve Uygulama Alanı">
      <div class="row wrap mt" style="gap:10px">
        <div><label class="ayar-baslik">İçerik türü</label>
          <select class="genis-input" id="sayfaEkleTip">
            ${ICERIK_TIPLERI.map(t => `<option value="${t}" ${t === "Özet" ? "selected" : ""}>${t}</option>`).join("")}
          </select></div>
        <div><label class="ayar-baslik">Öncelik</label>
          <select class="genis-input" id="sayfaEkleOncelik">
            <option value="A">A</option><option value="B" selected>B</option><option value="C">C</option>
          </select></div>
        <div><label class="ayar-baslik">Durum</label>
          <select class="genis-input" id="sayfaEkleDurumSecim">
            ${ICERIK_DURUMLARI.map(([v, ad]) => `<option value="${v}" ${v === "taslak" ? "selected" : ""}>${ad}</option>`).join("")}
          </select></div>
      </div>
      <label class="ayar-baslik mt">İçerik</label>
      ${editorHtml(editorId, "")}
      <div class="row mt wrap" style="gap:8px">
        <button class="btn btn-primary" onclick="sayfaEkleYaziGonder('${konuId}')">Kaydet</button>
        <button class="btn" onclick="sayfaEkleYaziGonder('${konuId}','taslak')">Taslak Kaydet</button>
        <button class="btn" onclick="sayfaEkleYaziGonder('${konuId}',null,true,'${dersId}')">Önizle</button>
        <button class="btn" onclick="sayfaEkleYaziGonder('${konuId}','yayinda')">Yayınla</button>
      </div>
    </div>`;
}

async function sayfaEklePdfGonder(konuId) {
  const input = $("#sayfaEklePdfDosya");
  const f = input && input.files && input.files[0];
  const durum = $("#sayfaEkleDurum");
  if (!f) { if (durum) durum.textContent = "Lütfen bir PDF seçin."; return; }
  if (typeof ppBagliMi !== "function" || !ppBagliMi()) {
    alert("Sayfa eklemek için önce Üyelik ve Ayarlar sayfasından sunucu bağlantısını kurmanız gerekir.");
    return;
  }
  if (typeof pdfjsLib === "undefined") { if (durum) durum.textContent = "PDF kütüphanesi yüklenemedi, internet bağlantınızı kontrol edin."; return; }

  try {
    const arrayBuffer = await f.arrayBuffer();
    const belge = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
    const temelAd = f.name.replace(/\.pdf$/i, "") || "sayfa";
    for (let i = 1; i <= belge.numPages; i++) {
      if (durum) durum.textContent = `Sayfa ${i}/${belge.numPages} işleniyor…`;
      const sayfa = await belge.getPage(i);
      const viewport = sayfa.getViewport({ scale: 1.6 });
      const canvas = document.createElement("canvas");
      canvas.width = viewport.width; canvas.height = viewport.height;
      await sayfa.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
      const blob = await new Promise(res => canvas.toBlob(res, "image/png"));
      const gorselDosya = new File([blob], `${temelAd}-${i}.png`, { type: "image/png" });
      const sonuc = await kuDosyaYukle(gorselDosya);
      iyOzetSayfaEkle(konuId, {
        baslik: `Sayfa ${i}`, icerik: "",
        gorseller: [{ id: iyBenzersizId("gorsel"), dosya: sonuc.yol, baslik: "" }],
        duzen: "sadece-gorsel"
      });
    }
    if (durum) durum.textContent = `✅ ${belge.numPages} sayfa eklendi.`;
    setTimeout(() => icerikYenileKonu(konuId), 900);
  } catch (e) {
    if (durum) durum.textContent = "";
    alert("PDF işlenemedi: " + e.message);
  }
}

function sayfaEkleYaziGonder(konuId, durumOverride, onizle, dersId) {
  const baslik = $("#sayfaEkleBaslik").value.trim();
  const icerik = editorIcerikAl("sayfaEkleEditor");
  if (!baslik && !icerik) { alert("Başlık veya içerik girin."); return; }
  const tip = ($("#sayfaEkleTip") || {}).value || "Özet";
  const oncelik = ($("#sayfaEkleOncelik") || {}).value || "B";
  const durum = durumOverride || ($("#sayfaEkleDurumSecim") || {}).value || "taslak";
  const k = iyKonuBul(konuId);
  const idx = k ? k.ozetSayfalari.length : 0;
  iyOzetSayfaEkle(konuId, { baslik, icerik, gorseller: [], duzen: "metin", tip, oncelik, durum });
  if (onizle) git(`ozetoku/${dersId}/${konuId}/${idx}`);
  else icerikYenileKonu(konuId);
}

function sayfaEklePptxFormu(konuId) {
  return `
    <div class="card">
      <p class="muted mb">Bir PowerPoint (.pptx) dosyası seçin — PDF'in aksine her slayt sayfa
        GÖRÜNTÜSÜ olarak değil, gerçek metin + görsel içeren bir Özet Sayfası olarak eklenir.
        Bu yüzden PDF'e göre çok daha hafif olur ve çok daha hızlı açılır. Slayt başlığı sayfa
        başlığı, gövde metni sayfa içeriği, slayttaki resimler de görsel galerisi olur.</p>
      <input type="file" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation" id="sayfaEklePptxDosya">
      <button class="btn btn-primary mt" onclick="sayfaEklePptxGonder('${konuId}')">Sayfaları Ekle</button>
      <div class="muted mt" id="sayfaEklePptxDurum"></div>
    </div>`;
}
async function sayfaEklePptxGonder(konuId) {
  const input = $("#sayfaEklePptxDosya");
  const f = input && input.files && input.files[0];
  const durum = $("#sayfaEklePptxDurum");
  if (!f) { if (durum) durum.textContent = "Lütfen bir .pptx dosyası seçin."; return; }
  if (typeof ppBagliMi !== "function" || !ppBagliMi()) {
    alert("Sayfa eklemek için önce Üyelik ve Ayarlar sayfasından sunucu bağlantısını kurmanız gerekir.");
    return;
  }
  if (typeof JSZip === "undefined" || typeof pptxSlaytlariCikar !== "function") {
    if (durum) durum.textContent = "PPTX kütüphanesi yüklenemedi, sayfayı yenileyip tekrar deneyin.";
    return;
  }

  try {
    if (durum) durum.textContent = "Dosya açılıyor…";
    const slaytlar = await pptxSlaytlariCikar(f, (i, toplam) => {
      if (durum) durum.textContent = `Slayt ${i}/${toplam} işleniyor…`;
    });

    for (let i = 0; i < slaytlar.length; i++) {
      const slayt = slaytlar[i];
      if (durum) durum.textContent = `Slayt ${i + 1}/${slaytlar.length} yükleniyor…`;
      const gorseller = [];
      for (const g of slayt.gorseller) {
        try {
          const dosyaNesnesi = new File([g.blob], g.ad, { type: g.blob.type || "image/png" });
          const sonuc = await kuDosyaYukle(dosyaNesnesi);
          gorseller.push({ id: iyBenzersizId("gorsel"), dosya: sonuc.yol, baslik: "" });
        } catch { /* tek bir görsel yüklenemezse sayfayı yine de metniyle ekle */ }
      }
      iyOzetSayfaEkle(konuId, {
        baslik: slayt.baslik,
        icerik: slayt.icerikHtml,
        gorseller,
        duzen: gorseller.length ? "gorsel-ust" : "metin"
      });
    }
    if (durum) durum.textContent = `✅ ${slaytlar.length} slayt sayfa olarak eklendi.`;
    setTimeout(() => icerikYenileKonu(konuId), 900);
  } catch (e) {
    if (durum) durum.textContent = "";
    alert("PPTX işlenemedi: " + e.message);
  }
}

/* --- Görseller --- */
function gorselYonetimHtml(k) {
  const kutular = (k.gorseller || []).map(g => `
    <figure class="card" style="padding:0">
      <img src="${g.dosya}" style="height:120px;width:100%;object-fit:cover">
      <figcaption class="row between" style="padding:8px 11px">
        <span class="muted">${esc(g.baslik || "")}</span>
        <button class="btn btn-sm tehlike" onclick="gorselSilGonder('${k.id}','${g.id}')">Sil</button>
      </figcaption>
    </figure>`).join("");
  return `
    <h2 class="section">🖼️ Görseller</h2>
    <div class="card mb">
      <label class="ayar-baslik">Görsel dosyası</label>
      <input type="file" accept="image/*" onchange="iyDosyaYukle(this, url => document.getElementById('gorselDosyaDeger').value = url, document.getElementById('gorselYuklemeDurum'))">
      <input type="hidden" id="gorselDosyaDeger">
      <div class="muted mt" id="gorselYuklemeDurum"></div>
      <label class="ayar-baslik mt">Başlık / açıklama (opsiyonel)</label>
      <input class="genis-input" id="gorselBaslik">
      <button class="btn btn-primary mt" onclick="gorselEkleGonder('${k.id}')">Görsel Ekle</button>
      <div class="hata" id="gorselHata"></div>
    </div>
    ${kutular ? `<div class="galeri mb">${kutular}</div>` : `<div class="empty mb"><div class="icon">🖼️</div>Henüz görsel eklenmedi.</div>`}`;
}
function gorselEkleGonder(konuId) {
  const dosya = $("#gorselDosyaDeger").value;
  if (!dosya) { $("#gorselHata").textContent = "Lütfen bir görsel seçin."; return; }
  iyGorselEkle(konuId, { dosya, baslik: $("#gorselBaslik").value.trim() });
  icerikYenileKonu(konuId);
}
function gorselSilGonder(konuId, gorselId) {
  if (!confirm("Bu görseli silmek istediğinize emin misiniz?")) return;
  iyGorselSil(konuId, gorselId);
  icerikYenileKonu(konuId);
}

/* --- Fotoromanlar --- */
let YONETIM_FOTOROMAN_ACIK = null;
function fotoYonetimHtml(k) {
  if (YONETIM_FOTOROMAN_ACIK) {
    const fr = (k.fotoromanlar || []).find(f => f.id === YONETIM_FOTOROMAN_ACIK);
    if (fr) return fotoromanYonetimEditor(k, fr);
    YONETIM_FOTOROMAN_ACIK = null;
  }
  const kartlar = (k.fotoromanlar || []).map(f => `
    <div class="card">
      <strong>${esc(f.baslik)}</strong><div class="muted mt">${f.kareler.length} kare</div>
      <div class="row mt">
        <button class="btn btn-sm btn-primary grow" onclick="fotoromanAcGonder('${f.id}','${k.id}')">Düzenle</button>
        <button class="btn btn-sm tehlike" onclick="fotoromanSilGonder('${k.id}','${f.id}')">Sil</button>
      </div>
    </div>`).join("");
  return `
    <h2 class="section">🎞️ Fotoromanlar</h2>
    <div class="card mb">
      <p class="muted mb">Fotoroman: konuyla ilgili, görsellerle anlatılan iç açıcı / eğlenceli bir mini-hikaye.</p>
      <label class="ayar-baslik">Başlık</label>
      <input class="genis-input" id="fotoBaslik_${k.id}" placeholder="Örn. Hasılat Nasıl Muhasebeleşir?">
      <button class="btn btn-primary mt" onclick="fotoromanEkleGonder('${k.id}')">Oluştur ve Kareleri Ekle</button>
    </div>
    ${kartlar ? `<div class="grid grid-3 mb">${kartlar}</div>` : `<div class="empty mb"><div class="icon">🎞️</div>Henüz fotoroman eklenmedi.</div>`}`;
}
function fotoromanEkleGonder(konuId) {
  const el = $("#fotoBaslik_" + konuId);
  const baslik = el ? el.value.trim() : "";
  if (!baslik) return;
  const id = iyFotoromanEkle(konuId, baslik);
  YONETIM_FOTOROMAN_ACIK = id;
  icerikYenileKonu(konuId);
}
function fotoromanAcGonder(id, konuId) { YONETIM_FOTOROMAN_ACIK = id; icerikYenileKonu(konuId); }
function fotoromanKapatGonder(konuId) { YONETIM_FOTOROMAN_ACIK = null; icerikYenileKonu(konuId); }
function fotoromanSilGonder(konuId, frId) {
  if (!confirm("Bu fotoromanı silmek istediğinize emin misiniz?")) return;
  iyFotoromanSil(konuId, frId);
  icerikYenileKonu(konuId);
}
function fotoromanYonetimEditor(k, fr) {
  const kareler = fr.kareler.map((kr, i) => `
    <div class="icerik-satir">
      ${kr.gorsel ? `<img src="${kr.gorsel}" style="width:56px;height:56px;object-fit:cover;border-radius:8px">` : `<span style="font-size:22px">🖼️</span>`}
      <div class="grow">${esc(kr.metin)}</div>
      <button class="btn btn-sm tehlike" onclick="fotoromanKareSilGonder('${k.id}','${fr.id}',${i})">Sil</button>
    </div>`).join("");
  return `
    <h2 class="section">🎞️ Fotoromanlar</h2>
    <button class="btn btn-sm mb" onclick="fotoromanKapatGonder('${k.id}')">‹ Fotoroman listesine dön</button>
    <div class="card mb">
      <h3 class="mb">${esc(fr.baslik)} — Yeni Kare Ekle</h3>
      <label class="ayar-baslik">Görsel</label>
      <input type="file" accept="image/*" onchange="iyDosyaYukle(this, url => document.getElementById('kareGorselDeger').value = url, document.getElementById('kareGorselYuklemeDurum'))">
      <input type="hidden" id="kareGorselDeger">
      <div class="muted mt" id="kareGorselYuklemeDurum"></div>
      <label class="ayar-baslik mt">Metin / diyalog</label>
      <textarea class="genis-input" id="kareMetin" style="width:100%" placeholder="Bu karede ne anlatılıyor / kim ne diyor?"></textarea>
      <button class="btn btn-primary mt" onclick="fotoromanKareEkleGonder('${k.id}','${fr.id}')">Kareyi Ekle</button>
    </div>
    ${kareler || `<div class="empty mb"><div class="icon">🎞️</div>Henüz kare eklenmedi.</div>`}`;
}
function fotoromanKareEkleGonder(konuId, frId) {
  const metin = $("#kareMetin").value.trim();
  const gorsel = $("#kareGorselDeger").value;
  if (!metin && !gorsel) return;
  iyFotoromanKareEkle(konuId, frId, { gorsel, metin });
  icerikYenileKonu(konuId);
}
function fotoromanKareSilGonder(konuId, frId, idx) {
  if (!confirm("Bu kareyi silmek istediğinize emin misiniz?")) return;
  iyFotoromanKareSil(konuId, frId, idx);
  icerikYenileKonu(konuId);
}

/* --- Bilgi Kartı Hazırla (tip: bilgi_karti — yalnızca Bilgi Kartları'nda
   gösterilir, çoktan seçmeli sınava dahil edilmez, bkz. sinavSorulariGetir) --- */
function bilgiKartiYonetimHtml(dersId, k) {
  const kartlar = sorulariGetir(dersId, k.id).filter(s => s.tip === "bilgi_karti");
  const satirlar = kartlar.map(s => `
    <div class="icerik-satir">
      <div class="grow"><strong>${esc(s.s)}</strong>
        <div class="muted">${esc(s.bolum)} · Cevap: ${esc(s.cevap)}</div></div>
      <button class="btn btn-sm tehlike" onclick="soruSilGonder('${dersId}','${k.id}','${s.id}')">Sil</button>
    </div>`).join("");

  return `
    <h2 class="section">🃏 Bilgi Kartı Hazırla (${kartlar.length})</h2>
    <div class="card mb">
      <h3 class="mb">Yeni Bilgi Kartı Ekle</h3>
      <label class="ayar-baslik">Bölüm (gruplama başlığı, opsiyonel)</label>
      <input class="genis-input" id="bkBolum" placeholder="Örn. Kavramlar">
      <label class="ayar-baslik mt">Soru / ön yüz</label>
      <textarea class="genis-input" id="bkSoru" style="width:100%"></textarea>
      <label class="ayar-baslik mt">Cevap / arka yüz</label>
      <textarea class="genis-input" id="bkCevap" style="width:100%"></textarea>
      <label class="ayar-baslik mt">Açıklama (opsiyonel)</label>
      <textarea class="genis-input" id="bkAciklama" style="width:100%"></textarea>
      <button class="btn btn-primary mt" onclick="bilgiKartiEkleGonder('${dersId}','${k.id}')">Kart Ekle</button>
      <div class="hata" id="bkHata"></div>
    </div>
    <div class="card mb">
      <h3 class="mb">CSV ile Toplu İçe Aktar</h3>
      <p class="muted mb">Sütunlar: <code>bolum, soru, cevap, aciklama</code> (ilk satır başlık; "bolum" ve
        "aciklama" opsiyonel). İçe aktarınca kartlar hemen kullanılabilir hâle gelir.</p>
      <input type="file" accept=".csv,text/csv" id="bkCsvDosya" onchange="bilgiKartiCsvSecildi(this,'${dersId}','${k.id}')">
      <div class="muted mt" id="bkCsvDurum"></div>
    </div>
    ${satirlar || `<div class="empty mb"><div class="icon">🃏</div>Bu konu için henüz bilgi kartı eklenmedi.</div>`}`;
}
function bilgiKartiEkleGonder(dersId, konuId) {
  const s = $("#bkSoru").value.trim();
  const cevap = $("#bkCevap").value.trim();
  if (!s || !cevap) { $("#bkHata").textContent = "Soru ve cevap zorunludur."; return; }
  iySoruEkle(dersId, konuId, { tip: "bilgi_karti", s, cevap, bolum: $("#bkBolum").value.trim(), aciklama: $("#bkAciklama").value.trim() });
  icerikYenile(dersId, konuId);
}
function bilgiKartiCsvSecildi(inputEl, dersId, konuId) {
  const f = inputEl.files && inputEl.files[0];
  if (!f) return;
  const okuyucu = new FileReader();
  okuyucu.onload = e => {
    const sorular = soruCsvBilgiKartiAyristir(e.target.result);
    inputEl.value = "";
    if (!sorular.length) { $("#bkCsvDurum").textContent = "Geçerli satır bulunamadı. Sütun başlıklarını kontrol edin."; return; }
    iySorularTopluEkle(dersId, konuId, sorular);
    /* icerikYenile() #content'i tamamen yeniden çizdiği için burada ayrıca
       durum yazmaya gerek yok — yeni kartlar ve güncellenen sayaç zaten
       görünür; kullanıcıya geri bildirim budur. */
    icerikYenile(dersId, konuId);
  };
  okuyucu.readAsText(f, "UTF-8");
}
function soruCsvBilgiKartiAyristir(metin) {
  const satirlar = ppCsvAyristir(metin);
  if (!satirlar.length) return [];
  const baslik = satirlar[0].map(s => (s || "").trim().toLowerCase());
  const idx = ad => baslik.indexOf(ad);
  const iBolum = idx("bolum"), iSoru = idx("soru"), iCevap = idx("cevap"), iAciklama = idx("aciklama");
  const sonuc = [];
  for (let r = 1; r < satirlar.length; r++) {
    const satir = satirlar[r];
    if (!satir.some(s => (s || "").trim() !== "")) continue;
    const soru = (satir[iSoru] || "").trim();
    const cevap = (satir[iCevap] || "").trim();
    if (!soru || !cevap) continue;
    sonuc.push({ tip: "bilgi_karti", bolum: (satir[iBolum] || "").trim() || "İçe Aktarılan", s: soru, cevap, aciklama: (satir[iAciklama] || "").trim() });
  }
  return sonuc;
}

/* --- Soru Hazırla (tip: cok_secmeli — Bilgi Kartları'nda da gösterilir,
   ayrıca "Beni Test Et" sınavına dahil edilir) --- */
let SORU_CSV_SON_HATALI = 0; // son çoktan seçmeli CSV içe aktarmada atlanan satır sayısı (bir kez gösterilir)
function cokSecmeliSoruYonetimHtml(dersId, k) {
  const hataliUyarisi = SORU_CSV_SON_HATALI;
  SORU_CSV_SON_HATALI = 0;
  const sorular = sinavSorulariGetir(dersId, k.id);
  const satirlar = sorular.map(s => `
    <div class="icerik-satir">
      <div class="grow"><strong>${esc(s.s)}</strong>
        <div class="muted">${esc(s.bolum)} · Doğru: ${esc(s.o[s.d])}</div></div>
      ${s.id.startsWith("soru-ek-") ? `<button class="btn btn-sm tehlike" onclick="soruSilGonder('${dersId}','${k.id}','${s.id}')">Sil</button>`
        : `<span class="badge badge-gray">Hazır soru</span>`}
    </div>`).join("");

  return `
    <h2 class="section">✏️ Soru Hazırla (${sorular.length})</h2>
    <div class="card mb">
      <h3 class="mb">Yeni Soru Ekle</h3>
      <label class="ayar-baslik">Bölüm (gruplama başlığı, opsiyonel)</label>
      <input class="genis-input" id="soruBolum" placeholder="Örn. Ek Sorular">
      <label class="ayar-baslik mt">Soru metni</label>
      <textarea class="genis-input" id="soruMetin" style="width:100%"></textarea>
      <label class="ayar-baslik mt">Şıklar (doğru olanı işaretleyin)</label>
      ${[0, 1, 2, 3].map(i => `
        <div class="row mt">
          <input type="radio" name="soruDogru" value="${i}" ${i === 0 ? "checked" : ""} style="width:20px">
          <input class="genis-input grow" id="soruSik${i}" placeholder="${String.fromCharCode(65 + i)} şıkkı">
        </div>`).join("")}
      <label class="ayar-baslik mt">Açıklama (opsiyonel)</label>
      <textarea class="genis-input" id="soruAciklama" style="width:100%" placeholder="Doğru cevabın nedeni..."></textarea>
      <button class="btn btn-primary mt" onclick="soruEkleGonder('${dersId}','${k.id}')">Soru Ekle</button>
      <div class="hata" id="soruHata"></div>
    </div>
    <div class="card mb">
      <h3 class="mb">CSV ile Toplu İçe Aktar</h3>
      <p class="muted mb">Sütunlar: <code>bolum, soru, secenek_a, secenek_b, secenek_c, secenek_d, dogru_cevap, aciklama</code>
        (ilk satır başlık; "bolum" ve "aciklama" opsiyonel). "dogru_cevap" harf (A/B/C/D) ya da şıkla birebir
        aynı metin olabilir. İçe aktarınca sorular hemen kullanılabilir hâle gelir.</p>
      <input type="file" accept=".csv,text/csv" id="soruCsvDosya" onchange="soruCsvSecildi(this,'${dersId}','${k.id}')">
      <div class="muted mt" id="soruCsvDurum"></div>
      ${hataliUyarisi > 0 ? `<div class="hata mt">⚠️ Son içe aktarmada ${hataliUyarisi} satır eksik/uyumsuz veri nedeniyle atlandı.</div>` : ""}
    </div>
    ${satirlar || `<div class="empty mb"><div class="icon">❓</div>Bu konu için henüz soru eklenmedi.</div>`}`;
}
function soruEkleGonder(dersId, konuId) {
  const s = $("#soruMetin").value.trim();
  const o = [0, 1, 2, 3].map(i => $("#soruSik" + i).value.trim());
  const d = +document.querySelector('input[name="soruDogru"]:checked').value;
  if (!s || o.some(x => !x)) { $("#soruHata").textContent = "Soru metni ve 4 şık zorunludur."; return; }
  iySoruEkle(dersId, konuId, { tip: "cok_secmeli", s, o, d, bolum: $("#soruBolum").value.trim(), aciklama: $("#soruAciklama").value.trim() });
  icerikYenile(dersId, konuId);
}
function soruCsvSecildi(inputEl, dersId, konuId) {
  const f = inputEl.files && inputEl.files[0];
  if (!f) return;
  const okuyucu = new FileReader();
  okuyucu.onload = e => {
    const { sorular, hatali } = soruCsvCokSecmeliAyristir(e.target.result);
    inputEl.value = "";
    if (!sorular.length) { $("#soruCsvDurum").textContent = "Geçerli satır bulunamadı. Sütun başlıklarını kontrol edin."; return; }
    iySorularTopluEkle(dersId, konuId, sorular);
    /* icerikYenile() #content'i yeniden çizeceğinden burada durum yazmanın
       anlamı yok — atlanan satır sayısını bir sonraki çizimde bir kez
       göstermek üzere SORU_CSV_SON_HATALI'a taşıyoruz (aksi hâlde bu bilgi
       sessizce kaybolurdu). */
    SORU_CSV_SON_HATALI = hatali;
    icerikYenile(dersId, konuId);
  };
  okuyucu.readAsText(f, "UTF-8");
}
function soruCsvCokSecmeliAyristir(metin) {
  const satirlar = ppCsvAyristir(metin);
  if (!satirlar.length) return { sorular: [], hatali: 0 };
  const baslik = satirlar[0].map(s => (s || "").trim().toLowerCase());
  const idx = ad => baslik.indexOf(ad);
  const iBolum = idx("bolum"), iSoru = idx("soru"), iA = idx("secenek_a"), iB = idx("secenek_b"),
    iC = idx("secenek_c"), iD = idx("secenek_d"), iCevap = idx("dogru_cevap"), iAciklama = idx("aciklama");
  const sonuc = [];
  let hatali = 0;
  for (let r = 1; r < satirlar.length; r++) {
    const satir = satirlar[r];
    if (!satir.some(s => (s || "").trim() !== "")) continue;
    const soru = (satir[iSoru] || "").trim();
    const o = [satir[iA], satir[iB], satir[iC], satir[iD]].map(s => (s || "").trim());
    const cevapHam = (satir[iCevap] || "").trim();
    if (!soru || o.some(x => !x) || !cevapHam) { hatali++; continue; }
    const harfIdx = "abcd".indexOf(cevapHam.toLowerCase());
    const d = (harfIdx >= 0 && harfIdx < 4) ? harfIdx : o.findIndex(x => x.toLowerCase() === cevapHam.toLowerCase());
    if (d < 0) { hatali++; continue; }
    sonuc.push({ tip: "cok_secmeli", bolum: (satir[iBolum] || "").trim() || "İçe Aktarılan", s: soru, o, d, aciklama: (satir[iAciklama] || "").trim() });
  }
  return { sorular: sonuc, hatali };
}
function soruSilGonder(dersId, konuId, soruId) {
  if (!confirm("Bu soruyu/kartı silmek istediğinize emin misiniz?")) return;
  iySoruSil(dersId, konuId, soruId);
  icerikYenile(dersId, konuId);
}

/* ---------------------------------------------------------
   19b2. PRATİK SORU SETLERİ (CSV içe aktarma — kalıcı MySQL depolama)
   --------------------------------------------------------- */
function pratikSetYonetimHtml(d, k) {
  if (ppBagliMi()) setTimeout(() => pratikSetListeYukle(d.id, k.id), 0);
  return `
    <h2 class="section">🧠 Pratik Soru Setleri</h2>
    <div class="card mb">
      <p class="muted mb">CSV'den soru içe aktararak "Sınav 1", "Sınav 2" gibi adlandırılmış pratik
        soru setleri oluşturun. Bilgi kartı veya anlık geri bildirimli çoktan seçmeli modla çalışılıp
        skorlar kalıcı olarak (bu tarayıcıdan bağımsız) saklanır.
        CSV sütunları: <code>tip,soru,secenek_a,secenek_b,secenek_c,secenek_d,dogru_cevap,aciklama</code>
        (<code>tip</code> = <code>cok_secmeli</code> veya <code>bilgi_karti</code>).</p>
      ${ppBagliMi() ? `
        <label class="ayar-baslik">Yeni set adı</label>
        <input class="genis-input" id="pratikSetAd_${k.id}" placeholder="Örn. Sınav 1">
        <button class="btn btn-primary mt" onclick="pratikSetEkleGonder('${d.id}','${k.id}')">Set Oluştur</button>
        <div class="hata" id="pratikSetHata"></div>
      ` : `<div class="notice uyari-notice">Pratik sistemi bağlı değil. Önce
        <a onclick="git('yonetim/dashboard')" style="cursor:pointer">Yönetim → Sunucu Bağlantısı</a> kartından bağlanın.</div>`}
    </div>
    <div id="pratikSetListe_${k.id}">${ppBagliMi() ? `<div class="muted">Yükleniyor…</div>` : ""}</div>`;
}

async function pratikSetListeYukle(dersId, konuId) {
  const alan = document.getElementById("pratikSetListe_" + konuId);
  if (!alan) return;
  try {
    const setler = await ppSetListele(dersId, konuId);
    if (!setler.length) {
      alan.innerHTML = `<div class="empty mb"><div class="icon">🧠</div>Henüz pratik seti eklenmedi.</div>`;
      return;
    }
    alan.innerHTML = setler.map(s => `
      <div class="icerik-satir">
        <span class="badge badge-gray">${s.soru_sayisi} soru</span>
        <div class="grow"><strong>${esc(s.ad)}</strong>
          <div class="muted">${new Date(s.olusturma_tarihi.replace(" ", "T") + "Z").toLocaleDateString("tr-TR")}</div></div>
        <button class="btn btn-sm" onclick="document.getElementById('pratikCsvGirdi_${s.id}').click()">CSV İçe Aktar</button>
        <input type="file" accept=".csv" id="pratikCsvGirdi_${s.id}" style="display:none" onchange="pratikCsvSecildi(this,${s.id})">
        <button class="btn btn-sm tehlike" onclick="pratikSetSilGonder(${s.id},'${dersId}','${konuId}')">Sil</button>
      </div>
      <div id="pratikCsvOnizleme_${s.id}" class="mb"></div>`).join("");
  } catch (e) {
    alan.innerHTML = `<div class="hata">Setler yüklenemedi: ${esc(e.message)}</div>`;
  }
}

async function pratikSetEkleGonder(dersId, konuId) {
  const el = $("#pratikSetAd_" + konuId);
  const ad = el ? el.value.trim() : "";
  const hata = $("#pratikSetHata");
  if (!ad) { if (hata) hata.textContent = "Set adı zorunludur."; return; }
  if (hata) hata.textContent = "";
  try {
    await ppSetEkle(dersId, konuId, ad);
    if (el) el.value = "";
    pratikSetListeYukle(dersId, konuId);
  } catch (e) {
    if (hata) hata.textContent = e.message;
  }
}

async function pratikSetSilGonder(setId, dersId, konuId) {
  if (!confirm("Bu pratik setini ve içindeki tüm soruları/deneme kayıtlarını silmek istediğinize emin misiniz?")) return;
  try {
    await ppSetSil(setId);
    pratikSetListeYukle(dersId, konuId);
  } catch (e) {
    alert("Silme başarısız: " + e.message);
  }
}

function pratikCsvSecildi(inputEl, setId) {
  const f = inputEl.files[0];
  if (!f) return;
  const okuyucu = new FileReader();
  okuyucu.onload = e => {
    const sorular = ppCsvSorulariAyristir(e.target.result);
    const alan = $("#pratikCsvOnizleme_" + setId);
    if (!alan) return;
    if (!sorular.length) {
      alan.innerHTML = `<div class="hata mt">CSV'de geçerli soru bulunamadı. Sütunlar:
        tip,soru,secenek_a,secenek_b,secenek_c,secenek_d,dogru_cevap,aciklama</div>`;
      return;
    }
    window["__pratikCsvBekleyen_" + setId] = sorular;
    const bilgiKarti = sorular.filter(s => s.tip === "bilgi_karti").length;
    const cokSecmeli = sorular.length - bilgiKarti;
    alan.innerHTML = `
      <div class="notice basari-notice mt">${sorular.length} soru bulundu
        (${cokSecmeli} çoktan seçmeli, ${bilgiKarti} bilgi kartı).
        <div class="row mt">
          <button class="btn btn-primary btn-sm" onclick="pratikCsvOnayla(${setId})">İçe Aktar</button>
          <button class="btn btn-sm" onclick="document.getElementById('pratikCsvOnizleme_${setId}').innerHTML=''">Vazgeç</button>
        </div>
      </div>`;
  };
  okuyucu.readAsText(f, "UTF-8");
  inputEl.value = "";
}

async function pratikCsvOnayla(setId) {
  const sorular = window["__pratikCsvBekleyen_" + setId];
  if (!sorular) return;
  const alan = $("#pratikCsvOnizleme_" + setId);
  if (alan) alan.innerHTML = `<div class="muted mt">Aktarılıyor…</div>`;
  try {
    const eklenen = await ppSorularIceAktar(setId, sorular);
    delete window["__pratikCsvBekleyen_" + setId];
    if (alan) alan.innerHTML = `<div class="notice basari-notice mt">✅ ${eklenen} soru içe aktarıldı.</div>`;
  } catch (e) {
    if (alan) alan.innerHTML = `<div class="hata mt">İçe aktarma başarısız: ${esc(e.message)}</div>`;
  }
}

/* ---------------------------------------------------------
   19c. YÖNETİM — SUNUCU BAĞLANTISI + YEDEK
   ---------------------------------------------------------
   İçerik artık GitHub'a "yayınlamaya" gerek kalmadan, her
   düzenlemeden kısa bir süre sonra doğrudan bu sunucuya
   (icerik_kaydet.php) yazılıyor — bkz. icerikTaslakKaydet.
   Bunun çalışması ve dosya yükleyebilmeniz için tek gereken,
   aşağıdaki uygulama anahtarıyla sunucuya bağlanmış olmak.
   --------------------------------------------------------- */
function yonetimSistemHtml() {
  return `
    ${!ppBagliMi()
      ? `<div class="notice uyari-notice mb">
          <strong>Sunucu bağlantısı kurulu değil.</strong> Değişiklikleriniz yalnızca bu tarayıcıda saklanıyor;
          sunucuya kaydedilmesi (ve dosya yükleyebilmeniz) için aşağıdaki Sunucu Bağlantısı kartından bağlanın.
        </div>`
      : ICERIK_TASLAK_VAR
      ? `<div class="notice uyari-notice mb"><strong>Kaydediliyor…</strong> (son yerel kayıt: ${icerikTaslakZamani() || "—"})</div>`
      : `<div class="notice basari-notice mb">Tüm değişiklikleriniz sunucuya kaydedildi.</div>`}
    ${yonetimBaglantiHtml()}`;
}

function yonetimBaglantiHtml() {
  return `
    <div class="card mb">
      <h3 class="mb">🧠 Sunucu Bağlantısı</h3>
      ${ppBagliMi()
        ? `<p class="muted mb">Uygulama anahtarı tanımlı. İçerik değişiklikleri ve dosya yüklemeleri
           doğrudan suleymanavci.com.tr sunucusuna kaydediliyor.</p>
           <div class="row wrap" style="gap:8px">
             <button class="btn btn-sm" onclick="ppBaglantiTestGonder()">Bağlantıyı Test Et</button>
             <button class="btn btn-sm tehlike" onclick="ppAnahtarSilGonder()">Anahtarı Kaldır</button>
           </div>`
        : `<p class="muted mb">İçerik kaydetme, dosya yükleme, yapay zekâ ve pratik soru sistemleri için
           <code>public/api/</code> altındaki sunucuya bağlanmak üzere bir uygulama anahtarı gerekir — bu,
           <code>public/api/config.php</code> içindeki <code>APP_ANAHTARI</code> ile birebir aynı olmalı.
           Kurulum adımları README.md içinde "Pratik Sistemi Kurulumu" bölümünde. Anahtar yalnızca bu
           tarayıcıda saklanır.</p>
           <input class="genis-input" id="ppAnahtarGirdi" type="password" placeholder="uzun-rastgele-anahtariniz">
           <button class="btn btn-primary mt" onclick="ppAnahtarKaydetGonder()">Anahtarı Kaydet</button>`}
      <div id="ppBaglantiDurum" class="muted mt"></div>
    </div>

    <div class="card">
      <h3 class="mb">💾 Yedek</h3>
      <p class="muted mb">Tüm içeriğin (sınav + dersler + sorular) JSON yedeğini indirir/yükler — cihazınızda ayrıca saklamak için.</p>
      <button class="btn genis mb" onclick="icerikYedekIndirGonder()">Yedek İndir</button>
      <input type="file" accept=".json" onchange="icerikYedekYukleGonder(this)">
    </div>`;
}

function ppAnahtarKaydetGonder() {
  const el = $("#ppAnahtarGirdi");
  if (!el || !el.value.trim()) return;
  ppAnahtarKaydet(el.value);
  yonlendir();
}
function ppAnahtarSilGonder() {
  if (!confirm("Sunucu uygulama anahtarını bu tarayıcıdan kaldırmak istediğinize emin misiniz?")) return;
  ppAnahtarSil();
  yonlendir();
}
async function ppBaglantiTestGonder() {
  const durum = $("#ppBaglantiDurum");
  if (durum) durum.textContent = "Test ediliyor…";
  try {
    const sonuc = await ppPingTest();
    if (durum) durum.textContent = "✅ " + (sonuc && sonuc.mesaj ? sonuc.mesaj : "Bağlantı başarılı.");
  } catch (e) {
    if (durum) durum.textContent = "❌ " + e.message;
  }
}

function icerikYedekIndirGonder() {
  const paket = { sinav: SINAV, dersler: DERSLER, sorular: SORULAR, tarih: new Date().toISOString() };
  const b = new Blob([JSON.stringify(paket, null, 2)], { type: "application/json" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(b);
  a.download = `ymm-icerik-yedek-${new Date().toISOString().slice(0, 10)}.json`;
  document.body.appendChild(a); a.click(); a.remove();
}
function icerikYedekYukleGonder(input) {
  const f = input.files[0]; if (!f) return;
  const okuyucu = new FileReader();
  okuyucu.onload = e => {
    try {
      const p = JSON.parse(e.target.result);
      SINAV = p.sinav; DERSLER.length = 0; DERSLER.push(...p.dersler); SORULAR = p.sorular || {};
      icerikTaslakKaydet();
      alert("Yedek yüklendi.");
      git("yonetim/dashboard");
    } catch { alert("Dosya okunamadı."); }
  };
  okuyucu.readAsText(f);
}

/* ---------------------------------------------------------
   20. BAŞLATMA
   --------------------------------------------------------- */
function menuAc() { $(".sidebar").classList.toggle("acik"); }

window.addEventListener("hashchange", yonlendir);
window.addEventListener("DOMContentLoaded", () => {
  const y = $("#year"); if (y) y.textContent = new Date().getFullYear();
  temaUygula();
  icerikYukle().then(yonlendir);
  yonlendir();
});
