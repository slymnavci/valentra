/* Ders Asistanı sohbet kalıcılığı
   - Sohbetleri ders/konu/materyal bazında bu tarayıcıda saklar.
   - Sayfa değiştirildiğinde veya sayfa yenilendiğinde konuşma ekranda kalır.
   - API bağlamı için son mesajlar da korunur.
*/
(function () {
  "use strict";

  const DEPO_SURUMU = "ymm_asistan_sohbet_v1";
  const EKRAN_MESAJ_LIMITI = 100;
  const API_MESAJ_LIMITI = 30;

  let aktifAnahtar = null;
  let gozlemci = null;
  let kaydetZamanlayici = null;

  const orjAsistanBaslat = window.asistanBaslat;
  const orjBotYaz = window.botYaz;
  const orjKullaniciYaz = window.kullaniciYaz;

  if (typeof orjAsistanBaslat !== "function" || typeof orjBotYaz !== "function" || typeof orjKullaniciYaz !== "function") {
    return;
  }

  function kimlikParcasi(v) {
    return String(v == null || v === "" ? "_" : v)
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9çğıöşü_-]+/gi, "-")
      .slice(0, 120);
  }

  function depoAnahtari() {
    const c = window.__ctx || {};
    let kullanici = "anonim";
    try {
      const u = window.Auth && typeof Auth.aktif === "function" ? Auth.aktif() : null;
      kullanici = u && (u.kullaniciAdi || u.ad || u.email) ? (u.kullaniciAdi || u.ad || u.email) : "anonim";
    } catch (_) {}

    // Sayfayı özellikle anahtara katmıyoruz. Böylece aynı konu/materyal içinde
    // 1. sayfadan 20. sayfaya geçildiğinde aynı sohbet görünmeye devam eder.
    const ders = c.dersId || c.ders || "ders";
    const konu = c.konuId || c.konu || c.standart || "konu";
    const materyal = c.materyalId || c.materyal || "materyal";
    return [DEPO_SURUMU, kullanici, ders, konu, materyal].map(kimlikParcasi).join("::");
  }

  function ekrandakiMesajlariOku() {
    const b = document.getElementById("chatBody");
    if (!b) return [];

    return Array.from(b.querySelectorAll(".msg")).map((n) => {
      const kaynak = n.querySelector(".src");
      const kopya = n.cloneNode(true);
      const kopyaKaynak = kopya.querySelector(".src");
      if (kopyaKaynak) kopyaKaynak.remove();
      return {
        tip: n.classList.contains("user") ? "user" : "bot",
        metin: (kopya.textContent || "").trim(),
        kaynak: kaynak ? (kaynak.textContent || "").replace(/^Kaynak:\s*/i, "").trim() : ""
      };
    }).filter((m) => m.metin && m.metin !== "Yanıt hazırlanıyor...").slice(-EKRAN_MESAJ_LIMITI);
  }

  function durumuKaydet() {
    if (!aktifAnahtar) return;
    try {
      let apiGecmisi = [];
      if (typeof sohbet !== "undefined" && Array.isArray(sohbet)) {
        apiGecmisi = sohbet.slice(-API_MESAJ_LIMITI);
      }
      localStorage.setItem(aktifAnahtar, JSON.stringify({
        zaman: Date.now(),
        ekran: ekrandakiMesajlariOku(),
        sohbet: apiGecmisi
      }));
    } catch (e) {
      console.warn("Asistan sohbeti kaydedilemedi:", e);
    }
  }

  function kaydiOku() {
    if (!aktifAnahtar) return null;
    try {
      const ham = localStorage.getItem(aktifAnahtar);
      if (!ham) return null;
      const d = JSON.parse(ham);
      if (!d || !Array.isArray(d.ekran)) return null;
      return d;
    } catch (_) {
      return null;
    }
  }

  function mesajiCiz(m) {
    if (!m || !m.metin) return;
    if (m.tip === "user") orjKullaniciYaz(m.metin);
    else orjBotYaz(m.metin, m.kaynak || undefined);
  }

  function kaydiGeriYukle() {
    const d = kaydiOku();
    if (!d || !d.ekran.length) return false;

    const b = document.getElementById("chatBody");
    if (!b) return false;
    b.innerHTML = "";
    d.ekran.slice(-EKRAN_MESAJ_LIMITI).forEach(mesajiCiz);

    try {
      if (typeof sohbet !== "undefined") {
        sohbet = Array.isArray(d.sohbet) ? d.sohbet.slice(-API_MESAJ_LIMITI) : [];
      }
    } catch (_) {}

    b.scrollTop = b.scrollHeight;
    return true;
  }

  function gozlemciKur() {
    if (gozlemci) gozlemci.disconnect();
    const b = document.getElementById("chatBody");
    if (!b || typeof MutationObserver === "undefined") return;

    gozlemci = new MutationObserver(() => {
      clearTimeout(kaydetZamanlayici);
      kaydetZamanlayici = setTimeout(durumuKaydet, 25);
    });
    gozlemci.observe(b, { childList: true, subtree: true, characterData: true });
  }

  function temizleButonuEkle() {
    const rozet = document.getElementById("aiMode");
    if (!rozet || !rozet.parentElement || document.getElementById("aiSohbetTemizle")) return;
    const btn = document.createElement("button");
    btn.id = "aiSohbetTemizle";
    btn.className = "ikon-btn";
    btn.type = "button";
    btn.title = "Bu ders/konu sohbetini temizle";
    btn.textContent = "↺";
    btn.onclick = window.asistanSohbetTemizle;
    rozet.parentElement.insertBefore(btn, rozet.nextSibling);
  }

  window.asistanSohbetTemizle = function () {
    aktifAnahtar = aktifAnahtar || depoAnahtari();
    try { localStorage.removeItem(aktifAnahtar); } catch (_) {}
    try { if (typeof sohbet !== "undefined") sohbet = []; } catch (_) {}
    const b = document.getElementById("chatBody");
    if (b) b.innerHTML = "";

    const c = window.__ctx || {};
    orjBotYaz(`Merhaba. ${c.standart || c.konu || "Bu konu"} üzerinde çalışıyorsunuz.\n\nŞu anda ${c.sayfa}. sayfadasınız${c.bolum ? ` (${c.bolum})` : ""}.\n\nYeni bir sohbet başlattınız.`);
    durumuKaydet();
  };

  window.asistanBaslat = function () {
    // Orijinal fonksiyon rozet/model bilgisini ve boş sohbet için karşılama mesajını kurar.
    orjAsistanBaslat.apply(this, arguments);
    aktifAnahtar = depoAnahtari();
    kaydiGeriYukle();
    temizleButonuEkle();
    gozlemciKur();
  };

  // Sekme kapanmadan hemen önce son durumu garanti altına al.
  window.addEventListener("beforeunload", durumuKaydet);
})();
