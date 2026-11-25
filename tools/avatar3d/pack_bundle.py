"""The shared avatar bundle.

   Everything that is the same for every member, in one binary file that
   is downloaded once and cached forever: the base mesh, its UVs and
   indices, and the morph targets the app can blend. A member is then 40
   weights and a handful of ids -- about 300 bytes -- instead of the 565
   KB a fully baked avatar packs to.

   Layout: 'AVB1', uint32 header length, a JSON header, then the blocks
   back to back. Every block starts on a 4-byte boundary so the client
   can take typed-array views straight onto the buffer with no copying."""
import json, struct, sys, os, gzip
sys.path.insert(0, '/home/claude/mh')
import build_avatar as BA, kibbe

OUT = sys.argv[1] if len(sys.argv) > 1 else '/home/claude/cine/avatar-base-v1.bin'

# ---- which targets can the app reach? --------------------------------
MACRO = ['caucasian-female-young', 'african-female-young', 'asian-female-young',
         'caucasian-male-young', 'african-male-young', 'asian-male-young']
names = set('macrodetails/' + m for m in MACRO)
for fam in ('dramatic', 'natural', 'classic', 'gamine', 'romantic'):
    for path, w in kibbe.shape_for({fam: 1.0}):
        names.add(path)
for t in getattr(kibbe, 'TYPES', []):
    tid = t['id'] if isinstance(t, dict) else t
    try:
        for path, w in kibbe.shape_for({tid: 1.0}):
            names.add(path)
    except Exception:
        pass
names = sorted(names)

blocks = []          # (key, bytes)
def add(key, data):
    blocks.append((key, bytes(data)))

# ---- the base mesh, unmorphed ----------------------------------------
V = BA.RAW
BODY = BA.BODYIDX
# the drawable body part, built once off the rest pose so the client gets
# the same vertex order the pipeline uses
P, TT, src = BA.part([list(p) for p in V], {'body'})
n = len(P)
xs = [p[0] for p in P]; ys = [p[1] for p in P]; zs = [p[2] for p in P]
mn = [min(xs), min(ys), min(zs)]; mx = [max(xs), max(ys), max(zs)]
sc = [(mx[i] - mn[i]) or 1.0 for i in range(3)]
pos = bytearray()
for p in P:
    for k in range(3):
        pos += struct.pack('<H', max(0, min(65535, int(round((p[k]-mn[k])/sc[k]*65535)))))
add('pos', pos)

idx = bytearray()
fmt = '<H' if n <= 65535 else '<I'
for f in TT:
    for i in f: idx += struct.pack(fmt, i)
add('idx', idx)

uv = bytearray()
for i in src:
    t = BA.UVOF.get(i)
    q = BA.UVRAW[t] if t is not None and t < len(BA.UVRAW) else [0.0, 0.0]
    for v in q:
        uv += struct.pack('<H', max(0, min(65535, int(round((v % 1.0)*65535)))))
add('uv', uv)

# src maps our vertices back to base-mesh vertices; the targets are
# indexed against the base mesh, so the client needs the mapping
smap = bytearray()
for i in src: smap += struct.pack('<I', i)
add('src', smap)

# ---- the helper groups the client needs -------------------------------
# The eyes, lashes and jaw are not part of the drawable body, but the
# client needs them morphed too: the eye centres place the eyeballs, set
# the hairline the scalp is cut at, and anchor the stylisation.
HELPERS = ['helper-l-eye', 'helper-r-eye', 'joint-mouth',
           'helper-l-eyelashes-1', 'helper-l-eyelashes-2',
           'helper-r-eyelashes-1', 'helper-r-eyelashes-2']
hids = []
hgroups = {}
for g in HELPERS:
    ids = sorted({i for q, idx in BA.FACES if q == g for i in idx})
    hgroups[g] = [len(hids) + k for k in range(len(ids))]
    hids += ids
hsrc = bytearray()
for i in hids: hsrc += struct.pack('<I', i)
add('hsrc', hsrc)
hpos = bytearray()
for i in hids:
    for k in range(3):
        hpos += struct.pack('<f', V[i][k])
add('hpos', hpos)

# ---- the anatomy reference -------------------------------------------
# anat.reference() measures the neutral body ONCE and keeps the landmarks
# as vertex indices, precisely so they survive every morph target. That
# makes them shared data, not a computation: bake them here and the
# client never has to run the armpit scan at all -- which also guarantees
# the build and the browser agree about where the shoulder is.
import anat
# Measure the landmarks exactly the way build() does -- through
# ensure_reference(), which measures a neutral ETHNIC BLEND rather than
# the raw base mesh. The raw mesh is an androgynous mannequin and its
# armpit sits somewhere else; measuring there gave the browser a
# shoulder 0.3 units from the one the build uses, which came out as a
# figure whose arms did not swing as far and a sleeve cut in the wrong
# place. Same function, same landmarks, by construction.
BA.ensure_reference()
R = anat._REF
vref = [list(q) for q in V]
BA.apply(vref, BA._REF_MIX)
Bref = anat.Body(vref, BODY)
armIdx = sorted(Bref.cls)
print('  anat reference: %d arm vertices, neckFrac %.4f' % (len(armIdx), R['neckFrac']))
cls = bytearray()
for i in armIdx:
    t, r, L = Bref.cls[i]
    cls += struct.pack('<I', i)
    cls += struct.pack('<hh', max(-32768, min(32767, int(round(t * 512)))),
                               max(-32768, min(32767, int(round(r * 512)))))
add('cls', cls)

# ---- the morph targets ------------------------------------------------
# Only the entries that touch a vertex we actually draw are kept: a
# target that moves the inside of the mouth is weight the client would
# download and never use.
keep = set(src) | set(hids)
tmeta = []
tdata = bytearray()
SCALE = 2048.0
for name in names:
    fn = 'targets/' + name + '.target'
    if not os.path.exists(fn):
        tmeta.append({'name': name, 'off': len(tdata), 'count': 0}); continue
    start = len(tdata); cnt = 0
    for line in open(fn):
        line = line.strip()
        if not line or line[0] == '#': continue
        q = line.split(); i = int(q[0])
        if i not in keep: continue
        tdata += struct.pack('<I', i)
        for d in (float(q[1]), float(q[2]), float(q[3])):
            tdata += struct.pack('<h', max(-32768, min(32767, int(round(d*SCALE)))))
        cnt += 1
    tmeta.append({'name': name, 'off': start, 'count': cnt})
add('targets', tdata)

# ---- assemble ---------------------------------------------------------
# The Kibbe tables travel with the bundle rather than being retyped in
# JavaScript. A second copy of this is a copy that drifts: the first pass
# at a browser-side table invented target names that do not exist, and
# every family rendered the same body because every weight was dropped.
header = {
    'v': 1, 'n': n, 'indexBytes': 2 if n <= 65535 else 4,
    'kibbe': {'family': kibbe.FAMILY, 'modYang': kibbe.MOD_YANG,
              'modYin': kibbe.MOD_YIN,
              'types': {k: v for k, v in kibbe.TYPES}},
    'helpers': hgroups, 'hn': len(hids),
    'ref': {
        'shoulder': {str(k): int(v) for k, v in R['shoulder'].items()},
        'tip': {str(k): int(v) for k, v in R['tip'].items()},
        'neckFrac': R['neckFrac'],
        'armL': {str(k): Bref.axis[k][2] for k in Bref.axis},
        'clsCount': len(armIdx), 'clsScale': 512,
    },
    'posMin': mn, 'posScale': sc, 'targetScale': SCALE,
    'targets': tmeta, 'blocks': {},
}
# two passes: the offsets depend on the header length, which depends on
# the offsets, so lay the blocks out first and then write the header
off = 0
for key, data in blocks:
    header['blocks'][key] = {'off': off, 'len': len(data)}
    off += len(data) + ((-len(data)) % 4)

hj = json.dumps(header, separators=(',', ':')).encode()
pad = (-(8 + len(hj))) % 4
hj += b' ' * pad
out = bytearray(b'AVB1' + struct.pack('<I', len(hj)) + hj)
for key, data in blocks:
    out += data
    out += b'\0' * ((-len(data)) % 4)

open(OUT, 'wb').write(out)
gz = len(gzip.compress(bytes(out), 9))
print('%s' % OUT)
print('  vertices        %d, triangles %d' % (n, len(TT)))
print('  targets         %d (%d entries kept)' % (len(tmeta), sum(t['count'] for t in tmeta)))
for key, data in blocks:
    print('  %-10s %8.1f KB' % (key, len(data)/1024))
print('  TOTAL      %8.1f KB   gzipped %.1f KB' % (len(out)/1024, gz/1024))
