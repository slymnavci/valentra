"""style.css'i ortak + sayfa paketlerine boler. Kullanim: bol.py KOK CIKTI [--yaz]"""
import os, re, sys, json, collections
KOK, CIKTI = sys.argv[1], sys.argv[2]
YAZ = '--yaz' in sys.argv
sys.argv = [sys.argv[0], KOK, CIKTI]
exec(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'analiz.py'), encoding='utf-8').read())

PAKETLER = {
    'network':  {'network.php', 'uye.php', 'uye-eposta.php'},
    'bolumler': {'gazete.php', 'piyasalar.php', 'sirket.php', 'mac-ozetleri.php', 'canli.php', 'burc.php', 'burclar.php', 'deprem.php'},
    'bilgi':    {'kanun.php', 'kanunlar.php', 'pratik-bilgi.php', 'pratik-bilgiler.php', 'vergi-takvimi.php',
                 'resmi-gazete.php', 'araclar.php', 'rehber.php', 'rehberler.php'},
    'sayfalar': {'kategori.php', 'konu.php', 'gunun-kelimesi.php', 'kose-yazisi.php', 'kose-yazilari.php', 'yazar.php',
                 'video.php', 'ara.php', 'etiket.php', 'kurumsal.php', 'bulten.php'},
}
# Ana sayfa ve haber sayfasi: en cok ziyaret edilen, Lighthouse'un olctugu
# sayfalar. Bunlarda kullanilabilecek her kural ortak style.css'te kalir.
ONCELIKLI = {'index.php', 'haber.php'}
TUM_SAYFA = set().union(*PAKETLER.values()) | ONCELIKLI
for ad, ps in PAKETLER.items():
    PAKETLER[ad] = ps & set(GIRIS)   # silinmis sayfa: yok say
# Listede olmayan (yeni eklenen) sayfa butun paketleri yukler (bkz.
# includes/stil_paketleri.php); bolmede oncelikli sayilir: orada
# kullanilan kural ortakta kalir.
YENI = set(GIRIS) - TUM_SAYFA
ONCELIKLI |= YENI
TUM_SAYFA |= YENI
# 'ek': birden cok gruba yayilan ama ana sayfa/haberde olmayan kurallar;
# ana sayfa ve haber disindaki butun sayfalarda yuklenir.
PAKET_SAYFALARI = dict(PAKETLER, ek=TUM_SAYFA - ONCELIKLI)
PAKET_YUKLENEN = {k: set(v) | YENI for k, v in PAKET_SAYFALARI.items()}

css = open('assets/style.css', encoding='utf-8').read()

# --- ayristirici: ust duzey ogeler [(on_metin, basliK, govde, ic_ogeler|None)]
# Icinde kural tasiyan at-kurallari: icerideki her kural ayri ele alinir
# (tasinir ve sira denetimine girer). @container baslangicta yoktu; blok
# bolunmez sayilip ortakta kaliyor, taban kurali ise pakete gidip ondan
# SONRA yukleniyor ve sutun sayilarini eziyordu (PR incelemesi).
KAPSAYICI = ('@media', '@supports', '@container', '@layer', '@scope', '@starting-style')

def blok_sonu(s, i):
    """s[i] == '{' ; eslesen '}' indeksini dondurur (yorum/dize farkinda)."""
    d, n = 0, len(s)
    while i < n:
        if s.startswith('/*', i):
            i = s.index('*/', i) + 2; continue
        c = s[i]
        if c in '"\'':
            j = i + 1
            while s[j] != c:
                j += 2 if s[j] == '\\' else 1
            i = j + 1; continue
        if c == '{': d += 1
        elif c == '}':
            d -= 1
            if d == 0: return i
        i += 1
    raise ValueError('kapanmayan blok')

def ogeler(s):
    out, i, n = [], 0, len(s)
    while i < n:
        j = i
        # onundeki bosluk ve yorumlar
        while j < n:
            if s[j].isspace(): j += 1
            elif s.startswith('/*', j): j = s.index('*/', j) + 2
            else: break
        if j >= n:
            out.append({'on': s[i:], 'tur': 'son'}); break
        a = j
        while s[a] != '{':
            if s.startswith('/*', a): a = s.index('*/', a) + 2
            else: a += 1
        b = blok_sonu(s, a)
        bas = s[j:a]
        out.append({'on': s[i:j], 'bas': bas, 'govde': s[a + 1:b], 'tur': 'kural'})
        i = b + 1
    return out

def seciciler(bas):
    bas = re.sub(r'/\*.*?\*/', '', bas, flags=re.S)
    return [x.strip() for x in bas.split(',') if x.strip()]

def siniflar(sec):
    sec = re.sub(r':not\([^)]*\)', '', sec)
    sec = re.sub(r'\[[^\]]*\]', '', sec)
    return re.findall(r'\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)', sec)

def secici_sayfalari(sec):
    """Secicinin eslesebilecegi sayfalar: siniflarin sayfa kumelerinin
    kesisimi (oge hepsini tasimali). Belirsiz ya da hic gecmeyen sinif bilgi
    vermez (her yer sayilir). Sinifsiz secici: her yer."""
    kume = set(TUM_SAYFA)
    for c in siniflar(sec):
        sayfa, belirsiz, _ = P(c)
        if belirsiz or not sayfa:
            continue
        kume &= sayfa
    return kume

def kural_paketi(bas):
    secs = seciciler(bas)
    if not secs: return None
    kume = set()
    for sec in secs:
        kume |= secici_sayfalari(sec)
    if not kume or kume & ONCELIKLI:
        return None
    for ad, ps in PAKETLER.items():
        if kume <= ps:
            return ad
    return 'ek'


# ---------------------------------------------------------------- kayitlar
KAYIT = []
UST = []
for o in ogeler(css):
    if o['tur'] == 'son':
        UST.append(('metin', o['on'])); continue
    bas = o['bas'].strip()
    if bas.startswith(KAPSAYICI):
        ic = []
        for io in ogeler(o['govde']):
            if io['tur'] == 'son':
                ic.append(('metin', io['on'])); continue
            ib = io['bas'].strip()
            KAYIT.append(dict(medya=bas, on=io['on'], bas=io['bas'], govde=io['govde'],
                              atama=None if ib.startswith('@') else kural_paketi(io['bas'])))
            ic.append(('kural', len(KAYIT) - 1))
        UST.append(('medya', o['bas'], o['on'], ic)); continue
    KAYIT.append(dict(medya='', on=o['on'], bas=o['bas'], govde=o['govde'],
                      atama=None if bas.startswith('@') else kural_paketi(bas)))
    UST.append(('kural', len(KAYIT) - 1))

# ------------------------------------------------------- sira catismalari
# Paketler style.css'ten SONRA yuklenir (ortak < ek < paket). Dosyada daha
# once gelen bir kural pakete tasininca, daha sonra gelen ve daha dusuk
# katmanda kalan bir kuralin ONUNE gecer. Ikisi ayni ogede ayni ozelligi
# esit oncelikle ayarliyorsa sonuc degisir -> onceki kural sonrakinin
# katmanina indirilir (temkinli: kuskuda tasima).
def KATMAN(a):
    return 0 if a is None else (1 if a == 'ek' else 2)

def oncelik(sec):
    sec = re.sub(r'"[^"]*"|\'[^\']*\'', '', sec)
    ids = len(re.findall(r'#[\w-]+', sec))
    sec2 = re.sub(r':(?:not|is|has)\(', ' (', sec)
    sec2 = re.sub(r':where\([^)]*\)', '', sec2)
    cls = len(re.findall(r'\.[\w-]+', sec2)) + len(re.findall(r'\[[^\]]*\]', sec2)) \
        + len(re.findall(r'(?<!:):(?!:)[\w-]+', sec2))
    el = len(re.findall(r'(?:^|[\s>+~(])([a-zA-Z][\w-]*)', re.sub(r'\[[^\]]*\]', '', sec2))) + len(re.findall(r'::[\w-]+', sec2))
    return (ids, cls, el)

def _islevsel(ham):
    """:not()/:has() silinir; :is()/:where() \x01 olur (ic ice parantezler dahil)."""
    onceki = None
    while onceki != ham:
        onceki = ham
        ham = re.sub(r':(?:not|has)\([^()]*\)', '', ham)
        ham = re.sub(r':(?:is|where)\([^()]*\)', '\x01', ham)
    return ham

def ozne(sec):
    """Sagdaki bilesik: (etiket, siniflar, sozde_oge, baglam_siniflari, belirsiz)."""
    ham = _islevsel(re.sub(r'\[[^\]]*\]', '', sec).strip())
    m = re.search(r'::([\w-]+)|:(before|after|first-line|first-letter)\b', ham)
    sozde = (m.group(1) or m.group(2)) if m else ''
    ham = ham.replace(':root', 'html')
    ham = re.sub(r'::?[\w-]+(\([^)]*\))?', '', ham)
    parca = [p for p in re.split(r'[\s>+~]+', ham.strip()) if p.replace('\x01', '') or p == '\x01']
    son = parca[-1] if parca else ''
    belirsiz = '\x01' in son
    son = son.replace('\x01', '')
    m = re.match(r'([a-zA-Z*][\w-]*)?', son)
    baglam = frozenset()
    for p in reversed(parca[:-1]):
        c = re.findall(r'\.([\w-]+)', p)
        if c: baglam = frozenset(c); break
    return (m.group(1) or '*', frozenset(re.findall(r'\.([\w-]+)', son)), sozde, baglam, belirsiz)

def ozellikler(govde):
    g = re.sub(r'/\*.*?\*/', '', govde, flags=re.S)
    out = set()
    for m in re.finditer(r'(?:^|[;{])\s*(--[\w-]+|[a-zA-Z-]+)\s*:', g):
        p = m.group(1)
        out.add(p if p.startswith('--') else p.lstrip('-').split('-')[0])
    return out

BIRLIKTE = collections.defaultdict(set)
SINIF_RE = re.compile(r"""class(?:Name)?\s*=\s*\\?["']((?:<\?.*?\?>|[^"'\\])*)""", re.S)
for f, t in METIN.items():
    if f.startswith(DIS): continue
    for m in SINIF_RE.finditer(t):
        tok = re.findall(r'[A-Za-z_][\w-]*', re.sub(r'<\?.*?\?>', ' ', m.group(1), flags=re.S))
        for x in tok:
            BIRLIKTE[x].update(tok)

for k in KAYIT:
    b = k['bas'].strip()
    k['ozel'] = b.startswith('@')
    k['sec'] = [] if k['ozel'] else [(oncelik(x), ozne(x), frozenset(secici_sayfalari(x))) for x in seciciler(k['bas'])]
    k['prop'] = set() if k['ozel'] else ozellikler(k['govde'])

def _bag(c1, c2):
    return bool(c1 & c2) or any(y in BIRLIKTE.get(x, ()) for x in c1 for y in c2)

def ayni_oge(o1, o2):
    (t1, c1, s1, b1, x1), (t2, c2, s2, b2, x2) = o1, o2
    if s1 != s2: return False                       # farkli sozde oge: farkli kutu
    if t1 != '*' and t2 != '*' and t1 != t2: return False
    if x1 or x2: return True                         # oznede :is()/:where(): temkinli
    # html/body secicisi ile sinifli (etiketsiz) secici: sinif html/body'de degilse ayri oge
    for (ta, ca), (tb, cb) in (((t1, c1), (t2, c2)), ((t2, c2), (t1, c1))):
        if ta in ('html', 'body') and not ca and cb and tb == '*':
            return False
    if c1 and c2: return _bag(c1, c2)
    if c1 or c2: return True                         # sinifli oge baska baglamin icinde olabilir
    if not b1 or not b2: return True                 # baglamsiz etiket secicisi her yerde
    return _bag(b1, b2)                              # ayri bilesenlerin ic etiketleri

def catisir(a, b):
    if not (a['prop'] & b['prop']): return False
    for s1, o1, p1 in a['sec']:
        for s2, o2, p2 in b['sec']:
            # Hicbir sayfada birlikte bulunamayan iki secici catisamaz.
            if s1 == s2 and (p1 & p2) and ayni_oge(o1, o2): return True
    return False

indirilen = 0
degisti = True
while degisti:
    degisti = False
    for i, a in enumerate(KAYIT):
        if a['ozel'] or a['atama'] is None: continue
        ka = KATMAN(a['atama'])
        for j in range(i + 1, len(KAYIT)):
            b = KAYIT[j]
            if b['ozel'] or KATMAN(b['atama']) >= ka: continue
            if catisir(a, b):
                if os.environ.get('GOSTER'):
                    print('  ', a['atama'], '|', ' '.join(a['bas'].split())[:60], '  <->  ', (b['medya'][:28] + ' ' if b['medya'] else '') + ' '.join(b['bas'].split())[:60], '|', sorted(a['prop'] & b['prop'])[:4])
                a['atama'] = b['atama']; indirilen += 1; degisti = True
                break
print('sira catismasi nedeniyle geri alinan kural:', indirilen)

def kural_metni(k): return k['on'] + k['bas'] + '{' + k['govde'] + '}'
ortak, paket = [], collections.defaultdict(list)
tasinan = collections.Counter()
for p in UST:
    if p[0] == 'metin': ortak.append(p[1])
    elif p[0] == 'kural':
        k = KAYIT[p[1]]
        if k['atama']: paket[k['atama']].append(kural_metni(k)); tasinan[k['atama']] += 1
        else: ortak.append(kural_metni(k))
    else:
        _, bas, on, ic = p
        ic_ortak, ic_paket = [], collections.defaultdict(list)
        for q in ic:
            if q[0] == 'metin': ic_ortak.append(q[1]); continue
            k = KAYIT[q[1]]
            if k['atama']: ic_paket[k['atama']].append(kural_metni(k)); tasinan[k['atama']] += 1
            else: ic_ortak.append(kural_metni(k))
        ortak.append(on + bas + '{' + ''.join(ic_ortak) + '}')
        for kk, ms in ic_paket.items():
            paket[kk].append('\n' + bas.strip() + ' {' + ''.join(ms) + '\n}')

ortak_css = ''.join(ortak)
# Bos kalan @media bloklarini temizle
ortak_css = re.sub(r'\n?@(?:media|supports|container|layer|scope)[^{]*\{\s*\}', '', ortak_css)
print('orijinal', len(css), 'ortak', len(ortak_css))
for k, ms in paket.items():
    print(k, 'kural', tasinan[k], 'bayt', len(''.join(ms)))
if YAZ:
    open('assets/style.css', 'w', encoding='utf-8').write(ortak_css)
    for k, ms in paket.items():
        bas = ('/* Valentra - ' + k + ' sayfalarinin stilleri (assets/style.css\'ten ayrildi;\n'
               ' * yalnizca bu sayfalarda yuklenir: ' + ', '.join(sorted(PAKET_SAYFALARI[k])) + ').\n'
               ' * Uretim: style.css bolme betigi, 08.11.2026. */\n')
        open('assets/stil-' + k + '.css', 'w', encoding='utf-8').write(bas + ''.join(ms).lstrip('\n') + '\n')
    json.dump({k: sorted(v) for k, v in PAKET_SAYFALARI.items()}, open(CIKTI + '/paketler.json', 'w'))
