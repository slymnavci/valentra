"""style.css kurallarinin hangi giris sayfalarinda kullanilabilecegini hesaplar.

Islev duzeyinde: bir sinif adi bir PHP islevinin govdesinde geciyorsa, o
islevi (dolayli olarak) cagiran sayfalara aittir; dosyanin ust duzey kodunda
geciyorsa dosyayi yukleyen sayfalara. Sayfaya baglanamayan her kullanim
'belirsiz' sayilir ve kural ortak dosyada kalir (temkinli taraf).
"""
import os, re, sys, json, collections
KOK = sys.argv[1]
os.chdir(KOK)
DIS = ('admin/', 'ajan/', 'egitim/', 'sql/', '.github/')

def dosyalar():
    for d, _, fs in os.walk('.'):
        if any(p in d for p in ('./.git', './ajan/vendor', './node_modules', './onbellek', './gorseller')):
            continue
        for f in fs:
            if f.endswith(('.php', '.js', '.html')):
                yield os.path.normpath(os.path.join(d, f))

TUM = sorted(dosyalar())
METIN = {f: open(f, encoding='utf-8', errors='replace').read() for f in TUM}
# PHP yorumlari bosluga cevrilmis (PHP tokenizer; konumlar ayni): aciklamada
# gecen islev ya da sinif adi cagri/kullanim sayilmasin.
_YORUMSUZ = json.load(open(sys.argv[2] + '/yorumsuz.json', encoding='utf-8'))
for _f in METIN:
    if _f in _YORUMSUZ and len(_YORUMSUZ[_f]) == len(METIN[_f]):
        METIN[_f] = _YORUMSUZ[_f]

# --- PHP islev govdeleri (yaklasik: 'function ad(' ... eslesen '}')
def govde_sonu(s, i):
    d = 0
    n = len(s)
    while i < n:
        c = s[i]
        if s.startswith('//', i) or s.startswith('#', i) and not s.startswith('#[', i):
            j = s.find('\n', i); i = n if j < 0 else j; continue
        if s.startswith('/*', i):
            j = s.find('*/', i); i = n if j < 0 else j + 2; continue
        if c in '"\'':
            j = i + 1
            while j < n and s[j] != c:
                j += 2 if s[j] == '\\' else 1
            i = j + 1; continue
        if s.startswith('<<<', i):
            m = re.match(r"<<<\s*'?\"?(\w+)'?\"?\n", s[i:])
            if m:
                k = re.search(r'\n\s*' + m.group(1) + r'\b', s[i + m.end() - 1:])
                i = i + m.end() - 1 + (k.end() if k else 0); continue
        if c == '{': d += 1
        elif c == '}':
            d -= 1
            if d == 0: return i
        i += 1
    return n

ISLEV = {}          # ad -> (dosya, bas, son)
ALAN = collections.defaultdict(list)   # dosya -> [(bas, son, ad)]
for f, t in METIN.items():
    if not f.endswith('.php'): continue
    for m in re.finditer(r'\bfunction\s+&?\s*([A-Za-z_]\w*)\s*\(', t):
        a = t.find('{', m.end())
        if a < 0: continue
        # imzanin sonu: ilk '{' oncesinde ';' yoksa (soyut degil)
        if ';' in t[m.end():a] and t[m.end():a].count('(') <= t[m.end():a].count(')') and t[m.end():a].rstrip().endswith(';'):
            continue
        b = govde_sonu(t, a)
        ISLEV[m.group(1)] = (f, a, b)
        ALAN[f].append((a, b, m.group(1)))

def sahip(f, pos):
    """pos'u iceren en ic islev; yoksa None (dosyanin ust duzeyi)."""
    en = None
    for a, b, ad in ALAN.get(f, ()):
        if a <= pos <= b and (en is None or a >= ISLEV[en][1]):
            en = ad
    return en

# --- include grafigi (ust duzey) ve JS
INC = re.compile(r"(?:require|include)(?:_once)?\s*\(?\s*(__DIR__|dirname\(__DIR__\))\s*\.\s*'([^']+)'")
JSR = re.compile(r"""(?:varlik|e_varlik)\(\s*'(/assets/[^']+\.js)'|src=["'](/assets/[^"'?]+\.js)""")
kenar = collections.defaultdict(set)
for f, t in METIN.items():
    if not f.endswith('.php'): continue
    d = os.path.dirname(f)
    for m in INC.finditer(t):
        b = d if m.group(1) == '__DIR__' else os.path.dirname(d)
        h = os.path.normpath(os.path.join(b, m.group(2).lstrip('/')))
        if h in METIN: kenar[f].add(h)
    for a, b2 in JSR.findall(t):
        h = os.path.normpath('.' + (a or b2))
        if h in METIN: kenar[f].add(h)

def ulasilan(bas):
    gor, yig = {bas}, [bas]
    while yig:
        x = yig.pop()
        for y in kenar.get(x, ()):
            if y not in gor: gor.add(y); yig.append(y)
    return gor

GIRIS = {}
for f in TUM:
    if os.path.dirname(f) in ('', '.') and f.endswith('.php'):
        u = ulasilan(f)
        if 'includes/sayfa_ust.php' in u:
            GIRIS[f] = u
# dosyanin UST DUZEY kodu hangi sayfalarda calisir
dosya_sayfa = collections.defaultdict(set)
for g, u in GIRIS.items():
    for x in u: dosya_sayfa[x].add(g)

# HTML parcasi donduren uclar: adresinin gectigi konumu calistiran sayfalara
# bagla (islev icindeyse o islevin sayfalari; islev sayfalari asagida
# hesaplaniyor, bu yuzden uclar sabit noktaya dahil).
UC = [f for f in TUM if f.endswith('.php') and f not in GIRIS and not f.startswith(DIS) and not f.startswith('includes/')]
UC_YER = collections.defaultdict(list)   # uc -> [(dosya, sahip)]
# --- islevlerin sayfalari (sabit nokta)
CAGRI = collections.defaultdict(list)   # islev -> [(dosya, sahip_islev|None)]
for f, t in METIN.items():
    if not f.endswith('.php'): continue
    for ad in ISLEV:
        pass
ADLAR = set(ISLEV)
for f, t in METIN.items():
    if not f.endswith('.php') or f.startswith(DIS): continue
    for m in re.finditer(r"""\b([A-Za-z_]\w*)\s*\(|['"]([A-Za-z_]\w*)['"]""", t):
        ad = m.group(1) or m.group(2)
        if ad in ADLAR:
            on = t[max(0, m.start() - 12):m.start()]
            if re.search(r'function\s+&?\s*$', on):
                continue
            CAGRI[ad].append((f, sahip(f, m.start())))

for uc in UC:
    for f, t in METIN.items():
        if f == uc or f.startswith(DIS): continue
        for m in re.finditer(re.escape(uc.replace('.php', '')) + r'(?:\.php)?(?=[\'"?/])', t):
            if t[max(0, m.start() - 1)] not in '/\'"': continue
            UC_YER[uc].append((f, sahip(f, m.start()) if f.endswith('.php') else None))

# Hic cagrilmayan islev dinamik cagriliyor olabilir: dosyasinin ust duzeyinden
# cagriliyor say (temkinli; cagirdigi islevler de bu sayfalari alir).
for ad, (f, a_, b_) in ISLEV.items():
    if not CAGRI.get(ad):
        CAGRI[ad].append((f, None))

islev_sayfa = {ad: set() for ad in ISLEV}
islev_belirsiz = {ad: False for ad in ISLEV}
def dosya_belirsiz(f):
    return not f.startswith(DIS) and not dosya_sayfa.get(f)

# 1) sayfalar (uclar ve islevler birlikte, sabit noktaya kadar)
degisti = True
while degisti:
    degisti = False
    for uc, yerler in UC_YER.items():
        for f, sh in yerler:
            ek = dosya_sayfa.get(f, set()) if sh is None else islev_sayfa[sh]
            for x in ulasilan(uc):
                if not ek <= dosya_sayfa[x]:
                    dosya_sayfa[x] |= ek; degisti = True
    for ad, yerler in CAGRI.items():
        for f, sh in yerler:
            ek = dosya_sayfa.get(f, set()) if sh is None else islev_sayfa[sh]
            if not ek <= islev_sayfa[ad]:
                islev_sayfa[ad] |= ek; degisti = True

# 2) belirsizlik: sayfalar kesinlestikten sonra (sayfaya baglanamayan cagri)
degisti = True
while degisti:
    degisti = False
    for ad, yerler in CAGRI.items():
        if islev_belirsiz[ad]: continue
        for f, sh in yerler:
            if (dosya_belirsiz(f) if sh is None else islev_belirsiz[sh]):
                islev_belirsiz[ad] = True; degisti = True; break

json.dump({'giris': sorted(GIRIS)}, open(sys.argv[2] + '/giris.json', 'w'))

# --- sinif kullanimi (konum duzeyinde)
def gecis_yerleri(c, t):
    yerler = [m.start() for m in re.finditer(r'(?<![A-Za-z0-9_-])' + re.escape(c) + r'(?![A-Za-z0-9_-])', t)]
    parca = c.split('-')
    for i in range(1, len(parca)):
        on, son = '-'.join(parca[:i]) + '-', '-' + '-'.join(parca[i:])
        for m in re.finditer(r'(?<![A-Za-z0-9_-])' + re.escape(on) + r"""(?:['"]\s*(?:\.|\+)|\$\{|<\?)""", t):
            yerler.append(m.start())
        for m in re.finditer(r"""(?:\.|\+)\s*['"]""" + re.escape(son) + r"""(?![A-Za-z0-9_-])""", t):
            yerler.append(m.start())
    return yerler

CACHE = {}
def P(c):
    if c in CACHE: return CACHE[c]
    sayfa, belirsiz, gecti = set(), False, []
    for f, t in METIN.items():
        if f.startswith(DIS) or c.split('-')[0] not in t: continue
        for pos in gecis_yerleri(c, t):
            gecti.append(f)
            s = sahip(f, pos) if f.endswith('.php') else None
            if s is None:
                sayfa |= dosya_sayfa.get(f, set()); belirsiz |= dosya_belirsiz(f)
            else:
                sayfa |= islev_sayfa[s]; belirsiz |= islev_belirsiz[s]
    CACHE[c] = (sayfa, belirsiz, sorted(set(gecti)))
    return CACHE[c]
