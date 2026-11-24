"""Shoes.

   A shoe is a last, not a foot with a coat of paint: offsetting the foot
   surface keeps five separate toe lobes and smoothing only turns them into
   five bumps under the leather. So each shoe is built as a form fitted to
   that foot's own measurements — footprint, lofted up, toe box receding as
   it rises — and a heel lifts the foot by rotating it about the ball, the
   way a real one does, instead of hovering the shoe in the air."""
import math

STYLES = {
 'flat':      dict(shaft=.016, heel=.00,  toe=2.6, topline='low',   vamp=.010),
 'sneaker':   dict(shaft=.055, heel=.012, toe=2.2, topline='padded', sole=.030, vamp=.012),
 'ankle-boot':dict(shaft=.105, heel=.030, toe=2.8, topline='straight'),
 'knee-boot': dict(shaft=.300, heel=.034, toe=2.8, topline='straight'),
 'pump':      dict(shaft=.012, heel=.075, toe=3.4, topline='scoop', vamp=.008),
 'heeled-boot':dict(shaft=.135, heel=.070, toe=3.0, topline='straight'),
 'sandal':    dict(shaft=.010, heel=.020, toe=2.4, topline='strap', open=True, vamp=0),
 'barefoot':  None,
}

def lift_heel(B, degrees):
    """Rotate each foot about the ball so a heel is actually standing in it."""
    if degrees <= 0: return
    for side in (1,-1):
        pts=[(i,B.v[i]) for i in B.idx
             if B.frac(B.v[i]) < 0.085 and B.v[i][0]*side > 0 and not B.limb(i)]
        if len(pts)<20: continue
        zs=[p[2] for _,p in pts]; ys=[p[1] for _,p in pts]
        ball=[0.0, min(ys), min(zs)+ (max(zs)-min(zs))*0.74]
        ball[0]=sum(p[0] for _,p in pts)/len(pts)
        a=math.radians(degrees)
        for i,p in pts:
            dz=p[2]-ball[2]; dy=p[1]-ball[1]
            # heel goes up and slightly forward; the toe stays on the floor
            w=max(0.0, min(1.0, (ball[2]-p[2])/((ball[2]-min(zs)) or 1)))
            w=w*w*(3-2*w)
            ang=a*w
            p[1]=ball[1] + dy*math.cos(ang) - dz*math.sin(ang)*0 + (ball[2]-p[2])*math.sin(ang)*0
            p[1]+= (ball[2]-p[2])*math.sin(ang) if p[2] < ball[2] else 0.0

def build(B, style='flat', e=2.5, rings=14, seg=36):
    """A shoe is the foot's own form below the ankle and the leg's form above
       it. Lofting a cone from the sole to the shaft collapses the toe box at
       exactly the height the toes are at, which is how toes end up poking out
       through the front of a closed shoe; so the toe box keeps its full length
       up to the top of the forefoot, and only above that does the form leave
       the foot and follow the leg."""
    o=STYLES.get(style)
    if o is None: return None
    P=[];T=[]
    for side in (1,-1):
        pts=[B.v[i] for i in B.idx
             if B.frac(B.v[i]) < 0.10 and B.v[i][0]*side > 0 and not B.limb(i)]
        if len(pts)<20: continue
        leg=[B.v[i] for i in B.idx
             if B.frac(B.v[i]) < 0.46 and B.v[i][0]*side > 0]
        xs=[p[0] for p in pts]; ys=[p[1] for p in pts]; zs=[p[2] for p in pts]
        cx=(min(xs)+max(xs))/2.0
        ax=(max(xs)-min(xs))/2.0*1.10
        zf=max(zs); zb=min(zs); cz=(zf+zb)/2.0
        front0=(zf-cz)*1.16; back0=(cz-zb)*1.10
        sole=o.get('sole', .012)*B.h
        y0=min(ys)-sole
        heel=o['heel']*B.h
        # the top of the forefoot: everything below this has to stay foot-shaped
        zcut=cz+(zf-cz)*0.52
        fore=[p[1] for p in pts if p[2] > zcut]
        yTop=max(fore) if fore else (min(ys)+0.5*(max(ys)-min(ys)))
        y1=B.bot + B.h*o['shaft']
        if not o.get('open'):
            y1=max(y1, yTop + o.get('vamp', .010)*B.h)
        else:
            y1=max(y1, y0 + 0.018*B.h)

        band=0.035*B.h
        def leg_at(y):
            q=[p for p in leg if abs(p[1]-y) < band]
            if len(q) < 8: return None
            lx=[p[0] for p in q]; lz=[p[2] for p in q]
            return ((min(lx)+max(lx))/2.0, (max(lx)-min(lx))/2.0*1.14,
                    (min(lz)+max(lz))/2.0, (max(lz)-min(lz))/2.0*1.14)

        def section(y):
            """centre and half-extents of the shoe at height y."""
            fx, fax, fcz, ff, fb = cx, ax, cz, front0, back0
            if y <= yTop:
                # still the foot: hold the toe box, ease the sides in a little
                t=(y-y0)/((yTop-y0) or 1.0)
                k=1.0-0.07*t*t
                return fx, fax*k, fcz, ff*(1.0-0.04*t*t), fb*k
            # above the forefoot the form leaves the foot and takes the leg
            t=min(1.0, (y-yTop)/(0.085*B.h))
            t=t*t*(3-2*t)
            L=leg_at(y)
            if L is None:
                L=(cx, ax*0.52, cz-(cz-zb)*0.18, max(front0,back0)*0.52)
                lx, lax, lcz, lr = L; lf=lr; lb=lr
            else:
                lx, lax, lcz, lr = L; lf=lr; lb=lr
            pad=1.0 + (0.05 if o['shaft'] > 0.09 else 0.02)
            return (fx+(lx-fx)*t, fax+(lax*pad-fax)*t, fcz+(lcz-fcz)*t,
                    ff+(lf*pad-ff)*t, fb+(lb*pad-fb)*t)

        start=len(P); prev=None
        for r in range(rings+1):
            u=r/rings
            y=y0+(y1-y0)*u
            sx, sax, scz, sf, sb = section(y)
            ring=[]
            ex = (e*1.30) if y <= yTop else e*0.80
            for s2 in range(seg):
                th=2.0*math.pi*s2/seg
                nx,nz=math.cos(th),math.sin(th)
                px=sx+sax*math.copysign(abs(nx)**(2.0/ex), nx)
                pz=scz+(sf if nz>=0 else sb)*math.copysign(abs(nz)**(2.0/ex), nz)
                py=y
                if heel>0 and y < y0+(y1-y0)*0.14 and pz<scz:
                    py += heel*min(1.0,(scz-pz)/((scz-zb) or 1))**1.3
                ring.append(len(P)); P.append([px,py,pz])
            if prev is not None:
                for s2 in range(seg):
                    a=prev[s2]; b=prev[(s2+1)%seg]; c=ring[s2]; d=ring[(s2+1)%seg]
                    T.append([a,c,b]); T.append([b,c,d])
            prev=ring
        # sole underneath, collar on top (the leg passes through the collar)
        first=list(range(start, start+seg))
        sx0, _, scz0, _, _ = section(y0)
        sx1, _, scz1, _, _ = section(y1)
        for (yy, ring, flip, qx, qz) in ((y0, first, True, sx0, scz0),
                                         (y1, prev,  False, sx1, scz1)):
            ci=len(P); P.append([qx, yy, qz])
            for s2 in range(seg):
                a=ring[s2]; b=ring[(s2+1)%seg]
                T.append([ci,b,a] if flip else [ci,a,b])
        # a block heel under the back of the sole
        if heel > 0.004*B.h:
            hz0=zb-(cz-zb)*0.06; hz1=cz-(cz-zb)*0.34
            hx0=ax*0.60
            corners=[(cx-hx0, hz0),(cx+hx0, hz0),(cx+hx0*0.92, hz1),(cx-hx0*0.92, hz1)]
            topi=[]; boti=[]
            for (qx,qz) in corners:
                topi.append(len(P)); P.append([qx, y0+heel*0.98, qz])
            for (qx,qz) in corners:
                boti.append(len(P)); P.append([cx+(qx-cx)*0.92, y0-heel*0.02,
                                              cz+(qz-cz)*0.96])
            for s2 in range(4):
                a=topi[s2]; b=topi[(s2+1)%4]; c=boti[s2]; d=boti[(s2+1)%4]
                T.append([a,c,b]); T.append([b,c,d])
            T.append([boti[0],boti[2],boti[1]]); T.append([boti[0],boti[3],boti[2]])
    if not P: return None
    N=[[0.0,0.0,0.0] for _ in P]
    for a,b,c in T:
        pa,pb,pc=P[a],P[b],P[c]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cxn=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t2 in (a,b,c):
            for j in range(3): N[t2][j]+=cxn[j]
    for q in N:
        L=math.sqrt(sum(x*x for x in q)) or 1.0
        for j in range(3): q[j]/=L
    return {'position':[c for v in P for c in v],'normal':[c for v in N for c in v],
            'index':[i for f in T for i in f],'n':len(P)}
