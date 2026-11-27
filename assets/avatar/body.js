/* Anatomy on the client: a port of tools/avatar3d/anat.py.

   The landmark SCAN is not ported, and deliberately so. anat.reference()
   measures the neutral body once and keeps its landmarks as vertex
   indices, precisely because an extreme morph target moves the geometry
   enough that scanning again latches the armpit onto a hip. Those
   indices are the same for every member, so they are baked into the
   bundle and read here. The browser never runs the scan, which also
   means the build and the browser cannot disagree about where the
   shoulder is. */
const smooth = t => { t = t < 0 ? 0 : t > 1 ? 1 : t; return t*t*(3-2*t); };

export function makeBody(B, v){
  const n = v.length / 3;
  let top = -Infinity, bot = Infinity;
  for(let i = 0; i < n; i++){
    const y = v[i*3+1];
    if(y > top) top = y;
    if(y < bot) bot = y;
  }
  const h = top - bot;
  const R = B.head.ref;
  const sp = {};
  for(const s of ['1','-1']){
    const i = B.back[R.shoulder[s]];
    sp[s] = [v[i*3], v[i*3+1], v[i*3+2]];
  }
  const sx = (Math.abs(sp['1'][0]) + Math.abs(sp['-1'][0])) / 2;
  const sy = (sp['1'][1] + sp['-1'][1]) / 2;
  const sz = (sp['1'][2] + sp['-1'][2]) / 2;
  const axis = {};
  for(const s of ['1','-1']){
    const ti = B.back[R.tip[s]];
    const S = sp[s];
    const d = [v[ti*3]-S[0], v[ti*3+1]-S[1], v[ti*3+2]-S[2]];
    const L = Math.hypot(d[0], d[1], d[2]) || 1;
    axis[s] = { S, d: [d[0]/L, d[1]/L, d[2]/L], L };
  }
  const body = {
    v, n, top, bot, h, sx, sy, sz, axis,
    index: B.index,            // the posing relax needs the mesh's own edges
    uv: B.uv,                  // garments cut from the body inherit its UVs
    cls: B.cls,                       // index -> [t, r] from the bundle
    neckY: bot + R.neckFrac * h,
    neck: [0, sy + 0.30, sz + 0.12],
    frac(i){ return (v[i*3+1] - bot) / h; },
    fracP(p){ return (p[1] - bot) / h; },
    neckDist(p){
      const nk = this.neck;
      return Math.hypot(p[0]-nk[0], p[1]-nk[1], (p[2]-nk[2])*0.85);
    },
    /* Posing needs a wide, soft notion of 'arm' so the deformation has no
       cliff in it; clothing needs a narrow, hard one, or a shirt rejects
       half the chest for being within two units of the arm's axis. */
    limb(i){
      const a = this.cls[i];
      if(!a) return null;
      if(a[1] > 1.05 || a[0] < 0.30) return null;
      return a;
    },
  };
  return body;
}

/* Swing each arm about its own shoulder so it hangs at the side. The
   weight falls off in BOTH t and r: a hard radius cut left a vertex just
   inside the arm rotating the whole way while its neighbour just outside
   did not move at all, and the faces spanning that line stretched into a
   funnel that showed through any clothing built on top. */
/* Swing each arm about its own shoulder so it hangs at the side.

   The weight is computed from the arm axis and then SMOOTHED OVER THE
   MESH. That second step is the one that matters. Any weight taken from
   a distance to an axis has a cliff in it somewhere -- the vertices just
   outside the arm's tube sit next to vertices just inside it, and the
   faces that span the two stretch into the pointed wedges that showed up
   at the elbows and read as triangular flaps. Narrowing the tube makes
   the cliff sharper and the wedges BIGGER, which is how I found out the
   radius was never the problem. Relaxing the weight field across the
   mesh removes the cliff wherever it happens to be. */
export function armsDown(B, degrees, opt){
  const o = opt || {};
  const a0deg = (degrees == null ? 34.0 : degrees) * Math.PI / 180;
  const v = B.v;
  const n = B.n;
  const passes = o.relaxPasses == null ? 14 : o.relaxPasses;
  const outX = o.outX == null ? 0.22 : o.outX;
  const outZ = o.outZ == null ? 0.38 : o.outZ;

  if(!B.adj) B.adj = buildAdjacency(B.index, n);
  const adj = B.adj;

  for(const s of ['1','-1']){
    const side = s === '1' ? 1 : -1;
    const { S } = B.axis[s];
    const a0 = a0deg * (-side);

    let w = new Float32Array(n);
    const along = new Float32Array(n);
    for(const key in B.cls){
      const i = +key;
      if(v[i*3] * side <= 0) continue;
      const a = B.cls[i];
      w[i] = smooth((a[0] + 0.35) / 1.15) * smooth((1.75 - a[1]) / 0.75);
      along[i] = a[0];
    }
    // only this side takes part in the relax, or the two arms bleed into
    // each other across the chest
    const mine = new Uint8Array(n);
    for(let i = 0; i < n; i++) mine[i] = v[i*3] * side > 0 ? 1 : 0;
    let next = new Float32Array(n);
    for(let p = 0; p < passes; p++){
      for(let i = 0; i < n; i++){
        if(!mine[i]){ next[i] = 0; continue; }
        const a = adj.start[i], b = adj.start[i+1];
        if(b <= a){ next[i] = w[i]; continue; }
        let sum = w[i], cnt = 1;
        for(let k = a; k < b; k++){ const j = adj.list[k]; if(!mine[j]) continue; sum += w[j]; cnt++; }
        next[i] = sum / cnt;
      }
      const t = w; w = next; next = t;
    }
    for(let i = 0; i < n; i++){
      const wi = w[i];
      if(wi <= 0.001) continue;
      const ang = a0 * wi;
      const rx = v[i*3] - S[0], ry = v[i*3+1] - S[1];
      v[i*3]   = S[0] + rx*Math.cos(ang) - ry*Math.sin(ang);
      v[i*3+1] = S[1] + rx*Math.sin(ang) + ry*Math.cos(ang);
      // Arms hang in front of the body, not inside it. Rotating in the XY
      // plane alone leaves the forearm at the torso's own depth and the
      // elbow ends up buried in the hip.
      const down = smooth((along[i] - 0.8) / 2.2);
      v[i*3]   += side * outX * wi * down;
      v[i*3+2] += outZ * wi * down;
    }
  }
}

function buildAdjacency(index, n){
  const count = new Uint32Array(n + 1);
  const bump = (a, b) => { count[a]++; count[b]++; };
  for(let f = 0; f < index.length; f += 3){
    bump(index[f], index[f+1]); bump(index[f+1], index[f+2]); bump(index[f+2], index[f]);
  }
  const start = new Uint32Array(n + 1);
  let run = 0;
  for(let i = 0; i <= n; i++){ start[i] = run; run += count[i]; }
  const list = new Uint32Array(run);
  const at = start.slice();
  const put = (a, b) => { list[at[a]++] = b; list[at[b]++] = a; };
  for(let f = 0; f < index.length; f += 3){
    put(index[f], index[f+1]); put(index[f+1], index[f+2]); put(index[f+2], index[f]);
  }
  return { start, list };
}

/* Cut a garment along a smooth isoline instead of along face boundaries.
   A port of cutter.shell(). Choosing whole faces is why every hem came
   out stepped: the edge could only follow the edges of the mesh. */
/* The surface a garment is cut from, with the skin's own detail taken
   out of it.

   A garment built as an offset of the body reproduces the body exactly,
   because that is what an offset is -- so the tee came out with nipples,
   a navel and a set of ribs printed through it, which reads as a wet
   shirt rather than as cotton. Cloth spans between the high points of a
   body; it does not sink into every dimple two vertices wide.

   Taubin smoothing rather than plain Laplacian: a Laplacian pass shrinks
   whatever it smooths, and shrinking the base is the one thing that must
   not happen here, because the garment would then be cut from a surface
   INSIDE the skin and the body would show through it. The alternating
   positive and negative steps take out the high frequencies and leave
   the shape -- the breast stays, the nipple goes.

   Cached on the body, because every garment on a figure wants the same
   one and the smoothing is the expensive part. */
export function drapeBase(B, index){
  if(B.drapeV) return { v: B.drapeV, n: B.drapeN };
  const n = B.n, v = B.v;
  if(!B.adj) B.adj = buildAdjacency(index, n);
  const adj = B.adj;
  let P = Float32Array.from(v);
  const Q = new Float32Array(P.length);
  const step = k => {
    for(let i = 0; i < n; i++){
      const a = adj.start[i], b = adj.start[i+1];
      if(b <= a){ Q[i*3]=P[i*3]; Q[i*3+1]=P[i*3+1]; Q[i*3+2]=P[i*3+2]; continue; }
      let sx=0, sy=0, sz=0, c=0;
      for(let t = a; t < b; t++){ const j = adj.list[t]; sx+=P[j*3]; sy+=P[j*3+1]; sz+=P[j*3+2]; c++; }
      sx/=c; sy/=c; sz/=c;
      Q[i*3]   = P[i*3]   + (sx - P[i*3])   * k;
      Q[i*3+1] = P[i*3+1] + (sy - P[i*3+1]) * k;
      Q[i*3+2] = P[i*3+2] + (sz - P[i*3+2]) * k;
    }
    P.set(Q);
  };
  /* Plain Laplacian, and the shrink is simply paid for in the garment's
     offset rather than compensated away.

     Taubin smooths without shrinking, which sounds like exactly what is
     wanted, but its negative step overshoots wherever the curvature is
     extreme and the most extreme curvature on a body is the tip of a
     nipple: it came back as a spike through the shirt. Adding the shrink
     back per vertex is worse still -- the shrink is LARGEST at a nipple,
     so adding it back pushes a cone straight out through the cloth.
     Here nothing is added back: the surface sinks by at most a tenth of
     a unit, every garment stands further off than that, and what the
     cloth is cut from is smooth. */
  for(let pass = 0; pass < 9; pass++) step(.58);
  const N = new Float32Array(P.length);
  for(let f = 0; f < index.length; f += 3){
    const a=index[f]*3, b=index[f+1]*3, c=index[f+2]*3;
    const ux=P[b]-P[a], uy=P[b+1]-P[a+1], uz=P[b+2]-P[a+2];
    const wx=P[c]-P[a], wy=P[c+1]-P[a+1], wz=P[c+2]-P[a+2];
    const nx=uy*wz-uz*wy, ny=uz*wx-ux*wz, nz=ux*wy-uy*wx;
    N[a]+=nx; N[a+1]+=ny; N[a+2]+=nz;
    N[b]+=nx; N[b+1]+=ny; N[b+2]+=nz;
    N[c]+=nx; N[c+1]+=ny; N[c+2]+=nz;
  }
  for(let i = 0; i < N.length; i += 3){
    const L = Math.hypot(N[i],N[i+1],N[i+2]) || 1;
    N[i]/=L; N[i+1]/=L; N[i+2]/=L;
  }
  /* and then the smoothed surface is pushed back out, by how far it sank
     -- but by a BLURRED version of how far, not the raw amount and not
     one number for the whole body.

     Smoothing a surface is shrinking it; that is how the nipple goes
     away. So the draped base ends up inside the skin and a garment
     offset from it has the body coming through at every place that sank
     more than the cloth is thick. Adding the raw sink back per vertex
     just rebuilds the feature, because the sink is largest exactly where
     the feature was. One constant for the whole body avoids that, and
     was what this did -- but the constant is the largest sink anywhere,
     which is a fingertip or the tip of the nose, and applying a
     fingertip's worth of ease to an upper arm inflates it by half its
     own radius. That is what turned every sleeve into a bat wing: not
     the sleeve, the ease.

     Blurring the sink over the surface keeps both properties. A nipple's
     spike averages away to nothing over a dozen passes, so nothing
     rebuilds it; the torso's broad, gentle sink survives, because it is
     broad. Each part of the body then gets its own ease. */
  const sink = new Float64Array(n);
  for(let i = 0; i < n; i++){
    const d = (v[i*3] - P[i*3]) * N[i*3]
            + (v[i*3+1] - P[i*3+1]) * N[i*3+1]
            + (v[i*3+2] - P[i*3+2]) * N[i*3+2];
    sink[i] = d > 0 ? d : 0;
  }
  {
    const tmp = new Float64Array(n);
    for(let pass = 0; pass < 14; pass++){
      for(let i = 0; i < n; i++){
        const a2 = adj.start[i], b2 = adj.start[i+1];
        if(b2 <= a2){ tmp[i] = sink[i]; continue; }
        let acc = 0;
        for(let t = a2; t < b2; t++) acc += sink[adj.list[t]];
        tmp[i] = sink[i] + (acc / (b2 - a2) - sink[i]) * .70;
      }
      sink.set(tmp);
    }
  }
  const easeMin = 0.020, easeMax = 0.090;
  for(let i = 0; i < n; i++){
    const e = Math.min(easeMax, Math.max(easeMin, sink[i]));
    P[i*3]   += N[i*3]   * e;
    P[i*3+1] += N[i*3+1] * e;
    P[i*3+2] += N[i*3+2] * e;
  }
  /* and then the one rule that cannot be broken: the base may not be
     inside the skin.

     A blurred ease is an average, so wherever the surface sank more than
     the average it is still under the skin, and the garment built on it
     has the body coming through -- a bare patch of shoulder in the
     middle of the shirt, which is worse than any amount of cling. So:
     push any vertex that is inside back out to the skin exactly, smooth,
     and push out again. The clamp wins at the peaks, the smoothing
     spreads what the clamp just rebuilt, and after a few rounds the
     surface lies OVER a nipple the way cloth does rather than denting
     into it or being pierced by it. */
  /* The clamp has to be gentle, and it has to be wide.

     Pushing every vertex that is inside the skin back out to it,
     exactly, rebuilds every rim: the body's navel is a crater, its rim
     protrudes, the rim gets clamped out while the middle stays smooth,
     and the shirt ends up with a dimple in it where the navel is. Two
     changes fix that. The base is allowed to sit a margin INSIDE the
     skin -- it only has to stay within a garment's thickness, because
     the cloth is offset from it -- so small protrusions never trigger
     the clamp at all. And the push is dilated over the mesh before it is
     applied, so where the clamp does fire it lifts a patch rather than a
     point. The raw value is taken back afterwards wherever it was
     larger, so nothing is ever left pierced. */
  /* The clamp measures against the RAW skin, not a smoothed copy of it.

     Smoothing the reference is the obvious way to stop a nipple
     printing through a fitted tee -- take it out of what the clamp
     protects and the cloth closes over it. It was tried at two passes
     and at six, and both let the body through somewhere else: at six
     the shoulder (which at this mesh density is a sharply curved cap,
     not the broad thing it looks) sank under its own smoothing and the
     arm came through the sleeve; at two the knees came through the
     leggings, which are the thinnest garment and have the least to
     spare. One reference serves every garment, so it has to be safe for
     the thinnest. The nipple stays, and the crease darkening under it
     is turned down instead. */
  const push = new Float64Array(n), tmp2 = new Float64Array(n);
  const clampOut = margin => {
    for(let i = 0; i < n; i++){
      const d = (v[i*3] - P[i*3]) * N[i*3]
              + (v[i*3+1] - P[i*3+1]) * N[i*3+1]
              + (v[i*3+2] - P[i*3+2]) * N[i*3+2] - margin;
      push[i] = d > 0 ? d : 0;
    }
    const raw = Float64Array.from(push);
    for(let pass = 0; pass < 2; pass++){          // dilate
      for(let i = 0; i < n; i++){
        let m = push[i];
        for(let t = adj.start[i]; t < adj.start[i+1]; t++){
          const q = push[adj.list[t]]; if(q > m) m = q;
        }
        tmp2[i] = m;
      }
      push.set(tmp2);
    }
    for(let pass = 0; pass < 2; pass++){          // and soften
      for(let i = 0; i < n; i++){
        const a2 = adj.start[i], b2 = adj.start[i+1];
        if(b2 <= a2){ tmp2[i] = push[i]; continue; }
        let acc = 0;
        for(let t = a2; t < b2; t++) acc += push[adj.list[t]];
        tmp2[i] = push[i] + (acc / (b2 - a2) - push[i]) * .5;
      }
      push.set(tmp2);
    }
    for(let i = 0; i < n; i++){
      const e = Math.max(push[i], raw[i]);
      if(e <= 0) continue;
      P[i*3]   += N[i*3]   * e;
      P[i*3+1] += N[i*3+1] * e;
      P[i*3+2] += N[i*3+2] * e;
    }
  };
  /* How far the base may sit inside the skin: comfortably less than the
     THINNEST garment's offset, because one draped base serves them all.
     At .050 against leggings offset .055 there were five thousandths of
     clearance, and the legs came out in patches of bare skin. */
  const MARGIN = 0.030;
  for(let k = 0; k < 4; k++){ clampOut(MARGIN); step(.55); }
  clampOut(MARGIN);
  /* Offsetting a surface outward folds it wherever the surface is
     concave and turns tighter than the offset -- under a breast, in an
     armpit, behind a knee. The fold is a pinch of back-facing cloth and
     it renders as a small black crescent that looks like a tear in the
     shirt. Three more smoothing passes AFTER the lift take the folds
     out: a fold is high frequency, and by this point nothing else of the
     body's detail is left for the smoothing to eat. */
  for(let pass = 0; pass < 3; pass++){ step(.55); clampOut(MARGIN); }
  B.drapeV = P; B.drapeN = N;
  return { v: P, n: N };
}

export function shell(B, index, nrm, field, thick, opt){
  const o = opt || {};
  const smoothN = o.smooth == null ? 2 : o.smooth;
  const relax = o.relax || 0;
  /* The FIELD is read from the real body -- the hem belongs where the
     anatomy says it does -- but the cloth is built on the draped base. */
  const D = o.drape ? drapeBase(B, index) : null;
  const v = D ? D.v : B.v;
  if(D) nrm = D.n;
  const fv = new Map();
  const F = i => {
    let x = fv.get(i);
    if(x === undefined){ x = field(i); fv.set(i, x); }
    return x;
  };
  const P = [], N = [], T = [], UV = [], LIMB = [];
  /* A garment gets its OWN UVs, wrapped round the figure, rather than
     the body's.
       A garment had none at all, which left every map on its material
     sampling a single texel. Handing it the body's atlas was the obvious
     fix and the wrong one: that atlas is cut and packed for a face, so
     its texel density swings wildly, and a weave tiled through it
     bunched into a dense swirl at every singularity -- most visibly one
     right on each nipple, which read as the shirt being moulded round
     them. A cylinder has no such places. The seam is at the back, where
     a garment's seam belongs. */
  const bodyUV = true;
  const cx = (B.sx !== undefined) ? 0 : 0, cz = B.sz || 0;
  const bot = B.bot, hh = B.h || 1;
  const uvOf = p => {
    const a = Math.atan2(p[0] - cx, p[2] - cz);
    return [a / (Math.PI * 2) + .5, (p[1] - bot) / hh];
  };
  const base = new Map(), cut = new Map();
  const put = (p, nn, uu, lb) => {
    P.push(p[0],p[1],p[2]); N.push(nn[0],nn[1],nn[2]);
    if(bodyUV) UV.push(uu[0], uu[1]);
    LIMB.push(lb || 0);
    return P.length/3 - 1;
  };
  /* "Is this an arm" by the SAME measure the sleeve cut uses, not by
     limb(): limb() lets go of the outer surface of a thick upper arm, so
     half a sleeve counted as torso and the drape blew it out into a bat
     wing. */
  const isLimb = i => {
    const a = B.cls && B.cls[i];
    if(a && a[1] <= 1.32) return 1;
    return (B.limb && B.limb(i)) ? 1 : 0;
  };
  const at = i => {
    let x = base.get(i);
    if(x === undefined){
      const pp = [v[i*3], v[i*3+1], v[i*3+2]];
      x = put(pp, [nrm[i*3], nrm[i*3+1], nrm[i*3+2]], uvOf(pp), isLimb(i));
      base.set(i, x);
    }
    return x;
  };
  const onEdge = (i, j) => {
    const key = i < j ? i + ':' + j : j + ':' + i;
    let x = cut.get(key);
    if(x !== undefined) return x;
    const a = F(i), b = F(j);
    let t = (a - b) ? a / (a - b) : 0.5;
    // Keep the cut off the ends of the edge. A crossing at t=0 or t=1
    // puts the new vertex exactly on an existing one and the triangle it
    // makes has no area: those zero-area slivers are the row of bright
    // specks that ran along every hem.
    t = t < 0.06 ? 0.06 : t > 0.94 ? 0.94 : t;
    const pos = [], nn = [];
    for(let k = 0; k < 3; k++){
      pos.push(v[i*3+k] + (v[j*3+k] - v[i*3+k]) * t);
      nn.push(nrm[i*3+k] + (nrm[j*3+k] - nrm[i*3+k]) * t);
    }
    const L = Math.hypot(nn[0],nn[1],nn[2]) || 1;
    x = put(pos, [nn[0]/L, nn[1]/L, nn[2]/L], uvOf(pos), isLimb(i) & isLimb(j));
    cut.set(key, x);
    return x;
  };
  for(let f = 0; f < index.length; f += 3){
    const i = index[f], j = index[f+1], k = index[f+2];
    const a = F(i), b = F(j), c = F(k);
    const inside = [];
    if(a > 0) inside.push(i);
    if(b > 0) inside.push(j);
    if(c > 0) inside.push(k);
    /* Winding has to follow the ORIGINAL triangle's cycle, not the order
       the inside vertices happen to come out of the filter. Taking them
       as [i,j,k] order reverses the piece whenever the odd vertex is the
       middle one -- a third of the cut faces came out inside-out, and
       with DoubleSide they are still drawn, just lit from behind. That
       is the alternating bright-and-dark zigzag along every hem: not the
       boundary wandering, not z-fighting, not the relax. Backfaces. */
    const tri = [i, j, k];
    if(inside.length === 3){
      T.push(at(i), at(j), at(k));
    } else if(inside.length === 1){
      const m = tri.indexOf(inside[0]);
      const p = tri[m], q = tri[(m+1)%3], r = tri[(m+2)%3];
      T.push(at(p), onEdge(p, q), onEdge(p, r));
    } else if(inside.length === 2){
      const m = tri.findIndex(x => inside.indexOf(x) < 0);
      const p = tri[m], q = tri[(m+1)%3], r = tri[(m+2)%3];
      const eq = onEdge(p, q), er = onEdge(p, r);
      T.push(at(q), at(r), er, at(q), er, eq);
    }
  }
  if(!T.length) return null;
  /* The cut edge needs more clearance than the middle of the cloth. A
     boundary vertex sits exactly on the isoline, where the garment and
     the skin are the same surface, so at a uniform offset the skin
     punches through along every hem as a bright ragged rim. Lifting the
     boundary a little further tucks it under instead. */
  const edge = new Map();
  for(let f = 0; f < T.length; f += 3){
    const a=T[f], b=T[f+1], c=T[f+2];
    for(const [i,j] of [[a,b],[b,c],[c,a]]){
      const k = i < j ? i + ':' + j : j + ':' + i;
      edge.set(k, (edge.get(k) || 0) + 1);
    }
  }
  const onEdgeV = new Uint8Array(P.length/3);
  for(const [k, n] of edge){
    if(n !== 1) continue;
    const [i, j] = k.split(':');
    onEdgeV[+i] = 1; onEdgeV[+j] = 1;
  }
  /* Offsetting a surface outward folds it wherever the surface is
     concave and turns tighter than the offset. Under a breast, in an
     armpit, behind a knee, the cloth passes through itself and comes
     back out, and the pinch of back-facing surface renders as a hard
     black crescent -- which the crease darkening then obligingly makes
     darker still, because as far as it can tell that really is a very
     deep fold.

     So the offset is capped by the radius the surface actually turns
     at. The Laplacian gives it: if the neighbours sit a distance d
     above the tangent plane at a mean spacing h, the surface is turning
     at about h squared over 2d, and an offset past half of that is a
     fold. Convex vertices are not capped -- offsetting outward from a
     convex surface never self-intersects. */
  {
    const nV = P.length / 3;
    const cnt = new Uint16Array(nV);
    const sx = new Float64Array(nV), sy = new Float64Array(nV), sz = new Float64Array(nV);
    const sl = new Float64Array(nV);
    const bump = (a, b) => {
      const dx = P[b*3] - P[a*3], dy = P[b*3+1] - P[a*3+1], dz = P[b*3+2] - P[a*3+2];
      sx[a] += dx; sy[a] += dy; sz[a] += dz;
      sl[a] += Math.hypot(dx, dy, dz); cnt[a]++;
    };
    for(let f = 0; f < T.length; f += 3){
      const a = T[f], b = T[f+1], c = T[f+2];
      bump(a,b); bump(b,a); bump(b,c); bump(c,b); bump(c,a); bump(a,c);
    }
    for(let i = 0; i < P.length; i += 3){
      const v3 = i/3;
      let t = typeof thick === 'function' ? thick([P[i], P[i+1], P[i+2]]) : thick;
      if(onEdgeV[v3]) t *= (o.edgeLift == null ? 1.75 : o.edgeLift);
      const c = cnt[v3];
      if(c){
        const d = (sx[v3]*N[i] + sy[v3]*N[i+1] + sz[v3]*N[i+2]) / c;
        if(d > 1e-9){                                   // concave here
          const h = sl[v3] / c;
          const lim = 0.5 * (h * h) / (2 * d);
          if(lim < t) t = lim;
        }
      }
      P[i] += N[i]*t; P[i+1] += N[i+1]*t; P[i+2] += N[i+2]*t;
    }
  }

  /* --------------------------------------------------------- the drape

     Up to here a garment is the body pushed out along its normals, and
     an offset of a surface IS that surface: the shirt had a waist that
     nipped in exactly where the body's did and a bust it was moulded
     round. Smoothing the base (drape:true) takes out the nipple and the
     navel, but it cannot do this, because this is not detail -- it is
     that cloth does not go where the body goes. Cloth hangs. It leaves
     the widest thing above it and falls, and it does not tuck back under
     a bust or into a waist on the way down.

     So, over the part of the garment that hangs: take the silhouette of
     the cloth in horizontal slices, and forbid a slice from being
     narrower than the one above it in any direction. Every vertex inside
     that envelope is pushed out to it. Nothing is ever pulled in, so the
     shoulders and the bust -- where the cloth really is supported and
     really does follow the body -- are left exactly as they were.

     Limb vertices are excluded. A horizontal slice through a figure with
     its arms down is a torso and two arms, and one envelope round all
     three would be a poncho. */
  if(o.slack > 0){
    const nV = P.length / 3;
    const torso = [];
    for(let i = 0; i < nV; i++) if(!LIMB[i]) torso.push(i);
    if(torso.length > 40){
      let yLo = Infinity, yHi = -Infinity;
      for(const i of torso){ const y = P[i*3+1]; if(y < yLo) yLo = y; if(y > yHi) yHi = y; }
      const H = (yHi - yLo) || 1;
      const NB = 44, NS = 28;
      const binOf = y => Math.min(NB - 1, Math.max(0, Math.floor((yHi - y) / H * NB)));
      const cxb = new Float64Array(NB), czb = new Float64Array(NB), cnt = new Float64Array(NB);
      for(const i of torso){
        const b = binOf(P[i*3+1]);
        cxb[b] += P[i*3]; czb[b] += P[i*3+2]; cnt[b]++;
      }
      // a bin with nothing in it borrows the nearest centre above it
      for(let b = 0; b < NB; b++){
        if(cnt[b] > 0){ cxb[b] /= cnt[b]; czb[b] /= cnt[b]; }
        else if(b > 0){ cxb[b] = cxb[b-1]; czb[b] = czb[b-1]; }
      }
      const rad = new Float64Array(NB * NS).fill(-1);
      const sect = (dx, dz) => {
        let a = Math.atan2(dz, dx) / (Math.PI * 2) + .5;
        a -= Math.floor(a);
        return Math.min(NS - 1, Math.floor(a * NS));
      };
      for(const i of torso){
        const b = binOf(P[i*3+1]);
        const dx = P[i*3] - cxb[b], dz = P[i*3+2] - czb[b];
        const r = Math.hypot(dx, dz);
        const sIdx = b * NS + sect(dx, dz);
        if(r > rad[sIdx]) rad[sIdx] = r;
      }
      // fill gaps round each ring, so a missing sector does not read as zero
      for(let b = 0; b < NB; b++){
        for(let pass = 0; pass < 3; pass++){
          for(let k = 0; k < NS; k++){
            const o0 = b*NS + k;
            if(rad[o0] >= 0) continue;
            const a2 = rad[b*NS + (k + NS - 1) % NS], b2 = rad[b*NS + (k + 1) % NS];
            if(a2 >= 0 && b2 >= 0) rad[o0] = (a2 + b2) * .5;
            else if(a2 >= 0) rad[o0] = a2;
            else if(b2 >= 0) rad[o0] = b2;
          }
        }
      }
      /* The rule that makes it cloth: going down, a sector cannot get
         narrower. The carry is a hair under one so a flared hem does not
         keep flaring all the way to the floor. */
      const carry = o.carry == null ? .986 : o.carry;
      for(let b = 1; b < NB; b++){
        for(let k = 0; k < NS; k++){
          const up = rad[(b-1)*NS + k] * carry;
          if(up > rad[b*NS + k]) rad[b*NS + k] = up;
        }
      }
      // round the envelope off, or the slices show as terracing
      const tmp = Float64Array.from(rad);
      for(let b = 0; b < NB; b++) for(let k = 0; k < NS; k++){
        let acc = 0, w = 0;
        for(let db = -1; db <= 1; db++) for(let dk = -1; dk <= 1; dk++){
          const bb = b + db; if(bb < 0 || bb >= NB) continue;
          const kk = (k + dk + NS) % NS;
          const ww = (db === 0 ? 2 : 1) * (dk === 0 ? 2 : 1);
          acc += tmp[bb*NS + kk] * ww; w += ww;
        }
        rad[b*NS + k] = acc / w;
      }
      /* How much of the envelope to take, down the garment: nothing at
         the top, where the cloth sits on the shoulders, everything by
         about two thirds of the way down. */
      const sm = t => { t = t < 0 ? 0 : t > 1 ? 1 : t; return t*t*(3-2*t); };
      for(const i of torso){
        const y = P[i*3+1];
        const f = sm(((yHi - y) / H - .34) / .40) * o.slack;
        if(f <= 0) continue;
        const b = binOf(y);
        const dx = P[i*3] - cxb[b], dz = P[i*3+2] - czb[b];
        const r = Math.hypot(dx, dz);
        if(r < 1e-6) continue;
        const tgt = rad[b*NS + sect(dx, dz)];
        if(tgt <= r) continue;
        const k = (r + (tgt - r) * f) / r;
        P[i*3]   = cxb[b] + dx * k;
        P[i*3+2] = czb[b] + dz * k;
      }
    }
  }

  /* Smooth the hem ALONG ITSELF.

     The cut lands exactly on the isoline, so the boundary is where it
     should be -- but it zigzags, because consecutive cut points sit on
     edges that run in alternating directions across the mesh, and at
     this density the zigzag is a visible sawtooth. Relaxing the garment
     as a whole does not touch it (tried: no change) and nor does
     clamping the crossing parameter (tried: no change), because neither
     is where the zigzag comes from.

     A boundary vertex on a manifold has exactly two boundary
     neighbours, so the hem is a closed loop and can be smoothed as a
     curve in its own right, which leaves the rest of the cloth alone.
     The pull is tangential -- along the chord between the neighbours --
     so the hem stays on the surface it was cut from instead of
     shrinking off it. */
  {
    const nb0 = new Int32Array(P.length/3).fill(-1);
    const nb1 = new Int32Array(P.length/3).fill(-1);
    for(const [k, cnt] of edge){
      if(cnt !== 1) continue;
      const p = k.indexOf(':');
      const i = +k.slice(0, p), j = +k.slice(p + 1);
      if(nb0[i] < 0) nb0[i] = j; else nb1[i] = j;
      if(nb0[j] < 0) nb0[j] = i; else nb1[j] = i;
    }
    const passes = o.hemPasses == null ? 6 : o.hemPasses;
    const k = o.hemPull == null ? 0.55 : o.hemPull;
    for(let pass = 0; pass < passes; pass++){
      const Q = P.slice();
      for(let i = 0; i < P.length/3; i++){
        const a = nb0[i], b = nb1[i];
        if(a < 0 || b < 0) continue;
        for(let c = 0; c < 3; c++){
          const mid = (P[a*3+c] + P[b*3+c]) * 0.5;
          Q[i*3+c] = P[i*3+c] + (mid - P[i*3+c]) * k;
        }
      }
      for(let i = 0; i < P.length; i++) P[i] = Q[i];
    }
  }
  if(relax > 0){
    const adj = new Map();
    const link = (a,b) => { if(!adj.has(a)) adj.set(a, new Set()); adj.get(a).add(b); };
    for(let f = 0; f < T.length; f += 3){
      const a=T[f],b=T[f+1],c=T[f+2];
      link(a,b); link(a,c); link(b,a); link(b,c); link(c,a); link(c,b);
    }
    for(let pass = 0; pass < smoothN; pass++){
      const Q = P.slice();
      for(const [i, nb] of adj){
        if(!nb.size) continue;
        let ax=0, ay=0, az=0;
        for(const j of nb){ ax+=P[j*3]; ay+=P[j*3+1]; az+=P[j*3+2]; }
        ax/=nb.size; ay/=nb.size; az/=nb.size;
        Q[i*3]   = P[i*3]  *(1-relax) + ax*relax;
        Q[i*3+1] = P[i*3+1]*(1-relax) + ay*relax;
        Q[i*3+2] = P[i*3+2]*(1-relax) + az*relax;
      }
      for(let i = 0; i < P.length; i++) P[i] = Q[i];
    }
  }
  /* Shade the cloth with the BODY's normals, not with its own.
     A cut leaves long thin triangles along the hem, and averaging their
     face normals points the edge sideways -- so every hem picked up a row
     of bright specks that look like z-fighting and are not. The body's
     normal at the same point is smooth, and a garment lying on a body
     should catch the light the way the body does. The boundary takes it
     almost entirely; the middle of the cloth keeps most of its own. */
  /* ------------------------------------------------------------ collar
     A band round the neckline, lofted off the boundary loop.

     Two reasons. A tee has one, and a finished edge is the thing that
     most says "garment" rather than "painted on". And the neckline is
     where you could previously see past the cloth to the unlit inside of
     its own back -- the pair of dark wedges either side of the collar
     that looked exactly like holes in the mesh. A band closes that line
     of sight with cloth, which is what a real collar does too.

     Built before the normals are, so the band gets shaded with
     everything else rather than needing its own pass. */
  if(o.collar > 0){
    const nV = P.length / 3;
    const nb0 = new Int32Array(nV).fill(-1), nb1 = new Int32Array(nV).fill(-1);
    for(const [k, cnt] of edge){
      if(cnt !== 1) continue;
      const q = k.indexOf(':');
      const i = +k.slice(0, q), j = +k.slice(q + 1);
      if(nb0[i] < 0) nb0[i] = j; else nb1[i] = j;
      if(nb0[j] < 0) nb0[j] = i; else nb1[j] = i;
    }
    /* EVERY boundary loop gets a band, not just the neckline: the cuffs
       and the hem are finished edges too, and on a sleeveless top so is
       the armhole. The neckline -- the highest loop -- gets the wider
       one, because a collar is wider than a hem. */
    /* for every boundary vertex, a neighbour that is NOT on a boundary:
       that is the direction the cloth lies in, so its opposite is the
       direction the band has to go */
    const onB = new Uint8Array(nV);
    for(let i = 0; i < nV; i++) if(nb0[i] >= 0 && nb1[i] >= 0) onB[i] = 1;
    const inner = new Int32Array(nV).fill(-1);
    for(let f = 0; f < T.length; f += 3){
      const tri = [T[f], T[f+1], T[f+2]];
      for(let a3 = 0; a3 < 3; a3++){
        const i = tri[a3];
        if(!onB[i] || inner[i] >= 0) continue;
        for(let b3 = 0; b3 < 3; b3++){
          const j = tri[b3];
          if(j !== i && !onB[j]){ inner[i] = j; break; }
        }
      }
    }
    const seen = new Uint8Array(nV);
    const loops = [];
    const span = loop => {
      let lo = [Infinity,Infinity,Infinity], hi = [-Infinity,-Infinity,-Infinity];
      for(const v3 of loop) for(let c = 0; c < 3; c++){
        const q = P[v3*3+c]; if(q < lo[c]) lo[c] = q; if(q > hi[c]) hi[c] = q;
      }
      return Math.hypot(hi[0]-lo[0], hi[1]-lo[1], hi[2]-lo[2]);
    };
    for(let i = 0; i < nV; i++){
      if(seen[i] || nb0[i] < 0 || nb1[i] < 0) continue;
      const loop = []; let cur = i, prev = -1, closed = false;
      for(let guard = 0; guard < nV; guard++){
        loop.push(cur); seen[cur] = 1;
        const a2 = nb0[cur], b2 = nb1[cur];
        const nxt = (a2 !== prev && a2 >= 0) ? a2 : b2;
        if(nxt < 0) break;
        if(nxt === i){ closed = true; break; }
        if(seen[nxt]) break;
        prev = cur; cur = nxt;
      }
      if(!closed || loop.length < 8) continue;
      let sy = 0; for(const v3 of loop) sy += P[v3*3+1];
      loops.push({ y: sy / loop.length, loop, d: span(loop) });
    }
    /* Only loops that are actually EDGES get a band. Two boundaries that
       touch at a vertex walk as one figure-of-eight, and lofting a band
       along that draws a ribbon from the cuff all the way down past the
       hip -- which is what it did. A real hem, cuff or neckline is small
       next to the garment it is the edge of; a merged one is not. */
    let gD = 0;
    {
      let lo = [Infinity,Infinity,Infinity], hi = [-Infinity,-Infinity,-Infinity];
      for(let i = 0; i < nV; i++) for(let c = 0; c < 3; c++){
        const q = P[i*3+c]; if(q < lo[c]) lo[c] = q; if(q > hi[c]) hi[c] = q;
      }
      gD = Math.hypot(hi[0]-lo[0], hi[1]-lo[1], hi[2]-lo[2]) || 1;
    }
    for(let i = loops.length - 1; i >= 0; i--) if(loops[i].d > gD * .60) loops.splice(i, 1);
    if(loops.length){
      let top = loops[0];
      for(const L of loops) if(L.y > top.y) top = L;
      const bandW = o.band == null ? o.collar * .62 : o.band;
      for(const entry of loops){
        const w = entry === top ? o.collar : bandW;
        if(!(w > 0)) continue;
        const L = entry.loop, ring = [];
        /* A band is extruded ALONG THE SURFACE, out through its own
           opening -- up the neck for a collar, down the arm for a cuff,
           down for a hem -- and only slightly proud of it.

           Two wrong ways first. Lofting along the surface NORMAL sends
           the band wherever the body happens to face: out from the chest
           at the front of a neckline, straight up over the shoulder. And
           taking the direction straight from "away from the neighbour
           inside the garment" is right in principle but picks a
           different neighbour at every vertex, so the band's outer edge
           zigzags -- a collar like torn paper.

           So the direction comes from the loop's own tangent, which is
           smooth by construction: perpendicular to it, in the surface.
           Only its SIGN comes from the interior neighbour, and a sign
           cannot zigzag. */
        const dir = new Float64Array(L.length * 3);
        for(let k = 0; k < L.length; k++){
          const i = L[k], a2 = i*3;
          const pv = L[(k - 1 + L.length) % L.length] * 3, nx = L[(k + 1) % L.length] * 3;
          let tx = P[nx] - P[pv], ty = P[nx+1] - P[pv+1], tz = P[nx+2] - P[pv+2];
          const Lt = Math.hypot(tx,ty,tz) || 1; tx/=Lt; ty/=Lt; tz/=Lt;
          let ux = N[a2+1]*tz - N[a2+2]*ty,
              uy = N[a2+2]*tx - N[a2]  *tz,
              uz = N[a2]  *ty - N[a2+1]*tx;
          const Lu = Math.hypot(ux,uy,uz);
          if(Lu > 1e-7){ ux/=Lu; uy/=Lu; uz/=Lu; }
          else { ux = N[a2]; uy = N[a2+1]; uz = N[a2+2]; }
          const inN = inner[i];
          if(inN >= 0){
            const ax = P[a2] - P[inN*3], ay = P[a2+1] - P[inN*3+1], az = P[a2+2] - P[inN*3+2];
            if(ux*ax + uy*ay + uz*az < 0){ ux = -ux; uy = -uy; uz = -uz; }
          }
          dir[k*3] = ux; dir[k*3+1] = uy; dir[k*3+2] = uz;
        }
        // and a pass round the loop, because a sign that does flip once
        // leaves one spike, and one spike on a collar is visible
        {
          const tmp3 = Float64Array.from(dir);
          for(let k = 0; k < L.length; k++){
            const pv = ((k - 1 + L.length) % L.length) * 3, nx3 = ((k + 1) % L.length) * 3;
            for(let c = 0; c < 3; c++)
              tmp3[k*3+c] = (dir[pv+c] + dir[k*3+c] * 2 + dir[nx3+c]) * .25;
          }
          for(let k = 0; k < L.length; k++){
            const Lu = Math.hypot(tmp3[k*3], tmp3[k*3+1], tmp3[k*3+2]);
            if(Lu > 1e-7) for(let c = 0; c < 3; c++) dir[k*3+c] = tmp3[k*3+c] / Lu;
          }
        }
        for(let k = 0; k < L.length; k++){
          const i = L[k], a2 = i*3;
          ring.push(P.length/3);
          P.push(P[a2]   + dir[k*3]   * w + N[a2]   * w * .30,
                 P[a2+1] + dir[k*3+1] * w + N[a2+1] * w * .30,
                 P[a2+2] + dir[k*3+2] * w + N[a2+2] * w * .30);
          N.push(N[a2], N[a2+1], N[a2+2]);
          if(bodyUV) UV.push(UV[i*2], UV[i*2+1] + w * .30);
          LIMB.push(LIMB[i]);
        }
        for(let k = 0; k < L.length; k++){
          const a2 = L[k], b2 = L[(k+1) % L.length];
          const c2 = ring[k], d2 = ring[(k+1) % ring.length];
          T.push(a2, c2, b2, b2, c2, d2);
        }
      }
    }
  }

  /* Face normals, each one turned to agree with the body's normal before
     it is accumulated.

     A cut triangle whose winding came out reversed contributes a face
     normal pointing the opposite way to its neighbours, and where the two
     meet they cancel: the vertex ends up with a near-zero normal and
     renders as a black wedge. There was a pair of them either side of the
     collar on every top. Rendering the cloth unlit showed the mesh there
     was whole, which is what pointed at the normals rather than at a hole
     in the geometry. */
  const FN = (() => {
    const A = new Float32Array(P.length);
    for(let f = 0; f < T.length; f += 3){
      const a=T[f]*3, b=T[f+1]*3, c=T[f+2]*3;
      const ux=P[b]-P[a], uy=P[b+1]-P[a+1], uz=P[b+2]-P[a+2];
      const wx=P[c]-P[a], wy=P[c+1]-P[a+1], wz=P[c+2]-P[a+2];
      let nx=uy*wz-uz*wy, ny=uz*wx-ux*wz, nz=ux*wy-uy*wx;
      const rx=(N[a]+N[b]+N[c]), ry=(N[a+1]+N[b+1]+N[c+1]), rz=(N[a+2]+N[b+2]+N[c+2]);
      if(nx*rx + ny*ry + nz*rz < 0){ nx=-nx; ny=-ny; nz=-nz; }
      A[a]+=nx; A[a+1]+=ny; A[a+2]+=nz;
      A[b]+=nx; A[b+1]+=ny; A[b+2]+=nz;
      A[c]+=nx; A[c+1]+=ny; A[c+2]+=nz;
    }
    for(let i = 0; i < A.length; i += 3){
      const L = Math.hypot(A[i],A[i+1],A[i+2]);
      if(L > 1e-9){ A[i]/=L; A[i+1]/=L; A[i+2]/=L; }
      else { A[i]=N[i]; A[i+1]=N[i+1]; A[i+2]=N[i+2]; }
    }
    return A;
  })();
  const out = new Float32Array(P.length);
  for(let i = 0; i < P.length; i += 3){
    const w = onEdgeV[i/3] ? 0.88 : 0.30;
    let x = FN[i]*(1-w) + N[i]*w;
    let y = FN[i+1]*(1-w) + N[i+1]*w;
    let z = FN[i+2]*(1-w) + N[i+2]*w;
    /* Cloth lying on a body cannot face into it. Where the cut runs
       along a crease -- the collar meeting the top of the shoulder is
       the one that showed -- the blend could come out pointing inward,
       the surface then faced away from every light at once, and the
       result was a small black wedge that looked exactly like a hole in
       the mesh. Rendering the cloth unlit showed the mesh was whole. */
    if(x*N[i] + y*N[i+1] + z*N[i+2] < 0.06){
      x = N[i]; y = N[i+1]; z = N[i+2];
    }
    const L = Math.hypot(x,y,z) || 1;
    out[i]=x/L; out[i+1]=y/L; out[i+2]=z/L;
  }
  const r = { position: P, normal: Array.from(out), index: T, n: P.length/3 };
  if(bodyUV) r.uv = UV;
  return r;
}

export function faceNormals(P, T){
  const N = new Float32Array(P.length);
  for(let f = 0; f < T.length; f += 3){
    const a=T[f]*3, b=T[f+1]*3, c=T[f+2]*3;
    const ux=P[b]-P[a], uy=P[b+1]-P[a+1], uz=P[b+2]-P[a+2];
    const wx=P[c]-P[a], wy=P[c+1]-P[a+1], wz=P[c+2]-P[a+2];
    const nx=uy*wz-uz*wy, ny=uz*wx-ux*wz, nz=ux*wy-uy*wx;
    N[a]+=nx; N[a+1]+=ny; N[a+2]+=nz;
    N[b]+=nx; N[b+1]+=ny; N[b+2]+=nz;
    N[c]+=nx; N[c+1]+=ny; N[c+2]+=nz;
  }
  for(let i = 0; i < N.length; i += 3){
    const L = Math.hypot(N[i],N[i+1],N[i+2]) || 1;
    N[i]/=L; N[i+1]/=L; N[i+2]/=L;
  }
  return Array.from(N);
}

export function bodyNormals(B, index){
  const N = new Float32Array(B.v.length);
  const v = B.v;
  for(let f = 0; f < index.length; f += 3){
    const a=index[f]*3, b=index[f+1]*3, c=index[f+2]*3;
    const ux=v[b]-v[a], uy=v[b+1]-v[a+1], uz=v[b+2]-v[a+2];
    const wx=v[c]-v[a], wy=v[c+1]-v[a+1], wz=v[c+2]-v[a+2];
    const nx=uy*wz-uz*wy, ny=uz*wx-ux*wz, nz=ux*wy-uy*wx;
    N[a]+=nx; N[a+1]+=ny; N[a+2]+=nz;
    N[b]+=nx; N[b+1]+=ny; N[b+2]+=nz;
    N[c]+=nx; N[c+1]+=ny; N[c+2]+=nz;
  }
  for(let i = 0; i < N.length; i += 3){
    const L = Math.hypot(N[i],N[i+1],N[i+2]) || 1;
    N[i]/=L; N[i+1]/=L; N[i+2]/=L;
  }
  return N;
}

/* ------------------------------------------------------------------ refine

   One level of subdivision, on the head only.

   The whole body is 13,380 vertices, which spreads thin: the nose, the
   lips and the rim of an ear each come down to a handful of polygons,
   and at the size a figure is actually looked at that is exactly where
   the eye goes. Subdividing everything would quadruple a mesh that is
   mostly thigh.

   The new vertices are placed on the PN-triangle surface -- the cubic
   patch that interpolates the three corners AND their normals -- rather
   than at the midpoints of the edges. A midpoint lands on the flat
   facet, so plain splitting adds vertices and changes nothing: the
   silhouette stays faceted and only the triangle count goes up. The PN
   placement rounds the surface out to the shape the normals already
   claim it has, which is the shape the shading has been pretending to
   all along.

   Interpolating, not approximating: every original vertex stays exactly
   where it was. That matters because everything else -- the garments,
   the brows, the lashes, the scalp -- is built against the original mesh
   by vertex index, and a subdivision that moved the originals would move
   the body out from under all of it.

   This runs after the morphs, on the finished positions, so the bundle
   does not grow by a byte. */
export function refine(pos, index, nrm, uv, inRegion){
  const n = pos.length / 3;
  const P = Array.from(pos), N = Array.from(nrm), UV = uv ? Array.from(uv) : null;
  const T = [];
  const mid = new Map();
  const dot = (ax,ay,az,bx,by,bz) => ax*bx + ay*by + az*bz;

  const edge = (a, b) => {
    const key = a < b ? a * n + b : b * n + a;
    let m = mid.get(key);
    if(m !== undefined) return m;
    const a3 = a*3, b3 = b*3;
    const dx = P[b3]-P[a3], dy = P[b3+1]-P[a3+1], dz = P[b3+2]-P[a3+2];
    const wa =  dot(dx,dy,dz, N[a3],N[a3+1],N[a3+2]);
    const wb = -dot(dx,dy,dz, N[b3],N[b3+1],N[b3+2]);
    m = P.length / 3;
    P.push((P[a3]  +P[b3]  )*.5 - (wa*N[a3]   + wb*N[b3]  )/8,
           (P[a3+1]+P[b3+1])*.5 - (wa*N[a3+1] + wb*N[b3+1])/8,
           (P[a3+2]+P[b3+2])*.5 - (wa*N[a3+2] + wb*N[b3+2])/8);
    let nx = N[a3]+N[b3], ny = N[a3+1]+N[b3+1], nz = N[a3+2]+N[b3+2];
    const L = Math.hypot(nx,ny,nz) || 1;
    N.push(nx/L, ny/L, nz/L);
    if(UV) UV.push((UV[a*2]+UV[b*2])*.5, (UV[a*2+1]+UV[b*2+1])*.5);
    mid.set(key, m);
    return m;
  };

  const keep = new Uint8Array(n);
  for(let i = 0; i < n; i++) keep[i] = inRegion(i) ? 1 : 0;

  /* Two passes. The first splits the triangles inside the region; the
     second mends the ones beside it, because a neighbour that keeps its
     long edge while the triangle across that edge has gained a vertex in
     the middle of it leaves a T-junction, and a T-junction is a crack
     you can see daylight through. */
  const split = [];
  for(let f = 0; f < index.length; f += 3){
    const a = index[f], b = index[f+1], c = index[f+2];
    if(keep[a] && keep[b] && keep[c]){
      const ab = edge(a,b), bc = edge(b,c), ca = edge(c,a);
      T.push(a,ab,ca,  ab,b,bc,  ca,bc,c,  ab,bc,ca);
    } else {
      split.push(f);
    }
  }
  for(const f of split){
    const t = [index[f], index[f+1], index[f+2]];
    const has = [];
    for(let e = 0; e < 3; e++){
      const a = t[e], b = t[(e+1)%3];
      const key = a < b ? a * n + b : b * n + a;
      has.push(mid.has(key) ? mid.get(key) : -1);
    }
    const cnt = has.filter(x => x >= 0).length;
    if(cnt === 0){ T.push(t[0], t[1], t[2]); continue; }
    if(cnt === 3){
      T.push(t[0],has[0],has[2],  has[0],t[1],has[1],  has[2],has[1],t[2],  has[0],has[1],has[2]);
      continue;
    }
    if(cnt === 1){
      const e = has.findIndex(x => x >= 0);
      const m = has[e], p = t[e], q = t[(e+1)%3], r = t[(e+2)%3];
      T.push(p, m, r,  m, q, r);
      continue;
    }
    // two split edges: cut off the corner they share, then halve the rest
    const e = has.findIndex(x => x < 0);               // the whole edge
    const p = t[e], q = t[(e+1)%3], r = t[(e+2)%3];    // p-q is whole
    const mq = has[(e+1)%3], mr = has[(e+2)%3];        // on q-r and r-p
    T.push(r, mr, mq,  mr, p, q,  mr, q, mq);
  }
  return { position: P, normal: N, uv: UV, index: T, n: P.length/3 };
}

/* ---------------------------------------------------------- obscurance

   Where a surface folds into itself, less of the sky reaches it. That
   darkening is most of what tells an eye how deep a crease is, and
   without it every fold -- the one under a chin, the one at an armpit,
   the one where a sleeve ends -- is drawn entirely by a light that
   happens to be pointing at it, and reads as paint rather than as depth.

   True ambient occlusion means tracing rays, which is not happening in
   a figure that rebuilds in a second. This is the cheap relative: for
   each vertex, look at the surface a short way around it and ask how
   much of it sits ABOVE the tangent plane. On a convex surface the
   answer is none and the vertex is open to the sky; in a crease the
   answer is most of it. It catches every fold the mesh actually has,
   which is what matters, and it costs one pass over the adjacency.

   Not a substitute for the real thing: it cannot know about the hair
   above the forehead or the sleeve over the arm, because those are
   other meshes. It only knows about the surface it is given. */
export function obscurance(P, N, index, opt){
  const o = opt || {};
  const n = P.length / 3;
  const adj = buildAdjacency(index, n);
  // the scale the effect works at: creases smaller than this are
  // cavities, anything larger is just shape
  let span = 0;
  {
    let lo = [Infinity,Infinity,Infinity], hi = [-Infinity,-Infinity,-Infinity];
    for(let i = 0; i < n; i++) for(let c = 0; c < 3; c++){
      const q = P[i*3+c]; if(q < lo[c]) lo[c] = q; if(q > hi[c]) hi[c] = q;
    }
    span = Math.max(hi[0]-lo[0], hi[1]-lo[1], hi[2]-lo[2]) || 1;
  }
  const R = (o.radius == null ? .035 : o.radius) * span;
  const RINGS = o.rings == null ? 3 : o.rings;
  const ao = new Float32Array(n);
  const mark = new Int32Array(n).fill(-1);
  const queue = new Int32Array(1024);
  const depth = new Int32Array(1024);
  for(let i = 0; i < n; i++){
    let head = 0, tail = 0;
    queue[tail] = i; depth[tail] = 0; tail++;
    mark[i] = i;
    let acc = 0, w = 0;
    while(head < tail){
      const j = queue[head], d = depth[head]; head++;
      if(j !== i){
        const dx = P[j*3] - P[i*3], dy = P[j*3+1] - P[i*3+1], dz = P[j*3+2] - P[i*3+2];
        const L = Math.hypot(dx, dy, dz);
        if(L > 1e-9){
          const up = (dx*N[i*3] + dy*N[i*3+1] + dz*N[i*3+2]) / L;
          // near things matter more, and only what is ABOVE the plane
          const fall = 1 / (1 + (L / R) * (L / R));
          acc += Math.max(0, up) * fall;
          w += fall;
        }
      }
      if(d >= RINGS) continue;
      for(let t = adj.start[j]; t < adj.start[j+1]; t++){
        const k = adj.list[t];
        if(mark[k] === i) continue;
        mark[k] = i;
        if(tail >= queue.length) break;
        queue[tail] = k; depth[tail] = d + 1; tail++;
      }
    }
    ao[i] = w > 0 ? acc / w : 0;
  }
  // and smooth it, because a cavity term read off a coarse mesh is noisy
  const tmp = new Float32Array(n);
  for(let pass = 0; pass < 2; pass++){
    for(let i = 0; i < n; i++){
      const a = adj.start[i], b = adj.start[i+1];
      if(b <= a){ tmp[i] = ao[i]; continue; }
      let acc = 0;
      for(let t = a; t < b; t++) acc += ao[adj.list[t]];
      tmp[i] = ao[i] * .4 + (acc / (b - a)) * .6;
    }
    ao.set(tmp);
  }
  const strength = o.strength == null ? 1.0 : o.strength;
  const floor = o.floor == null ? .35 : o.floor;
  for(let i = 0; i < n; i++)
    ao[i] = Math.max(floor, 1 - Math.min(1, ao[i] * 2.6) * strength);
  return ao;
}
