/* =========================================================
   KİMLİK DOĞRULAMA VE ÜYELİK
   ---------------------------------------------------------
   Hesaplar artık sunucuda saklanır (kullanici_hesap tablosu,
   bcrypt şifre hash'i) — bkz. public/api/giris_dogrula.php,
   hesap_ekle.php, hesap_sil.php, hesap_rol_degistir.php,
   hesap_sifre_degistir.php, oturum_kapat.php. Giriş, şifre
   değiştirme ve çıkış uç noktaları kasıtlı olarak uygulama
   anahtarı gerektirmez (yetki, kullanıcı adı+şifre doğrulaması
   veya oturum jetonundan gelir); üye ekleme/silme/rol
   değiştirme yalnızca yönetici uygulama anahtarıyla erişilebilir.

   Aktif oturum, sunucudan dönen kullanıcı bilgisiyle (jeton dahil)
   bu tarayıcıda (localStorage) önbelleğe alınır — böylece her
   sayfa geçişinde ağ isteği gerekmez. Çıkışta önce sunucudaki
   oturum jetonu geçersiz kılınmaya çalışılır, ardından yerel
   önbellek temizlenir (bkz. Auth.cikis).
   ========================================================= */

const OTURUM_KEY = "ymm_oturum_v1";

const Auth = {
  /* Sunucuda doğrula, başarılıysa oturumu bu tarayıcıda önbelleğe al. */
  async giris(kullaniciAdi, sifre) {
    try {
      const sonuc = await ppIstek("giris_dogrula.php", {
        method: "POST",
        body: JSON.stringify({ kullanici_adi: (kullaniciAdi || "").trim(), sifre })
      });
      localStorage.setItem(OTURUM_KEY, JSON.stringify(sonuc.kullanici));
      return { ok: true, kullanici: sonuc.kullanici };
    } catch (e) {
      return { ok: false, mesaj: e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "") };
    }
  },

  /* Herkese açık üye kaydı (bkz. api/kayit_ol.php). Başarılı kayıt
     aynı zamanda oturum açar; yanıt girişle aynı biçimdedir. */
  async kayit({ ad, eposta, kullaniciAdi, sifre, web }) {
    try {
      const sonuc = await ppIstek("kayit_ol.php", {
        method: "POST",
        body: JSON.stringify({ ad, eposta, kullanici_adi: (kullaniciAdi || "").trim(), sifre, web: web || "" })
      });
      localStorage.setItem(OTURUM_KEY, JSON.stringify(sonuc.kullanici));
      return { ok: true, kullanici: sonuc.kullanici };
    } catch (e) {
      return { ok: false, mesaj: e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "") };
    }
  },

  /* Aktif kullanıcı — önbellekten, ağ isteği yok. */
  aktif() {
    try { return JSON.parse(localStorage.getItem(OTURUM_KEY)); }
    catch { return null; }
  },

  yonetici() {
    const u = this.aktif();
    return !!(u && u.rol === "yonetici");
  },

  /* Sunucudaki oturum jetonunu geçersiz kılmayı dener (bkz.
     oturum_kapat.php), ardından yerel oturumu temizler. Sunucuya
     ulaşılamasa da (bağlantı yok, jeton yok vb.) kullanıcı tarayıcıda
     çıkış yapabilmeye devam eder. */
  async cikis() {
    try {
      await ppIstek("oturum_kapat.php", { method: "POST", body: JSON.stringify({}) });
    } catch { /* sunucuya ulaşılamadı — yine de yerel oturumu temizlemeye devam et */ }
    localStorage.removeItem(OTURUM_KEY);
  },

  /* Kullanıcının kendi şifresini değiştirmesi (mevcut şifre doğrulanarak). */
  async sifreDegistir(kullaniciAdi, eskiSifre, yeniSifre) {
    try {
      await ppIstek("hesap_sifre_degistir.php", {
        method: "POST",
        body: JSON.stringify({ kullanici_adi: kullaniciAdi, eski_sifre: eskiSifre, yeni_sifre: yeniSifre })
      });
      return { ok: true, mesaj: "Şifreniz güncellendi." };
    } catch (e) {
      return { ok: false, mesaj: e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "") };
    }
  },

  /* --- Yönetici işlemleri (uygulama anahtarı gerektirir) --- */
  async ekle({ kullaniciAdi, ad, eposta, sifre, rol, menuIzin }) {
    try {
      await ppIstek("hesap_ekle.php", {
        method: "POST",
        body: JSON.stringify({ kullanici_adi: kullaniciAdi, ad, eposta, sifre, rol, menuIzin })
      });
      return { ok: true };
    } catch (e) {
      return { ok: false, mesaj: e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "") };
    }
  },

  /* Mevcut bir üyenin menü erişim listesini günceller. menuIzin bir dizi
     olmalı (boş dizi = hiçbir menüye erişemez); kısıtlamayı tamamen
     kaldırmak için menuIzin yerine sinirsiz:true gönderilir. */
  async menuIzinGuncelle(kullaniciAdi, { menuIzin, sinirsiz } = {}) {
    try {
      await ppIstek("hesap_menu_izin.php", {
        method: "POST",
        body: JSON.stringify({ kullanici_adi: kullaniciAdi, menuIzin, sinirsiz: !!sinirsiz })
      });
      return { ok: true };
    } catch (e) {
      return { ok: false, mesaj: e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "") };
    }
  },

  /* Aktif kullanıcının, normalde yalnızca yöneticiye açık bir bölüme
     (ör. 'finansallar') AÇIKÇA izinli olup olmadığı. Yönetici her zaman
     true; sıradan üye yalnızca kullanici_hesap.menu_izin dizisinde bu id
     varsa true — kısıtlanmamış (menuIzin=null, eski davranış) bir üye
     için false döner, çünkü bu tür bölümler zaten varsayılan olarak
     kapalıydı (bkz. menuCiz, app.js — genel/açık menüler için farklı
     bir varsayılan uygulanır, bu fonksiyon yalnızca "açıkça izinli mi"
     sorusuna cevap verir). */
  menuIzinliMi(menuId) {
    const u = this.aktif();
    if (!u) return false;
    if (u.rol === "yonetici") return true;
    return Array.isArray(u.menuIzin) && u.menuIzin.includes(menuId);
  },

  async listele() {
    const sonuc = await ppIstek("hesap_listele.php");
    return sonuc.hesaplar;
  },

  async sil(kullaniciAdi) {
    if (kullaniciAdi === "savci") return { ok: false, mesaj: "Yönetici hesabı silinemez." };
    try {
      await ppIstek("hesap_sil.php", { method: "POST", body: JSON.stringify({ kullanici_adi: kullaniciAdi }) });
      return { ok: true };
    } catch (e) {
      return { ok: false, mesaj: e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "") };
    }
  },

  async rolDegistir(kullaniciAdi, rol) {
    if (kullaniciAdi === "savci") return { ok: false, mesaj: "Yönetici hesabının rolü değiştirilemez." };
    try {
      await ppIstek("hesap_rol_degistir.php", { method: "POST", body: JSON.stringify({ kullanici_adi: kullaniciAdi, rol }) });
      return { ok: true };
    } catch (e) {
      return { ok: false, mesaj: e.message.replace(/^Pratik sistemi hatası \(\d+\):\s*/, "") };
    }
  }
};
