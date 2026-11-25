"""Skin maps, baked once and shared by every member.

   Everything that makes a face read as a face at thumbnail size lives in
   the texture rather than the mesh: brows, the lash line, lip colour, the
   darker skin inside the eye socket. None of it can be modelled at this
   polygon count.

   The trick that makes one texture serve everybody is that it stores
   MASKS, not colour. R is the brow, G the lash line, B the lip, A the
   ambient shading. Skin tone still comes from the vertex colours and
   brow/lash colour from the member's own hair colour, so eight skin tones
   times twelve hair colours is still three textures, not ninety-six.

   The maps are baked in UV space through a position map: every texel of
   the head island is given the 3D point on the surface that lands there,
   and then the masks are evaluated per texel against 3D landmarks. Doing
   it per vertex instead gives a stipple, because the mesh is far coarser
   than the texture."""
import math, sys, os
import numpy as np
sys.path.insert(0, '/home/claude/mh')

OBJ = '/home/claude/mh/base.obj'

def load():
    V = []; VT = []; faces = []; cur = None
    for line in open(OBJ):
        if line.startswith('v '):
            p = line.split(); V.append((float(p[1]), float(p[2]), float(p[3])))
        elif line.startswith('vt '):
            p = line.split(); VT.append((float(p[1]), float(p[2])))
        elif line.startswith('g '):
            cur = line[2:].strip()
        elif line.startswith('f '):
            idx = []
            for t in line.split()[1:]:
                a = t.split('/')
                idx.append((int(a[0]) - 1, int(a[1]) - 1 if len(a) > 1 and a[1] else -1))
            faces.append((cur, idx))
    return np.array(V, dtype=np.float64), np.array(VT, dtype=np.float64), faces

V0, VT, FACES = load()

def tris(group='body'):
    out = []
    for g, idx in FACES:
        if g != group: continue
        for k in range(1, len(idx) - 1):
            a, b, c = idx[0], idx[k], idx[k+1]
            if a[1] < 0 or b[1] < 0 or c[1] < 0: continue
            out.append((a[0], b[0], c[0], a[1], b[1], c[1]))
    return np.array(out, dtype=np.int64)

TRIS = tris('body')

def group_centre(v, name):
    pts = [v[i] for g, idx in FACES if g == name for i, _ in idx]
    if not pts: return None
    return np.mean(np.array(pts), axis=0)

def position_map(v, N):
    """For every texel of the head island, the 3D point that lands there."""
    pos = np.full((N, N, 3), np.nan)
    uv = VT[TRIS[:, 3:6]]                      # (T,3,2)
    px = np.stack([uv[:, :, 0] * N, (1 - uv[:, :, 1]) * N], axis=-1)
    p3 = v[TRIS[:, 0:3]]                       # (T,3,3)
    x0 = np.floor(px[:, :, 0].min(1)).astype(int); x1 = np.ceil(px[:, :, 0].max(1)).astype(int)
    y0 = np.floor(px[:, :, 1].min(1)).astype(int); y1 = np.ceil(px[:, :, 1].max(1)).astype(int)
    big = ((x1 - x0) > N * .06) | ((y1 - y0) > N * .06)   # seam-crossing triangles
    for t in range(len(TRIS)):
        if big[t]: continue
        ax, ay = px[t, 0]; bx, by = px[t, 1]; cx, cy = px[t, 2]
        den = (by - cy) * (ax - cx) + (cx - bx) * (ay - cy)
        if abs(den) < 1e-9: continue
        xs = np.arange(max(0, x0[t] - 1), min(N, x1[t] + 2))
        ys = np.arange(max(0, y0[t] - 1), min(N, y1[t] + 2))
        if not len(xs) or not len(ys): continue
        X, Y = np.meshgrid(xs + .5, ys + .5)
        w0 = ((by - cy) * (X - cx) + (cx - bx) * (Y - cy)) / den
        w1 = ((cy - ay) * (X - cx) + (ax - cx) * (Y - cy)) / den
        w2 = 1 - w0 - w1
        m = (w0 >= -.004) & (w1 >= -.004) & (w2 >= -.004)
        if not m.any(): continue
        P = (w0[..., None] * p3[t, 0] + w1[..., None] * p3[t, 1] + w2[..., None] * p3[t, 2])
        sub = pos[np.ix_(ys, xs)]
        sub[m] = P[m]
        pos[np.ix_(ys, xs)] = sub
    return pos

def smoothstep(e0, e1, x):
    t = np.clip((x - e0) / (e1 - e0 + 1e-9), 0, 1)
    return t * t * (3 - 2 * t)

def ellipsoid(pos, c, r, soft=.25):
    d = np.sqrt(((pos - np.asarray(c)) / np.asarray(r)) ** 2 .sum(axis=-1)) \
        if False else np.sqrt((((pos - np.asarray(c)) / np.asarray(r)) ** 2).sum(axis=-1))
    return 1.0 - smoothstep(1 - soft, 1 + soft, d)

def bake(v, N=1024):
    pos = position_map(v, N)
    valid = ~np.isnan(pos[..., 0])
    P = np.where(np.isnan(pos), 0.0, pos)

    eL = group_centre(v, 'helper-l-eye'); eR = group_centre(v, 'helper-r-eye')
    mouth = group_centre(v, 'joint-mouth')
    g = abs(eL[0] - eR[0]) or 1.0
    sym = P.copy(); sym[..., 0] = np.abs(sym[..., 0])          # mirror, so one
    e = np.array([abs(eL[0]), eL[1], eL[2]])                   # test does both eyes

    brow = np.maximum(
        ellipsoid(sym, e + np.array([ .02*g,  .46*g, -.02*g]), ( .52*g, .105*g, .56*g), .55),
        ellipsoid(sym, e + np.array([ .34*g,  .42*g, -.14*g]), ( .30*g, .085*g, .40*g), .60))
    lash = ellipsoid(sym, e + np.array([0, .145*g, .10*g]), (.46*g, .052*g, .42*g), .50)
    socket = ellipsoid(sym, e + np.array([0, .02*g, -.02*g]), (.82*g, .52*g, .86*g), .80)
    crease = ellipsoid(sym, e + np.array([0, .255*g, .02*g]), (.44*g, .075*g, .44*g), .70)
    lip = np.maximum(
        ellipsoid(P, mouth + np.array([0, .035*g, .20*g]), (.46*g, .105*g, .42*g), .45),
        ellipsoid(P, mouth + np.array([0, -.075*g, .18*g]), (.40*g, .105*g, .40*g), .45))
    blush = ellipsoid(sym, e + np.array([.30*g, -.78*g, .10*g]), (.62*g, .46*g, .66*g), .95)
    nostril = ellipsoid(sym, np.array([.17*g, (eL[1]+mouth[1])*.5 - .10*g,
                                       P[..., 2].max()*.0 + mouth[2] + .30*g]),
                        (.14*g, .12*g, .30*g), .8)

    # ambient shading: deeper in the socket, under the brow, under the lip,
    # at the hairline and under the jaw. This is the term that stops a head
    # looking like a balloon with a face printed on it.
    ao = np.ones((N, N))
    ao -= .34 * socket
    ao -= .20 * crease
    ao -= .18 * ellipsoid(P, mouth + np.array([0, -.30*g, .12*g]), (.52*g, .16*g, .42*g), .8)
    ao -= .22 * nostril
    ao = np.clip(ao, 0, 1)

    # pore and colour break-up, used for both the normal and the roughness
    rng = np.random.default_rng(11)
    def noise(sz, s):
        a = rng.random((sz, sz))
        k = np.fft.fftfreq(sz)[:, None] ** 2 + np.fft.fftfreq(sz)[None, :] ** 2
        f = np.fft.fft2(a) * np.exp(-k * s)
        o = np.real(np.fft.ifft2(f)); o -= o.min(); return o / (o.max() or 1)
    fine = noise(N, 60.0)
    broad = noise(N, 900.0)
    detail = np.clip(fine * .62 + broad * .38, 0, 1)

    mask = np.zeros((N, N, 4), dtype=np.uint8)
    mask[..., 0] = (np.clip(brow, 0, 1) * 255).astype(np.uint8)
    mask[..., 1] = (np.clip(lash, 0, 1) * 255).astype(np.uint8)
    mask[..., 2] = (np.clip(lip, 0, 1) * 255).astype(np.uint8)
    mask[..., 3] = (ao * 255).astype(np.uint8)
    mask[~valid] = (0, 0, 0, 255)

    # a second mask set for the warm tones: cheek, nostril, lip edge
    warm = np.zeros((N, N, 3), dtype=np.uint8)
    warm[..., 0] = (np.clip(blush, 0, 1) * 255).astype(np.uint8)
    warm[..., 1] = (np.clip(nostril, 0, 1) * 255).astype(np.uint8)
    warm[..., 2] = (np.clip(crease, 0, 1) * 255).astype(np.uint8)
    warm[~valid] = 0

    # height -> normal. Pores everywhere, plus the brow standing slightly
    # proud and the lash line cut slightly in.
    h = detail * .55 + np.clip(brow, 0, 1) * .40 - np.clip(lash, 0, 1) * .55 \
        - np.clip(crease, 0, 1) * .25
    h = np.where(valid, h, detail * .55)
    gx = np.gradient(h, axis=1) * N * .0016
    gy = np.gradient(h, axis=0) * N * .0016
    nz = np.ones_like(h)
    L = np.sqrt(gx*gx + gy*gy + nz*nz)
    nrm = np.stack([(-gx/L*.5+.5), (gy/L*.5+.5), (nz/L*.5+.5)], axis=-1)
    normal = (np.clip(nrm, 0, 1) * 255).astype(np.uint8)

    rough = (np.clip(.82 - detail * .12
                     - np.clip(lip, 0, 1) * .26
                     - np.clip(nostril, 0, 1) * .10
                     + np.clip(brow, 0, 1) * .08, 0, 1) * 255).astype(np.uint8)
    return mask, warm, normal, rough

if __name__ == '__main__':
    from PIL import Image
    import build_avatar as BA, kibbe
    N = int(sys.argv[1]) if len(sys.argv) > 1 else 1024
    v = np.array(BA.RAW, dtype=np.float64).tolist()
    v = [list(p) for p in BA.RAW]
    BA.apply(v, [('macrodetails/caucasian-female-young', 1.0)] + kibbe.shape_for({'classic': 1.0}))
    # Bake against the same head the pipeline ships. The UVs do not move
    # when the head is scaled, but the landmarks do, and the masks are
    # placed from the landmarks.
    BA.stylise_head(v)
    v = np.array(v, dtype=np.float64)
    mask, warm, normal, rough = bake(v, N)
    out = '/home/claude/cine'
    Image.fromarray(mask, 'RGBA').save(out + '/skin_mask.png')
    Image.fromarray(warm, 'RGB').save(out + '/skin_warm.png')
    Image.fromarray(normal, 'RGB').save(out + '/skin_normal.png')
    Image.fromarray(rough, 'L').save(out + '/skin_rough.png')
    for f in ('skin_mask', 'skin_warm', 'skin_normal', 'skin_rough'):
        print('%-12s %7d bytes' % (f, os.path.getsize(out + '/' + f + '.png')))
