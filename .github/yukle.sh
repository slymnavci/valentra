#!/usr/bin/env bash
# Siteyi IHS'e yukler (deploy.yml "IHS sunucusuna yukle" adimi).
#
# Ortam: FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_DIRECTORY, GITHUB_SHA.
#
# YARIM DOSYA YOK (29.10.2026). Her yuklemede site birkac dakika 404 ve
# 500 veriyordu: dosyalar yerinde tek tek yaziliyordu, yarim inmis bir
# .htaccess ya da PHP dosyasi o an istenen sayfayi bozuyordu (Google
# denetimi tam bu ana denk gelip yuzlerce hata gordu). Simdi her dosya
# once ".in.<ad>" adiyla yukleniyor ve bitince tek hamlede asil adina
# geciyor (lftp xfer:use-temp-file); ziyaretci yarim dosya gormuyor.
#
# YALNIZCA DEGISENLER (29.10.2026). Dosya zamanlari her checkout'ta yeni
# oldugu icin her yukleme butun siteyi (10-15 dakika) yeniden
# gonderiyordu. Sunucudaki .deploy-surum son basarili yuklemenin
# commit'ini tutuyor; ondan bu yana degisen dosyalar gidiyor. Isaret
# yoksa, okunamazsa ya da gecmiste bulunamazsa eskisi gibi tam yukleme.
# Silinen dosyalar sunucudan silinmiyor (eskiden de silinmiyordu).
#
# SIRA: once alt dizinler, en son kokteki sayfalar ve .htaccess (yeni
# haber.php henuz yuklenmemis bir include'u istemesin), en en son
# .deploy-surum: yukleme yarida kalirsa isaret eski kalir ve sonraki
# yukleme ayni dosyalari yeniden gonderir.

set -uo pipefail

SURUM_DOSYASI=".deploy-surum"
TAM_ESIK=400   # bundan cok dosya degistiyse tam yukleme daha guvenli
GECICI=yes     # gecici adla yukle; sunucu yeniden adlandirmayi reddederse no

ortak_ayarlar() {
  cat <<EOF
set cmd:fail-exit yes
set net:timeout 20
set net:max-retries 2
set net:reconnect-interval-base 5
set ftp:ssl-allow no
set ftp:passive-mode yes
set xfer:use-temp-file $GECICI
EOF
}

lftp_calistir() {
  timeout "$1" lftp -u "$FTP_USERNAME","$FTP_PASSWORD" "ftp://$FTP_SERVER" < "$2"
}

# Sunucudaki son yukleme isareti (yoksa bos).
#
# TAKILAN BAGLANTI TAM YUKLEME DEGILDIR (30.10.2026). IHS'in FTP'si
# zaman zaman baglantiyi kabul edip yanit vermiyor; isaret okunamayinca
# "isaret yok" sanilip butun site (15 dakikalik deneme) gonderiliyordu.
# Simdi her okuma 30 sn ile sinirli; zaman asimi (124) yeniden deneniyor,
# yalnizca sunucu gercekten cevap verip isaret yoksa bos donuyor.
onceki_surum() {
  { ortak_ayarlar | sed 's/^set cmd:fail-exit yes$/set cmd:fail-exit no/'
    echo "cat \"$FTP_DIRECTORY/$SURUM_DOSYASI\""
    echo "bye"; } > /tmp/lftp_surum.txt

  local cikti durum
  for bekle in 10 20 ""; do
    cikti=$(lftp_calistir 30 /tmp/lftp_surum.txt 2>/dev/null)
    durum=$?
    [ "$durum" -ne 124 ] && break
    echo "Yukleme isareti okunamadi (zaman asimi)${bekle:+; $bekle sn sonra tekrar}." >&2
    [ -n "$bekle" ] && sleep "$bekle"
  done

  printf '%s' "$cikti" | tr -dc '0-9a-f' | cut -c1-40
}

# Yuklenmemesi gerekenler (eskiden de gitmiyordu).
yuklenir_mi() {
  case "$1" in
    .github/*|.git/*|node_modules/*|egitim/content/*|egitim/materyaller/*) return 1 ;;
  esac
  [ -f "$1" ]
}

tam_betik() {
  ortak_ayarlar
  echo "cd \"$FTP_DIRECTORY\""

  for d in $(find . -mindepth 1 -maxdepth 1 -type d ! -name .git ! -name .github ! -name node_modules -printf '%f\n' | sort); do
    if [ "$d" = "egitim" ]; then
      echo "mirror --reverse --verbose --exclude-glob content/** --exclude-glob materyaller/** $d $d"
    else
      echo "mirror --reverse --verbose $d $d"
    fi
  done

  echo "mirror --reverse --verbose --no-recursion . ."
}

fark_betigi() {
  ortak_ayarlar
  echo "cd \"$FTP_DIRECTORY\""

  local dizinler=""
  # Alt dizindekiler, sonra kok dosyalar, en son .htaccess.
  { grep '/' "$1" | sort; grep -v '/' "$1" | grep -vxF '.htaccess' | sort; grep -xF '.htaccess' "$1"; } | while IFS= read -r f; do
    [ -n "$f" ] || continue
    d=$(dirname "$f")
    if [ "$d" != "." ] && ! grep -qxF "$d" /tmp/yukle_dizinler.txt 2>/dev/null; then
      echo "mkdir -p -f \"$d\""
      echo "$d" >> /tmp/yukle_dizinler.txt
    fi
    echo "put \"$f\" -o \"$f\""
  done
}

# --- Ne yuklenecek -----------------------------------------------------

ONCEKI=$(onceki_surum || true)
MOD=tam

if [ "${#ONCEKI}" -eq 40 ] && git cat-file -e "$ONCEKI^{commit}" 2>/dev/null \
   && git merge-base --is-ancestor "$ONCEKI" HEAD 2>/dev/null; then
  : > /tmp/yukle_liste.txt
  git diff --name-only --diff-filter=ACMRT "$ONCEKI" HEAD | while IFS= read -r f; do
    yuklenir_mi "$f" && echo "$f"
  done > /tmp/yukle_liste.txt
  # Depoda olmayan, deploy'da uretilen ayar dosyasi her seferinde.
  [ -f includes/database.php ] && echo "includes/database.php" >> /tmp/yukle_liste.txt

  ADET=$(wc -l < /tmp/yukle_liste.txt)
  if [ "$ADET" -le "$TAM_ESIK" ]; then
    MOD=fark
    echo "Son yukleme: ${ONCEKI:0:7}. Degisen $ADET dosya yuklenecek:"
    sed 's/^/  /' /tmp/yukle_liste.txt
  else
    echo "Son yuklemeden bu yana $ADET dosya degismis; tam yukleme."
  fi
else
  echo "Sunucuda gecerli yukleme isareti yok (${ONCEKI:-bos}); tam yukleme."
fi

echo "${GITHUB_SHA:-$(git rev-parse HEAD)}" > /tmp/deploy_surum.txt

betik_hazirla() {
  rm -f /tmp/yukle_dizinler.txt
  if [ "$MOD" = fark ]; then fark_betigi /tmp/yukle_liste.txt; else tam_betik; fi
  echo "put /tmp/deploy_surum.txt -o \"$SURUM_DOSYASI\""
  echo "bye"
}

# --- Yukle (IHS FTP'si zaman zaman baglanti kabul etmiyor) ----------
#
# KISA DENEME, COK DENEME (30.10.2026). Takilan deneme sonuna kadar
# bekliyordu: degisen dosyalarin yuklemesi normalde 30-40 sn surerken
# uc deneme de bes dakikalik sinira takilip is 15 dakikada basarisiz
# oluyor, elle yeniden calistirilinca 35 sn'de bitiyordu (#184, #185).
# Degisen dosyalar: 2 dakikalik alti deneme, artan bekleme (en kotu ~16
# dk). Tam yukleme 10-15 dakika surdugu icin 15 dakikalik iki deneme.

if [ "$MOD" = fark ]; then
  SURE=120
  BEKLEMELER="15 30 45 60 90 -"
else
  SURE=900
  BEKLEMELER="30 -"
fi

DENEME=1
for bekle in $BEKLEMELER; do
  [ "$bekle" = - ] && bekle=""
  BASLA=$(date +%s)
  echo "--- Yukleme denemesi $DENEME ($MOD) ---"
  betik_hazirla > /tmp/lftp_betigi.txt

  if lftp_calistir "$SURE" /tmp/lftp_betigi.txt 2>&1 | tee /tmp/lftp_cikti.txt; [ "${PIPESTATUS[0]}" -eq 0 ]; then
    echo "Yukleme basarili (deneme $DENEME, $MOD, $(( $(date +%s) - BASLA )) sn)."
    exit 0
  fi

  echo "Deneme $DENEME basarisiz ($(( $(date +%s) - BASLA )) sn; $SURE sn siniri)."

  if [ -z "$bekle" ]; then
    echo "Yukleme $DENEME denemede de basarisiz oldu."
    exit 1
  fi

  # Sunucu ".in.<ad>" dosyasini asil adina cevirmeyi reddettiyse (bazi FTP
  # sunuculari var olan dosyanin uzerine yeniden adlandirmiyor) eski yontem.
  if [ "$GECICI" = yes ] && grep -qiE 'rename|RNFR|RNTO' /tmp/lftp_cikti.txt; then
    echo "::warning::FTP sunucusu yeniden adlandirmayi reddetti; dosyalar dogrudan yazilacak."
    GECICI=no
  fi

  echo "Yukleme basarisiz. $bekle saniye sonra tekrar denenecek."
  sleep "$bekle"
  DENEME=$((DENEME + 1))
done
