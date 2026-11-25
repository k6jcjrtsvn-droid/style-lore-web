/* Hair cards, on the client.

   A straight port of tools/avatar3d/hairgen.py. The two have to agree
   exactly, because the build renders the reference and the member's own
   device renders the portrait that everyone else sees -- hair that
   differs between them is hair that changes when you look at it. That is
   why both sides use the explicit generator below rather than Python's
   Mersenne Twister and the browser's Math.random, which agree about
   nothing. */
export class RNG {
  constructor(seed){
    this.s = ((Math.imul(seed | 0, 2654435761) + 1) >>> 0) || 1;
  }
  next(){
    let x = this.s;
    x = (x ^ (x << 13)) >>> 0;
    x = (x ^ (x >>> 17)) >>> 0;
    x = (x ^ (x << 5)) >>> 0;
    this.s = x;
    return x;
  }
  random(){ return this.next() / 4294967296; }
  uniform(a, b){ return a + (b - a) * this.random(); }
  randint(a, b){ return a + Math.floor(this.random() * (b - a + 1)); }
  randrange(n){ return Math.floor(this.random() * n); }
}

const smooth = t => { t = t < 0 ? 0 : t > 1 ? 1 : t; return t*t*(3-2*t); };
const norm = v => { const L = Math.hypot(v[0],v[1],v[2]) || 1; return [v[0]/L,v[1]/L,v[2]/L]; };
const sub = (a,b) => [a[0]-b[0],a[1]-b[1],a[2]-b[2]];
const add = (a,b) => [a[0]+b[0],a[1]+b[1],a[2]+b[2]];
const mul = (a,k) => [a[0]*k,a[1]*k,a[2]*k];
const cross = (a,b) => [a[1]*b[2]-a[2]*b[1], a[2]*b[0]-a[0]*b[2], a[0]*b[1]-a[1]*b[0]];
const lerp3 = (a,b,t) => [a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t];

export const STYLISED = {
  long:  {cards:520, length:2.05, segs:12, width:.165, gravity:.90, volume:.26, curl:.06},
  bob:   {cards:470, length:1.00, segs:9,  width:.165, gravity:.94, volume:.22, curl:.05},
  wavy:  {cards:540, length:1.80, segs:13, width:.175, gravity:.84, volume:.30, curl:.18},
  curls: {cards:600, length:1.10, segs:13, width:.145, gravity:.60, volume:.40, curl:.46},
  short: {cards:440, length:0.56, segs:8,  width:.150, gravity:.82, volume:.28, curl:.10},
  crop:  {cards:390, length:0.34, segs:7,  width:.145, gravity:.76, volume:.24, curl:.12},
};

/* scalpPos / scalpNrm are flat arrays, scalpTris a flat index array. */
export function buildHair(scalpPos, scalpNrm, scalpTris, style, seed, slices){
  const o = STYLISED[style] || STYLISED.long;
  slices = slices || 8;
  const rng = new RNG(seed == null ? 3 : seed);
  const V = i => [scalpPos[i*3], scalpPos[i*3+1], scalpPos[i*3+2]];
  const Nv = i => [scalpNrm[i*3], scalpNrm[i*3+1], scalpNrm[i*3+2]];
  const T = scalpTris.length / 3;

  const cum = new Float64Array(T);
  let run = 0;
  for(let t = 0; t < T; t++){
    const a = V(scalpTris[t*3]), b = V(scalpTris[t*3+1]), c = V(scalpTris[t*3+2]);
    const cr = cross(sub(b,a), sub(c,a));
    run += Math.hypot(cr[0],cr[1],cr[2]) * .5;
    cum[t] = run;
  }
  const total = run || 1;
  for(let t = 0; t < T; t++) cum[t] /= total;

  let yTop = -Infinity, yBot = Infinity, zF = -Infinity, zB = Infinity,
      xMin = Infinity, xMax = -Infinity;
  const nv = scalpPos.length / 3;
  for(let i = 0; i < nv; i++){
    const y = scalpPos[i*3+1], z = scalpPos[i*3+2], x = scalpPos[i*3];
    if(y > yTop) yTop = y; if(y < yBot) yBot = y;
    if(z > zF) zF = z; if(z < zB) zB = z;
    if(x < xMin) xMin = x; if(x > xMax) xMax = x;
  }
  const crown = [(xMin + xMax) * .5, yTop, (zF + zB) * .5];

  const P = [], N = [], UV = [], C = [], TRI = [];
  const LAYERS = [[Math.floor(o.cards * .45), .34, .80, .004],
                  [o.cards, 1.0, 1.0, .040]];
  for(const [count, lenK, widK, shell] of LAYERS){
    for(let k = 0; k < count; k++){
      const r = rng.random();
      let lo = 0, hi = T - 1;
      while(lo < hi){ const mid = (lo + hi) >> 1; if(cum[mid] < r) lo = mid + 1; else hi = mid; }
      const ia = scalpTris[lo*3], ib = scalpTris[lo*3+1], ic = scalpTris[lo*3+2];
      let u1 = rng.random(), u2 = rng.random();
      if(u1 + u2 > 1){ u1 = 1 - u1; u2 = 1 - u2; }
      const w = [1 - u1 - u2, u1, u2];
      const pa = V(ia), pb = V(ib), pc = V(ic);
      let root = [w[0]*pa[0]+w[1]*pb[0]+w[2]*pc[0],
                  w[0]*pa[1]+w[1]*pb[1]+w[2]*pc[1],
                  w[0]*pa[2]+w[1]*pb[2]+w[2]*pc[2]];
      const na = Nv(ia), nb = Nv(ib), nc = Nv(ic);
      const nrm = norm([w[0]*na[0]+w[1]*nb[0]+w[2]*nc[0],
                        w[0]*na[1]+w[1]*nb[1]+w[2]*nc[1],
                        w[0]*na[2]+w[1]*nb[2]+w[2]*nc[2]]);

      const zf = (root[2] - zB) / ((zF - zB) || 1);
      const fall = smooth((.80 - zf) / .34);
      const L = o.length * lenK * (.28 + .72 * fall) * rng.uniform(.80, 1.18);
      const segs = Math.max(3, Math.floor(o.segs * (.55 + .45 * fall)));

      let side = norm(cross(nrm, [0,1,0]));
      if(side[0]*side[0]+side[1]*side[1]+side[2]*side[2] < 1e-6) side = [1,0,0];
      let away = sub(root, crown);
      const dn = away[0]*nrm[0] + away[1]*nrm[1] + away[2]*nrm[2];
      away = [away[0]-nrm[0]*dn, away[1]-nrm[1]*dn, away[2]-nrm[2]*dn];
      if(away[0]*away[0]+away[1]*away[1]+away[2]*away[2] < 1e-5)
        away = [root[0]-crown[0], 0, root[2]-crown[2]];
      if(away[0]*away[0]+away[1]*away[1]+away[2]*away[2] < 1e-8) away = side.slice();
      away = norm(away);
      let d = norm(add(mul(away, .78), mul(nrm, .22)));
      const off = shell + (k % 97) * .00035;
      root = add(root, mul(nrm, off));
      let p = root.slice();
      let frame = norm(cross(d, nrm));
      if(frame[0]*frame[0]+frame[1]*frame[1]+frame[2]*frame[2] < 1e-6) frame = side.slice();
      const ph = rng.uniform(0, 6.283);
      const wid = o.width * widK * rng.uniform(.75, 1.3);
      const base = P.length / 3;
      let uslice = 0;
      for(let s = 0; s <= segs; s++){
        const t = s / segs;
        const g = smooth(t / .40) * o.gravity;
        d = norm(lerp3(d, [0,-1,0], g * .62));
        d = add(d, mul(norm([Math.cos(ph + t*5.0), 0, Math.sin(ph + t*5.0)]), o.curl * .22));
        d = norm(d);
        if(s > 0){
          p = add(p, mul(d, L / segs));
          const push = o.volume * Math.sin(Math.min(1, t * 1.9) * Math.PI) * .16;
          p = add(p, mul(nrm, push));
        }
        const dp = frame[0]*d[0] + frame[1]*d[1] + frame[2]*d[2];
        let sd = [frame[0]-d[0]*dp, frame[1]-d[1]*dp, frame[2]-d[2]*dp];
        if(sd[0]*sd[0]+sd[1]*sd[1]+sd[2]*sd[2] < 1e-8) sd = side.slice();
        sd = norm(sd); frame = sd;
        const geo = norm(cross(sd, d));
        const fn = norm([nrm[0]*.72+geo[0]*.28, nrm[1]*.72+geo[1]*.28, nrm[2]*.72+geo[2]*.28]);
        const half = wid * (1 - .62 * Math.pow(t, 1.4)) * .5;
        if(s === 0) uslice = rng.randrange(slices);
        const u0 = uslice / slices, u1b = (uslice + 1) / slices;
        const shade = .34 + .46 * Math.pow(t, .8);
        for(const [sgn, uu] of [[-1, u0], [1, u1b]]){
          const q = add(p, mul(sd, sgn * half));
          P.push(q[0], q[1], q[2]);
          N.push(fn[0], fn[1], fn[2]);
          UV.push(uu, t);
          C.push(shade, shade, shade);
        }
        if(s > 0){
          const i0 = base + (s-1)*2;
          TRI.push(i0, i0+2, i0+1, i0+1, i0+2, i0+3);
        }
      }
    }
  }
  if(!P.length) return null;
  return { position: P, normal: N, uv: UV, color: C, index: TRI, n: P.length/3 };
}

/* The cut the cards grow out of. A port of hair.scalp(). */
export function scalp(pos, index, nrm, eyeY, opts){
  const o = opts || {};
  const n = pos.length / 3;
  let yTop = -Infinity;
  for(let i = 0; i < n; i++) if(pos[i*3+1] > yTop) yTop = pos[i*3+1];
  let zMin = Infinity, zMax = -Infinity, xMax = 0;
  for(let i = 0; i < n; i++){
    if(pos[i*3+1] <= yTop - 2.4) continue;
    const z = pos[i*3+2], x = Math.abs(pos[i*3]);
    if(z < zMin) zMin = z; if(z > zMax) zMax = z; if(x > xMax) xMax = x;
  }
  xMax = xMax || 1;
  const front = o.front == null ? .80 : o.front, lift = o.lift || 0;
  const hairline = i => {
    const zf = Math.max(0, Math.min(1, (pos[i*3+2] - zMin) / ((zMax - zMin) || 1)));
    return eyeY + front * Math.pow(zf, 1.5) - .06 + lift - .20 * (Math.abs(pos[i*3]) / xMax);
  };
  const sc = new Uint8Array(n);
  for(let i = 0; i < n; i++) sc[i] = pos[i*3+1] > hairline(i) ? 1 : 0;
  const map = new Int32Array(n).fill(-1);
  const P = [], NN = [], TRI = [];
  const thick = o.thick == null ? .045 : o.thick;
  for(let f = 0; f < index.length; f += 3){
    const a = index[f], b = index[f+1], c = index[f+2];
    if(!(sc[a] && sc[b] && sc[c])) continue;
    for(const i of [a, b, c]){
      if(map[i] < 0){
        map[i] = P.length / 3;
        P.push(pos[i*3] + nrm[i*3]*thick,
               pos[i*3+1] + nrm[i*3+1]*thick,
               pos[i*3+2] + nrm[i*3+2]*thick);
        NN.push(nrm[i*3], nrm[i*3+1], nrm[i*3+2]);
      }
    }
    TRI.push(map[a], map[b], map[c]);
  }
  if(!TRI.length) return null;
  return { pos: P, nrm: NN, tris: TRI };
}
