import json, struct, math, os

D=json.load(open('build/headmesh.json'))

def qpos(arr):
    mn=[min(arr[i::3]) for i in range(3)]; mx=[max(arr[i::3]) for i in range(3)]
    sc=[(mx[i]-mn[i]) or 1 for i in range(3)]
    out=bytearray()
    for i in range(0,len(arr),3):
        for k in range(3):
            v=int(round((arr[i+k]-mn[k])/sc[k]*65535))
            out+=struct.pack('<H',max(0,min(65535,v)))
    return bytes(out),mn,sc

def qidx(idx,n):
    f='<H' if n<65536 else '<I'
    return b''.join(struct.pack(f,i) for i in idx), (2 if n<65536 else 4)

def qcol(c):
    def l2s(u):
        u=max(0.0,min(1.0,u))
        return u*12.92 if u<=0.0031308 else 1.055*(u**(1/2.4))-0.055
    return bytes(int(round(l2s(v)*255)) for v in c)

H={'parts':{},'morphs':{}}
BLOB=bytearray()
def put(b):
    off=len(BLOB); BLOB.extend(b)
    while len(BLOB)%4: BLOB.append(0)
    return off,len(b)

def addpart(name,d,color=False):
    n=len(d['position'])//3
    pb,mn,sc=qpos(d['position'])
    po,pl=put(pb)
    ib,isz=qidx(d['index'],n)
    io,il=put(ib)
    e={'n':n,'pos':[po,pl],'min':[round(x,5) for x in mn],'scale':[round(x,6) for x in sc],
       'idx':[io,il],'isz':isz,'ni':len(d['index'])}
    if color:
        co,cl=put(qcol(d['color'])); e['col']=[co,cl]
    H['parts'][name]=e

addpart('head',D['head'],color=True)
for k in ('lashes','teethU','teethL','tongue','browL','browR'):
    if k in D: addpart(k,D[k])
addpart('hair',D['hair']['bob'])

for name,m in D['morphs'].items():
    I=m['i']; dd=m['d']
    mx=max((abs(v) for v in dd), default=1) or 1
    ib=b''.join(struct.pack('<H',i) for i in I)
    db=b''.join(struct.pack('<h',max(-32767,min(32767,int(round(v/mx*32767))))) for v in dd)
    io,il=put(ib); do,dl=put(db)
    H['morphs'][name]={'n':len(I),'i':[io,il],'d':[do,dl],'s':round(mx/32767,10)}

H['eyeL']=D['eyeL']; H['eyeR']=D['eyeR']; H['hinge']=D['hinge']; H['bounds']=D['bounds']
hj=json.dumps(H,separators=(',',':')).encode()
pad=(-(8+len(hj)))%4
hj=hj+b' '*pad                      # keep the blob 4-byte aligned
out=b'LOR3'+struct.pack('<I',len(hj))+hj+bytes(BLOB)
assert (8+len(hj))%4==0
os.makedirs('build/assets',exist_ok=True)
open('build/assets/lorey3d.bin','wb').write(out)
import gzip
gz=gzip.compress(out,9)
print('header %.1f KB  blob %.1f KB  total %.1f KB  gzipped %.1f KB'%(
    len(hj)/1024,len(BLOB)/1024,len(out)/1024,len(gz)/1024))
