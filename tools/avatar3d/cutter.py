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

def shell(B, FACES, field, thick, nrm=None, smooth=2, relax=0.0):
    """field(point, index) -> signed distance, positive inside the garment."""
    if nrm is None: nrm = body_normals(B, FACES)
    fv = {}
    def F(i):
        if i not in fv: fv[i] = field(B.v[i], i)
        return fv[i]

    P=[]; N=[]; T=[]
    base={}            # original vertex -> our index
    cut={}             # (i,j) edge -> our index, so neighbours share it

    def put_vertex(pos, nn):
        P.append(list(pos)); N.append(list(nn)); return len(P)-1
    def at(i):
        if i not in base: base[i]=put_vertex(B.v[i], nrm.get(i,[0,1,0]))
        return base[i]
    def on_edge(i,j):
        key=(i,j) if i<j else (j,i)
        if key in cut: return cut[key]
        a,b=F(i),F(j)
        t = a/(a-b) if (a-b) else 0.5
        t = max(0.0, min(1.0, t))
        pi,pj = B.v[i], B.v[j]
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
            'index':[i for f in T for i in f],'n':len(P)}
