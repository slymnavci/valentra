/* =========================================================
   DERS ASİSTANI — bağlantı görünürlüğü + gerçek sayfa bağlamı
   ---------------------------------------------------------
   1) PPTX slaydının ekranda görünen gerçek metnini AI bağlamına ekler.
   2) AI hatasında sessizce yerel/sabit cevaba düşmeyi engeller.
   3) Üyelik ve Ayarlar ekranına gerçek OpenAI bağlantı testi ekler.
   ========================================================= */
(() => {
  "use strict";

  function aiFixMetniTemizle(metin) {
    return String(metin || "")
      .replace(/\u00a0/g, " ")
      .replace(/[ \t]+/g, " ")
      .replace(/\n[ \t]+/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .trim();
  }

  function aiFixSayfaBaglaminiGuncelle() {
    const c = window.__ctx || (window.__ctx = {});
    const sahne = document.getElementById("slaytSahne");
    if (!sahne) return;

    const metin = aiFixMetniTemizle(sahne.innerText || sahne.textContent || "");
    if (metin.length >= 3) {
      c.sayfaMetni = metin.slice(0, 16000);
      c.sayfaKaynakTuru = "PPTX slaytı";
    }
  }

  /* Mevcut sistem talimatını koru; yalnızca gerçek sayfa metnini ve
     soruya odaklanma kurallarını ekle. */
  if (typeof sistemTalimati === "function") {
    const eskiSistemTalimati = sistemTalimati;
    sistemTalimati = function() {
      aiFixSayfaBaglaminiGuncelle();
      const c = window.__ctx || {};
      const temel = eskiSistemTalimati();
      const sayfaMetni = aiFixMetniTemizle(c.sayfaMetni || "");

      return `${temel}\n\nGERÇEK OKUMA SAYFASI / SLAYT METNİ\n${sayfaMetni || "Bu sayfa için çıkarılmış gerçek metin yok."}\n\nEK CEVAP KURALLARI\n1. Kullanıcının sorduğu soruya doğrudan cevap ver; soru istemedikçe konunun genel özetini baştan yazma.\n2. Soru mevcut slayttaki bir ifade, tablo, kavram veya karşılaştırmayla ilgiliyse önce GERÇEK OKUMA SAYFASI / SLAYT METNİ bölümünü esas al.\n3. Slayt metni ile genel bilgin çelişirse farkı açıkça belirt.\n4. Kullanıcı “buradaki”, “bu”, “şu tablo”, “bu fark” gibi ifadeler kullanırsa bunları mevcut sayfaya referans kabul et.\n5. Aynı sabit cevabı tekrar etme; her soruyu kendi anlamına göre cevapla.`;
    };
  }

  function aiFixSaglayiciAdi(saglayici) {
    return ({ openai: "ChatGPT", anthropic: "Claude", gemini: "Gemini" })[saglayici] || saglayici;
  }

  function aiFixSeciliModel(saglayici) {
    const a = typeof Ayar !== "undefined" && Ayar.oku ? Ayar.oku() : {};
    if (saglayici === "openai") return a.openaiModel || "gpt-5.6-terra";
    if (saglayici === "anthropic") return a.anthropicModel || "claude-sonnet-5";
    if (saglayici === "gemini") return a.geminiModel || "gemini-3.1-flash-lite";
    return "";
  }

  /* Sessiz yerel fallback kaldırıldı. API başarısızsa kullanıcı gerçek
     hatayı görür; böylece “ChatGPT” rozeti altında sabit yerel cevap
     gösterilmesi engellenir. */
  if (typeof yapayZekayaSor === "function") {
    yapayZekayaSor = async function(saglayici, soru) {
      const ad = aiFixSaglayiciAdi(saglayici);
      const u = typeof Auth !== "undefined" && Auth.aktif ? Auth.aktif() : null;
      const model = aiFixSeciliModel(saglayici);

      if (typeof ppBagliMi !== "function" || !ppBagliMi() || !u) {
        botYaz(`${ad} çağrısı yapılmadı.\n\nSunucu/oturum bağlantısı kurulu değil. Üyelik ve Ayarlar bölümünden API bağlantısını test edin.\n\nYerel moda otomatik geçiş yapılmadı.`);
        return;
      }

      aiFixSayfaBaglaminiGuncelle();
      const bekleyen = botYaz(`${ad}${model ? " · " + model : ""} yanıt hazırlıyor…`);
      const c = window.__ctx || {};
      const mesajlar = sohbet.slice(-6);

      try {
        const cevap = await kuAiSohbet(u.kullaniciAdi, saglayici, sistemTalimati(), mesajlar);
        if (bekleyen) bekleyen.remove();
        botYaz(cevap || "Modelden cevap alınamadı.", `${ad}${model ? " · " + model : ""} · ${c.materyal || c.konu || c.bolum || ""}`);
        sohbet.push({ rol: "asistan", metin: cevap });
      } catch (e) {
        if (bekleyen) bekleyen.remove();
        const kotaMi = /quota|insufficient|billing|kredi|bakiye/i.test(e.message || "");
        botYaz(`⚠️ ${ad} isteği başarısız oldu.\n\nModel: ${model || "—"}\nHata: ${e.message || "Bilinmeyen hata"}\n${kotaMi ? "\nAPI bakiyesi/kredisi veya kullanım limitini kontrol edin.\n" : ""}\nYerel bilgi tabanına otomatik geçiş YAPILMADI. Bu mesaj gerçek API sorununu göstermek içindir.`);
      }
    };
  }

  function aiFixChatRozetiniGuncelle() {
    const rozet = document.getElementById("aiMode");
    if (!rozet || typeof Ayar === "undefined" || !Ayar.oku) return;
    const a = Ayar.oku();
    if (a.aiSaglayici === "openai") {
      const yeni = `ChatGPT · ${a.openaiModel || "gpt-5.6-terra"}`;
      if (rozet.textContent !== yeni) rozet.textContent = yeni;
    }
  }

  function aiFixTestDurum(metin, hata) {
    const el = document.getElementById("aiBaglantiTestDurum");
    if (!el) return;
    el.textContent = metin;
    el.style.whiteSpace = "pre-line";
    el.style.marginTop = "10px";
    el.style.padding = "10px 12px";
    el.style.borderRadius = "8px";
    el.style.background = hata ? "#fff1f2" : "#ecfdf5";
    el.style.color = hata ? "#991b1b" : "#166534";
    el.style.border = hata ? "1px solid #fecdd3" : "1px solid #bbf7d0";
  }

  async function aiFixBaglantiyiTestEt() {
    const btn = document.getElementById("aiBaglantiTestBtn");
    if (btn) { btn.disabled = true; btn.textContent = "Test ediliyor…"; }
    aiFixTestDurum("Kayıtlı API anahtarıyla OpenAI'ya gerçek bir test isteği gönderiliyor…", false);

    try {
      if (typeof ppIstek !== "function") throw new Error("Sunucu istemcisi yüklenemedi.");
      const sonuc = await ppIstek("ai_test.php", { method: "POST", body: JSON.stringify({}) });
      aiFixTestDurum(
        `API anahtarı: Kayıtlı ✅\nOpenAI bağlantısı: Başarılı ✅\nİstenen model: ${sonuc.istenen_model || "—"}\nGerçek kullanılan model: ${sonuc.gercek_model || sonuc.istenen_model || "—"}\nTest yanıtı: ${sonuc.cevap || "(yanıt metni boş, HTTP çağrısı başarılı)"}\nSüre: ${sonuc.sure_ms != null ? sonuc.sure_ms + " ms" : "—"}`,
        false
      );
    } catch (e) {
      aiFixTestDurum(`OpenAI bağlantı testi BAŞARISIZ ❌\n\n${e.message || "Bilinmeyen hata"}\n\nAnahtar kayıtlı görünse bile seçilen model, API bakiyesi veya API isteği ayrıca sorunlu olabilir.`, true);
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = "API Bağlantısını Test Et"; }
    }
  }

  function aiFixAyarUiHazirla() {
    const modelInput = document.getElementById("oaModel");
    if (!modelInput || document.getElementById("aiBaglantiTestBtn")) return;

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn btn-sm";
    btn.id = "aiBaglantiTestBtn";
    btn.textContent = "API Bağlantısını Test Et";
    btn.style.marginLeft = "8px";
    btn.addEventListener("click", aiFixBaglantiyiTestEt);

    const yenile = document.getElementById("faz15ModelYenile");
    if (yenile) yenile.insertAdjacentElement("afterend", btn);
    else modelInput.insertAdjacentElement("afterend", btn);

    const durum = document.createElement("div");
    durum.id = "aiBaglantiTestDurum";
    const aktifModel = document.getElementById("faz15AktifModel");
    const modelKutu = aktifModel ? aktifModel.parentElement : null;
    (modelKutu || btn).insertAdjacentElement("afterend", durum);
  }

  let aiFixTimer = null;
  function aiFixArayuzSonrasi() {
    clearTimeout(aiFixTimer);
    aiFixTimer = setTimeout(() => {
      aiFixSayfaBaglaminiGuncelle();
      aiFixAyarUiHazirla();
      aiFixChatRozetiniGuncelle();
    }, 0);
  }

  const hedef = document.getElementById("uygulama") || document.body;
  new MutationObserver(aiFixArayuzSonrasi).observe(hedef, { childList: true, subtree: true });
  window.addEventListener("hashchange", aiFixArayuzSonrasi);
  document.addEventListener("DOMContentLoaded", aiFixArayuzSonrasi);
  aiFixArayuzSonrasi();
})();
