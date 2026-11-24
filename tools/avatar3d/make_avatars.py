import sys, json, os
sys.path.insert(0,'/home/claude/mh')
import build_avatar as BA

MAC='macrodetails/'
def macro(eth, fem=True):
    g='female' if fem else 'male'
    return [(MAC+'caucasian-%s-young'%g,eth[0]),(MAC+'african-%s-young'%g,eth[1]),
            (MAC+'asian-%s-young'%g,eth[2])]

PEOPLE={
 'dramatic': dict(mix=macro((.7,.15,.15))+[('torso/torso-scale-vert-incr',.45),
     ('hip/hip-scale-horiz-decr',.30),('torso/torso-vshape-incr',.35)],
     skin='s3', hairColor='h1', topColor='t1', bottomColor='b1', sleeve=0.26, hairLen=2.4),
 'romantic': dict(mix=macro((.5,.3,.2))+[('hip/hip-scale-horiz-incr',.55),
     ('torso/torso-scale-vert-decr',.30),('stomach/stomach-tone-decr',.25),
     ('breast/breast-volume-vert-up',.35),('buttocks/buttocks-volume-incr',.35)],
     skin='s5', hairColor='h3', topColor='t2', bottomColor='b2', skirt=True, hairLen=2.0),
 'natural':  dict(mix=macro((.35,.4,.25))+[('torso/torso-scale-horiz-incr',.45),
     ('torso/torso-muscle-dorsi-incr',.25)],
     skin='s7', hairColor='h1', topColor='t3', bottomColor='b1', sleeve=0.42, hairLen=1.1),
 'gamine':   dict(mix=macro((.3,.2,.5))+[('torso/torso-scale-vert-decr',.55),
     ('hip/hip-scale-horiz-decr',.25),('torso/torso-scale-horiz-decr',.20)],
     skin='s2', hairColor='h8', topColor='t4', bottomColor='b3', sleeve=0.18, hairLen=0.7),
 'classic':  dict(mix=macro((.55,.25,.20)), skin='s4', hairColor='h5',
     topColor='t5', bottomColor='b2', skirt=True, hairLen=1.6),
}
out={}
for name,spec in PEOPLE.items():
    out[name]=BA.build(spec)
    e=out[name]
    print('%-10s body %5d  top %5s  legs %5s  shoes %5s  hair %5s' % (name,
        e['body']['n'],
        e['top']['n'] if e.get('top') else '-',
        (e.get('bottom') or e.get('skirt') or {}).get('n','-'),
        e['shoes']['n'] if e.get('shoes') else '-',
        e['hair']['n'] if e.get('hair') else '-'))
json.dump(out, open('build/avatars.json','w'))
print('avatars.json %.1f MB' % (os.path.getsize('build/avatars.json')/1048576))
