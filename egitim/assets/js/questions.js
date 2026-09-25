/* =========================================================
   SORU BANKASI
   ---------------------------------------------------------
   Yapı:  SORULAR[dersId][konuId] = [ {soru}, ... ]
   Her soru: { id, bolum, s, tip, aciklama, ... }
     bolum    : konu başlığı (gruplama için)
     s        : soru metni
     tip      : "cok_secmeli" (varsayılan, alan yoksa da böyle kabul edilir)
                veya "bilgi_karti"
     o, d     : (yalnızca cok_secmeli) dört seçenek + doğru seçeneğin indeksi (0-3)
     cevap    : (yalnızca bilgi_karti) kartın arka yüzündeki cevap metni
     aciklama : neden doğru olduğu

   bilgi_karti öğeleri yalnızca Bilgi Kartları'nda gösterilir (gerçek
   şıkları olmadığından çoktan seçmeli sınava dahil edilemez) — bkz.
   sinavSorulariGetir().

   GEÇME NOTU: 60
   60 altında kalınırsa o konunun çalışma takvimi sıfırlanır.
   ========================================================= */

const GECME_NOTU = 60;

/* SORULAR artık burada sabit kod olarak değil, public/content/sorular.json
   dosyasından çalışma zamanında yükleniyor (bkz. assets/js/icerik-yukle.js). */
let SORULAR = {};
/* Konuya ait soru sayısı */
function soruSayisi(dersId, konuId) {
  return (SORULAR[dersId] && SORULAR[dersId][konuId]) ? SORULAR[dersId][konuId].length : 0;
}

/* Konuya ait TÜM soru/kartları getir (bilgi kartları dahil) */
function sorulariGetir(dersId, konuId) {
  return (SORULAR[dersId] && SORULAR[dersId][konuId]) ? SORULAR[dersId][konuId] : [];
}

/* Konuya ait yalnızca çoktan seçmeli (sınava girebilecek) soruları getir.
   tip alanı olmayan eski kayıtlar geriye dönük uyumluluk için
   "cok_secmeli" kabul edilir. */
function sinavSorulariGetir(dersId, konuId) {
  return sorulariGetir(dersId, konuId).filter(q => q.tip !== "bilgi_karti");
}

/* Bölümlere göre grupla */
function sorulariBolumle(dersId, konuId) {
  const liste = sorulariGetir(dersId, konuId);
  const grup = {};
  liste.forEach(q => {
    if (!grup[q.bolum]) grup[q.bolum] = [];
    grup[q.bolum].push(q);
  });
  return grup;
}

/* Sınav bölümlerini (yalnızca çoktan seçmeli) grupla — "Bu bölümü çöz" listesi için */
function sinavSorulariBolumle(dersId, konuId) {
  const grup = {};
  sinavSorulariGetir(dersId, konuId).forEach(q => {
    if (!grup[q.bolum]) grup[q.bolum] = [];
    grup[q.bolum].push(q);
  });
  return grup;
}
