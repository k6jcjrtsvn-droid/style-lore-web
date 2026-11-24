"""Kibbe type -> starting body shape.

   The app already models a Kibbe result as a blend over five families plus a
   yin/yang modifier (KIBBE_TARGETS in index.html), so the body is built the
   same way rather than as thirteen hand-made presets. That means every one of
   the thirteen types is covered, blends between them behave sensibly, and a
   future type costs nothing.

   The families, as the system describes them:
     dramatic  yang — long vertical line, sharp blunt bone, narrow and
                      straight through the body, slim limbs
     natural   soft yang — broad blunt bone, wide shoulders, straight waist,
                      square and athletic
     classic   balance — moderate everything, even proportions
     gamine    yin/yang mix — small frame, short vertical line, slim and
                      straight but delicate
     romantic  yin — soft rounded flesh, full bust and hip, defined waist,
                      short vertical line

   `mod` is the flamboyant/soft modifier: positive exaggerates the yang
   reading of the blend, negative softens it toward yin.
"""

FAMILY = {
 'dramatic': {
   'torso/torso-scale-vert-incr':      .55,   # long vertical line
   'armslegs/l-upperleg-scale-vert-incr':  .40,
   'armslegs/r-upperleg-scale-vert-incr':  .40,
   'armslegs/lowerlegs-height-incr':       .40,
   'torso/torso-scale-horiz-decr':     .30,   # narrow through the body
   'torso/torso-scale-depth-decr':     .25,
   'hip/hip-scale-horiz-decr':         .40,
   'breast/breast-volume-vert-down':   .25,
   'armslegs/l-upperarm-scale-horiz-decr': .35,
   'armslegs/r-upperarm-scale-horiz-decr': .35,
   'armslegs/l-upperleg-fat-decr':     .35,
   'armslegs/r-upperleg-fat-decr':     .35,
   'stomach/stomach-tone-incr':        .30,
 },
 'natural': {
   'torso/torso-scale-horiz-incr':     .55,   # broad, blunt shoulder
   'torso/torso-muscle-dorsi-incr':    .35,
   'torso/torso-vshape-incr':          .30,
   'hip/hip-waist-down':               .35,   # straight through the waist
   'stomach/stomach-tone-incr':        .25,
   'hip/hip-scale-horiz-incr':         .15,
   'armslegs/l-upperarm-muscle-incr':  .25,
   'armslegs/r-upperarm-muscle-incr':  .25,
   'breast/breast-volume-vert-down':   .10,
 },
 'classic': {
   'torso/torso-scale-vert-incr':      .15,   # moderate, even, symmetrical
   'hip/hip-scale-horiz-incr':         .10,
   'breast/breast-volume-vert-up':     .10,
 },
 'gamine': {
   'torso/torso-scale-vert-decr':      .55,   # short vertical line
   'armslegs/l-upperleg-scale-vert-decr':  .40,
   'armslegs/r-upperleg-scale-vert-decr':  .40,
   'armslegs/lowerlegs-height-decr':       .40,
   'torso/torso-scale-horiz-decr':     .35,   # small, slim frame
   'hip/hip-scale-horiz-decr':         .30,
   'breast/breast-volume-vert-down':   .30,
   'armslegs/l-upperarm-scale-horiz-decr': .40,
   'armslegs/r-upperarm-scale-horiz-decr': .40,
   'armslegs/l-hand-scale-decr':       .30,
   'armslegs/r-hand-scale-decr':       .30,
 },
 'romantic': {
   'hip/hip-scale-horiz-incr':         .60,   # full hip
   'breast/breast-volume-vert-up':     .50,   # full bust
   'hip/hip-waist-up':                 .45,   # defined waist
   'stomach/stomach-tone-decr':        .35,   # soft flesh
   'buttocks/buttocks-volume-incr':    .45,
   'torso/torso-scale-vert-decr':      .25,   # short vertical line
   'armslegs/l-upperarm-fat-incr':     .25,
   'armslegs/r-upperarm-fat-incr':     .25,
   'armslegs/l-upperleg-fat-incr':     .30,
   'armslegs/r-upperleg-fat-incr':     .30,
 },
}

# What the flamboyant/soft modifier pushes on top of the blend.
MOD_YANG = {
 'torso/torso-scale-horiz-incr': .30, 'torso/torso-scale-vert-incr': .25,
 'torso/torso-vshape-incr': .25, 'stomach/stomach-tone-incr': .20,
}
MOD_YIN = {
 'breast/breast-volume-vert-up': .30, 'hip/hip-scale-horiz-incr': .25,
 'stomach/stomach-tone-decr': .25, 'hip/hip-waist-up': .20,
}

def shape_for(vec):
    """vec is the app's own {family: weight, mod: -1..1} — same numbers the
       quiz already produces, so the avatar cannot disagree with the result."""
    out={}
    for fam, w in vec.items():
        if fam == 'mod' or not w: continue
        for t, v in FAMILY.get(fam, {}).items():
            out[t] = out.get(t, 0.0) + v*w
    mod = vec.get('mod', 0.0)
    if mod:
        src = MOD_YANG if mod > 0 else MOD_YIN
        for t, v in src.items():
            out[t] = out.get(t, 0.0) + v*abs(mod)
    # Opposing targets cancel rather than fight: a blend that asks for both a
    # longer and a shorter torso should get neither, not both applied.
    for a, b in [('torso/torso-scale-vert-incr','torso/torso-scale-vert-decr'),
                 ('torso/torso-scale-horiz-incr','torso/torso-scale-horiz-decr'),
                 ('hip/hip-scale-horiz-incr','hip/hip-scale-horiz-decr'),
                 ('stomach/stomach-tone-incr','stomach/stomach-tone-decr'),
                 ('breast/breast-volume-vert-up','breast/breast-volume-vert-down'),
                 ('hip/hip-waist-up','hip/hip-waist-down')]:
        if a in out and b in out:
            d = out[a] - out[b]
            out[a] = max(0.0, d); out[b] = max(0.0, -d)
    for side in 'lr':
        for a, b in [('upperleg-scale-vert-incr','upperleg-scale-vert-decr'),
                     ('upperarm-scale-horiz-incr','upperarm-scale-horiz-decr')]:
            ka='armslegs/%s-%s'%(side,a); kb='armslegs/%s-%s'%(side,b)
            if ka in out and kb in out:
                d=out[ka]-out[kb]; out[ka]=max(0.0,d); out[kb]=max(0.0,-d)
    for a,b in [('armslegs/lowerlegs-height-incr','armslegs/lowerlegs-height-decr')]:
        if a in out and b in out:
            d=out[a]-out[b]; out[a]=max(0.0,d); out[b]=max(0.0,-d)
    return [(t, round(min(1.0, v), 3)) for t, v in sorted(out.items()) if v > 0.004]

TYPES = [
 ('dramatic',            {'dramatic':1.0}),
 ('soft-dramatic',       {'dramatic':.7,'romantic':.3,'mod':-.6}),
 ('flamboyant-natural',  {'natural':.7,'dramatic':.3,'mod':.6}),
 ('natural',             {'natural':1.0}),
 ('soft-natural',        {'natural':.7,'romantic':.3,'mod':-.6}),
 ('dramatic-classic',    {'classic':.7,'dramatic':.3,'mod':.6}),
 ('classic',             {'classic':1.0}),
 ('soft-classic',        {'classic':.7,'romantic':.3,'mod':-.6}),
 ('flamboyant-gamine',   {'gamine':.7,'dramatic':.3,'mod':.6}),
 ('gamine',              {'gamine':1.0}),
 ('soft-gamine',         {'gamine':.7,'romantic':.3,'mod':-.6}),
 ('theatrical-romantic', {'romantic':.7,'dramatic':.3,'mod':.6}),
 ('romantic',            {'romantic':1.0}),
]
