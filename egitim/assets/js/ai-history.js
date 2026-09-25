/* =========================================================
   DERS ASİSTANI — SUNUCUDA SORU GEÇMİŞİ
   - Soru + cevapları kullanıcı hesabına bağlı saklar.
   - Ders/konu bazında listeler; cihazlar arasında ortaktır.
   - Kullanıcı isterse Geçmiş Sorular alanını gizleyebilir.
   ========================================================= */
(() => {
  "use strict";

  const GIZLI_KEY = "ymm_ai_gecmis_gizli_v1";
  let bekleyenSoru = null;
  let bekleyenBaglam = null;
  let kayitlar = [];
  let acikKayitId = null;

  function token() {
    try {
      if (typeof ppOturumTokenAl === "function") return ppOturumTokenAl();
      const o = JSON.parse(localStorage.getItem("ymm_oturum_v1") || "{}");
      return o.token || "";
    } catch { return ""; }
  }

  async function istek(yol, secenek) {
    const r = await fetch("api/ai_gecmis.php" + (yol || ""), Object.assign({
      headers: { "Content-Type": "application/json", "X-Session-Token": token() }
    }, secenek || {}));
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.hata || "AI geçmişi isteği başarısız.");
    return d;
  }

  function ctx() {
    const c = window.__ctx || {};
    return {
      ders_id: c.dersId || "",
      konu_id: c.konuId || "",
      ders_ad: c.ders || "",
      konu_ad: c.konu || ""
    };
  }

  function escHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }

  function tarihYaz(s) {
    try {
      const d = new Date(String(s).replace(" ", "T") + "Z");
      return d.toLocaleString("tr-TR", { day:"2-digit", month:"2-digit", hour:"2-digit", minute:"2-digit" });
    } catch { return s || ""; }
  }

  function gizliMi() { return localStorage.getItem(GIZLI_KEY) === "1"; }
  function gizliYaz(v) { localStorage.setItem(GIZLI_KEY, v ? "1" : "0"); }

  function panelBulYaDaOlustur() {
    const chatBody = document.getElementById("chatBody");
    if (!chatBody || !chatBody.parentElement) return null;
    let panel = document.getElementById("aiHistoryPanel");
    if (panel) return panel;

    panel = document.createElement("div");
    panel.id = "aiHistoryPanel";
    panel.className = "ai-history-panel";
    chatBody.parentElement.insertBefore(panel, chatBody);
    return panel;
  }

  function panelCiz() {
    const panel = panelBulYaDaOlustur();
    if (!panel) return;
    const gizli = gizliMi();
    const c = ctx();
    const baslikAlt = c.konu_ad ? escHtml(c.konu_ad) : "Bu ders/konudaki kayıtlar";

    if (gizli) {
      panel.classList.add("kapali");
      panel.innerHTML = `<div class="ai-history-head">
        <strong>🕘 Geçmiş Sorular</strong>
        <button class="btn btn-sm" onclick="aiGecmisGoster()" title="Geçmiş soruları göster">Göster</button>
      </div>`;
      return;
    }

    panel.classList.remove("kapali");
    const liste = kayitlar.length ? kayitlar.map(k => {
      const acik = String(acikKayitId) === String(k.id);
      return `<div class="ai-history-item">
        <div class="ai-history-q" onclick="aiGecmisAc(${k.id})">
          <span>${escHtml(k.soru)}</span><small>${tarihYaz(k.tarih)}</small>
        </div>
        ${acik ? `<div class="ai-history-answer">${escHtml(k.cevap).replace(/\n/g,"<br>")}</div>` : ""}
        <div class="ai-history-actions">
          <button class="btn btn-sm" onclick="aiGecmisAc(${k.id})">${acik ? "Kapat" : "Aç"}</button>
          <button class="btn btn-sm" onclick="aiGecmisTekrarSor(${k.id})">Tekrar sor</button>
          <button class="btn btn-sm tehlike" onclick="aiGecmisSil(${k.id})">Sil</button>
        </div>
      </div>`;
    }).join("") : `<div class="ai-history-empty">Henüz kayıtlı soru yok.</div>`;

    panel.innerHTML = `<div class="ai-history-head">
        <div><strong>🕘 Geçmiş Sorular</strong><small>${baslikAlt}</small></div>
        <div class="row">
          ${kayitlar.length ? `<button class="btn btn-sm tehlike" onclick="aiGecmisTumunuSil()" title="Bu ders/konu geçmişini temizle">Temizle</button>` : ""}
          <button class="btn btn-sm" onclick="aiGecmisGizle()" title="Bu alanı gizle">Gizle</button>
        </div>
      </div>
      <div class="ai-history-list">${liste}</div>`;
  }

  async function yukle() {
    if (!token()) { kayitlar = []; panelCiz(); return; }
    const c = ctx();
    const q = new URLSearchParams({ limit: "100" });
    if (c.ders_id) q.set("ders_id", c.ders_id);
    if (c.konu_id) q.set("konu_id", c.konu_id);
    try {
      const d = await istek("?" + q.toString());
      kayitlar = d.kayitlar || [];
    } catch (e) {
      console.warn("AI geçmişi yüklenemedi:", e.message);
      kayitlar = [];
    }
    panelCiz();
  }

  async function kaydet(soru, cevap, baglam) {
    if (!token() || !soru || !cevap) return;
    try {
      await istek("", {
        method: "POST",
        body: JSON.stringify(Object.assign({}, baglam || ctx(), { soru, cevap }))
      });
      await yukle();
    } catch (e) {
      console.warn("AI geçmişi kaydedilemedi:", e.message);
    }
  }

  function gecersizBotMesaji(metin) {
    const m = String(metin || "");
    return !m.trim() || m.includes("Yanıt hazırlanıyor") ||
      m.includes("çağrısı yapılmadı") || m.includes("Pratik sistemi hatası") ||
      m.includes("sunucu bağlantısı kurulu değil");
  }

  /* Kullanıcının gerçek sorusunu ve bunu izleyen gerçek AI cevabını yakala. */
  if (typeof window.kullaniciYaz === "function") {
    const eskiKullaniciYaz = window.kullaniciYaz;
    window.kullaniciYaz = function(metin) {
      bekleyenSoru = String(metin || "").trim();
      bekleyenBaglam = ctx();
      return eskiKullaniciYaz.apply(this, arguments);
    };
  }

  if (typeof window.botYaz === "function") {
    const eskiBotYaz = window.botYaz;
    window.botYaz = function(metin) {
      const sonuc = eskiBotYaz.apply(this, arguments);
      if (bekleyenSoru && !gecersizBotMesaji(metin)) {
        const s = bekleyenSoru;
        const b = bekleyenBaglam;
        bekleyenSoru = null;
        bekleyenBaglam = null;
        kaydet(s, String(metin || ""), b);
      }
      return sonuc;
    };
  }

  if (typeof window.asistanBaslat === "function") {
    const eskiBaslat = window.asistanBaslat;
    window.asistanBaslat = function() {
      const r = eskiBaslat.apply(this, arguments);
      setTimeout(yukle, 0);
      return r;
    };
  }

  window.aiGecmisGizle = function() { gizliYaz(true); panelCiz(); };
  window.aiGecmisGoster = function() { gizliYaz(false); panelCiz(); };
  window.aiGecmisAc = function(id) { acikKayitId = String(acikKayitId) === String(id) ? null : id; panelCiz(); };
  window.aiGecmisTekrarSor = function(id) {
    const k = kayitlar.find(x => String(x.id) === String(id));
    if (!k) return;
    const inp = document.getElementById("chatInput");
    if (inp) { inp.value = k.soru; inp.focus(); }
    if (typeof mesajGonder === "function") mesajGonder();
  };
  window.aiGecmisSil = async function(id) {
    if (!confirm("Bu soru-cevap kaydını silmek istiyor musunuz?")) return;
    try {
      await istek("", { method:"DELETE", body:JSON.stringify({ id }) });
      await yukle();
    } catch (e) { alert(e.message); }
  };
  window.aiGecmisTumunuSil = async function() {
    if (!confirm("Bu ders/konudaki tüm AI soru geçmişini silmek istiyor musunuz?")) return;
    const c = ctx();
    try {
      await istek("", { method:"DELETE", body:JSON.stringify({ ders_id:c.ders_id, konu_id:c.konu_id }) });
      acikKayitId = null;
      await yukle();
    } catch (e) { alert(e.message); }
  };
})();
