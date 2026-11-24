"""Clothes, grown off the body.

   A fitted garment is the body's own surface held a few millimetres off the
   skin, so that is literally how these are built: take the body's faces,
   choose the ones the garment covers, push them out along their normals.
   The topology never changes, which means a garment costs a list of face
   indices to store and fits every body shape automatically — a wide hip
   pushes the skirt out because the skirt IS the hip, moved outward."""
import math, collections

def shell(B, FACES, pick, thick, soften=0.0):
    """pick(centroid, faceVerts) -> bool.  thick(point) -> metres off the skin."""
    keep=[]
    for g,idx in FACES:
        if g!='body': continue
        n=len(idx)
        c=[sum(B.v[i][j] for i in idx)/n for j in range(3)]
        if pick(c, idx): keep.append(idx)
    if not keep: return None
    nrm=collections.defaultdict(lambda:[0.0,0.0,0.0])
    for idx in keep:
        a,b,c3=B.v[idx[0]],B.v[idx[1]],B.v[idx[2]]
        u=[b[j]-a[j] for j in range(3)]; w=[c3[j]-a[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for i in idx:
            for j in range(3): nrm[i][j]+=cx[j]
    # Smooth the normal field before offsetting. The body has deep creases —
    # under the bust, the groin, between the toes — and pushing a surface out
    # along its raw normals makes the two walls of a crease cross over each
    # other: inverted faces, shards of cloth sticking through, skin showing
    # between them. 11% of the shirt's vertices were inside out. Cloth spans
    # a fold rather than diving into it, so the normals must too.
    adjn=collections.defaultdict(set)
    for idx in keep:
        for a2 in idx:
            for b2 in idx:
                if a2!=b2: adjn[a2].add(b2)
    for _ in range(4):
        nxt={}
        for i,nb in adjn.items():
            if not nb: continue
            av=[sum(nrm[j][k] for j in nb)/len(nb) for k in range(3)]
            nxt[i]=[nrm[i][k]*0.35 + av[k]*0.65 for k in range(3)]
        for i,q in nxt.items(): nrm[i]=q
    m={}; P=[]; T=[]
    for idx in keep:
        f=[]
        for i in idx:
            if i not in m:
                nn=nrm[i]; L=math.sqrt(sum(x*x for x in nn)) or 1.0
                t=thick(B.v[i])
                m[i]=len(P); P.append([B.v[i][j]+nn[j]/L*t for j in range(3)])
            f.append(m[i])
        for k in range(1,len(f)-1): T.append([f[0],f[k],f[k+1]])
    if soften>0:               # one Laplacian pass: cloth does not have creases
        adj=collections.defaultdict(set)
        for a,b,c3 in T:
            adj[a].update((b,c3)); adj[b].update((a,c3)); adj[c3].update((a,b))
        Q=[list(p) for p in P]
        for i,nb in adj.items():
            if not nb: continue
            av=[sum(P[j][k] for j in nb)/len(nb) for k in range(3)]
            for k in range(3): Q[i][k]=P[i][k]*(1-soften)+av[k]*soften
        P=Q
    # Backstop: a face far longer than its peers is a bridge across something
    # that moved, not cloth. There should be none of these now, but a garment
    # with one is unmistakable and a check is cheap.
    def edge(a,b):
        return math.sqrt(sum((P[a][j]-P[b][j])**2 for j in range(3)))
    lens=sorted(max(edge(a,b),edge(b,c3),edge(c3,a)) for a,b,c3 in T)
    if lens:
        cap=lens[len(lens)//2]*4.0
        T=[f for f in T if max(edge(f[0],f[1]),edge(f[1],f[2]),edge(f[2],f[0])) <= cap]
    N=[[0.0,0.0,0.0] for _ in P]
    for a,b,c3 in T:
        pa,pb,pc=P[a],P[b],P[c3]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t2 in (a,b,c3):
            for j in range(3): N[t2][j]+=cx[j]
    for q in N:
        L=math.sqrt(sum(x*x for x in q)) or 1.0
        for j in range(3): q[j]/=L
    return {'position':[c for v in P for c in v],'normal':[c for v in N for c in v],
            'index':[i for f in T for i in f],'n':len(P)}

def top(B, FACES, hem=0.47, neck=0.92, sleeve=0.30, thick=0.135):
    """Chosen by what it covers, not by what it avoids. Selecting the torso and
       then subtracting 'anything the arm rotation touched' is what tore a hole
       across the collarbone: those two sets overlap at the shoulder, which is
       exactly where a shirt must not have a hole.

       EVERY vertex of a face has to qualify, not just the first. A face with
       one vertex on the chest and the rest on the arm passed that test, came
       along with its arm vertices — which the pose had swung half a metre
       away — and rendered as a flap of cloth stretched across the bust."""
    armL = max(L for _,_,L in B.axis.values()) if B.axis else 1.0
    # Cut the sleeve in POSED space. `t` is measured along the arm as it lay
    # in the rest pose, pointing down and forward at fifty degrees; once the
    # arm swings to the side, a surface of constant t is no longer a ring
    # round the arm but a diagonal slash across the chest — which is exactly
    # the V that kept opening up. The posed arm hangs vertically, so the cuff
    # is simply a height.
    cuffY = B.sy - max(sleeve, 0.10)*armL
    def pick(c, idx):
        # Judge the face by its centroid. Requiring every vertex to qualify
        # sounds safer and is not: a face bridging the chest and the sleeve
        # has vertices on both sides of every boundary, so it gets dropped,
        # and the garment ends up with a notch at exactly the seam a real
        # shirt is made of. The stretched-face problem that rule was guarding
        # against came from a discontinuity in the pose, and that is fixed at
        # source; shell() still drops any outlier as a backstop.
        if B.frac(c) < hem: return False
        if B.neck_dist(c) < neck: return False
        if c[1] > B.sy + 0.35: return False   # the base of the neck, no higher
        for i in (idx[0], idx[1], idx[-1]):
            if B.limb(i) and B.v[i][1] < cuffY: return False
        return True

    return shell(B, FACES, pick, lambda p: thick, soften=0.30)

def bottoms(B, FACES, waist=0.56, hem=0.055, thick=0.085):
    def pick(c, idx):
        if B.limb(idx[0]): return False              # never the arms
        f=B.frac(c)
        return hem < f < waist
    return shell(B, FACES, pick, lambda p: thick, soften=0.3)

def shoes(B, FACES, ankle=0.075, thick=0.085):
    """A shoe is not a foot with a coat of paint. Offsetting the foot leaves
       five separate toes sticking out of the end. Smoothing hard melts them
       into one rounded form, which is what a shoe looks like."""
    def pick(c, idx):
        for i in idx:
            if B.limb(i): return False
            if B.frac(B.v[i]) > ankle+0.02: return False
        return True
    sh = shell(B, FACES, pick, lambda p: thick + 0.055*max(0.0, 1.0-B.frac(p)/ankle),
               soften=0.85)
    if sh: sh = _smooth(sh, 0.8, 3)
    return sh

def _smooth(mesh, amount, passes):
    import collections
    P=[[mesh['position'][i*3+k] for k in range(3)] for i in range(mesh['n'])]
    I=mesh['index']
    adj=collections.defaultdict(set)
    for t in range(0,len(I),3):
        a,b,c=I[t],I[t+1],I[t+2]
        adj[a].update((b,c)); adj[b].update((a,c)); adj[c].update((a,b))
    for _ in range(passes):
        Q=[list(p) for p in P]
        for i,nb in adj.items():
            if not nb: continue
            av=[sum(P[j][k] for j in nb)/len(nb) for k in range(3)]
            for k in range(3): Q[i][k]=P[i][k]*(1-amount)+av[k]*amount
        P=Q
    N=[[0.0,0.0,0.0] for _ in P]
    for t in range(0,len(I),3):
        a,b,c=I[t],I[t+1],I[t+2]
        pa,pb,pc=P[a],P[b],P[c]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t2 in (a,b,c):
            for j in range(3): N[t2][j]+=cx[j]
    for q in N:
        L=math.sqrt(sum(x*x for x in q)) or 1.0
        for j in range(3): q[j]/=L
    mesh['position']=[c for v in P for c in v]
    mesh['normal']=[c for v in N for c in v]
    return mesh

def skirt(B, FACES, waist=0.56, length=0.30, flare=0.55, rings=9):
    """A skirt is not a surface offset — it hangs away from the leg. Take the
       ring of the body at the waist and drop it, widening as it falls."""
    ring=[]
    for g,idx in FACES:
        if g!='body': continue
        n=len(idx)
        c=[sum(B.v[i][j] for i in idx)/n for j in range(3)]
        if abs(B.frac(c)-waist) < 0.012 and not any(B.limb(i) for i in idx):
            ring.append(c)
    if len(ring) < 12: return None
    ring.sort(key=lambda p: math.atan2(p[2], p[0]))
    # thin it to an even loop
    step=max(1, len(ring)//48)
    ring=ring[::step]
    P=[]; T=[]; prev=None
    for r in range(rings+1):
        u=r/rings
        y=ring[0][1] - B.h*length*u
        k=1.0 + flare*(u**1.35)
        cur=[]
        for p in ring:
            cur.append(len(P)); P.append([p[0]*k, y, p[2]*k])
        if prev is not None:
            m=len(ring)
            for i in range(m):
                a=prev[i]; b=prev[(i+1)%m]; c=cur[i]; d=cur[(i+1)%m]
                T.append([a,b,c]); T.append([b,d,c])
        prev=cur
    N=[[0.0,0.0,0.0] for _ in P]
    for a,b,c3 in T:
        pa,pb,pc=P[a],P[b],P[c3]
        uu=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[uu[1]*w[2]-uu[2]*w[1],uu[2]*w[0]-uu[0]*w[2],uu[0]*w[1]-uu[1]*w[0]]
        for t2 in (a,b,c3):
            for j in range(3): N[t2][j]+=cx[j]
    for q in N:
        L=math.sqrt(sum(x*x for x in q)) or 1.0
        for j in range(3): q[j]/=L
    return {'position':[c for v in P for c in v],'normal':[c for v in N for c in v],
            'index':[i for f in T for i in f],'n':len(P)}

def boots(B, FACES, ankle=0.072, rise=0.016, e=2.6, rings=9, seg=28):
    """A shoe is a last, not a foot with a coat of paint.

       Offsetting the foot surface keeps five separate toe lobes and smoothing
       only turns them into five bumps under the leather. Real shoes are built
       on a form: a footprint, lofted upward, with the toe box receding as it
       rises. So build that, fitted to this foot's own measurements."""
    out={}
    for side in (1,-1):
        pts=[B.v[i] for i in B.idx
             if B.frac(B.v[i]) < ankle and (B.v[i][0]*side) > 0 and not B.limb(i)]
        if len(pts) < 20: continue
        xs=[p[0] for p in pts]; ys=[p[1] for p in pts]; zs=[p[2] for p in pts]
        cx=(min(xs)+max(xs))/2.0
        ax=(max(xs)-min(xs))/2.0 * 1.18
        zf=max(zs); zb=min(zs); cz=(zf+zb)/2.0
        y0=min(ys)-0.02; y1=B.bot + B.h*(ankle+rise)
        P=[]; T=[]; prev=None
        for r in range(rings+1):
            u=r/rings
            hx = ax*(1.0 - 0.14*u)
            front = (zf-cz)*(1.0 - 0.42*u) * 1.22
            back  = (zb-cz)*(1.0 - 0.04*u) * 1.16
            y = y0 + (y1-y0)*u
            ring=[]
            for s in range(seg):
                th = 2.0*math.pi*s/seg
                nx, nz = math.cos(th), math.sin(th)
                px = cx + hx*math.copysign(abs(nx)**(2.0/e), nx)
                pz = cz + (front if nz>=0 else -back)*math.copysign(abs(nz)**(2.0/e), nz)
                ring.append(len(P)); P.append([px, y, pz])
            if prev is not None:
                for s in range(seg):
                    a=prev[s]; b=prev[(s+1)%seg]; c=ring[s]; d=ring[(s+1)%seg]
                    T.append([a,c,b]); T.append([b,c,d])
            prev=ring
        # sole and collar
        for y,ring,flip in ((y0,list(range(0,seg)),True),(y1,prev,False)):
            ci=len(P); P.append([cx, y, cz])
            for s in range(seg):
                a=ring[s]; b=ring[(s+1)%seg]
                T.append([ci,b,a] if flip else [ci,a,b])
        N=[[0.0,0.0,0.0] for _ in P]
        for a,b,c in T:
            pa,pb,pc=P[a],P[b],P[c]
            u1=[pb[j]-pa[j] for j in range(3)]; w1=[pc[j]-pa[j] for j in range(3)]
            cxn=[u1[1]*w1[2]-u1[2]*w1[1],u1[2]*w1[0]-u1[0]*w1[2],u1[0]*w1[1]-u1[1]*w1[0]]
            for t2 in (a,b,c):
                for j in range(3): N[t2][j]+=cxn[j]
        for q in N:
            L=math.sqrt(sum(x*x for x in q)) or 1.0
            for j in range(3): q[j]/=L
        out[side]={'position':[c for v in P for c in v],
                   'normal':[c for v in N for c in v],
                   'index':[i for f in T for i in f],'n':len(P)}
    if not out: return None
    # one mesh, both feet
    P=[]; N=[]; I=[]
    for m in out.values():
        off=len(P)//3
        P+=m['position']; N+=m['normal']; I+=[i+off for i in m['index']]
    return {'position':P,'normal':N,'index':I,'n':len(P)//3}
