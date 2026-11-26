/* Clothes and shoes on the client: ports of wardrobe2.py and footwear.py.

   A garment is a scalar field -- positive inside the cloth, negative
   outside -- and every triangle the boundary crosses is split exactly
   where it crosses. The hem then follows the curve it was asked for
   rather than the nearest staircase of quads. */
import { shell, faceNormals } from './body.js';

const sstep = t => { t = t < 0 ? 0 : t > 1 ? 1 : t; return t*t*(3-2*t); };

const limbY = (B, fracOfArm) => {
  let armL = 1.0;
  for(const s in B.axis) if(B.axis[s].L > armL) armL = B.axis[s].L;
  return B.sy - fracOfArm * armL;
};

export const TOPS = {
  'tee':          {hem:.515, neck:.76, sleeve:.30},
  'long-sleeve':  {hem:.510, neck:.76, sleeve:.78},
  'tank':         {hem:.515, neck:.80, sleeve:.02, strap:true, slack:.55},
  'vneck':        {hem:.515, neck:1.30, sleeve:.30, vee:true},
  'crop':         {hem:.60, neck:.95, sleeve:.22, slack:.35},
};
export const BOTTOMS = {
  'leggings': {waist:.56, hem:.075},
  'trousers': {waist:.57, hem:.065, thick:.075},
  'shorts':   {waist:.56, hem:.38},
  'capri':    {waist:.56, hem:.20},
};
export const SKIRTS = {
  'a-line': {length:.30, flare:.55, waist:.56},
  'pencil': {length:.26, flare:.10, waist:.57},
  'midi':   {length:.40, flare:.45, waist:.56},
  'maxi':   {length:.52, flare:.38, waist:.56},
  'mini':   {length:.17, flare:.50, waist:.56},
};

export function top(B, index, nrm, style, thick){
  const o = TOPS[style] || TOPS['tee'];
  let armL = 1.0;
  for(const s in B.axis) if(B.axis[s].L > armL) armL = B.axis[s].L;
  const cuffT = o.sleeve * armL;
  const neckR = o.neck;
  const v = B.v;
  const field = i => {
    const p = [v[i*3], v[i*3+1], v[i*3+2]];
    const d = [(B.fracP(p) - o.hem) * B.h];
    let nd = B.neckDist(p);
    if(o.vee) nd = nd + Math.max(0, 0.55 - Math.abs(p[0])) * (p[2] > 0 ? 1 : 0) * 1.1;
    d.push(nd - neckR);
    /* A ceiling so a top can never climb onto the head. It used to sit
       0.38 above the shoulder, which is low enough to slice across the
       top of the shoulder itself where it rises toward the neck -- the
       pair of small dark notches either side of the collar. The neckline
       is the neck-distance term's job; this one only has to be out of
       its way. */
    d.push(B.sy + 0.38 + 1.05 * sstep((Math.abs(p[0]) - 0.42) / 0.75) - p[1]);
    /* The cuff is cut ALONG THE ARM, not at a height. A horizontal plane
       through an arm that hangs at an angle is an ellipse, and what came
       out was a long diagonal tongue down the outside of each sleeve.

       The radius is its own number, between the two the anatomy offers.
       limb()'s 1.05 drops the outer surface of a thick upper arm, and
       those vertices then skip the cuff and are kept by the hem -- a
       ragged sleeve. The wide 2.00 reaches the side of the ribs, and
       cutting those at the cuff opens a hole under the arm.

       This is a threshold, and a threshold puts a cliff in the field,
       which is what leaves the small sawtooth still visible along the
       cuff. Three attempts at replacing it with a continuous measure of
       'how much of an arm is this' all came out WORSE -- fading the term
       left islands of cloth floating off the forearm, and driving it
       from the smoothed pose weights made the cut wander. The threshold
       stays until the boundary itself is resampled, which is the real
       fix and a larger one. */
    const a = B.cls[i];
    if(a && a[1] <= 1.32 && a[0] >= 0.25){
      d.push(cuffT - a[0]);
      if(o.strap) d.push(-1.0);                   // no sleeve at all
    }
    return Math.min.apply(null, d);
  };
  /* Thicker than the bottoms, and not by accident: where a tee overlaps
     a waistband the two shells are within a few thousandths of each
     other, each one wins the depth test over half the pixels, and with
     the picture supersampled the two colours average into what looks
     like a transparent shirt. Layering the offsets is the whole fix --
     the top is simply further out than what it is worn over. */
  /* slack: how much of the hanging envelope the cloth takes below the
     chest. A tank is cut closer than a tee and a crop ends before there
     is anything to hang, so they get less of it. */
  return shell(B, index, nrm, field, thick == null ? .115 : thick,
               { relax: (window.__RELAX == null ? .22 : window.__RELAX),
                 smooth: 2, drape: true, edgeLift: 1.0,
                 slack: o.slack == null ? .50 : o.slack,
                 collar: o.collar == null ? .085 : o.collar });
}

export function bottoms(B, index, nrm, style){
  const o = BOTTOMS[style] || BOTTOMS['leggings'];
  const th = o.thick == null ? .078 : o.thick;
  const v = B.v;
  const field = i => {
    if(B.limb(i)) return -1.0;
    const p = [v[i*3], v[i*3+1], v[i*3+2]];
    const f = B.fracP(p);
    return Math.min((f - o.hem) * B.h, (o.waist - f) * B.h);
  };
  return shell(B, index, nrm, field, th, { relax: .22, smooth: 2, drape: true, edgeLift: 1.0 });
}

export function skirt(B, index, nrm, style, rings, seg){
  const o = SKIRTS[style] || SKIRTS['a-line'];
  rings = rings || 14; seg = seg || 56;
  const v = B.v;
  const ring = [];
  for(let f = 0; f < index.length; f += 3){
    const ids = [index[f], index[f+1], index[f+2]];
    const c = [0,0,0];
    for(const i of ids) for(let k = 0; k < 3; k++) c[k] += v[i*3+k] / 3;
    if(Math.abs(B.fracP(c) - o.waist) < 0.010 && !ids.some(i => B.limb(i))) ring.push(c);
  }
  if(ring.length < 12) return null;
  const cx = ring.reduce((a,p)=>a+p[0],0)/ring.length;
  const cz = ring.reduce((a,p)=>a+p[2],0)/ring.length;
  const cy = ring.reduce((a,p)=>a+p[1],0)/ring.length;
  const byang = new Map();
  for(const p of ring){
    const a = Math.atan2(p[2]-cz, p[0]-cx);
    const k = Math.floor((a + Math.PI) / (2*Math.PI) * seg) % seg;
    const r = Math.hypot(p[0]-cx, p[2]-cz);
    const cur = byang.get(k);
    if(!cur || r > cur[0]) byang.set(k, [r, p]);
  }
  let meanR = 0;
  for(const [, q] of byang) meanR += q[0];
  meanR = meanR / Math.max(1, byang.size);
  const loop = [];
  for(let k = 0; k < seg; k++){
    const q = byang.get(k);
    if(q){ loop.push(q[1]); continue; }
    const a = -Math.PI + 2*Math.PI*(k + 0.5)/seg;
    loop.push([cx + Math.cos(a)*meanR, cy, cz + Math.sin(a)*meanR]);
  }
  const P = [], T = [];
  let prev = null;
  for(let r = 0; r <= rings; r++){
    const u = r / rings;
    const y = cy - B.h * o.length * u;
    const k = 1.0 + o.flare * Math.pow(u, 1.4);
    const cur = [];
    for(const p of loop){
      cur.push(P.length/3);
      P.push(cx + (p[0]-cx)*k, y, cz + (p[2]-cz)*k);
    }
    if(prev){
      const m = loop.length;
      for(let i = 0; i < m; i++){
        const a=prev[i], b=prev[(i+1)%m], c=cur[i], d=cur[(i+1)%m];
        T.push(a,b,c, b,d,c);
      }
    }
    prev = cur;
  }
  return { position: P, normal: faceNormals(P, T), index: T, n: P.length/3 };
}

/* Shoes. The toe box holds its full length up to the top of the forefoot
   and only above that does the form leave the foot and follow the leg --
   lofting one cone from sole to shaft collapses the toe box at exactly
   the height the toes are at, which is how toes end up poking out
   through the front of a closed shoe. */
export const SHOE_STYLES = {
  'flat':        {shaft:.016, heel:.00, toe:2.6, vamp:.010},
  'sneaker':     {shaft:.055, heel:.012, toe:2.2, sole:.030, vamp:.012},
  'ankle-boot':  {shaft:.105, heel:.030, toe:2.8},
  'knee-boot':   {shaft:.300, heel:.034, toe:2.8},
  'pump':        {shaft:.012, heel:.075, toe:3.4, vamp:.008},
  'heeled-boot': {shaft:.135, heel:.070, toe:3.0},
  'sandal':      {shaft:.010, heel:.020, toe:2.4, open:true, vamp:0},
  'barefoot':    null,
};

export function shoes(B, style, e, rings, seg){
  const o = SHOE_STYLES[style];
  if(!o) return null;
  e = e || 2.5; rings = rings || 14; seg = seg || 36;
  const v = B.v, P = [], T = [];
  const sgnpow = (n, p) => Math.sign(n) * Math.pow(Math.abs(n), p);
  for(const side of [1, -1]){
    const pts = [], leg = [];
    for(let i = 0; i < B.n; i++){
      const p = [v[i*3], v[i*3+1], v[i*3+2]];
      const f = B.fracP(p);
      if(f < 0.46 && p[0]*side > 0) leg.push(p);
      if(f < 0.10 && p[0]*side > 0 && !B.limb(i)) pts.push(p);
    }
    if(pts.length < 20) continue;
    const xs = pts.map(p=>p[0]), ys = pts.map(p=>p[1]), zs = pts.map(p=>p[2]);
    const cx = (Math.min(...xs) + Math.max(...xs)) / 2;
    const ax = (Math.max(...xs) - Math.min(...xs)) / 2 * 1.10;
    const zf = Math.max(...zs), zb = Math.min(...zs), cz = (zf + zb) / 2;
    const front0 = (zf - cz) * 1.16, back0 = (cz - zb) * 1.10;
    const sole = (o.sole == null ? .012 : o.sole) * B.h;
    const y0 = Math.min(...ys) - sole;
    const heel = (o.heel || 0) * B.h;
    const zcut = cz + (zf - cz) * 0.52;
    const fore = pts.filter(p => p[2] > zcut).map(p => p[1]);
    const yTop = fore.length ? Math.max(...fore)
                             : Math.min(...ys) + 0.5*(Math.max(...ys)-Math.min(...ys));
    let y1 = B.bot + B.h * o.shaft;
    if(!o.open) y1 = Math.max(y1, yTop + (o.vamp == null ? .010 : o.vamp) * B.h);
    else y1 = Math.max(y1, y0 + 0.018 * B.h);

    const band = 0.035 * B.h;
    const legAt = y => {
      const q = leg.filter(p => Math.abs(p[1]-y) < band);
      if(q.length < 8) return null;
      const lx = q.map(p=>p[0]), lz = q.map(p=>p[2]);
      return [(Math.min(...lx)+Math.max(...lx))/2, (Math.max(...lx)-Math.min(...lx))/2*1.07,
              (Math.min(...lz)+Math.max(...lz))/2, (Math.max(...lz)-Math.min(...lz))/2*1.07];
    };
    const section = y => {
      if(y <= yTop){
        const t = (y - y0) / ((yTop - y0) || 1);
        const k = 1.0 - 0.07*t*t;
        return [cx, ax*k, cz, front0*(1 - 0.04*t*t), back0*k];
      }
      let t = Math.min(1, (y - yTop) / (0.085 * B.h));
      t = t*t*(3-2*t);
      let L = legAt(y);
      let lx, lax, lcz, lr;
      if(!L){ lx = cx; lax = ax*0.52; lcz = cz-(cz-zb)*0.18; lr = Math.max(front0,back0)*0.52; }
      else { [lx, lax, lcz, lr] = L; }
      const pad = 1.0 + (o.shaft > 0.09 ? 0.05 : 0.02);
      return [cx+(lx-cx)*t, ax+(lax*pad-ax)*t, cz+(lcz-cz)*t,
              front0+(lr*pad-front0)*t, back0+(lr*pad-back0)*t];
    };
    const start = P.length/3;
    let prev = null;
    for(let r = 0; r <= rings; r++){
      const u = r/rings, y = y0 + (y1-y0)*u;
      const [sx, sax, scz, sf, sb] = section(y);
      const ring = [];
      const ex = y <= yTop ? e*1.30 : e*0.80;
      for(let s2 = 0; s2 < seg; s2++){
        const th = 2*Math.PI*s2/seg;
        const nx = Math.cos(th), nz = Math.sin(th);
        const px = sx + sax * sgnpow(nx, 2/ex);
        const pz = scz + (nz >= 0 ? sf : sb) * sgnpow(nz, 2/ex);
        let py = y;
        if(heel > 0 && y < y0 + (y1-y0)*0.14 && pz < scz)
          py += heel * Math.pow(Math.min(1, (scz-pz)/((scz-zb) || 1)), 1.3);
        ring.push(P.length/3); P.push(px, py, pz);
      }
      if(prev) for(let s2 = 0; s2 < seg; s2++){
        const a=prev[s2], b=prev[(s2+1)%seg], c=ring[s2], d=ring[(s2+1)%seg];
        T.push(a,c,b, b,c,d);
      }
      prev = ring;
    }
    const first = []; for(let s2 = 0; s2 < seg; s2++) first.push(start + s2);
    const s0 = section(y0), s1 = section(y1);
    for(const [yy, ring, flip, qx, qz] of [[y0, first, true, s0[0], s0[2]],
                                           [y1, prev, false, s1[0], s1[2]]]){
      const ci = P.length/3; P.push(qx, yy, qz);
      for(let s2 = 0; s2 < seg; s2++){
        const a = ring[s2], b = ring[(s2+1)%seg];
        if(flip) T.push(ci, b, a); else T.push(ci, a, b);
      }
    }
    if(heel > 0.004 * B.h){
      const hz0 = zb - (cz-zb)*0.06, hz1 = cz - (cz-zb)*0.34, hx0 = ax*0.60;
      const corners = [[cx-hx0,hz0],[cx+hx0,hz0],[cx+hx0*0.92,hz1],[cx-hx0*0.92,hz1]];
      const topi = [], boti = [];
      for(const [qx,qz] of corners){ topi.push(P.length/3); P.push(qx, y0+heel*0.98, qz); }
      for(const [qx,qz] of corners){ boti.push(P.length/3);
        P.push(cx+(qx-cx)*0.92, y0-heel*0.02, cz+(qz-cz)*0.96); }
      for(let s2 = 0; s2 < 4; s2++){
        const a=topi[s2], b=topi[(s2+1)%4], c=boti[s2], d=boti[(s2+1)%4];
        T.push(a,c,b, b,c,d);
      }
      T.push(boti[0],boti[2],boti[1], boti[0],boti[3],boti[2]);
    }
  }
  if(!P.length) return null;
  return { position: P, normal: faceNormals(P, T), index: T, n: P.length/3 };
}
