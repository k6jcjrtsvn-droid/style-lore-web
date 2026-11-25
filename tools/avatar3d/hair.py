import math, json

def build_hair(pos, tris, N, eye, opts):
    ys=[p[1] for p in pos]; yTop=max(ys)
    face=[p for p in pos if p[1]>yTop-2.4]
    zMin=min(p[2] for p in face); zMax=max(p[2] for p in face)
    xMax=max(abs(p[0]) for p in face) or 1
    eyeY=eye['c'][1]
    front=opts.get('front',0.80); lift=opts.get('lift',0.0)

    def hairline(p):
        zf=max(0.0,min(1.0,(p[2]-zMin)/((zMax-zMin) or 1)))
        return eyeY + front*(zf**1.5) - 0.06 + lift - 0.20*(abs(p[0])/xMax)

    # A hairline is not a drawn curve. Cutting the cap on a smooth
    # function of position gives one, and a smooth dark edge across the
    # forehead is what makes a head of hair look like a swimming cap. The
    # threshold is jittered per vertex instead, by about one ring of the
    # mesh, so the cap ends raggedly -- and because the cards are grown
    # from the cap, the hair itself ends raggedly with it.
    #
    # The jitter is hashed from the vertex INDEX, not its position: the
    # index is the same integer in the build and in the browser, where a
    # position is the same float only as long as every morph before it
    # agrees to the last bit.
    ragged = o.get('ragged', 0.13)
    def _jit(i):
        h = ((i ^ 0x9e3779b9) * 2246822507) & 0xFFFFFFFF
        h = ((h ^ (h >> 13)) * 3266489909) & 0xFFFFFFFF
        h = (h ^ (h >> 16)) & 0xFFFFFFFF
        return (h / 4294967296.0 - .5) * ragged
    sc=[p[1] > hairline(p) + _jit(i) for i, p in enumerate(pos)]
    cap=[t for t in tris if sc[t[0]] and sc[t[1]] and sc[t[2]]]
    if not cap: return None

    m={}; P=[]; T=[]
    for t in cap:
        f=[]
        for i in t:
            if i not in m:
                m[i]=len(P)
                h=max(0.0,min(1.0,(pos[i][1]-hairline(pos[i]))/0.55))
                th=opts.get('thick',0.05)+opts.get('puff',0.075)*h
                P.append([pos[i][k]+N[i][k]*th for k in range(3)])
            f.append(m[i])
        T.append(f)

    # boundary edge ring of the cap
    cnt={}
    for a,b,c in T:
        for e in ((a,b),(b,c),(c,a)):
            k=(min(e),max(e)); cnt[k]=cnt.get(k,0)+1
    bedge=[k for k,v in cnt.items() if v==1]
    nxt={}
    for a,b in bedge: nxt.setdefault(a,[]).append(b); nxt.setdefault(b,[]).append(a)

    # walk loops
    loops=[]; seen=set()
    for s in nxt:
        if s in seen: continue
        loop=[s]; seen.add(s); cur=s; prev=None
        while True:
            nb=[q for q in nxt[cur] if q!=prev and q not in seen]
            if not nb: break
            prev=cur; cur=nb[0]; seen.add(cur); loop.append(cur)
        if len(loop)>8: loops.append(loop)
    loops.sort(key=len, reverse=True)

    RINGS=opts.get('rings',7); LEN=opts.get('length',1.5)
    for loop in loops[:1]:
        prev_ring=loop
        base=[P[i][:] for i in loop]
        for r in range(1,RINGS+1):
            u=r/RINGS
            ring=[]
            for bi,i in enumerate(loop):
                p0=base[bi]
                zf=max(0.0,min(1.0,(p0[2]-zMin)/((zMax-zMin) or 1)))
                # no fall over the forehead; full fall at the sides and back
                t0=max(0.0,min(1.0,(0.72-zf)/0.26)); amt=t0*t0*(3-2*t0)
                L=LEN*amt
                flare=math.sin(u*2.1)*0.085*amt
                curl=(u**2)*0.10*amt
                x=p0[0]*(1+flare*0.40)
                y=p0[1]-L*u
                z=p0[2]+flare*(0.5 if p0[2]<0 else -0.35)-curl*(1 if p0[2]<0 else -1)*0.5
                if amt<0.02: x,y,z=p0
                ring.append(len(P)); P.append([x,y,z])
            for k in range(len(loop)):
                a=prev_ring[k]; b=prev_ring[(k+1)%len(loop)]
                c=ring[k];      d=ring[(k+1)%len(loop)]
                T.append([a,b,c]); T.append([b,d,c])
            prev_ring=ring

    NN=[[0.0,0.0,0.0] for _ in P]
    for a,b,c in T:
        pa,pb,pc=P[a],P[b],P[c]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t in (a,b,c):
            for j in range(3): NN[t][j]+=cx[j]
    for q in NN:
        L=math.sqrt(sum(x*x for x in q)) or 1
        for j in range(3): q[j]/=L
    return {'position':[c for v in P for c in v],'normal':[c for v in NN for c in v],
            'index':[i for f in T for i in f],'n':len(P)}

def hair_for_body(pos, tris, N, headTopY, eyeY, opts):
    """The scalp of a full figure. Same growth as Lorey's, but the hairline
       has to be found from the head's own proportions rather than handed in,
       because every body in the app is a different height."""
    return build_hair(pos, tris, N, {'c':[0.0, eyeY, 0.0], 'r':0.15}, opts)


def scalp(pos, tris, N, eye, opts):
    """The cap the hair grows out of, on its own.

       build_hair() cuts this same region and then lofts a solid skirt off
       its boundary, which is what makes a helmet. Cards need the cap but
       not the skirt, so the cut is available separately."""
    o = dict(opts or {})
    ys=[p[1] for p in pos]; yTop=max(ys)
    face=[p for p in pos if p[1]>yTop-2.4]
    if not face: return None
    zMin=min(p[2] for p in face); zMax=max(p[2] for p in face)
    xMax=max(abs(p[0]) for p in face) or 1
    eyeY=eye['c'][1]
    front=o.get('front',0.80); lift=o.get('lift',0.0)
    def hairline(p):
        zf=max(0.0,min(1.0,(p[2]-zMin)/((zMax-zMin) or 1)))
        return eyeY + front*(zf**1.5) - 0.06 + lift - 0.20*(abs(p[0])/xMax)
    # A hairline is not a drawn curve. Cutting the cap on a smooth
    # function of position gives one, and a smooth dark edge across the
    # forehead is what makes a head of hair look like a swimming cap. The
    # threshold is jittered per vertex instead, by about one ring of the
    # mesh, so the cap ends raggedly -- and because the cards are grown
    # from the cap, the hair itself ends raggedly with it.
    #
    # The jitter is hashed from the vertex INDEX, not its position: the
    # index is the same integer in the build and in the browser, where a
    # position is the same float only as long as every morph before it
    # agrees to the last bit.
    ragged = o.get('ragged', 0.13)
    def _jit(i):
        h = ((i ^ 0x9e3779b9) * 2246822507) & 0xFFFFFFFF
        h = ((h ^ (h >> 13)) * 3266489909) & 0xFFFFFFFF
        h = (h ^ (h >> 16)) & 0xFFFFFFFF
        return (h / 4294967296.0 - .5) * ragged
    sc=[p[1] > hairline(p) + _jit(i) for i, p in enumerate(pos)]
    cap=[t for t in tris if sc[t[0]] and sc[t[1]] and sc[t[2]]]
    if not cap: return None
    m={}; P=[]; NN=[]; T=[]
    thick=o.get('thick',0.045)
    for t in cap:
        f=[]
        for i in t:
            if i not in m:
                m[i]=len(P)
                P.append([pos[i][k]+N[i][k]*thick for k in range(3)])
                NN.append(list(N[i]))
            f.append(m[i])
        T.append(f)
    return {'pos':P, 'nrm':NN, 'tris':T}
