"""Stil paketleri denetimi: paketteki bir kural, paketin yuklenmedigi bir
sayfada da eslesebiliyor mu?

Kullanim: denetle.py KOK CALISMA_DIZINI   (CALISMA_DIZINI/yorumsuz.json gerekir)
Cikis kodu her zaman 0; bulgular GitHub Actions uyarisi olarak yazilir.
"""
import os, re, sys, json
BURASI = os.path.dirname(os.path.abspath(__file__))
kaynak = open(os.path.join(BURASI, 'bol.py'), encoding='utf-8').read()
# bol.py'nin analiz + paket tanimi + ayristirici kismi (bolme adimi haric)
exec(kaynak.split('# ---------------------------------------------------------------- kayitlar')[0])

bulgu = 0
for ad, sayfalar in PAKET_YUKLENEN.items():
    yol = 'assets/stil-' + ad + '.css'
    if not os.path.isfile(yol):
        continue
    metin = open(yol, encoding='utf-8').read()
    kurallar = []
    for o in ogeler(metin):
        if o['tur'] == 'son': continue
        if o['bas'].strip().startswith(('@media', '@supports')):
            kurallar += [io['bas'] for io in ogeler(o['govde']) if io['tur'] != 'son']
        else:
            kurallar.append(o['bas'])
    for bas in kurallar:
        if bas.strip().startswith('@'): continue
        kume = set()
        for sec in seciciler(bas):
            kume |= secici_sayfalari(sec)
        fazla = sorted(kume - set(sayfalar))
        if fazla:
            bulgu += 1
            print('::warning file=%s,title=Stil paketi::"%s" kurali %s sayfasinda da kullaniliyor olabilir ama %s orada yuklenmiyor. '
                  'Kurali assets/style.css\'e tasiyin (bkz. includes/stil_paketleri.php).'
                  % (yol, ' '.join(bas.split())[:80], ', '.join(fazla[:4]), os.path.basename(yol)))
# Paket haritasi ile includes/stil_paketleri.php ayni mi?
php = open('includes/stil_paketleri.php', encoding='utf-8').read()
for sayfa in sorted(YENI):
    if "'" + sayfa + "'" not in php:
        bulgu += 1
        print('::warning file=includes/stil_paketleri.php,title=Stil paketi::%s listede yok; butun paketleri yukluyor.' % sayfa)
print('Stil paketleri denetimi: %d bulgu.' % bulgu)
