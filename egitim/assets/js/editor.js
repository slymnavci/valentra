/* =========================================================
   ZENGİN METİN EDİTÖRÜ (paylaşılan bileşen)
   ---------------------------------------------------------
   contenteditable + document.execCommand tabanlı, küçük bir araç
   çubuğu. Build aracı/CDN gerektirmez. CTRL+V ile pano görselini
   doğrudan metne gömer (data URL olarak).

   Kullanım: editorHtml(id, mevcutHtml) bir HTML string'i döndürür,
   şablon literal içine gömülür. Kaydederken editorIcerikAl(id) ile
   güncel innerHTML okunur.
   ========================================================= */

function editorHtml(id, icerikHtml) {
  return `
    <div class="editor-sarmalayici">
      <div class="editor-arac-cubugu">
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','bold')" title="Kalın"><b>K</b></button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','italic')" title="İtalik"><i>İ</i></button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','underline')" title="Altı çizili"><u>A</u></button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','strikeThrough')" title="Üstü çizili"><s>Ü</s></button>
        <span class="editor-ayirici"></span>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorBlok('${id}','H2')" title="Büyük başlık">H2</button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorBlok('${id}','H3')" title="Alt başlık">H3</button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorBlok('${id}','P')" title="Paragraf">P</button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorBlok('${id}','BLOCKQUOTE')" title="Alıntı">❝</button>
        <span class="editor-ayirici"></span>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','insertUnorderedList')" title="Madde işaretli liste">•≡</button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','insertOrderedList')" title="Numaralı liste">1≡</button>
        <span class="editor-ayirici"></span>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','justifyLeft')" title="Sola hizala">◀</button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','justifyCenter')" title="Ortala">▬</button>
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','justifyRight')" title="Sağa hizala">▶</button>
        <span class="editor-ayirici"></span>
        <input type="color" class="editor-renk" onmousedown="event.stopPropagation()" oninput="editorKomut('${id}','foreColor',this.value)" title="Yazı rengi" value="#0f172a">
        <button type="button" class="editor-btn" onmousedown="event.preventDefault()" onclick="editorKomut('${id}','removeFormat')" title="Biçimlendirmeyi temizle">⌫</button>
      </div>
      <div class="editor-alan" id="${id}" contenteditable="true"
           onpaste="editorYapistir(event,'${id}')">${icerikHtml || ""}</div>
      <div class="muted" style="font-size:11.5px;padding:6px 10px">Panodan görsel yapıştırmak için CTRL+V kullanabilirsiniz.</div>
    </div>`;
}

function editorKomut(id, komut, deger) {
  const el = document.getElementById(id);
  if (el) el.focus();
  document.execCommand(komut, false, deger || null);
}

function editorBlok(id, etiket) {
  const el = document.getElementById(id);
  if (el) el.focus();
  document.execCommand("formatBlock", false, etiket);
}

function editorYapistir(e, id) {
  const veri = e.clipboardData || window.clipboardData;
  if (!veri || !veri.items) return;
  for (const oge of veri.items) {
    if (oge.type && oge.type.indexOf("image") === 0) {
      e.preventDefault();
      const dosya = oge.getAsFile();
      if (!dosya) return;
      const okuyucu = new FileReader();
      okuyucu.onload = ev => {
        const el = document.getElementById(id);
        if (el) el.focus();
        document.execCommand("insertImage", false, ev.target.result);
      };
      okuyucu.readAsDataURL(dosya);
      return;
    }
  }
  /* Görsel değilse tarayıcının varsayılan metin yapıştırma davranışı çalışır. */
}

function editorIcerikAl(id) {
  const el = document.getElementById(id);
  return el ? el.innerHTML.trim() : "";
}

/* ---------------------------------------------------------
   GÜVENLİ HTML — yönetici OLMAYAN kullanıcıların (ör. forum
   üyeleri) yazdığı zengin metni saklamadan/GÖSTERMEDEN önce
   dar bir izinli etiket/öznitelik alt kümesine indirger.
   Yönetimin yazdığı içerik (özet/özel sayfalar) için GEREKLİ
   DEĞİLDİR — orada tek yazar zaten güvenilen yöneticidir; ama
   forumda herhangi bir üye contenteditable alanına devtools'tan
   veya panodan keyfi HTML/script enjekte edebilir, bu yüzden
   hem gönderirken hem de her göstermeden önce uygulanmalıdır
   (savunma derinliği — saklanan veri hangi yoldan yazılmış
   olursa olsun, ekrana hep bu süzgeçten geçmiş hâliyle basılır).
   ========================================================= */
const EDITOR_IZINLI_ETIKETLER = new Set([
  "B", "STRONG", "I", "EM", "U", "S", "STRIKE", "P", "BR",
  "UL", "OL", "LI", "BLOCKQUOTE", "H2", "H3", "DIV", "SPAN", "A", "IMG"
]);
const EDITOR_IZINLI_STIL = /^(color|background-color|text-align)\s*:\s*[#a-zA-Z0-9(),.\s%-]+;?$/i;

function editorGuvenliHtml(ham) {
  const sarmalayici = document.createElement("div");
  sarmalayici.innerHTML = ham || "";
  editorDugunTemizle(sarmalayici);
  return sarmalayici.innerHTML.trim();
}

/* ebeveyn'in ÇOCUKLARINI temizler. Bir çocuk izinsizse önce KENDİ içeriği
   (henüz sarmalayıcı içindeyken) temizlenir, SONRA sarmalayıcı unwrap
   edilir — aksi hâlde `<font><script>...</script></font>` gibi iç içe
   bir kaçış, unwrap sonrası hâlâ süzülmemiş children bırakırdı (nextSibling
   ile ilerlediğimiz için forEach'in dondurulmuş anlık görüntüsüne
   güvenmiyoruz, bu yüzden yeni eklenen düğümler de sırayla işlenir). */
function editorDugunTemizle(ebeveyn) {
  let dugum = ebeveyn.firstChild;
  while (dugum) {
    const sonraki = dugum.nextSibling;
    if (dugum.nodeType === Node.TEXT_NODE) { dugum = sonraki; continue; }
    if (dugum.nodeType !== Node.ELEMENT_NODE) { ebeveyn.removeChild(dugum); dugum = sonraki; continue; }

    if (!EDITOR_IZINLI_ETIKETLER.has(dugum.tagName)) {
      if (dugum.tagName === "SCRIPT" || dugum.tagName === "STYLE" || dugum.tagName === "IFRAME") {
        ebeveyn.removeChild(dugum);
      } else {
        editorDugunTemizle(dugum); // unwrap etmeden önce içini süz
        while (dugum.firstChild) ebeveyn.insertBefore(dugum.firstChild, dugum);
        ebeveyn.removeChild(dugum);
      }
      dugum = sonraki;
      continue;
    }

    [...dugum.attributes].forEach(oznitelik => {
      const ad = oznitelik.name.toLowerCase();
      if (ad.startsWith("on")) { dugum.removeAttribute(oznitelik.name); return; }
      if (ad === "style") {
        if (!EDITOR_IZINLI_STIL.test(oznitelik.value.trim())) dugum.removeAttribute("style");
        return;
      }
      if (dugum.tagName === "A" && ad === "href") {
        if (!/^(https?:|mailto:)/i.test(oznitelik.value.trim())) dugum.removeAttribute("href");
        return;
      }
      if (dugum.tagName === "IMG" && ad === "src") {
        if (!/^(https?:|data:image\/)/i.test(oznitelik.value.trim())) dugum.removeAttribute("src");
        return;
      }
      if (!["style", "href", "src", "alt", "title"].includes(ad)) dugum.removeAttribute(oznitelik.name);
    });
    if (dugum.tagName === "A") { dugum.setAttribute("target", "_blank"); dugum.setAttribute("rel", "noopener noreferrer"); }

    editorDugunTemizle(dugum);
    dugum = sonraki;
  }
}
