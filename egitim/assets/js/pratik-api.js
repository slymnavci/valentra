/* =========================================================
   PRATİK SİSTEMİ — API İSTEMCİSİ
   ---------------------------------------------------------
   Soru seti / CSV içe aktarma / skor kaydı için kendi sunucumuzdaki
   (aynı origin, /api/) küçük bir PHP+MySQL backend'ine konuşur.
   GitHub yayınlama sisteminden (github-yayinla.js) tamamen bağımsızdır
   — bu, GitHub'da tutulmayan, sık değişen kullanıcı verisi (pratik
   soruları + deneme skorları) için kalıcı depolama sağlar.

   ⚠️ GÜVENLİK
   public/api/config.php'de tanımladığınız APP_ANAHTARI ile aynı
   değeri buraya (Üyelik ve Ayarlar sayfasından) girmeniz gerekir.
   Anahtar yalnızca bu tarayıcıda saklanır, her istekte X-App-Key
   başlığıyla sunucuya gönderilir. Kurulum adımları README.md'de.
   ========================================================= */

const PP_ANAHTAR_ADI = "ymm_pp_anahtar_v1";
/* Oturum nesnesinin (kullaniciAdi/ad/rol/token) saklandığı anahtar —
   auth.js'teki OTURUM_KEY ile birebir aynı olmalı. Giriş sonrası üretilen
   kullanıcıya özel jeton buradan okunup her istekte X-Session-Token
   başlığıyla gönderilir; sunucu artık kullanici_adi alanına değil bu
   jetona bağlı kimliğe göre yetkilendirme yapar (bkz. yetki.php). */
const PP_OTURUM_ADI = "ymm_oturum_v1";

function ppAnahtarAl() { return (localStorage.getItem(PP_ANAHTAR_ADI) || "").trim(); }
function ppAnahtarKaydet(a) { localStorage.setItem(PP_ANAHTAR_ADI, (a || "").trim()); }
function ppAnahtarSil() { localStorage.removeItem(PP_ANAHTAR_ADI); }
function ppBagliMi() { return !!ppAnahtarAl(); }
function ppOturumTokenAl() {
  try { return JSON.parse(localStorage.getItem(PP_OTURUM_ADI)).token || ""; }
  catch { return ""; }
}

async function ppIstek(yol, secenek) {
  const anahtar = ppAnahtarAl();
  const r = await fetch(`api/${yol}`, Object.assign({
    headers: Object.assign({
      "Content-Type": "application/json",
      "X-App-Key": anahtar,
      "X-Session-Token": ppOturumTokenAl()
    }, (secenek && secenek.headers) || {})
  }, secenek || {}));
  let govde = null;
  try { govde = await r.json(); } catch { /* boş/JSON-olmayan yanıt olabilir */ }
  if (!r.ok) {
    const mesaj = (govde && govde.hata) || r.statusText || "Bilinmeyen hata";
    throw new Error(`Pratik sistemi hatası (${r.status}): ${mesaj}`);
  }
  return govde;
}

async function ppPingTest() {
  return ppIstek("ping.php");
}

/* --------------------------------------------------------- */
/* SORU SETİ / SORU CRUD                                      */
/* --------------------------------------------------------- */
async function ppSetEkle(dersId, konuId, ad) {
  const sonuc = await ppIstek("set_ekle.php", {
    method: "POST", body: JSON.stringify({ ders_id: dersId, konu_id: konuId, ad })
  });
  return sonuc.id;
}

async function ppSetListele(dersId, konuId) {
  const sonuc = await ppIstek(`set_listele.php?ders_id=${encodeURIComponent(dersId)}&konu_id=${encodeURIComponent(konuId)}`);
  return sonuc.setler;
}

async function ppSetSil(setId) {
  await ppIstek("set_sil.php", { method: "POST", body: JSON.stringify({ id: setId }) });
}

async function ppSorularIceAktar(setId, sorular) {
  const sonuc = await ppIstek("sorular_ice_aktar.php", {
    method: "POST", body: JSON.stringify({ set_id: setId, sorular })
  });
  return sonuc.eklenen;
}

async function ppSorularGetir(setId) {
  const sonuc = await ppIstek(`sorular_getir.php?set_id=${encodeURIComponent(setId)}`);
  return sonuc.sorular;
}

async function ppSoruSil(soruId) {
  await ppIstek("soru_sil.php", { method: "POST", body: JSON.stringify({ id: soruId }) });
}

async function ppDenemeKaydet(setId, kullaniciAdi, dogru, toplam, puan, detay) {
  const sonuc = await ppIstek("deneme_kaydet.php", {
    method: "POST",
    body: JSON.stringify({ set_id: setId, kullanici_adi: kullaniciAdi, dogru, toplam, puan, detay: detay || null })
  });
  return sonuc.id;
}

async function ppDenemelerGetir(setId, kullaniciAdi) {
  const ek = kullaniciAdi ? `&kullanici_adi=${encodeURIComponent(kullaniciAdi)}` : "";
  const sonuc = await ppIstek(`denemeler_getir.php?set_id=${encodeURIComponent(setId)}${ek}`);
  return sonuc.denemeler;
}

/* --------------------------------------------------------- */
/* CSV AYRIŞTIRMA (dış kütüphane yok)                          */
/* --------------------------------------------------------- */
/* RFC4180'e yakın basit bir ayrıştırıcı: tırnaklı alanlar,
   tırnak içi virgül/satır sonu ve çift tırnak kaçışını (""->") destekler. */
function ppCsvAyristir(metin) {
  const satirlar = [];
  let satir = [], alan = "", tirnakIcinde = false;
  for (let i = 0; i < metin.length; i++) {
    const c = metin[i];
    if (tirnakIcinde) {
      if (c === '"') {
        if (metin[i + 1] === '"') { alan += '"'; i++; }
        else tirnakIcinde = false;
      } else alan += c;
    } else if (c === '"') {
      tirnakIcinde = true;
    } else if (c === ",") {
      satir.push(alan); alan = "";
    } else if (c === "\n" || c === "\r") {
      if (c === "\r" && metin[i + 1] === "\n") i++;
      satir.push(alan); alan = "";
      if (satir.length > 1 || satir[0] !== "") satirlar.push(satir);
      satir = [];
    } else {
      alan += c;
    }
  }
  if (alan !== "" || satir.length) { satir.push(alan); satirlar.push(satir); }
  return satirlar;
}

/* CSV metnini { tip, soru, secenekler, dogru_cevap, aciklama } dizisine çevirir.
   Beklenen sütunlar: tip,soru,secenek_a,secenek_b,secenek_c,secenek_d,dogru_cevap,aciklama
   tip = "cok_secmeli" | "bilgi_karti" (bilgi_karti satırlarında şık sütunları boş bırakılabilir). */
function ppCsvSorulariAyristir(metin) {
  const satirlar = ppCsvAyristir(metin);
  if (!satirlar.length) return [];
  const baslik = satirlar[0].map(s => (s || "").trim().toLowerCase());
  const idx = ad => baslik.indexOf(ad);
  const iTip = idx("tip"), iSoru = idx("soru"), iA = idx("secenek_a"), iB = idx("secenek_b"),
    iC = idx("secenek_c"), iD = idx("secenek_d"), iCevap = idx("dogru_cevap"), iAciklama = idx("aciklama");

  const sonuc = [];
  for (let r = 1; r < satirlar.length; r++) {
    const satir = satirlar[r];
    if (!satir.some(s => (s || "").trim() !== "")) continue;
    const tip = ((satir[iTip] || "").trim().toLowerCase() === "bilgi_karti") ? "bilgi_karti" : "cok_secmeli";
    const secenekler = [satir[iA], satir[iB], satir[iC], satir[iD]].map(s => (s || "").trim()).filter(Boolean);
    const soru = (satir[iSoru] || "").trim();
    const dogruCevap = (satir[iCevap] || "").trim();
    if (!soru || !dogruCevap) continue;
    sonuc.push({
      tip,
      soru,
      secenekler: tip === "cok_secmeli" ? secenekler : [],
      dogru_cevap: dogruCevap,
      aciklama: (satir[iAciklama] || "").trim()
    });
  }
  return sonuc;
}
