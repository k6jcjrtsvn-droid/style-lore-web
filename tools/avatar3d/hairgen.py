"""Hair as geometry.

   A solid cap over the skull reads as a helmet however good its material
   is -- the problem is the silhouette, not the shading. Pushing the
   environment map, clearcoat and sheen up on a smooth dome does not make
   hair, it makes chrome.

   Real hair at this budget is cards: curved ribbons grown out of the
   scalp, each carrying an alpha-cut texture of fine strands. That buys
   the three things a cap cannot have at any price -- a broken outline,
   depth you can see into, and a highlight that runs across the strands
   rather than around a sphere.

   A dark under-cap stays underneath so no scalp shows through the gaps."""
import math, random

# ---------------------------------------------------------------- strands
def strand_texture(W=512, H=512, slices=8, seed=5):
    """One texture, several vertical slices of it, each a different clump.
       Returns (rgba bytes, W, H). Alpha is what cuts the silhouette."""
    rng = random.Random(seed)
    px = bytearray(W * H * 4)
    sw = W // slices
    for s in range(slices):
        x0 = s * sw
        n = rng.randint(5, 9)
        for _ in range(n):
            cx = x0 + rng.uniform(sw * .10, sw * .90)
            wdt = rng.uniform(sw * .055, sw * .13)
            drift = rng.uniform(-sw * .30, sw * .30)
            tipF = rng.uniform(.52, 1.0)             # where this strand ends
            bright = rng.uniform(.42, .86)
            for y in range(H):
                t = y / (H - 1.0)
                if t > tipF: break
                # strands drift sideways and thin out toward the tip
                xc = cx + drift * (t ** 1.6)
                w = wdt * (1.0 - .55 * (t / max(tipF, 1e-6)) ** 1.4)
                a_end = 1.0 - smooth((t / max(tipF, 1e-6) - .70) / .30)
                lo = int(max(x0, xc - w)); hi = int(min(x0 + sw - 1, xc + w))
                for x in range(lo, hi + 1):
                    d = abs(x - xc) / (w or 1)
                    a = (1.0 - d * d) * a_end
                    if a <= 0: continue
                    i = (y * W + x) * 4
                    v = int(255 * bright * (.55 + .45 * (1 - d)))
                    if a * 255 > px[i + 3]:
                        px[i] = px[i+1] = px[i+2] = v
                        px[i+3] = int(min(255, a * 255))
    return bytes(px), W, H

def smooth(t):
    t = max(0.0, min(1.0, t)); return t * t * (3 - 2 * t)

# ---------------------------------------------------------------- helpers
def _norm(v):
    L = math.sqrt(sum(x*x for x in v)) or 1.0
    return [x / L for x in v]
def _sub(a, b): return [a[i]-b[i] for i in range(3)]
def _add(a, b): return [a[i]+b[i] for i in range(3)]
def _mul(a, k): return [x*k for x in a]
def _cross(a, b):
    return [a[1]*b[2]-a[2]*b[1], a[2]*b[0]-a[0]*b[2], a[0]*b[1]-a[1]*b[0]]
def _lerp(a, b, t): return [a[i] + (b[i]-a[i])*t for i in range(3)]

# Lengths are in world units, where the whole figure is about 16 tall and
# the head about 1.5 across. The first pass had them four times too long
# and the hair came out as a willow tree.
STYLES = {
    'long':  dict(cards=760, length=1.95, segs=9,  width=.095, gravity=.92, volume=.16, curl=.05),
    'bob':   dict(cards=680, length=0.95, segs=7,  width=.095, gravity=.95, volume=.14, curl=.04),
    'wavy':  dict(cards=780, length=1.70, segs=10, width=.100, gravity=.86, volume=.20, curl=.17),
    'curls': dict(cards=840, length=1.05, segs=10, width=.085, gravity=.62, volume=.30, curl=.46),
    'short': dict(cards=620, length=0.52, segs=6,  width=.090, gravity=.84, volume=.20, curl=.10),
    'crop':  dict(cards=560, length=0.32, segs=5,  width=.085, gravity=.78, volume=.18, curl=.12),
}

def build(scalp_pos, scalp_nrm, scalp_tris, style='long', seed=3, slices=8):
    """scalp_* describe the cap the hair grows out of, in world units."""
    o = STYLES.get(style, STYLES['long'])
    rng = random.Random(seed)

    # roots, distributed over the cap by triangle area so the parting is
    # not denser than the back of the head
    areas = []
    for a, b, c in scalp_tris:
        pa, pb, pc = scalp_pos[a], scalp_pos[b], scalp_pos[c]
        cr = _cross(_sub(pb, pa), _sub(pc, pa))
        areas.append(math.sqrt(sum(x*x for x in cr)) * .5)
    total = sum(areas) or 1.0
    cum = []; run = 0.0
    for A in areas: run += A; cum.append(run / total)

    ys = [p[1] for p in scalp_pos]
    yTop = max(ys); yBot = min(ys)
    zs = [p[2] for p in scalp_pos]; zF = max(zs); zB = min(zs)
    xs = [p[0] for p in scalp_pos]
    span = (yTop - yBot) or 1.0
    # Hair radiates from the crown and lies along the scalp before it falls.
    # Starting each card along the surface normal sends every one of them
    # straight up off the top of the skull first, which is what turned the
    # first pass into a fountain.
    crown = [(min(xs) + max(xs)) * .5, yTop, (zF + zB) * .5]

    P = []; N = []; UV = []; C = []; T = []
    # Two passes: a short dense under-layer that fills, then the long cards
    # that make the silhouette. One layer alone is either see-through or a
    # solid slab.
    LAYERS = ((int(o['cards'] * .45), .34, .80), (o['cards'], 1.0, 1.0))
    for layer, (count, lenK, widK) in enumerate(LAYERS):
      for k in range(count):
          r = rng.random()
          lo, hi = 0, len(cum) - 1
          while lo < hi:
              mid = (lo + hi) // 2
              if cum[mid] < r: lo = mid + 1
              else: hi = mid
          a, b, c = scalp_tris[lo]
          u1, u2 = rng.random(), rng.random()
          if u1 + u2 > 1: u1, u2 = 1 - u1, 1 - u2
          w = (1 - u1 - u2, u1, u2)
          root = [sum(w[j] * scalp_pos[[a,b,c][j]][i] for j in range(3)) for i in range(3)]
          nrm = _norm([sum(w[j] * scalp_nrm[[a,b,c][j]][i] for j in range(3)) for i in range(3)])

          # The front of the head keeps its hair short or it hangs over the
          # face; the back and sides carry the length.
          zf = (root[2] - zB) / ((zF - zB) or 1)
          fall = smooth((.80 - zf) / .34)
          L = o['length'] * lenK * (.28 + .72 * fall) * rng.uniform(.80, 1.18)
          segs = max(3, int(o['segs'] * (.55 + .45 * fall)))

          side = _norm(_cross(nrm, [0, 1, 0]))
          if sum(x*x for x in side) < 1e-6: side = [1, 0, 0]
          away = _sub(root, crown)
          dn = sum(away[i] * nrm[i] for i in range(3))
          away = [away[i] - nrm[i] * dn for i in range(3)]
          if sum(x*x for x in away) < 1e-5:
              away = [root[0] - crown[0], 0, root[2] - crown[2]]
          if sum(x*x for x in away) < 1e-8: away = side[:]
          away = _norm(away)
          d = _norm(_add(_mul(away, .78), _mul(nrm, .22)))
          p = root[:]
          ph = rng.uniform(0, 6.283)
          wid = o['width'] * widK * rng.uniform(.75, 1.3)
          base = len(P)
          for s in range(segs + 1):
              t = s / segs
              # the strand leaves the scalp along its normal, then gravity
              # takes over; a little curl keeps it off a perfect arc
              g = smooth(t / .40) * o['gravity']
              d = _norm(_lerp(d, [0, -1, 0], g * .62))
              d = _add(d, _mul(_norm([math.cos(ph + t*5.0), 0, math.sin(ph + t*5.0)]),
                               o['curl'] * .22))
              d = _norm(d)
              if s > 0:
                  p = _add(p, _mul(d, L / segs))
                  # volume early, then the clump draws back in
                  push = o['volume'] * math.sin(min(1.0, t * 1.9) * math.pi) * .16
                  p = _add(p, _mul(nrm, push))
              sd = _norm(_cross(d, nrm))
              if sum(x*x for x in sd) < 1e-6: sd = side
              fn = _norm(_cross(sd, d))
              half = wid * (1.0 - .62 * (t ** 1.4)) * .5
              uslice = rng.randrange(slices) if s == 0 else uslice
              u0 = uslice / slices; u1b = (uslice + 1) / slices
              shade = .34 + .46 * (t ** .8)            # dark roots, lit tips
              for sgn, uu in ((-1, u0), (1, u1b)):
                  P.append(_add(p, _mul(sd, sgn * half)))
                  N.append(fn[:])
                  UV.append([uu, t])
                  C.append([shade, shade, shade])
              if s > 0:
                  i0 = base + (s-1)*2; i1 = i0 + 1; i2 = i0 + 2; i3 = i0 + 3
                  T.append([i0, i2, i1]); T.append([i1, i2, i3])
    if not P: return None
    return {'position':[x for q in P for x in q], 'normal':[x for q in N for x in q],
            'uv':[x for q in UV for x in q], 'color':[x for q in C for x in q],
            'index':[i for f in T for i in f], 'n':len(P)}

def cap_mesh(cap):
    """The under-cap as a drawable mesh. Dark, tight to the skull, and only
       there so the scalp does not show between the cards."""
    P = cap['pos']; NN = cap['nrm']; T = cap['tris']
    return {'position':[x for q in P for x in q],
            'normal':[x for q in NN for x in q],
            'index':[i for f in T for i in f], 'n':len(P)}
