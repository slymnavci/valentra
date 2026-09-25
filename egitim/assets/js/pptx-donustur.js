/* =========================================================
   PPTX → ÖZET SAYFALARI DÖNÜŞTÜRÜCÜ
   ---------------------------------------------------------
   Bir .pptx dosyasını (ZIP içinde OOXML) tarayıcıda ayrıştırıp her
   slaytı bir Özet Sayfası'na (başlık + zengin metin + görseller)
   çevirir — PDF'teki gibi sayfa-görüntüsü DEĞİL, gerçek metin/HTML
   üretir; böylece sonuç PDF'e göre çok daha hafif/hızlı açılır.
   JSZip (public/assets/js/vendor/jszip.min.js, self-hosted — CDN
   SRI doğrulaması bu ortamda yapılamadığından npm'den doğrulanmış
   paket doğrudan repoya eklendi) ile ZIP açılır, DOMParser ile
   slayt XML'leri okunur.
   ========================================================= */

const PPTX_NS_A = "http://schemas.openxmlformats.org/drawingml/2006/main";
const PPTX_NS_P = "http://schemas.openxmlformats.org/presentationml/2006/main";
const PPTX_NS_R = "http://schemas.openxmlformats.org/officeDocument/2006/relationships";

/* dosya: File/Blob (.pptx). ilerlemeCagir(i, toplam) opsiyonel.
   Döner: [{ baslik, icerikHtml, gorseller: [{ad, blob}], ogeler, slaytBoyut }, ...]
   `ogeler`: her metin/görsel şeklin OOXML sırası, tipi (baslik|metin|gorsel)
   ve orijinal konumu (x, y, cx, cy — EMU, ppt/presentation.xml'deki
   <p:sldSz>'e göre) — büyük 16:9 slayt okuyucusunun PowerPoint'teki
   yerleşimi mümkün olduğunca koruyabilmesi için (bkz. app.js
   slaytOkuHtml/pptxKonumYuzdeye). Konumu çözülemeyen (placeholder'dan
   miras alınan, xfrm'siz) şekillerde x/y/cx/cy null döner — okuyucu bu
   durumda şekli normal akışa (üst üste) düşürür. */
async function pptxSlaytlariCikar(dosya, ilerlemeCagir) {
  if (typeof JSZip === "undefined") throw new Error("PPTX kütüphanesi yüklenemedi (jszip.min.js).");
  const zip = await JSZip.loadAsync(dosya);

  const slaytYollari = await pptxSlaytSirasiniCoz(zip);
  if (!slaytYollari.length) throw new Error("Bu dosyada slayt bulunamadı — geçerli bir .pptx dosyası mı?");

  const slaytBoyut = await pptxSunumBoyutuAl(zip);

  const sonuc = [];
  for (let i = 0; i < slaytYollari.length; i++) {
    if (ilerlemeCagir) ilerlemeCagir(i + 1, slaytYollari.length);
    const slaytYolu = slaytYollari[i];
    const slaytGirisi = zip.file(slaytYolu);
    if (!slaytGirisi) continue;
    const slaytXml = await slaytGirisi.async("string");

    const slaytAdi = slaytYolu.split("/").pop();
    const relsGirisi = zip.file(`ppt/slides/_rels/${slaytAdi}.rels`);
    const gorselHedefleri = relsGirisi ? await pptxIliskiGorselleriCoz(await relsGirisi.async("string")) : {};

    const { baslik, paragraflar, gorselRidListesi, ogeler } = pptxSlaytXmlAyristir(slaytXml);

    const gorseller = [];
    for (const rid of gorselRidListesi) {
      const hedefGoreli = gorselHedefleri[rid];
      if (!hedefGoreli) continue;
      const gorselYolu = "ppt/media/" + hedefGoreli.split("/").pop();
      const gorselGirisi = zip.file(gorselYolu);
      if (!gorselGirisi) continue;
      const blob = await gorselGirisi.async("blob");
      /* rid saklanır — sayfaEklePptxGonder() ogeler[].rid ile eşleştirip
         yerleşimdeki (slaytLayout) doğru görseli bulur; bir görsel
         yüklenemese bile diğerlerinin konumu kaymaz. */
      gorseller.push({ ad: gorselYolu.split("/").pop(), blob, rid });
    }

    sonuc.push({
      baslik: baslik || `Slayt ${i + 1}`,
      icerikHtml: paragraflar.map(p => `<p>${pptxEsc(p)}</p>`).join(""),
      gorseller,
      ogeler,
      slaytBoyut
    });
  }
  return sonuc;
}

/* ppt/presentation.xml'deki <p:sldSz cx=".." cy=".."/> (EMU cinsinden
   slayt genişlik/yüksekliği) okunur — bulunamazsa standart 16:9 EMU
   boyutuna (12192000x6858000) düşer. */
async function pptxSunumBoyutuAl(zip) {
  try {
    const sunumGirisi = zip.file("ppt/presentation.xml");
    if (!sunumGirisi) throw new Error("eksik");
    const doc = new DOMParser().parseFromString(await sunumGirisi.async("string"), "application/xml");
    const sldSz = doc.getElementsByTagNameNS(PPTX_NS_P, "sldSz")[0];
    const cx = sldSz && parseInt(sldSz.getAttribute("cx"), 10);
    const cy = sldSz && parseInt(sldSz.getAttribute("cy"), 10);
    if (!cx || !cy) throw new Error("boş");
    return { cx, cy };
  } catch {
    return { cx: 12192000, cy: 6858000 };
  }
}

/* ppt/presentation.xml'deki <p:sldId r:id="rIdX"> sırasını
   ppt/_rels/presentation.xml.rels ile dosya adına çevirir — slaytların
   GERÇEK sunum sırası, dosya adı sırasıyla her zaman aynı olmayabilir.
   Çözülemezse (beklenmeyen/eksik XML) dosya adına göre sayısal sıraya
   düşer — çoğu gerçek dünya .pptx'i için doğru sırayı verir. */
async function pptxSlaytSirasiniCoz(zip) {
  try {
    const sunumGirisi = zip.file("ppt/presentation.xml");
    const relsGirisi = zip.file("ppt/_rels/presentation.xml.rels");
    if (!sunumGirisi || !relsGirisi) throw new Error("eksik");

    const relsDoc = new DOMParser().parseFromString(await relsGirisi.async("string"), "application/xml");
    const relHarita = {};
    [...relsDoc.getElementsByTagName("Relationship")].forEach(r => {
      relHarita[r.getAttribute("Id")] = r.getAttribute("Target");
    });

    const sunumDoc = new DOMParser().parseFromString(await sunumGirisi.async("string"), "application/xml");
    const sldIdDugumleri = [...sunumDoc.getElementsByTagNameNS(PPTX_NS_P, "sldId")];
    const yollar = sldIdDugumleri
      .map(el => el.getAttributeNS(PPTX_NS_R, "id"))
      .map(rid => relHarita[rid])
      .filter(Boolean)
      .map(hedef => "ppt/" + hedef.replace(/^\.?\/*/, ""));
    if (!yollar.length) throw new Error("boş");
    return yollar;
  } catch {
    return Object.keys(zip.files)
      .filter(ad => /^ppt\/slides\/slide\d+\.xml$/.test(ad))
      .sort((a, b) => (+a.match(/slide(\d+)\.xml/)[1]) - (+b.match(/slide(\d+)\.xml/)[1]));
  }
}

/* slideN.xml.rels içindeki resim ilişkilerini {rId: hedefYol} olarak döner. */
async function pptxIliskiGorselleriCoz(relsXml) {
  const doc = new DOMParser().parseFromString(relsXml, "application/xml");
  const harita = {};
  [...doc.getElementsByTagName("Relationship")].forEach(r => {
    if (/image/i.test(r.getAttribute("Type") || "")) harita[r.getAttribute("Id")] = r.getAttribute("Target");
  });
  return harita;
}

/* Bir slaytın XML'ini ayrıştırır: ilk placeholder="title" (yoksa ilk
   metinli şekil) başlık, geri kalan şekillerin metni gövde paragrafları,
   <p:pic><a:blip r:embed="rId"> resim referansları olur. Şekiller
   <p:spTree> içindeki GERÇEK OOXML sırasıyla (metin/resim karışık)
   dolaşılır ve her biri için pptxKonumAl() ile x/y/cx/cy (EMU) okunup
   `ogeler` listesine eklenir — büyük slayt okuyucusu bunu kullanarak
   PowerPoint'teki orijinal yerleşimi mümkün olduğunca korur. */
function pptxSlaytXmlAyristir(xmlMetin) {
  const doc = new DOMParser().parseFromString(xmlMetin, "application/xml");
  const spTree = doc.getElementsByTagNameNS(PPTX_NS_P, "spTree")[0];
  const dugumler = spTree
    ? [...spTree.childNodes].filter(n => n.nodeType === 1 && (n.localName === "sp" || n.localName === "pic"))
    : [];

  let baslik = "";
  const govdeParagraflari = [];
  const gorselRidListesi = [];
  const ogeler = [];
  let govdeEklendiMi = false;

  dugumler.forEach(node => {
    const konum = pptxKonumAl(node);

    if (node.localName === "sp") {
      const txBody = node.getElementsByTagNameNS(PPTX_NS_P, "txBody")[0];
      if (!txBody) return;
      const paragrafDugumleri = [...txBody.getElementsByTagNameNS(PPTX_NS_A, "p")];
      const metinler = paragrafDugumleri
        .map(p => [...p.getElementsByTagNameNS(PPTX_NS_A, "t")].map(t => t.textContent).join(""))
        .filter(m => m.trim() !== "");
      if (!metinler.length) return;

      const phDugum = node.getElementsByTagNameNS(PPTX_NS_P, "ph")[0];
      const phTuru = phDugum ? (phDugum.getAttribute("type") || "") : "";
      const baslikMi = /title/i.test(phTuru) || (!baslik && !govdeEklendiMi);
      const html = metinler.map(m => `<p>${pptxEsc(m)}</p>`).join("");

      if (baslikMi && !baslik) {
        baslik = metinler.join(" ");
        ogeler.push({ tip: "baslik", html, x: konum.x, y: konum.y, cx: konum.cx, cy: konum.cy });
      } else {
        govdeEklendiMi = true;
        govdeParagraflari.push(...metinler);
        ogeler.push({ tip: "metin", html, x: konum.x, y: konum.y, cx: konum.cx, cy: konum.cy });
      }
    } else if (node.localName === "pic") {
      const blip = node.getElementsByTagNameNS(PPTX_NS_A, "blip")[0];
      const rid = blip ? blip.getAttributeNS(PPTX_NS_R, "embed") : null;
      if (!rid) return;
      gorselRidListesi.push(rid);
      ogeler.push({ tip: "gorsel", rid, x: konum.x, y: konum.y, cx: konum.cx, cy: konum.cy });
    }
  });

  return { baslik, paragraflar: govdeParagraflari, gorselRidListesi, ogeler };
}

/* Bir <p:sp>/<p:pic> düğümünün <p:spPr><a:xfrm><a:off/><a:ext/></a:xfrm>
   konumunu EMU cinsinden okur. xfrm yoksa (placeholder konumu slayt
   düzeninden miras alıyorsa) tamamı null döner — çağıran taraf bu
   durumda şekli normal akışa düşürür. */
function pptxKonumAl(node) {
  const xfrm = node.getElementsByTagNameNS(PPTX_NS_A, "xfrm")[0];
  if (!xfrm) return { x: null, y: null, cx: null, cy: null };
  const off = xfrm.getElementsByTagNameNS(PPTX_NS_A, "off")[0];
  const ext = xfrm.getElementsByTagNameNS(PPTX_NS_A, "ext")[0];
  const sayi = (el, ad) => {
    if (!el) return null;
    const v = parseInt(el.getAttribute(ad), 10);
    return Number.isFinite(v) ? v : null;
  };
  return { x: sayi(off, "x"), y: sayi(off, "y"), cx: sayi(ext, "cx"), cy: sayi(ext, "cy") };
}

/* Bir ogenin EMU konumunu (x,y,cx,cy) slayt boyutuna (sw,cy cinsinden
   slaytBoyut.cx/cy) göre yüzdeye çevirir — CSS'te aspect-ratio:16/9
   korunan sahnede left/top/width/height% olarak kullanılır. Konumu
   çözülemeyen ogeler için { konumsuz: true } döner. */
function pptxKonumYuzdeye(oge, slaytBoyut) {
  const sw = slaytBoyut && slaytBoyut.cx, sh = slaytBoyut && slaytBoyut.cy;
  if (oge.x == null || oge.y == null || oge.cx == null || oge.cy == null || !sw || !sh) {
    return { konumsuz: true };
  }
  return {
    konumsuz: false,
    xYuzde: +(oge.x / sw * 100).toFixed(3),
    yYuzde: +(oge.y / sh * 100).toFixed(3),
    cxYuzde: +(oge.cx / sw * 100).toFixed(3),
    cyYuzde: +(oge.cy / sh * 100).toFixed(3)
  };
}

function pptxEsc(metin) {
  const d = document.createElement("div");
  d.textContent = metin;
  return d.innerHTML;
}
