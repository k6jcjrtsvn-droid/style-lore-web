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
  /* and then the whole smoothed surface is pushed back out, by the most
     any point of it sank.

     Smoothing a surface IS shrinking it -- that is how the nipple goes
     away -- so the draped base ends up inside the skin, and a garment
     offset from it by a normal cloth thickness has the body coming
     through it at every place that sank more than that: the shoulders,
     the nipples, the front of the thighs. Compensating per vertex only
     moves the problem, because the shrink is largest exactly where the
     feature was, so adding it back rebuilds the feature. One constant
     for the whole surface puts the base outside the skin everywhere and
     keeps it smooth, and the garment then stands off it by its own
     thickness alone. The figure is about sixteen units tall, so this is
     a couple of centimetres of ease -- which is what clothes have. */
  let lift = 0;
  for(let i = 0; i < n; i++){
    const d = (v[i*3] - P[i*3]) * N[i*3]
            + (v[i*3+1] - P[i*3+1]) * N[i*3+1]
            + (v[i*3+2] - P[i*3+2]) * N[i*3+2];
    if(d > lift) lift = d;
  }
  lift = Math.min(lift, 0.26);
  for(let i = 0; i < n; i++){
    P[i*3]   += N[i*3]   * lift;
    P[i*3+1] += N[i*3+1] * lift;
    P[i*3+2] += N[i*3+2] * lift;
  }
  /* Offsetting a surface outward folds it wherever the surface is
     concave and turns tighter than the offset -- under a breast, in an
     armpit, behind a knee. The fold is a pinch of back-facing cloth and
     it renders as a small black crescent that looks like a tear in the
     shirt. Three more smoothing passes AFTER the lift take the folds
     out: a fold is high frequency, and by this point nothing else of the
     body's detail is left for the smoothing to eat. */
  for(let pass = 0; pass < 3; pass++) step(.55);
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
  const P = [], N = [], T = [], UV = [];
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
  const put = (p, nn, uu) => {
    P.push(p[0],p[1],p[2]); N.push(nn[0],nn[1],nn[2]);
    if(bodyUV) UV.push(uu[0], uu[1]);
    return P.length/3 - 1;
  };
  const at = i => {
    let x = base.get(i);
    if(x === undefined){
      const pp = [v[i*3], v[i*3+1], v[i*3+2]];
      x = put(pp, [nrm[i*3], nrm[i*3+1], nrm[i*3+2]], uvOf(pp));
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
    x = put(pos, [nn[0]/L, nn[1]/L, nn[2]/L], uvOf(pos));
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
  for(let i = 0; i < P.length; i += 3){
    const v3 = i/3;
    let t = typeof thick === 'function' ? thick([P[i], P[i+1], P[i+2]]) : thick;
    if(onEdgeV[v3]) t *= (o.edgeLift == null ? 1.75 : o.edgeLift);
    P[i] += N[i]*t; P[i+1] += N[i+1]*t; P[i+2] += N[i+2]*t;
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
