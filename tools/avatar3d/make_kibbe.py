import sys, json, os
sys.path.insert(0,'/home/claude/mh')
import build_avatar as BA, kibbe

MAC='macrodetails/'
NEUTRAL=[(MAC+'caucasian-female-young',.50),(MAC+'african-female-young',.28),
         (MAC+'asian-female-young',.22)]
out={}
for name, vec in kibbe.TYPES:
    spec=dict(mix=NEUTRAL+kibbe.shape_for(vec), skin='s4', hairColor='h2',
              topColor='t5', bottomColor='b1', sleeve=0.30, hairLen=1.6)
    out[name]=BA.build(spec)
    e=out[name]
    P=e['body']['position']; ys=[P[i] for i in range(1,len(P),3)]
    xs=[P[i] for i in range(0,len(P),3)]
    h=max(ys)-min(ys)
    # waist and hip widths, as fractions of height, so the shapes can be compared
    def width(frac, tol=0.012):
        y=min(ys)+h*frac
        w=[abs(P[i*3]) for i in range(len(ys)) if abs(P[i*3+1]-y)<h*tol and abs(P[i*3])<h*0.14]
        return (max(w)/h if w else 0)
    print('%-20s height %5.2f  shoulder %.3f  waist %.3f  hip %.3f' %
          (name, h, width(0.80), width(0.60), width(0.50)))
json.dump(out, open('build/kibbe.json','w'))
print('kibbe.json %.1f MB' % (os.path.getsize('build/kibbe.json')/1048576))
