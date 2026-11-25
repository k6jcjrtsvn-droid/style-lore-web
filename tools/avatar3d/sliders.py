"""The app's sliders, as MakeHuman targets.

   The figure editor has twelve body sliders and fifteen face sliders,
   all 0..1 with 0.5 as neutral. Each one here is a pair of lists -- what
   to apply going up from the middle, and what to apply going down -- so
   a slider at 0.5 contributes nothing and a slider at either end
   contributes its full list.

   This table ships inside the bundle rather than being retyped in
   JavaScript, for the same reason the Kibbe tables do: a second copy is
   a copy that drifts, and the first browser-side attempt at one invented
   target names that do not exist, so every weight was silently dropped."""

def _lr(name, w=1.0):
    """A target that exists once per side."""
    return [('armslegs/l-' + name, w), ('armslegs/r-' + name, w)]

def _face_lr(folder, name, w=1.0):
    return [(folder + '/l-' + name, w), (folder + '/r-' + name, w)]

BODY = {
 'height':    (([('armslegs/lowerlegs-height-incr', 1.0), ('armslegs/upperlegs-height-incr', 1.0),
                 ('torso/torso-scale-vert-incr', .35)]),
               ([('armslegs/lowerlegs-height-decr', 1.0), ('armslegs/upperlegs-height-decr', 1.0),
                 ('torso/torso-scale-vert-decr', .35)])),
 'shoulders': ([('torso/torso-scale-horiz-incr', 1.0), ('torso/torso-vshape-incr', .55)],
               [('torso/torso-scale-horiz-decr', 1.0), ('torso/torso-vshape-decr', .55)]),
 'chest':     ([('breast/breast-volume-vert-up', .85), ('breast/breast-point-incr', .45),
                ('torso/torso-muscle-pectoral-incr', .35)],
               [('breast/breast-volume-vert-down', .85), ('breast/breast-point-decr', .45),
                ('torso/torso-muscle-pectoral-decr', .35)]),
 'torso':     ([('torso/torso-scale-vert-incr', 1.0)],
               [('torso/torso-scale-vert-decr', 1.0)]),
 'waist':     ([('stomach/stomach-pregnant-incr', .55), ('hip/hip-waist-up', .70),
                ('stomach/stomach-tone-decr', .40)],
               [('stomach/stomach-pregnant-decr', .55), ('hip/hip-waist-down', .70),
                ('stomach/stomach-tone-incr', .40)]),
 'hips':      ([('hip/hip-scale-horiz-incr', 1.0), ('buttocks/buttocks-volume-incr', .70),
                ('hip/hip-scale-depth-incr', .35)],
               [('hip/hip-scale-horiz-decr', 1.0), ('buttocks/buttocks-volume-decr', .70),
                ('hip/hip-scale-depth-decr', .35)]),
 'legs':      ([('armslegs/upperlegs-height-incr', 1.0), ('armslegs/lowerlegs-height-incr', 1.0)],
               [('armslegs/upperlegs-height-decr', 1.0), ('armslegs/lowerlegs-height-decr', 1.0)]),
 # the knee rides up when the thigh is short and the shin long
 'knees':     ([('armslegs/upperlegs-height-decr', .70), ('armslegs/lowerlegs-height-incr', .70)],
               [('armslegs/upperlegs-height-incr', .70), ('armslegs/lowerlegs-height-decr', .70)]),
 'arms':      (_lr('upperarm-scale-vert-incr') + _lr('lowerarm-scale-vert-incr'),
               _lr('upperarm-scale-vert-decr') + _lr('lowerarm-scale-vert-decr')),
 'hands':     (_lr('hand-scale-incr'), _lr('hand-scale-decr')),
 'feet':      (_lr('foot-scale-incr'), _lr('foot-scale-decr')),
 'neck':      ([('neck/neck-scale-horiz-incr', 1.0), ('neck/neck-scale-depth-incr', .60)],
               [('neck/neck-scale-horiz-decr', 1.0), ('neck/neck-scale-depth-decr', .60)]),
}

FACE = {
 'headSize':   ([('head/head-scale-horiz-incr', .85), ('head/head-scale-vert-incr', .85),
                 ('head/head-scale-depth-incr', .60)],
                [('head/head-scale-horiz-decr', .85), ('head/head-scale-vert-decr', .85),
                 ('head/head-scale-depth-decr', .60)]),
 'forehead':   ([('forehead/forehead-scale-vert-incr', 1.0), ('forehead/forehead-trans-forward', .45)],
                [('forehead/forehead-scale-vert-decr', 1.0), ('forehead/forehead-trans-backward', .45)]),
 'cheeks':     (_face_lr('cheek', 'cheek-bones-incr') + _face_lr('cheek', 'cheek-volume-incr', .55),
                _face_lr('cheek', 'cheek-bones-decr') + _face_lr('cheek', 'cheek-volume-decr', .55)),
 'jaw':        ([('chin/chin-jaw-drop-incr', .55), ('chin/chin-width-incr', 1.0),
                 ('chin/chin-bones-incr', .70)],
                [('chin/chin-jaw-drop-decr', .55), ('chin/chin-width-decr', 1.0),
                 ('chin/chin-bones-decr', .70)]),
 'chin':       ([('chin/chin-height-incr', 1.0), ('chin/chin-prominent-incr', .55)],
                [('chin/chin-height-decr', 1.0), ('chin/chin-prominent-decr', .55)]),
 'eyeSize':    (_face_lr('eyes', 'eye-scale-incr'), _face_lr('eyes', 'eye-scale-decr')),
 'eyeSpace':   (_face_lr('eyes', 'eye-trans-out'), _face_lr('eyes', 'eye-trans-in')),
 'eyeTilt':    (_face_lr('eyes', 'eye-corner1-up') + _face_lr('eyes', 'eye-corner2-down', .65),
                _face_lr('eyes', 'eye-corner1-down') + _face_lr('eyes', 'eye-corner2-up', .65)),
 'browThick':  ([('eyebrows/eyebrows-trans-forward', 1.0)],
                [('eyebrows/eyebrows-trans-backward', 1.0)]),
 'browHeight': ([('eyebrows/eyebrows-trans-up', 1.0)],
                [('eyebrows/eyebrows-trans-down', 1.0)]),
 'noseWidth':  ([('nose/nose-scale-horiz-incr', 1.0), ('nose/nose-nostrils-width-incr', .60)],
                [('nose/nose-scale-horiz-decr', 1.0), ('nose/nose-nostrils-width-decr', .60)]),
 'noseLength': ([('nose/nose-scale-vert-incr', 1.0)],
                [('nose/nose-scale-vert-decr', 1.0)]),
 'mouthWidth': ([('mouth/mouth-scale-horiz-incr', 1.0)],
                [('mouth/mouth-scale-horiz-decr', 1.0)]),
 'lips':       ([('mouth/mouth-upperlip-volume-incr', 1.0), ('mouth/mouth-lowerlip-volume-incr', 1.0),
                 ('mouth/mouth-scale-vert-incr', .40)],
                [('mouth/mouth-upperlip-volume-decr', 1.0), ('mouth/mouth-lowerlip-volume-decr', 1.0),
                 ('mouth/mouth-scale-vert-decr', .40)]),
 'ears':       (_face_lr('ears', 'ear-scale-incr'), _face_lr('ears', 'ear-scale-decr')),
}

def all_targets():
    out = set()
    for tbl in (BODY, FACE):
        for up, dn in tbl.values():
            for lst in (up, dn):
                for t, w in lst: out.add(t)
    return sorted(out)

def mix_for(body=None, face=None):
    """0..1 sliders -> [(target, weight)]. 0.5 contributes nothing."""
    out = {}
    for tbl, vals in ((BODY, body or {}), (FACE, face or {})):
        for key, (up, dn) in tbl.items():
            v = vals.get(key)
            if v is None: continue
            d = (float(v) - 0.5) * 2.0
            if abs(d) < 0.02: continue
            for t, w in (up if d > 0 else dn):
                out[t] = out.get(t, 0.0) + w * abs(d)
    return [(t, round(min(1.0, v), 3)) for t, v in sorted(out.items()) if v > 0.004]

if __name__ == '__main__':
    import os
    names = all_targets()
    missing = [t for t in names if not os.path.exists('targets/' + t + '.target')]
    print('%d targets; %d missing' % (len(names), len(missing)))
    for m in missing: print('  MISSING', m)
