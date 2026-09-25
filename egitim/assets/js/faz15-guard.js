/* FAZ 1.5 — sadece faz15.js'in oluşturduğu MutationObserver için koruma.
   OpenAI model datalist/status alanındaki kendi DOM güncellemelerinin
   tekrar aynı gözlemciyi tetikleyip sonsuz döngü veya tekrarlı API isteği
   oluşturmasını engeller. Bir sonraki event-loop turunda yerleşik
   MutationObserver geri yüklenir; uygulamanın diğer kodlarını etkilemez. */
(() => {
  "use strict";
  const Yerlesik = window.MutationObserver;
  if (!Yerlesik) return;

  function KorunanMutationObserver(callback) {
    let gozlemci = null;
    gozlemci = new Yerlesik((kayitlar) => {
      const anlamli = kayitlar.filter(kayit => {
        let hedef = kayit.target;
        if (!hedef) return true;
        if (hedef.nodeType !== 1) hedef = hedef.parentElement;
        if (!hedef) return true;
        if (hedef.id === "oaModelListe") return false;
        if (hedef.closest && hedef.closest("#oaModelListe, .faz15-ai-model-kutu")) return false;
        return true;
      });
      if (anlamli.length) callback(anlamli, gozlemci);
    });
    return gozlemci;
  }

  KorunanMutationObserver.prototype = Yerlesik.prototype;
  window.MutationObserver = KorunanMutationObserver;

  /* faz15.js hemen sonraki klasik script olarak senkron çalışır. Sonraki
     görevde global constructor'ı eski haline getiriyoruz. */
  setTimeout(() => {
    if (window.MutationObserver === KorunanMutationObserver) window.MutationObserver = Yerlesik;
  }, 0);
})();
