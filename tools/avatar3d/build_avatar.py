import json, math, os, sys, collections
sys.path.insert(0, '/home/claude/mh')
import anat, wardrobe, wardrobe2, footwear, cutter, hair as HAIR, hairgen

RAW=[]; FACES=[]; UVRAW=[]; UVOF={}; cur=None
for line in open('base.obj'):
    if line.startswith('v '):
        p=line.split(); RAW.append([float(p[1]),float(p[2]),float(p[3])])
    elif line.startswith('vt '):
        p=line.split(); UVRAW.append([float(p[1]),float(p[2])])
    elif line.startswith('g '): cur=line[2:].strip()
    elif line.startswith('f '):
        toks=line.split()[1:]
        FACES.append((cur,[int(t.split('/')[0])-1 for t in toks]))
        # The base mesh ships a full UV layout and this parser was dropping
        # it on the floor. Everything that makes a face read as a face --
        # brows, lashes, lips -- has to be painted into that layout.
        for t in toks:
            a=t.split('/')
            if len(a)>1 and a[1]:
                UVOF.setdefault(int(a[0])-1, int(a[1])-1)
BODYIDX=sorted({i for g,idx in FACES if g=='body' for i in idx})

C={}
def T(p):
    if p not in C:
        out=[]; fn='targets/'+p+'.target'
        if os.path.exists(fn):
            for line in open(fn):
                line=line.strip()
                if not line or line[0]=='#': continue
                q=line.split(); out.append((int(q[0]),float(q[1]),float(q[2]),float(q[3])))
        else: print('   (missing target', p, ')')
        C[p]=out
    return C[p]

def apply(v, mix):
    for path,w in mix:
        if w==0: continue
        for i,dx,dy,dz in T(path):
            if i<len(v): v[i][0]+=dx*w; v[i][1]+=dy*w; v[i][2]+=dz*w

def mask(paths):
    m={}
    for p in paths:
        for i,dx,dy,dz in T(p):
            d=math.sqrt(dx*dx+dy*dy+dz*dz)
            if d>m.get(i,0): m[i]=d
    if not m: return {}
    mx=max(m.values()) or 1
    return {i:min(1.0,x/mx) for i,x in m.items()}

LIPS  = mask(['mouth/mouth-upperlip-volume-incr','mouth/mouth-lowerlip-volume-incr',
              'mouth/mouth-upperlip-height-incr','mouth/mouth-lowerlip-height-incr'])
LIDS  = mask(['eyes/l-eye-height3-incr','eyes/r-eye-height3-incr',
              'eyes/l-eye-height1-incr','eyes/r-eye-height1-incr'])
CHEEK = mask(['cheek/l-cheek-bones-incr','cheek/r-cheek-bones-incr'])

def part(verts, groups):
    m={}; P=[]; TT=[]; src=[]
    for g,idx in FACES:
        if g not in groups: continue
        f=[]
        for i in idx:
            if i not in m: m[i]=len(P); P.append(list(verts[i])); src.append(i)
            f.append(m[i])
        for k in range(1,len(f)-1): TT.append([f[0],f[k],f[k+1]])
    return P,TT,src

def normals(P,TT):
    N=[[0.0,0.0,0.0] for _ in P]; adj=[[] for _ in P]
    for a,b,c in TT:
        pa,pb,pc=P[a],P[b],P[c]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t in (a,b,c):
            for j in range(3): N[t][j]+=cx[j]
        adj[a]+=[b,c]; adj[b]+=[a,c]; adj[c]+=[a,b]
    for q in N:
        L=math.sqrt(sum(x*x for x in q)) or 1
        for j in range(3): q[j]/=L
    return N,adj

def s2l(u):
    u=max(0.0,min(1.0,u))
    return u/12.92 if u<=0.04045 else ((u+0.055)/1.055)**2.4
def mixc(a,b,t):
    t=max(0,min(1,t)); return [a[k]+(b[k]-a[k])*t for k in range(3)]

SKIN={'s1':[0.976,0.878,0.792],'s2':[0.945,0.824,0.722],'s3':[0.898,0.749,0.627],
      's4':[0.839,0.671,0.541],'s5':[0.741,0.569,0.447],'s6':[0.616,0.451,0.345],
      's7':[0.467,0.329,0.251],'s8':[0.333,0.227,0.176]}
HAIRC={'h1':[0.149,0.090,0.067],'h2':[0.286,0.176,0.114],'h3':[0.420,0.271,0.157],
       'h4':[0.561,0.392,0.212],'h5':[0.714,0.561,0.322],'h6':[0.824,0.702,0.467],
       'h7':[0.478,0.149,0.071],'h8':[0.251,0.251,0.263]}

def stylise_head(v, headK=1.12, eyeK=1.30):
    """Stylisation applied to the geometry, not to the shading.

       Every avatar system people actually like has a head a tenth larger
       than life and eyes a third larger. Trying for exact human
       proportions at this asset budget lands in the uncanny valley --
       which looks worse than either honest option -- so this commits to
       the other one. The neck takes the difference over a short falloff
       so nothing snaps."""
    eye = lambda g: [sum(v[i][k] for q,idx in FACES if q==g for i in idx)
                     / max(1, sum(1 for q,idx in FACES if q==g for i in idx))
                     for k in range(3)]
    eL = eye('helper-l-eye'); eR = eye('helper-r-eye')
    gap = abs(eL[0]-eR[0]) or 1.0
    eyeY = (eL[1]+eR[1])*0.5
    chin = eyeY - gap*7.0
    neck = chin - gap*3.4
    pivot = [0.0, chin, (eL[2]+eR[2])*0.5]
    for p in v:
        t = (p[1]-neck)/((chin-neck) or 1.0)
        t = max(0.0, min(1.0, t)); t = t*t*(3-2*t)
        if t <= 0: continue
        k = 1 + (headK-1)*t
        p[0] *= k
        p[1] = pivot[1] + (p[1]-pivot[1])*k
        p[2] = pivot[2] + (p[2]-pivot[2])*k
    # the eyes get their own extra scale, about each eyeball's own centre
    for g in ('helper-l-eye','helper-r-eye','helper-l-eyelashes-1','helper-l-eyelashes-2',
              'helper-r-eyelashes-1','helper-r-eyelashes-2'):
        ids = sorted({i for q,idx in FACES if q==g for i in idx})
        if not ids: continue
        c = [sum(v[i][k] for i in ids)/len(ids) for k in range(3)]
        for i in ids:
            for k in range(3): v[i][k] = c[k] + (v[i][k]-c[k])*eyeK

def skin_colours(P, src, B, N, adj, skinKey, hairKey):
    base=SKIN[skinKey]; hc=HAIRC[hairKey]
    # cheap cavity AO: how far the neighbour centroid sits along the normal
    AO=[]
    for i,p in enumerate(P):
        nb=set(adj[i])
        if not nb: AO.append(0.5); continue
        cx=[sum(P[j][k] for j in nb)/len(nb) for k in range(3)]
        AO.append(sum((cx[k]-p[k])*N[i][k] for k in range(3)))
    lo=min(AO); hi=max(AO); rng=(hi-lo) or 1
    AO=[max(0.0,min(1.0,((a-lo)/rng-0.35)/0.5)) for a in AO]
    # the hairline, so the scalp is dark under the hair instead of pink
    eyeY=B.sy+1.55
    zs=[p[2] for p in P if B.frac(p)>0.88]
    zmin,zmax=(min(zs),max(zs)) if zs else (0,1)
    xmax=max(abs(p[0]) for p in P if B.frac(p)>0.88) or 1
    col=[]
    for n,i in enumerate(src):
        c=base[:]
        if B.frac(P[n])>0.86:
            ch=CHEEK.get(i,0)
            if ch>0.35: c=mixc(c,[0.902,0.545,0.494],(ch-0.35)*0.30)
            lp=LIPS.get(i,0)
            if lp>0.12: c=mixc(c,mixc(base,[0.706,0.361,0.361],0.75),min(1,(lp-0.12)/0.5))
            ld=LIDS.get(i,0)
            if ld>0.88: c=mixc(c,[0.561,0.361,0.329],min(1,(ld-0.88)/0.12)*0.45)
            zf=max(0.0,min(1.0,(P[n][2]-zmin)/((zmax-zmin) or 1)))
            shl=eyeY + 0.80*(zf**1.5) - 0.06 - 0.20*(abs(P[n][0])/xmax)
            sd=(P[n][1]-(shl-0.20))/0.18
            if sd>0: c=mixc(c,hc,min(1.0,sd)*0.92)
        a=1.0-AO[n]*0.26
        col += [s2l(c[0]*a), s2l(c[1]*a), s2l(c[2]*a)]
    return col

def ball(verts, group):
    pts=[verts[i] for g,idx in FACES if g==group for i in idx]
    if not pts: return None
    xs=[p[0] for p in pts]; ys=[p[1] for p in pts]; zs=[p[2] for p in pts]
    return {'c':[(min(xs)+max(xs))/2,(min(ys)+max(ys))/2,(min(zs)+max(zs))/2],
            'r':max(max(xs)-min(xs),max(ys)-min(ys),max(zs)-min(zs))/2}

def simple(verts, groups):
    P,TT,_=part(verts,groups)
    if not P: return None
    N,_=normals(P,TT)
    return {'position':[c for v in P for c in v],'normal':[c for v in N for c in v],
            'index':[i for f in TT for i in f],'n':len(P)}

_REF_MIX=[('macrodetails/caucasian-female-young',.50),
          ('macrodetails/african-female-young',.28),
          ('macrodetails/asian-female-young',.22)]
def ensure_reference():
    ref=[list(p) for p in RAW]; apply(ref, _REF_MIX)
    anat.reference(ref, BODYIDX)

def build(spec):
    ensure_reference()
    v=[list(p) for p in RAW]
    apply(v, spec['mix'])
    if spec.get('stylised', True):
        stylise_head(v, spec.get('headK', 1.12), spec.get('eyeK', 1.30))
    B=anat.Body(v, BODYIDX)
    if not hasattr(B,'cls'): B.classify()
    anat.arms_down(B, 34.0)
    B2=anat.Body(v, BODYIDX); B2.cls=B.cls        # heights after posing
    P,TT,src=part(v,{'body'})
    N,adj=normals(P,TT)
    uv=[]
    for i in src:
        t=UVOF.get(i)
        q=UVRAW[t] if t is not None and t < len(UVRAW) else [0.0,0.0]
        uv += [q[0], q[1]]
    out={'body':{'position':[c for q in P for c in q],
                 'normal':[c for q in N for c in q],
                 'color':skin_colours(P,src,B2,N,adj,spec['skin'],spec['hairColor']),
                 'uv':uv,
                 'index':[i for f in TT for i in f],'n':len(P)}}
    nrm = cutter.body_normals(B2, FACES)
    out['top']=wardrobe2.top(B2, FACES, nrm, spec.get('top','tee'))
    if spec.get('skirt'):
        out['skirt']=wardrobe2.skirt(B2, FACES, nrm, spec.get('skirt'))
    if spec.get('bottom'):
        out['bottom']=wardrobe2.bottoms(B2, FACES, nrm, spec.get('bottom'))
    out['shoes']=footwear.build(B2, spec.get('shoes','flat'))
    # Strands, not the helper band. The band is fitting geometry -- a
    # strip around the lid -- and drawn as it stands it reads as a thick
    # black line painted round the eye. The lashes are grown off its
    # inner edge instead, the same way the browser does it.
    def _ids(gname):
        return sorted({i for g, idx in FACES if g == gname for i in idx})
    lashes = []
    for side, sgn in (('l', 1.0), ('r', -1.0)):
        eye = ball(v, 'helper-%s-eye' % side)
        if not eye: continue
        d = hairgen.build_lashes(v, [
            dict(ids=_ids('helper-%s-eyelashes-2' % side), upper=True,  count=112,
                 len=.30, wid=.022, up=.55, fwd=.80, sweep=.55, curl=.45),
            dict(ids=_ids('helper-%s-eyelashes-1' % side), upper=False, count=62,
                 len=.15, wid=.020, up=.70, fwd=.70, sweep=.35, curl=.10),
        ], eye['c'], eye['r'], 17, eye['c'][0])
        if d: lashes.append(d)
    if lashes:
        P = []; N = []; C = []; I = []
        for d in lashes:
            off = len(P) // 3
            P += d['position']; N += d['normal']; C += d['color']
            I += [i + off for i in d['index']]
        out['lashes'] = {'position':P, 'normal':N, 'color':C, 'index':I, 'n':len(P)//3}
    eye=ball(v,'helper-l-eye'); out['eyeL']=eye; out['eyeR']=ball(v,'helper-r-eye')
    # The brows are strands on the skin, not a smudge in the texture: the
    # head's UV island gives a painted brow about thirty texels across,
    # which can only ever be a smear.
    brows=hairgen.build_brows(P,N,eye['c'],out['eyeR']['c'],11)
    if brows: out['brows']=brows
    hopt={'front':.76,'thick':.05,'puff':.075,
          'rings':11,'length':spec.get('hairLen',1.55)}
    # The cap stays, but dark and tight to the skull: it is only there so
    # no scalp shows through the gaps between the cards.
    cap=HAIR.scalp(P,TT,N,eye,{'front':hopt['front'],'thick':.030})
    if cap:
        out['hairCap']=hairgen.cap_mesh(cap)
        out['hair']=hairgen.build(cap['pos'],cap['nrm'],cap['tris'],
                                  style=spec.get('hairStyle','long'),
                                  seed=spec.get('hairSeed',3))
    else:
        h=HAIR.build_hair(P,TT,N,eye,hopt)
        if h: out['hair']=h
    ys=[q[1] for q in P]
    out['bounds']={'top':max(ys),'bot':min(ys)}
    out['palette']={'skin':spec['skin'],'hair':spec['hairColor'],
                    'top':spec['topColor'],'bottom':spec['bottomColor']}
    return out
