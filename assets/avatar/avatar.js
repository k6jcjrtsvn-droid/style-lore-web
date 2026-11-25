/* The shared avatar bundle, on the client.

   One file is downloaded once and cached forever; a member is then forty
   weights and a handful of ids. Everything here is typed-array views
   straight onto the downloaded buffer -- nothing is parsed into objects,
   because at 13,380 vertices and 140,000 morph entries the parsing would
   cost more than the download. */
export async function loadBase(url){
  const buf = await (await fetch(url)).arrayBuffer();
  const dv = new DataView(buf);
  if(String.fromCharCode(dv.getUint8(0),dv.getUint8(1),dv.getUint8(2),dv.getUint8(3)) !== 'AVB1')
    throw new Error('not an avatar bundle');
  const hlen = dv.getUint32(4, true);
  const head = JSON.parse(new TextDecoder().decode(new Uint8Array(buf, 8, hlen)));
  const base = 8 + hlen;
  const at = k => { const b = head.blocks[k]; return { off: base + b.off, len: b.len }; };

  const pq = at('pos'), iq = at('idx'), uq = at('uv'), sq = at('src'), tq = at('targets');
  const posQ = new Uint16Array(buf, pq.off, pq.len / 2);
  const index = head.indexBytes === 2
    ? new Uint16Array(buf, iq.off, iq.len / 2)
    : new Uint32Array(buf, iq.off, iq.len / 4);
  const uvQ = new Uint16Array(buf, uq.off, uq.len / 2);
  const src = new Uint32Array(buf, sq.off, sq.len / 4);
  const tbuf = buf.slice(tq.off, tq.off + tq.len);   // own buffer: entries are 10 bytes,
  const tdv = new DataView(tbuf);                     // so views would be misaligned

  const n = head.n;
  // base-mesh vertex id -> our vertex. part() dedupes, so this is 1:1.
  let maxSrc = 0;
  for(let i = 0; i < n; i++) if(src[i] > maxSrc) maxSrc = src[i];
  const back = new Int32Array(maxSrc + 1).fill(-1);
  for(let i = 0; i < n; i++) back[src[i]] = i;

  const rest = new Float32Array(n * 3);
  const mn = head.posMin, sc = head.posScale;
  for(let i = 0; i < n; i++)
    for(let k = 0; k < 3; k++)
      rest[i*3+k] = mn[k] + posQ[i*3+k] / 65535 * sc[k];

  const uv = new Float32Array(n * 2);
  for(let i = 0; i < n * 2; i++) uv[i] = uvQ[i] / 65535;

  // the helper groups: eyes, lashes, jaw. Not drawn, but the client needs
  // them morphed -- they place the eyeballs, set the hairline the scalp is
  // cut at, and anchor the stylisation.
  const hq = at('hsrc'), hp = at('hpos');
  const hsrc = new Uint32Array(buf, hq.off, hq.len / 4);
  const hrest = new Float32Array(buf.slice(hp.off, hp.off + hp.len));
  const hn = head.hn;
  let maxH = 0;
  for(let i = 0; i < hn; i++) if(hsrc[i] > maxH) maxH = hsrc[i];
  const hback = new Int32Array(maxH + 1).fill(-1);
  for(let i = 0; i < hn; i++) hback[hsrc[i]] = i;

  // the arm classification, measured once on the neutral body at build
  // time and shipped. (t, r) along the arm for every vertex that is on
  // one; the scan that finds them is not ported, see body.js.
  const cq = at('cls');
  const cdv = new DataView(buf.slice(cq.off, cq.off + cq.len));
  const cls = Object.create(null);
  const cscale = head.ref.clsScale;
  for(let p = 0; p + 8 <= cq.len; p += 8){
    const bi = cdv.getUint32(p, true);
    const oi = bi < back.length ? back[bi] : -1;
    if(oi < 0) continue;
    cls[oi] = [cdv.getInt16(p+4, true)/cscale, cdv.getInt16(p+6, true)/cscale];
  }

  const byName = {};
  head.targets.forEach(t => { byName[t.name] = t; });

  return { head, n, index, uv, rest, back, tdv, byName, cls,
           hn, hrest, hback, helpers: head.helpers,
           tScale: head.targetScale };
}

/* mix: [[targetName, weight], ...] -- the same shape the Python pipeline
   takes, so a figure built here and a figure built there are the same
   figure. */
export function applyMix(B, mix, out, hout){
  const v = out || new Float32Array(B.rest.length);
  const h = hout || new Float32Array(B.hrest.length);
  v.set(B.rest); h.set(B.hrest);
  for(const [name, w] of mix){
    if(!w) continue;
    const t = B.byName[name];
    if(!t) continue;
    let p = t.off;
    for(let e = 0; e < t.count; e++, p += 10){
      const bi = B.tdv.getUint32(p, true);
      const dx = B.tdv.getInt16(p+4, true) / B.tScale * w;
      const dy = B.tdv.getInt16(p+6, true) / B.tScale * w;
      const dz = B.tdv.getInt16(p+8, true) / B.tScale * w;
      const oi = bi < B.back.length ? B.back[bi] : -1;
      if(oi >= 0){ v[oi*3] += dx; v[oi*3+1] += dy; v[oi*3+2] += dz; }
      const hi = bi < B.hback.length ? B.hback[bi] : -1;
      if(hi >= 0){ h[hi*3] += dx; h[hi*3+1] += dy; h[hi*3+2] += dz; }
    }
  }
  return { body: v, helper: h };
}

/* The centroid of a helper group, in the morphed pose. */
export function groupCentre(B, h, name){
  const ids = B.helpers[name];
  if(!ids || !ids.length) return null;
  const c = [0, 0, 0];
  for(const i of ids){ c[0] += h[i*3]; c[1] += h[i*3+1]; c[2] += h[i*3+2]; }
  return [c[0]/ids.length, c[1]/ids.length, c[2]/ids.length];
}

/* The same stylisation the pipeline bakes, so the client and the build
   agree. Landmarks come in rather than being found here, because the eye
   helpers are not part of the drawable body. */
export function styliseHead(v, eyeY, eyeZ, gap, headK){
  const chin = eyeY - gap * 7.0, neck = chin - gap * 3.4;
  const py = chin, pz = eyeZ;
  for(let i = 0; i < v.length; i += 3){
    let t = (v[i+1] - neck) / ((chin - neck) || 1);
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    t = t * t * (3 - 2 * t);
    if(t <= 0) continue;
    const k = 1 + (headK - 1) * t;
    v[i]   *= k;
    v[i+1]  = py + (v[i+1] - py) * k;
    v[i+2]  = pz + (v[i+2] - pz) * k;
  }
  return v;
}

export function normals(v, index, out){
  const N = out || new Float32Array(v.length);
  N.fill(0);
  for(let f = 0; f < index.length; f += 3){
    const a = index[f]*3, b = index[f+1]*3, c = index[f+2]*3;
    const ux = v[b]-v[a], uy = v[b+1]-v[a+1], uz = v[b+2]-v[a+2];
    const wx = v[c]-v[a], wy = v[c+1]-v[a+1], wz = v[c+2]-v[a+2];
    const nx = uy*wz-uz*wy, ny = uz*wx-ux*wz, nz = ux*wy-uy*wx;
    N[a]+=nx; N[a+1]+=ny; N[a+2]+=nz;
    N[b]+=nx; N[b+1]+=ny; N[b+2]+=nz;
    N[c]+=nx; N[c+1]+=ny; N[c+2]+=nz;
  }
  for(let i = 0; i < N.length; i += 3){
    const L = Math.hypot(N[i], N[i+1], N[i+2]) || 1;
    N[i]/=L; N[i+1]/=L; N[i+2]/=L;
  }
  return N;
}

/* Kibbe type -> morph weights. A port of kibbe.shape_for(), reading the
   tables out of the bundle so there is only ever one copy of them. */
const CANCEL = [
  ['torso/torso-scale-vert-incr','torso/torso-scale-vert-decr'],
  ['torso/torso-scale-horiz-incr','torso/torso-scale-horiz-decr'],
  ['hip/hip-scale-horiz-incr','hip/hip-scale-horiz-decr'],
  ['stomach/stomach-tone-incr','stomach/stomach-tone-decr'],
  ['breast/breast-volume-vert-up','breast/breast-volume-vert-down'],
  ['hip/hip-waist-up','hip/hip-waist-down'],
  ['armslegs/lowerlegs-height-incr','armslegs/lowerlegs-height-decr'],
];
for(const side of ['l','r'])
  for(const [a,b] of [['upperleg-scale-vert-incr','upperleg-scale-vert-decr'],
                      ['upperarm-scale-horiz-incr','upperarm-scale-horiz-decr']])
    CANCEL.push(['armslegs/' + side + '-' + a, 'armslegs/' + side + '-' + b]);

export function shapeFor(B, vec){
  const K = B.head.kibbe;
  const out = {};
  for(const fam in vec){
    const w = vec[fam];
    if(fam === 'mod' || !w) continue;
    const tbl = K.family[fam];
    if(!tbl) continue;
    for(const t in tbl) out[t] = (out[t] || 0) + tbl[t] * w;
  }
  const mod = vec.mod || 0;
  if(mod){
    const src = mod > 0 ? K.modYang : K.modYin;
    for(const t in src) out[t] = (out[t] || 0) + src[t] * Math.abs(mod);
  }
  // Opposing targets cancel rather than fight: a blend that asks for both
  // a longer and a shorter torso should get neither, not both applied.
  for(const [a, b] of CANCEL){
    if(a in out && b in out){
      const d = out[a] - out[b];
      out[a] = Math.max(0, d); out[b] = Math.max(0, -d);
    }
  }
  const list = [];
  for(const t of Object.keys(out).sort())
    if(out[t] > 0.004) list.push([t, Math.min(1, Math.round(out[t]*1000)/1000)]);
  return list;
}

/* The macro blend the pipeline measures its landmarks against. */
export const MACRO_NEUTRAL = [
  ['macrodetails/caucasian-female-young', .50],
  ['macrodetails/african-female-young', .28],
  ['macrodetails/asian-female-young', .22],
];

/* The editor's own sliders, as morph weights.

   Twelve for the body and fifteen for the face, each 0..1 with 0.5
   neutral, each mapped to a list of targets for the way up and another
   for the way down. The table lives in the bundle, exported from
   sliders.py at pack time, so there is one copy of it. */
export function sliderMix(B, body, face){
  const S = B.head.sliders;
  if(!S) return [];
  const out = {};
  const take = (tbl, vals) => {
    if(!vals) return;
    for(const key in tbl){
      const v = vals[key];
      if(v == null) continue;
      const d = (+v - 0.5) * 2;
      if(Math.abs(d) < 0.02) continue;
      const list = d > 0 ? tbl[key][0] : tbl[key][1];
      for(const [t, w] of list) out[t] = (out[t] || 0) + w * Math.abs(d);
    }
  };
  take(S.body, body);
  take(S.face, face);
  const list = [];
  for(const t of Object.keys(out).sort())
    if(out[t] > 0.004) list.push([t, Math.min(1, Math.round(out[t]*1000)/1000)]);
  return list;
}
