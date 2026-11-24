#!/bin/sh
# Everything Lorey is made of, straight from the MakeHuman repository.
# All of it was explicitly released as CC0 in September 2020 by
# Data Collection AB, Joel Palmius and Jonas Hauquier; each file says so
# in its own header. Run this once, then build_head.py, then pack.py.
set -e
B=https://raw.githubusercontent.com/makehumancommunity/makehuman/master/makehuman/data
curl -sf -o base.obj "$B/3dobjs/base.obj"
curl -sf -o modeling_modifiers.json "$B/modifiers/modeling_modifiers.json"
mkdir -p targets/macrodetails
for n in caucasian-female-young african-female-young asian-female-young \
         caucasian-male-young   african-male-young   asian-male-young; do
  curl -sf -o "targets/macrodetails/$n.target" "$B/targets/macrodetails/$n.target"
done
# every head and face modifier the editor can reach
python3 - <<'PY' > tlist.txt
import json
d=json.load(open('modeling_modifiers.json'))
want={'head','forehead','eyebrows','neck','eyes','nose','mouth','ears','chin','cheek'}
out=set()
for g in d:
    if g['group'] not in want: continue
    for m in g['modifiers']:
        t=m.get('target')
        if not t: continue
        if 'min' in m: out.add(g['group']+'/'+t+'-'+m['min']); out.add(g['group']+'/'+t+'-'+m['max'])
        else: out.add(g['group']+'/'+t)
print('\n'.join(sorted(out)))
PY
xargs -P 16 -I{} sh -c 'mkdir -p targets/$(dirname {}); curl -sf -o targets/{}.target "'"$B"'/targets/{}.target" || echo "MISS {}"' < tlist.txt
echo "done: $(find targets -name '*.target' | wc -l) targets"
