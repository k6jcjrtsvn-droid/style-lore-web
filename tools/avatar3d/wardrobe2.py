"""Clothes, described as fields rather than as lists of faces."""
import math, sys
sys.path.insert(0,'/home/claude/mh')
import cutter

def _limbY(B, frac_of_arm):
    armL = max(L for _,_,L in B.axis.values()) if B.axis else 1.0
    return B.sy - frac_of_arm*armL

# A neckline at .95 leaves a gap either side of the neck wide enough to
# see the unlit inside of the back of the shirt through: a pair of small
# black wedges that look exactly like holes in the mesh. A collar sits
# close to a neck.
TOPS = {
 'tee':        dict(hem=.515, neck=.76, sleeve=.30),
 'long-sleeve':dict(hem=.510, neck=.76, sleeve=.78),
 'tank':       dict(hem=.515, neck=.80, sleeve=.02, strap=True),
 'vneck':      dict(hem=.515, neck=1.30, sleeve=.30, vee=True),
 'crop':       dict(hem=.60, neck=.95, sleeve=.22),
}

def top(B, FACES, nrm, style='tee', thick=.085):
    o=TOPS.get(style, TOPS['tee'])
    armL = max(L for _,_,L in B.axis.values()) if B.axis else 1.0
    cuffT = o['sleeve'] * armL
    neckR=o['neck']
    def field(p, i):
        d=[]
        d.append((B.frac(p)-o['hem'])*B.h)            # the hem
        nd=B.neck_dist(p)
        if o.get('vee'):
            # a V drops at the centre front and rises at the sides
            nd = nd + max(0.0, 0.55-abs(p[0]))*(1.0 if p[2]>0 else 0.0)*1.1
        d.append(nd-neckR)                            # the neckline
        d.append(B.sy + 0.38 - p[1])                  # never the head
        # The cuff is cut ALONG THE ARM, not at a height. A horizontal
        # plane through an arm that hangs at an angle is an ellipse, and
        # what came out was a long diagonal tongue down the outside of
        # each sleeve. And the radius is its own: limb()'s 1.05 drops the
        # outer surface of a thick upper arm, which then skips the cuff
        # and is kept by the hem; the wide 2.00 reaches the ribs and cuts
        # a hole under the arm. 1.32 holds the whole arm and nothing else.
        a=B.cls.get(i)
        if a and a[1] <= 1.32 and a[0] >= 0.25:
            d.append(cuffT - a[0])                    # the cuff
            if o.get('strap'): d.append(-1.0)         # no sleeve at all
        return min(d)
    # Thicker than the bottoms, and not by accident: where a tee overlaps
    # a waistband the two shells are within a few thousandths of each
    # other, each one wins the depth test over half the pixels, and with
    # the picture supersampled the two colours average into what looks
    # like a transparent shirt. The top is simply further out than what
    # it is worn over.
    return cutter.shell(B, FACES, field, thick, nrm, relax=.22, smooth=2, drape=True)

BOTTOMS = {
 'leggings': dict(waist=.56, hem=.075),
 'trousers': dict(waist=.57, hem=.065, thick=.075),
 'shorts':   dict(waist=.56, hem=.38),
 'capri':    dict(waist=.56, hem=.20),
}

def bottoms(B, FACES, nrm, style='leggings'):
    o=BOTTOMS.get(style, BOTTOMS['leggings'])
    th=o.get('thick', .055)
    def field(p, i):
        if B.limb(i): return -1.0
        return min((B.frac(p)-o['hem'])*B.h, (o['waist']-B.frac(p))*B.h)
    return cutter.shell(B, FACES, field, th, nrm, relax=.22, smooth=2, drape=True)

SKIRTS = {
 'a-line': dict(length=.30, flare=.55, waist=.56),
 'pencil': dict(length=.26, flare=.10, waist=.57),
 'midi':   dict(length=.40, flare=.45, waist=.56),
 'maxi':   dict(length=.52, flare=.38, waist=.56),
 'mini':   dict(length=.17, flare=.50, waist=.56),
}

def skirt(B, FACES, nrm, style='a-line', rings=14, seg=56):
    o=SKIRTS.get(style, SKIRTS['a-line'])
    ring=[]
    for g,idx in FACES:
        if g!='body': continue
        n=len(idx)
        c=[sum(B.v[i][j] for i in idx)/n for j in range(3)]
        if abs(B.frac(c)-o['waist'])<0.010 and not any(B.limb(i) for i in idx):
            ring.append(c)
    if len(ring)<12: return None
    # resample the waist into an even loop so the skirt has no pinch in it
    cx=sum(p[0] for p in ring)/len(ring); cz=sum(p[2] for p in ring)/len(ring)
    cy=sum(p[1] for p in ring)/len(ring)
    byang={}
    for p in ring:
        a=math.atan2(p[2]-cz, p[0]-cx)
        k=int((a+math.pi)/(2*math.pi)*seg) % seg
        r=math.sqrt((p[0]-cx)**2+(p[2]-cz)**2)
        if k not in byang or r>byang[k][0]: byang[k]=(r,p)
    loop=[]
    for k in range(seg):
        if k in byang: loop.append(byang[k][1]); continue
        a=-math.pi+2*math.pi*(k+0.5)/seg
        rr=sum(v[0] for v in byang.values())/max(1,len(byang))
        loop.append([cx+math.cos(a)*rr, cy, cz+math.sin(a)*rr])
    P=[];T=[];prev=None
    for r in range(rings+1):
        u=r/rings
        y=cy - B.h*o['length']*u
        k=1.0 + o['flare']*(u**1.4)
        cur=[]
        for p in loop:
            cur.append(len(P)); P.append([cx+(p[0]-cx)*k, y, cz+(p[2]-cz)*k])
        if prev is not None:
            m=len(loop)
            for i in range(m):
                a=prev[i]; b=prev[(i+1)%m]; c=cur[i]; d=cur[(i+1)%m]
                T.append([a,b,c]); T.append([b,d,c])
        prev=cur
    return _finish(P,T)

def _finish(P,T):
    N=[[0.0,0.0,0.0] for _ in P]
    for a,b,c in T:
        pa,pb,pc=P[a],P[b],P[c]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t2 in (a,b,c):
            for j in range(3): N[t2][j]+=cx[j]
    for q in N:
        L=math.sqrt(sum(x*x for x in q)) or 1.0
        for j in range(3): q[j]/=L
    return {'position':[c for v in P for c in v],'normal':[c for v in N for c in v],
            'index':[i for f in T for i in f],'n':len(P)}
