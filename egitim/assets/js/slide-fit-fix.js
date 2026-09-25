/* PPTX slayt sahnesini mevcut okuyucu alanına gerçek 16:9 oranında mümkün olan en büyük boyutta sığdırır. */
(function () {
  "use strict";

  let ro = null;
  let izlenenAlan = null;
  let raf = null;

  function px(v) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : 0;
  }

  function slaytiSigdir() {
    const alan = document.querySelector(".slayt-sahne-alan");
    const sahne = document.getElementById("slaytSahne");
    if (!alan || !sahne) return;

    const cs = getComputedStyle(alan);
    const kullanilabilirGenislik = Math.max(0,
      alan.clientWidth - px(cs.paddingLeft) - px(cs.paddingRight));
    const kullanilabilirYukseklik = Math.max(0,
      alan.clientHeight - px(cs.paddingTop) - px(cs.paddingBottom));

    if (kullanilabilirGenislik < 40 || kullanilabilirYukseklik < 40) return;

    let genislik = Math.min(kullanilabilirGenislik, kullanilabilirYukseklik * 16 / 9);
    let yukseklik = genislik * 9 / 16;

    // Kesirli piksel taşmalarını engelle.
    genislik = Math.floor(genislik);
    yukseklik = Math.floor(yukseklik);

    sahne.style.setProperty("width", genislik + "px", "important");
    sahne.style.setProperty("height", yukseklik + "px", "important");
    sahne.style.setProperty("max-width", "none", "important");
    sahne.style.setProperty("max-height", "none", "important");
    sahne.style.setProperty("min-width", "0", "important");
    sahne.style.setProperty("flex", "0 0 auto", "important");
  }

  function planla() {
    if (raf) cancelAnimationFrame(raf);
    raf = requestAnimationFrame(() => {
      raf = null;
      slaytiSigdir();
    });
  }

  function gozlemciyiKur() {
    const alan = document.querySelector(".slayt-sahne-alan");
    if (!alan) return;
    if (alan === izlenenAlan) {
      planla();
      return;
    }

    if (ro) ro.disconnect();
    izlenenAlan = alan;
    if (typeof ResizeObserver !== "undefined") {
      ro = new ResizeObserver(planla);
      ro.observe(alan);
    }
    planla();
    setTimeout(planla, 80);
    setTimeout(planla, 300);
  }

  const mo = new MutationObserver(gozlemciyiKur);
  mo.observe(document.documentElement, { childList: true, subtree: true });

  window.addEventListener("resize", planla);
  document.addEventListener("fullscreenchange", () => setTimeout(planla, 40));
  document.addEventListener("DOMContentLoaded", gozlemciyiKur);
  gozlemciyiKur();
})();
