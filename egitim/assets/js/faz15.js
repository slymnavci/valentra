/* =========================================================
   FAZ 1.5 — Kritik kullanım düzeltmeleri
   - Takvim kayıtları Çalışma Planına öncelikli yansır
   - PPTX sayfaları 16:9 büyük/tam ekran slayt olarak gösterilir
   - OpenAI model listesi kullanıcının API erişiminden dinamik yüklenir
   Bu dosya mevcut app.js/CMS kodunu değiştirmeden küçük uyumluluk
   katmanı olarak sonradan yüklenir.
   ========================================================= */
(() => {
  "use strict";

  /* -------------------------------------------------------
     1) TAKVİM → ÇALIŞMA PLANI
     ------------------------------------------------------- */
  const faz15EskiPlanOlustur = typeof planOlustur === "function" ? planOlustur : null;

  function faz15GunAdi(tarih) {
    return ["Pazar", "Pazartesi", "Salı", "Çarşamba", "Perşembe", "Cuma", "Cumartesi"][tarih.getDay()];
  }

  function faz15KonuToplamSayfa(k) {
    if (!k) return 1;
    const pdfSayfa = (k.materyaller || []).reduce((s, m) => s + (+m.toplamSayfa || 0), 0);
    const ozetSayfa = (k.ozetSayfalari || []).filter(s =>
      (typeof Auth !== "undefined" && Auth.yonetici && Auth.yonetici()) || (s.durum || "yayinda") === "yayinda"
    ).length;
    return Math.max(1, pdfSayfa, ozetSayfa);
  }

  function faz15TakvimGorevleri(iso) {
    if (typeof takvimGunPlani !== "function") return [];
    return (takvimGunPlani(iso) || []).map(kayit => {
      const d = DERSLER.find(x => x.id === kayit.dersId);
      const k = d && konuBul(d.id, kayit.konuId);
      if (!d || !k) return null;

      const bas = new Date(String(kayit.baslangic) + "T12:00:00");
      const bit = new Date(String(kayit.bitis) + "T12:00:00");
      const gunSayisi = Number.isFinite(+bas) && Number.isFinite(+bit)
        ? Math.max(1, Math.round((bit - bas) / 86400000) + 1)
        : 1;
      const sayfa = Math.max(1, Math.ceil(faz15KonuToplamSayfa(k) / gunSayisi));

      return {
        dersId: d.id,
        dersIkon: d.ikon,
        konuId: k.id,
        konuAd: k.ad,
        oncelik: k.oncelik || "C",
        sayfa,
        tekrar: false,
        takvim: true
      };
    }).filter(Boolean);
  }

  function faz15BirlesikPlan() {
    if (!faz15EskiPlanOlustur) return [];
    const otomatik = faz15EskiPlanOlustur();
    if (!otomatik.length) return otomatik;

    /* Eski plan UTC toISOString kullandığı için Türkiye gibi pozitif
       saat dilimlerinde tarih bir gün kayabiliyordu. Dizi her takvim günü
       için bir kayıt ürettiğinden, tarihleri bugünden itibaren yerel ISO
       biçimiyle tekrar kuruyoruz. */
    const baslangic = new Date();
    baslangic.setHours(12, 0, 0, 0);

    return otomatik.map((gun, idx) => {
      const tarih = new Date(baslangic);
      tarih.setDate(baslangic.getDate() + idx);
      const iso = typeof takvimIso === "function"
        ? takvimIso(tarih)
        : `${tarih.getFullYear()}-${String(tarih.getMonth() + 1).padStart(2, "0")}-${String(tarih.getDate()).padStart(2, "0")}`;
      const temel = Object.assign({}, gun, { tarih: iso, gunAdi: faz15GunAdi(tarih) });
      const gorevler = faz15TakvimGorevleri(iso);
      if (!gorevler.length) return temel;

      return {
        tarih: iso,
        gunAdi: faz15GunAdi(tarih),
        gorevler,
        hedefSayfa: gorevler.reduce((s, g) => s + g.sayfa, 0),
        takvimGunu: true
      };
    });
  }

  if (faz15EskiPlanOlustur) planOlustur = faz15BirlesikPlan;

  /* Ana Sayfa'daki eski ayrı “Takvim Planınıza Göre Bugün” kartı artık
     gereksiz: Bugünün hedefi zaten birleştirilmiş planı kullanıyor. */
  function faz15EskiTakvimKartiniTemizle() {
    const alan = document.getElementById("content");
    if (!alan) return;
    alan.querySelectorAll("h3").forEach(h => {
      if ((h.textContent || "").includes("Takvim Planınıza Göre Bugün")) {
        const kart = h.closest(".card");
        if (kart) kart.remove();
      }
    });
  }

  /* -------------------------------------------------------
     2) PPTX → 16:9 SLAYT OKUYUCU
     ------------------------------------------------------- */
  if (typeof iyOzetSayfaEkle === "function") {
    const eskiEkle = iyOzetSayfaEkle;
    iyOzetSayfaEkle = function(konuId, sayfa) {
      eskiEkle(konuId, sayfa);
      if (!sayfa || !sayfa.slaytLayout) return;
      const k = iyKonuBul(konuId);
      const son = k && k.ozetSayfalari && k.ozetSayfalari[k.ozetSayfalari.length - 1];
      if (son) {
        son.slaytLayout = sayfa.slaytLayout;
        if (typeof icerikTaslakKaydet === "function") icerikTaslakKaydet();
      }
    };
  }

  if (typeof iyOzetSayfaGuncelle === "function") {
    const eskiGuncelle = iyOzetSayfaGuncelle;
    iyOzetSayfaGuncelle = function(konuId, idx, sayfa) {
      eskiGuncelle(konuId, idx, sayfa);
      if (!sayfa || !sayfa.slaytLayout) return;
      const k = iyKonuBul(konuId);
      if (k && k.ozetSayfalari && k.ozetSayfalari[idx]) {
        k.ozetSayfalari[idx].slaytLayout = sayfa.slaytLayout;
        if (typeof icerikTaslakKaydet === "function") icerikTaslakKaydet();
      }
    };
  }

  if (typeof sayfaEklePptxGonder === "function") {
    sayfaEklePptxGonder = async function(konuId) {
      const input = document.getElementById("sayfaEklePptxDosya");
      const f = input && input.files && input.files[0];
      const durum = document.getElementById("sayfaEklePptxDurum");
      if (!f) { if (durum) durum.textContent = "Lütfen bir .pptx dosyası seçin."; return; }
      if (typeof ppBagliMi !== "function" || !ppBagliMi()) {
        alert("Sayfa eklemek için önce sunucu bağlantısını kurmanız gerekir.");
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
          const gorselDosyaRid = {};

          for (const g of (slayt.gorseller || [])) {
            try {
              const dosyaNesnesi = new File([g.blob], g.ad, { type: g.blob.type || "image/png" });
              const sonuc = await kuDosyaYukle(dosyaNesnesi);
              gorseller.push({ id: iyBenzersizId("gorsel"), dosya: sonuc.yol, baslik: "" });
              if (g.rid) gorselDosyaRid[g.rid] = sonuc.yol;
            } catch (e) {
              console.warn("PPTX görseli yüklenemedi:", e.message);
            }
          }

          const ogeler = (slayt.ogeler || []).map(o => {
            if (o.tip === "gorsel") {
              const dosya = o.rid ? gorselDosyaRid[o.rid] : null;
              if (!dosya) return null;
              return Object.assign({ tip: "gorsel", dosya }, pptxKonumYuzdeye(o, slayt.slaytBoyut));
            }
            return Object.assign({ tip: o.tip, html: o.html || "" }, pptxKonumYuzdeye(o, slayt.slaytBoyut));
          }).filter(Boolean);

          const konumluOgeSayisi = ogeler.filter(o => !o.konumsuz).length;
          iyOzetSayfaEkle(konuId, {
            baslik: slayt.baslik,
            icerik: slayt.icerikHtml,
            gorseller,
            duzen: gorseller.length ? "gorsel-ust" : "metin",
            tip: "Özet",
            oncelik: "B",
            durum: "yayinda",
            slaytLayout: konumluOgeSayisi ? { ogeler } : null
          });
        }
        if (durum) durum.textContent = `✅ ${slaytlar.length} slayt sayfa olarak eklendi.`;
        setTimeout(() => icerikYenileKonu(konuId), 900);
      } catch (e) {
        if (durum) durum.textContent = "";
        alert("PPTX işlenemedi: " + e.message);
      }
    };
  }

  function faz15SlaytOkuHtml(d, k, sayfalar, idx, s) {
    const ay = Ayar.oku();
    const listeGizli = ay.ozetMenuGizli;
    window.__ctx = { ders: d.ad, konu: k.ad, standart: k.standart, sayfa: idx + 1, bolum: s.baslik || "", dersId: d.id, konuId: k.id };
    window.__pdfOkuyucuBaglam = null;

    const menuSatirlari = sayfalar.map((sf, i) => {
      const oniz = (sf.gorseller || [])[0];
      return `<div class="pdf-thumb slayt-thumb ${i === idx ? "aktif" : ""}" onclick="git('ozetoku/${d.id}/${k.id}/${i}')">
        <div class="slayt-thumb-16-9">${oniz ? `<img src="${oniz.dosya}" alt="">` : `<span>${i + 1}</span>`}</div>
        <span>${esc(sf.baslik || ("Slayt " + (i + 1)))}</span>
      </div>`;
    }).join("");

    const ogelerHtml = ((s.slaytLayout && s.slaytLayout.ogeler) || []).map(o => {
      const konumStil = o.konumsuz
        ? "position:relative;margin:0 auto 14px;max-width:82%"
        : `position:absolute;left:${o.xYuzde}%;top:${o.yYuzde}%;width:${o.cxYuzde}%;height:${o.cyYuzde}%`;
      if (o.tip === "gorsel") {
        return `<div class="slayt-oge slayt-oge-gorsel" style="${konumStil}"><img src="${o.dosya}" alt=""></div>`;
      }
      const sinif = o.tip === "baslik" ? "slayt-oge slayt-oge-baslik" : "slayt-oge slayt-oge-metin";
      return `<div class="${sinif}" style="${konumStil}">${o.html || ""}</div>`;
    }).join("");

    return `<div class="breadcrumb">
        <a onclick="git('dersler')">Dersler</a> /
        <a onclick="git('dersler/${d.id}')">${esc(d.ad)}</a> /
        <a onclick="git('dersler/${d.id}/${k.id}')">${esc(k.ad)}</a> / Slayt</div>
      <div class="reader slayt-reader" id="reader" style="grid-template-columns:${listeGizli ? "100%" : "180px 1fr"}">
        ${listeGizli ? "" : `<div class="pdf-thumb-rail slayt-thumb-rail" id="pdfThumbRail">${menuSatirlari}</div>`}
        <div class="reader-main" id="readerMain">
          <div class="reader-toolbar">
            <button class="btn btn-sm" id="slaytOncekiBtn" onclick="git('ozetoku/${d.id}/${k.id}/${idx - 1}')" ${idx <= 0 ? "disabled" : ""}>◀</button>
            <strong id="slaytSayac">${idx + 1} / ${sayfalar.length}</strong>
            <button class="btn btn-sm" id="slaytSonrakiBtn" onclick="git('ozetoku/${d.id}/${k.id}/${idx + 1}')" ${idx >= sayfalar.length - 1 ? "disabled" : ""}>▶</button>
            <div style="flex:1"></div>
            <button class="btn btn-sm" onclick="ozetMenuGizle()">${listeGizli ? "Listeyi Göster" : "Listeyi Gizle"}</button>
            <button class="btn btn-sm" onclick="menuGizle()">☰ Menü</button>
            <button class="btn btn-sm" onclick="tamEkran()">⛶ Tam Ekran</button>
          </div>
          <div class="slayt-sahne-alan">
            <div class="slayt-sahne" id="slaytSahne">${ogelerHtml}</div>
          </div>
        </div>
      </div>`;
  }

  if (typeof sayfaOzetOku === "function") {
    const eskiSayfaOzetOku = sayfaOzetOku;
    sayfaOzetOku = function(dersId, konuId, idxStr) {
      const d = DERSLER.find(x => x.id === dersId);
      const k = konuBul(dersId, konuId);
      const sayfalar = k && k.ozetSayfalari || [];
      if (!d || !k || !sayfalar.length) return eskiSayfaOzetOku(dersId, konuId, idxStr);
      const idx = Math.min(Math.max(0, parseInt(idxStr, 10) || 0), sayfalar.length - 1);
      const s = sayfalar[idx];
      if (s && s.slaytLayout && (s.slaytLayout.ogeler || []).length) {
        return faz15SlaytOkuHtml(d, k, sayfalar, idx, s);
      }
      return eskiSayfaOzetOku(dersId, konuId, idxStr);
    };
  }

  document.addEventListener("keydown", e => {
    if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") return;
    if (!document.getElementById("slaytSahne")) return;
    const aktif = document.activeElement;
    if (aktif && /^(INPUT|TEXTAREA|SELECT)$/.test(aktif.tagName)) return;
    const btn = document.getElementById(e.key === "ArrowRight" ? "slaytSonrakiBtn" : "slaytOncekiBtn");
    if (btn && !btn.disabled) { e.preventDefault(); btn.click(); }
  });

  /* -------------------------------------------------------
     3) OPENAI MODEL LİSTESİ / AKTİF MODEL
     ------------------------------------------------------- */
  if (typeof Ayar !== "undefined" && Ayar.varsayilan) {
    const eskiVarsayilan = Ayar.varsayilan.bind(Ayar);
    Ayar.varsayilan = function() {
      const a = eskiVarsayilan();
      if (!a.openaiModel || a.openaiModel === "gpt-4o-mini") a.openaiModel = "gpt-5.6-terra";
      return a;
    };
  }
  if (typeof AI_ALAN_ESLESTIRME !== "undefined" && AI_ALAN_ESLESTIRME.openai) {
    AI_ALAN_ESLESTIRME.openai.varsayilanModel = "gpt-5.6-terra";
  }

  let faz15ModelYukleniyor = false;
  let faz15ModelCache = null;

  function faz15ModelEtiketi(id) {
    if (!id) return "—";
    return id.replace(/^gpt-/i, "GPT-").replace(/(^|-)o(\d)/i, "$1o$2");
  }

  function faz15ModelDurumYaz(metin, hata) {
    const el = document.getElementById("faz15OpenaiModelDurum");
    if (!el) return;
    el.textContent = metin;
    el.classList.toggle("hata", !!hata);
  }

  function faz15AktifModelGuncelle() {
    const input = document.getElementById("oaModel");
    const etiket = document.getElementById("faz15AktifModel");
    if (input && etiket) etiket.textContent = "Aktif Model: " + faz15ModelEtiketi(input.value.trim() || "gpt-5.6-terra");
  }

  async function faz15OpenaiModelleriniYukle(zorla) {
    const input = document.getElementById("oaModel");
    const liste = document.getElementById("oaModelListe");
    if (!input || !liste || faz15ModelYukleniyor) return;
    if (faz15ModelCache && !zorla) {
      liste.innerHTML = faz15ModelCache.map(m => `<option value="${m.replace(/"/g, "&quot;")}"></option>`).join("");
      faz15ModelDurumYaz(`${faz15ModelCache.length} erişilebilir model API hesabınızdan yüklendi.`, false);
      return;
    }

    const u = typeof Auth !== "undefined" && Auth.aktif ? Auth.aktif() : null;
    if (!u || typeof ppBagliMi !== "function" || !ppBagliMi()) {
      faz15ModelDurumYaz("Sunucu bağlantısı kurulunca erişilebilir modeller otomatik yüklenecek.", false);
      return;
    }
    if (typeof kuOpenaiModelleriGetir !== "function") return;

    faz15ModelYukleniyor = true;
    faz15ModelDurumYaz("OpenAI hesabınızdaki erişilebilir modeller yükleniyor…", false);
    try {
      const modeller = await kuOpenaiModelleriGetir(u.kullaniciAdi);
      if (!modeller.length) throw new Error("Kullanılabilir model bulunamadı.");
      faz15ModelCache = modeller;
      liste.innerHTML = modeller.map(m => `<option value="${m.replace(/"/g, "&quot;")}"></option>`).join("");
      faz15ModelDurumYaz(`${modeller.length} erişilebilir model API hesabınızdan yüklendi.`, false);
    } catch (e) {
      faz15ModelDurumYaz("Model listesi alınamadı: " + e.message + " Model adını elle de yazabilirsiniz.", true);
    } finally {
      faz15ModelYukleniyor = false;
    }
  }

  function faz15OpenaiUiHazirla() {
    const input = document.getElementById("oaModel");
    if (!input) return;
    input.placeholder = "gpt-5.6-terra";

    if (!document.getElementById("faz15AktifModel")) {
      const kutu = document.createElement("div");
      kutu.className = "faz15-ai-model-kutu";
      kutu.innerHTML = `<strong id="faz15AktifModel"></strong>
        <button type="button" class="btn btn-sm" id="faz15ModelYenile">Modelleri Yenile</button>
        <div class="muted" id="faz15OpenaiModelDurum"></div>`;
      const liste = document.getElementById("oaModelListe");
      (liste || input).insertAdjacentElement("afterend", kutu);
      const yenile = document.getElementById("faz15ModelYenile");
      if (yenile) yenile.addEventListener("click", () => faz15OpenaiModelleriniYukle(true));
      input.addEventListener("input", faz15AktifModelGuncelle);
      input.addEventListener("change", faz15AktifModelGuncelle);
    }

    faz15AktifModelGuncelle();
    faz15OpenaiModelleriniYukle(false);
  }

  let faz15UiZamanlayici = null;
  function faz15ArayuzSonrasi() {
    clearTimeout(faz15UiZamanlayici);
    faz15UiZamanlayici = setTimeout(() => {
      faz15EskiTakvimKartiniTemizle();
      faz15OpenaiUiHazirla();
    }, 0);
  }

  const hedef = document.getElementById("uygulama") || document.body;
  new MutationObserver(faz15ArayuzSonrasi).observe(hedef, { childList: true, subtree: true });
  window.addEventListener("hashchange", faz15ArayuzSonrasi);
  document.addEventListener("DOMContentLoaded", faz15ArayuzSonrasi);
  faz15ArayuzSonrasi();
})();
