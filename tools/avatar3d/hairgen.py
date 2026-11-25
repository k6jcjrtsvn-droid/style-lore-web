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

class RNG:
    """An explicit generator, because the same hair has to come out of the
       build and out of the browser. Python's Mersenne Twister and the
       browser's Math.random agree about nothing, and the member's device
       renders the cached portrait -- so a figure that differs between the
       two is a figure that changes when you look at it."""
    __slots__ = ('s',)
    def __init__(self, seed):
        self.s = (int(seed) * 2654435761 + 1) & 0xFFFFFFFF or 1
    def next(self):
        x = self.s
        x ^= (x << 13) & 0xFFFFFFFF
        x ^= x >> 17
        x ^= (x << 5) & 0xFFFFFFFF
        self.s = x & 0xFFFFFFFF
        return self.s
    def random(self):
        return self.next() / 4294967296.0
    def uniform(self, a, b):
        return a + (b - a) * self.random()
    def randint(self, a, b):
        return a + int(self.random() * (b - a + 1))
    def randrange(self, n):
        return int(self.random() * n)

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

# Narrower cards than the first pass, and more of them. A card .165 wide
# is an eighth of the width of the head: at the root, where the clump
# texture is at full width, it draws a rectangle, and a head of hair made
# of rectangles is a head of hair you can count the pieces of.
STYLISED = {
    'long':  dict(cards=980, length=2.05, segs=12, width=.100, gravity=.90, volume=.26, curl=.06),
    'bob':   dict(cards=900, length=1.00, segs=9,  width=.100, gravity=.94, volume=.22, curl=.05),
    'wavy':  dict(cards=1010,length=1.80, segs=13, width=.106, gravity=.84, volume=.30, curl=.18),
    'curls': dict(cards=1120,length=1.10, segs=13, width=.090, gravity=.60, volume=.40, curl=.46),
    'short': dict(cards=840, length=0.56, segs=8,  width=.092, gravity=.82, volume=.28, curl=.10),
    'crop':  dict(cards=740, length=0.34, segs=7,  width=.088, gravity=.76, volume=.24, curl=.12),
}

def build(scalp_pos, scalp_nrm, scalp_tris, style='long', seed=3, slices=8,
          stylised=True):
    """scalp_* describe the cap the hair grows out of, in world units."""
    table = STYLISED if stylised else STYLES
    o = table.get(style, table['long'])
    rng = RNG(seed)

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
    # Each layer sits on its own shell, and every card inside a layer gets
    # a hair's breadth more offset than the last. Without it the cards are
    # near-coincident, they fight for depth, and the whole head renders as
    # a woven basket -- which looks like a texture bug and is not one.
    # Four passes. A dense short under-layer that fills; the main cards
    # that carry the shape; a thin scatter of flyaways sitting further off
    # the scalp, which is what stops the silhouette reading as a moulded
    # shell; and short baby hairs at the front hairline, because the hard
    # line where the cap meets the forehead is the single thing that most
    # gives a wig away.
    #   count, length x, width x, shell offset, mode
    #   mode 0 normal  1 flyaway  2 baby hair at the hairline
    LAYERS = ((int(o['cards'] * .60), .34, .80, .004, 0),
              (o['cards'],            1.0, 1.0, .040, 0),
              (int(o['cards'] * .08), .80, .38, .062, 1),
              (int(o['cards'] * .16), .10, .34, .012, 2))
    for layer, (count, lenK, widK, shell, mode) in enumerate(LAYERS):
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
          if mode == 2 and zf < .76: continue    # baby hairs: the front only
          # and no flyaway over the face: at this length one reads as a
          # scratch across the picture rather than as a loose hair
          if mode == 1 and zf > .74: continue
          fall = smooth((.80 - zf) / .34)
          L = o['length'] * lenK * (.28 + .72 * fall) * rng.uniform(.80, 1.18)
          if mode == 2: L = o['length'] * lenK * rng.uniform(.70, 1.55)
          if mode == 1: L *= rng.uniform(.85, 1.35)
          segs = 3 if mode == 2 else max(3, int(o['segs'] * (.55 + .45 * fall)))

          side = _norm(_cross(nrm, [0, 1, 0]))
          if sum(x*x for x in side) < 1e-6: side = [1, 0, 0]
          away = _sub(root, crown)
          dn = sum(away[i] * nrm[i] for i in range(3))
          away = [away[i] - nrm[i] * dn for i in range(3)]
          if sum(x*x for x in away) < 1e-5:
              away = [root[0] - crown[0], 0, root[2] - crown[2]]
          if sum(x*x for x in away) < 1e-8: away = side[:]
          away = _norm(away)
          # Hair that grows in front of the crown wants to fall straight
          # forward, which puts a curtain over the face -- the first
          # version had a fringe down to the nose on every style. Past the
          # crown the fall is bent outward instead, which is what a
          # parting is, and shortened, because the front of a head of hair
          # is always the short part of it.
          fr = smooth((zf - .55) / .45)
          if fr > 0:
              away = _norm([away[0] * (1 + 2.1 * fr), away[1], away[2] * (1 - .88 * fr)])
              # and no two of them the same length, or it is a bowl cut
              L *= (1 - .42 * fr) * (1 + fr * rng.uniform(-.38, .38))
          d = _norm(_add(_mul(away, .78), _mul(nrm, .22)))
          if mode == 1:
              # a flyaway leaves at its own angle, which is the point of it
              d = _norm([d[0] + rng.uniform(-.42, .42), d[1] + rng.uniform(-.14, .34),
                         d[2] + rng.uniform(-.42, .42)])
          elif mode == 2:
              # baby hairs lie back along the forehead rather than stand up
              d = _norm([d[0] * .5 + rng.uniform(-.5, .5),
                         d[1] * .3 - rng.uniform(.0, .35),
                         d[2] * .5 - .55])
          off = shell + (k % 97) * .00035
          root = _add(root, _mul(nrm, off))
          p = root[:]
          # A frame carried along the strand. Taking cross(d, nrm) fresh at
          # every step degenerates the moment the strand falls parallel to
          # the scalp normal, and the side vector flips sign -- so adjacent
          # cards ended up with opposite normals and the lit head rendered
          # as a woven basket. Rendering the same cards unlit showed a
          # clean mass, and as raw normals showed chaos, which is what
          # pointed at the frame rather than at the geometry.
          frame = _norm(_cross(d, nrm))
          if sum(x*x for x in frame) < 1e-6: frame = side[:]
          ph = rng.uniform(0, 6.283)
          wid = o['width'] * widK * rng.uniform(.75, 1.3)
          # Hair is not one colour. Letting each card sit a little lighter
          # or darker than its neighbours does more for the look of it
          # than any amount of shader work, because it is what the eye
          # reads as depth.
          bright = rng.uniform(.78, 1.22)
          base = len(P)
          for s in range(segs + 1):
              t = s / segs
              # the strand leaves the scalp along its normal, then gravity
              # takes over; a little curl keeps it off a perfect arc
              g = smooth(t / .40) * (o['gravity'] * .55 if mode == 1 else
                                     o['gravity'] * .30 if mode == 2 else o['gravity'])
              d = _norm(_lerp(d, [0, -1, 0], g * .62))
              d = _add(d, _mul(_norm([math.cos(ph + t*5.0), 0, math.sin(ph + t*5.0)]),
                               o['curl'] * .22))
              d = _norm(d)
              if s > 0:
                  p = _add(p, _mul(d, L / segs))
                  # volume early, then the clump draws back in
                  push = o['volume'] * math.sin(min(1.0, t * 1.9) * math.pi) * .16
                  p = _add(p, _mul(nrm, push))
              # re-orthogonalise rather than rebuild: a rotation-minimising
              # frame has no singularity to fall into
              dp = sum(frame[i] * d[i] for i in range(3))
              sd = [frame[i] - d[i] * dp for i in range(3)]
              if sum(x*x for x in sd) < 1e-8: sd = side[:]
              sd = _norm(sd); frame = sd
              geo = _norm(_cross(sd, d))
              # Shade the hair as one mass, not as hundreds of separate
              # ribbons: lean the card's normal toward the scalp's.
              fn = _norm([nrm[i] * .72 + geo[i] * .28 for i in range(3)])
              half = wid * (1.0 - .62 * (t ** 1.4)) * .5
              uslice = rng.randrange(slices) if s == 0 else uslice
              u0 = uslice / slices; u1b = (uslice + 1) / slices
              shade = (.30 + .38 * (t ** .8)) * bright   # dark roots, lit tips
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


# ---------------------------------------------------------------- clumps
def clump_texture(W=512, H=512, slices=8, seed=9):
    """Hair for a stylised figure.

       Fine strands are a realism cue: at avatar size they read as noise,
       and cutting them with alphaTest turns each one into a shard. A
       stylised head of hair is a handful of sculpted CLUMPS with soft
       edges and one bright band running across them -- which is also
       kinder to the renderer, because soft alpha hides the card edges
       instead of advertising them."""
    import math as _m
    rng = random.Random(seed)
    px = bytearray(W * H * 4)
    sw = W // slices
    for s in range(slices):
        x0 = s * sw
        n = rng.randint(1, 2)
        for _ in range(n):
            cx = x0 + rng.uniform(sw * .22, sw * .78)
            wdt = rng.uniform(sw * .28, sw * .42)
            drift = rng.uniform(-sw * .16, sw * .16)
            tipF = rng.uniform(.80, 1.0)
            band = rng.uniform(.26, .46)          # where the highlight sits
            for y in range(H):
                t = y / (H - 1.0)
                if t > tipF: break
                xc = cx + drift * (t ** 1.5)
                w = wdt * (1.0 - .70 * (t / max(tipF, 1e-6)) ** 1.25)
                a_end = 1.0 - smooth((t / max(tipF, 1e-6) - .62) / .38)
                lo = int(max(x0, xc - w)); hi = int(min(x0 + sw - 1, xc + w))
                for x in range(lo, hi + 1):
                    d = abs(x - xc) / (w or 1)
                    a = max(0.0, 1.0 - d ** 6) ** .60 * a_end   # plateau, narrow shoulder
                    if a <= .004: continue
                    i = (y * W + x) * 4
                    # one highlight band across the clump, and the clump is
                    # darker where it folds away at the edges
                    hl = _m.exp(-((t - band) ** 2) / .010) * (1.0 - d * .7)
                    v = int(255 * min(1.0, .30 + .34 * (1 - d) + .55 * hl))
                    if a * 255 > px[i + 3]:
                        px[i] = px[i+1] = px[i+2] = v
                        px[i+3] = int(min(255, a * 255))
    return bytes(px), W, H



# ------------------------------------------------------------------ brows
def _brow_sampler(pos, nrm, e, g):
    """A smooth read of the head's surface anywhere along the brow.

       Snapping each strand to its nearest vertex would quantise seventy
       strands onto the ten or so vertices the mesh has there and give a
       brow built out of steps, so this is a Gaussian-weighted average of
       the vertices around a target (x, y) instead."""
    sgn = 1.0 if e[0] >= 0 else -1.0
    idx = []
    for i, p in enumerate(pos):
        if p[0] * sgn < -.06 * g: continue
        if abs(p[0]) > 1.45 * g: continue
        if p[1] < e[1] - .10 * g or p[1] > e[1] + 1.15 * g: continue
        if p[2] < e[2] - .80 * g: continue
        idx.append(i)
    hx = .17 * g; hy = .14 * g
    def sample(tx, ty):
        W = 0.0; P = [0.0, 0.0, 0.0]; N = [0.0, 0.0, 0.0]
        for i in idx:
            p = pos[i]
            dx = (p[0] - tx) / hx; dy = (p[1] - ty) / hy
            w = math.exp(-(dx*dx + dy*dy))
            if w < 1e-4: continue
            W += w
            for k in range(3):
                P[k] += p[k] * w; N[k] += nrm[i][k] * w
        if not W: return None
        P = [c / W for c in P]; N = _norm([c / W for c in N])
        # A weighted average of points on a curved surface lands INSIDE
        # it. The bias is small -- a fraction of the kernel width -- but
        # so is the height a brow hair sits at, and the first version of
        # this had the whole brow submerged in the forehead.
        out = 0.0
        for i in idx:
            p = pos[i]
            dx = (p[0] - tx) / hx; dy = (p[1] - ty) / hy
            if dx*dx + dy*dy > 4: continue
            d = sum((p[k] - P[k]) * N[k] for k in range(3))
            if d > out: out = d
        return ([P[k] + N[k] * out for k in range(3)], N)
    return sample


def build_brows(pos, nrm, eL, eR, seed=11, hairs=160):
    """Eyebrows as strands rather than as a smudge in the skin texture.

       The head's UV island gives the brow about thirty texels across its
       thickness, so a painted brow can only ever be a smear; and a
       painted brow has no edge of its own, while the thing that makes a
       real brow read as hair is that its outline is made of the hairs
       sticking out of it. Around a hundred and sixty tapered strands a
       side cost some twenty-five hundred vertices for the pair, against
       the thirteen thousand the body already spends."""
    g = abs(eL[0] - eR[0]) or 1.0
    P = []; N = []; C = []; T = []
    for e in (eL, eR):
        sample = _brow_sampler(pos, nrm, e, g)
        if sample is None: continue
        sgn = 1.0 if e[0] >= 0 else -1.0
        # the same stream both sides: a face whose brows disagree looks
        # wrong in a way nobody can name but everybody sees
        rng = RNG(seed)
        xIn = .18 * g; xOut = .93 * g
        arch = lambda s: e[1] + (.385 + .100 * math.sin(math.pi * (s ** .85))) * g
        ax = lambda s: sgn * (xIn + (xOut - xIn) * s)
        thick = lambda s: (.072 - .040 * (s ** 1.5)) * g
        for k in range(hairs):
            # Hairs are spaced so the HEAD of the brow is the dense end.
            # Laying them out evenly along s does the opposite: the head
            # is the thick part, so an even count spread across a thicker
            # band comes out sparser than the thin tail, and the brow
            # ends up as a dark comma pointing at the nose.
            s = ((k + rng.uniform(.1, .9)) / hairs) ** 1.32
            u = (rng.random() + rng.random() - 1) * thick(s) + .14 * thick(s)
            seat = sample(ax(s), arch(s) + u)
            if seat is None: continue
            nS = seat[1]
            ds = .02
            a = sample(ax(max(0.0, s - ds)), arch(max(0.0, s - ds)) + u)
            b = sample(ax(min(1.0, s + ds)), arch(min(1.0, s + ds)) + u)
            if a is None or b is None: continue
            Tt = _norm(_sub(b[0], a[0]))
            U = _norm(_cross(nS, Tt))
            if U[1] < 0: U = _mul(U, -1)
            # inner hairs sweep up, the body lies along the arch, the
            # tail drops
            sweep = (.62 - 1.00 * s) * (1 + rng.uniform(-.13, .13)) - .04
            d = _norm(_add(_mul(Tt, math.cos(sweep)), _mul(U, math.sin(sweep))))
            L = (.105 - .042 * (s ** 1.4)) * g * rng.uniform(.72, 1.22)
            wid = (.0135 + .0055 * (1 - s)) * g * rng.uniform(.70, 1.30)
            bright = rng.uniform(.74, 1.16)
            segs = 3
            base = len(P)
            # The strand is grown straight from where it is seated, with a
            # slight droop, and NOT re-seated against the surface at every
            # step: over a hair this short the surface drops by about a
            # tenth of a millimetre, while a re-seat that lands on the
            # eyelid instead of the ridge moves the step by more than the
            # step itself. The first version did that and the brows
            # crawled down over the eyes.
            nn = nS
            for i in range(segs + 1):
                t = i / segs
                lift = (.012 + .026 * t) * g
                p = _add(_add(_add(seat[0], _mul(d, L * t)),
                              _mul(U, -.09 * L * t * t)), _mul(nn, lift))
                side = _norm(_cross(nn, d))
                half = wid * (1 - .85 * (t ** 1.1)) * .5
                shade = bright * (.62 + .34 * (t ** .7))
                for sg in (-1, 1):
                    P.append(_add(p, _mul(side, sg * half)))
                    N.append(nn[:])
                    C.append([shade, shade, shade])
                if i > 0:
                    i0 = base + (i-1)*2
                    T.append([i0, i0+2, i0+1]); T.append([i0+1, i0+2, i0+3])
    if not P: return None
    return {'position':[x for q in P for x in q], 'normal':[x for q in N for x in q],
            'color':[x for q in C for x in q],
            'index':[i for f in T for i in f], 'n':len(P)}


# ----------------------------------------------------------------- lashes
def build_lashes(hp, groups, c, r, seed=17, sgn=1.0):
    """Eyelashes, rooted on the mesh's own lid rims.

       The lash line was a dark smear in the skin texture, which is enough
       at thumbnail size and nothing like enough once a figure can be
       turned and looked at. Lashes are what make an eye read as wet and
       alive: they break the hard edge of the lid and cast the shadow
       that stops the eyeball looking like a bead glued into a socket.

       The strips are ordered ACROSS the face, not by angle around the
       eye. Angle was the first thing tried and it fans the lashes out
       like a sea urchin, because an eye seen from the front is not a
       circle and the corners of a lid are not where a circle would put
       them. And the lashes grow from the strip's INNER edge -- the lid
       margin -- because rooting them on the outer one puts the whole
       fringe a lid's width up the eyelid."""
    rng = RNG(seed)
    P = []; N = []; C = []; T = []
    for g in groups:
        ids = g['ids']
        if not ids or len(ids) < 6: continue
        pts = [list(hp[i]) for i in ids]
        xs = [p[0] for p in pts]
        xMin, xMax = min(xs), max(xs)
        span = (xMax - xMin) or 1.0
        n = g['count']
        for k in range(n):
            t0 = (k + rng.uniform(.12, .88)) / n
            x = xMin + span * t0
            best = None; bw = span / n * 2.2
            for p in pts:
                if abs(p[0] - x) > bw: continue
                e = -p[1] if g['upper'] else p[1]
                if best is None or e > best[1]: best = (p, e)
            if best is None: continue
            root = best[0]
            outward = 1.0 if g['upper'] else -1.0
            toOuter = 1.0 if sgn >= 0 else -1.0
            away = max(0.0, (t0 if sgn >= 0 else 1 - t0) - .18) / .82
            d = _norm([toOuter * g['sweep'] * away, outward * g['up'], g['fwd']])
            mid = 1 - abs(t0 - .55) * 2
            L = r * g['len'] * (.20 + .80 * max(0.0, mid)) * rng.uniform(.80, 1.20)
            wid = r * g['wid'] * rng.uniform(.70, 1.30)
            nn = [0.0, 0.0, 1.0]
            curl = g['curl']
            segs = 3
            base = len(P)
            for i in range(segs + 1):
                t = i / segs
                p = [root[0] + d[0]*L*t,
                     root[1] + d[1]*L*t + outward * curl * L * t * t,
                     root[2] + d[2]*L*t]
                side = _norm(_cross(nn, d))
                half = wid * (1 - .90 * (t ** 1.2)) * .5
                shade = .30 + .34 * t
                for sg in (-1, 1):
                    P.append(_add(p, _mul(side, sg * half)))
                    N.append(nn[:])
                    C.append([shade, shade, shade])
                if i > 0:
                    i0 = base + (i-1)*2
                    T.append([i0, i0+2, i0+1]); T.append([i0+1, i0+2, i0+3])
    if not P: return None
    return {'position':[x for q in P for x in q], 'normal':[x for q in N for x in q],
            'color':[x for q in C for x in q],
            'index':[i for f in T for i in f], 'n':len(P)}
