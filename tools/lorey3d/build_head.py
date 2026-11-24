import json, math, os

RAW=[]; FACES=[]; cur=None
for line in open('base.obj'):
    if line.startswith('v '):
        p=line.split(); RAW.append([float(p[1]),float(p[2]),float(p[3])])
    elif line.startswith('g '): cur=line[2:].strip()
    elif line.startswith('f '):
        FACES.append((cur,[int(t.split('/')[0])-1 for t in line.split()[1:]]))

def load(path):
    out=[]
    for line in open(path):
        line=line.strip()
        if not line or line[0]=='#': continue
        p=line.split(); out.append((int(p[0]),float(p[1]),float(p[2]),float(p[3])))
    return out
C={}
def T(p):
    if p not in C: C[p]=load('targets/'+p+'.target')
    return C[p]

def mask(paths):
    """vertex -> 0..1 weight, from how far these targets move each vertex"""
    m={}
    for p in paths:
        if not os.path.exists('targets/'+p+'.target'): continue
        for i,dx,dy,dz in T(p):
            d=math.sqrt(dx*dx+dy*dy+dz*dz)
            if d>m.get(i,0): m[i]=d
    if not m: return {}
    mx=max(m.values()) or 1
    return {i:min(1.0,v/mx) for i,v in m.items()}

verts=[list(p) for p in RAW]
MIX=[('macrodetails/caucasian-female-young',.55),
     ('macrodetails/asian-female-young',.20),
     ('macrodetails/african-female-young',.25),
     ('mouth/mouth-upperlip-volume-incr',.35),
     ('mouth/mouth-lowerlip-volume-incr',.30),
     ('mouth/mouth-cupidsbow-incr',.45),
     ('eyes/l-eye-height2-incr',.30),('eyes/r-eye-height2-incr',.30),
     ('eyes/l-eye-scale-incr',.22),('eyes/r-eye-scale-incr',.22),
     ('cheek/l-cheek-bones-incr',.40),('cheek/r-cheek-bones-incr',.40),
     ('chin/chin-width-decr',.25),
     ('nose/nose-scale-horiz-decr',.30),
     ('head/head-oval',.35)]
for p,w in MIX:
    for i,dx,dy,dz in T(p):
        if i<len(verts): verts[i][0]+=dx*w; verts[i][1]+=dy*w; verts[i][2]+=dz*w

LIPS  = mask(['mouth/mouth-upperlip-volume-incr','mouth/mouth-lowerlip-volume-incr',
              'mouth/mouth-upperlip-height-incr','mouth/mouth-lowerlip-height-incr'])
BROWS = mask(['eyebrows/eyebrows-trans-up','eyebrows/eyebrows-angle-up'])
LIDS  = mask(['eyes/l-eye-height3-incr','eyes/r-eye-height3-incr',
              'eyes/l-eye-height1-incr','eyes/r-eye-height1-incr'])
LIDU  = mask(['eyes/l-eye-eyefold-up','eyes/r-eye-eyefold-up'])
CHEEK = mask(['cheek/l-cheek-bones-incr','cheek/r-cheek-bones-incr'])

CUT=4.25
keep=[i for g,i in FACES if g=='body' and all(verts[j][1]>CUT for j in i)]
m={}; pos=[]; tris=[]; src=[]
for idx in keep:
    f=[]
    for i in idx:
        if i not in m: m[i]=len(pos); pos.append(list(verts[i])); src.append(i)
        f.append(m[i])
    for k in range(1,len(f)-1): tris.append([f[0],f[k],f[k+1]])

# normals
N=[[0.0,0.0,0.0] for _ in pos]
adj=[[] for _ in pos]
for a,b,c in tris:
    pa,pb,pc=pos[a],pos[b],pos[c]
    u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
    cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
    for t in (a,b,c):
        for j in range(3): N[t][j]+=cx[j]
    adj[a]+=[b,c]; adj[b]+=[a,c]; adj[c]+=[a,b]
for q in N:
    L=math.sqrt(sum(x*x for x in q)) or 1
    for j in range(3): q[j]/=L

# cheap cavity AO: how far the neighbour centroid sits along the normal
AO=[]
for i,p in enumerate(pos):
    nb=set(adj[i])
    if not nb: AO.append(1.0); continue
    cx=[sum(pos[j][k] for j in nb)/len(nb) for k in range(3)]
    d=sum((cx[k]-p[k])*N[i][k] for k in range(3))
    AO.append(d)
lo=min(AO); hi=max(AO); rng=(hi-lo) or 1
AO=[(a-lo)/rng for a in AO]          # 0 = convex-ish, 1 = cavity
AO=[max(0.0,min(1.0,(a-0.35)/0.5)) for a in AO]

def mixc(a,b,t):
    t=max(0,min(1,t)); return [a[k]+(b[k]-a[k])*t for k in range(3)]

SKIN=[0.886,0.651,0.533]
LIPC=[0.706,0.361,0.361]
BROWC=[0.267,0.169,0.129]
LASH=[0.145,0.098,0.090]
BLUSH=[0.902,0.545,0.494]
col=[]
for n,i in enumerate(src):
    c=SKIN[:]
    yb=(pos[n][1]-CUT)
    c=mixc(c,[0.863,0.627,0.510],max(0,(1.2-yb))*0.25)   # neck/chest a touch cooler
    ch=CHEEK.get(i,0)
    if ch>0.35: c=mixc(c,BLUSH,(ch-0.35)*0.30)
    lp=LIPS.get(i,0)
    if lp>0.12: c=mixc(c,LIPC,min(1,(lp-0.12)/0.5))
    ld=LIDS.get(i,0)
    if ld>0.88: c=mixc(c,[0.561,0.361,0.329],min(1,(ld-0.88)/0.12)*0.45)
    sz=max(0.0,min(1.0,(pos[n][2]-(-0.45))/1.9))
    shl=6.757 + 0.80*(sz**1.5) - 0.06 - 0.20*(abs(pos[n][0])/1.05)
    sd=(pos[n][1]-(shl-0.20))/0.18
    if sd>0: c=mixc(c,[0.149,0.090,0.067],min(1.0,sd)*0.92)
    a=1.0-AO[n]*0.26
    def s2l(u):
        u=max(0.0,min(1.0,u))
        return u/12.92 if u<=0.04045 else ((u+0.055)/1.055)**2.4
    col += [s2l(c[0]*a), s2l(c[1]*a), s2l(c[2]*a)]

def ball(group):
    pts=[verts[i] for g,idx in FACES if g==group for i in idx]
    xs=[p[0] for p in pts]; ys=[p[1] for p in pts]; zs=[p[2] for p in pts]
    return {'c':[(min(xs)+max(xs))/2,(min(ys)+max(ys))/2,(min(zs)+max(zs))/2],
            'r':max(max(xs)-min(xs),max(ys)-min(ys),max(zs)-min(zs))/2}

# scalp ring, for hair: body verts above the brow line and behind the face plane
ys=[p[1] for p in pos]; yTop=max(ys)
out={'head':{'position':[c for v in pos for c in v],
             'normal':[c for v in N for c in v],
             'color':col,
             'index':[i for f in tris for i in f]},
     'eyeL':ball('helper-l-eye'),'eyeR':ball('helper-r-eye'),
     'bounds':{'yTop':yTop,'yCut':CUT}}
json.dump(out,open('build/headmesh.json','w'))
print('head',len(pos),'verts',len(tris),'tris  top y %.2f'%yTop)
print('masks: lips',len(LIPS),'brows',len(BROWS),'lids',len(LIDS),'cheek',len(CHEEK))

# --- helper geometry: lashes, scalp/hair volume, teeth, tongue ---
def part(groups, ymin=None):
    mm={}; P=[]; TT=[]
    for g,idx in FACES:
        if g not in groups: continue
        if ymin is not None and sum(verts[i][1] for i in idx)/len(idx) < ymin: continue
        f=[]
        for i in idx:
            if i not in mm: mm[i]=len(P); P.append(list(verts[i]))
            f.append(mm[i])
        for k in range(1,len(f)-1): TT.append([f[0],f[k],f[k+1]])
    if not P: return None
    NN=[[0.0,0.0,0.0] for _ in P]
    for a,b,c in TT:
        pa,pb,pc=P[a],P[b],P[c]
        u=[pb[j]-pa[j] for j in range(3)]; w=[pc[j]-pa[j] for j in range(3)]
        cx=[u[1]*w[2]-u[2]*w[1],u[2]*w[0]-u[0]*w[2],u[0]*w[1]-u[1]*w[0]]
        for t in (a,b,c):
            for j in range(3): NN[t][j]+=cx[j]
    for q in NN:
        L=math.sqrt(sum(x*x for x in q)) or 1
        for j in range(3): q[j]/=L
    bb=[[min(p[k] for p in P),max(p[k] for p in P)] for k in range(3)]
    return {'position':[c for v in P for c in v],'normal':[c for v in NN for c in v],
            'index':[i for f in TT for i in f],'bbox':bb,'n':len(P)}

extra={
 'lashes': part({'helper-l-eyelashes-1','helper-l-eyelashes-2','helper-r-eyelashes-1','helper-r-eyelashes-2'}, ymin=6.745),
 'teethU': part({'helper-upper-teeth'}),
 'teethL': part({'helper-lower-teeth'}),
 'tongue': part({'helper-tongue'}),
}
out.update({k:v for k,v in extra.items() if v})
for k,v in extra.items():
    if v: print(k, v['n'],'verts  bbox', [[round(a,2),round(b,2)] for a,b in v['bbox']])
json.dump(out,open('build/headmesh.json','w'))

# --- hair, grown off the real scalp ---
import sys, os as _o; sys.path.insert(0, _o.path.dirname(_o.path.abspath(__file__)))
import hair as HAIR
HSTYLE={'bob':  dict(front=.76,thick=.05,puff=.075,rings=11,length=1.55),
        'long': dict(front=.84,thick=.05,puff=.085,rings=14,length=3.0),
        'pixie':dict(front=.74,thick=.045,puff=.060,rings=6,length=0.55)}
out['hair']={}
for k,o in HSTYLE.items():
    h=HAIR.build_hair(pos,tris,N,out['eyeL'],o)
    if h: out['hair'][k]=h; print('hair',k,h['n'],'verts')
json.dump(out,open('build/headmesh.json','w'))

# --- expression morphs -------------------------------------------------
import morphs as MO
idxmap = {src[n]: n for n in range(len(pos))}
faceY=[p[1] for p in pos if p[1]>yTop-2.4]
faceZ=[p[2] for p in pos if p[1]>yTop-2.4]
eyeY=out['eyeL']['c'][1]
hinge=(0.0, eyeY-0.30, min(faceZ)+0.12)   # the real hinge: below the ear

M={}
lipY=[pos[idxmap[i]][1] for i in LIPS if i in idxmap]
mouthY=sum(lipY)/len(lipY)
chinY=min(p[1] for p in pos if p[2]>0.75 and p[1]>yTop-2.6)
print('mouthY %.3f chinY %.3f hinge'%(mouthY,chinY), [round(x,3) for x in hinge])
M['jawOpen'] = MO.pack(MO.jaw_rotation(pos, hinge, mouthY, chinY, 1.0))
M['blinkL']  = MO.pack(MO.blink(pos, N, out['eyeL'], 'l'))
M['blinkR']  = MO.pack(MO.blink(pos, N, out['eyeR'], 'r'))
for name,(tp,sc) in {
    'smile'     : ('mouth/mouth-angles-up', 1.0),
    'frown'     : ('mouth/mouth-angles-down', 1.0),
    'browUp'    : ('eyebrows/eyebrows-trans-up', 1.0),
    'browDown'  : ('eyebrows/eyebrows-trans-down', 1.0),
    'browAngle' : ('eyebrows/eyebrows-angle-up', 1.0),
    'mouthWide' : ('mouth/mouth-scale-horiz-incr', 1.0),
    'mouthNarrow':('mouth/mouth-scale-horiz-decr', 1.0),
    'pucker'    : ('mouth/mouth-trans-forward', 1.0),
    'squint'    : ('eyes/l-eye-height2-decr', 1.0),
    'noseWrinkle':('nose/nose-nostrils-angle-up', 1.0),
}.items():
    p='targets/'+tp+'.target'
    if not os.path.exists(p): print('  (missing',tp,')'); continue
    M[name]=MO.pack(MO.from_target(T(tp), idxmap, sc))
# squint needs the other eye too
import copy
if 'squint' in M and os.path.exists('targets/eyes/r-eye-height2-decr.target'):
    extra_s=MO.from_target(T('eyes/r-eye-height2-decr'), idxmap, 1.0)
    M['squint']['i'] += [s[0] for s in extra_s]
    M['squint']['d'] += [round(c,5) for s in extra_s for c in (s[1],s[2],s[3])]
M['lipsPart']=MO.pack(MO.lips_part(pos, LIPS, idxmap, mouthY, 1.0))
out['morphs']=M
out['hinge']=list(hinge)
for k,v in M.items(): print('morph %-12s %d verts'%(k,len(v['i'])))
json.dump(out,open('build/headmesh.json','w'))
import os as _os
print('json %.1f KB'%(_os.path.getsize('build/headmesh.json')/1024))

# --- brows, projected onto the real surface at build time -------------
def brow_ribbon(sign, eye):
    cx,cy,cz = eye['c']; r = eye['r']
    cand=[(p,i) for i,p in enumerate(pos) if p[1]>yTop-2.2 and p[2]>0.2]
    P=[]; T=[]
    rows=0
    for k in range(17):
        t=k/16.0
        x=cx+sign*(t-0.26)*r*3.5
        arch=math.sin(max(0.0,min(1.0,t))*math.pi)*0.030 - (t-0.55)**2*0.045
        y=cy+r*1.06+arch+(-(t-0.70)*0.30 if t>0.70 else 0.0)
        best=None; bd=1e9
        for p,i in cand:
            d=(p[0]-x)**2+(p[1]-y)**2
            if d<bd: bd=d; best=p
        z=best[2]+0.014
        w=0.0175*math.sin(max(0.12,min(1.0,t))*math.pi)+0.0045
        P.append([x,y+w,z]); P.append([x,y-w,z]); rows+=1
    for k in range(rows-1):
        a=k*2; T.append([a,a+1,a+2]); T.append([a+1,a+3,a+2])
    NN=[[0.0,0.0,1.0] for _ in P]
    return {'position':[c for v in P for c in v],'normal':[c for v in NN for c in v],
            'index':[i for f in T for i in f],'n':len(P)}

out['browL']=brow_ribbon( 1, out['eyeL'])
out['browR']=brow_ribbon(-1, out['eyeR'])
print('brows', out['browL']['n'],'verts each')
json.dump(out,open('build/headmesh.json','w'))
