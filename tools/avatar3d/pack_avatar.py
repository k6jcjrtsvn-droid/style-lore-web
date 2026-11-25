"""How much does one avatar actually weigh?

   The pipeline emits JSON, which is fine for a test rig and hopeless as
   a delivery format: 5 MB per figure, most of it the decimal digits of
   float32s that only needed 16 bits. This packs one avatar the way the
   app would ship it and reports the real numbers, so the delivery design
   rests on measurements rather than on hope."""
import json, struct, gzip, io, sys, math

def quantise(part):
    """positions -> 16-bit over the part's own bounding box,
       normals  -> octahedral 16-bit (2 bytes, not 12),
       uv       -> 16-bit, colour -> 8-bit, index -> 16 or 32."""
    n = part['n']
    pos = part['position']
    mn = [min(pos[i::3]) for i in range(3)]
    mx = [max(pos[i::3]) for i in range(3)]
    sc = [(mx[i] - mn[i]) or 1.0 for i in range(3)]
    out = bytearray()
    for i in range(n):
        for k in range(3):
            q = int(round((pos[i*3+k] - mn[k]) / sc[k] * 65535))
            out += struct.pack('<H', max(0, min(65535, q)))
    blocks = {'pos': bytes(out)}

    nrm = part.get('normal')
    if nrm:
        o = bytearray()
        for i in range(n):
            x, y, z = nrm[i*3], nrm[i*3+1], nrm[i*3+2]
            L = abs(x) + abs(y) + abs(z) or 1.0
            x, y, z = x/L, y/L, z/L
            if z < 0:
                x, y = (1 - abs(y)) * (1 if x >= 0 else -1), (1 - abs(x)) * (1 if y >= 0 else -1)
            o += struct.pack('<bb', max(-127, min(127, int(round(x*127)))),
                                     max(-127, min(127, int(round(y*127)))))
        blocks['nrm'] = bytes(o)

    uv = part.get('uv')
    if uv:
        o = bytearray()
        for v in uv:
            o += struct.pack('<H', max(0, min(65535, int(round((v % 1.0) * 65535)))))
        blocks['uv'] = bytes(o)

    col = part.get('color')
    if col:
        blocks['col'] = bytes(max(0, min(255, int(round(c * 255)))) for c in col)

    idx = part['index']
    if n <= 65535:
        blocks['idx'] = b''.join(struct.pack('<H', i) for i in idx)
    else:
        blocks['idx'] = b''.join(struct.pack('<I', i) for i in idx)
    return blocks

def report(av, label):
    rows = []
    total = 0; totalgz = 0
    for name, part in av.items():
        if not isinstance(part, dict) or 'position' not in part: continue
        b = quantise(part)
        raw = sum(len(x) for x in b.values())
        gz = len(gzip.compress(b''.join(b.values()), 6))
        tris = len(part['index']) // 3
        rows.append((name, part['n'], tris, raw, gz))
        total += raw; totalgz += gz
    print('--- %s ---' % label)
    print('%-10s %8s %8s %10s %10s' % ('part', 'verts', 'tris', 'packed', 'gzipped'))
    for r in rows:
        print('%-10s %8d %8d %9.1fK %9.1fK' % (r[0], r[1], r[2], r[3]/1024, r[4]/1024))
    print('%-10s %8s %8s %9.1fK %9.1fK' % ('TOTAL', '', '', total/1024, totalgz/1024))
    return total, totalgz

if __name__ == '__main__':
    D = json.load(open(sys.argv[1] if len(sys.argv) > 1 else '/home/claude/cine/avatars.json'))
    import os
    print('source JSON: %.1f MB for %d avatars\n' % (
        os.path.getsize(sys.argv[1] if len(sys.argv) > 1 else '/home/claude/cine/avatars.json')/1048576,
        len(D)))
    k = list(D)[0]
    report(D[k], k)
