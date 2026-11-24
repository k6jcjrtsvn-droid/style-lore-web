"""Anatomy for the body mesh: where the arms are, where the legs are, where
   the neck is. Everything else — posing, clothing, shoes — is expressed in
   these terms rather than in raw coordinates, because raw coordinates move
   the moment a morph target is applied."""
import math

def _n(v):
    L=math.sqrt(sum(x*x for x in v)) or 1.0
    return [x/L for x in v]

# Landmarks are found ONCE, on the neutral mesh, and kept as VERTEX INDICES.
# Finding them again on each morphed body is what kept breaking: an extreme
# target moves the geometry enough that the armpit scan latches onto a hip or
# the fingertip search onto a knee, and then the arm axis points at the floor
# and the figure tears itself apart. The armpit is the same vertex on every
# body — only its position moves — so identify it once and look it up after.
_REF = None

def reference(verts, idxs):
    """Measure the neutral body once; everything else reads these indices."""
    global _REF
    if _REF is not None: return _REF
    B = Body(verts, idxs, _measure=True)
    def nearest(pt, pred=None):
        best=None; bd=1e18
        for i in idxs:
            p=verts[i]
            if pred and not pred(p): continue
            d=sum((p[j]-pt[j])**2 for j in range(3))
            if d<bd: bd=d; best=i
        return best
    _REF = {
        'shoulder': { s: nearest([s*B.sx, B.sy, B.sz], lambda p,s=s: p[0]*s>0) for s in (1,-1) },
        'tip':      { s: B.tipIdx[s] for s in B.tipIdx },
        'cls':      B.classify(),
        'neckFrac': (B.neckY-B.bot)/B.h,
    }
    return _REF


class Body:
    def __init__(self, verts, idxs, _measure=False):
        self.v=verts; self.idx=idxs
        B=[verts[i] for i in idxs]
        ys=[p[1] for p in B]
        self.top=max(ys); self.bot=min(ys); self.h=self.top-self.bot
        if not _measure and _REF is not None:
            # Read the landmarks off the reference vertices, wherever the
            # morph targets have moved them to.
            self.axis={}; self.tipIdx=dict(_REF['tip'])
            sp={s2: verts[_REF['shoulder'][s2]] for s2 in _REF['shoulder']}
            self.sx=sum(abs(p[0]) for p in sp.values())/len(sp)
            self.sy=sum(p[1] for p in sp.values())/len(sp)
            self.sz=sum(p[2] for p in sp.values())/len(sp)
            for s2,ti in _REF['tip'].items():
                S=[sp[s2][0], sp[s2][1], sp[s2][2]]
                t=verts[ti]
                d=[t[j]-S[j] for j in range(3)]
                L=math.sqrt(sum(x*x for x in d)) or 1.0
                self.axis[s2]=(S,[x/L for x in d],L)
            self.cls=dict(_REF['cls'])
            self.neckY=self.bot + _REF['neckFrac']*self.h
            self.neck=(0.0, self.sy + 0.30, self.sz + 0.12)
            return
        # the armpit: at shoulder height the arm is a separate cluster from
        # the torso, and the gap between them is the only landmark that
        # survives every morph target.
        # Scan for the armpit rather than assuming it sits at a fixed
        # fraction of height. A long torso puts the shoulder somewhere else
        # entirely, and a shirt cut at the wrong line loses a third of itself.
        best=(0.0, self.h*0.10, self.bot+self.h*0.84)
        for f in [0.80,0.81,0.82,0.83,0.84,0.85,0.86,0.87,0.88]:
            yy=self.bot+self.h*f
            sl=sorted(abs(p[0]) for p in B if abs(p[1]-yy)<0.22)
            if len(sl)<8: continue
            for i in range(1,len(sl)):
                g=sl[i]-sl[i-1]
                if g>best[0] and 0.6 < sl[i-1] < self.h*0.25:
                    best=(g,(sl[i-1]+sl[i])/2.0, yy)
        gap=(best[0],best[1]); y=best[2]
        self.sx=gap[1]; self.sy=y
        near=[p for p in B if abs(abs(p[0])-self.sx)<0.45 and abs(p[1]-y)<0.5]
        self.sz=sum(p[2] for p in near)/len(near) if near else 0.0
        # each arm's axis, shoulder to fingertip.
        # The fingertip is NOT simply the widest vertex: several body targets
        # push the hip or the shoulder wider than the hand, and then the arm
        # length comes out as 3.6 on one figure and 14.0 on the next, which
        # puts the sleeve cut somewhere around the knee. Take the farthest
        # vertex from the shoulder among those clearly outboard of the armpit.
        self.axis={}; self.tipIdx={}
        for side in (1,-1):
            S=[side*self.sx, self.sy, self.sz]
            tip=None; tipi=None; best=-1
            for i in idxs:
                p=verts[i]
                if p[0]*side <= 0: continue
                if abs(p[0]) < self.sx*1.25: continue
                if p[1] > self.sy + 0.5: continue
                # A knee is farther from the shoulder than a hand is, so the
                # search has to stay above the hip or the 'arm axis' ends up
                # pointing at the floor and nothing rotates at all.
                if self.frac(p) < 0.46: continue
                d2=sum((p[j]-S[j])**2 for j in range(3))
                if d2 > best: best=d2; tip=p; tipi=i
            if tip is None: continue
            self.tipIdx[side]=tipi
            d=[tip[j]-S[j] for j in range(3)]
            raw=math.sqrt(sum(x*x for x in d)) or 1.0
            d=[x/raw for x in d]              # normalise by its OWN length,
            L=raw                             # never by a substituted one
            # a human arm is between a quarter and a half of their height
            if not (0.22*self.h < L < 0.52*self.h): L=0.38*self.h
            self.axis[side]=(S,d,L)
        # The neck is the narrowest slice between the shoulders and the
        # crown — a real landmark, unlike "0.55 above the shoulder", which
        # lands in a different place on every body and cut a third of the
        # shirt off the tall ones.
        narrow=(1e9, self.sy+0.9)
        yy=self.sy+0.15
        # Only between the shoulder and the jaw. Scanning to the crown finds
        # the top of the skull, which is narrower than any neck, and then the
        # collar climbs over her mouth.
        while yy < self.sy + self.h*0.085:
            sl=[abs(p[0]) for p in B if abs(p[1]-yy)<0.12]
            if len(sl)>6:
                sl.sort(); w=sl[int(len(sl)*0.92)]
                if w < narrow[0]: narrow=(w, yy)
            yy += 0.12
        self.neckY=narrow[1]
        # Centre the collar scoop on the base of the neck, not on the
        # narrowest point higher up: a sphere centred above the cut line
        # takes a bite out of the top of the garment and leaves a V down
        # the sternum.
        self.neck=(0.0, self.sy + 0.30, self.sz + 0.12)

    def classify(self):
        """Work out which vertices are arm, and how far along, BEFORE anything
           is posed. The axis is measured on the rest pose, so asking after the
           arms have swung gives nonsense."""
        self.cls={}
        for i in self.idx:
            a=self.arm(self.v[i])
            if a: self.cls[i]=a
        return self.cls

    def limb(self, i):
        """Is this vertex on the arm proper? Posing needs a wide, soft notion
           of 'arm' so the deformation has no cliff in it; clothing needs a
           narrow, hard one, or a shirt rejects half the chest for being
           within two units of the arm's axis. Same joint, two questions."""
        a=self.cls.get(i)
        if not a: return None
        t,r,L=a
        if r > 1.05 or t < 0.30: return None
        return a

    def arm(self, p):
        """(t, r) along the arm this point belongs to, or None."""
        side = 1 if p[0] > 0 else -1
        a=self.axis.get(side)
        if not a: return None
        S,d,L=a
        rel=[p[j]-S[j] for j in range(3)]
        t=sum(rel[j]*d[j] for j in range(3))
        if t < -0.35 or t > L+1.2: return None
        perp=[rel[j]-t*d[j] for j in range(3)]
        r=math.sqrt(sum(x*x for x in perp))
        if r > 2.00: return None
        return (t, r, L)

    def neck_dist(self, p):
        n=self.neck
        return math.sqrt((p[0]-n[0])**2 + (p[1]-n[1])**2 + ((p[2]-n[2])*0.85)**2)

    def frac(self, p):
        """Height as a fraction of the figure: 0 at the soles, 1 at the crown."""
        return (p[1]-self.bot)/self.h

def arms_down(B, degrees=34.0):
    """Swing each arm about its own shoulder so it hangs at the side."""
    if not hasattr(B,'cls'): B.classify()
    moved=0
    for side,(S,d,L) in B.axis.items():
        a0=math.radians(degrees)*(-side)
        for i in B.idx:
            p=B.v[i]
            if p[0]*side<=0: continue
            a=B.cls.get(i)
            if not a: continue
            t,r,_=a
            # The weight has to fall off smoothly in BOTH directions. A hard
            # radius cut meant a vertex just inside the arm rotated the whole
            # way while its neighbour just outside did not move at all, and
            # the faces spanning that line stretched into a funnel that showed
            # through any clothing built on top. 49 stretched faces; zero on
            # the unposed body. Continuous fields, not cliffs.
            w=max(0.0,min(1.0,(t+0.35)/1.15)); w=w*w*(3-2*w)
            wr=max(0.0,min(1.0,(1.75-r)/0.75)); wr=wr*wr*(3-2*wr)
            w*=wr
            if w<=0.001: continue
            ang=a0*w
            rx=p[0]-S[0]; ry=p[1]-S[1]
            p[0]=S[0] + rx*math.cos(ang) - ry*math.sin(ang)
            p[1]=S[1] + rx*math.sin(ang) + ry*math.cos(ang)
            moved+=1
    return moved
