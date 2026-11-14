"""V Network sayfalari icin style.css'in INCE kopyasi (14.11.2026).

Kullanim: temel.py KOK CALISMA_DIZINI   (CALISMA_DIZINI/yorumsuz.json gerekir)

Neden: ana sayfa V Network oldu (14.11.2026); Network sayfalari style.css'in
tamamini (37 KB sikistirilmis) indirip isliyordu, Lighthouse'a gore 35 KB'i
kullanilmiyordu (ilk boyamayi ~300 ms geciktiren tek engelleyici dosya).

Ne yapar: style.css'i SIRASIYLA dolasir; bir kurali, secicilerinden biri
Network sayfalarinda (network.php, uye.php, uye-eposta.php) eslesebiliyorsa
tutar (bol.py'nin sayfa analizi, secici_sayfalari: belirsizde "her yer" =
tutulur). @font-face, @keyframes, :root gibi kurallar oldugu gibi kalir.
Sonuc assets/stil-temel-network.css; Network sayfalari style.css yerine bunu
yukler (includes/stil_paketleri.php). Kural sirasi degismedigi icin oncelik
ayni kalir.

Deploy her yuklemeden once yeniden uretir (.github/workflows/deploy.yml):
style.css degisince ince kopya kendiliginden guncellenir.
"""
import os, re, sys
BURASI = os.path.dirname(os.path.abspath(__file__))
kaynak = open(os.path.join(BURASI, 'bol.py'), encoding='utf-8').read()
exec(kaynak.split('# ---------------------------------------------------------------- kayitlar')[0])

NETWORK = {'network.php', 'uye.php', 'uye-eposta.php'} & set(GIRIS)

def gerekli(bas):
    bas = bas.strip()
    if bas.startswith('@'):
        return True
    for sec in seciciler(bas):
        if secici_sayfalari(sec) & NETWORK:
            return True
    return False

parca, tutulan, atilan = [], 0, 0
for o in ogeler(css):
    if o['tur'] == 'son':
        continue
    bas = o['bas'].strip()
    if bas.startswith(KAPSAYICI):
        ic = []
        for io in ogeler(o['govde']):
            if io['tur'] == 'son':
                continue
            if gerekli(io['bas']):
                ic.append(io['bas'].strip() + '{' + io['govde'] + '}'); tutulan += 1
            else:
                atilan += 1
        if ic:
            parca.append(bas + '{' + '\n'.join(ic) + '}')
        continue
    if gerekli(o['bas']):
        parca.append(bas + '{' + o['govde'] + '}'); tutulan += 1
    else:
        atilan += 1

# Yorumlar atilir (dosya kucuk kalsin); kural govdeleri aynen.
metin = re.sub(r'/\*.*?\*/', '', '\n'.join(parca), flags=re.S)
bas = ('/* Valentra - V Network sayfalarinin TEMEL stili: assets/style.css\'in bu sayfalarda\n'
       ' * eslesebilen kurallari, ayni sirayla. ELLE DUZENLEMEYIN: .github/stil/temel.py uretir\n'
       ' * (deploy her yuklemede yeniden uretir). Yeni kural style.css\'e yazilir. */\n')
open('assets/stil-temel-network.css', 'w', encoding='utf-8').write(bas + metin + '\n')
print('stil-temel-network.css: tutulan', tutulan, 'atilan', atilan, 'bayt', len(metin), '(style.css', len(css), ')')
