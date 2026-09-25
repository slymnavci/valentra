/* =========================================================
   KULLANICI VERİSİ SENKRON İSTEMCİSİ (FAZ 1)
   ---------------------------------------------------------
   state.sayfalar / state.sinavlar / Ayar.* için backend'e
   (aynı /api/ + X-App-Key deseni, ppIstek — bkz. pratik-api.js)
   konuşan istemci. Bu dosya UI'dan bağımsızdır: app.js içindeki
   DB/Ayar nesneleri bu fonksiyonları "arka planda senkronla"
   amacıyla çağırır. Backend'e erişilemezse (bağlantı yok, anahtar
   tanımlı değil vb.) hata fırlatır — çağıran taraf localStorage'a
   düşmeye devam eder, hiçbir özellik bu yüzden bozulmaz.
   ========================================================= */

async function kuIlerlemeIsaretle(kullaniciAdi, materyalId, sayfaNo, deger) {
  return ppIstek("ilerleme_isaretle.php", {
    method: "POST",
    body: JSON.stringify({ kullanici_adi: kullaniciAdi, materyal_id: materyalId, sayfa_no: sayfaNo, deger: !!deger })
  });
}

async function kuIlerlemeGetir(kullaniciAdi) {
  const sonuc = await ppIstek(`ilerleme_getir.php?kullanici_adi=${encodeURIComponent(kullaniciAdi)}`);
  return sonuc.sayfalar || {};
}

async function kuKonuSinavKaydet(kullaniciAdi, konuId, istemciId, dogru, toplam, puan, gecti) {
  const sonuc = await ppIstek("konusinav_kaydet.php", {
    method: "POST",
    body: JSON.stringify({ kullanici_adi: kullaniciAdi, konu_id: konuId, istemci_id: istemciId, dogru, toplam, puan, gecti: !!gecti })
  });
  return sonuc.id;
}

async function kuKonuSinavGetir(kullaniciAdi) {
  const sonuc = await ppIstek(`konusinav_getir.php?kullanici_adi=${encodeURIComponent(kullaniciAdi)}`);
  return sonuc.sinavlar || {};
}

async function kuAyarKaydet(kullaniciAdi, veri) {
  return ppIstek("ayar_kaydet.php", {
    method: "POST", body: JSON.stringify({ kullanici_adi: kullaniciAdi, veri })
  });
}

async function kuAyarGetir(kullaniciAdi) {
  const sonuc = await ppIstek(`ayar_getir.php?kullanici_adi=${encodeURIComponent(kullaniciAdi)}`);
  return sonuc.veri;
}

/* --------------------------------------------------------- */
/* AI ANAHTARLARI (sunucu tarafı, anahtar istemciye asla dönmez) */
/* --------------------------------------------------------- */
async function kuSirKaydet(kullaniciAdi, saglayici, anahtar, model) {
  return ppIstek("sir_kaydet.php", {
    method: "POST", body: JSON.stringify({ kullanici_adi: kullaniciAdi, saglayici, anahtar, model })
  });
}

async function kuSirSil(kullaniciAdi, saglayici) {
  return ppIstek("sir_sil.php", { method: "POST", body: JSON.stringify({ kullanici_adi: kullaniciAdi, saglayici }) });
}

/* Anahtarı olduğu gibi bırakıp yalnızca modeli günceller (bkz. sir_model_guncelle.php) —
   sağlayıcı için daha önce bir anahtar kaydedilmemişse hata fırlatır. */
async function kuSirModelGuncelle(kullaniciAdi, saglayici, model) {
  return ppIstek("sir_model_guncelle.php", {
    method: "POST", body: JSON.stringify({ kullanici_adi: kullaniciAdi, saglayici, model })
  });
}

async function kuSirDurum(kullaniciAdi) {
  const sonuc = await ppIstek(`sir_durum.php?kullanici_adi=${encodeURIComponent(kullaniciAdi)}`);
  return sonuc.durum;
}

/* Kullanıcının sunucuda kayıtlı OpenAI anahtarıyla erişebildiği model
   kimliklerini döner — anahtarın kendisi hiçbir zaman istemciye gelmez
   (bkz. openai_modeller_getir.php). Anahtar kayıtlı değilse veya
   sorgulanamazsa hata fırlatır — çağıran taraf (aiOpenaiModelleriYukle)
   bunu yakalayıp önerilen statik listeye düşer, ASLA otomatik/sessizce
   gpt-4o-mini'ye dönmez. */
async function kuOpenaiModelleriGetir(kullaniciAdi) {
  const sonuc = await ppIstek(`openai_modeller_getir.php?kullanici_adi=${encodeURIComponent(kullaniciAdi)}`);
  return sonuc.modeller || [];
}

/* sistem: talimat metni. mesajlar: [{rol:"kullanici"|"asistan", metin}]. */
async function kuAiSohbet(kullaniciAdi, saglayici, sistem, mesajlar) {
  const sonuc = await ppIstek("ai_sohbet.php", {
    method: "POST", body: JSON.stringify({ kullanici_adi: kullaniciAdi, saglayici, sistem, mesajlar })
  });
  return sonuc.cevap;
}

/* --------------------------------------------------------- */
/* İÇERİK KAYDETME (doğrudan sunucuya — GitHub'a gerek yok)   */
/* --------------------------------------------------------- */
/* dersler: {sinav, dersler} | null, sorular: SORULAR nesnesi | null,
   menu: {ogeler, ozelSayfalar} | null. Üçü de opsiyonel ama en az
   biri gönderilmeli. */
async function kuIcerikKaydet(dersler, sorular, menu) {
  return ppIstek("icerik_kaydet.php", {
    method: "POST", body: JSON.stringify({ dersler, sorular, menu })
  });
}

/* PDF/görsel dosyasını sunucudaki public/materyaller/ klasörüne yükler.
   FormData kullandığı için ppIstek yerine kendi fetch'ini yapar (Content-Type
   tarayıcı tarafından multipart sınırıyla otomatik ayarlanmalı). */
async function kuDosyaYukle(dosya) {
  const anahtar = ppAnahtarAl();
  const govde = new FormData();
  govde.append("dosya", dosya);
  const r = await fetch("api/dosya_yukle.php", {
    method: "POST",
    headers: { "X-App-Key": anahtar },
    body: govde
  });
  let sonuc = null;
  try { sonuc = await r.json(); } catch { /* boş/JSON-olmayan yanıt olabilir */ }
  if (!r.ok) {
    const mesaj = (sonuc && sonuc.hata) || r.statusText || "Bilinmeyen hata";
    throw new Error(`Dosya yükleme hatası (${r.status}): ${mesaj}`);
  }
  return sonuc;
}

/* --------------------------------------------------------- */
/* FORUM — kategori/konu/mesaj. Okuma herkese açık; konu açma
   ve mesaj ekleme uygulama anahtarı değil, kayıtlı bir üye
   olmayı gerektirir (bkz. forum_yardimci.php). */
/* --------------------------------------------------------- */
async function kuForumKategorilerGetir() {
  const sonuc = await ppIstek("forum_kategoriler_getir.php");
  return sonuc.kategoriler;
}

async function kuForumKategoriEkle(ad, aciklama) {
  const sonuc = await ppIstek("forum_kategori_ekle.php", {
    method: "POST", body: JSON.stringify({ ad, aciklama })
  });
  return sonuc.id;
}

async function kuForumKategoriSil(id) {
  return ppIstek("forum_kategori_sil.php", { method: "POST", body: JSON.stringify({ id }) });
}

async function kuForumKonularGetir(kategoriId) {
  const sonuc = await ppIstek(`forum_konular_getir.php?kategori_id=${encodeURIComponent(kategoriId)}`);
  return sonuc.konular;
}

async function kuForumKonuGetir(konuId) {
  return ppIstek(`forum_konu_getir.php?id=${encodeURIComponent(konuId)}`);
}

async function kuForumKonuAc(kategoriId, kullaniciAdi, baslik, icerik) {
  const sonuc = await ppIstek("forum_konu_ac.php", {
    method: "POST",
    body: JSON.stringify({ kategori_id: kategoriId, kullanici_adi: kullaniciAdi, baslik, icerik })
  });
  return sonuc.id;
}

async function kuForumMesajEkle(konuId, kullaniciAdi, icerik) {
  const sonuc = await ppIstek("forum_mesaj_ekle.php", {
    method: "POST", body: JSON.stringify({ konu_id: konuId, kullanici_adi: kullaniciAdi, icerik })
  });
  return sonuc.id;
}

async function kuForumKonuSil(id) {
  return ppIstek("forum_konu_sil.php", { method: "POST", body: JSON.stringify({ id }) });
}

async function kuForumMesajSil(id) {
  return ppIstek("forum_mesaj_sil.php", { method: "POST", body: JSON.stringify({ id }) });
}
