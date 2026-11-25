const { chromium } = require('playwright'); const fs=require('fs');
(async()=>{ const b=await chromium.launch(); const p=await b.newPage();
 p.on('pageerror',e=>console.log('[PAGE ERROR]',e.message));
 await p.goto('http://127.0.0.1:8101/verify.html'); await p.waitForFunction('window.ready===true',{timeout:60000});
 const spec = JSON.parse(fs.readFileSync('spec.json','utf8'));
 const r = await p.evaluate(s=>window.buildAvatar(s), spec);
 fs.writeFileSync('js_full.json', JSON.stringify(r, null, 1));
 console.log(JSON.stringify(r.ms));
 for(const k of Object.keys(r.summary))
   console.log('  %s n=%d tris=%d bbox=%s', k.padEnd(7), r.summary[k].n, r.summary[k].tris,
               r.summary[k].bbox.join(' '));
 await b.close(); })();
