"""Cut garments along a smooth isoline instead of along face boundaries.

   Choosing whole faces is why every hem, neckline and cuff came out stepped:
   the edge of the garment could only ever follow the edges of the mesh, and
   at this resolution those are visible. Instead each garment is described by
   a scalar field — positive inside the cloth, negative outside — and every
   triangle the boundary crosses is split exactly where it crosses. The hem
   then follows the curve it was asked for rather than the nearest staircase
   of quads."""
import math, collections

def body_normals(B, FACES):
    n=collections.defaultdict(lambda:[0.0,0.0,0.0])
    for g,idx in FACES:
        if g!='body': continue
        a,b,c=B.v[idx[0]],B.v[idx[1]],B.v[idx[2]]
        u=[b[j]-a[j] for j in range(3)]; w=[c[j]-a[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for i in idx:
            for j in range(3): n[i][j]+=cx[j]
    out={}
    for i,q in n.items():
        L=math.sqrt(sum(x*x for x in q)) or 1.0
        out[i]=[x/L for x in q]
    return out

def _tris(FACES):
    for g,idx in FACES:
        if g!='body': continue
        for k in range(1,len(idx)-1):
            yield (idx[0], idx[k], idx[k+1])

def drape_base(B, FACES):
    """The surface a garment is cut from, with the skin's own detail taken
       out of it.

       A garment built as an offset of the body reproduces the body
       exactly, because that is what an offset is -- so the tee came out
       with nipples, a navel and a set of ribs printed through it, which
       reads as a wet shirt rather than as cotton. Cloth spans between
       the high points of a body; it does not sink into every dimple two
       vertices wide.

       Plain Laplacian, not Taubin. Taubin smooths without shrinking,
       which sounds like what is wanted, but its negative step overshoots
       wherever the curvature is extreme and the most extreme curvature
       on a body is the tip of a nipple: it came back as a spike through
       the cloth. Here the shrinking does no harm, because the garment is
       offset outward afterwards anyway."""
    if getattr(B, '_drapeV', None) is not None:
        return B._drapeV, B._drapeN
    adj = collections.defaultdict(set)
    for (i, j, k) in _tris(FACES):
        adj[i].update((j, k)); adj[j].update((i, k)); adj[k].update((i, j))
    P = [list(p) for p in B.v]
    for _ in range(4):
        Q = [list(p) for p in P]
        for i, nb in adj.items():
            if not nb: continue
            for c in range(3):
                av = sum(P[j][c] for j in nb) / len(nb)
                Q[i][c] = P[i][c] + (av - P[i][c]) * .55
        P = Q
    N = {}
    acc = [[0.0, 0.0, 0.0] for _ in P]
    for (i, j, k) in _tris(FACES):
        pa, pb, pc = P[i], P[j], P[k]
        u = [pb[t]-pa[t] for t in range(3)]; w = [pc[t]-pa[t] for t in range(3)]
        cx = [u[1]*w[2]-u[2]*w[1], u[2]*w[0]-u[0]*w[2], u[0]*w[1]-u[1]*w[0]]
        for t2 in (i, j, k):
            for c in range(3): acc[t2][c] += cx[c]
    for i, q in enumerate(acc):
        L = math.sqrt(sum(x*x for x in q)) or 1.0
        N[i] = [x / L for x in q]
    # and then the whole smoothed surface is pushed back out, by the most
    # any point of it sank. Smoothing a surface IS shrinking it -- that is
    # how the nipple goes away -- so the draped base ends up inside the
    # skin and a garment offset from it by a normal cloth thickness has
    # the body coming through at every place that sank further than that:
    # the shoulders, the nipples, the front of the thighs. Compensating
    # per vertex only moves the problem, because the shrink is largest
    # exactly where the feature was, so adding it back rebuilds it. One
    # constant for the whole surface puts the base outside the skin
    # everywhere and keeps it smooth.
    lift = 0.0
    for i, p0 in enumerate(B.v):
        nn = N[i]
        d = sum((p0[c] - P[i][c]) * nn[c] for c in range(3))
        if d > lift: lift = d
    lift = min(lift, 0.26)
    for i in range(len(P)):
        for c in range(3): P[i][c] += N[i][c] * lift
    B._drapeV = P; B._drapeN = N
    return P, N


def shell(B, FACES, field, thick, nrm=None, smooth=2, relax=0.0, drape=False):
    """field(point, index) -> signed distance, positive inside the garment."""
    if nrm is None: nrm = body_normals(B, FACES)
    # The FIELD is read from the real body -- the hem belongs where the
    # anatomy says it does -- but the cloth is built on the draped base.
    V = B.v
    if drape:
        V, nrm = drape_base(B, FACES)
    fv = {}
    def F(i):
        if i not in fv: fv[i] = field(B.v[i], i)
        return fv[i]

    P=[]; N=[]; T=[]; UV=[]
    base={}            # original vertex -> our index
    cut={}             # (i,j) edge -> our index, so neighbours share it

    # A garment gets its OWN UVs, wrapped round the figure. It had none at
    # all, which left every map on its material sampling a single texel;
    # and handing it the BODY's atlas, the obvious fix, is the wrong one,
    # because that atlas is cut and packed for a face. Its texel density
    # swings wildly, and a weave tiled through it bunches into a dense
    # swirl at every singularity -- one of them right on each nipple,
    # which reads as the shirt being moulded round them.
    _cz = getattr(B, 'sz', 0.0) or 0.0
    _bot = getattr(B, 'bot', 0.0) or 0.0
    _h = getattr(B, 'h', 1.0) or 1.0
    def _uv(p):
        a = math.atan2(p[0], p[2] - _cz)
        return [a / (2 * math.pi) + .5, (p[1] - _bot) / _h]

    def put_vertex(pos, nn):
        P.append(list(pos)); N.append(list(nn)); UV.append(_uv(pos)); return len(P)-1
    def at(i):
        if i not in base: base[i]=put_vertex(V[i], nrm.get(i,[0,1,0]))
        return base[i]
    def on_edge(i,j):
        key=(i,j) if i<j else (j,i)
        if key in cut: return cut[key]
        a,b=F(i),F(j)
        t = a/(a-b) if (a-b) else 0.5
        t = max(0.0, min(1.0, t))
        pi,pj = V[i], V[j]
        ni,nj = nrm.get(i,[0,1,0]), nrm.get(j,[0,1,0])
        pos=[pi[k]+(pj[k]-pi[k])*t for k in range(3)]
        nn =[ni[k]+(nj[k]-ni[k])*t for k in range(3)]
        L=math.sqrt(sum(x*x for x in nn)) or 1.0
        cut[key]=put_vertex(pos,[x/L for x in nn])
        return cut[key]

    for (i,j,k) in _tris(FACES):
        a,b,c = F(i),F(j),F(k)
        tri=(i,j,k)
        inside=[x for x,f in ((i,a),(j,b),(k,c)) if f>0]
        if len(inside)==3:
            T.append([at(i),at(j),at(k)])
        elif len(inside)==0:
            continue
        # Winding has to follow the ORIGINAL triangle's cycle, not the
        # order the inside vertices come out of the comprehension. Taking
        # them as (i,j,k) order reverses the piece whenever the odd vertex
        # is the middle one, so a third of the cut faces came out
        # inside-out -- and with DoubleSide they are still drawn, just lit
        # from behind. That is the alternating bright-and-dark zigzag that
        # ran along every hem. Not the boundary wandering, not z-fighting,
        # not the relax pass: backfaces.
        elif len(inside)==1:
            m=tri.index(inside[0])
            p=tri[m]; q=tri[(m+1)%3]; r=tri[(m+2)%3]
            T.append([at(p), on_edge(p,q), on_edge(p,r)])
        else:
            m=[x for x in range(3) if tri[x] not in inside][0]
            p=tri[m]; q=tri[(m+1)%3]; r=tri[(m+2)%3]
            eq,er = on_edge(p,q), on_edge(p,r)
            T.append([at(q), at(r), er]); T.append([at(q), er, eq])
    if not T: return None

    # push the shell off the skin
    for n2 in range(len(P)):
        t=thick(P[n2]) if callable(thick) else thick
        for j in range(3): P[n2][j]+=N[n2][j]*t

    # Smooth the hem along itself. The cut lands exactly on the isoline,
    # but consecutive crossings sit on edges running in alternating
    # directions, so the boundary zigzags even when it is in the right
    # place. A boundary vertex on a manifold has exactly two boundary
    # neighbours, so the hem is a closed loop and can be relaxed as a
    # curve of its own without touching the rest of the cloth.
    cnt={}
    for a,b,c in T:
        for e in ((a,b),(b,c),(c,a)):
            k2=(min(e),max(e)); cnt[k2]=cnt.get(k2,0)+1
    nb={}
    for (a,b),v in cnt.items():
        if v!=1: continue
        nb.setdefault(a,[]).append(b); nb.setdefault(b,[]).append(a)
    for _ in range(6):
        Q=[list(q) for q in P]
        for i2,ns in nb.items():
            if len(ns)!=2: continue
            for c in range(3):
                mid=(P[ns[0]][c]+P[ns[1]][c])*0.5
                Q[i2][c]=P[i2][c]+(mid-P[i2][c])*0.55
        P=Q

    if relax>0:
        adj=collections.defaultdict(set)
        for a,b,c in T:
            adj[a].update((b,c)); adj[b].update((a,c)); adj[c].update((a,b))
        for _ in range(smooth):
            Q=[list(p) for p in P]
            for i2,nb in adj.items():
                if not nb: continue
                av=[sum(P[j][k] for j in nb)/len(nb) for k in range(3)]
                for k in range(3): Q[i2][k]=P[i2][k]*(1-relax)+av[k]*relax
            P=Q

    NN=[[0.0,0.0,0.0] for _ in P]
    for a,b,c in T:
        pa,pb,pc=P[a],P[b],P[c]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t2 in (a,b,c):
            for j in range(3): NN[t2][j]+=cx[j]
    for q in NN:
        L=math.sqrt(sum(x*x for x in q)) or 1.0
        for j in range(3): q[j]/=L
    return {'position':[c for v in P for c in v],'normal':[c for v in NN for c in v],
            'uv':[c for q in UV for c in q],
            'index':[i for f in T for i in f],'n':len(P)}
