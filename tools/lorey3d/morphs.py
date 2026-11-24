import math

def jaw_rotation(pos, jaw, mouthY, chinY, amount=1.0):
    """Swing the chin down and back about the hinge below the ear."""
    jx,jy,jz = jaw
    out=[]
    for i,p in enumerate(pos):
        top = max(0.0, min(1.0, (mouthY + 0.02 - p[1]) / 0.20))
        bot = max(0.0, min(1.0, (p[1] - (chinY - 0.34)) / 0.30))
        w = top*bot
        w = w*w*(3-2*w)
        back = max(0.0, min(1.0, (p[2] - jz) / 0.45))
        w *= back
        if w <= 0.001: continue
        a = 0.26*amount*w
        dy = p[1]-jy; dz = p[2]-jz
        ny = jy + dy*math.cos(a) - dz*math.sin(a)
        nz = jz + dy*math.sin(a) + dz*math.cos(a)
        out.append((i, 0.0, ny-p[1], nz-p[2]))
    return out

def blink(pos, nrm, eye, side, amount=1.0):
    """Sweep the upper lid down to the lower rim."""
    cx,cy,cz = eye['c']; r = eye['r']
    out=[]
    for i,p in enumerate(pos):
        dx = p[0]-cx; dy = p[1]-cy; dz = p[2]-cz
        d  = math.sqrt(dx*dx + dy*dy*1.25 + dz*dz*0.55)
        if d > r*2.5: continue
        if dy < -r*0.12: continue             # lower lid stays put
        w = max(0.0, min(1.0, 1.0 - (d - r*0.5)/(r*1.9)))
        w = w*w*(3-2*w)
        if w <= 0.002: continue
        drop = (dy + r*0.12) * 1.02 * amount * w
        out.append((i, 0.0, -drop, -drop*0.22*amount*w))
    return out

def from_target(tgt, idxmap, scale=1.0):
    out=[]
    for i,dx,dy,dz in tgt:
        j = idxmap.get(i)
        if j is None: continue
        out.append((j, dx*scale, dy*scale, dz*scale))
    return out

def pack(sparse):
    return {'i':[s[0] for s in sparse],
            'd':[round(c,5) for s in sparse for c in (s[1],s[2],s[3])]}

def lips_part(pos, lipIdx, idxmap, mouthY, amount=1.0):
    """Open a gap between the lips: the inner rim moves most, the corners not at all."""
    pts=[(idxmap[i], pos[idxmap[i]]) for i in lipIdx if i in idxmap]
    if not pts: return []
    xs=[abs(p[0]) for _,p in pts]; xmax=max(xs) or 1
    sig=0.085
    out=[]
    for j,p in pts:
        s = p[1]-mouthY
        g = math.exp(-(s/sig)**2)                 # strongest at the lip line
        corner = 1.0 - min(1.0,(abs(p[0])/xmax)**1.6)
        w = g*corner
        if w < 0.01: continue
        dirn = -1.0 if s < 0 else 0.40            # the lower lip does most of the work
        # the inner rim rolls back into the mouth instead of pouting out
        back = -0.062 if s < 0 else -0.022
        out.append((j, 0.0, dirn*0.082*amount*w, back*amount*w))
    return out
