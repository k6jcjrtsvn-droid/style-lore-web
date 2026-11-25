"""Hair card texture: clumps made of strands, not of toothpaste.

   The shipped texture draws one fat soft clump per slice. On a card it
   reads as a plastic ribbon, because every edge of it is a smooth curve
   and real hair has no smooth edges -- its outline is made of the
   individual hairs that stick out of the clump. So: the clump still
   gives the mass and the highlight, but the ALPHA is cut by fine
   strands, and the strands at the sides overhang the clump, which is
   what frays the silhouette."""
import math, random
import numpy as np

def smooth(t):
    t = np.clip(t, 0, 1); return t*t*(3-2*t)

def strand_clump_texture(W=512, H=512, slices=8, seed=9):
    rng = random.Random(seed)
    A = np.zeros((H, W), np.float32)      # alpha
    L = np.zeros((H, W), np.float32)      # luminance
    sw = W // slices
    ys = np.arange(H, dtype=np.float32)
    t = ys / (H - 1.0)
    for s in range(slices):
        x0 = s * sw
        xs = np.arange(x0, x0 + sw, dtype=np.float32)
        band = rng.uniform(.24, .46)            # where the sheen sits
        # --- the clump envelope: mass, highlight, and a soft inner alpha
        nclump = rng.randint(1, 2)
        env = np.zeros((H, sw), np.float32)
        lum = np.zeros((H, sw), np.float32)
        for _ in range(nclump):
            cx = x0 + rng.uniform(sw * .26, sw * .74)
            wdt = rng.uniform(sw * .26, sw * .40)
            drift = rng.uniform(-sw * .14, sw * .14)
            tipF = rng.uniform(.84, 1.0)
            xc = cx + drift * (t ** 1.5)
            # Narrow at the root as well as at the tip. A clump that is
            # full width at v=0 draws a solid bar across the top of every
            # card, and at the crown -- where the cards lie flat and you
            # see mostly their root half -- the head comes out tiled with
            # bright rectangles.
            w = wdt * (.34 + .66 * smooth(t / .20)) \
                    * (1.0 - .70 * np.clip(t / tipF, 0, 1) ** 1.25)
            d = np.abs(xs[None, :] - xc[:, None]) / np.maximum(w[:, None], 1e-6)
            live = (t <= tipF)[:, None]
            a_end = (1.0 - smooth((np.clip(t / tipF, 0, 1) - .62) / .38))[:, None]
            # the core is solid; the outer third is left to the strands
            core = np.clip(1.0 - (d / .62) ** 4, 0, 1) ** .7 * a_end * live
            hl = np.exp(-((t - band) ** 2) / .010)[:, None] * (1.0 - d * .7)
            v = np.clip(.24 + .26 * (1 - np.clip(d, 0, 1)) + .36 * hl, 0, .82) * (d < 1.25)
            env = np.maximum(env, core)
            lum = np.maximum(lum, v * live)
        # --- the strands. Fine, many, each with its own life, and free to
        #     overhang the clump so the outline is broken.
        n = rng.randint(26, 40)
        for _ in range(n):
            cx = x0 + rng.uniform(sw * .06, sw * .94)
            hw = rng.uniform(.9, 2.3)                  # half width, px
            drift = rng.uniform(-sw * .30, sw * .30)
            tipF = rng.uniform(.34, 1.0)
            bright = rng.uniform(.52, 1.18)
            wob = rng.uniform(-.7, .7)
            ph = rng.uniform(0, 6.283)
            xc = cx + drift * (t ** 1.7) + wob * np.sin(ph + t * rng.uniform(3, 7))
            tt = np.clip(t / tipF, 0, 1)
            hwt = hw * (1.0 - .80 * tt ** 1.5)         # tapers to a point
            d = np.abs(xs[None, :] - xc[:, None]) / np.maximum(hwt[:, None], 1e-6)
            a = np.clip(1.0 - d * d, 0, 1) * ((t <= tipF)[:, None])
            a = a * (1.0 - smooth((tt - .78) / .22))[:, None]
            A[:, x0:x0+sw] = np.maximum(A[:, x0:x0+sw], a)
            hl = np.exp(-((t - band) ** 2) / .013)[:, None]
            # Capped well short of white: the card texture multiplies the
            # hair colour, and a strand that reaches 1.0 renders as bright
            # as lit skin under a 2.4 rim light -- which is how the first
            # pass ended up with a fringe that looked see-through.
            v = np.clip(bright * (.26 + .26 * (1 - np.clip(d, 0, 1)) + .40 * hl), 0, .82)
            lum = np.where(a > .12, np.maximum(lum, v), lum)
        A[:, x0:x0+sw] = np.maximum(A[:, x0:x0+sw], env)
        # Each slice is one card's whole width, so anything still alive at
        # the slice edge becomes a straight vertical cut down the card.
        # Fade the outer margin and the card ends on hair instead.
        win = 1.0 - smooth((np.abs(xs - (x0 + sw * .5)) / (sw * .5) - .80) / .20)
        A[:, x0:x0+sw] *= win[None, :]
        L[:, x0:x0+sw] = lum
    rgba = np.zeros((H, W, 4), np.uint8)
    g = (np.clip(L, 0, 1) * 255).astype(np.uint8)
    rgba[..., 0] = rgba[..., 1] = rgba[..., 2] = g
    rgba[..., 3] = (np.clip(A, 0, 1) * 255).astype(np.uint8)
    return rgba

if __name__ == '__main__':
    # Writes the texture the cards are drawn with. Nothing in the repo
    # generated the shipped one before this file existed, which meant the
    # single asset the hair's whole look depends on could not be changed
    # without guessing how it had been made.
    import os, sys
    from PIL import Image
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(
        os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
        'assets', 'avatar', 'hair_strand.png')
    a = strand_clump_texture()
    Image.fromarray(a, 'RGBA').save(out)
    print(out, os.path.getsize(out), 'bytes')
