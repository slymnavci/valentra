/* Kullanıcı verilerini uygulama anahtarından bağımsız, oturum tokenı ile senkronlar. */
(function () {
  function tokenVarMi() {
    try { return !!ppOturumTokenAl(); } catch { return false; }
  }

  async function cdsIstek(secenek) {
    const r = await fetch('api/kullanici_senkron.php', Object.assign({
      headers: Object.assign({
        'Content-Type': 'application/json',
        'X-Session-Token': ppOturumTokenAl()
      }, (secenek && secenek.headers) || {})
    }, secenek || {}));
    let govde = null;
    try { govde = await r.json(); } catch {}
    if (!r.ok) {
      const mesaj = (govde && govde.hata) || r.statusText || 'Bilinmeyen hata';
      throw new Error(`Kullanıcı senkron hatası (${r.status}): ${mesaj}`);
    }
    return govde;
  }

  async function cdsTumunuGetir() {
    return cdsIstek();
  }

  window.kuAyarKaydet = async function (_kullaniciAdi, veri) {
    return cdsIstek({ method: 'POST', body: JSON.stringify({ islem: 'ayar', veri }) });
  };
  window.kuAyarGetir = async function () {
    const sonuc = await cdsTumunuGetir();
    return sonuc.ayar;
  };
  window.kuIlerlemeGetir = async function () {
    const sonuc = await cdsTumunuGetir();
    return sonuc.sayfalar || {};
  };
  window.kuIlerlemeIsaretle = async function (_kullaniciAdi, materyalId, sayfaNo, deger) {
    return cdsIstek({ method: 'POST', body: JSON.stringify({
      islem: 'ilerleme', materyal_id: materyalId, sayfa_no: sayfaNo, deger: !!deger
    }) });
  };
  window.kuKonuSinavGetir = async function () {
    const sonuc = await cdsTumunuGetir();
    return sonuc.sinavlar || {};
  };
  window.kuKonuSinavKaydet = async function (_kullaniciAdi, konuId, istemciId, dogru, toplam, puan, gecti) {
    const sonuc = await cdsIstek({ method: 'POST', body: JSON.stringify({
      islem: 'sinav', konu_id: konuId, istemci_id: istemciId,
      dogru, toplam, puan, gecti: !!gecti
    }) });
    return sonuc.id;
  };

  window.kuAyarSenkronla = function (a) {
    if (!tokenVarMi()) return;
    const u = Auth.aktif();
    if (!u) return;
    kuAyarKaydet(u.kullaniciAdi, a)
      .catch(e => console.warn('Ayar senkronu başarısız (yerelde kayıtlı kaldı):', e.message));
  };

  window.kuIlerlemeSenkronla = function (materyalId, sayfa, deger) {
    if (!tokenVarMi()) return;
    const u = Auth.aktif();
    if (!u) return;
    kuIlerlemeIsaretle(u.kullaniciAdi, materyalId, sayfa, deger)
      .catch(e => console.warn('İlerleme senkronu başarısız (yerelde kayıtlı kaldı):', e.message));
  };

  window.kuKonuSinavSenkronla = function (konuId, istemciId, dogru, toplam, puan, gecti) {
    if (!tokenVarMi()) return;
    const u = Auth.aktif();
    if (!u) return;
    kuKonuSinavKaydet(u.kullaniciAdi, konuId, istemciId, dogru, toplam, puan, gecti)
      .catch(e => console.warn('Sınav senkronu başarısız (yerelde kayıtlı kaldı):', e.message));
  };

  window.kullaniciSenkronBaslat = async function () {
    if (KU_SENKRON_YAPILDI || !tokenVarMi()) return;
    const u = Auth.aktif();
    if (!u) return;
    KU_SENKRON_YAPILDI = true;

    try {
      const uzak = await cdsTumunuGetir();
      const uzakSayfalar = uzak.sayfalar || {};
      const uzakSinavlar = uzak.sinavlar || {};
      const uzakAyar = uzak.ayar || null;

      const yerelSayfalarYedek = JSON.parse(JSON.stringify(state.sayfalar));
      Object.keys(uzakSayfalar).forEach(mid => {
        if (!state.sayfalar[mid]) state.sayfalar[mid] = {};
        Object.keys(uzakSayfalar[mid]).forEach(sayfaNo => {
          const zatenYereldeVarMi = yerelSayfalarYedek[mid] && yerelSayfalarYedek[mid][sayfaNo] !== undefined;
          state.sayfalar[mid][sayfaNo] = uzakSayfalar[mid][sayfaNo];
          if (!zatenYereldeVarMi) {
            const gun = String(uzakSayfalar[mid][sayfaNo]).slice(0, 10);
            state.gecmis[gun] = (state.gecmis[gun] || 0) + 1;
          }
        });
      });

      Object.keys(state.sinavlar).forEach(konuId => {
        state.sinavlar[konuId].forEach(k => { if (!k.istemciId) k.istemciId = iyBenzersizId('sinav'); });
      });
      const yerelSinavlarYedek = JSON.parse(JSON.stringify(state.sinavlar));
      Object.keys(uzakSinavlar).forEach(konuId => {
        const birlesik = [...(state.sinavlar[konuId] || [])];
        uzakSinavlar[konuId].forEach(k => {
          if (!birlesik.some(y => sinavKaydiAyni(y, k))) birlesik.push(k);
        });
        birlesik.sort((a, b) => new Date(a.tarih) - new Date(b.tarih));
        state.sinavlar[konuId] = birlesik;
      });
      DB.kaydet(state);

      Object.keys(yerelSayfalarYedek).forEach(mid => {
        Object.keys(yerelSayfalarYedek[mid]).forEach(sayfaNo => {
          if (!(uzakSayfalar[mid] && uzakSayfalar[mid][sayfaNo] !== undefined)) {
            kuIlerlemeIsaretle(u.kullaniciAdi, mid, +sayfaNo, true).catch(() => {});
          }
        });
      });
      Object.keys(yerelSinavlarYedek).forEach(konuId => {
        const uzakK = uzakSinavlar[konuId] || [];
        yerelSinavlarYedek[konuId].forEach(k => {
          if (!uzakK.some(u2 => sinavKaydiAyni(u2, k))) {
            kuKonuSinavKaydet(u.kullaniciAdi, konuId, k.istemciId, k.dogru, k.toplam, k.puan, k.gecti).catch(() => {});
          }
        });
      });

      if (uzakAyar) {
        /* Sunucudaki plan/ayarlar cihazlar arası ortak kaynaktır. Yerel görünüm alanları
           varsayılanlarla tamamlanır ama aynı isimli sunucu alanları (özellikle ellePlan)
           her zaman önceliklidir. */
        const birlesikAyar = Object.assign(Ayar.varsayilan(), Ayar.oku(), uzakAyar);
        localStorage.setItem(ayarAnahtari(), JSON.stringify(birlesikAyar));
        temaUygula();
      } else {
        kuAyarKaydet(u.kullaniciAdi, Ayar.oku()).catch(() => {});
      }

      yonlendir();
    } catch (e) {
      KU_SENKRON_YAPILDI = false;
      console.warn('Cihazlar arası kullanıcı senkronizasyonu başarısız, yerel veriyle devam ediliyor:', e.message);
    }
  };
})();
