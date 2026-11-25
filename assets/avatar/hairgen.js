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

/* Narrower cards than the first pass, and more of them. A card .165
   wide is an eighth of the width of the head: at the root, where the
   clump texture is at full width, it draws a rectangle, and a head of
   hair made of rectangles is a head of hair you can count the pieces
   of. Halving the width and raising the count costs about ten thousand
   vertices and buys hair with no countable pieces in it. */
export const STYLISED = {
  long:  {cards:980, length:2.05, segs:12, width:.100, gravity:.90, volume:.26, curl:.06},
  bob:   {cards:900, length:1.00, segs:9,  width:.100, gravity:.94, volume:.22, curl:.05},
  wavy:  {cards:1010,length:1.80, segs:13, width:.106, gravity:.84, volume:.30, curl:.18},
  curls: {cards:1120,length:1.10, segs:13, width:.090, gravity:.60, volume:.40, curl:.46},
  short: {cards:840, length:0.56, segs:8,  width:.092, gravity:.82, volume:.28, curl:.10},
  crop:  {cards:740, length:0.34, segs:7,  width:.088, gravity:.76, volume:.24, curl:.12},
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
  /* Four passes. A dense short under-layer that fills; the main cards
     that carry the shape; a thin scatter of long flyaways sitting
     further off the scalp, which is what stops the silhouette reading as
     a moulded shell; and short baby hairs at the front hairline, because
     the hard line where the cap meets the forehead is the single thing
     that most gives a wig away.
       count, length x, width x, shell offset, mode
       mode 0 normal  1 flyaway  2 baby hair at the hairline            */
  const LAYERS = [[Math.floor(o.cards * .60), .34, .80, .004, 0],
                  [o.cards,                   1.0, 1.0, .040, 0],
                  [Math.floor(o.cards * .08), .80,  .38, .062, 1],
                  [Math.floor(o.cards * .16), .10,  .34, .012, 2]];
  for(const [count, lenK, widK, shell, mode] of LAYERS){
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
      if(mode === 2 && zf < .76) continue;     // baby hairs: the front only
      // and no flyaway over the face: at this length one reads as a
      // scratch across the picture rather than as a loose hair
      if(mode === 1 && zf > .74) continue;
      const fall = smooth((.80 - zf) / .34);
      let L = o.length * lenK * (.28 + .72 * fall) * rng.uniform(.80, 1.18);
      if(mode === 2) L = o.length * lenK * rng.uniform(.70, 1.55);
      if(mode === 1) L *= rng.uniform(.85, 1.35);
      const segs = mode === 2 ? 3
                 : Math.max(3, Math.floor(o.segs * (.55 + .45 * fall)));

      let side = norm(cross(nrm, [0,1,0]));
      if(side[0]*side[0]+side[1]*side[1]+side[2]*side[2] < 1e-6) side = [1,0,0];
      let away = sub(root, crown);
      const dn = away[0]*nrm[0] + away[1]*nrm[1] + away[2]*nrm[2];
      away = [away[0]-nrm[0]*dn, away[1]-nrm[1]*dn, away[2]-nrm[2]*dn];
      if(away[0]*away[0]+away[1]*away[1]+away[2]*away[2] < 1e-5)
        away = [root[0]-crown[0], 0, root[2]-crown[2]];
      if(away[0]*away[0]+away[1]*away[1]+away[2]*away[2] < 1e-8) away = side.slice();
      away = norm(away);
      /* Hair that grows in front of the crown wants to fall straight
         forward, which puts a curtain over the face -- the first version
         had a fringe down to the nose on every style. Past the crown the
         fall is bent outward instead, which is what a parting is, and
         shortened, because the front of a head of hair is always the
         short part of it. */
      const fr = smooth((zf - .55) / .45);
      if(fr > 0){
        away = norm([away[0] * (1 + 2.1 * fr), away[1], away[2] * (1 - .88 * fr)]);
        // and no two of them the same length, or the fringe is a bowl cut
        L *= (1 - .42 * fr) * (1 + fr * rng.uniform(-.38, .38));
      }
      let d = norm(add(mul(away, .78), mul(nrm, .22)));
      if(mode === 1){
        // a flyaway leaves at its own angle, which is the whole point of it
        d = norm([d[0] + rng.uniform(-.42, .42), d[1] + rng.uniform(-.14, .34),
                  d[2] + rng.uniform(-.42, .42)]);
      } else if(mode === 2){
        // baby hairs lie back along the forehead rather than standing up
        d = norm([d[0] * .5 + rng.uniform(-.5, .5), d[1] * .3 - rng.uniform(.0, .35),
                  d[2] * .5 - .55]);
      }
      const off = shell + (k % 97) * .00035;
      root = add(root, mul(nrm, off));
      let p = root.slice();
      let frame = norm(cross(d, nrm));
      if(frame[0]*frame[0]+frame[1]*frame[1]+frame[2]*frame[2] < 1e-6) frame = side.slice();
      const ph = rng.uniform(0, 6.283);
      const wid = o.width * widK * rng.uniform(.75, 1.3);
      // Hair is not one colour. Letting each card sit a little lighter or
      // darker than its neighbours does more for the look of it than any
      // amount of shader work, because it is what the eye reads as depth.
      const bright = rng.uniform(.78, 1.22);
      const base = P.length / 3;
      let uslice = 0;
      for(let s = 0; s <= segs; s++){
        const t = s / segs;
        const g = smooth(t / .40) * (mode === 1 ? o.gravity * .55
                                     : mode === 2 ? o.gravity * .30 : o.gravity);
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
        const shade = (.30 + .38 * Math.pow(t, .8)) * bright;
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
  /* A hairline is not a drawn curve. Cutting the cap on a smooth
     function of position gives one, and a smooth dark edge across the
     forehead is what makes a head of hair look like a swimming cap. The
     threshold is jittered per vertex instead, by about one ring of the
     mesh, so the cap ends raggedly -- and because the cards are grown
     from the cap, the hair itself ends raggedly with it.

     The jitter is hashed from the vertex INDEX, not its position: the
     index is the same integer in the build and in the browser, where a
     position is the same float only as long as every morph before it
     agrees to the last bit. */
  const ragged = o.ragged == null ? .13 : o.ragged;
  const sc = new Uint8Array(n);
  for(let i = 0; i < n; i++){
    let h = Math.imul(i ^ 0x9e3779b9, 2246822507);
    h = Math.imul(h ^ (h >>> 13), 3266489909);
    h = (h ^ (h >>> 16)) >>> 0;
    const j = (h / 4294967296 - .5) * ragged;
    sc[i] = pos[i*3+1] > hairline(i) + j ? 1 : 0;
  }
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

/* ------------------------------------------------------------------ brows

   An eyebrow painted into the skin texture is a smudge. It has to be,
   because the head's UV island gives the brow about thirty texels across
   its thickness, and because a painted brow has no edge of its own -- the
   thing that makes a real brow read as hair is that its outline is made
   of the individual hairs sticking out of it.

   So the brows are geometry: around seventy tapered strands a side, lying
   on the skin, sweeping up at the inner end, along the arch through the
   body, and out and down at the tail. They cost about twelve hundred
   vertices for the pair, which is nothing next to the thirteen thousand
   the body already spends, and they pick up the key light like hair
   instead of staying flat like paint.

   The strands are seated by SAMPLING the head's own surface rather than
   by snapping to the nearest vertex: a Gaussian-weighted average of the
   vertices around a target (x, y) gives a smooth position and normal
   anywhere along the brow, where nearest-vertex snapping would quantise
   seventy strands onto the ten or so vertices the mesh actually has
   there and give a brow built out of steps. */
function browSampler(pos, nrm, e, g){
  const sgn = e[0] >= 0 ? 1 : -1;
  const n = pos.length / 3, idx = [];
  for(let i = 0; i < n; i++){
    const x = pos[i*3], y = pos[i*3+1], z = pos[i*3+2];
    if(x * sgn < -.06 * g) continue;            // this side of the face
    if(Math.abs(x) > 1.45 * g) continue;
    if(y < e[1] - .10 * g || y > e[1] + 1.15 * g) continue;
    if(z < e[2] - .80 * g) continue;            // not the back of the skull
    idx.push(i);
  }
  const hx = .17 * g, hy = .14 * g;
  return (tx, ty) => {
    let W = 0, px = 0, py = 0, pz = 0, nx = 0, ny = 0, nz = 0;
    for(const i of idx){
      const dx = (pos[i*3] - tx) / hx, dy = (pos[i*3+1] - ty) / hy;
      const w = Math.exp(-(dx*dx + dy*dy));
      if(w < 1e-4) continue;
      W += w;
      px += pos[i*3]*w; py += pos[i*3+1]*w; pz += pos[i*3+2]*w;
      nx += nrm[i*3]*w; ny += nrm[i*3+1]*w; nz += nrm[i*3+2]*w;
    }
    if(!W) return null;
    const P = [px/W, py/W, pz/W], N = norm([nx/W, ny/W, nz/W]);
    /* A weighted average of points on a curved surface lands INSIDE it.
       The bias is small -- a fraction of the kernel width -- but so is
       the height a brow hair sits at, and the first version of this had
       the whole brow submerged in the forehead. So measure how far the
       real vertices stand proud of the averaged point along the normal,
       and seat the hairs above THAT rather than above the average. */
    let out = 0;
    for(const i of idx){
      const dx = (pos[i*3] - tx) / hx, dy = (pos[i*3+1] - ty) / hy;
      if(dx*dx + dy*dy > 4) continue;
      const d = (pos[i*3]-P[0])*N[0] + (pos[i*3+1]-P[1])*N[1] + (pos[i*3+2]-P[2])*N[2];
      if(d > out) out = d;
    }
    return { p:[P[0] + N[0]*out, P[1] + N[1]*out, P[2] + N[2]*out], n:N };
  };
}

export function buildBrows(pos, nrm, eL, eR, seed, opt){
  const o = opt || {};
  const g = Math.abs(eL[0] - eR[0]) || 1;
  const P = [], N = [], C = [], TRI = [];
  for(const e of [eL, eR]){
    const sample = browSampler(pos, nrm, e, g);
    if(!sample) continue;
    const sgn = e[0] >= 0 ? 1 : -1;
    // the same stream both sides: a face whose brows disagree looks wrong
    // in a way nobody can name but everybody sees.
    const rng = new RNG(seed == null ? 11 : seed);
    const nHair = o.hairs || 160;
    const xIn = .18 * g, xOut = .93 * g;      // inner and outer ends, in |x|
    const arch = s => e[1] + (.385 + .100 * Math.sin(Math.PI * Math.pow(s, .85))) * g;
    const ax = s => sgn * (xIn + (xOut - xIn) * s);
    const thick = s => (.072 - .040 * Math.pow(s, 1.5)) * g;
    for(let k = 0; k < nHair; k++){
      /* Hairs are spaced so the HEAD of the brow is the dense end. Laying
         them out evenly along s does the opposite: the head is the thick
         part, so an even count spread across a thicker band comes out
         sparser than the thin tail, and the brow ends up as a dark comma
         pointing at the nose -- the wrong way round. */
      const s = Math.pow((k + rng.uniform(.1, .9)) / nHair, 1.32);
      // across the brow, densest along its spine
      const u = (rng.random() + rng.random() - 1) * thick(s) + .14 * thick(s);
      const seat = sample(ax(s), arch(s) + u);
      if(!seat) continue;
      const nS = seat.n;
      // the along-brow tangent, from the brow's own line
      const ds = .02;
      const a = sample(ax(Math.max(0, s - ds)), arch(Math.max(0, s - ds)) + u);
      const b = sample(ax(Math.min(1, s + ds)), arch(Math.min(1, s + ds)) + u);
      if(!a || !b) continue;
      let T = norm(sub(b.p, a.p));
      let U = norm(cross(nS, T));               // across the brow, roughly up
      if(U[1] < 0) U = mul(U, -1);
      // inner hairs sweep up, the body lies along the arch, the tail drops
      const sweep = (.62 - 1.00 * s) * (1 + rng.uniform(-.13, .13)) - .04;
      let d = norm(add(mul(T, Math.cos(sweep)), mul(U, Math.sin(sweep))));
      const L = (.105 - .042 * Math.pow(s, 1.4)) * g * rng.uniform(.72, 1.22);
      const wid = (.0135 + .0055 * (1 - s)) * g * rng.uniform(.70, 1.30);
      const bright = rng.uniform(.74, 1.16);
      const segs = 3;
      const base = P.length / 3;
      /* The strand is grown straight from where it is seated, with a
         slight droop, and NOT re-seated against the surface at every
         step. Re-seating was the obvious thing to do -- the forehead is
         curved, so follow it -- but over a hair this short the surface
         drops by about a tenth of a millimetre, while a re-seat that
         lands on the eyelid instead of the ridge moves the step by more
         than the step itself. The first version did exactly that and the
         brows crawled down over the eyes. */
      const nn = nS;
      for(let i = 0; i <= segs; i++){
        const t = i / segs;
        const lift = (.012 + .026 * t) * g;
        const p = add(add(add(seat.p, mul(d, L * t)), mul(U, -.09 * L * t * t)),
                      mul(nn, lift));
        const side = norm(cross(nn, d));
        const half = wid * (1 - .85 * Math.pow(t, 1.1)) * .5;
        const shade = bright * (.62 + .34 * Math.pow(t, .7));
        for(const sg of [-1, 1]){
          const q = add(p, mul(side, sg * half));
          P.push(q[0], q[1], q[2]);
          N.push(nn[0], nn[1], nn[2]);
          C.push(shade, shade, shade);
        }
        if(i > 0){
          const i0 = base + (i-1)*2;
          TRI.push(i0, i0+2, i0+1, i0+1, i0+2, i0+3);
        }
      }
    }
  }
  if(!P.length) return null;
  return { position:P, normal:N, color:C, index:TRI, n:P.length/3 };
}

/* ---------------------------------------------------------------- lashes

   The lash line was a dark smear in the skin texture, which is enough at
   thumbnail size and nothing like enough once the figure can be turned
   and looked at. Real lashes are what make an eye read as wet and alive:
   they break the hard edge of the lid and they cast the shadow that
   stops the eyeball looking like a bead glued into a socket.

   The base mesh already carries the lid rims as helper groups -- two
   strips per eye, one above and one below -- so the lashes do not have
   to be guessed at: they are rooted on the mesh's own rim and grown out
   and forward from it. */
export function buildLashes(hp, groups, c, r, seed, sgn){
  const P = [], N = [], C = [], TRI = [];
  const rng = new RNG(seed == null ? 17 : seed);
  for(const g of groups){
    const ids = g.ids;
    if(!ids || ids.length < 6) continue;
    /* The strip is ordered ACROSS the face, not by angle around the eye.
       Angle was the first thing tried and it fans the lashes out like a
       sea urchin, because an eye seen from the front is not a circle and
       the corners of the lid are not at the angles a circle would put
       them. Sorting on x is the lid's own parameter. */
    const pts = [];
    for(const i of ids) pts.push([hp[i*3], hp[i*3+1], hp[i*3+2]]);
    let xMin = Infinity, xMax = -Infinity;
    for(const p of pts){ if(p[0] < xMin) xMin = p[0]; if(p[0] > xMax) xMax = p[0]; }
    const span = (xMax - xMin) || 1;
    const n = g.count;
    for(let k = 0; k < n; k++){
      const t0 = (k + rng.uniform(.12, .88)) / n;
      const x = xMin + span * t0;
      /* The rim at this x. The helper strip is a BAND around the lid,
         and the lashes grow from its inner edge -- the lid margin, the
         edge nearest the eye opening -- not from its outer one. Rooting
         them on the outer edge puts the whole fringe a lid's width up
         the eyelid, which looks like lashes drawn on by someone who has
         not met an eye. */
      let best = null, bw = span / n * 2.2;
      for(const p of pts){
        if(Math.abs(p[0] - x) > bw) continue;
        const e = g.upper ? -p[1] : p[1];
        if(!best || e > best.e) best = { p, e };
      }
      if(!best) continue;
      const root = best.p;
      // out of the lid, forward off the face, and swept toward the outer
      // corner -- which is the single thing that makes lashes read as
      // lashes rather than as a row of pins
      const outward = g.upper ? 1 : -1;
      const toOuter = (sgn >= 0 ? 1 : -1);
      const away = Math.max(0, (sgn >= 0 ? t0 : 1 - t0) - .18) / .82;
      let d = norm([toOuter * g.sweep * away, outward * g.up, g.fwd]);
      const mid = 1 - Math.abs(t0 - .55) * 2;
      const L = r * g.len * (.20 + .80 * Math.max(0, mid)) * rng.uniform(.80, 1.20);
      const wid = r * g.wid * rng.uniform(.70, 1.30);
      const nn = [0, 0, 1];
      const curl = g.curl;
      const segs = 3;
      const base = P.length / 3;
      for(let i = 0; i <= segs; i++){
        const t = i / segs;
        const p = [root[0] + d[0]*L*t,
                   root[1] + d[1]*L*t + outward * curl * L * t * t,
                   root[2] + d[2]*L*t];
        let side = norm(cross(nn, d));
        if(!isFinite(side[0])) side = [1,0,0];
        const half = wid * (1 - .90 * Math.pow(t, 1.2)) * .5;
        const shade = .30 + .34 * t;
        for(const sg of [-1, 1]){
          const q = add(p, mul(side, sg * half));
          P.push(q[0], q[1], q[2]);
          N.push(nn[0], nn[1], nn[2]);
          C.push(shade, shade, shade);
        }
        if(i > 0){
          const i0 = base + (i-1)*2;
          TRI.push(i0, i0+2, i0+1, i0+1, i0+2, i0+3);
        }
      }
    }
  }
  if(!P.length) return null;
  return { position:P, normal:N, color:C, index:TRI, n:P.length/3 };
}
